<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Rpc;

use RuntimeException;

/**
 * Protocol-level error inside the JSON-RPC dispatcher (malformed envelope,
 * unknown method, bad params).
 *
 * Business errors raised from inside the dispatched method bubble up as
 * their own exception types and are wrapped by the dispatcher into an
 * "Internal error" envelope; only those implementing
 * {@see SafeRpcException} expose their message. This class intentionally
 * does NOT implement that marker because its `$message` field is the
 * JSON-RPC standard name ("Parse error", "Invalid Request", …) and the
 * descriptive detail is carried in `$rpcData` instead.
 *
 * The `rpc`-prefixed property names (`rpcCode`, `rpcData`) avoid
 * shadowing the parent `Exception::$code` / `$message` while still being
 * obvious in the throw-site and `@property` contexts.
 */
final class JsonRpcException extends RuntimeException
{
    /**
     * @param int         $rpcCode JSON-RPC 2.0 error code (-32700 .. -32603 per spec, plus -32000..-32099 for implementation errors)
     * @param string      $message short human-readable error string used as the envelope `message`
     * @param string|null $rpcData optional detail surfaced as the envelope `data` field
     */
    public function __construct(
        public readonly int $rpcCode,
        string $message,
        public readonly ?string $rpcData = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Raised when the request body is not valid JSON.
     *
     * @param string|null $detail optional short description of the
     *                            underlying json_decode failure, surfaced
     *                            as the envelope `data` so operators can
     *                            tell "empty body" from "truncated JSON"
     */
    public static function parseError(?string $detail = null): self
    {
        return new self(-32700, 'Parse error', $detail);
    }

    /**
     * Raised when the envelope deviates from the JSON-RPC 2.0 shape
     * (wrong version, non-object root, malformed id, etc.).
     *
     * @param string $reason human-readable reason surfaced as the envelope `data`
     */
    public static function invalidRequest(string $reason): self
    {
        return new self(-32600, 'Invalid Request', $reason);
    }

    /**
     * Raised when the requested method does not exist on the target.
     * The method name itself is placed in `data` so clients can log it.
     *
     * @param string $method name the caller asked for
     */
    public static function methodNotFound(string $method): self
    {
        return new self(-32601, 'Method not found', $method);
    }

    /**
     * Raised when positional or named params cannot be mapped onto the
     * target method's signature (missing required param, wrong container
     * type, etc.).
     *
     * @param string $reason human-readable reason surfaced as the envelope `data`
     */
    public static function invalidParams(string $reason): self
    {
        return new self(-32602, 'Invalid params', $reason);
    }
}
