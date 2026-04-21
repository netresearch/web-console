<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Rpc;

use BaseJsonRpcServer;
use Netresearch\WebConsole\Authentication\AuthenticationException;
use Netresearch\WebConsole\Authentication\CredentialVerifier;
use Netresearch\WebConsole\Command\CommandExecutor;
use Netresearch\WebConsole\Config;

/**
 * JSON-RPC endpoint for the web-console terminal frontend.
 *
 * Extends eazy-jsonrpc's reflection-based dispatcher: every public method
 * becomes a callable RPC method. Keep non-RPC helpers private or move them
 * into a collaborator.
 */
final class RpcServer extends BaseJsonRpcServer
{
    public function __construct(
        private readonly Config $config,
        private readonly CredentialVerifier $verifier,
        private readonly CommandExecutor $executor,
    ) {
        // BaseJsonRpcServer::__construct() registers `$this` with the
        // dispatcher, which is how public methods become RPC endpoints.
        parent::__construct();
    }

    /**
     * RPC entry: authenticate a user, hand back a session token and the
     * initial environment (cwd + hostname) the terminal should display.
     *
     * Throws when the credentials do not match so eazy-jsonrpc can render
     * the standard "Incorrect user or password" error response.
     *
     * @return array{token: string, environment: array{path: string, hostname: ?string}, output?: string}
     */
    public function login(string $user, string $password): array
    {
        $user = trim($user);

        if ($user !== '' && $password !== '' && isset($this->config->accounts[$user])) {
            $stored = $this->config->accounts[$user];

            if ($this->verifier->verify($password, $stored)) {
                $result = [
                    'token'       => $this->verifier->tokenFor($user, $stored),
                    'environment' => $this->environment(),
                ];

                $home = $this->resolveHome();

                if ($home !== '') {
                    if (is_dir($home)) {
                        $result['environment']['path'] = $home;
                    } else {
                        $result['output'] = 'Home directory not found: ' . $home;
                    }
                }

                return $result;
            }
        }

        throw AuthenticationException::incorrectCredentials();
    }

    /**
     * RPC entry: change the working directory for the caller's session.
     *
     * Empty path falls back to the configured home directory. Non-existing
     * or non-cd-able targets return an `output` string the terminal prints
     * verbatim instead of raising an RPC error (matches upstream UX).
     *
     * @param array<string, mixed>|object $environment client-held environment snapshot
     *
     * @return array{output?: string, environment?: array{path: string, hostname: ?string}}
     */
    public function cd(string $token, array|object $environment, string $path): array
    {
        $this->authenticate($token);
        $this->applyEnvironment($environment);

        $path = trim($path);

        if ($path === '') {
            $path = $this->resolveHome();
        }

        if ($path !== '') {
            if (!is_dir($path)) {
                return ['output' => 'cd: ' . $path . ': No such directory'];
            }

            if (!@chdir($path)) {
                return ['output' => 'cd: ' . $path . ': Unable to change directory'];
            }
        }

        return ['environment' => $this->environment()];
    }

    /**
     * RPC entry: produce tab-completion candidates for the current
     * directory (or the directory implied by the pattern prefix).
     *
     * @param array<string, mixed>|object $environment client-held environment snapshot
     * @param string                      $pattern     prefix the client is trying to complete
     * @param string                      $command     full command line so far (unused; kept for upstream compatibility)
     *
     * @return array{completion: list<string>}
     */
    public function completion(string $token, array|object $environment, string $pattern, string $command): array
    {
        $this->authenticate($token);
        $this->applyEnvironment($environment);

        $scanPath         = '';
        $completionPrefix = '';
        $completion       = [];

        if ($pattern !== '') {
            if (!is_dir($pattern)) {
                $pattern = dirname($pattern);

                if ($pattern === '.') {
                    $pattern = '';
                }
            }

            if ($pattern !== '' && is_dir($pattern)) {
                $scanPath         = $pattern;
                $completionPrefix = str_ends_with($pattern, '/') ? $pattern : $pattern . '/';
            } elseif ($pattern === '') {
                $scanPath = (string) getcwd();
            }
        } else {
            $scanPath = (string) getcwd();
        }

        if ($scanPath !== '') {
            $entries = scandir($scanPath);

            if ($entries === false) {
                return ['completion' => []];
            }

            $completion = array_values(array_diff($entries, ['.', '..']));
            natsort($completion);
            $completion = array_values($completion);

            if ($completionPrefix !== '') {
                $completion = array_map(
                    static fn (string $value): string => $completionPrefix . $value,
                    $completion,
                );
            }

            if ($pattern !== '') {
                $completion = array_values(array_filter(
                    $completion,
                    static fn (string $value): bool => strncmp($pattern, $value, strlen($pattern)) === 0,
                ));
            }
        }

        return ['completion' => $completion];
    }

    /**
     * RPC entry: execute a shell command and return its output.
     *
     * The working directory is taken from the client-held environment so
     * a client-side `cd` persists across stateless HTTP requests.
     *
     * @param array<string, mixed>|object $environment client-held environment snapshot
     *
     * @return array{output: string}
     */
    public function run(string $token, array|object $environment, string $command): array
    {
        $this->authenticate($token);
        $this->applyEnvironment($environment);

        $cwd = null;
        $env = (array) $environment;

        if (isset($env['path']) && is_string($env['path']) && $env['path'] !== '') {
            $cwd = $env['path'];
        }

        return ['output' => $command !== '' ? $this->executor->execute($command, $cwd) : ''];
    }

    /**
     * Snapshot the current process state the terminal displays (cwd +
     * hostname). Used to seed a fresh login and to refresh after `cd`.
     *
     * @return array{path: string, hostname: ?string}
     */
    private function environment(): array
    {
        return [
            'path'     => (string) getcwd(),
            'hostname' => function_exists('gethostname') ? (string) gethostname() : null,
        ];
    }

    /**
     * Apply the client-held environment back onto the PHP process,
     * specifically chdir() to environment['path'] when it exists. No-op if
     * the path is missing or not a directory.
     *
     * Caveat: chdir() is global state on the PHP worker. Under PHP-FPM the
     * effect persists across requests handled by the same worker until the
     * next client-supplied `path` overwrites it. {@see CommandExecutor}
     * takes an explicit cwd to avoid this for command execution;
     * completion() still reads getcwd(). Keeping the behaviour until we
     * can thread cwd through the RPC layer as pure data.
     *
     * @param array<string, mixed>|object $environment client-held environment snapshot
     */
    private function applyEnvironment(array|object $environment): void
    {
        $env  = (array) $environment;
        $path = $env['path'] ?? null;

        if (is_string($path) && $path !== '' && is_dir($path)) {
            @chdir($path);
        }
    }

    /**
     * Validate a session token and return the resolved username, throwing
     * when the token does not match any configured account. Short-circuits
     * to an empty username when no_login mode is active.
     *
     * @throws AuthenticationException on invalid token
     */
    private function authenticate(string $token): string
    {
        if ($this->config->noLogin) {
            return '';
        }

        $user = $this->verifier->verifyToken($token, $this->config->accounts);

        if ($user === null) {
            throw AuthenticationException::incorrectCredentials();
        }

        return $user;
    }

    /**
     * Resolve the terminal's home directory, falling back to the PHP
     * process' current working directory when none is configured.
     */
    private function resolveHome(): string
    {
        return $this->config->homeDirectory !== '' ? $this->config->homeDirectory : (string) getcwd();
    }
}
