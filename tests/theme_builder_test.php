<?php
/* [AI:GPT-5.6 Sol | 2026-08-29 02:00:00 UTC] */
require dirname(__DIR__) . '/lib/theme_package_builder.php';

$base = sys_get_temp_dir() . '/chaos-theme-builder-test-' . bin2hex(random_bytes(5));
$themes = $base . '/user/themes';
$releases = $base . '/releases';
$metadata = $base . '/user/data/theme_builder_projects.json';
$builder = new theme_package_builder($themes, $releases, $metadata);

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

try {
    $builder->createProject(
        [
            'slug' => 'classic',
            'name' => 'Classic',
            'version' => '1.0.0',
            'description' => 'Theme Builder test theme.',
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

    if (class_exists('ZipArchive')) {
        $artifact = $builder->buildRelease('classic');

        if (!is_file($artifact)) {
            $fail('Theme artifact missing.');
        }

        $manifest = $releases . '/classic/classic-1.0.1.manifest.json';

        if (!is_file($manifest)) {
            $fail('Theme artifact manifest missing.');
        }
    }

    $builder->deleteProject('classic');

    if (is_dir($themes . '/classic')) {
        $fail('deleteProject failed.');
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
