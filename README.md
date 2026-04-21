# About

> This is a maintained fork of [nickola/web-console](https://github.com/nickola/web-console)
> (upstream unmaintained since 2021). It carries security and PHP-compatibility
> fixes on top of the original code. Upstream is unchanged and referenced via
> `upstream` remote.

Web Console is a web-based application that allows to execute shell commands on a server directly from a browser (web-based shell).
The application is very light, does not require any database and can be installed and configured in about 3 minutes.

If you like Web Console, please consider an opportunity to support it in [any amount of Bitcoin](https://www.blockchain.com/btc/address/1NeDa2nXJLi5A8AN2CerBSSWD363vjdWaX).

![Web Console](https://raw.github.com/nickola/web-console/master/screenshots/main.png)

# Installation

Upload `webconsole.php` to the web server and open it in the browser.
Configure credentials either in the file itself (`$USER`, `$PASSWORD`) or via
environment variables (see below). Always put the script behind another
authentication layer (IP allowlist, HTTP basic auth, VPN) -- it hands out
shell access.

# Environment variables

The fork reads credentials from the container environment when the
corresponding variables are set. These win over values configured in the
PHP file.

| Variable                     | Purpose                                                                              |
| ---------------------------- | ------------------------------------------------------------------------------------ |
| `WEBCONSOLE_USER`            | Single-user account name.                                                            |
| `WEBCONSOLE_PASSWORD_HASH`   | `password_hash()` output (argon2id or bcrypt). Clears `$PASSWORD_HASH_ALGORITHM`.    |
| `WEBCONSOLE_HOME_DIRECTORY`  | Working directory after login (single-user form).                                    |

Generate a hash once and store it in the environment:

```
php -r 'echo password_hash("your-password", PASSWORD_ARGON2ID), "\n";'
```

## Netresearch fork changelog (v0.10.0)

Security and compatibility fixes on top of upstream v0.9.7:

 - `password_hash()` / `password_verify()` support via `verify_credential()`.
   Legacy md5/sha256/plaintext paths keep working but emit a one-shot
   `E_USER_DEPRECATED`.
 - `hash_equals()` for credential and session token comparison so strcmp()
   timing side channels are closed.
 - Tab completion does not crash on the second call anymore (upstream bug:
   `filter_pattern()` redeclared + `global $pattern` never resolved).
 - `cd` persists across stateless requests; `proc_open()` now receives the
   working directory per request instead of relying on `chdir()` on the PHP
   process (upstream #7, #33).
 - Build chain (Grunt/npm/Docker/Makefile, git submodules) removed; the
   bundled `webconsole.php` from upstream v0.9.7 is the single source. The
   source/bundle split is planned for v0.11.0.
 - Dev tools wired up: `composer ci:test` runs phplint, php-cs-fixer,
   rector and phpstan (level `max`, legacy baseline). See `composer.json`
   scripts.

### Known follow-ups

 - Session tokens are deterministic (`user:sha256(stored_credential)`) and
   have no server-side state. A stored-hash leak therefore grants token
   access. Fix tracked for the planned OOP refactor.
 - No rate limiting on login attempts. The deployment is expected to
   front the script with an IP allowlist or basic auth layer.

# About author

Web Console has been developed by [Nickolay Kovalev](http://nickola.ru). Also, various third-party components are used, see below.

# Used components

  - jQuery JavaScript Library: https://github.com/jquery/jquery
  - jQuery Terminal Emulator: https://github.com/jcubic/jquery.terminal
  - jQuery Mouse Wheel Plugin: https://github.com/brandonaaron/jquery-mousewheel
  - PHP JSON-RPC 2.0 Server/Client Implementation: https://github.com/sergeyfast/eazy-jsonrpc
  - Normalize.css: https://github.com/necolas/normalize.css

# URLs

 - GitHub: https://github.com/nickola/web-console
 - Website: http://nickola.ru/projects/web-console
 - Author: http://nickola.ru

# License

Web Console is licensed under [GNU LGPL Version 3](http://www.gnu.org/licenses/lgpl.html) license.
