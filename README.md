# About

> Netresearch fork of [nickola/web-console](https://github.com/nickola/web-console)
> (upstream unmaintained since 2021). Modernised for PHP 8.2+, split into a
> proper OOP codebase, tested, and shipped as a regular composer package.

Web Console is a web-based PHP shell: a small HTTP endpoint that accepts
shell commands from the browser and returns their output through a
JSON-RPC API. Useful as an emergency console in container deployments
where you cannot SSH in.

![Web Console](https://raw.github.com/nickola/web-console/master/screenshots/main.png)

# Installation

Install via composer:

```
composer require netresearch/web-console
```

Then serve `vendor/netresearch/web-console/webconsole.php` from a URL that
is protected by an existing auth layer (IP allowlist, HTTP basic auth,
VPN, OAuth proxy, ...). The script gives shell access -- never expose it
directly.

Set the credentials via environment variables:

```
WEBCONSOLE_USER=admin
WEBCONSOLE_PASSWORD_HASH='$argon2id$v=19$m=65536,t=4,p=1$...'
WEBCONSOLE_HOME_DIRECTORY=/var/www
```

Generate the hash once and store it somewhere safer than the repo:

```
php -r 'echo password_hash("your-password", PASSWORD_ARGON2ID), "\n";'
```

# Environment variables

| Variable                     | Purpose                                                                                        |
| ---------------------------- | ---------------------------------------------------------------------------------------------- |
| `WEBCONSOLE_USER`            | Username for login                                                                             |
| `WEBCONSOLE_PASSWORD_HASH`   | `password_hash()` output (argon2id or bcrypt). Plaintext/legacy md5 hashes are rejected.       |
| `WEBCONSOLE_HOME_DIRECTORY`  | Working directory after login. Empty keeps the PHP process' cwd.                               |
| `WEBCONSOLE_NO_LOGIN`        | Set to a truthy value (`true`, `1`, `yes`, ...) to skip authentication entirely -- dangerous.  |

# Codebase layout

```
webconsole.php                        thin entry: autoload + WebConsole::fromEnvironment()->run()
src/
 ├── WebConsole.php                   application facade (dispatch POST -> RPC, GET -> HTML)
 ├── Config.php                       immutable runtime config, built from the env
 ├── Authentication/
 │    ├── CredentialVerifier.php      password_hash/verify + session-token helpers
 │    └── AuthenticationException.php raised on bad credentials
 ├── Command/
 │    ├── CommandExecutor.php         proc_open wrapper with explicit per-call cwd
 │    └── CommandExecutionException.php raised when a command cannot spawn
 └── Rpc/
      └── RpcServer.php               extends BaseJsonRpcServer, hosts login/cd/run/completion
templates/
 ├── configure.php                    rendered when no credentials are configured
 └── terminal.php                     rendered once the console is ready
resources/
 ├── css/webconsole.css               project-specific terminal styles
 ├── js/webconsole.js                 project-specific terminal bootstrapper
 └── html/head.html                   shared <head> fragment
tests/                                phpunit unit tests (mirrors src/ layout)
```

# Development

```
composer install
composer ci:test        # phplint + cgl + rector + phpstan + phpunit
composer ci:cgl         # auto-fix php-cs-fixer
composer ci:rector      # auto-apply Rector rules
composer ci:test:php:phpstan:baseline   # regenerate the baseline
composer ci:test:php:unit:coverage      # coverage report under .build/coverage/
```

## Netresearch fork changelog

### v0.11.0 (planned)

Breaking: the bundled-single-file deployment model is replaced by a
regular composer package. Upstream's `$USER`/`$PASSWORD`/`$PASSWORD_HASH_ALGORITHM`
globals no longer exist; credentials must be supplied through
`WEBCONSOLE_USER` / `WEBCONSOLE_PASSWORD_HASH`.

 - OOP refactor: Fassade (`WebConsole`), value object (`Config`),
   dedicated service classes for authentication / command execution /
   RPC, custom exception hierarchy.
 - Legacy md5/sha256/plaintext credential paths removed. Only
   `password_hash()` output is accepted (argon2id, bcrypt).
 - Third-party sources (jquery.terminal, eazy-jsonrpc, normalize.css)
   come in through composer package repositories pinned to upstream
   commit SHAs, not git submodules.
 - Grunt/npm build chain dropped. `webconsole.php` is a ~30-line entry
   script; the frontend assets are inlined at runtime.
 - phpunit unit tests for the service classes.

### v0.10.0

Security and compatibility fixes on top of upstream v0.9.7, still in the
bundled-single-file layout:

 - `password_hash()` support via `verify_credential()`.
 - `hash_equals()` for credential and session token comparison.
 - Tab completion does not crash on the second call anymore (upstream
   bug: `filter_pattern()` redeclared + `global $pattern` never resolved).
 - `cd` persists across stateless requests; `proc_open()` receives the
   working directory per request (upstream #7, #33).
 - `WEBCONSOLE_*` env-var overrides introduced.
 - Dev tools wired up: `composer ci:test`.

### Known follow-ups

 - Session tokens are deterministic (`user:sha256(stored_hash)`) and
   have no server-side state. A stored-hash leak therefore grants token
   access. Non-trivial to fix without breaking the stateless deploy.
 - No rate limiting on login attempts. The deployment is expected to
   front the script with an IP allowlist or basic auth layer.

# Used components

  - [jQuery Terminal Emulator](https://github.com/jcubic/jquery.terminal)
    (bundles jQuery 1.7.1 and the mousewheel plugin)
  - [PHP JSON-RPC 2.0 Server/Client Implementation](https://github.com/sergeyfast/eazy-jsonrpc)
  - [Normalize.css](https://github.com/necolas/normalize.css)

# License

Web Console is licensed under [GNU LGPL Version 3](http://www.gnu.org/licenses/lgpl.html).
Original work by [Nickolay Kovalev](http://nickola.ru); fork maintained by
[Netresearch DTT GmbH](https://www.netresearch.de/).
