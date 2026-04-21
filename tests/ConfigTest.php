<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Test;

use Netresearch\WebConsole\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see Config::fromEnvironment()} wiring and the configured /
 * not-configured distinction that drives the UI's "must configure" page.
 */
#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    private const ENV_VARS = [
        'WEBCONSOLE_USER',
        'WEBCONSOLE_PASSWORD_HASH',
        'WEBCONSOLE_HOME_DIRECTORY',
        'WEBCONSOLE_NO_LOGIN',
    ];

    /**
     * Snapshot env state before each test so individual tests can putenv()
     * freely; tearDown restores it.
     *
     * @var array<string, string|false>
     */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (self::ENV_VARS as $name) {
            $this->originalEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            if ($value === false) {
                putenv($name);

                continue;
            }

            putenv($name . '=' . $value);
        }
    }

    /**
     * Without any environment variables, the config object must declare
     * itself "not configured" so the entry script renders the onboarding
     * page rather than a broken login form.
     */
    #[Test]
    public function emptyEnvironmentProducesUnconfiguredInstance(): void
    {
        $config = Config::fromEnvironment();

        self::assertFalse($config->isConfigured());
        self::assertSame([], $config->accounts);
    }

    /**
     * The common production setup: user + hash pair builds a single-account
     * map that the RPC server then consults.
     */
    #[Test]
    public function userAndHashBuildSingleAccountEntry(): void
    {
        putenv('WEBCONSOLE_USER=admin');
        putenv('WEBCONSOLE_PASSWORD_HASH=$argon2id$hash');

        $config = Config::fromEnvironment();

        self::assertSame(['admin' => '$argon2id$hash'], $config->accounts);
        self::assertTrue($config->isConfigured());
    }

    /**
     * A half-configured setup (only user, no hash) must not accidentally
     * enable passwordless login.
     */
    #[Test]
    public function userWithoutHashStaysUnconfigured(): void
    {
        putenv('WEBCONSOLE_USER=admin');

        self::assertFalse(Config::fromEnvironment()->isConfigured());
    }

    /**
     * Symmetric to {@see self::userWithoutHashStaysUnconfigured()}: a hash
     * without the matching username is useless.
     */
    #[Test]
    public function hashWithoutUserStaysUnconfigured(): void
    {
        putenv('WEBCONSOLE_PASSWORD_HASH=$argon2id$hash');

        self::assertFalse(Config::fromEnvironment()->isConfigured());
    }

    /**
     * WEBCONSOLE_NO_LOGIN maps through FILTER_VALIDATE_BOOL; anything PHP
     * treats as truthy there should disable authentication.
     */
    #[Test]
    #[DataProvider('truthyNoLoginValues')]
    public function noLoginFlagAcceptsTruthyStrings(string $value): void
    {
        putenv('WEBCONSOLE_NO_LOGIN=' . $value);

        $config = Config::fromEnvironment();

        self::assertTrue($config->noLogin);
        self::assertTrue($config->isConfigured(), 'noLogin mode counts as configured');
    }

    /** @return iterable<string, array{0: string}> */
    public static function truthyNoLoginValues(): iterable
    {
        yield 'literal true' => ['true'];
        yield 'number 1' => ['1'];
        yield 'uppercase TRUE' => ['TRUE'];
        yield 'word on' => ['on'];
        yield 'word yes' => ['yes'];
    }

    /**
     * The home directory configured by the operator survives the
     * environment round trip unchanged.
     */
    #[Test]
    public function homeDirectoryPassesThrough(): void
    {
        putenv('WEBCONSOLE_HOME_DIRECTORY=/var/www');

        self::assertSame('/var/www', Config::fromEnvironment()->homeDirectory);
    }
}
