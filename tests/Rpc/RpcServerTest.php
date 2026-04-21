<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Test\Rpc;

use Netresearch\WebConsole\Authentication\AuthenticationException;
use Netresearch\WebConsole\Authentication\CredentialVerifier;
use Netresearch\WebConsole\Command\CommandExecutor;
use Netresearch\WebConsole\Config;
use Netresearch\WebConsole\Rpc\RpcServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the RPC methods on {@see RpcServer}. Targets the methods
 * directly rather than going through {@see \Netresearch\WebConsole\Rpc\JsonRpcServer::execute()}
 * -- the dispatcher is covered by its own test class and the RPC target
 * must be verifiable in isolation.
 */
#[CoversClass(RpcServer::class)]
#[UsesClass(Config::class)]
#[UsesClass(CredentialVerifier::class)]
#[UsesClass(CommandExecutor::class)]
#[UsesClass(AuthenticationException::class)]
final class RpcServerTest extends TestCase
{
    private const PLAINTEXT = 'secret';

    private string $hash;

    private RpcServer $server;

    protected function setUp(): void
    {
        $this->hash   = password_hash(self::PLAINTEXT, PASSWORD_ARGON2ID);
        $this->server = new RpcServer(
            new Config(noLogin: false, accounts: ['admin' => $this->hash], homeDirectory: ''),
            new CredentialVerifier(),
            new CommandExecutor(),
        );
    }

    #[Test]
    public function loginWithCorrectCredentialsReturnsToken(): void
    {
        $result = $this->server->login('admin', self::PLAINTEXT);

        self::assertStringStartsWith('admin:', $result['token']);
        self::assertNotSame('', $result['environment']['path']);
    }

    /**
     * Security guardrail: wrong password must throw and the thrown
     * exception must be the domain-specific type (not a bare \Exception
     * or RuntimeException), so callers can catch it narrowly.
     */
    #[Test]
    public function loginWithWrongPasswordThrowsAuthenticationException(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Incorrect user or password');

        $this->server->login('admin', 'wrong');
    }

    #[Test]
    public function loginWithUnknownUserThrowsAuthenticationException(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->server->login('nobody', self::PLAINTEXT);
    }

    #[Test]
    public function loginWithEmptyCredentialsThrowsAuthenticationException(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->server->login('', '');
    }

    /**
     * Happy path: a valid token + a real command yields the command's
     * output (stdout). Uses `printf` so the test does not depend on a
     * shell builtin or on a trailing newline.
     */
    #[Test]
    public function runWithValidTokenExecutesCommand(): void
    {
        $token = $this->server->login('admin', self::PLAINTEXT)['token'];

        $result = $this->server->run($token, ['path' => sys_get_temp_dir()], 'printf hello');

        self::assertSame(['output' => 'hello'], $result);
    }

    /**
     * Empty command is a harmless no-op (matches upstream behaviour --
     * the client sometimes sends blank Enter presses).
     */
    #[Test]
    public function runWithEmptyCommandReturnsEmptyOutput(): void
    {
        $token = $this->server->login('admin', self::PLAINTEXT)['token'];

        $result = $this->server->run($token, [], '');

        self::assertSame(['output' => ''], $result);
    }

    #[Test]
    public function runWithInvalidTokenThrowsAuthenticationException(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->server->run('admin:tampered', [], 'printf hello');
    }

    /**
     * In no-login mode the server skips authentication entirely;
     * arbitrary (even empty) tokens must execute.
     */
    #[Test]
    public function runInNoLoginModeSkipsTokenCheck(): void
    {
        $openServer = new RpcServer(
            new Config(noLogin: true, accounts: [], homeDirectory: ''),
            new CredentialVerifier(),
            new CommandExecutor(),
        );

        $result = $openServer->run('', ['path' => sys_get_temp_dir()], 'printf ok');

        self::assertSame(['output' => 'ok'], $result);
    }

    /**
     * `cd` returns the refreshed environment so the client can render
     * the new prompt. Empty path falls back to the configured home
     * directory, which in the default fixture is cwd.
     */
    #[Test]
    public function cdToExistingDirectoryReturnsEnvironment(): void
    {
        $token = $this->server->login('admin', self::PLAINTEXT)['token'];
        $tmp   = sys_get_temp_dir();

        $result = $this->server->cd($token, ['path' => $tmp], $tmp);

        self::assertSame($tmp, $result['environment']['path'] ?? null);
    }

    #[Test]
    public function cdToMissingDirectoryReturnsErrorOutput(): void
    {
        $token = $this->server->login('admin', self::PLAINTEXT)['token'];

        $result = $this->server->cd($token, [], '/this/does/not/exist');

        self::assertArrayHasKey('output', $result);
        self::assertStringContainsString('No such directory', $result['output']);
    }
}
