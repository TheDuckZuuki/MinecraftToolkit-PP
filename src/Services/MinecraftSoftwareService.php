<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MinecraftSoftwareService
{
    /** @return array<string, string> */
    public function supportedSoftware(): array
    {
        return [
            'vanilla' => 'Vanilla Java',
            'bedrock' => 'Vanilla Bedrock',
            'paper' => 'Paper',
            'folia' => 'Folia',
            'purpur' => 'Purpur',
            'fabric' => 'Fabric',
            'forge' => 'Forge',
            'neoforge' => 'NeoForge',
        ];
    }

    /** @return array<string, string> */
    public function versionOptions(string $software): array
    {
        try {
            $versions = Cache::remember(
                "minecrafttoolkit.versions.$software",
                now()->addHours(6),
                fn (): array => $this->fetchVersions($software)
            );
        } catch (\Throwable $exception) {
            report($exception);
            $versions = match ($software) {
                'vanilla', 'paper', 'purpur', 'folia' => config('minecrafttoolkit.fallback_versions', []),
                'bedrock' => config('minecrafttoolkit.bedrock_fallback_versions', []),
                default => [],
            };
        }

        return collect($versions)
            ->filter(fn (mixed $version): bool => is_string($version) && preg_match('/^\d+(?:\.\d+){1,3}$/', $version) === 1)
            ->unique()
            ->sortDesc(SORT_NATURAL)
            ->mapWithKeys(fn (string $version): array => [$version => $version])
            ->all();
    }

    /** @return array{url: string, source: string, version_id: ?string} */
    public function resolveDownload(string $software, string $version): array
    {
        return match ($software) {
            'vanilla' => $this->resolveVanilla($version),
            'bedrock' => $this->resolveBedrock($version),
            'paper' => $this->resolvePaper($version),
            'folia' => $this->resolveFolia($version),
            'purpur' => $this->resolvePurpur($version),
            'fabric' => throw new MinecraftToolkitException('Für Fabric muss eine Loader-Version ausgewählt werden.'),
            'forge' => throw new MinecraftToolkitException('Für Forge muss eine Loader-Version ausgewählt werden.'),
            'neoforge' => throw new MinecraftToolkitException('Für NeoForge muss eine Loader-Version ausgewählt werden.'),
            default => throw new MinecraftToolkitException('Diese Serversoftware wird noch nicht unterstützt.'),
        };
    }

    /** @return array<string, string> */
    public function loaderVersionOptions(string $software, string $minecraftVersion): array
    {
        if (!in_array($software, ['fabric', 'forge', 'neoforge'], true) || $minecraftVersion === '') {
            return [];
        }

        try {
            $versions = Cache::remember(
                "minecrafttoolkit.loader-versions.$software.$minecraftVersion",
                now()->addMinutes(30),
                fn (): array => $this->fetchLoaderVersions($software, $minecraftVersion)
            );
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }

        return collect($versions)
            ->filter(fn (mixed $version): bool => is_string($version) && $version !== '')
            ->unique()
            ->sortDesc(SORT_NATURAL)
            ->mapWithKeys(fn (string $version): array => [$version => $version])
            ->all();
    }

    /**
     * @return array{
     *   url: string,
     *   source: string,
     *   version_id: string,
     *   file_name: string,
     *   startup: ?string,
     *   installer: bool,
     *   sha256: ?string
     * }
     */
    public function resolveInstallation(string $software, string $version, ?string $loaderVersion): array
    {
        if (!in_array($software, ['fabric', 'forge', 'neoforge'], true)) {
            $download = $this->resolveDownload($software, $version);
            if ($software === 'bedrock') {
                return $download + [
                    'file_name' => 'bedrock-server.zip',
                    'startup' => 'if [ ! -f bedrock_server ]; then unzip -o bedrock-server.zip && chmod +x bedrock_server; fi; LD_LIBRARY_PATH=. ./bedrock_server',
                    'installer' => true,
                    'sha256' => null,
                ];
            }

            return $download + [
                'file_name' => 'server.jar',
                'startup' => null,
                'installer' => false,
                'sha256' => null,
            ];
        }
        if (!is_string($loaderVersion) || !array_key_exists(
            $loaderVersion,
            $this->loaderVersionOptions($software, $version)
        )) {
            throw new MinecraftToolkitException('Wähle eine gültige Loader-Version.');
        }

        return match ($software) {
            'fabric' => $this->resolveFabric($version, $loaderVersion),
            'forge' => $this->resolveForge($version, $loaderVersion),
            'neoforge' => $this->resolveNeoForge($version, $loaderVersion),
        };
    }

    public function isBedrock(string $software): bool
    {
        return $software === 'bedrock';
    }

    /** @return string[] */
    private function fetchVersions(string $software): array
    {
        return match ($software) {
            'vanilla' => $this->vanillaManifest()['versions']
                ? collect($this->vanillaManifest()['versions'])
                    ->where('type', 'release')
                    ->pluck('id')
                    ->values()
                    ->all()
                : [],
            'bedrock' => $this->bedrockVersions(),
            'paper' => collect($this->json('https://fill.papermc.io/v3/projects/paper')['versions'] ?? [])
                ->flatten()
                ->values()
                ->all(),
            'folia' => collect($this->json('https://fill.papermc.io/v3/projects/folia')['versions'] ?? [])
                ->flatten()
                ->values()
                ->all(),
            'purpur' => $this->json('https://api.purpurmc.org/v2/purpur')['versions'] ?? [],
            'fabric' => collect($this->json('https://meta.fabricmc.net/v2/versions/game'))
                ->where('stable', true)
                ->pluck('version')
                ->values()
                ->all(),
            'forge' => $this->forgeMinecraftVersions($this->mavenVersions(
                'https://maven.minecraftforge.net/net/minecraftforge/forge/maven-metadata.xml'
            )),
            'neoforge' => $this->neoForgeMinecraftVersions($this->mavenVersions(
                'https://maven.neoforged.net/releases/net/neoforged/neoforge/maven-metadata.xml'
            )),
            default => [],
        };
    }

    /** @return string[] */
    private function fetchLoaderVersions(string $software, string $minecraftVersion): array
    {
        return match ($software) {
            'fabric' => collect($this->json("https://meta.fabricmc.net/v2/versions/loader/$minecraftVersion"))
                ->pluck('loader.version')
                ->filter(fn (mixed $value): bool => is_string($value))
                ->values()
                ->all(),
            'forge' => collect($this->mavenVersions(
                'https://maven.minecraftforge.net/net/minecraftforge/forge/maven-metadata.xml'
            ))
                ->filter(fn (string $version): bool => str_starts_with($version, "$minecraftVersion-"))
                ->map(fn (string $version): string => substr($version, strlen($minecraftVersion) + 1))
                ->values()
                ->all(),
            'neoforge' => collect($this->mavenVersions(
                'https://maven.neoforged.net/releases/net/neoforged/neoforge/maven-metadata.xml'
            ))
                ->filter(fn (string $version): bool => str_starts_with(
                    $version,
                    $this->neoForgePrefix($minecraftVersion)
                ))
                ->values()
                ->all(),
            default => [],
        };
    }

    /** @return array{url: string, source: string, version_id: ?string} */
    private function resolveVanilla(string $version): array
    {
        $entry = collect($this->vanillaManifest()['versions'] ?? [])->firstWhere('id', $version);
        if (!is_array($entry) || empty($entry['url'])) {
            throw new MinecraftToolkitException("Vanilla $version wurde nicht gefunden.");
        }

        $metadata = $this->json((string) $entry['url']);
        $url = Arr::get($metadata, 'downloads.server.url');
        if (!is_string($url)) {
            throw new MinecraftToolkitException("Für Vanilla $version ist kein Server-Download verfügbar.");
        }

        return ['url' => $url, 'source' => 'official', 'version_id' => $version];
    }

    /** @return array{url: string, source: string, version_id: ?string} */
    private function resolvePaper(string $version): array
    {
        $data = $this->json("https://fill.papermc.io/v3/projects/paper/versions/$version/builds");
        $build = $this->selectPaperBuild($data);
        $url = is_array($build) ? Arr::get($build, 'downloads.server:default.url') : null;

        if (!is_string($url)) {
            throw new MinecraftToolkitException("Für Paper $version wurde kein Build gefunden.");
        }

        return [
            'url' => $url,
            'source' => 'paper',
            'version_id' => isset($build['id']) ? (string) $build['id'] : null,
        ];
    }

    /** @param array<string, mixed>|array<int, array<string, mixed>> $data */
    public function selectPaperBuild(array $data): ?array
    {
        $builds = collect(array_is_list($data) ? $data : ($data['builds'] ?? []));

        return $builds->firstWhere('channel', 'STABLE') ?? $builds->first();
    }

    /** @return array{url: string, source: string, version_id: ?string} */
    private function resolveBedrock(string $version): array
    {
        $download = $this->bedrockDownload();
        if ($version !== $download['version']) {
            throw new MinecraftToolkitException("Bedrock $version ist nicht als offizieller Linux-Download verfügbar. Aktuell verfügbar: {$download['version']}.");
        }

        return [
            'url' => $download['url'],
            'source' => 'official-bedrock',
            'version_id' => $download['version'],
        ];
    }

    /** @return array{url: string, source: string, version_id: ?string} */
    private function resolveFolia(string $version): array
    {
        $data = $this->json("https://fill.papermc.io/v3/projects/folia/versions/$version/builds");
        $build = $this->selectPaperBuild($data);
        $url = is_array($build) ? Arr::get($build, 'downloads.server:default.url') : null;

        if (!is_string($url)) {
            throw new MinecraftToolkitException("Für Folia $version wurde kein Build gefunden.");
        }

        return [
            'url' => $url,
            'source' => 'folia',
            'version_id' => isset($build['id']) ? (string) $build['id'] : null,
        ];
    }

    /** @return array{url: string, source: string, version_id: ?string} */
    private function resolvePurpur(string $version): array
    {
        $data = $this->json("https://api.purpurmc.org/v2/purpur/$version");
        $latest = Arr::get($data, 'builds.latest');
        if (!is_string($latest) && !is_int($latest)) {
            throw new MinecraftToolkitException("Für Purpur $version wurde kein Build gefunden.");
        }

        return [
            'url' => "https://api.purpurmc.org/v2/purpur/$version/$latest/download",
            'source' => 'purpur',
            'version_id' => (string) $latest,
        ];
    }

    /** @return array{url: string, source: string, version_id: string, file_name: string, startup: string, installer: bool, sha256: null} */
    private function resolveFabric(string $minecraftVersion, string $loaderVersion): array
    {
        $installers = $this->json('https://meta.fabricmc.net/v2/versions/installer');
        $stableInstaller = collect($installers)->firstWhere('stable', true);
        $installerVersion = is_array($stableInstaller)
            ? ($stableInstaller['version'] ?? null)
            : ($installers[0]['version'] ?? null);
        if (!is_string($installerVersion)) {
            throw new MinecraftToolkitException('Für Fabric wurde keine Installer-Version gefunden.');
        }

        return [
            'url' => "https://meta.fabricmc.net/v2/versions/loader/$minecraftVersion/$loaderVersion/$installerVersion/server/jar",
            'source' => 'fabric',
            'version_id' => $loaderVersion,
            'file_name' => 'server.jar',
            'startup' => 'java -Xms128M -XX:MaxRAMPercentage=95.0 -Dterminal.jline=false -Dterminal.ansi=true -jar server.jar nogui',
            'installer' => false,
            'sha256' => null,
        ];
    }

    /** @return array{url: string, source: string, version_id: string, file_name: string, startup: string, installer: bool, sha256: string} */
    private function resolveForge(string $minecraftVersion, string $loaderVersion): array
    {
        $fullVersion = "$minecraftVersion-$loaderVersion";
        $marker = ".minecraft-toolkit/forge-$fullVersion.installed";
        $url = "https://maven.minecraftforge.net/net/minecraftforge/forge/$fullVersion/forge-$fullVersion-installer.jar";

        return [
            'url' => $url,
            'source' => 'forge',
            'version_id' => $loaderVersion,
            'file_name' => 'forge-installer.jar',
            'startup' => "mkdir -p .minecraft-toolkit; if [ ! -f $marker ]; then java -jar forge-installer.jar --installServer && touch $marker; fi; chmod +x run.sh; sh run.sh nogui",
            'installer' => true,
            'sha256' => $this->sha256($url),
        ];
    }

    /** @return array{url: string, source: string, version_id: string, file_name: string, startup: string, installer: bool, sha256: string} */
    private function resolveNeoForge(string $minecraftVersion, string $loaderVersion): array
    {
        $marker = ".minecraft-toolkit/neoforge-$loaderVersion.installed";
        $url = "https://maven.neoforged.net/releases/net/neoforged/neoforge/$loaderVersion/neoforge-$loaderVersion-installer.jar";

        return [
            'url' => $url,
            'source' => 'neoforge',
            'version_id' => $loaderVersion,
            'file_name' => 'neoforge-installer.jar',
            'startup' => "mkdir -p .minecraft-toolkit; if [ ! -f $marker ]; then java -jar neoforge-installer.jar --installServer && touch $marker; fi; chmod +x run.sh; sh run.sh nogui",
            'installer' => true,
            'sha256' => $this->sha256($url),
        ];
    }

    /** @param string[] $versions
     *  @return string[]
     */
    public function forgeMinecraftVersions(array $versions): array
    {
        return collect($versions)
            ->map(fn (string $version): ?string => preg_match('/^(\d+(?:\.\d+){1,2})-/', $version, $match)
                ? $match[1]
                : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param string[] $versions
     *  @return string[]
     */
    public function neoForgeMinecraftVersions(array $versions): array
    {
        return collect($versions)
            ->map(function (string $version): ?string {
                if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $version, $match)) {
                    return null;
                }

                if ((int) $match[1] < 26) {
                    return $match[2] === '0'
                        ? '1.' . $match[1]
                        : '1.' . $match[1] . '.' . $match[2];
                }

                return $match[1] . '.' . $match[2] . (isset($match[3]) ? '.' . $match[3] : '');
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function neoForgePrefix(string $minecraftVersion): string
    {
        return str_starts_with($minecraftVersion, '1.')
            ? substr($minecraftVersion, 2) . '.'
            : $minecraftVersion . '.';
    }

    /** @return string[] */
    private function bedrockVersions(): array
    {
        try {
            return [$this->bedrockDownload()['version']];
        } catch (\Throwable $exception) {
            report($exception);

            return config('minecrafttoolkit.bedrock_fallback_versions', []);
        }
    }

    /** @return array{url: string, version: string} */
    private function bedrockDownload(): array
    {
        return Cache::remember('minecrafttoolkit.bedrock.download.linux', now()->addHours(6), function (): array {
            $html = Http::withUserAgent((string) config('minecrafttoolkit.user_agent'))
                ->accept('text/html')
                ->connectTimeout(5)
                ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
                ->get('https://www.minecraft.net/en-us/download/server/bedrock')
                ->throw()
                ->body();

            $normalized = str_replace('\\/', '/', $html);
            if (!preg_match('~https://www\.minecraft\.net/bedrockdedicatedserver/bin-linux/bedrock-server-([0-9.]+)\.zip~', $normalized, $match)) {
                throw new MinecraftToolkitException('Der offizielle Bedrock-Linux-Download konnte nicht gefunden werden.');
            }

            return [
                'url' => $match[0],
                'version' => $match[1],
            ];
        });
    }

    /** @return array<string, mixed> */
    private function vanillaManifest(): array
    {
        return Cache::remember(
            'minecrafttoolkit.manifest.vanilla',
            now()->addHours(6),
            fn (): array => $this->json('https://piston-meta.mojang.com/mc/game/version_manifest_v2.json')
        );
    }

    /** @return array<string, mixed> */
    private function json(string $url): array
    {
        return Http::acceptJson()
            ->withUserAgent((string) config('minecrafttoolkit.user_agent'))
            ->connectTimeout(5)
            ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
            ->get($url)
            ->throw()
            ->json();
    }

    /** @return string[] */
    private function mavenVersions(string $url): array
    {
        $xml = Http::withUserAgent((string) config('minecrafttoolkit.user_agent'))
            ->connectTimeout(5)
            ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
            ->get($url)
            ->throw()
            ->body();
        $metadata = simplexml_load_string($xml);
        if ($metadata === false) {
            throw new MinecraftToolkitException('Die Loader-Metadaten konnten nicht gelesen werden.');
        }

        return collect($metadata->versioning->versions->version ?? [])
            ->map(fn (\SimpleXMLElement $version): string => (string) $version)
            ->filter()
            ->values()
            ->all();
    }

    private function sha256(string $url): string
    {
        $checksum = trim(Http::withUserAgent((string) config('minecrafttoolkit.user_agent'))
            ->connectTimeout(5)
            ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
            ->get($url . '.sha256')
            ->throw()
            ->body());
        if (!preg_match('/^[a-f0-9]{64}$/i', $checksum)) {
            throw new MinecraftToolkitException('Die SHA-256-Prüfsumme des Loader-Installers ist ungültig.');
        }

        return strtolower($checksum);
    }
}
