<?php

declare(strict_types=1);

namespace GroceryRestable\Auth;

use GroceryRestable\Exception\ApiException;
use GroceryRestable\Http\Request;
use GroceryRestable\Resource\Resource;
use GroceryRestable\Store\ConfigStore;
use GroceryRestable\Support\Jwt;

/**
 * Resolves the caller from API keys / JWT bearer tokens / user sessions and checks permissions.
 *
 * Credentials are read from (in order):
 *   - "apikey" or "X-API-Key" header
 *   - "Authorization: Bearer <api key "gr_" | session "grs_" | jwt>"
 *   - "?apikey=" query parameter (only when allow_query_apikey is enabled)
 */
final class Authenticator
{
    public const MODES = ['none', 'api_key', 'jwt', 'any'];

    public function __construct(
        private readonly ConfigStore $store,
        private readonly string $jwtSecretOverride = '',
    ) {
    }

    public function authenticate(Request $request): AuthContext
    {
        $mode = (string) $this->store->setting('auth_mode', 'api_key');
        if ($mode === 'none') {
            return AuthContext::service('none');
        }

        $apiKey = $request->header('apikey') ?? $request->header('x-api-key');
        $bearer = null;
        $authorization = $request->header('authorization');
        if ($authorization !== null && preg_match('/^Bearer\s+(\S+)$/i', $authorization, $m)) {
            $bearer = $m[1];
        }
        if ($apiKey === null && $bearer === null && $this->store->setting('allow_query_apikey')) {
            $apiKey = $request->queryParam('apikey');
        }

        // Opaque user session token issued by /auth (session strategy).
        if ($bearer !== null && str_starts_with($bearer, 'grs_')) {
            if (! in_array($mode, ['jwt', 'any'], true)) {
                throw ApiException::unauthorized('User token authentication is disabled.');
            }

            return $this->fromSession($bearer);
        }

        // Supabase style: "apikey" identifies the project, "Authorization: Bearer <jwt>" the user.
        if ($bearer !== null && ! str_starts_with($bearer, 'gr_')) {
            if (! in_array($mode, ['jwt', 'any'], true)) {
                throw ApiException::unauthorized('JWT authentication is disabled.');
            }

            return $this->fromJwt($bearer);
        }

        $key = $bearer ?? $apiKey;
        if ($key !== null && $key !== '') {
            if (! in_array($mode, ['api_key', 'any'], true)) {
                throw ApiException::unauthorized('API key authentication is disabled.');
            }
            $row = $this->store->findApiKey($key);
            if ($row === null) {
                throw ApiException::unauthorized('Invalid API key.');
            }

            return new AuthContext((string) $row['role'], 'api_key', [], (int) $row['id']);
        }

        return AuthContext::anonymous();
    }

    /**
     * Whether $auth may perform $permission (select|insert|update|delete) on $resource.
     */
    public function allows(AuthContext $auth, Resource $resource, string $permission): bool
    {
        if ($auth->isService()) {
            return true;
        }

        $permissions = $resource->getPermissions() ?? (array) $this->store->setting('default_permissions', []);
        $granted = [...(array) ($permissions[$auth->role] ?? []), ...(array) ($permissions['*'] ?? [])];

        return in_array($permission, $granted, true);
    }

    public function authorize(AuthContext $auth, Resource $resource, string $permission): void
    {
        if ($this->allows($auth, $resource, $permission)) {
            return;
        }
        if ($auth->isAnonymous()) {
            throw ApiException::unauthorized('Authentication required to ' . $permission . ' "' . $resource->getName() . '".');
        }
        throw ApiException::forbidden('Role "' . $auth->role . '" is not allowed to ' . $permission . ' "' . $resource->getName() . '".');
    }

    /**
     * Verify a JWT signed with the configured secret.
     */
    public function fromJwt(string $token): AuthContext
    {
        $secret = $this->jwtSecretOverride !== '' ? $this->jwtSecretOverride : (string) $this->store->setting('jwt_secret', '');
        if ($secret === '') {
            throw ApiException::unauthorized('JWT authentication is not configured.');
        }

        $claims = Jwt::decode(
            $token,
            $secret,
            60,
            (string) $this->store->setting('jwt_audience', '') ?: null,
            (string) $this->store->setting('jwt_issuer', '') ?: null,
            (string) $this->store->setting('jwt_algorithm', 'HS256'),
            (bool) $this->store->setting('jwt_require_exp', true),
        );

        $roleClaim = (string) $this->store->setting('jwt_role_claim', 'role');
        $role = self::claimPath($claims, $roleClaim) ?? $this->store->setting('jwt_default_role', 'authenticated');
        if (! is_string($role) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $role)) {
            throw ApiException::unauthorized('Invalid role claim in token.');
        }
        // service_role bypasses every permission: only its API key grants it, unless explicitly allowed.
        if ($role === AuthContext::SERVICE_ROLE && ! $this->store->setting('jwt_allow_service_role', false)) {
            throw ApiException::unauthorized('Tokens cannot grant the service_role role.');
        }

        return new AuthContext($role, 'jwt', $claims);
    }

    /**
     * Resolve an opaque session token (issued by /auth with the "session" strategy).
     */
    public function fromSession(string $token): AuthContext
    {
        $session = $this->store->findAuthSession($token, 'session');
        if ($session === null) {
            throw ApiException::unauthorized('Invalid or expired session.');
        }
        $role = (string) $session['role'];
        if ($role === AuthContext::SERVICE_ROLE && ! $this->store->setting('jwt_allow_service_role', false)) {
            throw ApiException::unauthorized('Sessions cannot grant the service_role role.');
        }

        return new AuthContext($role, 'session', $session['claims'] + ['sub' => $session['user_id'], 'sid' => $session['id']]);
    }

    /**
     * Read a claim using dot notation ("app_metadata.role").
     *
     * @param array<string, mixed> $claims
     */
    private static function claimPath(array $claims, string $path): mixed
    {
        $value = $claims;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
