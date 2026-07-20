<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurseForgeService
{
    public function __construct(private readonly CurseForgeApiKeyProvider $apiKeyProvider) {}

    private const API = 'https://api.curseforge.com/v1';

    private const MINECRAFT_GAME_ID = 432;

    private const BUKKIT_CLASS_ID = 5;

    private const MODS_CLASS_ID = 6;

    /** @return array<int, array<string, mixed>> */
    public function searchPackages(string $query, MinecraftToolkitSetup $setup, int $limit = 20): array
    {
        $this->assertEnabled();
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new MinecraftToolkitException('The search must contain at least two characters.');
        }

        $params = $this->searchParameters($setup) + [
            'searchFilter' => $query,
            'sortField' => 2,
            'sortOrder' => 'desc',
            'pageSize' => min(max($limit, 1), 50),
        ];
        $key = 'minecrafttoolkit.curseforge.search.' . sha1(json_encode($params, JSON_THROW_ON_ERROR));
        $response = Cache::remember(
            $key,
            now()->addMinutes(10),
            fn (): array => $this->get('/mods/search', $params)
        );

        return $this->normalizeSearchResults($response['data'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    public function popularPackages(MinecraftToolkitSetup $setup, int $offset = 0, int $limit = 20): array
    {
        $this->assertEnabled();

        if (!in_array($setup->software, ['paper', 'purpur', 'folia', 'fabric', 'forge', 'neoforge'], true)) {
            return [];
        }

        $params = $this->searchParameters($setup) + [
            'sortField' => 2,
            'sortOrder' => 'desc',
            'index' => max(0, $offset),
            'pageSize' => min(max($limit, 1), 50),
        ];
        $key = 'minecrafttoolkit.curseforge.popular.' . sha1(json_encode($params, JSON_THROW_ON_ERROR));
        $response = Cache::remember(
            $key,
            now()->addMinutes(10),
            fn (): array => $this->get('/mods/search', $params)
        );

        return $this->normalizeSearchResults($response['data'] ?? []);
    }

    /** @return array{project: array<string, mixed>, version: array<string, mixed>, dependencies: array<int, array<string, mixed>>, warning: ?string} */
    public function installationCandidate(string $projectId, MinecraftToolkitSetup $setup): array
    {
        $this->assertEnabled();
        if (!ctype_digit($projectId) || (int) $projectId <= 0) {
            throw new MinecraftToolkitException('The CurseForge project ID is invalid.');
        }

        $project = $this->project((int) $projectId);
        $files = $this->files((int) $projectId, $setup);
        usort($files, function (array $a, array $b): int {
            $dateCompare = strcmp((string) ($b['fileDate'] ?? ''), (string) ($a['fileDate'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });
        $file = collect($files)
            ->where('releaseType', 1)
            ->first(fn (mixed $candidate): bool => is_array($candidate) && !$this->isServerPack($candidate))
            ?? collect($files)->first(fn (mixed $candidate): bool => is_array($candidate) && !$this->isServerPack($candidate));
        if (!is_array($file)
            || !is_string($file['fileName'] ?? null)
            || strtolower(pathinfo($file['fileName'], PATHINFO_EXTENSION)) !== 'jar') {
            throw new MinecraftToolkitException('No compatible CurseForge JAR was found.');
        }

        $downloadUrl = is_string($file['downloadUrl'] ?? null) ? $file['downloadUrl'] : null;
        if ($downloadUrl === null && isset($file['id'])) {
            $download = $this->get("/mods/$projectId/files/{$file['id']}/download-url");
            $downloadUrl = is_string($download['data'] ?? null) ? $download['data'] : null;
        }
        if ($downloadUrl === null) {
            throw new MinecraftToolkitException(
                'CurseForge does not provide an API download URL for this file.'
            );
        }

        $file['downloadUrl'] = $downloadUrl;
        $file['hashes_normalized'] = $this->normalizeHashes($file['hashes'] ?? []);

        return [
            'project' => $this->normalizeProject($project),
            'version' => [
                'id' => (string) $file['id'],
                'version_number' => (string) ($file['displayName'] ?? $file['fileName']),
                'date_published' => is_string($file['fileDate'] ?? null) ? $file['fileDate'] : null,
                'selected_file' => [
                    'filename' => $file['fileName'],
                    'url' => $downloadUrl,
                    'hashes' => $file['hashes_normalized'],
                    'size' => (int) ($file['fileLength'] ?? 0),
                ],
            ],
            'dependencies' => $this->dependencyDetails($file),
            'warning' => in_array($setup->software, ['fabric', 'forge', 'neoforge'], true)
                ? 'CurseForge does not provide a reliable client/server solution. Check the project description before installing.'
                : null,
        ];
    }

    /** @return array{project: array<string, mixed>, version: array<string, mixed>, dependencies: array<int, array<string, mixed>>, warning: ?string} */
    public function updateCandidate(string $projectId, MinecraftToolkitSetup $setup): array
    {
        return $this->installationCandidate($projectId, $setup);
    }

    /** @return array<string, scalar> */
    public function searchParameters(MinecraftToolkitSetup $setup): array
    {
        $plugin = in_array($setup->software, ['paper', 'purpur', 'folia'], true);
        $parameters = [
            'gameId' => self::MINECRAFT_GAME_ID,
            'classId' => $plugin ? self::BUKKIT_CLASS_ID : self::MODS_CLASS_ID,
            'gameVersion' => $setup->minecraft_version,
        ];
        $loader = $this->loaderType($setup->software);
        if ($loader !== null) {
            $parameters['modLoaderType'] = $loader;
        }

        return $parameters;
    }

    public function loaderType(string $software): ?int
    {
        return match ($software) {
            'forge' => 1,
            'fabric' => 4,
            'neoforge' => 6,
            default => null,
        };
    }

    /** @param array<int, mixed> $projects
     *  @return array<int, array<string, mixed>>
     */
    public function normalizeSearchResults(array $projects): array
    {
        return collect($projects)
            ->filter(fn (mixed $project): bool => is_array($project) && isset($project['id']))
            ->map(fn (array $project): array => [
                'project_id' => (string) $project['id'],
                'slug' => (string) ($project['slug'] ?? $project['id']),
                'title' => (string) ($project['name'] ?? 'Unbekanntes Projekt'),
                'description' => (string) ($project['summary'] ?? ''),
                'icon_url' => is_string($project['logo']['thumbnailUrl'] ?? null)
                    ? $project['logo']['thumbnailUrl']
                    : null,
                'project_url' => is_string($project['links']['websiteUrl'] ?? null)
                    ? $project['links']['websiteUrl']
                    : null,
                'downloads' => (int) ($project['downloadCount'] ?? 0),
                'author' => (string) ($project['authors'][0]['name'] ?? ''),
                'server_side' => 'unknown',
                'categories' => collect($project['categories'] ?? [])
                    ->pluck('name')
                    ->filter(fn (mixed $value): bool => is_string($value))
                    ->values()
                    ->all(),
                'updated_at' => is_string($project['dateModified'] ?? null) ? $project['dateModified'] : null,
                'versions' => collect($project['latestFilesIndexes'] ?? [])
                    ->pluck('gameVersion')
                    ->filter(fn (mixed $value): bool => is_string($value))
                    ->unique()
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /** @param array<int, mixed> $hashes
     *  @return array<string, string>
     */
    public function normalizeHashes(array $hashes): array
    {
        $normalized = [];
        foreach ($hashes as $hash) {
            if (!is_array($hash) || !is_string($hash['value'] ?? null)) {
                continue;
            }
            if (($hash['algo'] ?? null) === 1) {
                $normalized['sha1'] = strtolower($hash['value']);
            }
            if (($hash['algo'] ?? null) === 2) {
                $normalized['md5'] = strtolower($hash['value']);
            }
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function project(int $projectId): array
    {
        return Cache::remember(
            "minecrafttoolkit.curseforge.project.$projectId",
            now()->addHour(),
            fn (): array => $this->get("/mods/$projectId")['data'] ?? []
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function files(int $projectId, MinecraftToolkitSetup $setup): array
    {
        $params = [
            'gameVersion' => $setup->minecraft_version,
            'pageSize' => 50,
        ];
        $loader = $this->loaderType($setup->software);
        if ($loader !== null) {
            $params['modLoaderType'] = $loader;
        }
        $key = 'minecrafttoolkit.curseforge.files.' . sha1(json_encode([
            $projectId,
            $params,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember(
            $key,
            now()->addMinutes(10),
            fn (): array => $this->get("/mods/$projectId/files", $params)['data'] ?? []
        );
    }

    /** @param array<string, mixed> $file
     *  @return array<int, array<string, mixed>>
     */
    private function dependencyDetails(array $file): array
    {
        return collect($file['dependencies'] ?? [])
            ->filter(fn (mixed $dependency): bool => is_array($dependency)
                && in_array($dependency['relationType'] ?? null, [2, 3], true))
            ->take(30)
            ->map(function (array $dependency): array {
                $projectId = (string) ($dependency['modId'] ?? '');
                $project = [];
                try {
                    $project = $projectId !== '' ? $this->project((int) $projectId) : [];
                } catch (\Throwable $exception) {
                    report($exception);
                }

                return [
                    'project_id' => $projectId !== '' ? $projectId : null,
                    'version_id' => null,
                    'type' => ($dependency['relationType'] ?? null) === 3 ? 'required' : 'optional',
                    'title' => (string) (($project['name'] ?? null) ?: ($projectId ?: 'Unbekannte Dependency')),
                    'slug' => is_string($project['slug'] ?? null) ? $project['slug'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $project
     *  @return array<string, mixed>
     */
    private function normalizeProject(array $project): array
    {
        return [
            'project_id' => (string) ($project['id'] ?? ''),
            'slug' => (string) ($project['slug'] ?? $project['id'] ?? ''),
            'title' => (string) ($project['name'] ?? 'Unknown Project'),
            'description' => (string) ($project['summary'] ?? ''),
            'icon_url' => is_string($project['logo']['thumbnailUrl'] ?? null)
                ? $project['logo']['thumbnailUrl']
                : null,
            'project_url' => is_string($project['links']['websiteUrl'] ?? null)
                ? $project['links']['websiteUrl']
                : null,
            'downloads' => (int) ($project['downloadCount'] ?? 0),
            'server_side' => 'unknown',
            'categories' => collect($project['categories'] ?? [])
                ->pluck('name')
                ->filter(fn (mixed $value): bool => is_string($value))
                ->values()
                ->all(),
            'published_at' => is_string($project['dateCreated'] ?? null) ? $project['dateCreated'] : null,
            'updated_at' => is_string($project['dateModified'] ?? null) ? $project['dateModified'] : null,
        ];
    }

    /** @param array<string, scalar> $query
     *  @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->usesProxy()
                ? $this->proxyRequest($path, $query)
                : $this->directRequest($path, $query);

            if (!is_array($response)) {
                throw new MinecraftToolkitException('CurseForge returned an invalid response.');
            }

            return $response;
        } catch (MinecraftToolkitException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Minecraft Toolkit CurseForge request failed.', [
                'exception' => $exception::class,
                'path' => $path,
                'mode' => $this->usesProxy() ? 'proxy' : 'direct',
            ]);
            throw new MinecraftToolkitException(
                'CurseForge is currently unavailable. Please try again later.',
                previous: $exception
            );
        }
    }

    /** @param array<string, scalar> $query
     *  @return array<string, mixed>
     */
    private function proxyRequest(string $path, array $query = []): array
    {
        $headers = $this->proxyHeaders($path);

        return Http::acceptJson()
            ->withHeaders($headers)
            ->withUserAgent((string) config('minecrafttoolkit.user_agent'))
            ->connectTimeout(5)
            ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
            ->get($this->proxyUrl(), ['path' => $path] + $query)
            ->throw()
            ->json();
    }


    /** @return array<string, string> */
    private function proxyHeaders(string $path): array
    {
        $secret = trim((string) config('minecrafttoolkit.curseforge_proxy_secret', ''));
        $clientId = trim((string) config('minecrafttoolkit.curseforge_proxy_client_id', 'minecraft-toolkit'));
        $userAgent = (string) config('minecrafttoolkit.user_agent');
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $headers = [
            'X-BlueIT-Toolkit-Request' => 'v1',
            'X-Minecraft-Toolkit-Client' => $clientId,
            'X-Minecraft-Toolkit-Timestamp' => $timestamp,
            'X-Minecraft-Toolkit-Nonce' => $nonce,
        ];

        if ($secret !== '' && (bool) config('minecrafttoolkit.curseforge_proxy_signed_requests', true)) {
            $headers['X-Minecraft-Toolkit-Signature'] = hash_hmac(
                'sha256',
                "GET\n{$path}\n{$timestamp}\n{$clientId}\n{$nonce}\n{$userAgent}",
                $secret
            );
        }

        return $headers;
    }

    /** @param array<string, scalar> $query
     *  @return array<string, mixed>
     */
    private function directRequest(string $path, array $query = []): array
    {
        return Http::acceptJson()
            ->withHeaders(['x-api-key' => $this->apiKey()])
            ->withUserAgent((string) config('minecrafttoolkit.user_agent'))
            ->connectTimeout(5)
            ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
            ->get(self::API . $path, $query)
            ->throw()
            ->json();
    }

    private function assertEnabled(): void
    {
        if (!(bool) config('minecrafttoolkit.curseforge_enabled', false)) {
            throw new MinecraftToolkitException('CurseForge is disabled in the plugin settings.');
        }

        if (!$this->usesProxy() && !$this->apiKeyProvider->hasKey()) {
            throw new MinecraftToolkitException(
                'CurseForge is disabled because neither a Toolkit proxy nor a local CurseForge API key has been configured.'
            );
        }
    }

    public function isConfigured(): bool
    {
        return (bool) config('minecrafttoolkit.curseforge_enabled', false)
            && ($this->usesProxy() || $this->apiKeyProvider->hasKey());
    }

    public function keySource(): ?string
    {
        if ($this->usesProxy()) {
            return 'toolkit-proxy';
        }

        return $this->apiKeyProvider->source();
    }

    private function apiKey(): string
    {
        $key = $this->apiKeyProvider->getKey();
        if ($key === null) {
            throw new MinecraftToolkitException(
                'CurseForge is disabled because neither a Toolkit proxy nor a local CurseForge API key has been configured.'
            );
        }

        return $key;
    }


    private function usesProxy(): bool
    {
        return $this->proxyUrl() !== '';
    }

    private function proxyUrl(): string
    {
        return rtrim(trim((string) config('minecrafttoolkit.curseforge_proxy_url', '')), '/');
    }

    /** @param array<string, mixed> $file */
    private function isServerPack(array $file): bool
    {
        return (bool) ($file['isServerPack'] ?? false);
    }
}
