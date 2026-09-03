<?php
/* [AI:GPT-5.6 Sol | 2026-08-29 02:00:00 UTC] */
class theme_package_builder
{
    private const LIMIT = 1048576;
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

    public function __construct(
        ?string $themes = null,
        ?string $releases = null,
        ?string $metadataFile = null
    ) {
        $this->themes = $themes ?? USERROOT . '/themes';
        $this->releases = $releases ?? dirname(USERROOT) . '/releases';
        $this->metadataFile = $metadataFile ?? USERROOT . '/data/theme_builder_projects.json';

        $this->makeDirectory($this->themes);
        $this->makeDirectory($this->releases);
        $this->makeDirectory(dirname($this->metadataFile));
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

            $project = is_array($metadata[$slug] ?? null) ? $metadata[$slug] : [];

            $projects[] = [
                'slug' => $slug,
                'name' => (string) ($project['name'] ?? $slug),
                'version' => (string) ($project['version'] ?? '0.0.0'),
                'description' => (string) ($project['description'] ?? ''),
                'signing' => is_array($project['signing'] ?? null) ? $project['signing'] : [],
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
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $version = trim((string) ($input['version'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

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
                    '/inc',
                ] as $directory
            ) {
                $this->makeDirectory($root . $directory);
            }

            $this->write($root . '/assets/css/site.css', $this->starterCss());
            $this->write($root . '/assets/js/site.js', $this->starterJs());
            $this->write($root . '/inc/head.php', $this->starterHead());
            $this->write($root . '/inc/nav.php', $this->starterNav());
            $this->write($root . '/inc/foot.php', $this->starterFoot());
            $this->writeBinary($root . '/assets/icons/icon.png', $this->starterIcon());

            $metadata = $this->projectMetadata();
            $metadata[$slug] = [
                'name' => $name,
                'version' => $version,
                'description' => $description,
                'signing' => [
                    'type' => 'sha256',
                    'fingerprint' => '',
                    'sha256' => hash('sha256', random_bytes(32)),
                    'key_id' => '',
                    'public_key' => '',
                ],
            ];
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
        $existingSigning = is_array($metadata[$slug]['signing'] ?? null)
            ? $metadata[$slug]['signing']
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

        $metadata[$slug] = [
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'signing' => $this->signingMetadata($signingInput),
        ];

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

        if ($metadata['version'] === '0.0.0') {
            $errors[] = 'Project metadata must be saved before release.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    public function buildRelease(string $slug): string
    {
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

            $artifacts[] = [
                'name' => $name,
                'size' => filesize($path),
                'modified' => filemtime($path),
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
    }

    public function certificationStatus(): array
    {
        $identity = $this->readJson(USERROOT . '/data/certified_developer.json', false);
        $endpoint = trim((string) (getenv('CHAOS_CERTIFICATION_ENDPOINT') ?: ''));
        $status = [
            'certified' => false,
            'signing' => false,
            'message' => 'Full theme development and unsigned packaging available; signing is not configured.',
        ];

        if ($endpoint === '' || $identity === []) {
            return $status;
        }

        if (!$this->isPublicHttps($endpoint)) {
            $status['message'] = 'Certification endpoint must be public HTTPS.';
            return $status;
        }

        $query = http_build_query(
            [
                'developer_id' => (string) ($identity['developer_id'] ?? ''),
                'domain' => (string) ($identity['domain'] ?? ($_SERVER['HTTP_HOST'] ?? '')),
                'key_id' => (string) ($identity['key_id'] ?? ''),
                'capability' => 'theme_signing',
            ]
        );

        $raw = @file_get_contents(
            $endpoint . '?' . $query,
            false,
            stream_context_create(
                [
                    'http' => [
                        'timeout' => 5,
                        'ignore_errors' => true,
                    ],
                ]
            )
        );

        $response = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($response)) {
            $status['message'] = 'Certification status unavailable; unsigned theme development remains available.';
            return $status;
        }

        $status['certified'] = ($response['certified'] ?? false) === true;
        $status['signing'] = $status['certified']
            && ($response['signing']['theme'] ?? false) === true;
        $status['key_id'] = (string) ($response['key_id'] ?? '');
        $status['message'] = $status['signing']
            ? 'Certified theme signing authorized; private keys are never retained.'
            : 'Unsigned theme development remains fully available; theme signing is not authorized.';

        return $status;
    }

    public function signRelease(string $slug, string $artifact, array $upload): void
    {
        if (!$this->certificationStatus()['signing']) {
            throw new RuntimeException('Theme signing not authorized.');
        }

        if (!preg_match('/^[a-z][a-z0-9_]{1,62}-[0-9A-Za-z.+_-]+\.zip$/', $artifact)) {
            throw new InvalidArgumentException('Invalid artifact.');
        }

        if (
            ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))
        ) {
            throw new RuntimeException('ChAoS MVC-issued key required.');
        }

        $path = $this->artifactRoot($slug, false) . '/' . $artifact;

        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Artifact missing.');
        }

        $pem = file_get_contents((string) $upload['tmp_name']);
        $key = is_string($pem) ? openssl_pkey_get_private($pem) : false;
        $pem = null;

        if ($key === false) {
            throw new RuntimeException('Invalid key.');
        }

        $hash = hash_file('sha256', $path);
        $signature = '';

        if (!is_string($hash) || !openssl_sign($hash, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing failed.');
        }

        $this->write($path . '.sig', base64_encode($signature) . PHP_EOL);
    }

    private function metadataFor(string $slug): array
    {
        $metadata = $this->projectMetadata();
        $project = is_array($metadata[$slug] ?? null) ? $metadata[$slug] : [];

        return [
            'name' => (string) ($project['name'] ?? $slug),
            'version' => (string) ($project['version'] ?? '0.0.0'),
            'description' => (string) ($project['description'] ?? ''),
            'signing' => is_array($project['signing'] ?? null) ? $project['signing'] : [],
        ];
    }

    private function projectMetadata(): array
    {
        return $this->readJson($this->metadataFile, false);
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

    private function signingMetadata(array $input): array
    {
        $type = strtolower(trim((string) ($input['signing_type'] ?? 'sha256')));
        $fingerprint = trim((string) ($input['signing_fingerprint'] ?? ''));
        $sha256 = strtolower(trim((string) ($input['signing_sha256'] ?? '')));
        if ($sha256 === '') {
            $sha256 = hash('sha256', random_bytes(32));
        }
        $keyId = strtolower(trim((string) ($input['signing_key_id'] ?? '')));
        $publicKey = preg_replace('/\s+/', '', trim((string) ($input['signing_public_key'] ?? '')));
        $publicKey = is_string($publicKey) ? $publicKey : '';

        if (!in_array($type, ['sha256', 'rsa-sha256', 'openpgp'], true)) {
            throw new InvalidArgumentException('Signing type must be SHA-256, RSA-SHA256, or OpenPGP.');
        }
        if (strlen($fingerprint) > 255) {
            throw new InvalidArgumentException('Signing fingerprint must not exceed 255 characters.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('Signing SHA-256 is required.');
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

        return [
            'type' => $type,
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
<?php /* [AI:GPT-5.6 Sol | __TIMESTAMP__ UTC] */ ?>
<header class="theme-header">
    <a class="theme-brand" href="/">
        <?= htmlspecialchars($SITE['name'] ?? 'Chaos MVC', ENT_QUOTES, 'UTF-8'); ?>
    </a>

    <nav class="theme-nav" aria-label="Primary navigation">
        <a href="/">Home</a>
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
    flex-wrap: wrap;
    gap: var(--space-3);
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
