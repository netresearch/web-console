<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Authentication;

use RuntimeException;

/**
 * Thrown when a login attempt fails or a session token does not resolve to
 * a known account.
 *
 * The message is intentionally user-generic so the RPC response does not
 * help an attacker distinguish between "unknown user" and "wrong password".
 */
final class AuthenticationException extends RuntimeException
{
    public static function incorrectCredentials(): self
    {
        return new self('Incorrect user or password');
    }
}
