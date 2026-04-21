<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Test;

use Netresearch\WebConsole\Authentication\CredentialVerifier;
use Netresearch\WebConsole\Command\CommandExecutor;
use Netresearch\WebConsole\Config;
use Netresearch\WebConsole\Rpc\RpcServer;
use Netresearch\WebConsole\WebConsole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the {@see WebConsole} facade. Verifies the
 * dispatch decisions (configured vs. not, GET vs. POST) via the rendered
 * HTML rather than by mocking `$_SERVER` -- the facade only reads the
 * request method, so the tests toggle that variable directly.
 */
#[CoversClass(WebConsole::class)]
#[UsesClass(Config::class)]
#[UsesClass(CredentialVerifier::class)]
#[UsesClass(CommandExecutor::class)]
#[UsesClass(RpcServer::class)]
final class WebConsoleTest extends TestCase
{
    private const ENV_VARS = [
        'WEBCONSOLE_USER',
        'WEBCONSOLE_PASSWORD_HASH',
        'WEBCONSOLE_HOME_DIRECTORY',
        'WEBCONSOLE_NO_LOGIN',
    ];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        foreach (self::ENV_VARS as $name) {
            $this->originalEnv[$name] = getenv($name);
            putenv($name);
        }

        $previous                    = $_SERVER['REQUEST_METHOD'] ?? null;
        $this->originalRequestMethod = is_string($previous) ? $previous : null;
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

        if ($this->originalRequestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }
    }

    /**
     * A GET to an unconfigured deployment must render the "must be
     * configured" onboarding page, not the empty terminal shell -- the
     * operator has to know setup is incomplete.
     */
    #[Test]
    public function unconfiguredGetRendersConfigurePage(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $html = $this->render();

        self::assertStringContainsString('must be configured', $html);
        self::assertStringContainsString('WEBCONSOLE_PASSWORD_HASH', $html);
    }

    /**
     * A GET with configured credentials renders the terminal shell
     * (recognisable by the `<body>` on an HTML class="no-js" document
     * that the jquery.terminal script bootstraps from).
     */
    #[Test]
    public function configuredGetRendersTerminalShell(): void
    {
        putenv('WEBCONSOLE_USER=admin');
        putenv('WEBCONSOLE_PASSWORD_HASH=' . password_hash('secret', PASSWORD_ARGON2ID));
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $html = $this->render();

        self::assertStringContainsString('class="no-js"', $html);
        self::assertStringNotContainsString('must be configured', $html);
    }

    /**
     * `noLogin` configuration counts as "configured" and renders the
     * terminal shell even without a password hash.
     */
    #[Test]
    public function noLoginGetRendersTerminalShell(): void
    {
        putenv('WEBCONSOLE_NO_LOGIN=true');
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $html = $this->render();

        self::assertStringContainsString('class="no-js"', $html);
    }

    /**
     * Capture the facade's output rather than letting it stream to stdout
     * during the test run.
     */
    private function render(): string
    {
        ob_start();
        WebConsole::fromEnvironment()->run();

        return (string) ob_get_clean();
    }
}
