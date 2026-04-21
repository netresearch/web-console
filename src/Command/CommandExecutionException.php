<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Command;

use Netresearch\WebConsole\Rpc\SafeRpcException;
use RuntimeException;

/**
 * Thrown when a shell command cannot be launched.
 *
 * This only fires at the process-spawn layer (proc_open failure); it does
 * not signal that a successfully launched command returned a non-zero exit
 * status -- those are returned to the caller as plain output, matching
 * upstream behaviour. The message echoes the client-supplied command,
 * which the client already knows, so it is safe to surface via the
 * {@see SafeRpcException} marker.
 */
final class CommandExecutionException extends RuntimeException implements SafeRpcException
{
    public static function processSpawnFailed(string $command): self
    {
        return new self(sprintf('Failed to spawn command: %s', $command));
    }
}
