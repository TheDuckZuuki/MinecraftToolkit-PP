# Changelog

All notable changes to Minecraft Toolkit are documented in this file.

The changelog is maintained under the current release version. New changes are added to the latest version section until the project owner explicitly requests a version bump.

This project is source-available, not open source. See [`LICENSE`](./LICENSE) for usage rights.

## [1.0.0] - Unreleased

### Added

- Added initial Pelican plugin structure for Minecraft Toolkit.
- Added server navigation pages for setup, overview, installer, updater, settings, and version changes.
- Added setup wizard for supported Minecraft server software.
- Added support for Vanilla Java setup with official Mojang server JAR handling.
- Added support for Vanilla Bedrock setup with Bedrock Dedicated Server handling.
- Added support for Paper setup.
- Added support for Purpur setup.
- Added support for Folia setup as a plugin-based server type using `/plugins`.
- Added support for Fabric setup and loader version handling.
- Added support for Forge setup and installer-first-start handling.
- Added support for NeoForge setup and installer-first-start handling.
- Added `eula.txt` generation.
- Added Java `server.properties` generation.
- Added Bedrock server properties generation.
- Added automatic server port handling from the primary Pelican allocation.
- Added server icon upload and `server-icon.png` writing for Java servers.
- Added MOTD configuration during setup.
- Added a basic MOTD formatter helper for Minecraft color and style codes.
- Added optional plugin/mod selection directly inside the setup wizard.
- Added automatic installation of selected packages immediately after setup completion.
- Added Modrinth package search and install support.
- Added CurseForge package source support through the BlueIT/Vercel proxy flow.
- Added optional direct CurseForge API key fallback for private/self-hosted installations.
- Added CurseForge disabled mode when neither proxy nor private key is available.
- Added default CurseForge proxy URL for the BlueIT Vercel proxy.
- Added default proxy token support so normal users do not need to configure a CurseForge API key for the official proxy flow.
- Added default popular package listing in the installer, so users do not need to search before seeing compatible packages.
- Added pagination for package browsing.
- Added package source, project ID, version ID, file name, target path, dependency, and managed-state tracking.
- Added plugin installation for Paper, Purpur, and Folia into `/plugins`.
- Added mod installation for Fabric, Forge, and NeoForge into `/mods`.
- Added crossplay setup for Paper and Purpur.
- Added GeyserMC and Floodgate installation as managed system packages.
- Added Geyser crossplay configuration patching for Bedrock port, MOTD values, and `auth-type: floodgate`.
- Added update checking for managed plugins/mods.
- Added safe update flow with old file backup before replacement.
- Added Minecraft version change workflow with compatibility checks.
- Added compatibility states for compatible, update required, incompatible, unknown, system package, and manual package cases.
- Added support for removing incompatible packages or accepting risk during version changes.
- Added source-available license file.
- Added license section to the README.
- Added German and English language files for plugin-owned UI strings.
- Added locale fallback logic: German is used when the active locale starts with `de`; every other locale falls back to English.
- Added translated labels for navigation, setup, installer, updater, settings, package sources, server software, status messages, and common actions where the text is controlled by this plugin.

### Changed

- Marked the plugin as source-available, not open source.
- Clarified that redistribution, rebranding, public forks, modified public releases, and resale are not allowed without permission.
- Moved the intended public CurseForge flow to a backend proxy so the real API key is not exposed in the plugin source.
- Removed the requirement that every normal plugin user must request their own CurseForge API key.
- Clarified in the README that the bundled proxy token is not a private CurseForge API key.
- Clarified that the actual CurseForge API key must stay on the proxy backend.
- Added Folia to the supported software table in the README.
- Updated README with setup package installation, popular package browsing, MOTD formatter, crossplay behavior, language behavior, installation notes, and changelog reference.
- Kept the plugin language handling isolated to Minecraft Toolkit and did not change the global Pelican locale.
- Kept the plugin version at `1.0.0` because the plugin has not been publicly released yet.

### Fixed

- Fixed Crossplay configuration patching so Geyser `auth-type` is set to `floodgate` more reliably.
- Fixed Crossplay configuration patching so Geyser Bedrock port and MOTD values are written together.
- Fixed Installer behavior so compatible packages can be shown before a search query is entered.
