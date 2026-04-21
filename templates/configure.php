<?php

declare(strict_types=1);

/**
 * Rendered by \Netresearch\WebConsole\WebConsole::renderPage() when no
 * credentials are configured. Locals provided by the caller:
 *
 * @var string $head shared <head> fragment (favicon, viewport, title)
 * @var string $css  pre-bundled CSS (normalize + terminal + project styles)
 */
?><!DOCTYPE html>
<html>
    <head>
        <?php echo $head; ?>
        <style type="text/css"><?php echo $css; ?></style>
    </head>
    <body>
        <div class="configure">
            <p>Web Console must be configured before use.</p>
            <ul>
                <li>Set <code>WEBCONSOLE_USER</code> and <code>WEBCONSOLE_PASSWORD_HASH</code>
                    in the environment (the hash is a <code>password_hash()</code> output,
                    e.g. argon2id).</li>
                <li>Optionally set <code>WEBCONSOLE_HOME_DIRECTORY</code> for the default
                    working directory.</li>
                <li>Reload this page.</li>
            </ul>
            <p>Full documentation:
                <a href="https://github.com/netresearch/web-console">github.com/netresearch/web-console</a></p>
        </div>
    </body>
</html>
