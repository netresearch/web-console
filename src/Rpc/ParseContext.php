<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Rpc;

/**
 * Mutable scratch slot the parser fills as it walks the envelope so that
 * failures at any later stage (method-not-found, invalid-params, business
 * throwable) can still echo the caller's id back in the error response.
 *
 * This is intentionally not a value object — the caller creates an empty
 * instance per request, the parser writes to it, and {@see JsonRpcServer}
 * reads from it when building the response envelope.
 */
final class ParseContext
{
    /**
     * Request id extracted from the envelope. `null` means either the
     * caller sent a null id (a regular request per spec) or the parser
     * failed before it could look at the id field; the two are
     * distinguishable via the `isNotification` return from
     * {@see JsonRpcServer::parse()}.
     */
    public int|float|string|null $id = null;
}
