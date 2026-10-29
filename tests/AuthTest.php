<?php

declare(strict_types=1);

use AiSdk\Mcp\Auth\BearerAuthenticator;
use AiSdk\Mcp\Auth\BearerChallenge;
use AiSdk\Mcp\Auth\BearerToken;
use AiSdk\Mcp\Auth\ProtectedResourceMetadata;
use AiSdk\Mcp\Exceptions\McpException;
use AiSdk\Mcp\Protocol;
use AiSdk\Mcp\Server\HttpHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

it('builds RFC 9728 protected resource metadata and its well-known path', function () {
    $metadata = new ProtectedResourceMetadata('https://mcp.test/mcp', ['https://auth.test'], ['tools:read', 'tools:call']);
    $factory = new Psr17Factory();
    $response = $metadata->response($factory, $factory);
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and(json_decode((string) $response->getBody(), true))->toBe([
            'resource' => 'https://mcp.test/mcp', 'authorization_servers' => ['https://auth.test'],
            'scopes_supported' => ['tools:read', 'tools:call'], 'bearer_methods_supported' => ['header'],
        ])
        ->and($metadata->metadataUrl())->toBe('https://mcp.test/.well-known/oauth-protected-resource/mcp')
        ->and((new ProtectedResourceMetadata('https://mcp.test/', ['https://auth.test']))->metadataUrl())
        ->toBe('https://mcp.test/.well-known/oauth-protected-resource')
        ->and((new ProtectedResourceMetadata('https://mcp.test/mcp/', ['https://auth.test']))->metadataUrl())
        ->toBe('https://mcp.test/.well-known/oauth-protected-resource/mcp/');
});

it('rejects unsafe resource issuer and challenge URLs', function (string $url) {
    expect(fn() => new ProtectedResourceMetadata($url, ['https://auth.test']))->toThrow(McpException::class)
        ->and(fn() => new ProtectedResourceMetadata('https://mcp.test', [$url]))->toThrow(McpException::class)
        ->and(fn() => BearerChallenge::header($url))->toThrow(McpException::class);
})->with([
    'http://mcp.test', 'http://localhost.evil.test', 'http://127.0.0.1.evil.test', 'https://user:secret@mcp.test',
    'https://mcp.test#fragment', 'https://mcp.test?token=secret', "https://mcp.test/\r\nInjected: value", 'https://mcp.test/%0d%0aevil',
    'https://good.test\\@evil.test', 'https://-bad.test', 'file:///etc/passwd', 'https://mcp.test:99999',
    'https://', 'https://mcp.test/"quoted',
]);

it('permits HTTP only on exact loopback hosts', function (string $url) {
    expect((new ProtectedResourceMetadata($url, [$url]))->toArray()['resource'])->toBe($url);
})->with(['http://localhost:8080/mcp', 'http://127.0.0.1/mcp', 'http://[::1]:8080/mcp']);

it('extracts only a single syntactically valid header bearer token', function () {
    expect(BearerToken::extract(new ServerRequest('POST', 'https://mcp.test?access_token=query')))->toBeNull()
        ->and(BearerToken::extract(new ServerRequest('POST', 'https://mcp.test', ['Authorization' => 'bEaReR abc.DEF_-~+/=='])))->toBe('abc.DEF_-~+/==')
        ->and(BearerToken::extract(new ServerRequest('POST', 'https://mcp.test', ['Authorization' => 'Basic aaa'])))->toBeNull();
});

it('rejects ambiguous malformed or oversized token headers without exposing them', function (array $headers) {
    try {
        BearerToken::extract(new ServerRequest('POST', 'https://mcp.test', $headers));
        test()->fail('Expected malformed credentials to be rejected.');
    } catch (McpException $e) {
        expect($e->getMessage())->not->toContain('secret');
    }
})->with([
    [['Authorization' => ['Bearer secret', 'Bearer another']]],
    [['Authorization' => 'Bearer secret, Bearer another']],
    [['Authorization' => 'Bearer secret invalid']],
    [['Authorization' => 'Bearer']],
    [['Authorization' => 'Bearer ' . str_repeat('secret', 2000)]],
]);

it('builds authorization and scope challenges with bounded safe fields', function () {
    $factory = new Psr17Factory();
    $url = 'https://mcp.test/.well-known/oauth-protected-resource';
    expect(BearerChallenge::header($url))->toBe('Bearer resource_metadata="' . $url . '"');
    $response = BearerChallenge::response($factory, $url, 403, scopes: ['tools:call', 'tools:read']);
    expect($response->getStatusCode())->toBe(403)
        ->and($response->getHeaderLine('WWW-Authenticate'))->toBe('Bearer resource_metadata="' . $url . '", error="insufficient_scope", scope="tools:call tools:read"')
        ->and($response->getHeaderLine('Cache-Control'))->toBe('no-store');
    expect(fn() => BearerChallenge::header($url, scopes: ['bad"scope']))->toThrow(McpException::class)
        ->and(fn() => BearerChallenge::header($url, error: "injected\r\n"))->toThrow(McpException::class)
        ->and(fn() => new ProtectedResourceMetadata('https://mcp.test', []))->toThrow(McpException::class)
        ->and(fn() => new ProtectedResourceMetadata('https://mcp.test', ['https://auth.test'], bearerMethodsSupported: ['invalid']))->toThrow(McpException::class);
});

it('authenticates before parsing HTTP bodies and gives appropriate token and scope errors', function () {
    $factory = new Psr17Factory();
    $calls = 0;
    $auth = new BearerAuthenticator(function (string $token) use (&$calls): ?array {
        $calls++;

        return match ($token) {
            'good' => ['tools:read', 'tools:call'],
            'limited' => ['tools:read'],
            'broken' => throw new RuntimeException('secret credential details'),
            default => null,
        };
    }, $factory, 'https://mcp.test/.well-known/oauth-protected-resource/mcp', ['tools:call']);
    $handler = new HttpHandler(demoServer(), $factory, $factory, authenticator: $auth);
    $request = new ServerRequest('POST', 'https://mcp.test/mcp', [], 'invalid JSON');
    $missing = $handler->handle($request);
    expect($missing->getStatusCode())->toBe(401)
        ->and($missing->getHeaderLine('WWW-Authenticate'))->not->toContain('error=')
        ->and($calls)->toBe(0);
    $denied = $handler->handle($request->withHeader('Authorization', 'Bearer invalid'));
    expect($denied->getStatusCode())->toBe(401)
        ->and($denied->getHeaderLine('WWW-Authenticate'))->toContain('error="invalid_token"');
    $limited = $handler->handle($request->withHeader('Authorization', 'Bearer limited'));
    expect($limited->getStatusCode())->toBe(403)
        ->and($limited->getHeaderLine('WWW-Authenticate'))->toContain('error="insufficient_scope"', 'scope="tools:call"');
    $broken = $handler->handle($request->withHeader('Authorization', 'Bearer broken'));
    expect($broken->getStatusCode())->toBe(401)
        ->and((string) $broken->getBody() . $broken->getHeaderLine('WWW-Authenticate'))->not->toContain('secret');
    expect($handler->handle($request->withHeader('Authorization', 'Bearer a b'))->getStatusCode())->toBe(400);
    expect($handler->handle($request->withHeader('Authorization', 'Bearer good'))->getStatusCode())->toBe(400);
    $good = new ServerRequest('POST', 'https://mcp.test/mcp', [
        'Authorization' => 'Bearer good', 'MCP-Protocol-Version' => Protocol::VERSION, 'Mcp-Method' => 'tools/list',
    ], json_encode(rpc('tools/list')));
    expect($handler->handle($good)->getStatusCode())->toBe(200);
});
