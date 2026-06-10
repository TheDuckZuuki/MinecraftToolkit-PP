<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
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
        $this->assertDownloadUrl($url);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $fileName)
            || !in_array($extension, $allowedExtensions, true)) {
            throw new MinecraftToolkitException('Der Zieldateiname für den Download ist ungültig.');
        }

        $this->repository($server)->pull($url, '/', [
            'filename' => $fileName,
            'foreground' => true,
        ])->throw();
    }

    /** @param array<string, string> $hashes */
    public function downloadJar(Server $server, string $url, string $path, array $hashes = []): void
    {
        $this->assertDownloadUrl($url);
        $path = $this->safePath($path);
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'jar') {
            throw new MinecraftToolkitException('Es dürfen nur JAR-Dateien installiert werden.');
        }

        $response = Http::withUserAgent((string) config('minecrafttoolkit.user_agent'))
            ->connectTimeout(5)
            ->timeout((int) config('minecrafttoolkit.download_timeout', 300))
            ->get($url)
            ->throw();
        $contents = $response->body();

        if ($contents === '' || strlen($contents) > (int) config('minecrafttoolkit.max_package_bytes', 104857600)) {
            throw new MinecraftToolkitException('Der Paketdownload ist leer oder überschreitet das Größenlimit.');
        }

        $expectedSha512 = Arr::get($hashes, 'sha512');
        $expectedSha256 = Arr::get($hashes, 'sha256');
        $expectedSha1 = Arr::get($hashes, 'sha1');
        $expectedMd5 = Arr::get($hashes, 'md5');
        if (is_string($expectedSha512) && !hash_equals(strtolower($expectedSha512), hash('sha512', $contents))) {
            throw new MinecraftToolkitException('Die SHA-512-Prüfsumme des Downloads ist ungültig.');
        }
        if (!is_string($expectedSha512)
            && is_string($expectedSha256)
            && !hash_equals(strtolower($expectedSha256), hash('sha256', $contents))) {
            throw new MinecraftToolkitException('Die SHA-256-Prüfsumme des Downloads ist ungültig.');
        }
        if (!is_string($expectedSha512)
            && !is_string($expectedSha256)
            && is_string($expectedSha1)
            && !hash_equals(strtolower($expectedSha1), hash('sha1', $contents))) {
            throw new MinecraftToolkitException('Die SHA-1-Prüfsumme des Downloads ist ungültig.');
        }
        if (!is_string($expectedSha512)
            && !is_string($expectedSha256)
            && !is_string($expectedSha1)
            && is_string($expectedMd5)
            && !hash_equals(strtolower($expectedMd5), md5($contents))) {
            throw new MinecraftToolkitException('Die MD5-Prüfsumme des Downloads ist ungültig.');
        }

        $this->ensureDirectory($this->repository($server), basename(dirname($path)), dirname(dirname($path)) ?: '/');
        $this->write($server, $path, $contents);
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
            throw new MinecraftToolkitException('Der Dateipfad ist ungültig.');
        }

        return $path;
    }

    private function assertDownloadUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
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
        $allowed = collect($allowedDomains)->contains(
            fn (string $domain): bool => $host === $domain || str_ends_with($host, ".$domain")
        );

        if (($parts['scheme'] ?? null) !== 'https' || !$allowed) {
            throw new MinecraftToolkitException('Die Download-URL ist aus Sicherheitsgründen nicht erlaubt.');
        }
    }
}
