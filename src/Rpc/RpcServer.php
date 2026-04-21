<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Rpc;

use Netresearch\WebConsole\Authentication\AuthenticationException;
use Netresearch\WebConsole\Authentication\CredentialVerifier;
use Netresearch\WebConsole\Command\CommandExecutor;
use Netresearch\WebConsole\Config;

/**
 * Business endpoints for the web-console terminal frontend.
 *
 * Plain object — every public method declared on this class itself is
 * exposed as an RPC method when the instance is handed to
 * {@see JsonRpcServer}. Keep non-RPC helpers private or move them into a
 * collaborator.
 *
 * The class is intentionally cwd-pure: the client holds the shell
 * environment (`path`, `hostname`), each request carries it along, and no
 * method mutates the PHP worker's global `chdir()` state. Commands are
 * dispatched into an explicit cwd through {@see CommandExecutor}; tab
 * completion resolves its scan root from the same client-held path.
 */
final readonly class RpcServer
{
    public function __construct(
        private Config $config,
        private CredentialVerifier $verifier,
        private CommandExecutor $executor,
    ) {
    }

    /**
     * RPC entry: authenticate a user, hand back a session token and the
     * initial environment (cwd + hostname) the terminal should display.
     *
     * Throws {@see AuthenticationException} on bad credentials so the
     * dispatcher can render the standard "Incorrect user or password"
     * error response.
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
                    'environment' => $this->environment((string) getcwd()),
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
     * RPC entry: "change" the working directory for the caller's session.
     *
     * Stateless: we resolve the target path (relative inputs are resolved
     * against the client-held `path`), verify it is a directory, and hand
     * the resolved absolute path back to the client. No `chdir()` on the
     * PHP worker.
     *
     * Empty path falls back to the configured home directory. Non-existing
     * targets return an `output` string the terminal prints verbatim
     * instead of raising an RPC error (matches upstream UX).
     *
     * @param array<string, mixed>|object $environment client-held environment snapshot
     *
     * @return array{output?: string, environment?: array{path: string, hostname: ?string}}
     */
    public function cd(string $token, array|object $environment, string $path): array
    {
        $this->authenticate($token);

        $currentPath = $this->currentPath($environment);
        $target      = trim($path);

        if ($target === '') {
            $target = $this->resolveHome();
        }

        if ($target === '') {
            return ['environment' => $this->environment($currentPath)];
        }

        $resolved = $this->resolveAgainst($currentPath, $target);

        if (!is_dir($resolved)) {
            return ['output' => 'cd: ' . $path . ': No such directory'];
        }

        $absolute = realpath($resolved);

        if ($absolute === false) {
            return ['output' => 'cd: ' . $path . ': Unable to resolve directory'];
        }

        return ['environment' => $this->environment($absolute)];
    }

    /**
     * RPC entry: produce tab-completion candidates for the directory
     * implied by the prefix (or the client-held cwd if the prefix is
     * empty / not a directory).
     *
     * @param array<string, mixed>|object $environment client-held environment snapshot
     * @param string                      $pattern     prefix the client is trying to complete
     *
     * @return array{completion: list<string>}
     */
    public function completion(string $token, array|object $environment, string $pattern): array
    {
        $this->authenticate($token);

        $cwd              = $this->currentPath($environment);
        $scan             = $this->resolveCompletionScan($pattern, $cwd);
        $scanPath         = $scan['scanPath'];
        $completionPrefix = $scan['prefix'];

        if ($scanPath === '') {
            return ['completion' => []];
        }

        $entries = @scandir($scanPath);

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

        return ['completion' => $completion];
    }

    /**
     * RPC entry: execute a shell command and return its output.
     *
     * The working directory is taken from the client-held environment so
     * a client-side `cd` persists across stateless HTTP requests. No
     * global `chdir()` mutation — the cwd is passed as the 4th arg to
     * `proc_open()` inside {@see CommandExecutor}.
     *
     * @param array<string, mixed>|object $environment client-held environment snapshot
     *
     * @return array{output: string}
     */
    public function run(string $token, array|object $environment, string $command): array
    {
        $this->authenticate($token);

        if ($command === '') {
            return ['output' => ''];
        }

        $cwd = $this->currentPath($environment);

        if ($cwd !== '' && !is_dir($cwd)) {
            return ['output' => sprintf('%s: No such directory (session cwd vanished)', $cwd)];
        }

        return ['output' => $this->executor->execute($command, $cwd !== '' ? $cwd : null)];
    }

    /**
     * Build a terminal environment snapshot anchored at `$path`.
     *
     * @return array{path: string, hostname: ?string}
     */
    private function environment(string $path): array
    {
        return [
            'path'     => $path,
            'hostname' => function_exists('gethostname') ? (string) gethostname() : null,
        ];
    }

    /**
     * Return the client-held current working directory verbatim.
     *
     * Deliberately does NOT fall back to the PHP worker's `getcwd()` when
     * the path is missing or has vanished since the client last saw it —
     * that would silently swap the user's terminal context for the pool
     * worker's root directory. Callers that need to dispatch a command or
     * scan a directory check `is_dir()` on the returned string themselves;
     * staleness surfaces as an honest "No such directory" error rather
     * than a confusing jump into `/`.
     *
     * Only truly-unset paths (no `path` key, empty string) fall back to
     * the worker's cwd so a fresh session without a prior `cd` still has
     * something to work against.
     *
     * @param array<string, mixed>|object $environment client-held environment snapshot
     */
    private function currentPath(array|object $environment): string
    {
        $env  = (array) $environment;
        $path = $env['path'] ?? null;

        if (is_string($path) && $path !== '') {
            return $path;
        }

        return (string) getcwd();
    }

    /**
     * Resolve `$target` against `$base` without touching the PHP worker's
     * cwd. Absolute paths are returned as-is; relative paths are joined.
     */
    private function resolveAgainst(string $base, string $target): string
    {
        if ($target === '') {
            return $base;
        }

        if (str_starts_with($target, '/')) {
            return $target;
        }

        if ($base === '') {
            return $target;
        }

        return rtrim($base, '/') . '/' . $target;
    }

    /**
     * Work out which directory the client wants listed for tab-completion
     * and the prefix that must be prepended to each match so the client
     * sees a consistent absolute-or-relative result.
     *
     * @return array{scanPath: string, prefix: string}
     */
    private function resolveCompletionScan(string $pattern, string $cwd): array
    {
        if ($pattern === '') {
            return ['scanPath' => $cwd, 'prefix' => ''];
        }

        $absolute = str_starts_with($pattern, '/') ? $pattern : $this->resolveAgainst($cwd, $pattern);

        if (is_dir($absolute)) {
            $prefix = str_ends_with($pattern, '/') ? $pattern : $pattern . '/';

            return ['scanPath' => $absolute, 'prefix' => $prefix];
        }

        $parent = dirname($pattern);

        if ($parent === '.' || $parent === '') {
            return ['scanPath' => $cwd, 'prefix' => ''];
        }

        $absoluteParent = str_starts_with($parent, '/') ? $parent : $this->resolveAgainst($cwd, $parent);

        if (!is_dir($absoluteParent)) {
            return ['scanPath' => '', 'prefix' => ''];
        }

        return ['scanPath' => $absoluteParent, 'prefix' => rtrim($parent, '/') . '/'];
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
