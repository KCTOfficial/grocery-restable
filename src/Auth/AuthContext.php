<?php

declare(strict_types=1);

namespace GroceryRestable\Auth;

/**
 * Who is calling the API.
 */
final class AuthContext
{
    /** Role that bypasses every permission check (like Supabase's service_role). */
    public const SERVICE_ROLE = 'service_role';
    public const ANON = 'anon';

    /**
     * @param string $method anonymous | api_key | jwt | session | internal
     * @param array<string, mixed> $claims JWT / session claims (empty for API keys)
     */
    public function __construct(
        public readonly string $role,
        public readonly string $method = 'anonymous',
        public readonly array $claims = [],
        public readonly ?int $apiKeyId = null,
    ) {
    }

    public static function anonymous(): self
    {
        return new self(self::ANON);
    }

    /**
     * Full access, used when auth is disabled and by the admin console.
     */
    public static function service(string $method = 'internal'): self
    {
        return new self(self::SERVICE_ROLE, $method);
    }

    public function isService(): bool
    {
        return $this->role === self::SERVICE_ROLE;
    }

    public function isAnonymous(): bool
    {
        return $this->role === self::ANON;
    }

    public function claim(string $name, mixed $default = null): mixed
    {
        return $this->claims[$name] ?? $default;
    }

    /**
     * Subject of the JWT or session ("sub" claim), typically the user id.
     */
    public function userId(): mixed
    {
        return $this->claims['sub'] ?? null;
    }
}
