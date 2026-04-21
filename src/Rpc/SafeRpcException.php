<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Rpc;

/**
 * Marker interface for exceptions whose `getMessage()` is safe to surface
 * to the RPC caller as the `data` field of the error envelope.
 *
 * {@see JsonRpcServer::execute()} forwards the message verbatim for any
 * `Throwable` implementing this interface (AuthenticationException,
 * CommandExecutionException, …); everything else is reported as a bare
 * "Internal error" with no `data`, so absolute filesystem paths, SQL
 * fragments, DB credentials or stack-trace residue from framework-level
 * TypeErrors do not leak to the browser.
 */
interface SafeRpcException
{
}
