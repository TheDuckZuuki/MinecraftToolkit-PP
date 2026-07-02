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
    'curseforge_enabled' => $boolean(env('MINECRAFT_TOOLKIT_CURSEFORGE_ENABLED', true), true),
    // Default public flow uses the BlueIT service so the real CurseForge API key stays outside the plugin source.
    // Private/self-hosted installs may override the service URL, shared secret, or use a direct local API key in .env.
    'curseforge_proxy_url' => rtrim((string) env('MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_URL', 'https://blueit42.vercel.app' . '/api' . '/curseforge' . '/proxy'), '/'),
    'curseforge_proxy_secret' => env('MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_SECRET', 'blueit42-minecraft-toolkit-proxy-v1'),
    'curseforge_proxy_client_id' => env('MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_CLIENT_ID', 'minecraft-toolkit'),
    'curseforge_proxy_signed_requests' => $boolean(env('MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_SIGNED_REQUESTS', true), true),
    // Optional local direct API key override for private/self-hosted installs only.
    'curseforge_api_key' => env('MINECRAFT_TOOLKIT_CURSEFORGE_API_KEY', ''),
    'updater_enabled' => $boolean(env('MINECRAFT_TOOLKIT_UPDATER_ENABLED', true), true),
    'version_change_enabled' => $boolean(env('MINECRAFT_TOOLKIT_VERSION_CHANGE_ENABLED', true), true),
    'version_change_users_enabled' => $boolean(env('MINECRAFT_TOOLKIT_VERSION_CHANGE_USERS_ENABLED', true), true),
    'crossplay_enabled' => $boolean(env('MINECRAFT_TOOLKIT_CROSSPLAY_ENABLED', true), true),
    'bedrock_port_required' => $boolean(env('MINECRAFT_TOOLKIT_BEDROCK_PORT_REQUIRED', true), true),
    'bedrock_download_url' => env('MINECRAFT_TOOLKIT_BEDROCK_DOWNLOAD_URL', 'https://www.minecraft.net/bedrockdedicatedserver/bin-linux/bedrock-server-1.26.23.1.zip'),
    'bedrock_download_version' => env('MINECRAFT_TOOLKIT_BEDROCK_DOWNLOAD_VERSION', '1.26.23.1'),
    'http_timeout' => max(5, (int) env('MINECRAFT_TOOLKIT_HTTP_TIMEOUT', 20)),
    'download_timeout' => max(30, (int) env('MINECRAFT_TOOLKIT_DOWNLOAD_TIMEOUT', 300)),
    'block_private_download_ips' => $boolean(env('MINECRAFT_TOOLKIT_BLOCK_PRIVATE_DOWNLOAD_IPS', true), true),
    'hash_required' => $boolean(env('MINECRAFT_TOOLKIT_HASH_REQUIRED', false), false),
    // Java 21 supports class file version 65. Set to 0 to disable this safety check.
    'java_class_version_max' => (int) env('MINECRAFT_TOOLKIT_JAVA_CLASS_VERSION_MAX', 65),
    'max_icon_bytes' => max(65536, (int) env('MINECRAFT_TOOLKIT_MAX_ICON_BYTES', 2097152)),
    'max_package_bytes' => max(1048576, (int) env('MINECRAFT_TOOLKIT_MAX_PACKAGE_BYTES', 104857600)),
    'max_jar_entries' => max(100, (int) env('MINECRAFT_TOOLKIT_MAX_JAR_ENTRIES', 20000)),
    'max_jar_entry_bytes' => max(1048576, (int) env('MINECRAFT_TOOLKIT_MAX_JAR_ENTRY_BYTES', 52428800)),
    'user_agent' => env('MINECRAFT_TOOLKIT_USER_AGENT', 'BlueIT-MinecraftToolkit/1.3.0'),
    'bedrock_fallback_versions' => array_values(array_filter(array_map('trim', explode(',', (string) env('MINECRAFT_TOOLKIT_BEDROCK_FALLBACK_VERSIONS', 'latest,1.26.23.1'))))),
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
