# About

> Netresearch fork of [nickola/web-console](https://github.com/nickola/web-console)
> (upstream unmaintained since 2021). Modernised for PHP 8.2+, split into a
> proper OOP codebase, tested, and shipped as a regular composer package.

Web Console is a web-based PHP shell: a small HTTP endpoint that accepts
shell commands from the browser and returns their output through a
JSON-RPC API. Useful as an emergency console in container deployments
where you cannot SSH in.

**This is not a web-based SSH client.** Commands are executed one-shot
via `proc_open()`, not through a PTY. See [Out of scope](#out-of-scope)
below for what this means in practice.

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

# Out of scope

The console executes commands one at a time through PHP's `proc_open()`:
each request starts a fresh subprocess, the output is captured in full,
and the HTTP response ends when the command does. That is enough for
the typical *emergency-diagnosis* use case (`ls`, `cat`, `tail -n 200`,
`grep`, `composer show`, `kubectl get pods`, …) but it **intentionally
does not** support:

 - **Interactive editors** (`vim`, `nano`, `emacs`) — no PTY, no
   keystroke streaming. Upstream issues
   [#4](https://github.com/nickola/web-console/issues/4),
   [#44](https://github.com/nickola/web-console/issues/44).
 - **Long-running / streaming commands** (`tail -f`, `ping`,
   `top`, `htop`, `rsync` with progress) — the HTTP request buffers
   until the command exits; infinite commands simply hang the browser.
   Upstream issues
   [#3](https://github.com/nickola/web-console/issues/3),
   [#24](https://github.com/nickola/web-console/issues/24),
   [#5](https://github.com/nickola/web-console/issues/5).
 - **Multi-line / heredoc commands** (`cat <<EOF …`) — a single RPC
   call carries exactly one command string. Upstream issue
   [#62](https://github.com/nickola/web-console/issues/62).
 - **Interactive SSH** / host hopping / pubkey login. Use a real SSH
   client. Upstream issues
   [#15](https://github.com/nickola/web-console/issues/15),
   [#38](https://github.com/nickola/web-console/issues/38),
   [#39](https://github.com/nickola/web-console/issues/39),
   [#45](https://github.com/nickola/web-console/issues/45),
   [#48](https://github.com/nickola/web-console/issues/48),
   [#53](https://github.com/nickola/web-console/issues/53).
 - **Aliases / login-shell behaviour** (`.bashrc`, `.profile`) —
   `proc_open()` spawns a non-interactive, non-login shell, so user
   aliases and PATH tweaks are not sourced. Upstream issues
   [#35](https://github.com/nickola/web-console/issues/35),
   [#36](https://github.com/nickola/web-console/issues/36),
   [#41](https://github.com/nickola/web-console/issues/41).
 - **Command blocklists / restricted shells / custom shells**. If you
   need that, pick the user the web server runs as carefully (chroot,
   unprivileged account) — the filter belongs at the OS level, not in
   PHP. Upstream issues
   [#9](https://github.com/nickola/web-console/issues/9),
   [#20](https://github.com/nickola/web-console/issues/20).

If you need any of the above, you want a PTY-backed terminal like
[ttyd](https://github.com/tsl0922/ttyd) (C),
[wetty](https://github.com/butlerx/wetty) (Node), or
[xterm.js](https://xtermjs.org/) plus a WebSocket backend. Those are
different products with different deploy footprints.

# Fixed versus upstream

Highlights of what the Netresearch fork repairs compared to upstream
`v0.9.7`. Issue and PR numbers link to `nickola/web-console`:

| Upstream issue | Status |
|---|---|
| [#7](https://github.com/nickola/web-console/issues/7), [#33](https://github.com/nickola/web-console/issues/33), [#41](https://github.com/nickola/web-console/issues/41), [PR #28](https://github.com/nickola/web-console/pull/28) — `cd` does not persist across requests | Fixed in `v0.10.0`. `CommandExecutor::execute()` passes an explicit per-request `cwd` to `proc_open()` so the terminal's client-held environment survives between HTTP requests. |
| [#57](https://github.com/nickola/web-console/issues/57), [PR #13](https://github.com/nickola/web-console/pull/13) — `strcmp()` on already-hashed password silently never matches | Fixed in `v0.10.0`. Replaced by `CredentialVerifier::verify()` (`password_hash` + `hash_equals`). Legacy md5/sha256/plaintext paths removed in `v0.11.0`. |
| Timing attack on credential / token compare | Fixed in `v0.10.0`. All credential comparisons go through `hash_equals()`; `password_verify()` is already constant-time. |
| Tab completion crashes on the second call and never actually filters | Fixed in `v0.10.0`. `filter_pattern()` was declared inside `completion()` but registered in the global namespace (`Cannot redeclare filter_pattern()` on the second call), and its `global $pattern` never referenced the method parameter — so the prefix filter was a no-op. Replaced by a closure. |
| [#2](https://github.com/nickola/web-console/issues/2), [#16](https://github.com/nickola/web-console/issues/16), [#37](https://github.com/nickola/web-console/issues/37), [#42](https://github.com/nickola/web-console/issues/42), [#43](https://github.com/nickola/web-console/issues/43), [#46](https://github.com/nickola/web-console/issues/46), [#51](https://github.com/nickola/web-console/issues/51) — mobile keyboard / selection / Chrome-Android issues | Resolved in `v0.12.0` by the `jquery.terminal` 0.11 → 2.45 upgrade. jquery.terminal's maintainer explicitly confirmed these in comments: "fixed upstream, update the library." |
| [#23](https://github.com/nickola/web-console/issues/23) — `proc_open()` disabled via `disable_functions` yields a useless fatal | Fixed in `v0.12.0`. The facade checks `function_exists('proc_open')` on every request and renders an actionable 500 page pointing at `disable_functions`. |

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

### v0.12.0

Frontend dependency refresh and a small host-compatibility fix. No
breaking changes on the PHP API; a deployment that lives through
composer keeps working unchanged.

 - `jquery.terminal` **0.11 → 2.45.2**. Mobile keyboard, selection and
   Chrome-Android bugs that upstream [nickola/web-console](https://github.com/nickola/web-console)
   acknowledged but never released are fixed by this upgrade alone (see
   *Fixed versus upstream* above).
 - `jQuery` **1.7 → 3.7.1**. Drops the last IE-era codepaths; required
   by jquery.terminal 2.x.
 - `normalize.css` **3 → 8.0.1**. Follows the jquery.terminal stylesheet
   it pairs with.
 - `sergeyfast/eazy-jsonrpc` **→ 3.0.3** from Packagist; the private
   inline-package entry that v0.11.0 carried is gone.
 - `jquery.mousewheel` dependency removed. jquery.terminal 2.x uses the
   native `wheel` event.
 - `webconsole.js` rewritten against jquery.terminal 2.x (new
   `split_command`, `wheel` instead of `mousewheel`, `beforeunload`).
 - `WebConsole::run()` now returns a 500 page with actionable guidance
   when `proc_open()` is disabled via `disable_functions` (upstream #23).
 - New integration tests for the facade and the RPC server (43 tests /
   60 assertions total, phpstan level max with an empty baseline).

### v0.11.0

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

  - [jQuery Terminal Emulator](https://github.com/jcubic/jquery.terminal) 2.45.2
  - [jQuery](https://github.com/jquery/jquery-dist) 3.7.1
  - [PHP JSON-RPC 2.0 Server/Client Implementation](https://github.com/sergeyfast/eazy-jsonrpc) 3.0.3
  - [Normalize.css](https://github.com/necolas/normalize.css) 8.0.1

# License

Web Console is licensed under [GNU LGPL Version 3](http://www.gnu.org/licenses/lgpl.html).
Original work by [Nickolay Kovalev](http://nickola.ru); fork maintained by
[Netresearch DTT GmbH](https://www.netresearch.de/).
