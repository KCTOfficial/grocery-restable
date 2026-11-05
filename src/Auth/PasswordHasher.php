<?php

declare(strict_types=1);

namespace GroceryRestable\Auth;

use GroceryRestable\Exception\ValidationException;

/**
 * Password hashing for user tables.
 *
 * New hashes use argon2id (default), argon2i or bcrypt through password_hash(), so they stay compatible with
 * CodeIgniter Shield, Laravel and any other PHP application using password_verify() on the same table.
 *
 * Existing tables with old unsalted hex digests (md5, sha1, sha256, sha512) can be migrated: enable those
 * algorithms as "legacy" and a successful login transparently rehashes the password with the current algorithm.
 * Any other scheme can be verified with a custom callback (see UserAuth::verifyLegacyPasswordUsing()).
 */
final class PasswordHasher
{
    /** Algorithms that can create new hashes. */
    public const ALGORITHMS = ['argon2id', 'argon2i', 'bcrypt'];

    /** Verify-only digests, for migrating old tables. Never used to create hashes. */
    public const LEGACY = ['md5' => 32, 'sha1' => 40, 'sha256' => 64, 'sha512' => 128];

    /** Bcrypt silently ignores everything after 72 bytes. */
    private const BCRYPT_MAX_BYTES = 72;

    /** Longer passwords are rejected before hashing (hashing cost / DoS guard). */
    public const MAX_BYTES = 4096;

    /** @var array<string, string> Dummy hash per algorithm + options, to equalize timing for unknown users. */
    private static array $dummies = [];

    private readonly string $algorithm;

    /** @var array<string, int> */
    private readonly array $options;

    /**
     * @param array{cost?: int, memory_cost?: int, time_cost?: int} $options
     * @param list<string> $legacy Enabled verify-only digests (keys of LEGACY).
     * @param (callable(string, string): ?bool)|null $legacyVerifier Custom check for other hash formats:
     *        return true / false when it recognizes the hash, null to let the built-in checks decide.
     */
    public function __construct(
        string $algorithm = 'argon2id',
        array $options = [],
        private readonly array $legacy = [],
        private readonly mixed $legacyVerifier = null,
    ) {
        if (! in_array($algorithm, self::ALGORITHMS, true)) {
            throw new \InvalidArgumentException('Unsupported password algorithm "' . $algorithm . '".');
        }
        // A server without argon2 support falls back to bcrypt instead of refusing every login.
        if ($algorithm !== 'bcrypt' && ! self::isAvailable($algorithm)) {
            $algorithm = 'bcrypt';
        }
        $this->algorithm = $algorithm;
        $this->options = $algorithm === 'bcrypt'
            ? ['cost' => self::clamp((int) ($options['cost'] ?? 12), 10, 15)]
            : [
                'memory_cost' => self::clamp((int) ($options['memory_cost'] ?? 65536), 19456, 1048576),
                'time_cost' => self::clamp((int) ($options['time_cost'] ?? 4), 2, 10),
                'threads' => 1,
            ];
    }

    /**
     * @param array<string, mixed> $settings Store settings (auth_password_*).
     * @param (callable(string, string): ?bool)|null $legacyVerifier
     */
    public static function fromSettings(array $settings, ?callable $legacyVerifier = null): self
    {
        return new self(
            (string) ($settings['auth_password_algorithm'] ?? 'argon2id'),
            [
                'cost' => (int) ($settings['auth_password_cost'] ?? 12),
                'memory_cost' => (int) ($settings['auth_password_memory'] ?? 65536),
                'time_cost' => (int) ($settings['auth_password_time'] ?? 4),
            ],
            array_values(array_intersect((array) ($settings['auth_password_legacy'] ?? []), array_keys(self::LEGACY))),
            $legacyVerifier,
        );
    }

    public static function isAvailable(string $algorithm): bool
    {
        return match ($algorithm) {
            'bcrypt' => true,
            'argon2id' => defined('PASSWORD_ARGON2ID'),
            'argon2i' => defined('PASSWORD_ARGON2I'),
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function availableAlgorithms(): array
    {
        return array_values(array_filter(self::ALGORITHMS, [self::class, 'isAvailable']));
    }

    /** The algorithm actually used for new hashes (after a possible fallback). */
    public function algorithm(): string
    {
        return $this->algorithm;
    }

    public function hash(string $password): string
    {
        self::assertLength($password);
        if ($this->algorithm === 'bcrypt' && strlen($password) > self::BCRYPT_MAX_BYTES) {
            throw ValidationException::forField('password', 'Passwords longer than 72 bytes are not supported by bcrypt.');
        }

        return password_hash($password, $this->phpAlgorithm(), $this->options);
    }

    public function verify(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '' || strlen($password) > self::MAX_BYTES) {
            return false;
        }
        if ($this->legacyVerifier !== null) {
            $custom = ($this->legacyVerifier)($password, $hash);
            if ($custom !== null) {
                return $custom;
            }
        }
        $digest = $this->legacyDigest($hash);
        if ($digest !== null) {
            return hash_equals(strtolower($hash), hash($digest, $password));
        }

        return password_verify($password, $hash);
    }

    /**
     * Whether $hash should be replaced after a successful login: legacy digests, another algorithm,
     * or weaker parameters than the current ones.
     */
    public function needsRehash(string $hash): bool
    {
        if ($this->legacyDigest($hash) !== null || (password_get_info($hash)['algo'] ?? null) === null) {
            return true;
        }

        return password_needs_rehash($hash, $this->phpAlgorithm(), $this->options);
    }

    /**
     * Spend the same time as a real verification (unknown users must not answer faster).
     */
    public function dummyVerify(string $password): void
    {
        $key = $this->algorithm . json_encode($this->options);
        self::$dummies[$key] ??= password_hash(bin2hex(random_bytes(16)), $this->phpAlgorithm(), $this->options);
        password_verify(substr($password, 0, self::MAX_BYTES), self::$dummies[$key]);
    }

    /**
     * A value that never verifies, for accounts without a password (e.g. created by Google sign-in)
     * in tables where the password column is NOT NULL.
     */
    public static function unusable(): string
    {
        return '!' . bin2hex(random_bytes(16));
    }

    public static function assertLength(string $password): void
    {
        if (strlen($password) > self::MAX_BYTES) {
            throw ValidationException::forField('password', 'The password is too long.');
        }
    }

    private function legacyDigest(string $hash): ?string
    {
        if ($this->legacy === [] || ! ctype_xdigit($hash)) {
            return null;
        }
        foreach ($this->legacy as $digest) {
            if (strlen($hash) === self::LEGACY[$digest]) {
                return $digest;
            }
        }

        return null;
    }

    private function phpAlgorithm(): string
    {
        return match ($this->algorithm) {
            'argon2id' => PASSWORD_ARGON2ID,
            'argon2i' => PASSWORD_ARGON2I,
            default => PASSWORD_BCRYPT,
        };
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
