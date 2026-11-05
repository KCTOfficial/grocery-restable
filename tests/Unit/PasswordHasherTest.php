<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Unit;

use GroceryRestable\Auth\GoogleVerifier;
use GroceryRestable\Auth\PasswordHasher;
use GroceryRestable\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testAlgorithmsAndParameters(): void
    {
        foreach (PasswordHasher::availableAlgorithms() as $algorithm) {
            $hasher = new PasswordHasher($algorithm, ['cost' => 10, 'memory_cost' => 19456, 'time_cost' => 2]);
            $hash = $hasher->hash('s3cret pass');
            self::assertTrue($hasher->verify('s3cret pass', $hash), $algorithm);
            self::assertFalse($hasher->verify('wrong', $hash), $algorithm);
            self::assertFalse($hasher->needsRehash($hash), $algorithm);
            self::assertTrue(password_verify('s3cret pass', $hash), 'Standard format, usable by any PHP application');
        }

        $bcrypt = (new PasswordHasher('bcrypt', ['cost' => 10]))->hash('pass');
        self::assertStringStartsWith('$2y$10$', $bcrypt);
        self::assertTrue((new PasswordHasher('bcrypt', ['cost' => 12]))->needsRehash($bcrypt), 'Stronger cost');
        self::assertTrue((new PasswordHasher('argon2id'))->needsRehash($bcrypt), 'Other algorithm');

        // parameters are clamped to safe ranges
        self::assertStringStartsWith('$2y$10$', (new PasswordHasher('bcrypt', ['cost' => 4]))->hash('pass'));
        self::assertStringContainsString('m=19456,t=2', (new PasswordHasher('argon2id', ['memory_cost' => 8, 'time_cost' => 1]))->hash('pass'));
    }

    public function testLegacyDigestsAreOptIn(): void
    {
        $md5 = md5('old');
        self::assertFalse((new PasswordHasher('bcrypt'))->verify('old', $md5));

        $hasher = new PasswordHasher('bcrypt', ['cost' => 10], ['md5', 'sha256']);
        self::assertTrue($hasher->verify('old', $md5));
        self::assertTrue($hasher->verify('old', strtoupper($md5)));
        self::assertTrue($hasher->verify('old', hash('sha256', 'old')));
        self::assertFalse($hasher->verify('old', sha1('old')), 'sha1 not enabled');
        self::assertFalse($hasher->verify('wrong', $md5));
        self::assertTrue($hasher->needsRehash($md5));

        $custom = new PasswordHasher('bcrypt', [], [], static fn (string $p, string $h): ?bool => str_starts_with($h, 'salted:') ? $h === 'salted:' . sha1('pepper' . $p) : null);
        self::assertTrue($custom->verify('x', 'salted:' . sha1('pepperx')));
        self::assertFalse($custom->verify('y', 'salted:' . sha1('pepperx')));
        self::assertTrue($custom->verify('x', $custom->hash('x')), 'Falls back to the built-in checks');
    }

    public function testEdgeCases(): void
    {
        $hasher = new PasswordHasher('bcrypt', ['cost' => 10]);
        self::assertFalse($hasher->verify('x', null));
        self::assertFalse($hasher->verify('x', ''));
        $unusable = PasswordHasher::unusable();
        self::assertFalse($hasher->verify($unusable, $unusable));

        $this->expectException(ValidationException::class);
        $hasher->hash(str_repeat('a', 73)); // bcrypt would silently ignore the end
    }

    public function testSettings(): void
    {
        $hasher = PasswordHasher::fromSettings(['auth_password_algorithm' => 'bcrypt', 'auth_password_cost' => 11, 'auth_password_legacy' => ['md5', 'rot13']]);
        self::assertSame('bcrypt', $hasher->algorithm());
        self::assertStringStartsWith('$2y$11$', $hasher->hash('x'));
        self::assertTrue($hasher->verify('x', md5('x')));
    }

    public function testJwkToPem(): void
    {
        $config = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        $key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + (is_file($config) ? ['config' => $config] : []));
        if ($key === false) {
            self::markTestSkipped('OpenSSL cannot generate RSA keys here.');
        }
        $details = openssl_pkey_get_details($key);
        $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $pem = GoogleVerifier::rsaPem($b64($details['rsa']['n']), $b64($details['rsa']['e']));
        self::assertSame(trim($details['key']), trim((string) $pem));
    }
}
