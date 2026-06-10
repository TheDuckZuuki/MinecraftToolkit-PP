# Minecraft Toolkit

Minecraft Toolkit is a Pelican Panel plugin for setting up and managing Minecraft servers directly from the server panel. It writes files through Wings, stores the selected software and installed packages per server, and blocks risky changes while the server is running.

## Changelog

See [`CHANGELOG.md`](./CHANGELOG.md) for version history and notable changes.

Current pre-release version: `1.0.0`.

## License and usage rights

Minecraft Toolkit is **source-available, not open source**. You may download and use the official plugin on your own Pelican Panel installation, but you may not redistribute it, reupload it, publish forks, publish modified versions, rename it, resell it, or release derivative versions.

Official distribution is only allowed through channels approved by Nico Egger / BlueIT, such as the official GitHub repository and the Pelican Hub. See [`LICENSE`](./LICENSE) for the full terms.

No CurseForge API key is included in the public plugin source. CurseForge access is designed to run through a backend proxy or an optional private local key.

## Features

- Guided setup for Vanilla Java, Vanilla Bedrock, Paper, Folia, Purpur, Fabric, Forge, and NeoForge
- Automatic Minecraft and loader version selection from official sources
- Generation of `eula.txt` and `server.properties`
- Optional plugin/mod selection during setup; selected packages are installed immediately after setup completes
- Installer opens with popular compatible Modrinth/CurseForge packages even before searching
- MOTD formatter helper for Minecraft color and style codes
- Primary server port taken from the Pelican allocation
- Optional validated 64x64 PNG server icon
- Modrinth plugin installation for Paper, Purpur, and Folia
- Modrinth mod installation for Fabric, Forge, and NeoForge
- CurseForge plugin and mod installation through a Toolkit backend proxy or optional private local API key
- Required and optional dependency review
- Optional Geyser and Floodgate crossplay for Paper and Purpur, including Bedrock port, Floodgate auth-type, and Geyser MOTD patching
- Update checks for managed Modrinth, Geyser, and Floodgate packages
- Individual and bulk package updates
- Minecraft version changes with package compatibility checks
- File backups before setup, configuration changes, and package updates
- SHA verification when the package source provides a checksum
- Per-server overview, settings, action logs, and update history

All server files are accessed through Pelican's Wings API. The plugin does not require Panel and Wings to share a filesystem.

## Requirements

- Pelican Panel compatible with `^1.0.0-beta34`
- PHP 8.3 or newer with `curl`, `mbstring`, `openssl`, and `SimpleXML`
- Reachable Wings node
- A Minecraft-compatible Egg; Java servers need a Java image, Bedrock servers need an image with unzip and Bedrock runtime support
- HTTPS access from the Panel host to Mojang, PaperMC, PurpurMC, Fabric, Forge, NeoForge, Modrinth, and GeyserMC
- A stopped server for setup, package installation, updates, or configuration changes
- Permission to edit server files
- Permission to change the startup command when installing Fabric, Forge, NeoForge, or Vanilla Bedrock

No queue worker or scheduler is required. Update checks run only when a user starts them from the Minecraft Updater page.

## Installation through Plugin Upload

1. Create a ZIP whose top-level folder is named `minecrafttoolkit`.
2. Open the Pelican admin plugin page and import the ZIP.
3. Install and enable Minecraft Toolkit.
4. Open the plugin settings and review the security and source options.
5. Clear the Panel cache if the server navigation does not appear immediately.

The Pelican plugin installer discovers and runs the migrations in `database/migrations`.

## Manual Installation

```bash
cd /var/www/pelican
cp -R /path/to/minecrafttoolkit plugins/minecrafttoolkit
php artisan p:plugin:install
php artisan optimize:clear
```

The directory name must remain `minecrafttoolkit`, because it must match the plugin ID in `plugin.json`.

## First Server Setup

1. Create a Pelican server with a Minecraft-compatible Egg.
2. Assign a primary allocation.
3. Stop the server.
4. Open **Minecraft Setup** in the server sidebar.
5. Select the server software and Minecraft version.
6. For Fabric, Forge, or NeoForge, select a compatible loader version. Bedrock uses the current official Linux Bedrock Dedicated Server download.
7. Configure the MOTD, world name, player limit, and gameplay settings.
8. Optionally upload a 64x64 PNG server icon.
9. For Paper or Purpur, optionally enable crossplay and select a Bedrock allocation. Folia intentionally does not expose the crossplay switch because Paper plugin compatibility is not guaranteed.
10. Optionally select multiple compatible plugins or mods that should be installed directly after setup.
11. Review the setup and start it.

Existing target files are moved to:

```text
/.minecraft-toolkit/backups/YYYY-MM-DD-HH-mm-ss/
```

Forge and NeoForge use their official `--installServer` flow during the first server start. Vanilla Bedrock downloads the official Linux Bedrock Dedicated Server ZIP and extracts it during the first server start. That first launch creates `run.sh` and the loader libraries and can take several minutes.

## Supported Server Software

| Software | Package type | Package directory | Notes |
| --- | --- | --- | --- |
| Vanilla Java | none | none | Official Mojang server JAR |
| Paper | plugins | `/plugins` | Supports Modrinth, CurseForge, and crossplay |
| Purpur | plugins | `/plugins` | Supports Modrinth, CurseForge, and crossplay |
| Folia | plugins | `/plugins` | Supports plugin installation with Folia compatibility warnings |
| Fabric | mods | `/mods` | Uses the official Fabric server launcher |
| Forge | mods | `/mods` | Runs the official installer on first start |
| NeoForge | mods | `/mods` | Runs the official installer on first start |

The Java version in the server image must support the selected Minecraft version. Minecraft Toolkit does not replace the server's Docker image.

## Package Installer

After setup, open **Minecraft Installer**:

1. Select Modrinth or CurseForge.
2. Browse the automatically loaded popular compatible package list or search for a project.
3. Review the selected compatible version and its dependencies.
4. Stop the server if it is running.
5. Install the package.

Search results are filtered by the configured Minecraft version, server loader, project type, and server-side compatibility. Client-only projects are blocked. Downloads are restricted to HTTPS and validated JAR filenames.

Required dependencies are shown before installation but are not installed silently. Select and install them separately so every additional package remains visible to the user.

CurseForge uses the official BlueIT Toolkit backend proxy by default, so normal plugin users do not need to request their own CurseForge API key. The CurseForge API key stays on the BlueIT/Vercel proxy and is not shipped inside the public PHP plugin source. Advanced selfhosters can override the proxy URL or configure a private direct API key. If CurseForge is disabled or no valid proxy/key is available, CurseForge is hidden from server users while Modrinth continues to work.

CurseForge does not consistently expose whether a mod is client-only or server-compatible. The Toolkit shows a warning for ambiguous CurseForge mods and requires the user to verify the project description before installation.

## Crossplay

Crossplay is available only for Paper and Purpur.

When enabled, Minecraft Toolkit:

- Downloads Geyser-Spigot and Floodgate-Spigot from GeyserMC
- Verifies their SHA-256 checksums
- Installs them as protected managed system packages
- Stores the selected Bedrock allocation
- Patches Geyser to use the Bedrock port and Floodgate authentication

Geyser normally creates its configuration after the first server start. If `plugins/Geyser-Spigot/config.yml` did not exist during setup:

1. Start the server once.
2. Stop the server again.
3. Open **Minecraft Settings**.
4. Click **Crossplay-Konfiguration anwenden**.

The existing Geyser configuration is backed up and only the relevant YAML values are changed.

## Package Updates

Open **Minecraft Updater** and click **Updates prüfen**. Checks are available while the server is running because they do not modify files.

Before installing an update:

1. Stop the server.
2. Update one package or click **Alle verfügbaren Updates installieren**.
3. Start the server and verify its console and plugin or mod list.

For each update, the old JAR is moved into the Toolkit backup directory before the new file is downloaded. If the download or checksum validation fails, the plugin attempts to restore the old file and leaves the database on the previous version.

The updater currently supports:

- Managed Modrinth plugins
- Managed Modrinth mods
- Managed CurseForge plugins and mods
- Geyser
- Floodgate

Manually uploaded files and the server software itself are not updated automatically.

## Changing the Minecraft Version

Open **Minecraft-Version ändern** after the initial setup:

1. Select a new Minecraft version.
2. For Fabric, Forge, or NeoForge, select a compatible loader version. Bedrock uses the current official Linux Bedrock Dedicated Server download.
3. Run the compatibility check.
4. Review every managed plugin, mod, dependency, and crossplay package.
5. Stop the server.
6. Choose the available change strategy.

Package statuses:

- **Compatible:** the installed package version supports the target.
- **Update required:** a compatible Modrinth version will be installed.
- **System package update:** Geyser or Floodgate will be refreshed.
- **Incompatible:** no compatible version was found.
- **Unknown:** the source is unavailable or has no reliable compatibility data.

When all packages are compatible, the safe change action is available. If blocking packages exist, the user can either back up and disable those packages or explicitly accept the risk and keep them installed.

The server software stays the same during this operation. For example, the page can change Paper 1.21.4 to another Paper version, but it does not convert Paper to Fabric.

The current server artifact and affected package files are backed up before replacement. `server.properties` is preserved. Forge and NeoForge generate their new loader runtime on the next server start.

## Server Settings

The **Minecraft Settings** page changes only the displayed `server.properties` values and preserves unrelated entries. The server must be stopped before saving.

Available settings include:

- MOTD
- Maximum players
- View distance
- Simulation distance
- Online mode
- Whitelist
- PVP
- Flight
- Bedrock allocation and crossplay configuration for Paper/Purpur

## Plugin Configuration

Administrators can configure Minecraft Toolkit from the Pelican plugin settings page.

| Variable | Default | Description |
| --- | --- | --- |
| `MINECRAFT_TOOLKIT_ENABLED` | `true` | Enables the plugin |
| `MINECRAFT_TOOLKIT_ADMINS_ONLY` | `false` | Restricts modifying Toolkit actions to administrators |
| `MINECRAFT_TOOLKIT_BACKUP_BEFORE_OVERWRITE` | `true` | Backs up setup target files before replacement |
| `MINECRAFT_TOOLKIT_MODRINTH_ENABLED` | `true` | Enables Modrinth search and installation |
| `MINECRAFT_TOOLKIT_CURSEFORGE_ENABLED` | `true` | Enables CurseForge when the default proxy, a custom proxy, or a private key is available |
| `MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_URL` | `https://blueit42.vercel.app/api/curseforge/proxy` | Toolkit CurseForge proxy used by default for public installs |
| `MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_SECRET` | `blueit42-minecraft-toolkit-proxy-v1` | Public release token for the default Toolkit proxy; override when using your own proxy |
| `MINECRAFT_TOOLKIT_CURSEFORGE_API_KEY` | empty | Optional private direct CurseForge API key sent through the `x-api-key` request header |
| `MINECRAFT_TOOLKIT_UPDATER_ENABLED` | `true` | Enables the package updater page |
| `MINECRAFT_TOOLKIT_VERSION_CHANGE_ENABLED` | `true` | Enables compatibility checks and Minecraft version changes |
| `MINECRAFT_TOOLKIT_VERSION_CHANGE_USERS_ENABLED` | `true` | Allows non-admin server owners and permitted subusers to change versions |
| `MINECRAFT_TOOLKIT_CROSSPLAY_ENABLED` | `true` | Enables Geyser/Floodgate support |
| `MINECRAFT_TOOLKIT_BEDROCK_PORT_REQUIRED` | `true` | Requires a separate Bedrock allocation |
| `MINECRAFT_TOOLKIT_HTTP_TIMEOUT` | `20` | Metadata API timeout in seconds |
| `MINECRAFT_TOOLKIT_DOWNLOAD_TIMEOUT` | `300` | Package download timeout in seconds |
| `MINECRAFT_TOOLKIT_MAX_ICON_BYTES` | `2097152` | Maximum server icon size |
| `MINECRAFT_TOOLKIT_MAX_PACKAGE_BYTES` | `104857600` | Maximum package size |
| `MINECRAFT_TOOLKIT_USER_AGENT` | `Pelican-Minecraft-Toolkit/1.0` | User-Agent for external requests |

After changing environment values manually, clear cached configuration:

```bash
cd /var/www/pelican
php artisan optimize:clear
```

## Permissions

Root administrators and server owners can use the Toolkit. Subusers need permission to view the server and both create and update files.

Fabric, Forge, and NeoForge setup additionally requires the Pelican startup-update permission because the plugin installs a loader-specific startup command.

Users without the required permissions do not see the corresponding Toolkit pages or receive a permission error instead of a raw exception.

## Security

- Server-changing actions require the server to be offline.
- Download URLs must use HTTPS and an allowlisted official domain.
- Path traversal and unsafe JAR filenames are rejected.
- Package size limits are enforced.
- SHA-512, SHA-256, or SHA-1 is verified when supplied by the source.
- Existing files are backed up before replacement.
- Technical exceptions are written to the Laravel log while the UI receives a short error message.

## MOTD formatting

The setup page includes a small MOTD formatter. Users can choose a color and basic styles such as bold, italic, and underline. The generated Minecraft formatting-code MOTD is written into `server.properties`. Advanced users can still type Minecraft formatting codes manually, for example `§aGreen §lBold §rNormal`.

For Paper/Purpur crossplay, applying the Crossplay configuration also patches Geyser to use Floodgate authentication and writes the selected MOTD into Geyser's Bedrock MOTD fields where possible.

## Troubleshooting

- **Minecraft pages are missing:** verify the plugin is enabled, migrations completed, and run `php artisan optimize:clear`.
- **Setup says no allocation exists:** assign a primary allocation to the Pelican server.
- **Action says the server must be stopped:** stop it completely and wait until Wings reports `offline`, `exited`, `dead`, or `created`.
- **Fabric, Forge, or NeoForge setup is denied:** grant startup-update permission or run the setup as the server owner or an administrator.
- **Forge or NeoForge does not start:** inspect the first-start console, verify the container has the correct Java version, and confirm `/run.sh` was created.
- **No Modrinth results:** verify the selected Minecraft version and loader are supported by the project and that the Panel host can reach `api.modrinth.com`.
- **CurseForge is missing:** verify that the BlueIT Toolkit proxy is reachable, disable local overrides with wrong values, or configure a private local API key. Public plugin builds do not ship with a CurseForge API key.
- **CurseForge has no download URL:** the project author disabled third-party API downloads; that file cannot be installed automatically.
- **Crossplay config is missing:** start Paper/Purpur once, stop it, and apply the config from Minecraft Settings.
- **Bedrock players cannot connect:** verify the selected allocation permits UDP traffic and the node firewall exposes that port.
- **Update failed:** inspect `/.minecraft-toolkit/backups/` and the Laravel log. The updater attempts to restore the previous file automatically.
- **Version change is blocked:** review incompatible and unknown packages, then either secure and disable them or explicitly use the risk option.
- **Server does not start after a risky change:** stop it, restore the relevant files from `/.minecraft-toolkit/backups/`, and return to the previous Minecraft version.
- **Wings is unreachable:** verify node connectivity and credentials; Toolkit file actions intentionally stop instead of writing directly to node volumes.

Logs for setup, installs, crossplay, checks, and updates are stored per server and shown on the Minecraft Overview page.


## CurseForge API handling

CurseForge access is designed around a backend proxy. This keeps the CurseForge API key outside the public plugin source and avoids requiring every normal plugin user to apply for a key for the same project.

Public installs work out of the box with the default BlueIT proxy values:

```env
MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_URL=https://blueit42.vercel.app/api/curseforge/proxy
MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_SECRET=blueit42-minecraft-toolkit-proxy-v1
```

Normal users do not need to set these values manually. They are included as defaults in the plugin config.

If you run your own Vercel or backend proxy, set these variables in the plugin and configure the matching secret on your proxy:

```env
MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_URL=https://your-vercel-domain.vercel.app/api/curseforge/proxy
MINECRAFT_TOOLKIT_CURSEFORGE_PROXY_SECRET=your_proxy_secret
```

Your Vercel/backend proxy project must have:

```env
CURSEFORGE_API_KEY=your_real_curseforge_key
CURSEFORGE_PROXY_SECRET=your_proxy_secret
```

The default proxy secret is a public release token, not a private API key. Do not treat it as protection against abuse. Real protection must happen on the proxy side through rate limiting, caching, allowed operations, and monitoring.

Optional private/selfhosted fallback:

```env
MINECRAFT_TOOLKIT_CURSEFORGE_API_KEY=local_direct_key
```

Do not ship CurseForge API keys inside public plugin builds. The public plugin contains only the default proxy URL and a public release token. If no proxy and no direct private key are configured, CurseForge disables itself safely and Modrinth continues to work.



## Languages

Minecraft Toolkit includes English and German plugin translations.

The plugin automatically uses German when the current Pelican/user locale starts with `de` such as `de`, `de_AT`, or `de_DE`. For every other locale, the plugin injects English strings as the fallback so users do not see untranslated German UI text.
