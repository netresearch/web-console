<?php

declare(strict_types=1);

namespace Netresearch\WebConsole\Test\Rpc;

use Netresearch\WebConsole\Rpc\JsonRpcException;
use Netresearch\WebConsole\Rpc\JsonRpcServer;
use Netresearch\WebConsole\Rpc\SafeRpcException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the JSON-RPC 2.0 dispatcher. Covers envelope validation,
 * positional and named parameter dispatch, notifications, and how the
 * dispatcher wraps exceptions thrown by the target object.
 */
#[CoversClass(JsonRpcServer::class)]
#[UsesClass(JsonRpcException::class)]
#[UsesClass(SafeRpcException::class)]
final class JsonRpcServerTest extends TestCase
{
    private JsonRpcServer $server;

    protected function setUp(): void
    {
        $this->server = new JsonRpcServer(new JsonRpcServerTestTarget());
    }

    #[Test]
    public function positionalParamsDispatchInOrder(): void
    {
        $response = $this->call('greet', ['world'], 1);

        self::assertSame('hello world', $this->successResult($response));
        self::assertSame(1, $response['id']);
    }

    #[Test]
    public function namedParamsDispatchByParameterName(): void
    {
        $response = $this->call('greet', ['name' => 'rso'], 2);

        self::assertSame('hello rso', $this->successResult($response));
    }

    /**
     * Params can be omitted entirely when the target method has defaults
     * (`greet(string $name = 'world')`).
     */
    #[Test]
    public function missingParamsUseMethodDefaults(): void
    {
        $response = $this->call('greet', null, 3);

        self::assertSame('hello world', $this->successResult($response));
    }

    /**
     * Extra positional arguments are silently dropped -- this is the
     * tolerant behaviour the existing client depends on (the JS layer
     * sometimes sends extra context args upstream methods don't consume).
     */
    #[Test]
    public function extraPositionalParamsAreDropped(): void
    {
        $response = $this->call('greet', ['world', 'ignored'], 4);

        self::assertSame('hello world', $this->successResult($response));
    }

    /**
     * JSON-RPC 2.0 says a request without an `id` member is a notification
     * and MUST NOT produce a response body.
     */
    #[Test]
    public function notificationsReturnNothing(): void
    {
        $raw = (string) json_encode(['jsonrpc' => '2.0', 'method' => 'greet', 'params' => ['world']]);

        self::assertSame('', $this->server->execute($raw));
    }

    /**
     * `id: null` is a regular (non-notification) request per the spec.
     * The server must respond and echo the null id back verbatim.
     */
    #[Test]
    public function nullIdIsRegularRequest(): void
    {
        $raw      = (string) json_encode(['jsonrpc' => '2.0', 'method' => 'greet', 'params' => [], 'id' => null]);
        $response = $this->decode($this->server->execute($raw));

        self::assertArrayHasKey('result', $response);
        self::assertNull($response['id']);
    }

    /**
     * `id: 0` is falsy in PHP but a perfectly valid JSON-RPC id. Clients
     * that auto-increment from 0 must round-trip it correctly.
     */
    #[Test]
    public function zeroIdRoundTrips(): void
    {
        $response = $this->call('greet', ['world'], 0);

        self::assertSame(0, $response['id']);
    }

    /**
     * String ids are explicitly allowed by the spec; UUID-like correlation
     * ids are the common case in the wild.
     */
    #[Test]
    public function stringIdRoundTrips(): void
    {
        $raw      = (string) json_encode(['jsonrpc' => '2.0', 'method' => 'greet', 'params' => [], 'id' => 'req-xyz']);
        $response = $this->decode($this->server->execute($raw));

        self::assertSame('req-xyz', $response['id']);
    }

    /**
     * Float ids are SHOULD-NOT per the spec, but the server SHOULD still
     * respond with the original id value rather than refuse the call.
     */
    #[Test]
    public function floatIdRoundTrips(): void
    {
        $raw      = (string) json_encode(['jsonrpc' => '2.0', 'method' => 'greet', 'params' => [], 'id' => 1.5]);
        $response = $this->decode($this->server->execute($raw));

        self::assertSame(1.5, $response['id']);
    }

    #[Test]
    public function invalidJsonReturnsParseError(): void
    {
        $response = $this->decode($this->server->execute('{not json'));

        self::assertSame(-32700, $this->errorCode($response));
        self::assertNull($response['id']);
    }

    #[Test]
    public function wrongJsonrpcVersionReturnsInvalidRequest(): void
    {
        $response = $this->call('greet', [], 5, overrides: ['jsonrpc' => '1.0']);

        self::assertSame(-32600, $this->errorCode($response));
    }

    #[Test]
    public function unknownMethodReturnsMethodNotFound(): void
    {
        $response = $this->call('doesNotExist', [], 6);

        self::assertSame(-32601, $this->errorCode($response));
        self::assertSame('doesNotExist', $this->errorData($response));
    }

    /**
     * Regression: id was extracted too late, so method-not-found /
     * invalid-params / business-throwable responses all echoed `id: null`
     * instead of the caller's id. The parser now writes the id to a
     * shared context before validating the method.
     */
    #[Test]
    public function errorResponsesPreserveCallerId(): void
    {
        $methodNotFound = $this->call('doesNotExist', [], 123);
        self::assertSame(123, $methodNotFound['id']);

        $invalidParams = $this->call('concat', ['only-one'], 456);
        self::assertSame(456, $invalidParams['id']);

        $unsafeThrowable = $this->call('boom', [], 789);
        self::assertSame(789, $unsafeThrowable['id']);

        $safeThrowable = $this->call('safeBoom', [], 101);
        self::assertSame(101, $safeThrowable['id']);
    }

    /**
     * Private helpers on the target must not be exposed as RPC methods --
     * only `IS_PUBLIC` reflection methods count.
     */
    #[Test]
    public function privateMethodsAreNotExposed(): void
    {
        $response = $this->call('secret', [], 7);

        self::assertSame(-32601, $this->errorCode($response));
    }

    /**
     * Magic methods (leading `__`) must also stay hidden -- otherwise
     * callers could reach `__construct` / `__destruct` / `__call`.
     */
    #[Test]
    public function magicMethodsAreNotExposed(): void
    {
        $response = $this->call('__construct', [], 8);

        self::assertSame(-32601, $this->errorCode($response));
    }

    #[Test]
    public function missingRequiredPositionalParamReturnsInvalidParams(): void
    {
        $response = $this->call('concat', ['only-one'], 9);

        self::assertSame(-32602, $this->errorCode($response));
    }

    #[Test]
    public function missingRequiredNamedParamReturnsInvalidParams(): void
    {
        $response = $this->call('concat', ['first' => 'only-one'], 10);

        self::assertSame(-32602, $this->errorCode($response));
    }

    /**
     * Invalid `params` types (anything other than array/object/null) are
     * protocol errors -- the JSON-RPC 2.0 spec is explicit about this.
     */
    #[Test]
    #[DataProvider('invalidParamsValueProvider')]
    public function invalidParamsTypeReturnsInvalidParams(mixed $paramsValue): void
    {
        $raw      = (string) json_encode(['jsonrpc' => '2.0', 'method' => 'greet', 'params' => $paramsValue, 'id' => 11]);
        $response = $this->decode($this->server->execute($raw));

        self::assertSame(-32602, $this->errorCode($response));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidParamsValueProvider(): iterable
    {
        yield 'string' => ['not valid'];
        yield 'int' => [42];
        yield 'bool' => [true];
    }

    /**
     * Regular throwables — a TypeError, a framework exception, anything
     * the dispatcher did not explicitly declare as safe — become the
     * generic "Internal error" envelope WITHOUT the original message.
     * This is the anti-information-disclosure guarantee: filesystem
     * paths, SQL fragments, stack-trace residue never reach the browser.
     */
    #[Test]
    public function unsafeThrowableHidesExceptionMessage(): void
    {
        $response = $this->call('boom', [], 12);

        self::assertSame(0, $this->errorCode($response));
        self::assertArrayHasKey('error', $response);
        self::assertSame('Internal error', $response['error']['message']);
        self::assertArrayNotHasKey('data', $response['error']);
    }

    /**
     * Domain exceptions that implement {@see SafeRpcException} are the
     * only ones whose message is forwarded as `data`. That is the hook
     * AuthenticationException / CommandExecutionException use to surface
     * "Incorrect user or password" or "cd: /tmp: No such directory" to
     * the terminal.
     */
    #[Test]
    public function safeRpcExceptionSurfacesMessage(): void
    {
        $response = $this->call('safeBoom', [], 13);

        self::assertSame(0, $this->errorCode($response));
        self::assertSame('Internal error', $response['error']['message'] ?? null);
        self::assertSame('told-you-so', $this->errorData($response));
    }

    /**
     * Inherited public methods must NOT be exposed — otherwise adding a
     * framework base class or a trait (e.g. `LoggerAwareTrait`'s
     * `setLogger`) to the target silently grows the RPC surface.
     */
    #[Test]
    public function inheritedMethodsAreNotExposed(): void
    {
        $server   = new JsonRpcServer(new JsonRpcServerInheritingChild());
        $raw      = (string) json_encode(['jsonrpc' => '2.0', 'method' => 'inheritedHello', 'params' => [], 'id' => 14]);
        $response = $this->decode($server->execute($raw));

        self::assertSame(-32601, $this->errorCode($response));
    }

    /**
     * Sanity check the positive case on the inheritance fixture: methods
     * declared on the target's own class are still reachable even when
     * the class extends a parent with public methods.
     */
    #[Test]
    public function ownMethodOfInheritingTargetIsExposed(): void
    {
        $server   = new JsonRpcServer(new JsonRpcServerInheritingChild());
        $response = $this->decode($server->execute((string) json_encode([
            'jsonrpc' => '2.0',
            'method'  => 'ownHello',
            'params'  => [],
            'id'      => 15,
        ])));

        self::assertSame('own', $this->successResult($response));
    }

    /**
     * Build a request envelope, send it through the dispatcher and decode
     * the response into a JSON-RPC 2.0 response shape.
     *
     * @param array<int|string, mixed>|null $params
     * @param array<string, mixed>          $overrides
     *
     * @return array{jsonrpc: string, id: int|float|string|null, result?: mixed, error?: array{code: int, message: string, data?: string}}
     */
    private function call(string $method, ?array $params, int $id, array $overrides = []): array
    {
        $envelope = ['jsonrpc' => '2.0', 'method' => $method, 'id' => $id];

        if ($params !== null) {
            $envelope['params'] = $params;
        }

        $envelope = array_merge($envelope, $overrides);

        return $this->decode($this->server->execute((string) json_encode($envelope)));
    }

    /**
     * Decode a raw JSON-RPC response body into its declared shape. The
     * test only ever asks the dispatcher; anything out of shape is a bug
     * in the server, not in the test.
     *
     * @return array{jsonrpc: string, id: int|float|string|null, result?: mixed, error?: array{code: int, message: string, data?: string}}
     */
    private function decode(string $json): array
    {
        /** @var array{jsonrpc: string, id: int|float|string|null, result?: mixed, error?: array{code: int, message: string, data?: string}} $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Narrow a response to its success envelope and return the result.
     * Fails the enclosing test when the response was an error envelope.
     *
     * @param array{jsonrpc: string, id: int|float|string|null, result?: mixed, error?: array{code: int, message: string, data?: string}} $response
     */
    private function successResult(array $response): mixed
    {
        self::assertArrayNotHasKey('error', $response, 'expected a success envelope, got: ' . json_encode($response));
        self::assertArrayHasKey('result', $response);

        return $response['result'];
    }

    /**
     * Narrow a response to its error envelope and return the code. Fails
     * the enclosing test when the response was a success envelope.
     *
     * @param array{jsonrpc: string, id: int|float|string|null, result?: mixed, error?: array{code: int, message: string, data?: string}} $response
     */
    private function errorCode(array $response): int
    {
        self::assertArrayHasKey('error', $response, 'expected an error envelope, got: ' . json_encode($response));

        return $response['error']['code'];
    }

    /**
     * Narrow a response to its error envelope and return the `data` field.
     * Fails the enclosing test when either is missing.
     *
     * @param array{jsonrpc: string, id: int|float|string|null, result?: mixed, error?: array{code: int, message: string, data?: string}} $response
     */
    private function errorData(array $response): string
    {
        self::assertArrayHasKey('error', $response, 'expected an error envelope, got: ' . json_encode($response));
        self::assertArrayHasKey('data', $response['error'], 'error envelope is missing its `data` field');

        return $response['error']['data'];
    }
}

/**
 * Fixture target. Exposes a handful of methods so the dispatcher has
 * something to reflect on: a defaulted positional / named method, a
 * two-required-param method, throwing methods (regular + SafeRpcException),
 * and a private helper the dispatcher must NOT expose.
 */
final class JsonRpcServerTestTarget
{
    public function greet(string $name = 'world'): string
    {
        return 'hello ' . $name;
    }

    public function concat(string $first, string $second): string
    {
        return $first . $second;
    }

    public function boom(): never
    {
        throw new RuntimeException('/var/www/secret/path.php:42 oopsie');
    }

    public function safeBoom(): never
    {
        throw new JsonRpcServerTestSafeException('told-you-so');
    }

    /** @phpstan-ignore method.unused */
    private function secret(): string
    {
        return 'shh';
    }
}

/**
 * Parent with a public method — the child should NOT surface it as RPC.
 */
class JsonRpcServerInheritingParent
{
    public function inheritedHello(): string
    {
        return 'inherited';
    }
}

/**
 * Inheriting fixture target. Only `ownHello()` should be dispatchable;
 * `inheritedHello()` from the parent must stay hidden.
 */
final class JsonRpcServerInheritingChild extends JsonRpcServerInheritingParent
{
    public function ownHello(): string
    {
        return 'own';
    }
}

/**
 * Marker-bearing exception to exercise the SafeRpcException code path.
 */
final class JsonRpcServerTestSafeException extends RuntimeException implements SafeRpcException
{
}
