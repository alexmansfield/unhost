<?php
/**
 * Spike: does laravel/mcp 0.9.5's Client::web() complete
 * initialize → notifications/initialized → tools/list → tools/call
 * against RestlessWP's stateless JSON-RPC endpoint with Basic auth?
 *
 * unhost ticket #9. Reads the credential from a sibling .mcp.json at runtime;
 * every logged header is redacted. Only read-only calls are made.
 *
 * Usage: php restlesswp-client-spike.php [/path/to/.mcp.json] [serverKey]
 */
declare(strict_types=1);

$vendor = getenv('LARAVEL_VENDOR') ?: $_SERVER['HOME'].'/Cove/Sites/scatterblend.localhost/vendor';
require $vendor.'/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Laravel\Mcp\Exceptions\ClientException;
use Laravel\Mcp\Exceptions\JsonRpcException;

// --- minimal container so the Http facade resolves (no Laravel app booted) ---
$container = new Container;
Container::setInstance($container);
Facade::setFacadeApplication($container);
$container->instance('config', new Repository([]));
$container->singleton(Factory::class, fn () => new Factory(new Dispatcher($container)));
$http = $container->make(Factory::class);

// --- credential: read at runtime, never printed ---
$mcpJson = $argv[1] ?? $_SERVER['HOME'].'/Cove/Sites/example.localhost/.mcp.json';
$key = $argv[2] ?? 'wordpress';
$cfg = json_decode((string) file_get_contents($mcpJson), true)['mcpServers'][$key] ?? null;
if (! $cfg) { fwrite(STDERR, "no server [$key] in $mcpJson\n"); exit(1); }
$url = $cfg['url'];
$authorization = $cfg['headers']['Authorization'];
$scheme = explode(' ', $authorization, 2)[0];

// --- wire log with redaction ---
$SENSITIVE = ['authorization', 'cookie', 'set-cookie'];
$redact = function (array $headers) use ($SENSITIVE): array {
    $out = [];
    foreach ($headers as $name => $values) {
        $v = implode(', ', (array) $values);
        $out[$name] = in_array(strtolower($name), $SENSITIVE, true)
            ? substr($v, 0, strpos($v.' ', ' ')).' <redacted '.strlen($v).' chars>'
            : $v;
    }
    return $out;
};
$log = [];
$n = 0;
$t0 = null;
$http->globalRequestMiddleware(function ($request) use (&$log, &$n, &$t0, $redact) {
    $n++;
    $t0 = microtime(true);
    $body = (string) $request->getBody();
    $decoded = json_decode($body, true);
    $log[$n] = [
        'request' => [
            'method' => $request->getMethod(),
            'rpc'    => is_array($decoded) ? ($decoded['method'] ?? '?').(isset($decoded['id']) ? " id={$decoded['id']}" : ' (notification)') : '(empty)',
            'headers' => $redact($request->getHeaders()),
            'bytes'  => strlen($body),
        ],
    ];
    return $request;
});
$http->globalResponseMiddleware(function ($response) use (&$log, &$n, &$t0, $redact) {
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $log[$n]['response'] = [
        'status'  => $response->getStatusCode(),
        'ms'      => $ms,
        'headers' => $redact($response->getHeaders()),
        'bytes'   => $response->getHeaderLine('Content-Length') ?: '(streamed)',
    ];
    return $response;
});

$say = fn (string $s) => fwrite(STDOUT, $s."\n");
$say("== RestlessWP ↔ laravel/mcp client spike ==");
$say("endpoint: ".preg_replace('#^(https?://[^/]+).*#', '$1/…', $url)."  auth scheme: $scheme");

// ---------------------------------------------------------------- phase 1: happy path
$client = Client::web($url)
    ->withHeaders(['Authorization' => $authorization])
    ->withTimeout(30);

$T = microtime(true);
try {
    $client->connect();
    $init = $client->initializeResult();
    $say(sprintf("connect(): ok in %d ms — protocolVersion=%s server=%s %s capabilities=%s",
        (int) round((microtime(true) - $T) * 1000),
        $init->protocolVersion, $init->serverInfo->name, $init->serverInfo->version,
        json_encode($init->capabilities)));
} catch (Throwable $e) {
    $say("connect(): FAILED ".get_class($e).": ".$e->getMessage());
}

$T = microtime(true);
try {
    $tools = $client->tools();
    $say(sprintf("tools(): %d tools in %d ms", $tools->count(), (int) round((microtime(true) - $T) * 1000)));
    $annotated = $tools->filter(fn ($t) => $t->annotations !== [])->count();
    $readOnly = $tools->filter(fn ($t) => ($t->annotations['readOnlyHint'] ?? false) === true)->count();
    $destructive = $tools->filter(fn ($t) => ($t->annotations['destructiveHint'] ?? false) === true)->count();
    $say("  annotated: $annotated  readOnlyHint: $readOnly  destructiveHint: $destructive");
    $say("  sample names: ".$tools->keys()->take(8)->implode(', '));
    $say("  has list-posts: ".($tools->has('list-posts') ? 'yes' : 'no').
        "  has restlesswp-list-posts: ".($tools->has('restlesswp-list-posts') ? 'yes' : 'no').
        "  has get-posts: ".($tools->has('get-posts') ? 'yes' : 'no'));
} catch (Throwable $e) {
    $say("tools(): FAILED ".get_class($e).": ".$e->getMessage());
    $tools = collect();
}

// 0.13.0 names first, then the 0.9.x fallback: a readOnlyHint tool with no required input
$target = $tools->keys()->first(fn ($k) => in_array($k, ['list-post-types', 'restlesswp-acf-get-post-types'], true))
    ?? $tools->filter(fn ($t) => ($t->annotations['readOnlyHint'] ?? false) === true && empty($t->inputSchema['required']))->keys()->first();
$say("  chosen read-only tool: ".var_export($target, true));
if ($target) {
    $T = microtime(true);
    try {
        $result = $client->callTool($target, []);
        $say(sprintf("callTool(%s): isError=%s in %d ms", $target, var_export($result->isError, true), (int) round((microtime(true) - $T) * 1000)));
        $say("  content blocks: ".count($result->content)."  types: ".implode(',', array_map(fn ($c) => $c['type'] ?? '?', $result->content)));
        $say("  structuredContent: ".($result->structuredContent === null ? 'null' : 'keys='.implode(',', array_keys($result->structuredContent))));
        $say("  text (first 300): ".substr($result->text(), 0, 300));
    } catch (Throwable $e) {
        $say("callTool($target): FAILED ".get_class($e).": ".$e->getMessage());
    }
}

// unknown tool → what does the client surface?
try {
    $r = $client->callTool('no-such-tool-xyz', []);
    $say("callTool(no-such-tool-xyz): returned ToolResult isError=".var_export($r->isError, true)." text=".substr($r->text(), 0, 160));
} catch (JsonRpcException $e) {
    $say("callTool(no-such-tool-xyz): JsonRpcException code=".$e->getCode()." msg=".$e->getMessage());
} catch (Throwable $e) {
    $say("callTool(no-such-tool-xyz): ".get_class($e).": ".$e->getMessage());
}
$say("connected() after JsonRpcException: ".var_export($client->connected(), true));

// second call on same client — does it re-initialize?
$before = $n;
try { $client->ping(); $say("ping(): ok — HTTP requests used: ".($n - $before)); } catch (Throwable $e) { $say("ping(): ".get_class($e).": ".$e->getMessage()." — HTTP requests used: ".($n - $before)); }

$before = $n;
$client->disconnect();
$say("disconnect(): HTTP requests used: ".($n - $before));
unset($client);

// ---------------------------------------------------------------- phase 2: no credential
$say("\n-- anonymous client --");
$anon = Client::web($url)->withTimeout(30);
try {
    $anon->tools();
    $say("anon tools(): unexpectedly succeeded");
} catch (AuthorizationRequiredException $e) {
    $c = $e->challenge;
    $say("anon tools(): AuthorizationRequiredException — ".$e->getMessage());
    $say("  challenge: ".($c === null ? 'null' : 'resourceMetadataUrl='.var_export($c->resourceMetadataUrl, true).' error='.var_export($c->error, true).' scope='.var_export($c->scope, true).' query='.json_encode($e->query())));
} catch (Throwable $e) {
    $say("anon tools(): ".get_class($e).": ".$e->getMessage());
}
unset($anon);

// ---------------------------------------------------------------- phase 3: wrong credential
$say("\n-- wrong credential (same scheme, garbage value) --");
$bad = Client::web($url)->withHeaders(['Authorization' => $scheme.' '.base64_encode('nobody:not-a-real-password')])->withTimeout(30);
try {
    $bad->tools();
    $say("bad tools(): unexpectedly succeeded");
} catch (AuthorizationRequiredException $e) {
    $say("bad tools(): AuthorizationRequiredException — ".$e->getMessage());
} catch (Throwable $e) {
    $say("bad tools(): ".get_class($e).": ".$e->getMessage());
}
unset($bad);

// ---------------------------------------------------------------- wire log
$say("\n== wire log (headers redacted) ==");
foreach ($log as $i => $entry) {
    $req = $entry['request'];
    $res = $entry['response'] ?? ['status' => '(no response)', 'ms' => '-', 'headers' => [], 'bytes' => '-'];
    $say(sprintf("#%d %s %s → %s in %s ms, body %s bytes",
        $i, $req['method'], $req['rpc'], $res['status'], $res['ms'], $res['bytes']));
    $say("   > ".json_encode(array_intersect_key($req['headers'], array_flip(['Accept', 'Authorization', 'MCP-Session-Id', 'MCP-Protocol-Version', 'Content-Type']))));
    $say("   < ".json_encode(array_intersect_key(array_change_key_case($res['headers'], CASE_LOWER), array_flip(['content-type', 'mcp-session-id', 'www-authenticate', 'content-length', 'server', 'x-restlesswp']))));
}
