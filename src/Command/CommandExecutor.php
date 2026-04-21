<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Command;

/**
 * Executes shell commands in a stateless, per-request manner.
 *
 * Passes the working directory as the fourth proc_open() argument so
 * concurrent requests do not race through the PHP process' global CWD
 * and a client-side `cd` persists across stateless HTTP requests
 * (upstream issues #7 and #33).
 */
final class CommandExecutor
{
    /**
     * Run a shell command and return its combined stdout/stderr output.
     *
     * The trailing newline that most commands emit is stripped so the RPC
     * client does not have to.
     *
     * @param string      $command raw shell command line to execute
     * @param string|null $cwd     explicit working directory; null or a
     *                             missing path falls back to the PHP
     *                             process' current working directory
     *
     * @throws CommandExecutionException when proc_open() cannot spawn the command
     */
    public function execute(string $command, ?string $cwd = null): string
    {
        $cwd = ($cwd !== null && $cwd !== '' && is_dir($cwd)) ? $cwd : null;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command . ' 2>&1', $descriptors, $pipes, $cwd);

        if (!is_resource($process)) {
            throw CommandExecutionException::processSpawnFailed($command);
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($output !== '' && str_ends_with($output, "\n")) {
            return substr($output, 0, -1);
        }

        return $output;
    }
}
