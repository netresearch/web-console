<?php

declare(strict_types=1);

/**
 * Rendered by \Netresearch\WebConsole\WebConsole::run() when the host PHP
 * has `proc_open()` disabled via `disable_functions`. Without it the
 * console has nothing to dispatch commands with, so we bail out early
 * with an actionable hint instead of the default fatal error.
 *
 * No locals; this template is standalone.
 */
?><!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <title>Web Console — proc_open disabled</title>
        <style>
            body { font-family: sans-serif; max-width: 42em; margin: 4em auto; padding: 0 1em; color: #222; }
            h1 { color: #b00; }
            code { background: #f2f2f2; padding: 0.1em 0.3em; border-radius: 3px; }
            pre { background: #f2f2f2; padding: 0.8em; border-radius: 3px; overflow-x: auto; }
        </style>
    </head>
    <body>
        <h1>Web Console cannot run on this host</h1>
        <p>PHP's <code>proc_open()</code> function is disabled here. The console
           uses it to spawn shell commands; without it there is nothing to do.</p>
        <p>Your hosting provider probably listed <code>proc_open</code> under
           <code>disable_functions</code> in <code>php.ini</code>. Either remove it from
           that list or run the console on a host that allows process spawning
           (a dedicated VM or container is the usual answer).</p>
        <p>If you are the operator, check with:</p>
        <pre>php -i | grep disable_functions</pre>
        <p>See <a href="https://github.com/netresearch/web-console">github.com/netresearch/web-console</a>
           for documentation.</p>
    </body>
</html>
