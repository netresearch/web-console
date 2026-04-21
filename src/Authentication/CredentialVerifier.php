<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Authentication;

/**
 * Verifies login attempts and session tokens.
 *
 * Stored credentials must be password_hash() output (argon2id or bcrypt).
 * The legacy md5/sha256/plaintext paths from upstream are intentionally not
 * supported in this fork -- rotate old setups to a proper hash.
 */
final class CredentialVerifier
{
    /**
     * Check a plaintext password against a stored password_hash() output.
     *
     * Rejects empty hashes and non-recognised hash formats outright; only
     * bcrypt/argon2 output makes it to password_verify(). Returns false
     * (not throw) so the caller controls the response.
     */
    public function verify(string $input, string $storedHash): bool
    {
        if ($storedHash === '' || password_get_info($storedHash)['algo'] === null) {
            return false;
        }

        return password_verify($input, $storedHash);
    }

    /**
     * Build the opaque session token a client keeps between requests.
     *
     * Derived from the stored hash, not from the submitted password, so it is
     * stable regardless of the hashing algorithm and does not expose the
     * plaintext.
     */
    public function tokenFor(string $user, string $storedHash): string
    {
        return $user . ':' . hash('sha256', $storedHash);
    }

    /**
     * Validate an opaque session token against the configured account map.
     *
     * Matches the format produced by {@see self::tokenFor()} (`user:sha256`)
     * and uses hash_equals() for a timing-safe comparison. Returns the
     * authenticated username or null on any mismatch.
     *
     * @param array<string, string> $accounts username => password_hash() output
     */
    public function verifyToken(string $token, array $accounts): ?string
    {
        $parts = explode(':', trim($token), 2);

        if (count($parts) !== 2) {
            return null;
        }

        $user      = trim($parts[0]);
        $tokenHash = trim($parts[1]);

        if ($user === '' || $tokenHash === '' || !isset($accounts[$user]) || $accounts[$user] === '') {
            return null;
        }

        return hash_equals(hash('sha256', $accounts[$user]), $tokenHash) ? $user : null;
    }
}
