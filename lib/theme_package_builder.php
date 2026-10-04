<?php
if (!class_exists('builder_release_signer', false)) {
    require_once __DIR__ . '/builder_release_signer.php';
}
if (!class_exists('builder_certification_client', false)) {
    require_once __DIR__ . '/builder_certification_client.php';
}
/* [AI:GPT-5.6 Sol | 2026-08-29 02:00:00 UTC] */
class theme_package_builder
{
    private const LIMIT = 1048576;
    private const BOOTSTRAP_VERSION = '5.3.8';
    private const TEXT_EXTENSIONS = [
        'php',
        'json',
        'md',
        'txt',
        'css',
        'js',
        'html',
        'xml',
        'svg',
        'yml',
        'yaml',
    ];

    private string $themes;
    private string $releases;
    private string $metadataFile;
    private string $configFile;

    public function __construct(
        ?string $themes = null,
        ?string $releases = null,
        ?string $metadataFile = null,
        ?string $configFile = null
    ) {
        $this->themes = $themes ?? USERROOT . '/themes';
        $this->releases = $releases ?? dirname(USERROOT) . '/releases';
        $this->metadataFile = $metadataFile ?? USERROOT . '/data/theme_builder_projects.json';
        $this->configFile = $configFile ?? __DIR__ . '/../data/certification.json';

        $this->makeDirectory($this->themes);
        $this->makeDirectory($this->releases);
        $this->makeDirectory(dirname($this->metadataFile));
        $this->makeDirectory(dirname($this->configFile));
    }

    public function builderConfig(): array
    {
        return $this->readJson($this->configFile, false);
    }

    public function configRequired(): bool
    {
        $config = $this->builderConfig();
        foreach (['developer', 'domain', 'algorithm', 'key_id', 'transport_key'] as $field) {
            if (!is_string($config[$field] ?? null) || $config[$field] === '') return true;
        }
        return preg_match('/^[a-f0-9]{64}$/', (string) $config['transport_key']) !== 1;
    }

    public function saveBuilderConfig(array $input): void
    {
        $existing = $this->builderConfig();
        $developer = trim((string) ($input['developer'] ?? ''));
        $domain = strtolower(trim((string) ($input['domain'] ?? '')));
        $algorithm = strtolower(trim((string) ($input['algorithm'] ?? '')));
        $keyId = strtolower(trim((string) ($input['key_id'] ?? '')));
        $transportKey = strtolower(trim((string) ($input['transport_key'] ?? '')));
        if ($transportKey === '') {
            $transportKey = strtolower(trim((string) ($existing['transport_key'] ?? '')));
        }
        if ($developer === '' || filter_var('https://' . $domain, FILTER_VALIDATE_URL) === false
            || !in_array($algorithm, ['rsa-sha256', 'openpgp'], true)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/', $keyId)
            || !preg_match('/^[a-f0-9]{64}$/', $transportKey)) {
            throw new InvalidArgumentException('Enter a valid developer, domain, signing algorithm, key ID, and transport key.');
        }
        $base = ['developer' => $developer, 'domain' => $domain, 'algorithm' => $algorithm,
            'key_id' => $keyId, 'transport_key' => $transportKey];
        $this->write($this->configFile, $this->encode($base));
        $result = (new builder_certification_client($this->releases . '/.certification-cache', $this->configFile))
            ->verify($developer, $domain, 'theme', $algorithm, $keyId);
        $this->write($this->configFile, $this->encode($base + [
            'public_key' => is_string($result['public_key'] ?? null) ? $result['public_key'] : '',
            'fingerprint' => (string) ($result['fingerprint'] ?? ''),
            'credential_id' => (string) ($result['credential_id'] ?? ''),
            'verification_state' => (string) ($result['state'] ?? 'unavailable'),
            'verified_at' => (string) ($result['verified_at'] ?? ''),
        ]));
    }

    public function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{1,62}$/', $slug);
    }

    public function listProjects(): array
    {
        $metadata = $this->projectMetadata();
        $projects = [];

        foreach (glob($this->themes . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $slug = basename($directory);

            if (!$this->isValidSlug($slug) || is_link($directory)) {
                continue;
            }

            $project = $this->metadataFor($slug);

            $projects[] = [
                'slug' => $slug,
                'name' => (string) ($project['name'] ?? $slug),
                'version' => (string) ($project['version'] ?? '0.0.0'),
                'description' => (string) ($project['description'] ?? ''),
                'signing' => is_array($project['signing'] ?? null) ? $project['signing'] : [],
                'update_url' => $project['update_url'] ?? '',
                'creator' => $project['creator'] ?? '',
                'domain' => $project['domain'] ?? '',
                'certified' => $project['certified'] ?? 'No',
                'package_hosts' => $project['package_hosts'] ?? [],
            ];
        }

        usort(
            $projects,
            static fn(array $left, array $right): int => strcmp($left['slug'], $right['slug'])
        );

        return $projects;
    }

    public function createProject(array $input): void
    {
        $config = $this->builderConfig();
        if (!$this->configRequired()) {
            $input['creator'] = $config['developer'];
            $input['domain'] = $config['domain'];
            $input['signing_type'] = $config['algorithm'];
            $input['signing_key_id'] = $config['key_id'];
        }
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $version = trim((string) ($input['version'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $domain = $this->domain((string) ($input['domain'] ?? ''));
        $creator = trim((string) ($input['creator'] ?? ''));
        $projectIdentity = hash('sha256', random_bytes(32));
        $signingInput = $this->preloadCertifiedIdentity(
            [
                'signing_type' => (string) ($input['signing_type'] ?? 'none'),
                'signing_sha256' => $projectIdentity,
                'signing_fingerprint' => $projectIdentity,
                'signing_key_id' => (string) ($input['signing_key_id'] ?? ''),
                'signing_public_key' => '',
            ],
            $creator,
            $domain
        );
        if ($signingInput['signing_key_id'] !== '' && ($signingInput['signing_public_key'] ?? '') === '') {
            $signingInput['signing_type'] = 'none';
            $signingInput['signing_key_id'] = '';
        }

        $this->validateMetadata($slug, $name, $version);

        $root = $this->themes . '/' . $slug;

        if (file_exists($root)) {
            throw new RuntimeException('Theme project already exists.');
        }

        try {
            foreach (
                [
                    '/assets/css',
                    '/assets/icons',
                    '/assets/img',
                    '/assets/js',
                    '/assets/vendor/bootstrap/css',
                    '/assets/vendor/bootstrap/js',
                    '/inc',
                ] as $directory
            ) {
                $this->makeDirectory($root . $directory);
            }

            $this->write($root . '/assets/css/site.css', $this->starterCss());
            $this->write($root . '/assets/js/site.js', $this->starterJs());
            $this->installBootstrap($root);
            $this->write($root . '/inc/head.php', $this->starterHead());
            $this->write($root . '/inc/nav.php', $this->starterNav());
            $this->write($root . '/inc/foot.php', $this->starterFoot());
            $this->writeBinary($root . '/assets/icons/icon.png', $this->starterIcon());

            $metadata = $this->projectMetadata();
            $metadata[$slug] = [
                'name' => $name,
                'version' => $version,
                'description' => $description,
                'domain' => $domain,
                'creator' => $creator,
                'certified' => 'No',
                'signing' => $this->signingMetadata($signingInput),
            ];
            $metadata[$slug]['certified'] = $this->certificationFor($metadata[$slug]) ? 'Yes' : 'No';
            $this->writeThemeMetadata($slug, $metadata[$slug]);
            $this->saveProjectMetadata($metadata);
        } catch (Throwable $exception) {
            if (is_dir($root)) {
                $this->remove($root);
            }

            throw $exception;
        }
    }

    public function editProject(string $slug, array $input): void
    {
        $this->root($slug);

        $name = trim((string) ($input['name'] ?? ''));
        $version = trim((string) ($input['version'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        $this->validateMetadata($slug, $name, $version);

        $metadata = $this->projectMetadata();
        $currentMetadata = $this->metadataFor($slug);
        $existingSigning = is_array($currentMetadata['signing'] ?? null)
            ? $currentMetadata['signing']
            : [];
        $signingInput = $input;

        foreach (
            [
                'signing_type' => 'type',
                'signing_fingerprint' => 'fingerprint',
                'signing_sha256' => 'sha256',
                'signing_key_id' => 'key_id',
                'signing_public_key' => 'public_key',
            ] as $inputKey => $metadataKey
        ) {
            if (!array_key_exists($inputKey, $signingInput)) {
                $signingInput[$inputKey] = $existingSigning[$metadataKey] ?? '';
            }
        }
        $signingInput = $this->preloadCertifiedIdentity(
            $signingInput,
            (string) ($input['creator'] ?? $currentMetadata['creator'] ?? ''),
            (string) ($input['domain'] ?? $currentMetadata['domain'] ?? '')
        );

        $metadata[$slug] = [
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'update_url' => trim((string) ($input['update_url'] ?? $currentMetadata['update_url'] ?? '')),
            'creator' => trim((string) ($input['creator'] ?? $currentMetadata['creator'] ?? '')),
            'domain' => $this->domain((string) ($input['domain'] ?? $currentMetadata['domain'] ?? '')),
            'certified' => 'No',
            'package_hosts' => isset($input['package_hosts'])
                ? array_values(array_filter(preg_split('/[\s,]+/', strtolower(trim((string) $input['package_hosts']))) ?: []))
                : ($currentMetadata['package_hosts'] ?? []),
            'signing' => $this->signingMetadata($signingInput),
        ];
        $metadata[$slug]['certified'] = $this->certificationFor($metadata[$slug]) ? 'Yes' : 'No';

        $this->writeThemeMetadata($slug, $metadata[$slug]);
        $this->saveProjectMetadata($metadata);
    }

    public function deleteProject(string $slug): void
    {
        $this->remove($this->root($slug));

        $metadata = $this->projectMetadata();
        unset($metadata[$slug]);
        $this->saveProjectMetadata($metadata);
    }

    public function fileTree(string $slug): array
    {
        $root = $this->root($slug);
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }

            $files[] = [
                'path' => str_replace(
                    '\\',
                    '/',
                    substr($item->getPathname(), strlen($root) + 1)
                ),
                'directory' => $item->isDir(),
            ];
        }

        usort(
            $files,
            static fn(array $left, array $right): int => strcmp($left['path'], $right['path'])
        );

        return $files;
    }

    public function readFile(string $slug, string $path): string
    {
        $file = $this->existingPath($slug, $path, true);
        $this->assertEditable($file);

        $content = file_get_contents($file);

        if (!is_string($content)) {
            throw new RuntimeException('Read failed.');
        }

        return $content;
    }

    public function writeFile(string $slug, string $path, string $content): void
    {
        if (strlen($content) > self::LIMIT) {
            throw new RuntimeException('Editor limit exceeded.');
        }

        $file = $this->existingPath($slug, $path, true);
        $this->assertEditable($file);
        $this->write($file, $content);
    }

    public function createFile(string $slug, string $path): void
    {
        $file = $this->freshPath($slug, $path);
        $this->assertEditable($file);

        if (file_exists($file) || !touch($file)) {
            throw new RuntimeException('Create failed.');
        }
    }

    public function createDirectory(string $slug, string $path): void
    {
        $directory = $this->freshPath($slug, $path);

        if (file_exists($directory) || !mkdir($directory, 0755)) {
            throw new RuntimeException('Create failed.');
        }
    }

    public function renamePath(string $slug, string $path, string $newPath): void
    {
        $source = $this->existingPath($slug, $path, false);
        $destination = $this->freshPath($slug, $newPath);

        if (file_exists($destination) || !rename($source, $destination)) {
            throw new RuntimeException('Rename failed.');
        }
    }

    public function deletePath(string $slug, string $path): void
    {
        $target = $this->existingPath($slug, $path, false);

        if (is_dir($target)) {
            $this->remove($target);
            return;
        }

        if (!unlink($target)) {
            throw new RuntimeException('Delete failed.');
        }
    }

    public function validateProject(string $slug): array
    {
        $root = $this->root($slug);
        $errors = [];

        foreach (
            [
                'assets/css',
                'assets/icons',
                'assets/img',
                'assets/js',
                'inc',
            ] as $directory
        ) {
            if (!is_dir($root . '/' . $directory)) {
                $errors[] = 'Required directory missing: ' . $directory;
            }
        }

        foreach (
            [
                'assets/css/site.css',
                'assets/icons/icon.png',
                'assets/js/site.js',
                'inc/head.php',
                'inc/nav.php',
                'inc/foot.php',
            ] as $file
        ) {
            if (!is_file($root . '/' . $file)) {
                $errors[] = 'Required file missing: ' . $file;
            }
        }

        $head = is_file($root . '/inc/head.php')
            ? (string) file_get_contents($root . '/inc/head.php')
            : '';

        if ($head !== '') {
            if (!str_contains($head, '$SITE')) {
                $errors[] = 'head.php must consume the MVC-provided $SITE data.';
            }

            if (!str_contains($head, "theme::assetUrl('css/site.css')")) {
                $errors[] = 'head.php must load the theme stylesheet with theme::assetUrl().';
            }

            if (!str_contains($head, "__DIR__ . '/nav.php'")) {
                $errors[] = 'head.php must include inc/nav.php.';
            }
        }

        $metadata = $this->metadataFor($slug);

        try {
            $this->validateMetadata($slug, $metadata['name'], $metadata['version']);
        } catch (InvalidArgumentException $error) {
            $errors[] = $error->getMessage();
        }

        if ($metadata['version'] === '0.0.0') {
            $errors[] = 'Project metadata must be saved before release.';
        }
        try {
            $this->domain((string) ($metadata['domain'] ?? ''));
            $this->certified((string) ($metadata['certified'] ?? ''));
        } catch (InvalidArgumentException $error) {
            $errors[] = $error->getMessage();
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    public function buildRelease(string $slug): string
    {
        $root = $this->root($slug);
        $this->installBootstrap($root);
        $this->ensureBootstrapReferences($root);

        // Preserve extra fields while refreshing the manifest's file inventory.
        $current = $this->metadataFor($slug);
        $current['certified'] = $this->certificationFor($current) ? 'Yes' : 'No';
        $this->writeThemeMetadata($slug, $current);
        $validation = $this->validateProject($slug);

        if (!$validation['valid']) {
            throw new RuntimeException('Validation failed.');
        }

        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZIP extension required.');
        }

        $metadata = $this->metadataFor($slug);
        $base = $slug . '-' . $metadata['version'];
        $output = $this->artifactRoot($slug, true);
        $zipPath = $output . '/' . $base . '.zip';
        $temporary = $zipPath . '.tmp-' . bin2hex(random_bytes(5));

        $zip = new ZipArchive();

        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP create failed.');
        }

        try {
            foreach ($this->fileTree($slug) as $entry) {
                if ($entry['directory']) {
                    continue;
                }

                $file = $this->existingPath($slug, $entry['path'], true);

                if (!$zip->addFile($file, $slug . '/' . $entry['path'])) {
                    throw new RuntimeException('ZIP add failed.');
                }
            }
        } finally {
            $zip->close();
        }

        if (!rename($temporary, $zipPath)) {
            throw new RuntimeException('ZIP finalize failed.');
        }
        if (is_file($zipPath . '.sig') || is_link($zipPath . '.sig')) {
            if (!unlink($zipPath . '.sig')) {
                throw new RuntimeException('Cannot invalidate the previous release signature.');
            }
        }

        builder_release_signer::invalidatePublication($zipPath, $slug);
        $hash = hash_file('sha256', $zipPath);

        if (!is_string($hash)) {
            throw new RuntimeException('Artifact hash failed.');
        }

        $this->write(
            $output . '/' . $base . '.sha256',
            $hash . '  ' . basename($zipPath) . PHP_EOL
        );

        $this->write(
            $output . '/' . $base . '.manifest.json',
            $this->encode(
                [
                    'theme' => $slug,
                    'name' => $metadata['name'],
                    'version' => $metadata['version'],
                    'artifact' => basename($zipPath),
                    'sha256' => $hash,
                    'signing' => $metadata['signing'],
                    'signed' => false,
                    'built_at' => gmdate('c'),
                ]
            )
        );

        return $zipPath;
    }

    public function listArtifacts(string $slug): array
    {
        $root = $this->artifactRoot($slug, false);

        if (!is_dir($root)) {
            return [];
        }

        $artifacts = [];

        foreach (scandir($root) ?: [] as $name) {
            $path = $root . '/' . $name;

            if ($name === '.' || $name === '..' || !is_file($path) || is_link($path)) {
                continue;
            }

            $release = str_ends_with($name, '.manifest.json') ? $this->readJson($path, false) : [];
            $signature = is_array($release['signature'] ?? null) ? $release['signature'] : [];
            $artifacts[] = [
                'name' => $name,
                'size' => filesize($path),
                'modified' => filemtime($path),
                'verification' => is_array($release['verification'] ?? null) ? $release['verification'] : [],
                'signature_algorithm' => ($release['signed'] ?? false) === true
                    ? (string) ($release['signature_algorithm'] ?? $signature['algorithm'] ?? 'Unknown') : '',
                'signature' => ($release['signed'] ?? false) === true
                    ? (string) (is_string($release['signature'] ?? null) ? $release['signature'] : ($signature['value'] ?? '')) : '',
            ];
        }

        usort(
            $artifacts,
            static fn(array $left, array $right): int => $right['modified'] <=> $left['modified']
        );

        return $artifacts;
    }

    public function artifactFile(string $slug, string $name): string
    {
        if ($name !== basename($name) || !preg_match('/^[A-Za-z0-9._-]{1,240}$/', $name)) {
            throw new InvalidArgumentException('Invalid artifact.');
        }

        $root = $this->artifactRoot($slug, false);
        $resolvedRoot = is_link($root) ? false : realpath($root);
        $candidate = $root . '/' . $name;
        $resolved = is_link($candidate) ? false : realpath($candidate);

        if (
            $resolvedRoot === false
            || $resolved === false
            || !str_starts_with($resolved, $resolvedRoot . DIRECTORY_SEPARATOR)
            || !is_file($resolved)
        ) {
            throw new RuntimeException('Artifact was not found.');
        }

        return $resolved;
    }

    public function deleteData(): void
    {
        $metadata = $this->projectMetadata();

        foreach (array_keys($metadata) as $slug) {
            if (!is_string($slug) || !$this->isValidSlug($slug)) {
                continue;
            }

            $themeRoot = $this->root($slug);
            $artifactRoot = $this->artifactRoot($slug, false);

            if (is_dir($artifactRoot) && !is_link($artifactRoot)) {
                $this->remove($artifactRoot);
            }

            $this->remove($themeRoot);
        }

        $this->saveProjectMetadata([]);
        if (is_file($this->configFile) && !is_link($this->configFile)) unlink($this->configFile);
    }

    public function certificationStatus(string $slug = ''): array
    {
        $status = [
            'state' => 'not_verified',
            'certified' => false,
            'signing' => false,
            'message' => 'Certification: Not Verified. Select a project and configure its developer identity; Builder and signing remain available.',
        ];
        $config = $this->builderConfig();
        if (!$this->configRequired()) {
            return (new builder_certification_client($this->releases . '/.certification-cache', $this->configFile))->verify(
                $config['developer'], $config['domain'], 'theme', $config['algorithm'], $config['key_id']
            );
        }
        if (!$this->isValidSlug($slug)) {
            $config = $this->builderConfig();
            if ($this->configRequired()) return $status;
            return (new builder_certification_client($this->releases . '/.certification-cache', $this->configFile))->verify(
                $config['developer'], $config['domain'], 'theme', $config['algorithm'], $config['key_id']
            );
        }
        try {
            $metadata = $this->metadataFor($slug);
        } catch (Throwable $error) {
            return $status;
        }
        $trust = is_array($metadata['signing'] ?? null) ? $metadata['signing'] : [];
        $algorithm = strtolower((string) ($trust['algorithm'] ?? $trust['type'] ?? ''));
        if ($algorithm === 'pgp') {
            $algorithm = 'openpgp';
        }
        return (new builder_certification_client($this->releases . '/.certification-cache', $this->configFile))->verify(
            (string) ($metadata['creator'] ?? ''),
            (string) ($metadata['domain'] ?? ''),
            'theme',
            $algorithm,
            (string) ($trust['key_id'] ?? '')
        );
    }

    private function certificationFor(array $metadata): bool
    {
        $trust = is_array($metadata['signing'] ?? null) ? $metadata['signing'] : [];
        $algorithm = strtolower((string) ($trust['algorithm'] ?? $trust['type'] ?? ''));
        if ($algorithm === 'pgp') {
            $algorithm = 'openpgp';
        }
        $result = (new builder_certification_client($this->releases . '/.certification-cache', $this->configFile))->verify(
            (string) ($metadata['creator'] ?? $metadata['author'] ?? ''),
            (string) ($metadata['domain'] ?? ''),
            'theme',
            $algorithm,
            (string) ($trust['key_id'] ?? '')
        );
        return ($result['certified'] ?? false) === true && ($result['signing'] ?? false) === true;
    }

    private function preloadCertifiedIdentity(array $input, string $developer, string $domain): array
    {
        $config = $this->builderConfig();
        if (!$this->configRequired() && !empty($config['public_key'])
            && $developer === $config['developer'] && strtolower($domain) === $config['domain']
            && strtolower((string) ($input['signing_type'] ?? '')) === $config['algorithm']
            && strtolower((string) ($input['signing_key_id'] ?? '')) === $config['key_id']) {
            $input['signing_public_key'] = $config['public_key'];
            $input['signing_fingerprint'] = $config['fingerprint'] ?? ($input['signing_fingerprint'] ?? '');
        }
        $algorithm = strtolower(trim((string) ($input['signing_type'] ?? '')));
        if ($algorithm === 'pgp') {
            $algorithm = 'openpgp';
        }
        $result = (new builder_certification_client($this->releases . '/.certification-cache', $this->configFile))->verify(
            $developer,
            $domain,
            'theme',
            $algorithm,
            (string) ($input['signing_key_id'] ?? '')
        );
        if (($result['certified'] ?? false) === true
            && is_string($result['public_key'] ?? null)
            && $result['public_key'] !== '') {
            $input['signing_public_key'] = $result['public_key'];
            if (is_string($result['fingerprint'] ?? null) && $result['fingerprint'] !== '') {
                $input['signing_fingerprint'] = $result['fingerprint'];
            }
        }
        return $input;
    }

    public function signRelease(string $slug, string $artifact, array $upload, string $passphrase = '', string $download = ''): void
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))
            || filesize((string) $upload['tmp_name']) > self::LIMIT) {
            throw new RuntimeException('Upload a private signing key (maximum 1 MiB).');
        }
        $private = file_get_contents((string) $upload['tmp_name']);
        if (!is_string($private)) {
            throw new RuntimeException('Cannot read private signing key.');
        }
        try {
            $this->signReleaseWithKey($slug, $artifact, $private, $passphrase, $download);
        } finally {
            $private = null;
        }
    }

    /**
     * Rebuild the selected project and sign the exact ZIP just created.
     * The admin never accepts an artifact filename for this transaction.
     */
    public function buildAndSignRelease(string $slug, array $upload, string $passphrase = '', string $download = ''): string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))
            || (int) filesize((string) $upload['tmp_name']) < 1
            || (int) filesize((string) $upload['tmp_name']) > self::LIMIT) {
            throw new RuntimeException('Upload a private signing key (maximum 1 MiB).');
        }
        $private = file_get_contents((string) $upload['tmp_name']);
        if (!is_string($private) || $private === '') {
            throw new RuntimeException('Cannot read private signing key.');
        }
        try {
            return $this->buildAndSignReleaseWithKey($slug, $private, $passphrase, $download);
        } finally {
            $private = null;
        }
    }

    /** Testable in-memory form of the current-project build-and-sign transaction. */
    public function buildAndSignReleaseWithKey(string $slug, string $private, string $passphrase, string $download): string
    {
        $metadata = $this->metadataFor($slug);
        builder_release_signer::publication($metadata, $download);
        builder_release_signer::requireBackend(
            builder_release_signer::algorithm((array) ($metadata['signing'] ?? []))
        );
        $artifact = $this->buildRelease($slug);
        $this->signReleaseWithKey($slug, basename($artifact), $private, $passphrase, $download);
        return $artifact;
    }

    /** Sign the exact packaged metadata, not later edits to the live project. */
    public function signReleaseWithKey(string $slug, string $artifact, string $private, string $passphrase, string $download): void
    {
        if (!str_ends_with($artifact, '.zip')) {
            throw new InvalidArgumentException('Select a release ZIP.');
        }
        $path = $this->artifactFile($slug, $artifact);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot read release ZIP.');
        }
        try {
            $raw = $zip->getFromName($slug . '/theme.json');
            $metadata = is_string($raw) ? json_decode($raw, true) : null;
        } finally {
            $zip->close();
        }
        if (!is_array($metadata) || ($metadata['theme'] ?? '') !== $slug) {
            throw new RuntimeException('Release must contain its matching theme.json.');
        }
        $manifest = builder_release_signer::sign('theme', $metadata, $path, $download, $private, $passphrase);
        try {
            foreach (builder_release_signer::releaseFiles('theme', $metadata, $manifest, $path) as $outputPath => $contents) {
                $this->write($outputPath, $contents);
            }
            $manifest['verification'] = builder_release_signer::verifyWrittenRelease('theme', $metadata, $manifest, $path);
            $manifest['verification'] = builder_release_signer::stageLocalRelease('theme', $metadata, $manifest, $path);
        } catch (Throwable $exception) {
            builder_release_signer::invalidatePublication($path, $slug);
            throw $exception;
        }
        $this->write(substr($path, 0, -4) . '.manifest.json', $this->encode($manifest));
    }

    private function metadataFor(string $slug): array
    {
        $metadata = $this->projectMetadata();
        $project = is_array($metadata[$slug] ?? null) ? $metadata[$slug] : [];
        $path = $this->root($slug) . '/theme.json';
        if (file_exists($path) || is_link($path)) {
            $project = $this->readJson($path);
        }
        if (($project['signing']['type'] ?? '') === 'sha256') {
            $project['signing']['type'] = 'none';
        }
        if (is_array($project['signing'] ?? null)) {
            $project['signing']['type'] ??= $project['signing']['algorithm'] ?? 'none';
        }

        return [
            'name' => (string) ($project['name'] ?? $slug),
            'version' => (string) ($project['version'] ?? '0.0.0'),
            'description' => (string) ($project['description'] ?? ''),
            'theme' => $slug,
            'update_url' => (string) ($project['update_url'] ?? ''),
            'creator' => (string) ($project['creator'] ?? $project['author'] ?? ''),
            'domain' => (string) ($project['domain'] ?? ''),
            'certified' => (string) ($project['certified'] ?? 'No'),
            'package_hosts' => (array) ($project['package_hosts'] ?? []),
            'signing' => is_array($project['signing'] ?? null) ? $project['signing'] : [],
        ];
    }

    private function projectMetadata(): array
    {
        return $this->readJson($this->metadataFile, false);
    }

    private function writeThemeMetadata(string $slug, array $project): void
    {
        $path = $this->root($slug) . '/theme.json';
        $existing = file_exists($path) || is_link($path) ? $this->readJson($path) : [];
        $files = ['theme.json'];
        foreach ($this->fileTree($slug) as $entry) {
            if (!$entry['directory'] && $entry['path'] !== 'theme.json') {
                $files[] = $entry['path'];
            }
        }
        $local = array_replace($existing, $project, ['theme' => $slug, 'files' => $files]);
        $local['signing']['algorithm'] = $local['signing']['algorithm'] ?? $local['signing']['type'] ?? 'none';
        unset($local['signing']['type']);
        $this->write($path, $this->encode($local));
    }

    private function saveProjectMetadata(array $metadata): void
    {
        ksort($metadata);
        $this->write($this->metadataFile, $this->encode($metadata));
    }

    private function root(string $slug): string
    {
        if (!$this->isValidSlug($slug)) {
            throw new InvalidArgumentException('Invalid theme project.');
        }

        $base = realpath($this->themes);
        $root = is_link($this->themes . '/' . $slug)
            ? false
            : realpath($this->themes . '/' . $slug);

        if (
            $base === false
            || $root === false
            || !str_starts_with($root, $base . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Project outside theme root.');
        }

        return $root;
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if (
            $path === ''
            || strlen($path) > 240
            || str_contains($path, '..')
            || str_contains($path, "\0")
            || !preg_match('#^[A-Za-z0-9._/-]+$#', $path)
        ) {
            throw new InvalidArgumentException('Invalid relative path.');
        }

        return $path;
    }

    private function existingPath(string $slug, string $path, bool $file): string
    {
        $root = $this->root($slug);
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $this->normalizeRelativePath($path)
        );
        $resolved = is_link($candidate) ? false : realpath($candidate);

        if (
            $resolved === false
            || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
            || ($file && !is_file($resolved))
        ) {
            throw new RuntimeException('Path outside project or missing.');
        }

        return $resolved;
    }

    private function freshPath(string $slug, string $path): string
    {
        $root = $this->root($slug);
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $this->normalizeRelativePath($path)
        );
        $parent = is_link(dirname($candidate)) ? false : realpath(dirname($candidate));

        if (
            $parent === false
            || !str_starts_with($parent, $root . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Destination outside project.');
        }

        return $candidate;
    }

    private function assertEditable(string $path): void
    {
        if (
            !in_array(
                strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                self::TEXT_EXTENSIONS,
                true
            )
        ) {
            throw new InvalidArgumentException('Unsupported editor file type.');
        }

        if (is_file($path) && filesize($path) > self::LIMIT) {
            throw new RuntimeException('Editor limit exceeded.');
        }
    }

    private function validateMetadata(string $slug, string $name, string $version): void
    {
        if (!$this->isValidSlug($slug)) {
            throw new InvalidArgumentException('Invalid lowercase theme slug.');
        }

        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('Theme name required.');
        }

        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new InvalidArgumentException('Semantic version required.');
        }
    }

    private function domain(string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) {
            throw new InvalidArgumentException('A valid developer domain is required.');
        }
        return $value;
    }

    private function certified(string $value): string
    {
        if (!in_array($value, ['Yes', 'No'], true)) {
            throw new InvalidArgumentException('Certified must be Yes or No.');
        }
        return $value;
    }

    private function signingMetadata(array $input): array
    {
        $type = strtolower(trim((string) ($input['signing_type'] ?? 'none')));
        if ($type === 'sha256' || $type === '') {
            $type = 'none';
        }
        if ($type === 'pgp') {
            $type = 'openpgp';
        }
        $fingerprint = trim((string) ($input['signing_fingerprint'] ?? ''));
        $sha256 = strtolower(trim((string) ($input['signing_sha256'] ?? '')));
        $keyId = strtolower(trim((string) ($input['signing_key_id'] ?? '')));
        $publicInput = trim((string) ($input['signing_public_key'] ?? ''));
        $publicKey = str_starts_with($publicInput, '-----BEGIN ')
            ? base64_encode($publicInput) : preg_replace('/\s+/', '', $publicInput);
        $publicKey = is_string($publicKey) ? $publicKey : '';

        if (!in_array($type, ['none', 'rsa-sha256', 'openpgp'], true)) {
            throw new InvalidArgumentException('Signature algorithm must be None, RSA-SHA256, or OpenPGP.');
        }
        if (strlen($fingerprint) > 255) {
            throw new InvalidArgumentException('Signing fingerprint must not exceed 255 characters.');
        }
        if ($sha256 !== '' && preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('Public-key SHA-256 must be 64 hexadecimal characters when provided.');
        }
        if ($keyId !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/', $keyId) !== 1) {
            throw new InvalidArgumentException('Signing key ID is invalid.');
        }
        if (($keyId === '') !== ($publicKey === '')) {
            throw new InvalidArgumentException('Signing key ID and public key must be supplied together.');
        }
        if ($publicKey !== '' && base64_decode($publicKey, true) === false) {
            throw new InvalidArgumentException('Signing public key must be compact base64 data.');
        }
        if ($type === 'rsa-sha256' && $publicKey !== '') {
            $handle = @openssl_pkey_get_public(base64_decode($publicKey, true));
            $details = $handle === false ? false : openssl_pkey_get_details($handle);
            if (!is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
                throw new InvalidArgumentException('RSA signing requires a valid full public PEM or its base64 encoding.');
            }
            $publicKey = base64_encode($details['key']);
        }

        return [
            'algorithm' => $type,
            'fingerprint' => $fingerprint,
            'sha256' => $sha256,
            'key_id' => $keyId,
            'public_key' => $publicKey,
        ];
    }

    private function artifactRoot(string $slug, bool $create): string
    {
        $this->root($slug);
        $root = $this->releases . '/' . $slug;

        if ($create) {
            $this->makeDirectory($root);
        }

        return $root;
    }

    private function readJson(string $path, bool $required = true): array
    {
        if (!is_file($path) || is_link($path)) {
            if ($required) {
                throw new RuntimeException('JSON missing.');
            }

            return [];
        }

        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            if ($required) {
                throw new RuntimeException('JSON invalid.');
            }

            return [];
        }

        return $decoded;
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            throw new RuntimeException('JSON encode failed.');
        }

        return $json . PHP_EOL;
    }

    private function write(string $path, string $content): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));

        if (
            file_put_contents($temporary, $content, LOCK_EX) === false
            || !rename($temporary, $path)
        ) {
            @unlink($temporary);
            throw new RuntimeException('Write failed.');
        }
    }

    private function writeBinary(string $path, string $content): void
    {
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Binary write failed.');
        }
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true)) {
            throw new RuntimeException('Directory create failed.');
        }
    }

    private function remove(string $root): void
    {
        if (is_link($root)) {
            if (!unlink($root)) {
                throw new RuntimeException('Delete failed.');
            }
            return;
        }

        if (!is_dir($root)) {
            if (is_file($root) && !unlink($root)) {
                throw new RuntimeException('Delete failed.');
            }
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $removed = ($item->isLink() || $item->isFile())
                ? unlink($path)
                : rmdir($path);

            if (!$removed) {
                throw new RuntimeException('Delete failed.');
            }
        }

        if (!rmdir($root)) {
            throw new RuntimeException('Delete failed.');
        }
    }

    private function isPublicHttps(string $url): bool
    {
        $parts = parse_url($url);

        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        if ($host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);

        return $ip === false
            || filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
    }

    private function starterHead(): string
    {
        $timestamp = gmdate('Y-m-d H:i:s');

        return str_replace('__TIMESTAMP__', $timestamp, <<<'PHP'
<?php
/* [AI:GPT-5.6 Sol | __TIMESTAMP__ UTC] */
$og = $og ?? [];

$ogTitle = $og['title'] ?? ($SITE['name'] ?? 'Chaos MVC');
$ogDescription = $og['desc'] ?? ($SITE['description'] ?? 'Powered by Chaos MVC');
$ogUrl = $og['url'] ?? URLROOT;
$ogImage = $og['image'] ?? theme::assetUrl('icons/icon.png');
$ogType = $og['type'] ?? 'website';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta
        name="description"
        content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8'); ?>"
    >
    <meta
        name="author"
        content="<?= htmlspecialchars($SITE['name'] ?? 'Chaos MVC', ENT_QUOTES, 'UTF-8'); ?>"
    >

    <meta property="og:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:url" content="<?= htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:type" content="<?= htmlspecialchars($ogType, ENT_QUOTES, 'UTF-8'); ?>">

    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">

    <link rel="stylesheet" href="<?= theme::assetUrl('vendor/bootstrap/css/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="<?= theme::assetUrl('css/site.css'); ?>">
    <link rel="icon" type="image/png" href="<?= theme::assetUrl('icons/icon.png'); ?>">
</head>
<body>
<div class="theme-shell">
<?php include __DIR__ . '/nav.php'; ?>
<main class="theme-main">
PHP
        );
    }

    private function starterNav(): string
    {
        $timestamp = gmdate('Y-m-d H:i:s');

        return str_replace('__TIMESTAMP__', $timestamp, <<<'PHP'
<?php
/* [AI:GPT-5.6 Sol | __TIMESTAMP__ UTC] */
$SITE = $GLOBALS['SITE'] ?? ($SITE ?? []);
$siteName = (string) ($SITE['name'] ?? 'Chaos MVC');
$loggedIn = isset($_SESSION['user_id']);
$isAdmin = $loggedIn && (int) ($_SESSION['user_level'] ?? 0) >= 7;

if ($loggedIn && empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<header class="theme-header">
    <a class="theme-brand" href="/">
        <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>
    </a>

    <nav class="theme-nav" aria-label="Primary navigation">
        <a href="/">Home</a>
        <a href="/posts">Posts</a>

        <?php if ($loggedIn): ?>
            <?php if ($isAdmin): ?>
                <a href="/admin">Admin</a>
            <?php endif; ?>

            <form action="/logout" method="POST">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        (string) $_SESSION['csrf_token'],
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>"
                >
                <button class="theme-nav-button" type="submit">Logout</button>
            </form>
        <?php else: ?>
            <a href="/login">Login</a>
            <a href="/register">Register</a>
        <?php endif; ?>
    </nav>
</header>
<?php /* [End AI:GPT-5.6 Sol] */ ?>
PHP
        );
    }

    private function starterFoot(): string
    {
        $timestamp = gmdate('Y-m-d H:i:s');

        return str_replace('__TIMESTAMP__', $timestamp, <<<'PHP'
</main>

<footer class="theme-footer">
    <p>
        &copy; <?= date('Y'); ?>
        <?= htmlspecialchars(
            $SITE['copyright_name'] ?? ($SITE['name'] ?? 'Chaos MVC'),
            ENT_QUOTES,
            'UTF-8'
        ); ?>
    </p>

    <p>
        Built with
        <a href="https://www.chaos-mvc.org" target="_blank" rel="noopener noreferrer">Chaos MVC</a>
    </p>
</footer>
</div>

<script src="<?= theme::assetUrl('vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?= theme::assetUrl('js/site.js'); ?>"></script>
</body>
</html>
<?php /* [End AI:GPT-5.6 Sol] */ ?>
PHP
        );
    }

    private function starterCss(): string
    {
        $timestamp = gmdate('Y-m-d H:i:s');

        return str_replace('__TIMESTAMP__', $timestamp, <<<'CSS'
/* [AI:GPT-5.6 Sol | __TIMESTAMP__ UTC] */
:root {
    --page-width: 72rem;
    --space-1: 0.5rem;
    --space-2: 0.75rem;
    --space-3: 1rem;
    --space-4: 1.5rem;
    --space-5: 2rem;
    --space-6: 3rem;
    --border-radius: 0.65rem;
    --text: #20242a;
    --muted: #66707c;
    --surface: #ffffff;
    --surface-soft: #f5f7f9;
    --border: #d8dde3;
    --accent: #34495e;
    --accent-strong: #22313f;
}

* {
    box-sizing: border-box;
}

html {
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    line-height: 1.5;
    background: var(--surface-soft);
    color: var(--text);
}

body {
    margin: 0;
    min-height: 100vh;
}

a {
    color: var(--accent);
    text-decoration: none;
}

a:hover,
a:focus-visible {
    color: var(--accent-strong);
    text-decoration: underline;
}

img {
    max-width: 100%;
    height: auto;
}

.theme-shell {
    width: min(100% - 2rem, var(--page-width));
    margin-inline: auto;
}

.theme-header,
.theme-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
}

.theme-header {
    padding-block: var(--space-4);
    border-bottom: 1px solid var(--border);
}

.theme-brand {
    color: var(--text);
    font-size: 1.2rem;
    font-weight: 700;
}

.theme-nav {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-3);
}

.theme-nav form {
    margin: 0;
}

.theme-nav-button {
    padding: 0;
    border: 0;
    border-radius: 0;
    background: none;
    color: var(--accent);
}

.theme-nav-button:hover,
.theme-nav-button:focus-visible {
    background: none;
    color: var(--accent-strong);
    text-decoration: underline;
}

.theme-main {
    min-height: 60vh;
    padding-block: var(--space-6);
}

.theme-footer {
    padding-block: var(--space-4);
    border-top: 1px solid var(--border);
    color: var(--muted);
    font-size: 0.9rem;
}

h1,
h2,
h3,
h4,
h5,
h6 {
    line-height: 1.2;
    margin-top: 0;
}

p,
ul,
ol,
blockquote,
pre,
table,
form {
    margin-top: 0;
    margin-bottom: var(--space-4);
}

button,
input,
select,
textarea {
    font: inherit;
}

input,
select,
textarea {
    width: 100%;
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--border-radius);
    background: var(--surface);
    color: var(--text);
}

button,
.button {
    display: inline-block;
    padding: var(--space-2) var(--space-4);
    border: 1px solid var(--accent);
    border-radius: var(--border-radius);
    background: var(--accent);
    color: #ffffff;
    cursor: pointer;
}

button:hover,
.button:hover {
    background: var(--accent-strong);
    color: #ffffff;
    text-decoration: none;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: var(--surface);
}

th,
td {
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--border);
    text-align: left;
}

code,
pre {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

pre {
    overflow-x: auto;
    padding: var(--space-3);
    border-radius: var(--border-radius);
    background: #1f252b;
    color: #f4f6f8;
}

@media (max-width: 42rem) {
    .theme-header,
    .theme-footer {
        align-items: flex-start;
        flex-direction: column;
    }

    .theme-main {
        padding-block: var(--space-5);
    }
}
/* [End AI:GPT-5.6 Sol] */
CSS
        );
    }

    private function starterJs(): string
    {
        $timestamp = gmdate('Y-m-d H:i:s');

        return str_replace('__TIMESTAMP__', $timestamp, <<<'JS'
/* [AI:GPT-5.6 Sol | __TIMESTAMP__ UTC] */
'use strict';

// Theme-specific browser behavior belongs here.
/* [End AI:GPT-5.6 Sol] */
JS
        );
    }

    private function installBootstrap(string $themeRoot): void
    {
        $source = dirname(__DIR__) . '/assets/vendor/bootstrap';

        foreach (['css/bootstrap.min.css', 'js/bootstrap.bundle.min.js'] as $relative) {
            $sourceFile = $source . '/' . $relative;

            if (!is_file($sourceFile) || is_link($sourceFile)) {
                throw new RuntimeException(
                    'Bundled Bootstrap ' . self::BOOTSTRAP_VERSION . ' asset missing: ' . $relative
                );
            }

            $contents = file_get_contents($sourceFile);

            if (!is_string($contents)) {
                throw new RuntimeException('Bundled Bootstrap asset unreadable: ' . $relative);
            }

            $this->writeBinary($themeRoot . '/assets/vendor/bootstrap/' . $relative, $contents);
        }
    }

    private function ensureBootstrapReferences(string $themeRoot): void
    {
        $headPath = $themeRoot . '/inc/head.php';
        $footPath = $themeRoot . '/inc/foot.php';
        $css = "    <link rel=\"stylesheet\" href=\"<?= theme::assetUrl('vendor/bootstrap/css/bootstrap.min.css'); ?>\">";
        $js = "<script src=\"<?= theme::assetUrl('vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>\"></script>";

        $head = is_file($headPath) ? file_get_contents($headPath) : false;
        if (is_string($head) && !str_contains($head, 'vendor/bootstrap/css/bootstrap.min.css')) {
            $siteCss = "    <link rel=\"stylesheet\" href=\"<?= theme::assetUrl('css/site.css'); ?>\">";
            $updated = str_contains($head, $siteCss)
                ? str_replace($siteCss, $css . PHP_EOL . $siteCss, $head)
                : str_replace('</head>', $css . PHP_EOL . '</head>', $head);
            if ($updated === $head) {
                throw new RuntimeException('Theme head.php has no safe Bootstrap CSS insertion point.');
            }
            $this->write($headPath, $updated);
        }

        $foot = is_file($footPath) ? file_get_contents($footPath) : false;
        if (is_string($foot) && !str_contains($foot, 'vendor/bootstrap/js/bootstrap.bundle.min.js')) {
            $siteJs = "<script src=\"<?= theme::assetUrl('js/site.js'); ?>\"></script>";
            $updated = str_contains($foot, $siteJs)
                ? str_replace($siteJs, $js . PHP_EOL . $siteJs, $foot)
                : str_replace('</body>', $js . PHP_EOL . '</body>', $foot);
            if ($updated === $foot) {
                throw new RuntimeException('Theme foot.php has no safe Bootstrap JavaScript insertion point.');
            }
            $this->write($footPath, $updated);
        }
    }

    private function starterIcon(): string
    {
        $binary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );

        if (!is_string($binary)) {
            throw new RuntimeException('Starter icon unavailable.');
        }

        return $binary;
    }
}
/* [End AI:GPT-5.6 Sol] */
