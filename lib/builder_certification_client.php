<?php

declare(strict_types=1);

/* [AI:GPT-5.6 Sol | 2026-09-04 21:51:00 UTC] */

/**
 * Shared read-only Chaos MVC developer certification client.
 *
 * Certification describes developer qualification. It never authorizes Builder
 * use and never replaces release signature verification.
 */
final class builder_certification_client
{
    private const DEFAULT_ENDPOINT = 'https://chaos-mvc.org/developers/verify';
    private const SUCCESS_TTL = 86400;
    private const FAILURE_TTL = 300;
    private const MAX_RESPONSE_BYTES = 65536;

    private string $transportKey = '';

    public function __construct(
        private string $cacheDirectory
    ) {
        //$configPath = __DIR__ . '/../data/certifications.json';
		$configPath = USERROOT . '/data/certification.json';

        $config = json_decode(
            (string) @file_get_contents($configPath),
            true
        );

        $key = is_array($config)
            ? strtolower(
                trim(
                    (string) ($config['transport_key'] ?? '')
                )
            )
            : '';

        if (preg_match('/^[a-f0-9]{64}$/', $key) === 1) {
            $this->transportKey = $key;
        }
    }

    /**
     * Verify a developer's certification and signing identity.
     *
     * @param string $developer
     * @param string $domain
     * @param string $artifactType
     * @param string $algorithm
     * @param string $keyId
     *
     * @return array<string, mixed>
     */
    public function verify(
        string $developer,
        string $domain,
        string $artifactType,
        string $algorithm,
        string $keyId
    ): array {
        $developer = trim($developer);
        $domain = strtolower(trim($domain));
        $artifactType = strtolower(trim($artifactType));
        $algorithm = strtolower(trim($algorithm));
        $keyId = strtolower(trim($keyId));

        $base = [
            'state' => 'not_verified',
            'certified' => false,
            'signing' => false,
            'developer' => $developer,
            'domain' => $domain,
            'certification' => $artifactType,
            'key_id' => $keyId,
            'credential_id' => null,
            'public_key' => null,
            'fingerprint' => null,
            'reason' => null,
            'message' => (
                'Certification: Not Verified. '
                . 'Builder and signing remain available.'
            ),
        ];

        if (
            $developer === ''
            || !$this->validDomain($domain)
            || !in_array($artifactType, ['module', 'theme'], true)
            || !in_array($algorithm, ['rsa-sha256', 'openpgp'], true)
            || preg_match(
                '/^[a-z0-9][a-z0-9_-]{2,63}$/',
                $keyId
            ) !== 1
        ) {
            return array_replace(
                $base,
                [
                    'reason' => 'identity_not_configured',
                ]
            );
        }

        $cache = $this->readCache(
            $developer,
            $domain,
            $artifactType,
            $algorithm,
            $keyId
        );

        if ($cache !== null) {
            return array_replace(
                $base,
                $cache,
                [
                    'message' => $this->message($cache),
                ]
            );
        }

        $endpoint = trim(
            (string) (
                getenv('CHAOS_CERTIFICATION_ENDPOINT')
                ?: self::DEFAULT_ENDPOINT
            )
        );

        if (!$this->validEndpoint($endpoint)) {
            return array_replace(
                $base,
                [
                    'state' => 'unavailable',
                    'reason' => 'invalid_endpoint',
                    'message' => (
                        'Certification: Unavailable '
                        . '(invalid certification endpoint). '
                        . 'Builder and signing remain available.'
                    ),
                ]
            );
        }

        $query = http_build_query(
            [
                'developer' => $developer,
                'domain' => $domain,
                'type' => $artifactType,
                'key_id' => $keyId,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $separator = str_contains($endpoint, '?')
            ? '&'
            : '?';

        [$status, $raw, $transportError] = $this->request(
            $endpoint . $separator . $query
        );

        $response = is_string($raw)
            ? json_decode($raw, true)
            : null;

        if (
            $status < 200
            || $status >= 300
            || !is_array($response)
        ) {
            if ($transportError !== '') {
                $failure = $transportError;
            } elseif ($status > 0) {
                $failure = 'HTTP ' . $status;

                if (is_string($raw) && !is_array($response)) {
                    $failure .= ', response was not JSON';
                }
            } else {
                $failure = 'no HTTP response';
            }

            return array_replace(
                $base,
                [
                    'state' => 'unavailable',
                    'reason' => 'service_unavailable',
                    'message' => (
                        'Certification: Unavailable ('
                        . $failure
                        . '). Builder and signing remain available.'
                    ),
                ]
            );
        }

        $result = $this->normalize(
            $response,
            $developer,
            $domain,
            $artifactType,
            $algorithm,
            $keyId
        );

        $this->writeCache(
            $developer,
            $domain,
            $artifactType,
            $algorithm,
            $keyId,
            $result
        );

        return array_replace(
            $base,
            $result,
            [
                'message' => $this->message($result),
            ]
        );
    }

    /**
     * Normalize and validate a certification response.
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    public function normalize(
        array $response,
        string $developer,
        string $domain,
        string $artifactType,
        string $algorithm,
        string $keyId
    ): array {
        $reason = (string) (
            $response['reason']
            ?? 'invalid_response'
        );

        $certification = is_array(
            $response['certification'] ?? null
        )
            ? $response['certification']
            : [];

        $certificationType = is_string(
            $response['certification'] ?? null
        )
            ? $response['certification']
            : ($certification['type'] ?? null);

        $exact = (
            is_string($response['developer'] ?? null)
            && hash_equals(
                $developer,
                $response['developer']
            )
            && is_string($response['domain'] ?? null)
            && hash_equals(
                $domain,
                strtolower($response['domain'])
            )
            && is_string($certificationType)
            && hash_equals(
                $artifactType,
                strtolower($certificationType)
            )
        );

        $signing = is_array($response['signing'] ?? null)
            ? $response['signing']
            : [];

        $signingExact = (
            is_string($signing['algorithm'] ?? null)
            && hash_equals(
                $algorithm,
                strtolower($signing['algorithm'])
            )
            && is_string($signing['key_id'] ?? null)
            && hash_equals(
                $keyId,
                strtolower($signing['key_id'])
            )
        );

        $expiry = (
            $response['expires_at']
            ?? $certification['expires_at']
            ?? null
        );

        $notExpired = (
            $expiry === null
            || (
                is_string($expiry)
                && strtotime($expiry) !== false
                && strtotime($expiry) > time()
            )
        );

        $active = (
            !array_key_exists('status', $response)
            || (
                is_string($response['status'])
                && strtolower(
                    trim($response['status'])
                ) === 'active'
            )
        );

        $verified = (
            ($response['certified'] ?? false) === true
            && $exact
            && $signingExact
            && $notExpired
            && $active
        );

        $publicKeySource = (
            $signing['public_key']
            ?? $response['signing_public_key']
            ?? null
        );

        $publicKey = null;

        if ($verified && is_string($publicKeySource)) {
            if (str_contains($publicKeySource, '-----BEGIN ')) {
                $publicKey = base64_encode(
                    trim($publicKeySource) . "\n"
                );
            } else {
                $publicKey = preg_replace(
                    '/\s+/',
                    '',
                    $publicKeySource
                );
            }
        }

        if (
            $publicKey !== null
            && (
                $publicKey === ''
                || base64_decode($publicKey, true) === false
            )
        ) {
            $publicKey = null;
        }

        $credentialId = (
            $response['credential_id']
            ?? $certification['credential_id']
            ?? null
        );

        $fingerprint = (
            $signing['fingerprint']
            ?? $response['signing_fingerprint']
            ?? $response['openpgp_fingerprint']
            ?? null
        );

        if (!$notExpired) {
            $failureReason = 'certification_expired';
        } elseif (!$active) {
            $failureReason = 'certification_inactive';
        } else {
            $failureReason = $reason;
        }

        return [
            'state' => $verified
                ? 'verified'
                : 'not_verified',
            'certified' => $verified,
            'signing' => $verified,
            'developer' => $developer,
            'domain' => $domain,
            'certification' => $artifactType,
            'key_id' => $keyId,
            'credential_id' => (
                $verified
                && is_scalar($credentialId)
            )
                ? (string) $credentialId
                : null,
            'public_key' => $publicKey,
            'fingerprint' => (
                $verified
                && is_string($fingerprint)
            )
                ? trim($fingerprint)
                : null,
            'reason' => $verified
                ? null
                : $failureReason,
            'verified_at' => gmdate('c'),
            'expires_at' => gmdate(
                'c',
                time()
                + (
                    $verified
                        ? self::SUCCESS_TTL
                        : self::FAILURE_TTL
                )
            ),
        ];
    }

    /**
     * Build a human-readable verification message.
     *
     * @param array<string, mixed> $result
     */
    private function message(array $result): string
    {
        if (($result['state'] ?? '') === 'verified') {
            return (
                'Certification: Verified. '
                . 'Developer, domain, artifact type, and signing identity '
                . 'match chaos-mvc.org.'
            );
        }

        $reason = !empty($result['reason'])
            ? ' (' . $result['reason'] . ')'
            : '';

        return (
            'Certification: Not Verified'
            . $reason
            . '. Builder and signing remain available.'
        );
    }

    /**
     * Read a cached verification result.
     *
     * @return array<string, mixed>|null
     */
    private function readCache(
        string $developer,
        string $domain,
        string $type,
        string $algorithm,
        string $keyId
    ): ?array {
        $path = $this->cachePath(
            $developer,
            $domain,
            $type,
            $algorithm,
            $keyId
        );

        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $decoded = json_decode(
            (string) file_get_contents($path),
            true
        );

        if (
            !is_array($decoded)
            || !is_string($decoded['expires_at'] ?? null)
        ) {
            return null;
        }

        $expiry = strtotime($decoded['expires_at']);

        if ($expiry === false || $expiry <= time()) {
            return null;
        }

        return $decoded;
    }

    /**
     * Write a verification result to the local cache.
     *
     * @param array<string, mixed> $result
     */
    private function writeCache(
        string $developer,
        string $domain,
        string $type,
        string $algorithm,
        string $keyId,
        array $result
    ): void {
        if (is_link($this->cacheDirectory)) {
            return;
        }

        if (
            !is_dir($this->cacheDirectory)
            && !mkdir(
                $this->cacheDirectory,
                0700,
                true
            )
        ) {
            return;
        }

        $path = $this->cachePath(
            $developer,
            $domain,
            $type,
            $algorithm,
            $keyId
        );

        $temporary = (
            $path
            . '.tmp-'
            . bin2hex(random_bytes(6))
        );

        $json = json_encode(
            $result,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        ) . "\n";

        if (
            file_put_contents(
                $temporary,
                $json,
                LOCK_EX
            ) === strlen($json)
        ) {
            chmod($temporary, 0600);

            if (file_exists($path)) {
                unlink($path);
            }

            rename($temporary, $path);
        }

        if (is_file($temporary)) {
            unlink($temporary);
        }
    }

    /**
     * Build the cache path for a certification identity.
     */
    private function cachePath(
        string $developer,
        string $domain,
        string $type,
        string $algorithm,
        string $keyId
    ): string {
        $identity = implode(
            "\0",
            [
                'v2',
                $developer,
                $domain,
                $type,
                $algorithm,
                $keyId,
            ]
        );

        return (
            rtrim(
                $this->cacheDirectory,
                '/\\'
            )
            . DIRECTORY_SEPARATOR
            . hash('sha256', $identity)
            . '.json'
        );
    }

    /**
     * Validate a developer domain.
     */
    private function validDomain(string $domain): bool
    {
        return preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}'
            . '[a-z0-9])?\.)+[a-z]{2,63}$/',
            $domain
        ) === 1;
    }

    /**
     * Validate the certification authority endpoint.
     */
    private function validEndpoint(string $url): bool
    {
        $parts = parse_url($url);

        if (
            filter_var(
                $url,
                FILTER_VALIDATE_URL
            ) === false
            || !is_array($parts)
            || strtolower(
                (string) ($parts['scheme'] ?? '')
            ) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (
                isset($parts['port'])
                && $parts['port'] !== 443
            )
        ) {
            return false;
        }

        $host = strtolower(
            (string) $parts['host']
        );

        return (
            $host === 'chaos-mvc.org'
            || $host === 'www.chaos-mvc.org'
        );
    }

    /**
     * Perform the certification authority request.
     *
     * cURL is preferred. HTTPS streams are used as a fallback.
     *
     * @return array{0:int,1:string|false,2:string}
     */
    private function request(string $url): array
    {
        $errors = [];

        if (function_exists('curl_init')) {
            $handle = curl_init($url);

            if ($handle !== false) {
                $headers = [
                    'Accept: application/json',
                ];

                if ($this->transportKey !== '') {
                    $headers[] = (
                        'X-API-KEY: '
                        . $this->transportKey
                    );
                }

                curl_setopt_array(
                    $handle,
                    [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_HTTPHEADER => $headers,
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    ]
                );

                $raw = curl_exec($handle);

                $status = (int) curl_getinfo(
                    $handle,
                    CURLINFO_RESPONSE_CODE
                );

                if ($raw === false) {
                    $errors[] = (
                        'cURL: '
                        . curl_error($handle)
                    );
                }

                curl_close($handle);

                if (
                    is_string($raw)
                    && strlen($raw) <= self::MAX_RESPONSE_BYTES
                ) {
                    return [
                        $status,
                        $raw,
                        '',
                    ];
                }
            }
        }

        if (
            !filter_var(
                ini_get('allow_url_fopen'),
                FILTER_VALIDATE_BOOL
            )
        ) {
            $errors[] = 'HTTPS streams disabled';

            return [
                0,
                false,
                implode('; ', $errors),
            ];
        }

        $header = (
            "Accept: application/json\r\n"
            . "Connection: close\r\n"
        );

        if ($this->transportKey !== '') {
            $header .= (
                'X-API-KEY: '
                . $this->transportKey
                . "\r\n"
            );
        }

        $context = stream_context_create(
            [
                'http' => [
                    'method' => 'GET',
                    'header' => $header,
                    'timeout' => 10,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]
        );

        $raw = @file_get_contents(
            $url,
            false,
            $context,
            0,
            self::MAX_RESPONSE_BYTES
        );

        if ($raw === false) {
            $errors[] = 'HTTPS stream request failed';
        }

        return [
            $this->httpStatus(
                $http_response_header ?? []
            ),
            $raw,
            implode('; ', $errors),
        ];
    }

    /**
     * Extract an HTTP response status from stream headers.
     *
     * @param array<int, string> $headers
     */
    private function httpStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (
                preg_match(
                    '/^HTTP\/\S+\s+(\d{3})\b/i',
                    (string) $header,
                    $match
                ) === 1
            ) {
                return (int) $match[1];
            }
        }

        return 0;
    }
}

/* [End AI:GPT-5.6 Sol] */