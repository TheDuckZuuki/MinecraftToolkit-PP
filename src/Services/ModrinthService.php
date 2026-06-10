<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ModrinthService
{
    private const API = 'https://api.modrinth.com/v2';

    /** @return array<int, array<string, mixed>> */
    public function searchPackages(string $query, MinecraftToolkitSetup $setup, int $limit = 20): array
    {
        $this->assertEnabled();
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new MinecraftToolkitException('Die Suche muss mindestens zwei Zeichen enthalten.');
        }

        $facets = $this->searchFacets($setup);
        $key = 'minecrafttoolkit.modrinth.search.' . sha1(json_encode([$query, $facets, $limit], JSON_THROW_ON_ERROR));
        $data = Cache::remember($key, now()->addMinutes(10), fn (): array => $this->get('/search', [
            'query' => $query,
            'facets' => json_encode($facets, JSON_THROW_ON_ERROR),
            'index' => 'relevance',
            'limit' => min(max($limit, 1), 100),
        ]));

        return $this->normalizeSearchResults($data['hits'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    public function searchPlugins(string $query, MinecraftToolkitSetup $setup, int $limit = 20): array
    {
        return $this->searchPackages($query, $setup, $limit);
    }

    /** @return array{project: array<string, mixed>, version: array<string, mixed>, dependencies: array<int, array<string, mixed>>, warning: ?string} */
    public function installationCandidate(string $projectId, MinecraftToolkitSetup $setup): array
    {
        $this->assertEnabled();
        $this->assertIdentifier($projectId);
        if (!in_array($setup->software, ['paper', 'purpur', 'folia', 'fabric', 'forge', 'neoforge'], true)) {
            throw new MinecraftToolkitException('Diese Serversoftware unterstützt keine Modrinth-Pakete.');
        }

        $project = $this->project($projectId);
        if (($project['project_type'] ?? null) !== 'mod'
            || ($project['server_side'] ?? 'unknown') === 'unsupported') {
            throw new MinecraftToolkitException('Dieses Projekt ist nicht als serverseitiges Paket geeignet.');
        }

        $versions = $this->versions($projectId, $setup);
        $version = collect($versions)->firstWhere('version_type', 'release') ?? ($versions[0] ?? null);
        if (!is_array($version)) {
            throw new MinecraftToolkitException('Keine kompatible Paketversion wurde gefunden.');
        }

        $file = collect($version['files'] ?? [])->firstWhere('primary', true)
            ?? collect($version['files'] ?? [])->first();
        if (!is_array($file)
            || !is_string($file['url'] ?? null)
            || !is_string($file['filename'] ?? null)
            || strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION)) !== 'jar') {
            throw new MinecraftToolkitException('Die kompatible Version enthält keine installierbare JAR-Datei.');
        }

        $version['selected_file'] = $file;

        return [
            'project' => $this->normalizeProject($project),
            'version' => $version,
            'dependencies' => $this->dependencyDetails($version),
            'warning' => $setup->software === 'folia'
                ? 'Folia ist nicht automatisch mit jedem Paper-Plugin kompatibel. Prüfe die Projektbeschreibung, bevor du produktive Server migrierst.'
                : null,
        ];
    }

    /** @return array{project: array<string, mixed>, version: array<string, mixed>, dependencies: array<int, array<string, mixed>>, warning: ?string} */
    public function updateCandidate(string $projectId, MinecraftToolkitSetup $setup): array
    {
        return $this->installationCandidate($projectId, $setup);
    }

    /** @param array<int, mixed> $hits
     *  @return array<int, array<string, mixed>>
     */
    public function normalizeSearchResults(array $hits): array
    {
        return collect($hits)
            ->filter(fn (mixed $hit): bool => is_array($hit) && is_string($hit['project_id'] ?? null))
            ->map(fn (array $hit): array => [
                'project_id' => $hit['project_id'],
                'slug' => (string) ($hit['slug'] ?? $hit['project_id']),
                'title' => (string) ($hit['title'] ?? 'Unbekanntes Projekt'),
                'description' => (string) ($hit['description'] ?? ''),
                'icon_url' => is_string($hit['icon_url'] ?? null) ? $hit['icon_url'] : null,
                'downloads' => (int) ($hit['downloads'] ?? 0),
                'author' => (string) ($hit['author'] ?? ''),
                'server_side' => (string) ($hit['server_side'] ?? 'unknown'),
                'categories' => array_values(array_filter($hit['display_categories'] ?? $hit['categories'] ?? [], 'is_string')),
                'versions' => array_values(array_filter($hit['versions'] ?? [], 'is_string')),
            ])
            ->values()
            ->all();
    }

    /** @return string[] */
    public function loaderCandidates(string $software): array
    {
        return match ($software) {
            'paper' => ['paper', 'spigot', 'bukkit'],
            'purpur' => ['purpur', 'paper', 'spigot', 'bukkit'],
            'folia' => ['folia', 'paper'],
            'fabric' => ['fabric'],
            'forge' => ['forge'],
            'neoforge' => ['neoforge'],
            default => [],
        };
    }

    /** @return array<int, array<int, string>> */
    public function searchFacets(MinecraftToolkitSetup $setup): array
    {
        return [
            array_map(
                fn (string $loader): string => "categories:$loader",
                $this->loaderCandidates($setup->software)
            ),
            ["versions:{$setup->minecraft_version}"],
            [in_array($setup->software, ['paper', 'purpur', 'folia'], true)
                ? 'project_type:plugin'
                : 'project_type:mod'],
            ['server_side:required', 'server_side:optional'],
        ];
    }

    /** @return array<string, mixed> */
    private function project(string $projectId): array
    {
        return Cache::remember(
            "minecrafttoolkit.modrinth.project.$projectId",
            now()->addHour(),
            fn (): array => $this->get("/project/$projectId")
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function versions(string $projectId, MinecraftToolkitSetup $setup): array
    {
        $loaders = $this->loaderCandidates($setup->software);
        $key = 'minecrafttoolkit.modrinth.versions.' . sha1(json_encode([
            $projectId,
            $loaders,
            $setup->minecraft_version,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember($key, now()->addMinutes(10), fn (): array => $this->get(
            "/project/$projectId/version",
            [
                'loaders' => json_encode($loaders, JSON_THROW_ON_ERROR),
                'game_versions' => json_encode([$setup->minecraft_version], JSON_THROW_ON_ERROR),
                'include_changelog' => 'false',
            ]
        ));
    }

    /** @param array<string, mixed> $version
     *  @return array<int, array<string, mixed>>
     */
    private function dependencyDetails(array $version): array
    {
        return collect($version['dependencies'] ?? [])
            ->filter(fn (mixed $dependency): bool => is_array($dependency)
                && in_array($dependency['dependency_type'] ?? null, ['required', 'optional'], true))
            ->take(30)
            ->map(function (array $dependency): array {
                $projectId = $dependency['project_id'] ?? null;
                $project = [];

                try {
                    if (!is_string($projectId) && is_string($dependency['version_id'] ?? null)) {
                        $dependencyVersion = $this->getVersion($dependency['version_id']);
                        $projectId = $dependencyVersion['project_id'] ?? null;
                    }

                    $project = is_string($projectId) ? $this->project($projectId) : [];
                } catch (\Throwable $exception) {
                    report($exception);
                }

                return [
                    'project_id' => $projectId,
                    'version_id' => is_string($dependency['version_id'] ?? null) ? $dependency['version_id'] : null,
                    'type' => (string) $dependency['dependency_type'],
                    'title' => (string) ($project['title'] ?? $dependency['file_name'] ?? $projectId ?? 'Unbekannte Dependency'),
                    'slug' => is_string($project['slug'] ?? null) ? $project['slug'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function getVersion(string $versionId): array
    {
        $this->assertIdentifier($versionId);

        return Cache::remember(
            "minecrafttoolkit.modrinth.version.$versionId",
            now()->addMinutes(10),
            fn (): array => $this->get("/version/$versionId")
        );
    }

    /** @param array<string, mixed> $project
     *  @return array<string, mixed>
     */
    private function normalizeProject(array $project): array
    {
        return [
            'project_id' => (string) ($project['id'] ?? ''),
            'slug' => (string) ($project['slug'] ?? $project['id'] ?? ''),
            'title' => (string) ($project['title'] ?? 'Unbekanntes Projekt'),
            'description' => (string) ($project['description'] ?? ''),
            'icon_url' => is_string($project['icon_url'] ?? null) ? $project['icon_url'] : null,
            'downloads' => (int) ($project['downloads'] ?? 0),
            'server_side' => (string) ($project['server_side'] ?? 'unknown'),
        ];
    }

    /** @param array<string, scalar> $query
     *  @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        try {
            return Http::acceptJson()
                ->withUserAgent((string) config('minecrafttoolkit.user_agent'))
                ->connectTimeout(5)
                ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
                ->get(self::API . $path, $query)
                ->throw()
                ->json();
        } catch (\Throwable $exception) {
            report($exception);
            throw new MinecraftToolkitException('Modrinth ist derzeit nicht erreichbar. Versuche es später erneut.', previous: $exception);
        }
    }

    private function assertEnabled(): void
    {
        if (!(bool) config('minecrafttoolkit.modrinth_enabled', true)) {
            throw new MinecraftToolkitException('Modrinth ist in den Minecraft-Toolkit-Einstellungen deaktiviert.');
        }
    }

    private function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z0-9!@$()`.+,\'_-]{3,64}$/', $identifier)) {
            throw new MinecraftToolkitException('Die Modrinth-Projektkennung ist ungültig.');
        }
    }
}
