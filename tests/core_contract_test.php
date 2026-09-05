<?php
declare(strict_types=1);
/* [AI:GPT-5.6 | 2026-09-04 UTC] */
/** Usage: php tests/core_contract_test.php /path/to/chaos-mvc */
$kind = 'theme';
putenv('CHAOS_CERTIFICATION_ENDPOINT=https://chaos-mvc.org:444/developers/verify');
$core = $argv[1] ?? '';
if (!is_file($core . '/app/lib/release_signature.php')) {
    throw new RuntimeException('Pass a checkout of chaos-mvc containing release_signature.php.');
}
require dirname(__DIR__) . '/lib/' . $kind . '_package_builder.php';
// No Core constructor, filesystem mutation, routing or network operation is invoked.
class controller {}
require $core . '/app/controllers/admin.php';
require $core . '/app/lib/theme_updater.php';
require $core . '/app/lib/release_signature.php';
$verifierClass = $kind === 'module' ? admin::class : theme_updater::class;
$verifier = (new ReflectionClass($verifierClass))->newInstanceWithoutConstructor();
$verify = new ReflectionMethod($verifierClass, 'verify' . ucfirst($kind) . 'ReleaseSignature');
$archiveCheck = new ReflectionMethod($verifierClass, 'validate' . ucfirst($kind) . 'Archive');
$base = sys_get_temp_dir() . '/builder-contract-test-' . bin2hex(random_bytes(10));
mkdir($base, 0700);
$fail = static function (string $message): never { throw new RuntimeException($message); };
$reject = static function (callable $call, string $message) use ($fail): void {
    try { $call(); } catch (RuntimeException | InvalidArgumentException $expected) { return; }
    $fail($message);
};
$remove = static function (string $path) use (&$remove): void {
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child) && !is_link($child)) $remove($child); else unlink($child);
    }
    rmdir($path);
};
try {
    $builder = $kind === 'module'
        ? new module_package_builder($base . '/projects', $base . '/releases', $base . '/data/certification.json')
        : new theme_package_builder($base . '/projects', $base . '/releases', $base . '/data/projects.json', $base . '/data/certification.json');
    $input = [
        'slug' => 'fixture', 'name' => 'Fixture', 'version' => '1.0.1',
        'description' => 'Core contract fixture', 'update_url' => 'https://example.com/updates/fixture.json',
        'creator' => 'Test', 'domain' => 'example.com', 'certified' => 'No',
    ];
    $builder->createProject($input);
    $options = ['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => __DIR__ . '/openssl.cnf'];
    $key = openssl_pkey_new($options);
    if ($key === false) $fail('Cannot create test RSA key.');
    openssl_pkey_export($key, $private, 'fixture-passphrase', $options);
    $public = openssl_pkey_get_details($key)['key'];
    $input += ['signing_type' => 'rsa-sha256', 'signing_key_id' => 'fixture-publisher', 'signing_public_key' => base64_encode($public)];
    $builder->editProject('fixture', $input);
    $metadata = json_decode(file_get_contents($base . '/projects/fixture/' . $kind . '.json'), true, 512, JSON_THROW_ON_ERROR);
    $download = 'https://example.com/releases/fixture-1.0.1.zip?edition=public';
    if ($kind === 'module') {
        $artifact = $builder->buildAndSignReleaseWithPem('fixture', $private, 'fixture-passphrase', $download);
    } else {
        $artifact = $builder->buildAndSignReleaseWithKey('fixture', $private, 'fixture-passphrase', $download);
    }
    $manifest = json_decode(file_get_contents(substr($artifact, 0, -4) . '.manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $check = static function (array $m, array $config) use ($verify, $verifier, $kind): void {
        $verify->invoke($verifier, $m, $config, $m[$kind], $m['version'], $m['download'], $m['sha256']);
    };
    $check($manifest, $metadata);
    $archiveCheck->invoke($verifier, $artifact, 'fixture');
    if ($manifest['sha256'] !== hash_file('sha256', $artifact)) $fail('Wrong ZIP hash.');
    if ($manifest['signature'] !== base64_encode(file_get_contents($artifact . '.sig'))) $fail('JSON must embed signature bytes, not a filename.');
    foreach ([$kind, 'version', 'download', 'sha256', 'key_id', 'signature'] as $field) {
        $tampered = $manifest;
        $tampered[$field] .= 'x';
        $reject(fn() => $check($tampered, $metadata), 'Core accepted tampered ' . $field);
    }
    $wrongDomain = str_replace('CHAOS-MVC-' . strtoupper($kind), 'CHAOS-MVC-' . ($kind === 'module' ? 'THEME' : 'MODULE'), builder_release_signer::statement($kind, $manifest));
    $reject(fn() => release_signature::verify($metadata['signing'], $manifest['key_id'], $manifest['signature'], $wrongDomain), 'Cross-kind signature accepted.');
    $wrongKey = openssl_pkey_new($options);
    openssl_pkey_export($wrongKey, $wrongPrivate, 'fixture-passphrase', $options);
    $reject(fn() => builder_release_signer::sign($kind, $metadata, $artifact, $download, $wrongPrivate, 'fixture-passphrase'), 'Wrong private key accepted.');
    $reject(fn() => builder_release_signer::sign($kind, $metadata, $artifact, $download, $private, 'wrong-passphrase'), 'Wrong passphrase accepted.');
    $reject(fn() => builder_release_signer::sign($kind, $metadata, $artifact, 'https://other.example/fixture.zip', $private, 'fixture-passphrase'), 'Unapproved host accepted.');
    $reject(fn() => builder_release_signer::sign($kind, $metadata, $artifact, 'http://example.com/fixture.zip', $private, 'fixture-passphrase'), 'HTTP accepted.');
    $conflict = $metadata;
    $conflict['signing']['type'] = 'openpgp';
    $reject(fn() => builder_release_signer::sign($kind, $conflict, $artifact, $download, $private, 'fixture-passphrase'), 'Conflicting algorithms accepted.');
    $legacy = $manifest;
    openssl_sign($manifest['sha256'], $legacySignature, $key, OPENSSL_ALGO_SHA256);
    $legacy['signature'] = base64_encode($legacySignature);
    $reject(fn() => $check($legacy, $metadata), 'Legacy checksum-only signature accepted.');
    $remotePath = dirname($artifact) . '/fixture.remote.json';
    $remote = json_decode(file_get_contents($remotePath), true, 512, JSON_THROW_ON_ERROR);
    if (array_keys($remote) !== [$kind, 'version', 'download', 'sha256', 'key_id', 'signature']) $fail('Remote JSON shape differs from guide.');
    $check($remote, $metadata);
    builder_release_signer::verifyWrittenRelease($kind, $metadata, $manifest, $artifact);
    if (($manifest['verification']['saved_release_files'] ?? '') !== 'verified'
        || ($manifest['verification']['developer_domain'] ?? '') !== 'not_checked'
        || ($manifest['verification']['publication'] ?? '') !== 'local_data_verified') $fail('Ambiguous verification status.');
    $staged = $manifest['verification']['staging_directory'] . DIRECTORY_SEPARATOR . basename($artifact);
    builder_release_signer::verifyWrittenRelease($kind, $metadata, $manifest, $staged);
    $stagedBytes = file_get_contents($staged);
    file_put_contents($staged, $stagedBytes . 'x');
    $reject(fn() => builder_release_signer::stageLocalRelease($kind, $metadata, $manifest, $artifact), 'Staging overwrote changed existing ZIP.');
    file_put_contents($staged, $stagedBytes);
    builder_release_signer::stageLocalRelease($kind, $metadata, $manifest, $artifact);
    if (count(array_diff(scandir(dirname($staged)), ['.', '..'])) !== 4) $fail('Unexpected staged files.');
    foreach ([$kind, 'version', 'download', 'sha256', 'key_id', 'signature'] as $field) {
        $tampered = $remote;
        $tampered[$field] .= 'x';
        $reject(fn() => builder_release_signer::verifyRelease($kind, $metadata, $tampered, $artifact), 'Builder accepted tampered ' . $field);
    }
    foreach (builder_release_signer::releaseFiles($kind, $metadata, $manifest, $artifact) as $file => $bytes) {
        file_put_contents($file, $bytes . 'x');
        $reject(fn() => builder_release_signer::verifyWrittenRelease($kind, $metadata, $manifest, $artifact), 'Read-back accepted modified ' . $file);
        file_put_contents($file, $bytes);
    }
    $zipBytes = file_get_contents($artifact);
    file_put_contents($artifact, $zipBytes . 'x');
    $reject(fn() => builder_release_signer::verifyRelease($kind, $metadata, $remote, $artifact), 'Builder accepted modified ZIP.');
    file_put_contents($artifact, $zipBytes);
    if (file_get_contents(substr($artifact, 0, -4) . '-release.txt') !== builder_release_signer::statement($kind, $remote)) $fail('Statement bytes differ.');
    if (!isset($metadata['signing']['algorithm']) || isset($metadata['signing']['type'])) $fail('Local manifest needs canonical algorithm field.');
    $aliasTrust = $metadata;
    $aliasTrust['signing']['type'] = $aliasTrust['signing']['algorithm'];
    unset($aliasTrust['signing']['algorithm']);
    $check($manifest, $aliasTrust);
    $aliasTrust['signing']['public_key'] = $public;
    $check($manifest, $aliasTrust);
    $weakOptions = array_replace($options, ['private_key_bits' => 2048]);
    $weakKey = openssl_pkey_new($weakOptions);
    openssl_pkey_export($weakKey, $weakPrivate, 'fixture-passphrase', $weakOptions);
    $weakInput = array_replace($input, ['signing_public_key' => base64_encode(openssl_pkey_get_details($weakKey)['key'])]);
    $builder->editProject('fixture', $weakInput);
    $weakMetadata = json_decode(file_get_contents($base . '/projects/fixture/' . $kind . '.json'), true);
    $weakArtifact = $builder->buildRelease('fixture');
    $reject(fn() => builder_release_signer::sign($kind, $weakMetadata, $weakArtifact, $download, $weakPrivate, 'fixture-passphrase'), 'RSA below 3072 bits accepted.');
    if (file_exists($remotePath)) $fail('Rebuild retained stale remote publication JSON.');
    if (file_exists($weakArtifact . '.sig')) $fail('Rebuild retained stale signature.');
    echo ucfirst($kind) . " RSA signing, Core verification, Core archive validation and tamper tests passed.\n";

    // PGP uses a disposable publisher, never the operator's default keyring.
    if (extension_loaded('gnupg') && version_compare((string) phpversion('gnupg'), '1.5.0', '>=')) {
        $home = $base . '/fixture-gnupg';
        mkdir($home, 0700);
        $run = static function (array $args) use ($home): string {
            $pipes = [];
            $process = proc_open(array_merge(['gpg', '--homedir', $home, '--batch', '--pinentry-mode', 'loopback', '--passphrase', 'fixture-passphrase'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start fixture GnuPG.');
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new RuntimeException('Fixture GnuPG failed: ' . $error);
            return $out;
        };
        $run(['--quick-generate-key', 'Builder Fixture <fixture@example.invalid>', 'rsa3072', 'sign', '0']);
        $public = $run(['--export']);
        $private = $run(['--export-secret-keys']);
        $input['signing_type'] = 'openpgp';
        $input['signing_public_key'] = base64_encode($public);
        $builder->editProject('fixture', $input);
        $metadata = json_decode(file_get_contents($base . '/projects/fixture/' . $kind . '.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($kind === 'module') {
            $artifact = $builder->buildAndSignReleaseWithPem('fixture', $private, 'fixture-passphrase', $download);
        } else {
            $artifact = $builder->buildRelease('fixture');
            $builder->signReleaseWithKey('fixture', basename($artifact), $private, 'fixture-passphrase', $download);
        }
        $manifest = json_decode(file_get_contents(substr($artifact, 0, -4) . '.manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $check($manifest, $metadata);
        echo ucfirst($kind) . " OpenPGP signing and Core verification passed.\n";
    } else {
        $input['signing_type'] = 'openpgp';
        $input['signing_public_key'] = base64_encode('unavailable-backend-fixture');
        $builder->editProject('fixture', $input);
        $metadata = json_decode(file_get_contents($base . '/projects/fixture/' . $kind . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $artifact = $builder->buildRelease('fixture');
        try {
            builder_release_signer::sign($kind, $metadata, $artifact, $download, 'not-a-private-key', '');
            $fail('Missing GnuPG silently fell back.');
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'requires PHP GnuPG')) throw $e;
        }
        echo "PASS: missing GnuPG fails closed. SKIP: real OpenPGP round-trip (PHP GnuPG 1.5+ unavailable).\n";
    }
} finally {
    $remove($base);
}
/* [End AI:GPT-5.6] */
