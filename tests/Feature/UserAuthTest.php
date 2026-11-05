<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Feature;

use GroceryRestable\Exception\ApiException;
use GroceryRestable\Support\Jwt;
use GroceryRestable\Tests\Support\ApiTestCase;

final class UserAuthTest extends ApiTestCase
{
    private const SECRET = 'jwt-secret-jwt-secret-jwt-secret-jwt-secret';
    private const CLIENT_ID = 'web-client.apps.googleusercontent.com';

    protected function settings(): array
    {
        return [
            'auth_mode' => 'any',
            'jwt_secret' => self::SECRET,
            'auth_password_enabled' => true,
            'auth_users_identity_columns' => ['email', 'username'],
            'auth_users_role_column' => 'role',
            'auth_users_active_column' => 'active',
            'auth_users_claim_columns' => ['tenant_id'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $hash = password_hash('correct horse', PASSWORD_BCRYPT);
        $this->db()->exec("CREATE TABLE users (
                id INTEGER PRIMARY KEY, email VARCHAR(100) UNIQUE, username VARCHAR(50), password VARCHAR(255) NOT NULL,
                role VARCHAR(30), active BOOLEAN NOT NULL DEFAULT 1, tenant_id INTEGER, google_id VARCHAR(64), name VARCHAR(100)
            );
            INSERT INTO users (id, email, username, password, role, active, tenant_id) VALUES
                (1, 'Alice@Example.com', 'alice', '$hash', 'editor', 1, 7),
                (2, 'bob@example.com', 'bob', '$hash', NULL, 0, 7),
                (3, 'root@example.com', 'root', '$hash', 'service_role', 1, NULL),
                (4, 'legacy@example.com', 'legacy', '" . md5('old secret') . "', NULL, 1, NULL);");
        $this->api->resource('offices')->setPermissions(['editor' => ['select']]);
    }

    private function login(string $identity = 'alice@example.com', string $password = 'correct horse'): array
    {
        return $this->call('POST', 'auth/login', ['identity' => $identity, 'password' => $password]);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function storedHash(int $id): string
    {
        return (string) $this->db()->query('SELECT password FROM users WHERE id = ' . $id)->fetchColumn();
    }

    // ------------------------------------------------------------------ password login

    public function testPasswordLoginIssuesAJwtUsableOnTheApi(): void
    {
        [$status, $body, $response] = $this->login();
        self::assertSame(200, $status);
        self::assertSame('no-store', $response->getHeader('Cache-Control'));
        self::assertSame('Bearer', $body['token_type']);
        self::assertStringStartsWith('grr_', $body['refresh_token']);
        self::assertArrayNotHasKey('password', $body['user']);
        self::assertSame('alice', $body['user']['username']);

        $claims = Jwt::decode($body['access_token'], self::SECRET);
        self::assertSame('1', $claims['sub']);
        self::assertSame('editor', $claims['role']);
        self::assertSame(7, $claims['tenant_id']);
        self::assertSame('password', $claims['provider']);
        self::assertSame(900, $claims['exp'] - $claims['iat']);

        self::assertSame(200, $this->call('GET', 'offices', null, $this->bearer($body['access_token']))[0]);
        self::assertSame(401, $this->call('GET', 'offices')[0]);

        [$status, $me] = $this->call('GET', 'auth/me', null, $this->bearer($body['access_token']));
        self::assertSame(200, $status);
        self::assertSame('Alice@Example.com', $me['email']);

        // the username column is an identity too
        self::assertSame(200, $this->login('alice')[0]);
    }

    public function testUnknownUsersAndWrongPasswordsLookTheSame(): void
    {
        [$s1, $b1] = $this->login('alice@example.com', 'wrong');
        [$s2, $b2] = $this->login('nobody@example.com', 'wrong');
        self::assertSame([401, 401], [$s1, $s2]);
        self::assertSame($b1, $b2);
        self::assertSame('invalid_credentials', $b1['error']['code']);
        self::assertSame(422, $this->call('POST', 'auth/login', ['identity' => 'alice'])[0]);
    }

    public function testBruteForceProtection(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->login('alice', 'wrong ' . $i)[0]);
        }
        [$status, $body, $response] = $this->login('alice');
        self::assertSame(429, $status, 'Even the right password is refused while the account is throttled');
        self::assertSame('too_many_attempts', $body['error']['code']);
        self::assertNotNull($response->getHeader('Retry-After'));

        // per IP limit
        $this->api->store()->saveSettings(['auth_max_attempts' => 0, 'auth_max_attempts_ip' => 6]);
        self::assertSame(401, $this->login('somebody', 'x')[0]);
        self::assertSame(429, $this->login('bob', 'x')[0]);
    }

    public function testDisabledAccountsAndServiceRole(): void
    {
        [$status, $body] = $this->login('bob');
        self::assertSame(403, $status);
        self::assertSame('account_disabled', $body['error']['code']);

        [$status, $body] = $this->login('root');
        self::assertSame(403, $status);
        self::assertSame('role_not_allowed', $body['error']['code']);
    }

    public function testRefreshTokenRotationAndReuseDetection(): void
    {
        $first = $this->login()[1];
        [$status, $second] = $this->call('POST', 'auth/refresh', ['refresh_token' => $first['refresh_token']]);
        self::assertSame(200, $status);
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        self::assertSame('1', Jwt::decode($second['access_token'], self::SECRET)['sub']);

        // a parallel refresh with the previous token is tolerated for a few seconds
        self::assertSame(200, $this->call('POST', 'auth/refresh', ['refresh_token' => $first['refresh_token']])[0]);

        // later, the old token is treated as stolen: the whole session is revoked
        $this->api->store()->pdo()->exec('UPDATE auth_sessions SET rotated_at = rotated_at - 60');
        self::assertSame(401, $this->call('POST', 'auth/refresh', ['refresh_token' => $first['refresh_token']])[0]);
        self::assertSame(401, $this->call('POST', 'auth/refresh', ['refresh_token' => $second['refresh_token']])[0]);
    }

    public function testRefreshReadsTheUserAgain(): void
    {
        $tokens = $this->login()[1];
        $this->db()->exec("UPDATE users SET role = 'manager', tenant_id = 9 WHERE id = 1");
        $claims = Jwt::decode($this->call('POST', 'auth/refresh', ['refresh_token' => $tokens['refresh_token']])[1]['access_token'], self::SECRET);
        self::assertSame(['manager', 9], [$claims['role'], $claims['tenant_id']]);

        $tokens = $this->login()[1];
        $this->db()->exec('UPDATE users SET active = 0 WHERE id = 1');
        self::assertSame(403, $this->call('POST', 'auth/refresh', ['refresh_token' => $tokens['refresh_token']])[0]);
    }

    public function testLogout(): void
    {
        $tokens = $this->login()[1];
        self::assertSame(204, $this->call('POST', 'auth/logout', null, $this->bearer($tokens['access_token']))[0]);
        self::assertSame(401, $this->call('POST', 'auth/refresh', ['refresh_token' => $tokens['refresh_token']])[0]);
        self::assertSame(401, $this->call('POST', 'auth/logout')[0]);

        $a = $this->login()[1];
        $b = $this->login()[1];
        self::assertSame(204, $this->call('POST', 'auth/logout', ['refresh_token' => $a['refresh_token'], 'all' => true])[0]);
        self::assertSame(401, $this->call('POST', 'auth/refresh', ['refresh_token' => $b['refresh_token']])[0]);
    }

    public function testSessionStrategy(): void
    {
        $this->api->store()->saveSettings(['auth_token_strategy' => 'session']);
        [$status, $body] = $this->login();
        self::assertSame(200, $status);
        self::assertStringStartsWith('grs_', $body['access_token']);
        self::assertArrayNotHasKey('refresh_token', $body);

        self::assertSame(200, $this->call('GET', 'offices', null, $this->bearer($body['access_token']))[0]);
        self::assertSame('1', $this->call('GET', 'auth/me', null, $this->bearer($body['access_token']))[1]['id'] . '');

        self::assertSame(204, $this->call('POST', 'auth/logout', null, $this->bearer($body['access_token']))[0]);
        self::assertSame(401, $this->call('GET', 'offices', null, $this->bearer($body['access_token']))[0]);

        // revoking every session of a user (e.g. after a password change)
        $token = $this->login()[1]['access_token'];
        self::assertSame(1, $this->api->auth()->revokeSessions(1));
        self::assertSame(401, $this->call('GET', 'offices', null, $this->bearer($token))[0]);
    }

    public function testPasswordsAreRehashedWithTheConfiguredAlgorithm(): void
    {
        self::assertSame(200, $this->login()[0]);
        self::assertStringStartsWith('$argon2id$', $this->storedHash(1), 'bcrypt hash upgraded on login');

        self::assertSame(401, $this->login('legacy', 'old secret')[0], 'md5 is not accepted unless enabled');
        $this->api->store()->saveSettings(['auth_password_legacy' => ['md5']]);
        $api = \GroceryRestable\Tests\Support\Fixture::reopen($this->api);
        self::assertSame(200, \GroceryRestable\Tests\Support\Fixture::call($api, 'POST', 'auth/login', ['identity' => 'legacy', 'password' => 'old secret'])[0]);
        self::assertStringStartsWith('$argon2id$', $this->storedHash(4));
    }

    public function testResourcePasswordFieldsUseTheConfiguredHasher(): void
    {
        $this->api->store()->saveSettings(['auth_password_algorithm' => 'bcrypt', 'auth_password_cost' => 11]);
        $this->api->resource('users')->setPermissions('*', ['insert'])->fieldType('password', 'password');
        self::assertSame(201, $this->call('POST', 'users', ['email' => 'new@example.com', 'password' => 'brand new pass'])[0]);
        $hash = (string) $this->db()->query("SELECT password FROM users WHERE email = 'new@example.com'")->fetchColumn();
        self::assertStringStartsWith('$2y$11$', $hash);
        self::assertSame(200, $this->login('new@example.com', 'brand new pass')[0]);
    }

    public function testHooks(): void
    {
        $this->api->auth()
            ->claims(static fn (array $user): array => ['plan' => 'pro', 'sub' => 'hijack'])
            ->beforeLogin(static function (array $user): void {
                if ($user['username'] === 'legacy') {
                    throw new ApiException('Verify your email first.', 403, 'email_not_verified');
                }
            })
            ->userPayload(static fn (array $user): array => ['id' => $user['id'], 'name' => $user['username']]);

        $body = $this->login()[1];
        $claims = Jwt::decode($body['access_token'], self::SECRET);
        self::assertSame(['pro', '1'], [$claims['plan'], $claims['sub']]);
        self::assertSame(['id' => 1, 'name' => 'alice'], $body['user']);

        $this->api->store()->saveSettings(['auth_password_legacy' => ['md5']]);
        $this->api->auth()->setHasher(\GroceryRestable\Auth\PasswordHasher::fromSettings($this->api->store()->settings()));
        self::assertSame('email_not_verified', $this->login('legacy', 'old secret')[1]['error']['code']);
    }

    public function testHooksRegisteredWhileBootingApply(): void
    {
        // Framework integrations define everything lazily (CodeIgniter's define() runs in booting()).
        $api = \GroceryRestable\Tests\Support\Fixture::reopen($this->api);
        $api->booting(static fn ($api) => $api->auth()->claims(static fn (array $user): array => ['name' => $user['username']]));

        $body = \GroceryRestable\Tests\Support\Fixture::call($api, 'POST', 'auth/login', ['identity' => 'alice', 'password' => 'correct horse'])[1];
        self::assertSame('alice', Jwt::decode($body['access_token'], self::SECRET)['name']);
        self::assertSame([1, true, 7], [$body['user']['id'], $body['user']['active'], $body['user']['tenant_id']], 'Native JSON types');
    }

    public function testEndpointsFollowTheSettings(): void
    {
        self::assertSame(['password' => ['identity' => ['email', 'username']], 'google' => false, 'strategy' => 'jwt'], $this->call('GET', 'auth')[1]);

        $this->api->store()->saveSettings(['auth_mode' => 'api_key']);
        self::assertSame('user_tokens_disabled', $this->login()[1]['error']['code']);

        $this->api->store()->saveSettings(['auth_mode' => 'any', 'auth_password_enabled' => false]);
        self::assertSame(404, $this->login()[0]);
        self::assertSame(404, $this->call('POST', 'auth/google', ['id_token' => 'x'])[0]);
        self::assertSame(405, $this->call('GET', 'auth/login')[0]);
    }

    public function testStaleTokensDoNotBlockSigningIn(): void
    {
        $expired = Jwt::encode(['sub' => '1', 'exp' => time() - 3600], self::SECRET);
        self::assertSame(200, $this->call('POST', 'auth/login', ['identity' => 'alice', 'password' => 'correct horse'], $this->bearer($expired))[0]);
        self::assertSame(401, $this->call('GET', 'auth/me', null, $this->bearer($expired))[0]);
        self::assertSame(401, $this->call('GET', 'auth/me', null, $this->bearer('grr_' . str_repeat('a', 64)))[0], 'Refresh tokens are not bearer tokens');
        $apiKey = $this->api->store()->createApiKey('k', 'authenticated');
        self::assertSame(401, $this->call('GET', 'auth/me', null, ['apikey' => $apiKey])[0]);
    }

    public function testOpenApiDescribesTheAuthEndpoints(): void
    {
        $spec = $this->api->openApi('http://localhost/api');
        self::assertArrayHasKey('/auth/login', $spec['paths']);
        self::assertArrayHasKey('/auth/refresh', $spec['paths']);
        self::assertArrayNotHasKey('/auth/google', $spec['paths']);
        self::assertStringContainsString('"security":[{}]', json_encode($spec['paths']['/auth/login']['post']));

        $this->api->store()->saveSettings(['auth_password_enabled' => false]);
        self::assertArrayNotHasKey('/auth/login', $this->api->openApi('http://localhost/api')['paths']);
    }

    public function testCustomRoleClaimPath(): void
    {
        $this->api->store()->saveSettings(['jwt_role_claim' => 'app_metadata.role']);
        $token = $this->login()[1]['access_token'];
        self::assertSame(['role' => 'editor'], Jwt::decode($token, self::SECRET)['app_metadata']);
        self::assertSame(200, $this->call('GET', 'offices', null, $this->bearer($token))[0]);
    }

    // ------------------------------------------------------------------ Google

    /**
     * @return array{0: \OpenSSLAsymmetricKey, 1: callable, 2: \ArrayObject}
     */
    private function google(array $settings = []): array
    {
        $config = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        $key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + (is_file($config) ? ['config' => $config] : []));
        if ($key === false) {
            self::markTestSkipped('OpenSSL cannot generate RSA keys here.');
        }
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $calls = new \ArrayObject();
        $tokens = new \ArrayObject();
        $http = static function (string $method, string $url, array $form = []) use ($rsa, $b64, $calls, $tokens): array {
            $calls[] = [$method, $url, $form];
            if ($url === \GroceryRestable\Auth\GoogleVerifier::JWKS_URL) {
                return ['status' => 200, 'headers' => ['cache-control' => 'public, max-age=20000'], 'body' => json_encode(['keys' => [
                    ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'k1', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])],
                ]])];
            }
            if ($form['code'] === 'good-code') {
                return ['status' => 200, 'headers' => [], 'body' => json_encode(['id_token' => $tokens['next']])];
            }

            return ['status' => 400, 'headers' => [], 'body' => '{"error":"invalid_grant"}'];
        };
        $this->api->auth()->setHttpClient($http);
        $this->api->store()->saveSettings($settings + ['auth_google_enabled' => true, 'auth_google_client_ids' => [self::CLIENT_ID]]);

        return [$key, $tokens, $calls];
    }

    private function idToken(\OpenSSLAsymmetricKey $key, array $claims, string $kid = 'k1'): string
    {
        $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $data = $b64(json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT'])) . '.' . $b64(json_encode($claims + [
            'iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'iat' => time(), 'exp' => time() + 3600,
            'sub' => '1100001', 'email' => 'alice@example.com', 'email_verified' => true, 'name' => 'Alice G',
        ]));
        openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);

        return $data . '.' . $b64($signature);
    }

    public function testGoogleSignInLinksAnExistingUserByVerifiedEmail(): void
    {
        [$key, , $calls] = $this->google();
        [$status, $body] = $this->call('POST', 'auth/google', ['credential' => $this->idToken($key, [])]);
        self::assertSame(200, $status, json_encode($body));
        self::assertSame('google', Jwt::decode($body['access_token'], self::SECRET)['provider']);
        self::assertSame('1', (string) $body['user']['id']);
        self::assertSame('1100001', $this->db()->query('SELECT google_id FROM users WHERE id = 1')->fetchColumn());

        // second sign-in: found by google_id, keys come from the cache
        self::assertSame(200, $this->call('POST', 'auth/google', ['id_token' => $this->idToken($key, ['email' => 'changed@example.com'])])[0]);
        self::assertCount(1, $calls);

        self::assertSame(['client_id' => self::CLIENT_ID], $this->call('GET', 'auth')[1]['google']);
    }

    public function testGoogleTokensAreVerified(): void
    {
        [$key] = $this->google();
        $other = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + (is_file(dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf') ? ['config' => dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf'] : []));
        $cases = [
            'wrong audience' => $this->idToken($key, ['aud' => 'someone-else.apps.googleusercontent.com']),
            'wrong issuer' => $this->idToken($key, ['iss' => 'https://evil.example.com']),
            'expired' => $this->idToken($key, ['exp' => time() - 3600]),
            'forged signature' => $this->idToken($other, []),
            'tampered' => preg_replace('/\.[^.]+\./', '.' . rtrim(strtr(base64_encode(json_encode(['sub' => 'x', 'aud' => self::CLIENT_ID])), '+/', '-_'), '=') . '.', $this->idToken($key, [])),
            'malformed' => 'not-a-token',
        ];
        foreach ($cases as $case => $token) {
            self::assertSame(401, $this->call('POST', 'auth/google', ['id_token' => $token])[0], $case);
        }

        // unverified email never links to an existing account
        [$status, $body] = $this->call('POST', 'auth/google', ['id_token' => $this->idToken($key, ['email_verified' => false])]);
        self::assertSame([403, 'account_not_found'], [$status, $body['error']['code']]);
    }

    public function testGoogleSignupAndHostedDomain(): void
    {
        [$key] = $this->google(['auth_google_signup' => true, 'auth_google_signup_fields' => ['name' => 'name', 'username' => 'given_name']]);
        [$status, $body] = $this->call('POST', 'auth/google', ['id_token' => $this->idToken($key, ['sub' => '2200002', 'email' => 'carol@example.com', 'given_name' => 'carol', 'name' => 'Carol C'])]);
        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['carol@example.com', 'carol', 'Carol C', '2200002'], [$body['user']['email'], $body['user']['username'], $body['user']['name'], $body['user']['google_id']]);
        self::assertSame('authenticated', Jwt::decode($body['access_token'], self::SECRET)['role']);
        // the generated password never verifies
        self::assertSame(401, $this->login('carol@example.com', (string) $this->db()->query("SELECT password FROM users WHERE email = 'carol@example.com'")->fetchColumn())[0]);

        self::assertSame(403, $this->call('POST', 'auth/google', ['id_token' => $this->idToken($key, ['sub' => '3', 'email' => 'dave@example.com', 'email_verified' => false])])[0]);

        $this->api->store()->saveSettings(['auth_google_hosted_domains' => ['example.com']]);
        self::assertSame(403, $this->call('POST', 'auth/google', ['id_token' => $this->idToken($key, ['sub' => '2200002'])])[0]);
        self::assertSame(200, $this->call('POST', 'auth/google', ['id_token' => $this->idToken($key, ['sub' => '2200002', 'hd' => 'example.com'])])[0]);
    }

    public function testGoogleAuthorizationCode(): void
    {
        [$key, $tokens, $calls] = $this->google(['auth_google_client_secret' => 'google-secret']);
        $tokens['next'] = $this->idToken($key, []);
        self::assertSame(200, $this->call('POST', 'auth/google', ['code' => 'good-code'])[0]);
        self::assertSame(['grant_type' => 'authorization_code', 'code' => 'good-code', 'client_id' => self::CLIENT_ID, 'client_secret' => 'google-secret', 'redirect_uri' => 'postmessage'], $calls[0][2]);

        self::assertSame(401, $this->call('POST', 'auth/google', ['code' => 'bad-code'])[0]);
        self::assertSame(422, $this->call('POST', 'auth/google', ['code' => 'good-code', 'redirect_uri' => 'javascript:alert(1)'])[0]);
        self::assertSame(422, $this->call('POST', 'auth/google', [])[0]);
    }

    public function testGoogleAccountAlreadyLinkedElsewhere(): void
    {
        [$key] = $this->google();
        $this->db()->exec("UPDATE users SET google_id = '999' WHERE id = 1");
        self::assertSame(409, $this->call('POST', 'auth/google', ['id_token' => $this->idToken($key, [])])[0]);
    }
}
