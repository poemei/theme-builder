<?php
declare(strict_types=1);

/* [AI:GPT-5.6 | 2026-09-04 UTC] */
/**
 * Publisher-side implementation of Core release_signature contract at 98613e2.
 * Packaged independently with each builder; never loads or changes Core.
 */
final class builder_release_signer
{
    /** Stage public release files inside the project's managed release data; never private keys. */
    public static function stageLocalRelease(string $kind, array $metadata, array $manifest, string $artifact): array
    {
        $releaseRoot = realpath(dirname($artifact));
        if ($releaseRoot === false || is_link(dirname($artifact))) {
            throw new RuntimeException('Release data directory is unavailable or is a symlink.');
        }
        self::verifyWrittenRelease($kind, $metadata, $manifest, $artifact);
        $identity = hash('sha256', self::statement($kind, $manifest) . $manifest['signature']);
        $directory = $releaseRoot;
        foreach (['verified', $identity] as $component) {
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $component)) {
                throw new RuntimeException('Invalid local staging path component.');
            }
            $directory .= DIRECTORY_SEPARATOR . $component;
            if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
                throw new RuntimeException('Local staging path must be a real directory.');
            }
            if (!is_dir($directory) && !mkdir($directory, 0755)) {
                throw new RuntimeException('Cannot create local staging directory.');
            }
            $resolved = realpath($directory);
            if ($resolved === false || !str_starts_with($resolved, $releaseRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Local staging path escapes release data.');
            }
        }
        $stagedArtifact = $directory . DIRECTORY_SEPARATOR . basename($artifact);
        $files = self::releaseFiles($kind, $metadata, $manifest, $artifact);
        // No directory traversal/copy: only the exact ZIP and generated public sidecars.
        $files[$artifact] = file_get_contents($artifact);
        foreach ($files as $source => $bytes) {
            $destination = $directory . DIRECTORY_SEPARATOR . basename($source);
            if (is_link($destination)) throw new RuntimeException('Refusing symlink in release staging.');
            if (file_exists($destination)) {
                if (!is_file($destination) || file_get_contents($destination) !== $bytes) {
                    throw new RuntimeException('Existing staged release differs; refusing overwrite.');
                }
                continue;
            }
            $handle = @fopen($destination, 'xb');
            if ($handle === false) throw new RuntimeException('Cannot create staged release file.');
            try {
                if (!is_string($bytes) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                    throw new RuntimeException('Cannot finish staged release file.');
                }
            } finally {
                fclose($handle);
            }
        }
        $status = self::verifyWrittenRelease($kind, $metadata, $manifest, $stagedArtifact);
        $status['publication'] = 'local_data_verified';
        $status['staging_directory'] = $directory;
        $status['developer_domain'] = 'not_checked';
        return $status;
    }

    public static function requireBackend(string $algorithm): void
    {
        if ($algorithm === 'rsa-sha256' && (!extension_loaded('openssl')
            || !is_callable('openssl_sign') || !is_callable('openssl_verify'))) {
            throw new RuntimeException('RSA signing and verification require the PHP OpenSSL extension. An installed openssl command alone is not sufficient.');
        }
        if ($algorithm === 'openpgp' && (!extension_loaded('gnupg')
            || version_compare((string) phpversion('gnupg'), '1.5.0', '<'))) {
            throw new RuntimeException('OpenPGP signing requires PHP GnuPG 1.5+ for signing and verification. No unsigned fallback.');
        }
    }

    /** Reopen saved outputs and verify using only the pinned public key, never the private key. */
    public static function verifyWrittenRelease(string $kind, array $metadata, array $expected, string $artifact): array
    {
        self::archive($kind, $metadata, $artifact);
        $files = self::releaseFiles($kind, $metadata, $expected, $artifact);
        foreach ($files as $path => $bytes) {
            if (is_link($path) || !is_file($path) || file_get_contents($path) !== $bytes) {
                throw new RuntimeException('Release read-back verification failed: ' . basename($path));
            }
        }
        $remote = json_decode((string) file_get_contents(dirname($artifact) . '/' . $metadata[$kind] . '.remote.json'), true, 512, JSON_THROW_ON_ERROR);
        self::verifyRelease($kind, $metadata, $remote, $artifact);
        return [
            'package' => 'verified',
            'signature' => 'verified',
            'saved_release_files' => 'verified',
            'verified_at' => gmdate('c'),
            'publication' => 'not_published',
            'developer_domain' => 'not_checked',
            'publish_url' => (string) $metadata['update_url'],
        ];
    }

    public static function verifyRelease(string $kind, array $metadata, array $remote, string $artifact): void
    {
        $trust = (array) ($metadata['signing'] ?? []);
        $algorithm = self::algorithm($trust);
        self::requireBackend($algorithm);
        self::publication($metadata, (string) ($remote['download'] ?? ''));
        foreach ([$kind, 'version'] as $field) {
            if (($remote[$field] ?? null) !== ($metadata[$field] ?? null)) {
                throw new RuntimeException('Release identity does not match packaged trust.');
            }
        }
        if (($remote['key_id'] ?? null) !== ($trust['key_id'] ?? null)
            || !is_string($remote['sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/', $remote['sha256'])
            || !is_file($artifact) || is_link($artifact)
            || !hash_equals($remote['sha256'], (string) hash_file('sha256', $artifact))) {
            throw new RuntimeException('Release key ID or ZIP checksum verification failed.');
        }
        $signature = base64_decode((string) ($remote['signature'] ?? ''), true);
        if (!is_string($signature) || $signature === '') {
            throw new RuntimeException('Release signature missing or malformed.');
        }
        $public = self::publicKey((string) ($trust['public_key'] ?? ''));
        $statement = self::statement($kind, $remote);
        if ($algorithm === 'rsa-sha256') {
            $key = @openssl_pkey_get_public($public);
            $details = $key === false ? false : openssl_pkey_get_details($key);
            if (!is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 3072
                || openssl_verify($statement, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
                throw new RuntimeException('Saved release RSA signature verification failed.');
            }
        } else {
            self::pgp($public, null, '', $statement, $signature);
        }
    }

    public static function invalidatePublication(string $artifact, string $slug): void
    {
        foreach ([dirname($artifact) . '/' . $slug . '.remote.json', substr($artifact, 0, -4) . '-release.txt', $artifact . '.sig', substr($artifact, 0, -4) . '.manifest.json'] as $path) {
            if ((is_file($path) || is_link($path)) && !unlink($path)) {
                throw new RuntimeException('Cannot invalidate previous publication output.');
            }
        }
    }

    /** Publish only Core's six remote fields, plus OpenSSL-compatible sidecars. */
    public static function releaseFiles(string $kind, array $metadata, array $manifest, string $artifact): array
    {
        $remote = array_intersect_key($manifest, array_flip([$kind, 'version', 'download', 'sha256', 'key_id', 'signature']));
        $signature = base64_decode($remote['signature'], true);
        if (!is_string($signature) || $signature === '') {
            throw new RuntimeException('Invalid detached signature.');
        }
        // Stable, distinct filename; publish these bytes at the configured update_url.
        $destination = dirname($artifact) . '/' . $metadata[$kind] . '.remote.json';
        return [
            $artifact . '.sig' => $signature,
            substr($artifact, 0, -4) . '-release.txt' => self::statement($kind, $remote),
            $destination => json_encode($remote, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        ];
    }

    public static function algorithm(array $trust): string
    {
        $algorithm = strtolower((string) ($trust['algorithm'] ?? $trust['type'] ?? ''));
        if (isset($trust['algorithm'], $trust['type'])
            && strtolower((string) $trust['algorithm']) !== strtolower((string) $trust['type'])) {
            throw new RuntimeException('Conflicting signing algorithm and type.');
        }
        if ($algorithm === 'pgp') {
            $algorithm = 'openpgp';
        }
        if (!in_array($algorithm, ['rsa-sha256', 'openpgp'], true)) {
            throw new RuntimeException('Configure RSA-SHA256 or OpenPGP signing. Checksums cannot authorize updates.');
        }
        return $algorithm;
    }

    public static function publicKey(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, '-----BEGIN ')) {
            return $value;
        }
        $decoded = base64_decode($value, true);
        if (!is_string($decoded) || $decoded === '') {
            throw new RuntimeException('A public PEM or OpenPGP key is required (raw or base64).');
        }
        return $decoded;
    }

    /** Validate publication URLs without fetching or rewriting the signed URL. */
    public static function publication(array $metadata, string $download): void
    {
        $source = self::https((string) ($metadata['update_url'] ?? ''));
        $target = self::https($download);
        $allowed = array_merge([$source], (array) ($metadata['package_hosts'] ?? []));
        if (!in_array($target, array_map('strtolower', $allowed), true)) {
            throw new RuntimeException('Download host must match update_url or an installed package_hosts entry.');
        }
    }

    private static function https(string $url): string
    {
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new RuntimeException('Publication URLs must be exact HTTPS URLs on port 443 without credentials or fragments.');
        }
        $host = strtolower($parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local')
            || (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
                && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw new RuntimeException('Publication URLs must use a public host.');
        }
        return $host;
    }

    public static function statement(string $kind, array $manifest): string
    {
        if (!in_array($kind, ['module', 'theme'], true)) {
            throw new InvalidArgumentException('Unknown release kind.');
        }
        foreach ([$kind, 'version', 'download', 'sha256', 'key_id'] as $field) {
            if (!is_string($manifest[$field] ?? null) || $manifest[$field] === ''
                || preg_match('/[\r\n\x00]/', $manifest[$field])) {
                throw new RuntimeException('Invalid release statement field: ' . $field);
            }
        }
        return implode("\n", [
            'CHAOS-MVC-' . strtoupper($kind) . '-RELEASE',
            $kind . '=' . $manifest[$kind],
            'version=' . $manifest['version'],
            'download=' . $manifest['download'],
            'sha256=' . $manifest['sha256'],
            'key_id=' . $manifest['key_id'],
        ]);
    }

    /** Return publishable JSON fields only after creating and verifying a real signature. */
    public static function sign(string $kind, array $metadata, string $artifact, string $download, string $private, string $passphrase): array
    {
        self::archive($kind, $metadata, $artifact);
        self::publication($metadata, $download);
        $trust = (array) ($metadata['signing'] ?? []);
        $algorithm = self::algorithm($trust);
        self::requireBackend($algorithm);
        $public = self::publicKey((string) ($trust['public_key'] ?? ''));
        $hash = hash_file('sha256', $artifact);
        if (!is_string($hash)) {
            throw new RuntimeException('Cannot hash release ZIP.');
        }
        $manifest = [
            $kind => (string) ($metadata[$kind] ?? ''),
            'version' => (string) ($metadata['version'] ?? ''),
            'download' => $download,
            'sha256' => $hash,
            'key_id' => (string) ($trust['key_id'] ?? ''),
        ];
        $statement = self::statement($kind, $manifest);
        if ($algorithm === 'rsa-sha256') {
            $key = @openssl_pkey_get_private($private, $passphrase);
            $details = $key === false ? false : openssl_pkey_get_details($key);
            $publicHandle = @openssl_pkey_get_public($public);
            $expected = $publicHandle === false ? false : openssl_pkey_get_details($publicHandle);
            if (!is_array($details) || !is_array($expected)
                || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
                || (int) ($details['bits'] ?? 0) < 3072
                || !hash_equals($expected['key'], $details['key'])) {
                throw new RuntimeException('Private key/passphrase must match the configured RSA-3072+ public key.');
            }
            $signature = '';
            if (!openssl_sign($statement, $signature, $key, OPENSSL_ALGO_SHA256)
                || openssl_verify($statement, $signature, $publicHandle, OPENSSL_ALGO_SHA256) !== 1) {
                throw new RuntimeException('RSA release statement signing or verification failed.');
            }
        } else {
            $signature = self::pgp($public, $private, $passphrase, $statement);
        }
        $manifest['signature'] = base64_encode($signature);
        $manifest['signature_algorithm'] = $algorithm;
        $manifest['signed'] = true;
        $manifest['signed_at'] = gmdate('c');
        return $manifest;
    }


    /** Bound and validate the ZIP before producing an authenticated release. */
    private static function archive(string $kind, array $metadata, string $artifact): void
    {
        if (!is_file($artifact) || is_link($artifact) || filesize($artifact) > 26214400) {
            throw new RuntimeException('Release ZIP is missing or exceeds Core\'s 25 MiB limit.');
        }
        $slug = (string) ($metadata[$kind] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,62}$/', $slug)) {
            throw new RuntimeException('Invalid package identity.');
        }
        $zip = new ZipArchive();
        if ($zip->open($artifact) !== true) {
            throw new RuntimeException('Invalid ZIP.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 2000) {
                throw new RuntimeException('Core accepts 1–2000 archive entries.');
            }
            $seen = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                $key = strtolower(rtrim($name, '/'));
                if (!str_starts_with($name, $slug . '/') || str_contains($name, '\\')
                    || str_contains($name, "\0") || isset($seen[$key])) {
                    throw new RuntimeException('Archive boundary, path or collision violation.');
                }
                $seen[$key] = true;
                foreach (explode('/', rtrim($name, '/')) as $part) {
                    if ($part === '' || $part === '.' || $part === '..' || str_contains($part, ':')
                        || preg_match('/[. ]$/', $part)
                        || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\\.|$)/i', $part)) {
                        throw new RuntimeException('Archive path is not portable.');
                    }
                }
                $os = $attr = 0;
                if ($zip->getExternalAttributesIndex($i, $os, $attr) && (($attr >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Archive contains a symlink.');
                }
                $size = (int) ($stat['size'] ?? 0);
                $total += $size;
                if ($size > 10485760 || $total > 52428800) {
                    throw new RuntimeException('Archive exceeds Core expansion limits.');
                }
                if (str_ends_with(strtolower($name), '.php')) {
                    token_get_all((string) $zip->getFromIndex($i), TOKEN_PARSE);
                }
            }
            $raw = $zip->getFromName($slug . '/' . $kind . '.json');
            if (!is_string($raw) || strlen($raw) > 1048576) {
                throw new RuntimeException('Packaged metadata is missing or exceeds Core\'s 1 MiB limit.');
            }
            $packed = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($packed) || ($packed[$kind] ?? '') !== $slug
                || ($packed['version'] ?? '') !== ($metadata['version'] ?? '')
                || ($packed['signing'] ?? []) !== ($metadata['signing'] ?? [])) {
                throw new RuntimeException('Packaged identity, version or signing trust does not match.');
            }
            if ($kind === 'theme') {
                foreach (['head', 'nav', 'foot'] as $part) {
                    if ($zip->locateName($slug . '/inc/' . $part . '.php') === false
                        && $zip->locateName($slug . '/' . $part . '.php') === false) {
                        throw new RuntimeException('Theme is missing required shell part: ' . $part);
                    }
                }
            } elseif ($zip->locateName($slug . '/controllers/' . $slug . '.php') === false) {
                throw new RuntimeException('Module is missing its controller.');
            }
        } finally {
            $zip->close();
        }
    }
    /** Isolated temporary keyrings; no default keyring or network key discovery. */
    private static function pgp(string $public, ?string $private, string $passphrase, string $statement, ?string $detached = null): string
    {
        if (!extension_loaded('gnupg') || version_compare((string) phpversion('gnupg'), '1.5.0', '<')) {
            throw new RuntimeException('OpenPGP signing requires PHP GnuPG 1.5+ and its backend. No unsigned fallback.');
        }
        $home = sys_get_temp_dir() . '/chaos-builder-pgp-' . bin2hex(random_bytes(16));
        if (!mkdir($home, 0700)) {
            throw new RuntimeException('Cannot create private temporary signing keyring.');
        }
        try {
            $gpg = new gnupg(['home_dir' => $home]);
            $gpg->seterrormode(GNUPG_ERROR_EXCEPTION);
            $import = $gpg->import($public);
            if (!is_array($import) || ($import['imported'] ?? 0) !== 1
                || ($import['secret_imported'] ?? 0) !== 0 || ($import['secret_read'] ?? 0) !== 0) {
                throw new RuntimeException('Configure exactly one OpenPGP public publisher key.');
            }
            $fingerprint = (string) ($import['fingerprint'] ?? '');
            $keys = $gpg->keyinfo($fingerprint);
            if (!is_array($keys) || count($keys) !== 1) {
                throw new RuntimeException('Invalid OpenPGP publisher.');
            }
            $publisher = $keys[0];
            foreach (['revoked', 'expired', 'disabled', 'invalid'] as $flag) {
                if (!empty($publisher[$flag])) {
                    throw new RuntimeException('OpenPGP publisher is revoked, expired, disabled or invalid.');
                }
            }
            $allowed = [];
            foreach ($publisher['subkeys'] ?? [] as $subkey) {
                if (empty($subkey['revoked']) && empty($subkey['expired'])
                    && empty($subkey['disabled']) && empty($subkey['invalid'])) {
                    $allowed[] = $subkey['fingerprint'] ?? '';
                }
            }
            if ($private !== null) {
                $secret = $gpg->import($private);
                if (!is_array($secret) || ($secret['secret_imported'] ?? 0) !== 1
                    || !hash_equals($fingerprint, (string) ($secret['fingerprint'] ?? ''))) {
                    throw new RuntimeException('Upload the private key belonging to the configured OpenPGP publisher.');
                }
                $gpg->setarmor(0);
                $gpg->setsignmode(GNUPG_SIG_MODE_DETACH);
                if (!$gpg->addsignkey($fingerprint, $passphrase)) {
                    throw new RuntimeException('Cannot unlock the OpenPGP signing key.');
                }
                $signature = $gpg->sign($statement);
                if (!is_string($signature) || $signature === '') {
                    throw new RuntimeException('OpenPGP detached signing failed.');
                }
            } else {
                $signature = $detached;
            }
            $results = $gpg->verify($statement, $signature);
            if (!is_array($results) || count($results) !== 1 || ($results[0]['status'] ?? -1) !== 0
                || !isset($results[0]['summary']) || ($results[0]['summary'] & ~3) !== 0
                || !in_array($results[0]['fingerprint'] ?? null, $allowed, true)) {
                throw new RuntimeException('OpenPGP signature did not verify against configured publisher trust.');
            }
            return $signature;
        } finally {
            unset($gpg);
            self::removeKeyring($home);
        }
    }

    /** Only used on the randomly created private temporary keyring above. */
    private static function removeKeyring(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                self::removeKeyring($path);
            } elseif (!unlink($path)) {
                throw new RuntimeException('Private keyring cleanup failed; remove the temporary keyring immediately.');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Private keyring cleanup failed; remove the temporary keyring immediately.');
        }
    }
}
/* [End AI:GPT-5.6] */
