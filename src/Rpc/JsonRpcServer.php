<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Rpc;

use JsonException;
use ReflectionMethod;
use ReflectionObject;
use stdClass;
use Throwable;

/**
 * Minimal JSON-RPC 2.0 dispatcher.
 *
 * Wraps an arbitrary target object and exposes the public methods that are
 * declared *on the target class itself* (not inherited / trait-provided,
 * not magic, not static) as RPC endpoints. Supports both positional
 * (array) and named (object) params. Single-request only — batch and SMD
 * are out of scope.
 *
 * Replaces the historical sergeyfast/eazy-jsonrpc dependency, which is
 * unmaintained since 2019 and not PHP 8+ clean.
 *
 * Information-disclosure policy: business exceptions that implement
 * {@see SafeRpcException} are surfaced verbatim (their message becomes the
 * `data` field of the error envelope); any other throwable is reported as
 * a bare "Internal error" with no `data`. That guarantee keeps TypeError
 * paths, framework-level exceptions and PHP warnings from leaking
 * absolute filesystem paths, SQL fragments or stack-trace residue to the
 * browser.
 */
final class JsonRpcServer
{
    private const VERSION = '2.0';

    /**
     * Code used for the "Internal error" envelope. Kept at `0` — outside
     * the spec's reserved -32xxx range — so it cannot be confused with a
     * real protocol error by downstream log analysis.
     */
    private const GENERIC_ERROR_CODE = 0;

    private const GENERIC_ERROR_MESSAGE = 'Internal error';

    /**
     * Reflection cache of the dispatchable methods on {@see self::$target},
     * keyed by method name for O(1) lookup during dispatch.
     *
     * @var array<string, ReflectionMethod>
     */
    private array $methods;

    /**
     * @param object $target the instance whose public methods are exposed as RPC endpoints
     */
    public function __construct(private readonly object $target)
    {
        $this->methods = $this->collectPublicMethods($target);
    }

    /**
     * Parse a JSON-RPC request from the raw HTTP body, dispatch it and
     * return the JSON response body (or an empty string for notifications).
     *
     * Throwables raised by the target method are caught and wrapped into
     * an "Internal error" envelope; only exceptions implementing
     * {@see SafeRpcException} carry their message through to the client.
     *
     * @param string $rawBody the raw HTTP POST body
     *
     * @return string the JSON-encoded response, or an empty string when the request was a notification
     */
    public function execute(string $rawBody): string
    {
        $context = new ParseContext();

        try {
            [$method, $params, $isNotification] = $this->parse($rawBody, $context);
            $args                               = $this->resolveArgs($method, $params);
            $result                             = $this->methods[$method]->invokeArgs($this->target, $args);

            if ($isNotification) {
                return '';
            }

            return $this->encode(['jsonrpc' => self::VERSION, 'result' => $result, 'id' => $context->id]);
        } catch (JsonRpcException $e) {
            return $this->encode($this->errorEnvelope($context->id, $e->rpcCode, $e->getMessage(), $e->rpcData));
        } catch (Throwable $e) {
            $data = $e instanceof SafeRpcException ? $e->getMessage() : null;

            return $this->encode($this->errorEnvelope($context->id, self::GENERIC_ERROR_CODE, self::GENERIC_ERROR_MESSAGE, $data));
        }
    }

    /**
     * Validate the envelope and pull method / params / isNotification out
     * of it. The request id is written to {@see $context} as early as
     * possible (before method/params validation) so later failures still
     * echo the caller's id back — only a malformed envelope leaves
     * `$context->id` at its initial `null`.
     *
     * @param string $rawBody the raw HTTP POST body
     *
     * @return array{0: string, 1: array<int|string, mixed>|stdClass|null, 2: bool} tuple of (method, params, isNotification)
     *
     * @throws JsonRpcException when the envelope is not a valid JSON-RPC 2.0 request
     */
    private function parse(string $rawBody, ParseContext $context): array
    {
        try {
            $decoded = json_decode($rawBody, associative: false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw JsonRpcException::parseError($rawBody === '' ? 'empty request body' : $e->getMessage());
        }

        if (!$decoded instanceof stdClass) {
            throw JsonRpcException::invalidRequest('request must be a JSON object');
        }

        $isNotification = !property_exists($decoded, 'id');

        if (!$isNotification) {
            $context->id = $this->coerceId($decoded->id ?? null);
        }

        if (!property_exists($decoded, 'jsonrpc') || $decoded->jsonrpc !== self::VERSION) {
            throw JsonRpcException::invalidRequest('jsonrpc version must be "2.0"');
        }

        if (!property_exists($decoded, 'method') || !is_string($decoded->method) || $decoded->method === '') {
            throw JsonRpcException::invalidRequest('method must be a non-empty string');
        }

        $method = $decoded->method;

        if (!isset($this->methods[$method])) {
            throw JsonRpcException::methodNotFound($method);
        }

        $params = property_exists($decoded, 'params') ? $this->coerceParams($decoded->params) : null;

        return [$method, $params, $isNotification];
    }

    /**
     * Map JSON-RPC `params` onto the target method's parameter list.
     * Positional arrays fill arguments in order (extras dropped, defaults
     * fill trailing gaps); objects match by parameter name.
     *
     * @param string                                 $methodName name of the method to dispatch; MUST exist in {@see self::$methods}
     * @param array<int|string, mixed>|stdClass|null $params     the caller-supplied params
     *
     * @return list<mixed> arguments ready to be passed to `invokeArgs()`
     *
     * @throws JsonRpcException when a required parameter is missing
     */
    private function resolveArgs(string $methodName, array|stdClass|null $params): array
    {
        $method = $this->methods[$methodName];

        if ($params === null) {
            $params = [];
        }

        if (is_array($params)) {
            $remaining = array_values($params);
            $args      = [];

            foreach ($method->getParameters() as $param) {
                if ($remaining !== []) {
                    $args[] = array_shift($remaining);

                    continue;
                }

                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();

                    continue;
                }

                throw JsonRpcException::invalidParams(sprintf('missing required parameter #%d (%s)', count($args) + 1, $param->getName()));
            }

            return $args;
        }

        $args = [];

        foreach ($method->getParameters() as $param) {
            $name = $param->getName();

            if (property_exists($params, $name)) {
                $args[] = $params->{$name};

                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();

                continue;
            }

            throw JsonRpcException::invalidParams(sprintf('missing required parameter "%s"', $name));
        }

        return $args;
    }

    /**
     * Normalise the `params` field against the JSON-RPC 2.0 spec: it must
     * be array, object or null. Anything else is a protocol error.
     *
     * @return array<int|string, mixed>|stdClass|null
     */
    private function coerceParams(mixed $value): array|stdClass|null
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof stdClass) {
            return $value;
        }

        throw JsonRpcException::invalidParams('params must be array, object or null');
    }

    /**
     * Normalise the `id` field. The JSON-RPC 2.0 spec permits string,
     * number or null; float ids are SHOULD-NOT but the server SHOULD echo
     * them back verbatim, so we accept them here.
     *
     * @throws JsonRpcException when the value is of any other type
     */
    private function coerceId(mixed $value): int|float|string|null
    {
        if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        throw JsonRpcException::invalidRequest('id must be string, number or null');
    }

    /**
     * Build an error response envelope. The `data` member is only included
     * when a non-empty string is supplied.
     *
     * @param int|float|string|null $id      the request id, echoed back verbatim
     * @param int                   $code    JSON-RPC error code (either from the 2.0 spec or 0 for generic internal errors)
     * @param string                $message short human-readable error string
     * @param string|null           $data    optional machine- or human-readable detail
     *
     * @return array{jsonrpc: string, error: array{code: int, message: string, data?: string}, id: int|float|string|null}
     */
    private function errorEnvelope(int|float|string|null $id, int $code, string $message, ?string $data): array
    {
        $error = ['code' => $code, 'message' => $message];

        if ($data !== null && $data !== '') {
            $error['data'] = $data;
        }

        return ['jsonrpc' => self::VERSION, 'error' => $error, 'id' => $id];
    }

    /**
     * JSON-encode a response payload. Unescaped slashes keep URLs in
     * `output` readable on the wire.
     *
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Collect the dispatchable methods on the target. Only methods
     * declared on the target's own class (not inherited, not trait-
     * provided) are exposed, so adding `LoggerAwareTrait` or extending a
     * framework base class cannot silently grow the RPC surface. Skips
     * magic methods (`__`-prefixed), static methods, and the ctor/dtor.
     *
     * @return array<string, ReflectionMethod>
     */
    private function collectPublicMethods(object $target): array
    {
        $targetClass = $target::class;
        $methods     = [];

        foreach ((new ReflectionObject($target))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $targetClass) {
                continue;
            }

            if ($method->isStatic()) {
                continue;
            }

            if ($method->isConstructor()) {
                continue;
            }

            if ($method->isDestructor()) {
                continue;
            }

            $name = $method->getName();

            if (str_starts_with($name, '__')) {
                continue;
            }

            $methods[$name] = $method;
        }

        return $methods;
    }
}
