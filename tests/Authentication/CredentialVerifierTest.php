<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Test\Authentication;

use Netresearch\WebConsole\Authentication\CredentialVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see CredentialVerifier}.
 *
 * Covers the password_hash/password_verify happy path and the
 * token-generation / token-validation round trip, plus the hardening
 * checks (no legacy md5 fallback, no plaintext match, tamper-proof token).
 */
#[CoversClass(CredentialVerifier::class)]
final class CredentialVerifierTest extends TestCase
{
    private CredentialVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new CredentialVerifier();
    }

    /**
     * The main production hashing mode: argon2id.
     */
    #[Test]
    public function verifyAcceptsMatchingArgon2idHash(): void
    {
        $hash = password_hash('secret', PASSWORD_ARGON2ID);

        self::assertTrue($this->verifier->verify('secret', $hash));
    }

    /**
     * Bcrypt is the other value `password_hash()` may produce by default.
     * Both must pass since upstream deployments may have bcrypt hashes.
     */
    #[Test]
    public function verifyAcceptsMatchingBcryptHash(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);

        self::assertTrue($this->verifier->verify('secret', $hash));
    }

    #[Test]
    public function verifyRejectsWrongPassword(): void
    {
        $hash = password_hash('secret', PASSWORD_ARGON2ID);

        self::assertFalse($this->verifier->verify('wrong', $hash));
    }

    /**
     * An empty stored hash means no credential is configured for this
     * user; the verifier must short-circuit rather than call password_verify
     * with empty input.
     */
    #[Test]
    public function verifyRejectsEmptyStoredHash(): void
    {
        self::assertFalse($this->verifier->verify('secret', ''));
    }

    /**
     * Guard against an operator pasting a legacy md5 hash from the old
     * upstream `$PASSWORD_HASH_ALGORITHM = 'md5'` setup. We deliberately
     * dropped that path -- plaintext md5 must not authenticate anyone.
     */
    #[Test]
    public function verifyRejectsLegacyMd5Hash(): void
    {
        self::assertFalse($this->verifier->verify('secret', md5('secret')));
    }

    /**
     * Same guard for the even worse "store plaintext, compare plaintext"
     * mode the upstream settings.php allowed.
     */
    #[Test]
    public function verifyRejectsPlaintextCompare(): void
    {
        self::assertFalse($this->verifier->verify('secret', 'secret'));
    }

    /**
     * The token scheme derives the second component from the stored hash
     * via sha256, not from the password; therefore two calls with the same
     * inputs must produce the same token -- a client that reconnects with
     * the same credentials gets the same token back.
     */
    #[Test]
    public function tokenForIsStableForSameInput(): void
    {
        $hash = password_hash('secret', PASSWORD_ARGON2ID);

        self::assertSame(
            $this->verifier->tokenFor('admin', $hash),
            $this->verifier->tokenFor('admin', $hash),
        );
    }

    /**
     * The emitted token must never carry the plaintext secret; an attacker
     * sniffing the token should not obtain the login password.
     */
    #[Test]
    public function tokenForDoesNotExposePlaintext(): void
    {
        $hash  = password_hash('plaintext-secret', PASSWORD_ARGON2ID);
        $token = $this->verifier->tokenFor('admin', $hash);

        self::assertStringNotContainsString('plaintext-secret', $token);
        self::assertStringStartsWith('admin:', $token);
    }

    /**
     * Self-minted token round-trips through verifyToken() and resolves
     * back to the authenticated user.
     */
    #[Test]
    public function verifyTokenAcceptsSelfMintedToken(): void
    {
        $hash     = password_hash('secret', PASSWORD_ARGON2ID);
        $token    = $this->verifier->tokenFor('admin', $hash);
        $accounts = ['admin' => $hash];

        self::assertSame('admin', $this->verifier->verifyToken($token, $accounts));
    }

    /**
     * A token from one deployment must not authenticate against a
     * different account map.
     */
    #[Test]
    public function verifyTokenRejectsForeignAccount(): void
    {
        $hash     = password_hash('secret', PASSWORD_ARGON2ID);
        $token    = $this->verifier->tokenFor('admin', $hash);
        $accounts = ['other' => password_hash('other', PASSWORD_ARGON2ID)];

        self::assertNull($this->verifier->verifyToken($token, $accounts));
    }

    /**
     * A token whose hash part was replaced by an attacker-chosen value
     * must not validate.
     */
    #[Test]
    public function verifyTokenRejectsTamperedTokenHash(): void
    {
        $hash     = password_hash('secret', PASSWORD_ARGON2ID);
        $token    = 'admin:' . str_repeat('0', 64);
        $accounts = ['admin' => $hash];

        self::assertNull($this->verifier->verifyToken($token, $accounts));
    }

    /**
     * Malformed or empty tokens are rejected uniformly (null), not as
     * spectacularly as with exceptions.
     */
    #[Test]
    public function verifyTokenRejectsMalformedToken(): void
    {
        self::assertNull($this->verifier->verifyToken('no-colon-here', ['admin' => 'x']));
        self::assertNull($this->verifier->verifyToken('', ['admin' => 'x']));
        self::assertNull($this->verifier->verifyToken(':', ['admin' => 'x']));
    }

    #[Test]
    public function verifyTokenRejectsEmptyAccountsMap(): void
    {
        $hash  = password_hash('secret', PASSWORD_ARGON2ID);
        $token = $this->verifier->tokenFor('admin', $hash);

        self::assertNull($this->verifier->verifyToken($token, []));
    }
}
