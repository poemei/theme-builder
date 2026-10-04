# Changelog

## [0.5.2] - 2026-10-04

- Generate session-aware theme navigation with guest Login/Register links,
  authenticated Logout, and a conditional Admin link for the established
  ChAoS MVC Admin access level.
- Make generated Logout use POST with an escaped CSRF token, initializing the
  session token when required.
- Add theme-local navigation-button styling so the secure Logout control
  behaves visually like the other navigation links.

## [0.5.1] - 2026-10-04

- Package Bootstrap 5.3.8 CSS and JavaScript inside Theme Builder and copy both
  assets into every newly created theme.
- Backfill the packaged Bootstrap assets and missing local header/footer
  references whenever an existing theme is built.
- Generate theme headers and footers that load Bootstrap from the theme's own
  asset directory, removing the runtime jsDelivr dependency.
- Keep Builder Admin styling on the normal theme render path, now backed by the
  active theme's packaged Bootstrap assets instead of a third-party CDN.

## [0.5.0] - 2026-10-04

- Make the Admin interface's Bootstrap dependency explicit instead of relying
  on the active site theme to provide it.
- Load the current Bootstrap 5.3.8 CSS after the rendered Admin header and the
  matching Bootstrap bundle immediately before the rendered Admin footer.
- Pin both CDN resources to the official version-specific Subresource Integrity
  hashes and anonymous CORS mode.
- Validate the integration with eight Admin asset-order/integrity checks, the
  existing Theme Builder behavior suite, complete PHP syntax checks, manifest
  completeness, and Git whitespace validation.

## [0.4.10] - 2026-09-20

- Complete live theme-project creation, editing, validation, and bounded file operations.
- Generate and retain versioned artifacts with verified local release history.
- Emit the exact six-field remote update JSON consumed by ChAoS MVC Core.
- Correct certification configuration lookup to use Theme Builder's module-owned runtime file.
- Remove the embedded certification transport credential from the Admin interface.
- Exclude installation-specific `data/certification.json` runtime state from source control and release packages.
- Align documentation with canonical `signing.algorithm` metadata and the completed Builder workflow.
- Reserve live OpenPGP round-trip qualification for a Linux server with PHP GnuPG 1.5 or newer.

## [0.4.9] - 2026-09-05

- Align Theme Builder certification, signing, Core verification, and artifact handling with the shared developer-tool release contract.

## [0.4.8] - 2026-09-04

- Prefer PHP cURL with verified TLS for hosted certification requests and retain HTTPS streams as a fallback.

## [0.4.7] - 2026-09-04

- Add one-time, module-owned certification configuration under `data/`.
- Verify and preload the account public key; never store the private key.
- Hide setup after valid configuration and restore it when configuration data is missing.
- Apply the configured developer identity automatically to new theme projects.

## [0.4.6] - 2026-09-04

- Replace the legacy certification protocol with the shared read-only `/developers/verify` contract.
- Verify exact developer, domain, theme credential, algorithm, key ID, active result, and expiry before writing `certified: "Yes"`.
- Keep certification at `No` for mismatch, expiry, malformed response, or unavailable service without disabling Builder or signing work.
- Add per-project signing identity onboarding; verified account responses may preload the public key and OpenPGP fingerprint.
- Cache verified assertions for up to 24 hours and negative assertions for up to five minutes; stale success is never accepted.
- Never request, transmit, cache, or import private keys from certification.
- Document certification and cryptographic release signing as separate trust relationships.

## [0.4.5] - 2026-09-04

- Write the entered developer domain into every generated local `theme.json`, normalized to lowercase.
- Write the form's certification selection as the exact local JSON string `"Yes"` or `"No"`.
- Preserve domain and certification across ordinary project edits.
- Keep certification metadata independent from publisher signing-key configuration.

## [0.4.4] - 2026-09-04

- Remove the manual ZIP-name field from the admin signing flow.
- Build and sign the selected theme as one transaction: refresh theme.json, package current files, locate the resulting ZIP internally, sign it, then run all verification gates.
- Preflight publication policy and the required crypto backend before replacing the current project artifact.
- Retain the lower-level existing-artifact signing method for compatibility, but do not expose it through admin.

## [0.4.3] - 2026-09-04

- Gate signing on the actual PHP crypto backend, not assumptions about the host OS.
- Reopen and verify saved JSON, signature, statement, and ZIP using public-key trust before reporting success.
- Copy only public release files into project release data under verified/<release-identity>/; verify the copies and reject conflicting existing files.
- Record and display build-time verification results separately from unchecked public-domain availability.
- Add tamper tests for saved outputs, copied releases, and ZIP checksums.

## [0.4.2] - 2026-09-04

- Apply DEV_SIGNATURE_OPENSSL.md: generated local manifests use signing.algorithm and base64 public PEM trust.
- Emit separate <slug>.remote.json with exactly Core's six signed release fields for the developer's update_url.
- Emit binary ZIP.sig and exact LF/no-trailing-newline release statement for OpenSSL verification.
- Remove stale remote JSON and statement on rebuild; preserve automatic project identifiers and lifecycle scaffolding.
- Test generated publication JSON against the local Core verifier.

## [0.4.1] - 2026-09-04

- Restore automatic SHA-256 project identity generation on creation, with a matching fingerprint.
- Preserve generated identity during ordinary settings edits; keep ZIP checksums and Core-compatible release signatures separate.

## [0.4.0] - 2026-09-04

- Match Core 98613e2 signed release statements, including exact download URL and embedded base64 signature.
- Implement real OpenPGP detached signing using PHP GnuPG 1.5+ and isolated temporary keyrings.
- Enforce RSA-3072+ and matching public/private keys; remove certification as a publisher-signing gate.
- Sign metadata inside the selected ZIP, not later edits to the live theme.
- Add update_url, creator, and package_hosts settings to generated theme.json.
- Refresh theme.json file inventory on build while preserving additional fields.
- Validate package limits/paths/PHP syntax before signing and invalidate stale signatures on rebuild.
- Add integration tests using Core's actual signature and archive validators.

## [0.3.1] - 2026-09-04

- Generate and maintain theme.json on project creation/settings save; include it in release ZIPs and backfill missing manifests on build.
- Read theme.json as the theme metadata source and preserve additional fields when saving settings.
- Separate unsigned integrity hashing from RSA-SHA256/OpenPGP algorithm selection; stop generating random SHA-256 identities.
- Record and display actual RSA signatures and algorithms in release manifests/artifact feedback.
- Require the RSA private key to match the configured public key; explicitly identify OpenPGP as metadata support, not built-in signing.
- Test generated and packaged manifests, hash correctness, and unsigned OpenPGP metadata behavior.

All notable changes to the ChAoS MVC Theme Builder are documented in this file.

The format follows the development progression of the Theme Builder project.

---

## [0.3.0] - 2026-09-02

### Added

- Added Module / Data Lifecycle controls for module-owned theme projects, metadata, and release artifacts.
- Added standard ChAoS MVC Core Nuke delegation through `/admin/uninstall`.
- Added a required SHA-256 identity to every newly created theme project.
- Added canonical signing metadata with `type`, `fingerprint`, `sha256`, `key_id`, and `public_key` fields.
- Added optional OpenPGP signing identity support, including an optional fingerprint or public key.
- Added authenticated download links for generated project artifacts.
- Added path-confined artifact resolution for downloads.

### Changed

- Bumped Theme Builder to version 0.3.0.
- Release manifests now include the theme project's canonical signing metadata alongside the artifact's calculated SHA-256.
- Saving ordinary project metadata now preserves the existing signing identity unless signing fields are explicitly changed.
- Documented Theme Builder as a generic, file-backed module with no SQL lifecycle.

### Security

- Artifact downloads remain behind the existing administrator authorization boundary.
- Public key metadata is accepted only as strict compact base64 and must be paired with a valid key ID.
- Delete Data is limited to theme slugs tracked in Theme Builder's own metadata.
- No ChAoS MVC Core files were modified.

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
