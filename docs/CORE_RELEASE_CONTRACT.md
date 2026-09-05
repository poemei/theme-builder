# Core release contract

Implemented against stnlabz/chaos-mvc commit `98613e26b644ba9f0c98472dbfda8f7fa737dd66` (1.1.10 development).
Source: https://github.com/stnlabz/chaos-mvc/blob/98613e26b644ba9f0c98472dbfda8f7fa737dd66/docs/RELEASE_SIGNATURE_CONTRACT.md

## Publishing

1. Configure the project's update_url and publisher signing algorithm, key_id and public_key.
   Core accepts RSA-SHA256 with RSA-3072+ keys, or OpenPGP. Public keys may be raw PEM/armor or base64.
   Builders save the accepted `type` alias; Core also accepts `algorithm`.
   None is for local development only. SHA-256 is never a signature.
2. Set additional package_hosts when the ZIP is hosted somewhere other than the update manifest host.
   These settings must be installed locally on consumer sites; remote packages cannot introduce new trust.
3. Build the ZIP. For Theme Builder, save project settings first and then build: signing uses metadata inside the ZIP.
4. Supply the matching private key, its passphrase if encrypted, and the exact final public HTTPS ZIP URL.
   Module Builder combines building and signing; Theme Builder signs the selected built ZIP.
5. Publish the generated `<slug>.remote.json` bytes at update_url and the unchanged ZIP at the signed download URL.
   No automatic upload or deployment occurs. The `.zip.sig` sidecar contains binary signature bytes. `<slug>-<version>-release.txt` contains the exact signed statement, with LF separators and no final newline. The versioned `.manifest.json` is a builder receipt, not the publication file.
   The update JSON's `signature` field is the signature itself, never a filename.

Changing the URL, version, identity, ZIP, hash or key ID requires re-signing. A rebuilt ZIP is unsigned until signing succeeds.
A checksum file is provided for integrity checks, independently of publisher authentication.
A signed release does not assert ChAoS certification; certification metadata remains separate.

## Exact signed statement

UTF-8, LF separators, **no trailing newline**:

```text
CHAOS-MVC-MODULE-RELEASE
module=<slug>
version=<version>
download=<exact HTTPS ZIP URL>
sha256=<lowercase hash of final ZIP bytes>
key_id=<installed publisher key ID>
```

Themes use `CHAOS-MVC-THEME-RELEASE` and `theme=<slug>`.
RSA uses SHA-256 with PKCS#1 v1.5; OpenPGP creates a binary detached signature of the same statement.
Both emit base64 signature bytes in JSON, plus a descriptive signature_algorithm field.
The required remote fields are module/theme, version, download, sha256, key_id, signature.

## OpenPGP runtime

PHP GnuPG 1.5+ and its backend are required on both the signing host and the installing Core host.
Absence is an explicit error, never fallback to unsigned output.
The uploaded secret key is imported into a newly created private temporary keyring, never the default account keyring.
The signature is checked against the configured publisher before publication.
The temporary keyring is removed in a finally block. A cleanup error aborts the operation and requires operator attention.
RSA private keys stay in memory apart from PHP's normal temporary upload.
Never put private keys in a module/theme project directory.

## Package constraints and verification

ZIPs contain exactly the named top-level project directory and its module.json/theme.json.
Theme manifests are generated on creation, updated on settings save, and included in ZIPs.
Additional theme manifest fields are preserved; file inventories refresh on build.
Signing enforces Core's 25 MiB ZIP, 2000-entry, 10 MiB/file and 50 MiB expanded limits,
portable names, no symlinks or case collisions, matching identity/version/trust, and PHP syntax.

Run the behavior suite, then:

```text
php tests/core_contract_test.php /path/to/chaos-mvc
```

The integration test invokes Core's actual signature verifier and archive validator, not a copy.
It checks tampering, mismatched keys/passphrases, checksum-only signatures and package boundaries.
The OpenPGP round-trip uses a disposable test key when GnuPG is available; otherwise it prints an explicit skip
and tests missing-backend failure. Passing local tests does not claim live-domain deployment acceptance.

## Automatic project identity

Creation generates SHA-256 from 32 cryptographically random bytes and saves the same 64-character hexadecimal value in signing.sha256 and signing.fingerprint. Each new project gets its own identity. Normal metadata edits preserve these values. They are project identity metadata, not a calculated OpenPGP key fingerprint, ZIP checksum, or publisher signature. Configuring a real signing key may replace them; Core release signing remains unchanged.

## OpenSSL guide outputs

The generated local `theme.json` pins `signing.algorithm`, `key_id`, and `public_key` (base64 of the full public PEM for RSA). Existing automatic identity SHA-256/fingerprint metadata is preserved; it is not the release signature or ZIP hash. Unsigned drafts use algorithm `none` until publisher trust is configured.

After signing, download `<slug>.remote.json` from the project's artifact list and publish its unchanged contents at the project's exact `update_url` on the developer's domain (renaming the file there if needed). It contains only `theme`, `version`, `download`, `sha256`, `key_id`, and `signature`. Publish the unchanged ZIP at `download`. Neither builder uploads to your domain.

RSA signs and verifies the statement using PHP OpenSSL SHA-256, equivalent to the guide's OpenSSL commands. You can independently verify with:

```text
openssl dgst -sha256 -verify public-key.pem -signature <slug>-<version>.zip.sig <slug>-<version>-release.txt
```

Private keys are never included in generated manifests or artifacts.

### Verification gates (0.4.3)

The builder checks its actual PHP OpenSSL/GnuPG backend, validates the package, signs and verifies the statement, then reopens the saved files and verifies the ZIP checksum and signature using only the configured public key. It then copies the four public files (ZIP, remote JSON, binary signature, statement) into the project's managed release directory under `verified/<release-identity>/` and verifies those copies. Private keys are not copied. Conflicting staged files cause failure, not overwrite.

The build receipt and artifact list report these build-time results and the local verified-copy directory. This is local-only preparation: `developer_domain: not_checked` means neither HTTP availability nor publication at `update_url` has been verified. No webroot writes, server uploads, or Core changes occur. A rebuild invalidates current signature/publication receipts but retains earlier isolated verified copies as release history.

### Current-project build and sign (0.4.4)

The Theme Builder admin does not ask for a ZIP filename. **Build and sign current theme** refreshes the selected project's `theme.json`, builds the versioned ZIP, passes that exact internally returned path to signing, and completes the existing read-back and local-copy verification gates. This prevents stale, foreign, or mistyped artifact names from entering the admin signing workflow.

