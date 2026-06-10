<?php

declare(strict_types=1);

$boolean = static fn (mixed $value, bool $default): bool => filter_var(
    $value,
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
) ?? $default;

return [
    'enabled' => $boolean(env('MINECRAFT_TOOLKIT_ENABLED', true), true),
    'admins_only' => $boolean(env('MINECRAFT_TOOLKIT_ADMINS_ONLY', false), false),
    'backup_before_overwrite' => $boolean(env('MINECRAFT_TOOLKIT_BACKUP_BEFORE_OVERWRITE', true), true),
    'modrinth_enabled' => $boolean(env('MINECRAFT_TOOLKIT_MODRINTH_ENABLED', true), true),
    'curseforge_enabled' => $boolean(env('MINECRAFT_TOOLKIT_CURSEFORGE_ENABLED', false), false),
    // CurseForge is temporarily disabled by default for public builds.
    // To enable it on a private installation, set MINECRAFT_TOOLKIT_CURSEFORGE_ENABLED=true
    // and provide either a private proxy URL or a private local API key in .env.
    // Example proxy default intentionally left empty:
    // 'curseforge_proxy_url' => rtrim((string) env('MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_URL', 'https://blueit42.vercel.app/api/curseforge/proxy'), '/'),
    'curseforge_proxy_url' => rtrim((string) env('MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_URL', ''), '/'),
    'curseforge_proxy_secret' => env('MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_SECRET', ''),
    // Optional local direct API key override for private/selfhosted installs only.
    'curseforge_api_key' => env('MINECRAFT_TOOLKIT_CURSEFORGE_API_KEY', ''),
    'updater_enabled' => $boolean(env('MINECRAFT_TOOLKIT_UPDATER_ENABLED', true), true),
    'version_change_enabled' => $boolean(env('MINECRAFT_TOOLKIT_VERSION_CHANGE_ENABLED', true), true),
    'version_change_users_enabled' => $boolean(env('MINECRAFT_TOOLKIT_VERSION_CHANGE_USERS_ENABLED', true), true),
    'crossplay_enabled' => $boolean(env('MINECRAFT_TOOLKIT_CROSSPLAY_ENABLED', true), true),
    'bedrock_port_required' => $boolean(env('MINECRAFT_TOOLKIT_BEDROCK_PORT_REQUIRED', true), true),
    'http_timeout' => max(5, (int) env('MINECRAFT_TOOLKIT_HTTP_TIMEOUT', 20)),
    'download_timeout' => max(30, (int) env('MINECRAFT_TOOLKIT_DOWNLOAD_TIMEOUT', 300)),
    // Java 21 supports class file version 65. Set to 0 to disable this safety check.
    'java_class_version_max' => (int) env('MINECRAFT_TOOLKIT_JAVA_CLASS_VERSION_MAX', 65),
    'max_icon_bytes' => max(65536, (int) env('MINECRAFT_TOOLKIT_MAX_ICON_BYTES', 2097152)),
    'max_package_bytes' => max(1048576, (int) env('MINECRAFT_TOOLKIT_MAX_PACKAGE_BYTES', 104857600)),
    'user_agent' => env('MINECRAFT_TOOLKIT_USER_AGENT', 'Pelican-Minecraft-Toolkit/1.2.0'),
    'bedrock_fallback_versions' => array_values(array_filter(array_map('trim', explode(',', (string) env('MINECRAFT_TOOLKIT_BEDROCK_FALLBACK_VERSIONS', ''))))),
    'fallback_versions' => [
        '1.21.11',
        '1.21.10',
        '1.21.8',
        '1.21.7',
        '1.21.6',
        '1.21.5',
        '1.21.4',
        '1.20.6',
        '1.20.4',
    ],
];
