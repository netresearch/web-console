<?php
// Web Console v<!-- @echo VERSION --> (<!-- @echo DATE -->)
//
// Author: Nickolay Kovalev (http://nickola.ru)
// GitHub: https://github.com/nickola/web-console
// URL: http://web-console.org

// Disable login (don't ask for credentials, be careful)
// Example: $NO_LOGIN = true;
$NO_LOGIN = false;

// Single-user credentials
// Example: $USER = 'user'; $PASSWORD = 'password';
$USER = 'dev';
$PASSWORD = 'dev';

// Multi-user credentials
// Example: $ACCOUNTS = array('user1' => 'password1', 'user2' => 'password2');
$ACCOUNTS = array();

// Password hash algorithm (password must be hashed)
// Example: $PASSWORD_HASH_ALGORITHM = 'md5';
//          $PASSWORD_HASH_ALGORITHM = 'sha256';
$PASSWORD_HASH_ALGORITHM = '';

// Home directory (multi-user mode supported)
// Example: $HOME_DIRECTORY = '/tmp';
//          $HOME_DIRECTORY = array('user1' => '/home/user1', 'user2' => '/home/user2');
$HOME_DIRECTORY = '';

// Environment-based overrides. When set, these variables take precedence over
// the values configured above. Intended for container deployments where
// credentials must not be committed to source.
//
//   WEBCONSOLE_USER           : overrides $USER
//   WEBCONSOLE_PASSWORD_HASH  : overrides $PASSWORD; expects a password_hash()
//                               output (argon2id or bcrypt). Clears
//                               $PASSWORD_HASH_ALGORITHM so the modern
//                               password_verify() path is used.
//   WEBCONSOLE_HOME_DIRECTORY : overrides $HOME_DIRECTORY (single-user form only)
$_envUser = getenv('WEBCONSOLE_USER');
if ($_envUser !== false && $_envUser !== '') {
    $USER = $_envUser;
}
$_envPasswordHash = getenv('WEBCONSOLE_PASSWORD_HASH');
if ($_envPasswordHash !== false && $_envPasswordHash !== '') {
    $PASSWORD                = $_envPasswordHash;
    $PASSWORD_HASH_ALGORITHM = '';
}
$_envHomeDirectory = getenv('WEBCONSOLE_HOME_DIRECTORY');
if ($_envHomeDirectory !== false && $_envHomeDirectory !== '') {
    $HOME_DIRECTORY = $_envHomeDirectory;
}
unset($_envUser, $_envPasswordHash, $_envHomeDirectory);

// Code below is automatically generated from different components
// For more information see: https://github.com/nickola/web-console
//
// Used components:
//   - jQuery JavaScript Library: https://github.com/jquery/jquery
//   - jQuery Terminal Emulator: https://github.com/jcubic/jquery.terminal
//   - jQuery Mouse Wheel Plugin: https://github.com/brandonaaron/jquery-mousewheel
//   - PHP JSON-RPC 2.0 Server/Client Implementation: https://github.com/sergeyfast/eazy-jsonrpc
//   - Normalize.css: https://github.com/necolas/normalize.css
?>
