# Changelog

All notable changes to Minecraft Toolkit are documented in this file.

The changelog is maintained under the current release version. New changes are added to the latest version section until the project owner explicitly requests a version bump.

This project is source-available, not open source. See [`LICENSE`](./LICENSE) for usage rights.

## [1.2.1] - Unreleased

### Added

- Added BlueIT CurseForge proxy support using signed Toolkit requests with client id, timestamp, nonce, Toolkit marker header, user-agent binding, and HMAC signatures.
- Added CurseForge proxy secret rotation support and clearer 401 troubleshooting documentation for the BlueIT backend flow.
- Added hidden/default CurseForge configuration behavior so proxy URL, shared secret, and direct API key fields stay empty in the panel unless an administrator intentionally overrides them.
- Added broader Forge Minecraft version discovery by merging Maven metadata with Forge promotion metadata, so older Forge Minecraft versions are available in the setup wizard.
- Added optional Vanilla Bedrock download override configuration for cases where the official Minecraft download page cannot be parsed.

### Changed

- Re-enabled CurseForge through the BlueIT Toolkit proxy while keeping the real CurseForge API key outside the public plugin source.
- Changed CurseForge settings text to show only the BlueIT service host/status instead of exposing the internal proxy path in the panel UI.
- Changed the default Toolkit user agent to the BlueIT Toolkit identifier required by the hardened backend.
- Changed setup package selection to support mixed providers: Modrinth and CurseForge selections now remain selected when switching sources or changing search text, and setup installs the combined selection.
- Changed Vanilla Bedrock version loading so the setup wizard always offers a `Latest official Bedrock server` option when the Minecraft download page cannot be parsed.
- Changed Vanilla Bedrock setup downloads to download the official Bedrock ZIP through the panel first instead of using the Wings pull endpoint directly.
- Updated CurseForge proxy documentation for the official REST API flow, allowlisted endpoints, and server-side `x-api-key` handling.

### Fixed

- Fixed Minecraft setup failing with `Call to undefined method MinecraftServerFileService::extractMaxClassMajorVersionFromJar()` by restoring the JAR class-version scanner and Java compatibility guard.
- Fixed Forge loader version discovery for older Minecraft versions by merging Maven loader builds with Forge promotion metadata and refreshing the loader-version cache key.
- Fixed CurseForge setup browsing showing a generic empty result when the proxy request fails; backend failures are now logged and surfaced more clearly during setup package loading.
- Fixed Vanilla Bedrock setup showing `No options available` when the official Minecraft download page cannot be parsed.
- Fixed Vanilla Bedrock `latest` setup by using the configured official Bedrock Linux ZIP fallback when the Minecraft download page cannot be parsed.
- Fixed Vanilla Bedrock setup failing when Wings returns HTTP 500 for `minecraft.net` pull requests by downloading the ZIP through the panel and writing it to the server files afterward.

## [1.2.0] - Previous release

### Note

- Temporarily disabled CurseForge by default for public builds; administrators can still enable it with a private proxy or a private direct API key.
- Removed the default active CurseForge proxy URL and proxy secret from runtime configuration so normal installs only use Modrinth unless CurseForge is explicitly configured.

### Added

- Added paged `server.properties` editing in Minecraft Settings, including standard Java properties plus a full raw editor for unknown or newer values.
- Added a Java class-version safety check for downloaded JAR files. Public builds default to Java 21 / class version 65 and reject newer JARs before writing them to the server.
- Added updater action to install missing required dependencies after the server has already been set up.
- Added updater action to remove managed plugins/mods safely by backing up the file and disabling package management.
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
- Added this `CHANGELOG.md` file.

### Changed

- Changed the setup Mods/Plugins step back to the same card-style package browser used by the installer, without rendering it outside the Mods/Plugins wizard step.
- Changed Geyser configuration patching to update the modern `java.auth-type` and `motd` sections instead of only adding legacy/unused keys.
- Changed the setup wizard defaults so no server software is preselected before the user chooses one.
- Marked the plugin as source-available, not open source.
- Clarified that redistribution, rebranding, public forks, modified public releases, and resale are not allowed without permission.
- Moved the intended public CurseForge flow to a backend proxy so the real API key is not exposed in the plugin source.
- Removed the requirement that every normal plugin user must request their own CurseForge API key.
- Added Folia to the supported software table in the README.
- Updated README with setup package installation, popular package browsing, MOTD formatter, crossplay behavior, language behavior, installation notes, and changelog reference.
- Kept the plugin language handling isolated to Minecraft Toolkit and did not change the global Pelican locale.
- Set the plugin version to `1.2.0` as requested for the next package build.

### Fixed

- Fixed setup package selection showing as an old dropdown instead of the installer-style package browser.
- Fixed setup installs accepting plugin JARs that require a newer Java runtime than the configured server Java version can load.
- Fixed Geyser MOTD/auth patching leaving `motd.primary-motd`, `motd.secondary-motd`, `motd.passthrough-motd`, and `java.auth-type` unchanged.
- Fixed setup package selection by replacing the embedded Livewire package-browser partial inside the wizard with a stable Filament multi-select field, preventing `AnonymousComponent::setupPackageSelected()` 500 errors during Modrinth loading.
- Fixed setup package selection rendering before software selection by removing the custom package-browser view from the setup wizard render path.
- Fixed the setup package browser appearing before a server software was selected by starting the setup wizard with no preselected software.
- Reduced setup wizard Livewire side effects by no longer auto-loading package browser results during software/version/loader field updates.
- Fixed updater/install candidate handling so the exact candidate from the last update check is stored and reused during the actual update instead of resolving a possibly different file later.
- Fixed Modrinth update candidate ordering by sorting compatible versions by publish date before selecting the latest release.
- Fixed CurseForge update candidate ordering by sorting compatible files by file date and file ID before selecting the latest release.
- Added downloaded-JAR metadata verification so updates/installations abort if the JAR itself reports an older or different plugin version than the selected candidate.
- Added update candidate metadata storage to update checks so the UI check and the update action use the same version ID, filename, download URL, hashes, and dependency data.
- Fixed package updates that appeared successful but were detected again afterwards because stale `logs/latest.log` data overwrote the freshly updated database version.
- Fixed Geyser/Floodgate update checks so runtime versions like `2.10.0` no longer downgrade stored build versions like `2.10.0+1162`.
- Added post-download verification so an update only succeeds when the new JAR exists and the old target file was removed or backed up.
- Fixed Crossplay configuration patching so Geyser `auth-type` is set to `floodgate` more reliably.
- Fixed Crossplay configuration patching so Geyser Bedrock port and MOTD values are written together.
- Fixed Installer behavior so compatible packages can be shown before a search query is entered.
- Fixed setup package browsing so the setup page uses the same card-style popular/search list behavior as the installer instead of only a compact multi-select dropdown.
- Fixed package result card image sizing by applying hard width/height limits so large external icons cannot stretch the package list layout.
- Fixed package installation so required dependencies reported by Modrinth or CurseForge are installed automatically before the selected package.
- Added a known dependency rule for ViaRewind so ViaBackwards is treated as required even when source metadata is incomplete.
- Fixed updater checks so package records are synchronized with the actually loaded plugin version from `logs/latest.log` before checking for updates.
- Fixed updater checks so a database version mismatch against the real installed plugin version no longer reports a package as current.
- Added detection of recent plugin load failures from `logs/latest.log`, including missing plugin dependencies and Java class-version incompatibilities.
- Fixed updater status handling so runtime-incompatible packages are reported as errors instead of `up to date`.
- Fixed setup package browser placement so the package browser only appears inside the Mods/Plugins wizard step instead of below every setup step.
