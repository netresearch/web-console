<?php

declare(strict_types=1);

namespace Netresearch\WebConsole;

use Composer\InstalledVersions;
use Netresearch\WebConsole\Authentication\CredentialVerifier;
use Netresearch\WebConsole\Command\CommandExecutor;
use Netresearch\WebConsole\Rpc\RpcServer;

/**
 * Application facade.
 *
 * Wires the collaborators together (Config / CredentialVerifier /
 * CommandExecutor / RpcServer) and dispatches between the two things a
 * request can want: run the JSON-RPC endpoint (POST) or render the HTML
 * terminal shell (GET).
 *
 * Typical usage from the entry script:
 *
 *     \Netresearch\WebConsole\WebConsole::fromEnvironment()->run();
 */
final readonly class WebConsole
{
    /**
     * @param Config $config runtime configuration snapshot
     */
    public function __construct(
        private Config $config,
    ) {
    }

    /**
     * Build an instance whose config is read from process environment
     * variables. Most callers want this over the raw constructor.
     */
    public static function fromEnvironment(): self
    {
        return new self(Config::fromEnvironment());
    }

    /**
     * Dispatch the current HTTP request:
     *
     *   - POST: JSON-RPC endpoint
     *   - GET (any other method): HTML page (terminal or "configure me")
     *
     * Sends output directly; does not return a response object.
     */
    public function run(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->handleRpc();

            return;
        }

        $this->renderPage();
    }

    /**
     * Instantiate the RPC server and let eazy-jsonrpc dispatch the POST
     * body against its public methods.
     */
    private function handleRpc(): void
    {
        $server = new RpcServer(
            $this->config,
            new CredentialVerifier(),
            new CommandExecutor(),
        );
        $server->Execute();
    }

    /**
     * Render the HTML shell for the terminal. When no credentials are
     * configured, render the "Web Console must be configured" page instead
     * so operators know the deploy is incomplete.
     */
    private function renderPage(): void
    {
        $template = $this->config->isConfigured() ? 'terminal' : 'configure';
        $head     = $this->readHead();
        $css      = $this->bundleCss();
        $js       = $this->bundleJs();

        require __DIR__ . '/../templates/' . $template . '.php';
    }

    /**
     * Return the `<head>` fragment that both templates share.
     */
    private function readHead(): string
    {
        return trim((string) file_get_contents(__DIR__ . '/../resources/html/head.html'));
    }

    /**
     * Concatenate the CSS assets into a single string ready to be inlined
     * into a `<style>` block: normalize reset + jQuery-terminal theme +
     * project-specific styles.
     */
    private function bundleCss(): string
    {
        $normalize      = InstalledVersions::getInstallPath('necolas/normalize.css');
        $jqueryTerminal = InstalledVersions::getInstallPath('jcubic/jquery.terminal');

        return file_get_contents($normalize . '/normalize.css')
            . file_get_contents($jqueryTerminal . '/css/jquery.terminal-0.11.12.min.css')
            . file_get_contents(__DIR__ . '/../resources/css/webconsole.css');
    }

    /**
     * Concatenate the JS assets into a single string ready to be inlined
     * into a `<script>` block: jQuery + mousewheel + jQuery-terminal +
     * project-specific behaviour.
     */
    private function bundleJs(): string
    {
        $jqueryTerminal = InstalledVersions::getInstallPath('jcubic/jquery.terminal');

        return file_get_contents($jqueryTerminal . '/js/jquery-1.7.1.min.js')
            . file_get_contents($jqueryTerminal . '/js/jquery.mousewheel-min.js')
            . file_get_contents($jqueryTerminal . '/js/jquery.terminal-0.11.12.min.js')
            . file_get_contents(__DIR__ . '/../resources/js/webconsole.js');
    }
}
