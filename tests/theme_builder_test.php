<?php
/* [AI:GPT-5.6 Sol | 2026-08-29 02:00:00 UTC] */
require dirname(__DIR__) . '/lib/theme_package_builder.php';
putenv('CHAOS_CERTIFICATION_ENDPOINT=https://chaos-mvc.org:444/developers/verify');

$base = sys_get_temp_dir() . '/chaos-theme-builder-test-' . bin2hex(random_bytes(5));
$themes = $base . '/user/themes';
$releases = $base . '/releases';
$metadata = $base . '/user/data/theme_builder_projects.json';
$builder = new theme_package_builder($themes, $releases, $metadata, $base . '/data/certification.json');

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};
$certificationClient = new builder_certification_client($base . '/certification-cache');
$certificationResponse = [
    'certified' => true, 'developer' => 'PM', 'domain' => 'poemei.com',
    'certification' => 'theme', 'credential_id' => '003',
    'signing' => [
        'algorithm' => 'rsa-sha256', 'key_id' => 'pm-test-key',
        'public_key' => base64_encode('public-account-key'),
    ],
    'private_key' => 'must-never-be-consumed',
];
$normalizedCertification = $certificationClient->normalize(
    $certificationResponse, 'PM', 'poemei.com', 'theme', 'rsa-sha256', 'pm-test-key'
);
if (($normalizedCertification['certified'] ?? false) !== true
    || ($normalizedCertification['public_key'] ?? '') !== base64_encode('public-account-key')
    || array_key_exists('private_key', $normalizedCertification)) $fail('verified account signing identity was not safely normalized');
if (($certificationClient->normalize(
    $certificationResponse, 'PM', 'poemei.com', 'module', 'rsa-sha256', 'pm-test-key'
)['certified'] ?? true) !== false) $fail('theme certification accepted the wrong artifact type');
if (($certificationClient->normalize(
    $certificationResponse, 'PM', 'poemei.com', 'theme', 'rsa-sha256', 'wrong-key'
)['certified'] ?? true) !== false) $fail('certification accepted a signing key mismatch');

try {
    $builder->createProject(
        [
            'slug' => 'classic',
            'name' => 'Classic',
            'version' => '1.0.0',
            'description' => 'Theme Builder test theme.',
            'domain' => 'Themes.Example.com',
            'certified' => 'Yes',
        ]
    );

    foreach (
        [
            'assets/css/site.css',
            'assets/icons/icon.png',
            'assets/img',
            'assets/js/site.js',
            'inc/head.php',
            'inc/nav.php',
            'inc/foot.php',
        ] as $required
    ) {
        if (!file_exists($themes . '/classic/' . $required)) {
            $fail('Missing generated theme path: ' . $required);
        }
    }

    $validation = $builder->validateProject('classic');

    if (!$validation['valid']) {
        $fail('Generated theme invalid: ' . implode('; ', $validation['errors']));
    }

    $created = $builder->listProjects()[0] ?? [];
    $originalSha256 = (string) ($created['signing']['sha256'] ?? '');

    if (
        ($created['signing']['algorithm'] ?? '') !== 'none'
        || preg_match('/^[a-f0-9]{64}$/', $originalSha256) !== 1
        || ($created['signing']['fingerprint'] ?? '') !== $originalSha256
    ) {
        $fail('New project must be unsigned with matching SHA-256 and fingerprint identity metadata.');
    }
    $themeMetadata = json_decode((string) file_get_contents($themes . '/classic/theme.json'), true);
    if (($themeMetadata['theme'] ?? '') !== 'classic'
        || ($themeMetadata['domain'] ?? '') !== 'themes.example.com'
        || ($themeMetadata['certified'] ?? '') !== 'No') {
        $fail('Generated theme.json missing theme identity, domain, or certification selection.');
    }

    $head = (string) file_get_contents($themes . '/classic/inc/head.php');

    if (
        !str_contains($head, '$SITE')
        || !str_contains($head, "theme::assetUrl('css/site.css')")
        || !str_contains($head, "__DIR__ . '/nav.php'")
    ) {
        $fail('Generated head.php does not use the expected ChAoS MVC theme shell.');
    }

    $builder->createFile('classic', 'assets/css/extra.css');
    $builder->writeFile('classic', 'assets/css/extra.css', 'body { overflow-wrap: anywhere; }');

    if (!str_contains($builder->readFile('classic', 'assets/css/extra.css'), 'overflow-wrap')) {
        $fail('Theme editor failed.');
    }

    try {
        $builder->readFile('classic', '../../app/core/router.php');
        $fail('Path traversal accepted.');
    } catch (InvalidArgumentException | RuntimeException $expected) {
    }

    $builder->editProject(
        'classic',
        [
            'name' => 'Classic Theme',
            'version' => '1.0.1',
            'description' => 'Edited.',
        ]
    );

    $edited = $builder->listProjects()[0] ?? [];

    if (($edited['signing']['sha256'] ?? '') !== $originalSha256) {
        $fail('Editing ordinary metadata rotated the signing SHA-256.');
    }
    if (($edited['signing']['fingerprint'] ?? '') !== $originalSha256) {
        $fail('Editing ordinary metadata changed the project fingerprint.');
    }
    $editedLocal = json_decode((string) file_get_contents($themes . '/classic/theme.json'), true);
    if (($editedLocal['domain'] ?? '') !== 'themes.example.com'
        || ($editedLocal['certified'] ?? '') !== 'No') {
        $fail('Ordinary edit lost local domain or certification selection.');
    }

    $builder->editProject(
        'classic',
        [
            'name' => 'Classic Theme',
            'version' => '1.0.1',
            'description' => 'Edited.',
            'signing_type' => 'openpgp',
            'signing_fingerprint' => '762379FB834CDBE299CD5A817640B4869AD65E22',
            'signing_sha256' => $originalSha256,
            'signing_key_id' => 'pm-test-key',
            'signing_public_key' => base64_encode('test OpenPGP public key'),
        ]
    );

    if (class_exists('ZipArchive')) {
        $artifact = $builder->buildRelease('classic');

        if (!is_file($artifact)) {
            $fail('Theme artifact missing.');
        }

        $manifest = $releases . '/classic/classic-1.0.1.manifest.json';

        if (!is_file($manifest)) {
            $fail('Theme artifact manifest missing.');
        }

        $releaseMetadata = json_decode((string) file_get_contents($manifest), true);
        $zip = new ZipArchive();
        $zip->open($artifact);
        $packedMetadata = json_decode((string) $zip->getFromName('classic/theme.json'), true);
        $zip->close();
        if (($packedMetadata['signing']['algorithm'] ?? '') !== 'openpgp'
            || ($packedMetadata['version'] ?? '') !== '1.0.1'
            || ($releaseMetadata['signed'] ?? null) !== false
            || ($releaseMetadata['sha256'] ?? '') !== hash_file('sha256', $artifact)) {
            $fail('Packaged theme.json or integrity/signature state is incorrect.');
        }

        if (($releaseMetadata['signing']['algorithm'] ?? '') !== 'openpgp') {
            $fail('Release manifest did not preserve OpenPGP metadata.');
        }

        if ($builder->artifactFile('classic', basename($artifact)) !== realpath($artifact)) {
            $fail('Artifact resolver failed.');
        }

        try {
            $builder->artifactFile('classic', '../module.json');
            $fail('Artifact traversal accepted.');
        } catch (InvalidArgumentException | RuntimeException $expected) {
        }
    }

    $builder->deleteData();

    if (is_dir($themes . '/classic')) {
        $fail('deleteData failed to remove the managed theme.');
    }

    if ($builder->listProjects() !== []) {
        $fail('deleteData failed to clear project metadata.');
    }

    if (is_dir($releases . '/classic')) {
        $fail('deleteData failed to remove generated artifacts.');
    }

    echo 'Theme Builder behavior tests passed.' . PHP_EOL;
} finally {
    if (is_dir($base)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($base);
    }
}
/* [End AI:GPT-5.6 Sol] */
