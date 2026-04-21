<?php

declare(strict_types=1);

namespace Netresearch\WebConsole;

/**
 * Runtime configuration for the web-console.
 *
 * All values come from environment variables. The legacy `$USER`/`$PASSWORD`
 * globals and `$PASSWORD_HASH_ALGORITHM` from upstream are no longer
 * supported -- deploy with `WEBCONSOLE_PASSWORD_HASH` (argon2id / bcrypt)
 * instead.
 */
final readonly class Config
{
    /**
     * @param bool                  $noLogin       when true, skip authentication entirely
     * @param array<string, string> $accounts      username => password_hash() output
     * @param string                $homeDirectory absolute path the terminal starts in after login; empty for cwd
     */
    public function __construct(
        public bool $noLogin,
        public array $accounts,
        public string $homeDirectory,
    ) {
    }

    /**
     * Build a config from the process environment variables.
     *
     * Recognised variables: WEBCONSOLE_USER, WEBCONSOLE_PASSWORD_HASH,
     * WEBCONSOLE_HOME_DIRECTORY, WEBCONSOLE_NO_LOGIN. Only non-empty values
     * populate the account map; missing credentials leave the instance
     * unconfigured.
     */
    public static function fromEnvironment(): self
    {
        $user = self::envString('WEBCONSOLE_USER');
        $hash = self::envString('WEBCONSOLE_PASSWORD_HASH');

        $accounts = [];

        if ($user !== '' && $hash !== '') {
            $accounts[$user] = $hash;
        }

        return new self(
            noLogin: filter_var(self::envString('WEBCONSOLE_NO_LOGIN'), FILTER_VALIDATE_BOOL),
            accounts: $accounts,
            homeDirectory: self::envString('WEBCONSOLE_HOME_DIRECTORY'),
        );
    }

    /**
     * Read an environment variable as a string, normalising the
     * `false`-on-missing return of {@see getenv()} to an empty string so
     * the caller can compare with `!== ''` without an extra guard.
     */
    private static function envString(string $name): string
    {
        $value = getenv($name);

        return $value === false ? '' : $value;
    }

    /**
     * Is the web-console ready to accept logins or serve unauthenticated
     * traffic? Returns false when no account is configured and login is
     * required, so callers render the "must be configured" page instead.
     */
    public function isConfigured(): bool
    {
        return $this->noLogin || $this->accounts !== [];
    }
}
