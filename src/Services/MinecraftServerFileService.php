<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class MinecraftServerFileService
{
    public function write(Server $server, string $path, string $contents): void
    {
        $this->repository($server)->putContent($this->safePath($path), $contents)->throw();
    }

    public function read(Server $server, string $path, ?int $limit = null): string
    {
        return $this->repository($server)->getContent($this->safePath($path), $limit);
    }


    /** @return array<int, array<string, mixed>> */
    public function listDirectory(Server $server, string $path): array
    {
        return collect($this->repository($server)->getDirectory($this->safePath($path)))
            ->filter(fn (mixed $file): bool => is_array($file))
            ->values()
            ->all();
    }

    public function exists(Server $server, string $path): bool
    {
        $path = $this->safePath($path);
        $directory = dirname($path);
        $name = basename($path);

        try {
            return collect($this->repository($server)->getDirectory($directory === '\\' ? '/' : $directory))
                ->contains(fn (mixed $file): bool => is_array($file) && ($file['name'] ?? null) === $name);
        } catch (\Throwable $exception) {
            if ($exception instanceof FileNotFoundException) {
                return false;
            }

            throw $exception;
        }
    }

    public function pullJar(Server $server, string $url, string $fileName = 'server.jar'): void
    {
        $this->pullFile($server, $url, $fileName, ['jar']);
    }

    /** @param string[] $allowedExtensions */
    public function pullFile(Server $server, string $url, string $fileName, array $allowedExtensions = ['jar']): void
    {
        $this->assertFileName($fileName, $allowedExtensions);
        $this->assertDownloadUrl($url);

        $this->repository($server)->pull($url, '/', [
            'filename' => $fileName,
            'foreground' => true,
        ])->throw();
    }

    /** @param string[] $allowedExtensions */
    public function downloadFile(Server $server, string $url, string $fileName, array $allowedExtensions = ['jar']): array
    {
        $this->assertFileName($fileName, $allowedExtensions);
        $this->assertDownloadUrl($url);

        $response = Http::withUserAgent((string) config('minecrafttoolkit.user_agent'))
            ->withHeaders([
                'Accept' => 'application/zip,application/octet-stream,*/*',
            ])
            ->connectTimeout(10)
            ->timeout((int) config('minecrafttoolkit.download_timeout', 300))
            ->get($url)
            ->throw();

        $contents = $response->body();
        if ($contents === '' || strlen($contents) > (int) config('minecrafttoolkit.max_package_bytes', 104857600)) {
            throw new MinecraftToolkitException('The target file name for the package download is empty or exceeds the size limit. It is invalid.');
        }

        $this->write($server, '/' . $fileName, $contents);

        return [
            'sha1' => hash('sha1', $contents),
            'sha256' => hash('sha256', $contents),
            'sha512' => hash('sha512', $contents),
            'size' => strlen($contents),
        ];
    }

    /** @param string[] $allowedExtensions */
    private function assertFileName(string $fileName, array $allowedExtensions): void
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $fileName)
            || !in_array($extension, $allowedExtensions, true)) {
            throw new MinecraftToolkitException('The target file name for the download is invalid.');
        }
    }

    /** @param array<string, string> $hashes */
    public function downloadJar(Server $server, string $url, string $path, array $hashes = []): void
    {
        $this->downloadJarWithMetadata($server, $url, $path, $hashes);
    }

    /** @param array<string, string> $hashes
     *  @return array{sha1: string, sha256: string, sha512: string, size: int, plugin_version: ?string, class_major_version: ?int}
     */
    public function downloadJarWithMetadata(Server $server, string $url, string $path, array $hashes = []): array
    {
        $this->assertDownloadUrl($url);
        $path = $this->safePath($path);
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'jar') {
            throw new MinecraftToolkitException('Only JAR files may be installed.');
        }

        $response = $this->downloadResponse($url);
        $contents = $response->body();

        if ($contents === '' || strlen($contents) > $this->configInt('max_package_bytes', 104857600)) {
            throw new MinecraftToolkitException('The package download is empty or exceeds the size limit.');
        }

        $this->assertJarMagicBytes($contents);
        $this->assertStrongHashPolicy($hashes);

        $expectedSha512 = Arr::get($hashes, 'sha512');
        $expectedSha256 = Arr::get($hashes, 'sha256');
        $expectedSha1 = Arr::get($hashes, 'sha1');
        $expectedMd5 = Arr::get($hashes, 'md5');
        if (is_string($expectedSha512) && !hash_equals(strtolower($expectedSha512), hash('sha512', $contents))) {
            throw new MinecraftToolkitException('The SHA-512 checksum for the download is invalid.');
        }
        if (!is_string($expectedSha512)
            && is_string($expectedSha256)
            && !hash_equals(strtolower($expectedSha256), hash('sha256', $contents))) {
            throw new MinecraftToolkitException('The SHA-256 checksum for the download is invalid.');
        }
        if (!is_string($expectedSha512)
            && !is_string($expectedSha256)
            && is_string($expectedSha1)
            && !hash_equals(strtolower($expectedSha1), hash('sha1', $contents))) {
            throw new MinecraftToolkitException('The SHA-1 checksum for the download is invalid.');
        }
        if (!is_string($expectedSha512)
            && !is_string($expectedSha256)
            && !is_string($expectedSha1)
            && is_string($expectedMd5)
            && !hash_equals(strtolower($expectedMd5), md5($contents))) {
            throw new MinecraftToolkitException('The MD5 checksum for the download is invalid.');
        }

        $metadata = $this->inspectJarContents($contents);

        $this->ensureDirectory($this->repository($server), basename(dirname($path)), dirname(dirname($path)) ?: '/');
        $this->write($server, $path, $contents);

        return $metadata;
    }

    /** @return array{sha1: string, sha256: string, sha512: string, size: int, plugin_version: ?string, class_major_version: ?int} */
    public function inspectJarContents(string $contents): array
    {
        if ($contents === '' || strlen($contents) > $this->configInt('max_package_bytes', 104857600)) {
            throw new MinecraftToolkitException('The package contents are empty or exceed the size limit.');
        }

        $this->assertJarMagicBytes($contents);
        $this->assertSafeJarStructure($contents);

        $metadata = [
            'sha1' => hash('sha1', $contents),
            'sha256' => hash('sha256', $contents),
            'sha512' => hash('sha512', $contents),
            'size' => strlen($contents),
            'plugin_version' => $this->extractPluginVersionFromJar($contents),
            'class_major_version' => $this->extractMaxClassMajorVersionFromJar($contents),
        ];

        $this->assertJavaClassVersionAllowed($metadata['class_major_version']);

        return $metadata;
    }

    private function downloadResponse(string $url): Response
    {
        $currentUrl = $url;

        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $this->assertDownloadUrl($currentUrl);

            $response = Http::withUserAgent($this->configString('user_agent', 'Pelican-Minecraft-Toolkit/1.2.0'))
                ->connectTimeout(5)
                ->timeout($this->configInt('download_timeout', 300))
                ->withoutRedirecting()
                ->get($currentUrl);

            if (!in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response->throw();
            }

            $location = $response->header('Location');
            if (!is_string($location) || trim($location) === '') {
                throw new MinecraftToolkitException('The download was redirected without a valid redirect destination.');
            }

            $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
        }

        throw new MinecraftToolkitException('The download was redirected too many times.');
    }

    private function extractPluginVersionFromJar(string $contents): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mtk-jar-');
        if ($tmp === false) {
            return null;
        }

        try {
            file_put_contents($tmp, $contents);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return null;
            }

            foreach (['plugin.yml', 'paper-plugin.yml', 'bungee.yml', 'velocity-plugin.json'] as $entry) {
                $data = $zip->getFromName($entry);
                if (!is_string($data)) {
                    continue;
                }

                if ($entry === 'velocity-plugin.json') {
                    $json = json_decode($data, true);
                    if (is_array($json) && is_string($json['version'] ?? null)) {
                        return trim($json['version']);
                    }
                }

                if (preg_match('/^version:\s*["\']?([^"\'\r\n#]+)["\']?/mi', $data, $matches)) {
                    return trim($matches[1]);
                }
            }

            return null;
        } finally {
            if (isset($zip) && $zip instanceof \ZipArchive) {
                $zip->close();
            }
            @unlink($tmp);
        }
    }

    private function extractMaxClassMajorVersionFromJar(string $contents): ?int
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }

        return $this->withJar($contents, function (\ZipArchive $zip): ?int {
            $max = null;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || !str_ends_with(strtolower($name), '.class')) {
                    continue;
                }

                $data = $zip->getFromIndex($index);
                if (!is_string($data) || strlen($data) < 8 || substr($data, 0, 4) !== "\xCA\xFE\xBA\xBE") {
                    continue;
                }

                $header = unpack('nminor/nmajor', substr($data, 4, 4));
                $major = is_array($header) ? (int) ($header['major'] ?? 0) : 0;
                if ($major > 0) {
                    $max = $max === null ? $major : max($max, $major);
                }
            }

            return $max;
        });
    }

    private function assertJavaClassVersionAllowed(?int $majorVersion): void
    {
        $allowed = $this->configInt('java_class_version_max', 65);
        if ($majorVersion === null || $allowed <= 0 || $majorVersion <= $allowed) {
            return;
        }

        throw new MinecraftToolkitException(
            "The JAR requires Java class version $majorVersion; the maximum allowed is $allowed."
        );
    }

    public function backupIfPresent(Server $server, string $path): ?string
    {
        if (!$this->exists($server, $path)) {
            return null;
        }

        $timestamp = now()->format('Y-m-d-H-i-s');
        $base = '/.minecraft-toolkit';
        $backupRoot = "$base/backups";
        $target = "$backupRoot/$timestamp";
        $repository = $this->repository($server);

        $this->ensureDirectory($repository, '.minecraft-toolkit', '/');
        $this->ensureDirectory($repository, 'backups', $base);
        $this->ensureDirectory($repository, $timestamp, $backupRoot);
        $repository->renameFiles('/', [[
            'from' => ltrim($path, '/'),
            'to' => ltrim("$target/" . basename($path), '/'),
        ]])->throw();

        return "$target/" . basename($path);
    }

    public function move(Server $server, string $from, string $to): void
    {
        $from = $this->safePath($from);
        $to = $this->safePath($to);
        $this->repository($server)->renameFiles('/', [[
            'from' => ltrim($from, '/'),
            'to' => ltrim($to, '/'),
        ]])->throw();
    }

    public function delete(Server $server, string $path): void
    {
        $path = $this->safePath($path);
        $this->repository($server)->deleteFiles('/', [ltrim($path, '/')])->throw();
    }

    /** @return array<int, array{path: string, created: string, files: array<int, array{name: string, size: int|null}>}> */
    public function listBackups(Server $server, int $limit = 10): array
    {
        $root = '/.minecraft-toolkit/backups';

        try {
            $directories = collect($this->listDirectory($server, $root))
                ->filter(fn (array $file): bool => (bool) ($file['is_file'] ?? false) === false)
                ->sortByDesc(fn (array $file): string => (string) ($file['name'] ?? ''))
                ->take(max(1, $limit));

            return $directories
                ->map(function (array $directory) use ($server, $root): array {
                    $name = (string) ($directory['name'] ?? '');
                    $path = "$root/$name";

                    try {
                        $files = collect($this->listDirectory($server, $path))
                            ->filter(fn (array $file): bool => (bool) ($file['is_file'] ?? true))
                            ->map(fn (array $file): array => [
                                'name' => (string) ($file['name'] ?? ''),
                                'size' => isset($file['size']) ? (int) $file['size'] : null,
                            ])
                            ->filter(fn (array $file): bool => $file['name'] !== '')
                            ->values()
                            ->all();
                    } catch (\Throwable) {
                        $files = [];
                    }

                    return [
                        'path' => $path,
                        'created' => $name,
                        'files' => $files,
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function ensureDirectory(DaemonFileRepository $repository, string $name, string $path): void
    {
        try {
            $repository->createDirectory($name, $path)->throw();
        } catch (\App\Exceptions\Repository\FileExistsException) {
        }
    }

    private function repository(Server $server): DaemonFileRepository
    {
        return (new DaemonFileRepository())->setServer($server);
    }

    private function safePath(string $path): string
    {
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, "\0") || str_contains($path, '../')) {
            throw new MinecraftToolkitException('The file path is invalid.');
        }

        return $path;
    }

    private function assertJarMagicBytes(string $contents): void
    {
        if (str_starts_with($contents, "PK\x03\x04")
            || str_starts_with($contents, "PK\x05\x06")
            || str_starts_with($contents, "PK\x07\x08")) {
            return;
        }

        throw new MinecraftToolkitException('The download is not a valid JAR/ZIP file.');
    }

    /** @param array<string, string> $hashes */
    private function assertStrongHashPolicy(array $hashes): void
    {
        if (!$this->configBool('hash_required', false)) {
            return;
        }

        if (is_string($hashes['sha512'] ?? null) || is_string($hashes['sha256'] ?? null)) {
            return;
        }

        throw new MinecraftToolkitException(
            'This installation requires SHA-256 or SHA-512 for package downloads.'
        );
    }

    private function assertSafeJarStructure(string $contents): void
    {
        if (!class_exists(\ZipArchive::class)) {
            return;
        }

        $this->withJar($contents, function (\ZipArchive $zip): void {
            if ($zip->numFiles > $this->configInt('max_jar_entries', 20000)) {
                throw new MinecraftToolkitException('The JAR file contains too many files.');
            }

            $maxEntryBytes = $this->configInt('max_jar_entry_bytes', 52428800);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);
                if (!is_string($name)
                    || $name === ''
                    || str_contains($name, "\0")
                    || str_contains($name, '\\')
                    || str_starts_with($name, '/')
                    || preg_match('#(^|/)\.\.(/|$)#', $name)
                    || preg_match('/^[A-Za-z]:/', $name)) {
                    throw new MinecraftToolkitException('The JAR contains invalid file paths.');
                }

                $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
                if ($size > $maxEntryBytes) {
                    throw new MinecraftToolkitException('The JAR file contains a single file that is too large.');
                }
            }
        });
    }

    /** @param callable(\ZipArchive): mixed $callback */
    private function withJar(string $contents, callable $callback): mixed
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mtk-jar-');
        if ($tmp === false) {
            return null;
        }

        try {
            file_put_contents($tmp, $contents);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new MinecraftToolkitException('The JAR file could not be opened.');
            }

            return $callback($zip);
        } finally {
            if (isset($zip) && $zip instanceof \ZipArchive) {
                $zip->close();
            }
            @unlink($tmp);
        }
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $base = parse_url($baseUrl);
        $scheme = (string) ($base['scheme'] ?? 'https');
        $host = (string) ($base['host'] ?? '');
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        if (str_starts_with($location, '//')) {
            return "$scheme:$location";
        }
        if (str_starts_with($location, '/')) {
            return "$scheme://$host$port$location";
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return "$scheme://$host$port$directory/$location";
    }

    private function hostUsesPrivateAddress(string $host): bool
    {
        if (!$this->configBool('block_private_download_ips', true)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPrivateAddress($host);
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!is_array($records) || $records === []) {
            $ips = @gethostbynamel($host) ?: [];
            $records = array_map(fn (string $ip): array => ['ip' => $ip], $ips);
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $this->isPrivateAddress($ip)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateAddress(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function configBool(string $key, bool $default): bool
    {
        return filter_var($this->configValue($key, $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function configInt(string $key, int $default): int
    {
        return max(0, (int) $this->configValue($key, $default));
    }

    private function configString(string $key, string $default): string
    {
        return (string) $this->configValue($key, $default);
    }

    private function configValue(string $key, mixed $default): mixed
    {
        try {
            return function_exists('config') ? config("minecrafttoolkit.$key", $default) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function assertDownloadUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || !in_array((int) ($parts['port'] ?? 443), [443], true)) {
            throw new MinecraftToolkitException('For security reasons, the download URL is not allowed.');
        }
        $allowedDomains = [
            'mojang.com',
            'minecraft.net',
            'papermc.io',
            'purpurmc.org',
            'modrinth.com',
            'geysermc.org',
            'fabricmc.net',
            'minecraftforge.net',
            'neoforged.net',
            'curseforge.com',
            'forgecdn.net',
        ];
        $allowed = false;
        foreach ($allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, ".$domain")) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed || $this->hostUsesPrivateAddress($host)) {
            throw new MinecraftToolkitException('For security reasons, the download URL is not allowed.');
        }
    }
}
