# Changelog

All notable changes to the ChAoS MVC Theme Builder are documented in this file.

The format follows the development progression of the Theme Builder project.

---

## [0.2.0] - 2026-08-30

### Added

- Added signed theme release workflow modeled after the ChAoS MVC Module Builder.
- Added RSA-3072 developer keypair generation.
- Added encrypted private-key handling for release signing.
- Added `key-metadata.json` generation.
- Added RSA-SHA256 release signatures.
- Added release signature verification support.
- Added `.sha256` integrity artifact generation.
- Added `.sig` signature artifact generation.
- Added signed release manifest generation.
- Added developer signing identity support.
- Added signing metadata directly to `theme.json`.
- Added support for the following theme metadata:
  - `theme`
  - `name`
  - `version`
  - `author`
  - `description`
  - `changelog`
  - `update_url`
  - `creator`
  - `domain`
  - `certified`
  - `signing`
- Added `sha256`, `key_id`, and `public_key` fields under the `signing` object.
- Added update URL support for distributed theme update manifests.
- Added creator identity and originating domain metadata.
- Added certification status metadata.
- Added changelog metadata for theme releases.
- Added Windows-safe OpenSSL configuration handling.
- Added in-memory key ZIP generation to avoid retaining developer private keys on the server.
- Added private/public key matching validation before release signing.

### Changed

- Reworked Theme Builder release handling to follow the established ChAoS MVC Module Builder workflow.
- Theme releases now require cryptographic signing.
- Replaced the previous optional signing model with a unified **Build & Sign Release** workflow.
- Release building is now an internal step of the signed-release process rather than a separate public unsigned release action.
- `theme.json` now serves as the authoritative theme identity and release metadata manifest.
- Expanded project editing to manage signing, update, creator, domain, certification, and changelog metadata.
- Theme validation now accounts for the expanded `theme.json` structure.
- Release packaging now derives theme identity and version information from the live `theme.json`.
- Theme packages remain theme-specific and do not inherit module-only concepts such as routes or module file declarations.

### Security

- Private signing keys are not retained by Theme Builder.
- Release signing verifies that the supplied private key corresponds to the expected developer public key.
- Theme packages receive SHA-256 integrity hashes.
- Release packages are signed using RSA-SHA256.
- Signing identity is embedded into the theme manifest for downstream verification.

### Validation

- PHP syntax validation passed for Theme Builder components.
- Theme scaffolding tests passed.
- Theme manifest structure and ordering tests passed.
- Bounded file-operation tests passed.
- RSA-3072 key generation tests passed.
- Signing identity tests passed.
- Administrative Build & Sign workflow tests passed.
- ZIP release/signature integration requires PHP `ZipArchive` and is executed when that extension is available on the ChAoS MVC host.

---

## [0.1.1] - 2026-08-29

### Added

- Added `theme.json` as a required file in every generated theme.
- Added `author` support to Theme Builder project settings.
- Added validation for required theme manifest fields.
- Added theme manifest handling to the release process.

### Changed

- Moved theme identity metadata into the theme itself rather than relying exclusively on Theme Builder project metadata.
- Project settings now update the live `theme.json`.
- Release metadata is derived from the live theme manifest.
- Theme validation now requires `theme.json`.
- Updated generated theme structure to:

```text
user/themes/<slug>/
├── theme.json
├── assets/
│   ├── css/
│   │   └── site.css
│   ├── icons/
│   │   └── icon.png
│   ├── img/
│   └── js/
│       └── site.js
└── inc/
    ├── head.php
    ├── nav.php
    └── foot.php
```

### Theme Manifest

The initial standard theme manifest contains:

```json
{
    "theme": "classic",
    "name": "Classic",
    "version": "1.0.0",
    "author": "STN-LABZ",
    "description": "The Classic Starter Theme"
}
```

### Validation

Theme Builder now validates that:

- `theme.json` exists.
- `theme` is present.
- `name` is present.
- `version` is present.
- `author` is present.
- `description` is present.
- The `theme` value matches the project/theme slug.

### Tests

- Updated behavior tests for `theme.json`.
- PHP syntax checks passed.
- Theme Builder behavior tests passed.

---

## [0.1.0] - 2026-08-29

### Added

- Initial ChAoS MVC Theme Builder implementation.
- Added administrative Theme Builder interface.
- Added theme project creation.
- Added theme project editing.
- Added theme project deletion.
- Added bounded live theme file editing.
- Added file creation.
- Added directory creation.
- Added file and directory rename operations.
- Added file and directory deletion.
- Added theme structure validation.
- Added release package generation.
- Added SHA-256 release artifact generation.
- Added release manifest generation.
- Added certification integration groundwork.
- Added developer signing workflow groundwork.
- Added generated artifact display for Theme Builder projects.
- Added support for existing themes located under `user/themes/`.

### Theme Scaffolding

New theme projects generate the standard starter structure:

```text
user/themes/<slug>/
├── assets/
│   ├── css/
│   │   └── site.css
│   ├── icons/
│   │   └── icon.png
│   ├── img/
│   └── js/
│       └── site.js
└── inc/
    ├── head.php
    ├── nav.php
    └── foot.php
```

### Starter Theme

- Added generic `head.php` generation.
- Added generic `nav.php` generation.
- Added generic `foot.php` generation.
- Added generic `site.css`.
- Added starter `site.js`.
- Added placeholder theme icon.
- Starter theme deliberately contains no site-specific or developer-specific content.
- Starter CSS provides a usable neutral baseline without attempting to define a finished theme.

### ChAoS MVC Integration

Generated theme shells use ChAoS MVC presentation facilities including:

- `$SITE`
- `URLROOT`
- `theme::assetUrl()`
- Theme-local navigation.
- Theme-local CSS.
- Theme-local JavaScript.
- Optional OpenGraph metadata.

Theme Builder does not require Codex or public API discovery for theme development.

### Project Management

- Theme source is maintained under:

```text
user/themes/<slug>/
```

- Release artifacts are maintained under:

```text
releases/<slug>/
```

- Added project slug validation.
- Added safe project enumeration.
- Added support for recognizing existing theme directories.

### File Operations

- Added bounded filesystem operations restricted to the selected theme.
- Added path traversal protection.
- Added null-byte protection.
- Added invalid path validation.
- Added symlink escape protection.
- Added theme-root boundary enforcement.
- Added text editor support for common theme-development formats including PHP, JSON, Markdown, CSS, JavaScript, HTML, XML, SVG, YAML, and plain text.

### Validation

Initial theme validation checks for:

- `assets/css/`
- `assets/icons/`
- `assets/img/`
- `assets/js/`
- `inc/`
- `assets/css/site.css`
- `assets/icons/icon.png`
- `assets/js/site.js`
- `inc/head.php`
- `inc/nav.php`
- `inc/foot.php`

Theme shell validation also checks that `head.php` integrates with expected ChAoS MVC theme facilities.

### Release Packaging

- Added ZIP theme package generation.
- Added SHA-256 package hashing.
- Added JSON release manifest generation.
- Theme ZIPs contain the complete theme directory under the theme slug.

### Certification

- Added certification integration groundwork.
- Uncertified developers could initially create, edit, validate, and package themes.
- Initial implementation reserved certified signing for authorized developers.
- Private signing keys were designed not to be retained by the local Theme Builder installation.

> This unsigned-release behavior was superseded in version 0.2.0 when signed releases became mandatory.

### Tests

- Added Theme Builder behavior test suite.
- Tested theme creation.
- Tested generated scaffold.
- Tested theme validation.
- Tested bounded file editing.
- Tested path traversal rejection.
- Tested project metadata editing.
- Tested project deletion.
- Added conditional release-package testing when PHP `ZipArchive` is available.
- Initial behavior tests passed.

---

## Development Direction

Theme Builder was established as the theme-development counterpart to the ChAoS MVC Module Builder.

Its development progression has been:

**0.1.0**  
Initial theme workspace, scaffolding, validation, file management, and release packaging.

**0.1.1**  
Established `theme.json` as part of the theme itself and as the authoritative theme identity manifest.

**0.2.0**  
Aligned the release workflow with Module Builder and established cryptographically signed theme releases with developer identity, integrity verification, update metadata, and certification information.

The first reference theme intended to be developed through Theme Builder is **Classic**.