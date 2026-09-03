# ChAoS MVC Theme Builder

> **Build themes the ChAoS way.**

The **ChAoS MVC Theme Builder** is a developer tool for creating, managing, and packaging themes for the ChAoS MVC platform.

Current version: **0.3.0**.

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

---

## 🛠 Development Workflow

The Theme Builder workflow is designed around a simple progression:

```text
Create Project
      ↓
Build Theme
      ↓
Edit & Test
      ↓
Validate
      ↓
Create Artifact
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
> You need certification to sign.**

---

## 🔐 Signing

Certified developers may sign eligible release artifacts using their ChAoS MVC developer identity.

Every new theme project receives a required SHA-256 identity. Project settings use the canonical signing object:

```json
{
  "type": "sha256",
  "fingerprint": "",
  "sha256": "64 lowercase hexadecimal characters",
  "key_id": "",
  "public_key": ""
}
```

`type` may be `sha256`, `rsa-sha256`, or `openpgp`. A PGP fingerprint is optional. A public RSA or OpenPGP key may be stored as compact base64 when accompanied by its key ID. The project identity SHA-256 is metadata; each built ZIP also receives a separately calculated content SHA-256 in its release manifest and `.sha256` file.

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

## 🚧 Project Status

**Theme Builder is currently under development.**

The project is being rebuilt using the established Module Builder workflow as its foundation, with module-specific behavior being replaced by the requirements of the ChAoS MVC theme system.

---

## Documentation
[CHANGELOG](docs/CHANGELOG.md)

## ChAoS MVC

Theme Builder is part of the ChAoS MVC developer ecosystem.

**ChAoS MVC**  
*Protect the core. Grow outward.*
