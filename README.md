# ChAoS MVC Theme Builder

> **Build themes the ChAoS way.**

The **ChAoS MVC Theme Builder** is a developer tool for creating, managing, and packaging themes for the ChAoS MVC platform.

Current version: **0.5.1**.

It provides a standardized development workflow so themes are built against the expected ChAoS MVC theme structure rather than assembled manually or according to developer-specific conventions.

---

## 🧙 Theme Development

Theme Builder provides a workspace for developing ChAoS MVC themes, including:

- Creating theme projects
- Managing theme files
- Editing existing projects
- Deleting projects
- Building standard ChAoS MVC theme structures
- Creating distributable theme artifacts
- Downloading generated artifacts from the authenticated project screen
- Preparing themes for certification and release

Theme projects are created using the standard ChAoS MVC theme layout and conventions.

---

## 📦 Theme Projects

Theme Builder manages each theme as its own project.

Projects provide the working environment for the theme's:

- Source files
- Metadata
- Assets
- Configuration
- Release artifacts

Generated themes are stored under the ChAoS MVC user theme directory:

```text
/user/themes/<theme-slug>/
```

Each new project starts with this structure:

```text
<theme-slug>/
├── assets/
│   ├── css/site.css
│   ├── icons/icon.png
│   ├── img/
│   ├── js/site.js
│   └── vendor/bootstrap/
│       ├── css/bootstrap.min.css
│       └── js/bootstrap.bundle.min.js
├── inc/
│   ├── head.php
│   ├── nav.php
│   └── foot.php
└── theme.json
```

### Packaged Bootstrap

Theme Builder packages Bootstrap **5.3.8** with the theme. It does not fetch
Bootstrap from jsDelivr, query a remote version service, or depend on a CDN when
the site renders.

The generated `inc/head.php` loads Bootstrap CSS before `css/site.css`, allowing
the theme stylesheet to intentionally override Bootstrap. The generated
`inc/foot.php` loads `bootstrap.bundle.min.js` before `js/site.js`. The bundle
includes Bootstrap's required Popper support.

When an existing theme is built, Theme Builder restores the packaged Bootstrap
files and adds either missing local asset reference at a safe point in
`inc/head.php` or `inc/foot.php`. It never inserts a second copy when the local
reference is already present. If a customized include has no safe insertion
point, the build stops with an error instead of producing a theme with an
ambiguous dependency.

Bootstrap remains vendor code. Put theme-specific presentation rules in
`assets/css/site.css` and theme-specific behavior in `assets/js/site.js`.

---

## 🛠 Development Workflow

The Theme Builder workflow is designed around a simple progression:

```text
Create Project
      ↓
Edit & Test
      ↓
Validate
      ↓
Build Artifact
      ↓
Certify / Sign
      ↓
Release
```

The builder handles the mechanics while the developer remains responsible for the theme itself.

---

## 🎓 ChAoS MVC Certification

Theme Builder is part of the ChAoS MVC developer certification workflow.

Certification is **not required to learn or use the builder**.

Developers may use Theme Builder to:

- Learn the ChAoS MVC theme architecture
- Build themes
- Test themes
- Create projects
- Practice the official development workflow

Certification determines whether a developer is authorized to **sign their work as ChAoS-certified**.

In other words:

> **You do not need certification to build.  
> Certification status is distinct from publisher signing.**

---

## 🔐 Signing

Developers sign release statements with their configured RSA-SHA256 or OpenPGP publisher key. Core does not use a certification flag as signature verification.

Every new theme project receives a `theme.json`, updated when project settings are saved and included in the ZIP. Existing projects without it receive one when saved or built. Existing extra manifest fields are preserved. Project settings use the signing object:

```json
{
  "algorithm": "none",
  "fingerprint": "",
  "sha256": "",
  "key_id": "",
  "public_key": ""
}
```

`algorithm` may be `none`, `rsa-sha256`, or `openpgp`. Legacy `sha256` selections are treated as unsigned. Creation generates a unique SHA-256 project identity and a matching fingerprint, preserved on ordinary settings edits. This identity is not a release signature or a PGP public-key fingerprint. Each ZIP receives its actual content hash in its release manifest and `.sha256` file. The signing SHA-256 field starts as project identity metadata and may later be replaced with your signing-key fingerprint.

Both RSA-SHA256 and OpenPGP generate a real detached signature of Core's full release statement, including the exact download URL. OpenPGP requires PHP GnuPG 1.5+ and its backend. The release JSON embeds the base64 signature itself and displays its algorithm. A newly built ZIP is unsigned until signing succeeds. See [Core release contract](docs/CORE_RELEASE_CONTRACT.md) for publication instructions and integration tests.

Private signing keys are not intended to become ordinary Theme Builder project files or be stored casually on the hosting server.

Certification and signing remain distinct from theme creation itself.

---

## 🧱 Architecture

Theme Builder follows the same general project and artifact workflow used by the ChAoS MVC Module Builder.

This provides a consistent developer experience across the ChAoS MVC development toolchain while allowing each builder to enforce the standards specific to its artifact type.

```text
ChAoS MVC Developer Tools
│
├── Module Builder
│   └── ChAoS MVC Modules
│
└── Theme Builder
    └── ChAoS MVC Themes
```

---

## 🛡 Core Protection

Theme Builder operates outside the protected ChAoS MVC Core.

The builder creates and manages developer-owned theme resources without requiring modifications to the framework's protected architecture.

> **Protect the core. Grow outward.**

## Module / Data Lifecycle

Theme Builder is a generic, file-backed module and does not create SQL tables. Its lifecycle controls cover the data it actually owns:

- **Delete Data** removes all Theme Builder-managed theme directories, saved project metadata, and generated artifacts while preserving Theme Builder itself.
- **Nuke Module** submits the standard `/admin/uninstall` request so ChAoS MVC Core remains responsible for complete module removal.

Individual theme projects can still be deleted independently. No Core files are changed by Theme Builder.

---

## Project Status

Theme Builder implements the established Module Builder workflow for theme projects, including bounded file operations, validation, packaging, certification checks, release signing, and managed artifacts.

---

## Documentation
[CHANGELOG](docs/CHANGELOG.md)

## ChAoS MVC

Theme Builder is part of the ChAoS MVC developer ecosystem.

**ChAoS MVC**  
*Protect the core. Grow outward.*

### OpenSSL-compatible release files

Local `theme.json` uses `signing.algorithm`, `key_id`, and the base64 public key. Configure publisher trust before signing. Automatic project identity values remain separate from the final ZIP checksum in meaning.

Signing creates `<slug>.remote.json` for publication at your configured `update_url`, a binary `.zip.sig`, and an exact `-release.txt` statement. The remote JSON contains the six Core release fields, including a verified signature; the versioned `.manifest.json` remains the builder receipt. Download the files from the project's artifacts; publication to your developer domain is manual. See [the signing contract](docs/CORE_RELEASE_CONTRACT.md).

### Verification gates (0.5.1)

The builder checks its actual PHP OpenSSL/GnuPG backend, validates the package, signs and verifies the statement, then reopens the saved files and verifies the ZIP checksum and signature using only the configured public key. It then copies the four public files (ZIP, remote JSON, binary signature, statement) into the project's managed release directory under `verified/<release-identity>/` and verifies those copies. Private keys are not copied. Conflicting staged files cause failure, not overwrite.

The build receipt and artifact list report these build-time results and the local verified-copy directory. This is local-only preparation: `developer_domain: not_checked` means neither HTTP availability nor publication at `update_url` has been verified. No webroot writes, server uploads, or Core changes occur. A rebuild invalidates current signature/publication receipts but retains earlier isolated verified copies as release history.

### Current-project build and sign (0.5.1)

The Theme Builder admin does not ask for a ZIP filename. **Build and sign current theme** refreshes the selected project's `theme.json`, builds the versioned ZIP, passes that exact internally returned path to signing, and completes the existing read-back and local-copy verification gates. This prevents stale, foreign, or mistyped artifact names from entering the admin signing workflow.

### Per-project developer certification

Projects use the shared read-only Chaos MVC Developers verification contract. Enter the project's developer, domain, signing algorithm, and published key ID. An exact, active, unexpired theme credential may preload the account's public key/fingerprint and sets local `certified` to `Yes`. Every other result writes `No` while leaving all Builder and local signing features available. Private keys remain upload-only at signing time. See [Certification Integration](docs/CERTIFICATION.md).
