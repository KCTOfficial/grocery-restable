<?php

declare(strict_types=1);

namespace GroceryRestable\Auth;

use GroceryRestable\Exception\ApiException;
use GroceryRestable\Store\ConfigStore;

/**
 * Verifies Google ID tokens (Sign in with Google, One Tap, Android / iOS SDKs) locally:
 * RS256 signature against Google's published keys (cached), issuer, audience, expiry and hosted domain.
 * Can also exchange an OAuth authorization code (popup "code" flow, server redirect flow) for an ID token.
 */
final class GoogleVerifier
{
    public const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];
    public const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const CACHE_KEY = 'auth:google:jwks';
    private const LEEWAY = 60;
    /** Unknown key ids trigger a refetch at most this often (key rotation, without letting clients hammer Google). */
    private const REFETCH_AFTER = 300;

    /** @var callable(string, string, array<string, string>): array{status: int, headers: array<string, string>, body: string} */
    private $http;

    /**
     * @param callable(string, string, array<string, string>): array{status: int, headers: array<string, string>, body: string} $http
     */
    public function __construct(private readonly ConfigStore $store, callable $http)
    {
        $this->http = $http;
    }

    /**
     * @param list<string> $clientIds Accepted audiences.
     * @param list<string> $hostedDomains Accepted "hd" claims (Google Workspace), empty = any account.
     * @return array<string, mixed> The verified claims.
     */
    public function verifyIdToken(string $idToken, array $clientIds, array $hostedDomains = []): array
    {
        if ($clientIds === []) {
            throw new ApiException('Google sign-in has no client id configured.', 500, 'configuration_error');
        }
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw ApiException::unauthorized('Malformed Google ID token.');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode((string) self::unb64($h), true);
        $claims = json_decode((string) self::unb64($p), true);
        $signature = self::unb64($s);
        if (! is_array($header) || ! is_array($claims) || $signature === false) {
            throw ApiException::unauthorized('Malformed Google ID token.');
        }
        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw ApiException::unauthorized('Unsupported Google ID token algorithm.');
        }

        $pem = $this->key($header['kid']);
        if ($pem === null || openssl_verify($h . '.' . $p, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw ApiException::unauthorized('Invalid Google ID token signature.');
        }

        $now = time();
        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw ApiException::unauthorized('Invalid Google ID token issuer.');
        }
        if (! array_intersect((array) ($claims['aud'] ?? []), $clientIds)) {
            throw ApiException::unauthorized('The Google ID token was issued for another application.');
        }
        if (! is_numeric($claims['exp'] ?? null) || $now - self::LEEWAY >= (int) $claims['exp']) {
            throw ApiException::unauthorized('The Google ID token has expired.');
        }
        if (isset($claims['iat']) && (! is_numeric($claims['iat']) || (int) $claims['iat'] > $now + self::LEEWAY)) {
            throw ApiException::unauthorized('Invalid Google ID token issue time.');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw ApiException::unauthorized('The Google ID token has no subject.');
        }
        if ($hostedDomains !== [] && ! in_array(strtolower((string) ($claims['hd'] ?? '')), array_map('strtolower', $hostedDomains), true)) {
            throw ApiException::forbidden('This Google account is not allowed to sign in.');
        }

        return $claims;
    }

    /**
     * Exchange an authorization code for the ID token. $redirectUri is "postmessage" for the
     * Google Identity Services popup code flow, otherwise the redirect URI used to obtain the code.
     */
    public function exchangeCode(string $code, string $clientId, string $clientSecret, string $redirectUri = 'postmessage', ?string $codeVerifier = null): string
    {
        if ($clientSecret === '') {
            throw new ApiException('Google sign-in has no client secret configured (needed for authorization codes).', 500, 'configuration_error');
        }
        $form = array_filter([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        $response = ($this->http)('POST', self::TOKEN_URL, $form);
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || ! is_array($data) || ! is_string($data['id_token'] ?? null)) {
            // Google answers 400 invalid_grant for expired / reused / foreign codes.
            throw ApiException::unauthorized('The Google authorization code is invalid or expired.');
        }

        return $data['id_token'];
    }

    /**
     * PEM public key for a key id, from the cached key set (refetched on unknown ids, rate limited).
     */
    private function key(string $kid): ?string
    {
        $cached = $this->store->cacheGet(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['keys'][$kid])) {
            return $cached['keys'][$kid];
        }
        if (is_array($cached) && time() - (int) ($cached['fetched_at'] ?? 0) < self::REFETCH_AFTER) {
            return null;
        }

        $response = ($this->http)('GET', self::JWKS_URL, []);
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || ! is_array($data) || ! is_array($data['keys'] ?? null)) {
            throw new ApiException('Google sign-in keys could not be loaded.', 502, 'provider_unavailable');
        }
        $keys = [];
        foreach ($data['keys'] as $jwk) {
            if (is_array($jwk) && ($jwk['kty'] ?? null) === 'RSA' && is_string($jwk['kid'] ?? null) && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                $pem = self::rsaPem($jwk['n'], $jwk['e']);
                if ($pem !== null) {
                    $keys[$jwk['kid']] = $pem;
                }
            }
        }
        $maxAge = preg_match('/max-age=(\d+)/', (string) ($response['headers']['cache-control'] ?? ''), $m) ? (int) $m[1] : 3600;
        $this->store->cachePut(self::CACHE_KEY, ['fetched_at' => time(), 'keys' => $keys], max(self::REFETCH_AFTER, min(86400, $maxAge)));

        return $keys[$kid] ?? null;
    }

    /**
     * RSA public key (JWK modulus / exponent, base64url) as a PEM SubjectPublicKeyInfo.
     */
    public static function rsaPem(string $n, string $e): ?string
    {
        $modulus = self::unb64($n);
        $exponent = self::unb64($e);
        if ($modulus === false || $exponent === false || $modulus === '' || $exponent === '') {
            return null;
        }
        $rsaPublicKey = self::der(0x30, self::derInteger($modulus) . self::derInteger($exponent));
        $algorithm = hex2bin('300d06092a864886f70d0101010500'); // SEQUENCE { rsaEncryption, NULL }
        $spki = self::der(0x30, $algorithm . self::der(0x03, "\x00" . $rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 0x80) {
            return chr($tag) . chr($length) . $value;
        }
        $lengthBytes = ltrim(pack('N', $length), "\x00");

        return chr($tag) . chr(0x80 | strlen($lengthBytes)) . $lengthBytes . $value;
    }

    private static function unb64(string $data): string|false
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
