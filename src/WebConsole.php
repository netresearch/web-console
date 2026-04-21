<?php

declare(strict_types=1);

namespace Netresearch\WebConsole;

use Composer\InstalledVersions;
use Netresearch\WebConsole\Authentication\CredentialVerifier;
use Netresearch\WebConsole\Command\CommandExecutor;
use Netresearch\WebConsole\Rpc\JsonRpcServer;
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
     * Bails out early when the environment is missing `proc_open()` (some
     * shared-hosting providers disable it via `disable_functions`).
     *
     * Sends output directly; does not return a response object.
     */
    public function run(): void
    {
        if (!function_exists('proc_open')) {
            $this->renderProcOpenMissing();

            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->handleRpc();

            return;
        }

        $this->renderPage();
    }

    /**
     * HTTP adapter: read the POST body, hand it to the transport-agnostic
     * {@see JsonRpcServer::execute()} and echo the response with the
     * correct Content-Type.
     */
    private function handleRpc(): void
    {
        $endpoints = new RpcServer(
            $this->config,
            new CredentialVerifier(),
            new CommandExecutor(),
        );
        $dispatcher = new JsonRpcServer($endpoints);

        $response = $dispatcher->execute((string) file_get_contents('php://input'));

        if (!headers_sent()) {
            header('Content-Type: application/json');
        }

        echo $response;
    }

    /**
     * Render a 500 page explaining that `proc_open()` is disabled on the
     * host. The script cannot do anything useful without it, so we bail
     * out with an actionable hint instead of the default fatal error.
     */
    private function renderProcOpenMissing(): void
    {
        http_response_code(500);
        require __DIR__ . '/../templates/proc-open-missing.php';
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
     * into a `<style>` block: normalize reset + jquery.terminal theme +
     * project-specific styles.
     */
    private function bundleCss(): string
    {
        $normalize      = InstalledVersions::getInstallPath('necolas/normalize.css');
        $jqueryTerminal = InstalledVersions::getInstallPath('jcubic/jquery.terminal');

        return file_get_contents($normalize . '/normalize.css')
            . file_get_contents($jqueryTerminal . '/css/jquery.terminal.min.css')
            . file_get_contents(__DIR__ . '/../resources/css/webconsole.css');
    }

    /**
     * Concatenate the JS assets into a single string ready to be inlined
     * into a `<script>` block: jQuery + jquery.terminal + project-specific
     * behaviour.
     *
     * jquery.mousewheel is no longer needed (jquery.terminal 2.x uses the
     * native `wheel` event).
     */
    private function bundleJs(): string
    {
        $jquery         = InstalledVersions::getInstallPath('jquery/jquery-dist');
        $jqueryTerminal = InstalledVersions::getInstallPath('jcubic/jquery.terminal');

        return file_get_contents($jquery . '/dist/jquery.min.js')
            . file_get_contents($jqueryTerminal . '/js/jquery.terminal.min.js')
            . file_get_contents(__DIR__ . '/../resources/js/webconsole.js');
    }
}
