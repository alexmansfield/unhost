# The pass-through in the box: laravel/mcp client and the Scatterblend `mcp` module

Research ticket #4, part of map #1. Read 21 Sep 2026 against the sources below; every claim cites a file and line, or a URL.

**Sources (primary):**

- `laravel/mcp` **v0.9.5** as vendored at `~/Cove/Sites/scatterblend.localhost/vendor/laravel/mcp/` (version from `composer.lock`; requires `illuminate/* ^11.45.3|^12.41.1|^13.0`). Cited below as `mcp/…` meaning `vendor/laravel/mcp/src/…`.
- The Scatterblend `mcp` module, `nonboxed/scatterblend-mcp` dev-main at `~/Cove/Sites/scatterblend-mcp/` (requires `laravel/mcp ^0.9`). Cited as `sb-mcp/…` meaning `src/…`.
- The Scatterblend `oauth` module at `~/Cove/Sites/scatterblend-oauth/`. Cited as `sb-oauth/…`.
- Scatterblend core at `~/Cove/Sites/scatterblend/`. Cited as `core/…`.
- Core's `resources/boost/skills/scatterblend-development/` and the module's `resources/boost/skills/mcp-development/SKILL.md`.
- Official docs: <https://laravel.com/docs/13.x/mcp> (the "MCP Client" and "Searchable Tool Catalogs" sections). The docs describe `$client->capabilities()` and `$client->serverInfo()`; **neither exists in v0.9.5** (only `initializeResult()` does, `mcp/Client.php:99`). The docs lead the vendored release; trust the source lines below.

---

## Answers

**Client half**

- **`Client::web()`** — `public static function web(string $url): WebClient` (`mcp/Client.php:70`), one argument, the endpoint URL. Returns a `WebClient` wrapping `new HttpTransport($url)`. No constructor options; everything else is fluent: `withToken(string|Closure)`, `withHeaders(array)`, `withTimeout(float)`, `withOAuth(...)` (`mcp/WebClient.php:29–61`, `mcp/Client.php:75`).
- **`withHeaders()`** — `public function withHeaders(array $headers): static` (`WebClient.php:39`); merged into the transport's custom headers and applied *last*, replacing any default header of the same name case-insensitively (`HttpTransport.php:74–77, 196–204`). So `withHeaders(['Authorization' => 'Basic …'])` overrides the Bearer header `withToken()` would set: Basic auth (RestlessWP application passwords) works through this seam.
- **Session id** — captured from every response's `MCP-Session-Id` header and echoed on every later request of that transport instance (`HttpTransport.php:182–184, 209–216`); `disconnect()`/`__destruct()` sends `DELETE` to the URL when a session id is held (`HttpTransport.php:267–280`). State lives in the transport object, so it lasts as long as the PHP object: per request under FPM.
- **Timeouts** — `protected float $timeoutSeconds = 30.0` per HTTP round-trip, set by `withTimeout(float $seconds)` (`HttpTransport.php:33`, `Client.php:75–80`). Applies to POST and to the terminating DELETE.
- **`tools/list`** — `public function tools(?int $limit = null, ?iterable $default = null): Collection` (`Client.php:113`): follows `nextCursor` to the end (or `$limit` items), keyed by tool name, each a `Client\Primitives\Tool` with `name`, `title`, `description`, `inputSchema`, `outputSchema`, `annotations`, `meta` and a `call(array $arguments): ToolResult` method (`Primitives/Tool.php:20–29, 71–78`; `Methods/Concerns/PaginatesList.php:59–116`).
- **`tools/call`** — `public function callTool(string $name, array $arguments = []): ToolResult` (`Client.php:129`). Returns `ToolResult { array $content; bool $isError; ?array $structuredContent; ?array $meta }` with `text()` (concatenated text items) and `__toString()` (`Client/Schema/ToolResult.php:18–62`). Arguments are sent as a JSON object even when empty (`Methods/Tools/CallTool.php:38`).
- **401/403** — thrown as `AuthorizationRequiredException(string $message, ?WwwAuthenticateChallenge $challenge)` after resetting the transport (`HttpTransport.php:114–123`); the parsed challenge has `resourceMetadataUrl`, `error`, `errorDescription`, `scope` (`Client/OAuth/WwwAuthenticateChallenge.php:12–39`). Both statuses map to the *same* exception: the gateway cannot tell "bad credential" from "forbidden" without reading `$e->challenge->error`.
- **Error mapping** — four classes, all under `Laravel\Mcp`: transport/HTTP/parse faults → `Exceptions\ClientException`; 401/403 → `Client\Exceptions\AuthorizationRequiredException` (extends `OAuthException` extends `ClientException`); 404 while holding a session → `SessionExpiredException` (extends `ClientException`) which `Protocol::dispatch()` catches once, reconnects and retries (`Protocol.php:86–99`); a JSON-RPC `error` object → `Exceptions\JsonRpcException($message, $code, $requestId, ?array $data)` and the connection is **kept** (`Protocol.php:155–166`). A tool-level failure is **not** an exception: `ToolResult->isError === true` with the message in `content` (`ToolResult.php:30–47`).
- **Reuse** — a `Client` is reusable within one process: lazy connect on first dispatch, the session id retained, no reconnect between calls (`Protocol.php:88–90`). Across requests: `Mcp::registerClient($name, fn () => Client::web(...))` memoises per name and `disconnectAll()` runs on `terminating` (`Client/ClientManager.php:36–39`, `Server/McpServiceProvider.php:102–104`), so the practical lifetime is one HTTP request (or one Octane request). Per-request construction is the expected pattern; a first `callTool()` on a fresh client costs **three POSTs** (`initialize`, `notifications/initialized`, `tools/call`) plus one DELETE at destruct when the remote issued a session id (`Protocol.php:49–73`, `HttpTransport.php:168–171`).

**Server half**

- **Offering a tool** — from `bootModule()`, behind `class_exists(\Nonboxed\Scatterblend\Mcp\McpServer::class)`: `McpServer::tool(Tool|string $tool): Registration` then `->order(int)` (`sb-mcp/McpServer.php:31–34`; `mcp-development/SKILL.md:76–85`). The key is `{module}.{tool-name}` stamped by core's `StampsKeys` from the tool's own `name()`, e.g. `gateway.call-site-tool`; names are kebab-case segments (`/^[a-z][a-z0-9-]*$/`) and a foreign prefix or an offer during `registerModule()` is a `ContributionException` (`sb-mcp/Server/Registry.php:190–200`; `core/src/Contributions/Concerns/StampsKeys.php:38–62`; `core/src/Modules/Manifest.php:15`). Instances are accepted as well as class names (`Registry.php:192`; `sb-mcp/tests/Feature/RegistryTest.php:116`).
- **`#[Capability]` / `shouldRegister()`** — a tool extends `Nonboxed\Scatterblend\Mcp\Tools\Tool` and carries `#[Capability('gateway:noun:verb')]` or `#[Open]`, never both, never neither (`sb-mcp/Tools/Tool.php:28–65`; `Tools/Capability.php:14–18`; `Tools/Open.php`). `shouldRegister(Request $request, Gate $gate): bool` answers `Gate::forUser($request->user())->allows($capability)` and laravel/mcp asks it on every listing and every call (`Tool.php:34–49`; `mcp/Server/Primitive.php:91–98`; `mcp/Server/ServerContext.php:128–135`). The registry validates on `booted` that a declared capability is core's, `operator`, or a `key:noun:verb` of an **active** module (`Registry.php:233–260`). The `ChecksToolCapabilities` convention trait enforces the attribute rule on every tool offered and every class under `src/` extending the base (`sb-mcp/Testing/Conventions/ChecksToolCapabilities.php:31–66`).
- **Actor and tenant** — the actor is `$request->user()` (`Laravel\Mcp\Request::user(?string $guard)`, `mcp/Request.php:96–101`): the token's user, or the agent owning a client-credentials client (`sb-oauth/Auth/TokenGuard.php:37–56`). The tenant is bound before the server runs by the `scatterblend.api` group (`['api', 'auth:api', BindTokenTenant::class]`, `core/src/ScatterblendServiceProvider.php:890`; `core/src/Http/Middleware/BindTokenTenant.php:37–63`): read it with `CurrentTenant::get()` / `membership()` / `via()` (`core/src/Tenancy/CurrentTenant.php:72–85`), as `Whoami` does (`sb-mcp/Tools/Whoami.php:61–62`). Inside `handle()` the `Context` already carries `tenant_id`, `membership_id`, `via` and the module adds `tool` (`CurrentTenant.php:45–47`; `sb-mcp/Tools/Invoker.php:44, 55`).
- **Structured error / `Refusal`** — `Nonboxed\Scatterblend\Mcp\Contracts\Refusal` is an empty marker interface (`sb-mcp/Contracts/Refusal.php:13`). An exception implementing it (or core's `InvitationException`) thrown from `handle()` becomes `Response::error($e->getMessage())`, unreported, regardless of `app.debug` (`sb-mcp/Tools/Invoker.php:74–96`). Anything else: `ValidationException` → its messages; `AuthenticationException`/`AuthorizationException` → their message; any other throwable → reported and answered `'An internal server error occurred.'` unless `app.debug` (`mcp/Server/Methods/Concerns/InteractsWithResponses.php:110–132`). A tool may also *return* an error itself: `Response::error(string)` or `Response::make(Response::error(...))->withStructuredContent([...])` gives `isError: true` **plus** `structuredContent` on the wire (`mcp/Response.php:107–110, 120–123`; `mcp/ResponseFactory.php:58–63`; `mcp/Server/ToolInvoker.php:30–38`). The wire shape is only ever `{content, isError, structuredContent?, _meta?}`; there is no error-code field in the tool result, so a gateway error taxonomy must live in `structuredContent`.
- **`throttle:mcp`** — the module mounts `Mcp::web('/mcp', ApplicationServer::class)->middleware(['scatterblend.api', 'throttle:mcp'])` and defines the `mcp` limiter on `booted` only if the application has not: `Limit::perMinute(config('mcp.throttle', 60))->by(user id ?: ip)` (`sb-mcp/McpServiceProvider.php:79–98`; `config/mcp.php:17`). It is per actor and per minute over **every** JSON-RPC message (`ping`, `tools/list` and `tools/call` alike; `sb-mcp/tests/Feature/ServerTest.php:112–135`). A module cannot add route middleware to `/mcp` (the route is the `mcp` module's), but the application can redefine the `mcp` limiter, and a tool can rate-limit itself inside `handle()` with `RateLimiter::attempt()`/`tooManyAttempts()` and throw a `Refusal` — nothing in laravel/mcp or the module prevents that.
- **Result size** — no cap anywhere: neither laravel/mcp's server nor the `mcp` module bounds a `tools/call` result; the only byte limit in the package is `mcp.tool_search.max_output_bytes` (65 536 by default) inside `ToolSearch` (`mcp/config/mcp.php` "Tool Search"; `mcp/Server/Tools/ToolSearch.php:36–37, 98–117, 138–140`). `tools/list` is cursor-paginated at `defaultPaginationLength = 15`, `maxPaginationLength = 50` per page (`mcp/Server.php:103–105`; `Server/Methods/ListTools.php:17–23`). On the client side, `tools()` reads every page into memory and `HttpTransport` reads the whole body (or every SSE `data:` line) into a queue (`HttpTransport.php:137–150, 218–229`). A cap on what the gateway forwards is the gateway's to add.

**`listChanged` and `ToolSearch`**

- **`listChanged => false`** is the value `Server::$capabilities` advertises for tools, resources and prompts (`mcp/Server.php:76–86`). It forbids nothing at runtime: it is a promise to the client that the server will *never send* `notifications/tools/list_changed`, and laravel/mcp has no code path to send one (the server only ever answers requests; the client's `handleServerRequest()` rejects any server-initiated request other than `ping`, `mcp/Client/Protocol.php:183–203`, and its `HttpTransport` fails on one arriving over SSE, `HttpTransport.php:260–262`). The module's registry is filled during boot and read afresh per request (`sb-mcp/Server/ApplicationServer.php:23–35`), and `shouldRegister()` already makes the list per-actor, so a listing *can* differ between calls; what is fixed is that a client is told not to re-list. A `gateway` module that changed its own tool list per site would silently desynchronise clients that cache the first listing. D4's constant, site-parameterised surface is the correct reading.
- **`ToolSearch`/`SearchTools`/`ExecuteTools`** are a **local** catalog: `ToolSearch::__construct(iterable $tools)` accepts only `Laravel\Mcp\Server\Tool` instances or subclasses (`ToolSearch.php:32–46`), scores a query against local `name()`/`description()`/`inputSchema` (`:59–118`), and `execute()` invokes locally through `ToolInvoker` (`:124–148, 165–224`). Nothing in it takes a remote `tools/list` payload, so it is **precedent, not reusable** for a remote catalog. It also cannot be offered through the module's registry: `Registry::offer()` refuses anything that is not a `Tool` instance (`sb-mcp/Server/Registry.php:193–197`), and the registry never emits the `ToolSearch::class => [...]` key that `ServerContext::tools()` expects (`mcp/Server/ServerContext.php:40–56`; `ApplicationServer.php:30`). What *is* reusable: its output shape `{ok, tools:[{name, description, inputSchema, annotations?}], hasMore}` and `{ok, results:[{name, content, isError}]}` with `{ok:false, error:{kind, message}}` (`ToolSearch.php:82–91, 117, 135–147, 269–280`), and its config idiom `mcp.tool_search.max_output_bytes`. A gateway `list-site-tools` can answer in the `search_tools` shape and an eventual bulk `call-site-tools` in the `execute_tools` shape, so a model that has learned laravel/mcp's catalog reads the gateway the same way.

---

## 1. Client half in detail

### 1.1 Construction and configuration

```php
// mcp/Client.php:38–45, 65–80
public function __construct(protected Transport $transport, public ?Implementation $clientInfo = null)
public static function local(string $command, array $args = []): static
public static function web(string $url): WebClient
public function withTimeout(float $seconds): static

// mcp/WebClient.php:18–61
public function __construct(protected HttpTransport $httpTransport, public ?Implementation $clientInfo = null, protected ?OAuthConfig $oAuthConfig = null)
public function withToken(#[SensitiveParameter] string|Closure $token): static
public function withHeaders(array $headers): static
public function withOAuth(?string $clientId = null, ?string $clientSecret = null, ?string $scope = null, ?string $redirectUri = null): static
public function oAuthClient(?string $resourceMetadataUrl = null, ?string $challengeScope = null): OAuthClient
```

- `clientInfo` defaults to `new Implementation(name: config('app.name', 'Laravel MCP Client'), version: '0.0.1')` (`Client.php:47–53`): the remote site sees unhost's `app.name` in `initialize`. To identify the gateway (or the acting user) differently, pass `new WebClient(new HttpTransport($url), new Implementation(...))` directly, or set a header.
- `initialize` sends `capabilities: {}` — the client declares no capabilities (`Client/Methods/Initialize.php:31–38`), so the remote may not use sampling, elicitation or roots against it.
- Protocol version: the client asks for `ProtocolVersion::LATEST` (`2025-11-25`) and accepts any in `clientSupported()`; the negotiated version is echoed as `MCP-Protocol-Version` on every request *after* the first successful one (`HttpTransport.php:186–188`; `Enums/ProtocolVersion.php:9–12`).

### 1.2 Headers on the wire

`HttpTransport::headers()` (`HttpTransport.php:176–207`) builds, in order: `Accept: application/json, text/event-stream`; `MCP-Session-Id` if held; `MCP-Protocol-Version` if initialised; `Authorization: Bearer {token}` if `withToken()` set one (closures are resolved on every request, so a rotating credential is supported); then every custom header, each replacing a same-named default. Therefore:

- **Basic auth**: `->withHeaders(['Authorization' => 'Basic '.base64_encode("$user:$appPassword")])` — the Bearer line is removed before the custom one is added. (This is what the discovery record asserted; confirmed at lines 196–204.)
- `Content-Type: application/json` is set by `withBody($message, 'application/json')` (`:104`).
- Guzzle `stream => true` is passed for every POST (`:106`), so SSE bodies are read line by line (`:218–250`).

### 1.3 Request lifecycle and session

`Protocol::dispatch()` (`Protocol.php:86–99`): if not connected, `connect()` first, which sends `initialize` and then the `notifications/initialized` notification (`:49–73`). Each `Method` is one JSON-RPC request with an incrementing integer id (`:105–111`); the transport POSTs it and queues the response body (or each SSE `data:` line); `attempt()` drains the queue until it sees the frame with the matching id, answering server `ping` frames and refusing any other server request with `-32601` (`:113–134, 183–203`).

Server-side, laravel/mcp's own server is **stateless**: it mints a UUID session id on `initialize` and returns it as a header (`mcp/Server.php:308–322`; `Server/Transport/HttpTransport.php:116–118`), but never validates the id on later requests — the module's `ServerTest` calls `tools/list` without ever initialising (`sb-mcp/tests/Feature/ServerTest.php:52–55`). What RestlessWP does on its side is a question for the RestlessWP ticket; the client handles both "no session header" and "session header" (`HttpTransport.php:209–216`).

### 1.4 What each exception means

| Condition | Exception (`Laravel\Mcp\…`) | Connection after | Source |
|---|---|---|---|
| TCP/TLS/DNS/timeout (`Illuminate\Http\Client\ConnectionException`) | `Exceptions\ClientException` "HTTP request to [url] failed: …" | reset | `HttpTransport.php:108–110, 290–295` |
| HTTP 401 or 403 | `Client\Exceptions\AuthorizationRequiredException` with `?WwwAuthenticateChallenge $challenge`; helpers `resourceMetadataUrl()`, `scope()`, `query()` | reset | `HttpTransport.php:114–123`; `AuthorizationRequiredException.php:11–37` |
| HTTP 404 while a session id is held | `Exceptions\SessionExpiredException` → `Protocol::dispatch()` reconnects **once** and retries the same method | re-initialised | `HttpTransport.php:125–129`; `Protocol.php:92–98` |
| Any other non-2xx (incl. 404 with no session, 429, 5xx) | `ClientException` "Unexpected HTTP status [n] …" | reset | `HttpTransport.php:131–133` |
| Body not JSON / not JSON-RPC 2.0 / both or neither of `result`,`error` | `ClientException` | disconnected | `Protocol.php:119–146` |
| Server-initiated request over SSE (anything but `ping`) | `ClientException` | reset | `HttpTransport.php:252–265`; `Protocol.php:183–203` |
| JSON-RPC `error` object (e.g. `-32602` tool not found, `-32603` internal) | `Exceptions\JsonRpcException(string $message, int $code, mixed $requestId = null, ?array $data = null)` — note `getCode()` is the JSON-RPC code | **kept** | `Protocol.php:155–166`; `Exceptions/JsonRpcException.php:15–21` |
| Tool answered `isError: true` | no exception; `ToolResult->isError` | kept | `ToolResult.php:30–47` |
| `initialize` result names an unsupported protocol version or is malformed | `ClientException` | disconnected | `Client/Schema/InitializeResult.php:39–48` |

`tools()`, `prompts()`, `resources()` take a `$default` iterable and swallow only `AuthorizationRequiredException` when one is given (`Client.php:113–124`); `callTool()` has no such fallback.

Important for a gateway: a non-`Errable` handler upstream turns a tool error into a JSON-RPC error, but laravel/mcp's `CallTool` and `ToolInvoker` are `Errable`, so on a laravel/mcp remote a tool's `Response::error` arrives as `isError: true`, never as `JsonRpcException` (`mcp/Server/Methods/CallTool.php:16`; `Server/ToolInvoker.php:16`; `InteractsWithResponses.php:34–42`). RestlessWP is a different implementation and must be checked separately (ticket on RestlessWP).

### 1.5 Reuse, named clients and serialisation

- `Client::__destruct()` disconnects if connected (`Client.php:219–224`), and `HttpTransport::__destruct()` always calls `disconnect()`, which sends `DELETE` only if a session id is held (`HttpTransport.php:168–171, 267–280`). Every ad-hoc client that saw a session id costs one DELETE on the way out; errors on that DELETE are swallowed.
- `Mcp::registerClient(string $name, Closure $factory)` / `Mcp::client(string $name)` memoise by name inside a container singleton (`Server/McpServiceProvider.php:25`; `ClientManager.php:25–39`); `disconnectAll()` on `terminating` (`McpServiceProvider.php:102–104`). The docs say "resolved once per request and automatically disconnected at the end of the request lifecycle" (laravel.com/docs/13.x/mcp, Named Clients). A named client is a fixed URL+credential, so one name per **site** would be the fit, registered lazily; for hundreds of sites a per-call ad-hoc client is simpler and equivalent in cost.
- `Client::__serialize()` (`Client.php:184–195`): a named client serialises as its name and is rebuilt from the factory; an ad-hoc client serialises its transport **recipe**, which includes the **resolved token string and every custom header in plaintext** (`HttpTransport.php:87–96`). A queued job holding an ad-hoc `WebClient` with a Basic-auth header would write the site credential into the queue payload. Hazard for any async or bulk design: queue a *site id* and rebuild the client in the job, never the client.

### 1.6 What `tools()` returns and costs

`ListTools` walks `nextCursor` until blank, refusing a repeated cursor, honouring `$limit` (`PaginatesList.php:59–116`); each page is a `tools/list` POST. Against a laravel/mcp remote with the stock page size of 15, a 60-tool site is four round-trips. `Tool::from()` refuses payloads missing `name` or with wrong types (`Primitives/Tool.php:36–54`), so a non-conforming remote listing throws `ClientException` rather than yielding a partial collection.

---

## 2. Server half in detail

### 2.1 The registry seam

- `McpServer::tool()`, `resource()`, `prompt()`, `instructions(string $name, string|Closure $text)`, `registration(string $key)`, `registry()` (`sb-mcp/McpServer.php:31–80`). While the `mcp` module is installed but not listed active, `registryOrThrowaway()` returns a fresh unbound `Registry`, so the offer is a no-op (`:77–80`).
- `Registry::tool(McpTool|string $tool): Registration` instantiates class-string tools **at offer time, on every boot** to read `name()` (`Registry.php:190–200`; SKILL.md:22 "take light constructor dependencies"). A gateway tool's constructor must not resolve anything that needs a tenant or an HTTP request.
- `Registration` is a fluent builder: `order(int)`, `hide()`, `replace(Registration)`, `wireName()`, `capability()`, `source()` (`Registration.php:39–119`). The application may reorder/hide/replace a module's tool by key (`McpServer::registration('gateway.call-site-tool')->hide()`).
- `Registry::validate()` on `booted`: no two registrations of a kind share a wire name; every `Tool` capability is `operator`, a `RoleSet::CORE_CAPABILITIES` entry, or a `key:noun:verb` whose module is **active** (`Registry.php:150–169, 233–260`). So `#[Capability('gateway:sites:call')]` requires the `gateway` manifest to declare `gateway:sites:call` in `$capabilities`; the `sites` module's capabilities are valid too when `sites` is active (a `gateway` tool gated on `sites:site:view` — a *foreign* capability — would pass validation; whether that is wanted is an ADR question for the elevation ticket).
- The `ApplicationServer` copies the registry into laravel/mcp's `$tools`, `$resources`, `$prompts`, `$instructions` per request and swaps the `tools/call` handler for the module's `CallTool` (`ApplicationServer.php:23–35`).

### 2.2 The actor, the tenant and the credential

- `scatterblend.api` = `['api', 'auth:api', BindTokenTenant::class]` (`core/src/ScatterblendServiceProvider.php:890`). `BindTokenTenant` aborts 401 without a `User`, asks the `CredentialTenantStep` for the credential's reach, then `TokenTenantBinding::decide()` with the `X-Tenant` header; binds `CurrentTenant` with the membership and `via` (`BindTokenTenant.php:37–63`).
- The `oauth` module's `TokenGuard` makes the actor the token's user, or for a client-credentials token the **owner** of the client, which is the agent user (`sb-oauth/Auth/TokenGuard.php:37–56, 103–112`). `Credential::via()` is `oauth:{client id}` (`sb-oauth/Auth/Credential.php:70–75`); `Credential::connection()` finds the `Connection` row (user × client, remembering `tenant_id`) (`:109–116`; `sb-oauth/Models/Connection.php:27–71`). `Agent` is a thin row: `user_id`, `created_by` (`Models/Agent.php:20–45`).
- `CapabilityIntersection::refuses(Authenticatable $user, string $ability): ?bool` is a Gate `before` callback: when the token's scopes name capabilities, a capability not among them is refused; `*` or `mcp:use` alone leave the role's answer intact (`sb-oauth/Auth/CapabilityIntersection.php:30–49`). A gateway tool never reads scopes; it asks `Gate::forUser($actor)` and the intersection is applied for it (SKILL.md:20, 72).
- Inside `handle(Request $request)`: `$request->user()`, `app(CurrentTenant::class)->get()`, `->membership()`, `->via()`; `$request->sessionId()`, `$request->meta()` (`mcp/Request.php:96–121`). The `Whoami` tool is the model of this (`sb-mcp/Tools/Whoami.php:53–80`).

### 2.3 Answering: shapes and refusals

- Return types: `Response::text()`/`error()` return `Response`; `Response::structured(array)` returns `ResponseFactory` and also emits the JSON as a text item (`mcp/Response.php:90–105`); `Response::make(Response|array)` builds a `ResponseFactory` for several items; `->withStructuredContent(array)` and `->withMeta()` on the factory (`ResponseFactory.php:48–63`). A `Generator` return streams notifications then the final result (`ToolInvoker.php:20–28`; `InteractsWithResponses.php:51–86`). Declaring the wrong return type is a `TypeError` reported as a fault (SKILL.md:73).
- The wire result is `{content: [...], isError: bool, structuredContent?: {...}, _meta?: {...}}` (`ToolInvoker.php:30–38`; `HasStructuredContent.php:28–35`). `isError` is true if *any* item is an error.
- `Refusal` (`sb-mcp/Contracts/Refusal.php`) → `Response::error($e->getMessage())`, unreported (`Invoker.php:74–77`). For a gateway that must surface the *remote's* `isError` result, the cleanest route is to **return** `Response::make(Response::error($remote->text()))->withStructuredContent(['site' => …, 'tool' => …, 'remote' => $remote->structuredContent])`, not throw; throwing a `Refusal` gives a sentence only.
- Validation: `$request->validate([...])` throws `ValidationException` → answered as `isError` with the messages (`InteractsWithResponses.php:110–114`); `schema(JsonSchema $schema): array` declares `inputSchema` (`mcp/Server/Tool.php:22–25`).

### 2.4 Throttle

- `throttle:mcp` is applied on the one route, keyed by `getAuthIdentifier()` else IP, per minute, config `mcp.throttle` default 60 (`McpServiceProvider.php:83–97`; `config/mcp.php:17`). Every JSON-RPC POST counts, including `initialize` and `ping`. A client such as Claude that lists tools and pings will spend part of the budget on overhead.
- The limiter is defined only if `RateLimiter::limiter('mcp') === null` at `booted`, so the **application** (unhost's `AppServiceProvider`) may define `mcp` with a different shape — e.g. higher for agents, or keyed by tenant. A module's `bootModule()` runs before `booted`, so a module could pre-empt it too, but that is the application's decision by the module's own comment ("only when the application defined none", `McpServiceProvider.php:74–77`).
- Route middleware on `/mcp` cannot be extended by another module: `Mcp::web()` returns the `Route` to the `mcp` module only (`McpServiceProvider.php:95–97`; `mcp/Server/Registrar.php:42–66`). A gateway-specific limit (per site, per dangerous tool) therefore lives inside the tool, using Laravel's `RateLimiter` directly and throwing a `Refusal` when exceeded; this is ordinary Laravel and needs no seam.

### 2.5 Sizes

Nothing bounds a tool result in laravel/mcp's server, the module, or the client (a search across `mcp/src` for byte limits finds only `ToolSearch`). PHP's `memory_limit` and the web server's response limits are the only ceilings. A gateway that forwards a RestlessWP `list-posts` verbatim can produce a result larger than a model context; the `mcp.tool_search.max_output_bytes` idiom (default 65 536; `mcp/config/mcp.php`) is the precedent for a `gateway.max_result_bytes` config with an `{ok:false, error:{kind:'OutputLimitExceeded'}}` answer (`ToolSearch.php:269–280`).

---

## 3. `listChanged` and the catalog shape

See the Answers. Two further facts:

- Any server can flip the capability with `addCapability('tools.listChanged', true)` (`mcp/Server.php:134–152`), but with no code to emit the notification it would be a lie; the module does not call it (`ApplicationServer.php`).
- `ServerContext::tools()` builds a `ToolSearch` only from a `ToolSearch::class` array key in `$tools` (`ServerContext.php:40–56`); the module's registry emits a flat `list<McpTool>` (`Registry.php:113–116`), so a catalog *cannot* be offered through `McpServer::tool()` today. Doing so would need a change in the `mcp` module (a `McpServer::catalog()` kind), which is a Scatterblend ask, not an unhost decision. It is not needed for the gateway: the remote catalog is data the gateway serves through its own tool.

---

## 4. Sketch: a `gateway` module offering `call-site-tool`

Only APIs verified above. Names in the ticket's vocabulary; the `sites` seam (`Site`, the site connector resolving a credential for `(actor, site)`) is assumed as an interface, not designed here.

```php
// gateway/src/GatewayServiceProvider.php
namespace Unhost\Gateway;

use Nonboxed\Scatterblend\Modules\ModuleServiceProvider;
use Unhost\Gateway\Tools\CallSiteTool;
use Unhost\Gateway\Tools\ListSiteTools;

class GatewayServiceProvider extends ModuleServiceProvider
{
    protected string $key = 'gateway';
    protected string $label = 'Gateway';
    protected string $description = 'Pass-through to each site\'s own MCP server.';
    protected array $requires = ['sites'];                 // not 'mcp': off is a legitimate configuration
    protected array $capabilities = ['gateway:site-tools:call', 'gateway:site-tools:view'];

    protected function registerModule(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gateway.php', 'gateway');   // e.g. timeout, max_result_bytes
        $this->app->singleton(SiteClients::class);
    }

    protected function bootModule(): void
    {
        if (class_exists(\Nonboxed\Scatterblend\Mcp\McpServer::class)) {     // SKILL.md:79–83
            \Nonboxed\Scatterblend\Mcp\McpServer::tool(ListSiteTools::class)->order(20);   // key gateway.list-site-tools
            \Nonboxed\Scatterblend\Mcp\McpServer::tool(CallSiteTool::class)->order(30);    // key gateway.call-site-tool
            \Nonboxed\Scatterblend\Mcp\McpServer::instructions('sites', static fn (): string => __('Call list-sites, then list-site-tools for a site\'s schemas, then call-site-tool.'));
        }
    }
}
```

```php
// gateway/src/SiteClients.php — one place that builds a client for a site; per call, never cached across requests
namespace Unhost\Gateway;

use Laravel\Mcp\Client;
use Laravel\Mcp\WebClient;

class SiteClients
{
    public function __construct(private \Illuminate\Contracts\Config\Repository $config) {}

    /** @param  array<string, string>  $headers  the site connector's credential as headers, e.g. ['Authorization' => 'Basic …'] */
    public function for(string $mcpUrl, array $headers): WebClient
    {
        return Client::web($mcpUrl)                                   // mcp/Client.php:70
            ->withHeaders($headers)                                    // WebClient.php:39 — replaces Bearer if present
            ->withTimeout((float) $this->config->get('gateway.timeout', 30));   // Client.php:75
    }
}
```

```php
// gateway/src/Tools/CallSiteTool.php
namespace Unhost\Gateway\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Laravel\Mcp\Exceptions\ClientException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Nonboxed\Scatterblend\Mcp\Tools\Capability;
use Nonboxed\Scatterblend\Mcp\Tools\Tool;
use Unhost\Gateway\SiteClients;
use Unhost\Sites\SiteBook;          // the `sites` seam: resolves a Site the actor may reach, or throws a Refusal

#[Name('call-site-tool')]           // kebab-case: it is the key segment gateway.call-site-tool
#[IsOpenWorld]
#[Capability('gateway:site-tools:call')]
class CallSiteTool extends Tool
{
    public function __construct(private SiteBook $sites, private SiteClients $clients) {}   // light: Registry instantiates on every boot

    public function title(): string       { return __('Call a site tool'); }
    public function description(): string { return __('Run one tool on one site\'s own MCP server, as you.'); }

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description(__('The site slug from list-sites.'))->required(),
            'tool' => $schema->string()->max(255)->description(__('The exact tool name from list-site-tools.'))->required(),
            'arguments' => $schema->object()->description(__('Arguments matching the tool\'s input schema.')),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'site' => ['required', 'string'],
            'tool' => ['required', 'string', 'max:255'],
            'arguments' => ['nullable', 'array'],
        ]);

        $actor = $request->user();                                                  // mcp/Request.php:96
        $site = $this->sites->reachable($actor, $validated['site']);                // throws a Refusal → Response::error (Invoker.php:74–77)
        $credential = $site->connector()->credentialFor($actor, $site);             // provider contract, undecided here
        $client = $this->clients->for($site->mcpUrl(), $credential->headers());

        try {
            $result = $client->callTool($validated['tool'], $validated['arguments'] ?? []);   // Client.php:129
        } catch (AuthorizationRequiredException $e) {                              // 401/403 at the site
            return $this->failure('SiteAuthorization', $e->getMessage(), ['challenge' => $e->query()]);
        } catch (JsonRpcException $e) {                                            // e.g. -32602 tool not found at the site
            return $this->failure('SiteRpc', $e->getMessage(), ['code' => $e->getCode()]);
        } catch (ClientException $e) {                                             // transport, timeout, malformed
            return $this->failure('SiteUnreachable', $e->getMessage());
        }
        // audit row written here or in a wrapping service: (actor, tenant, site, tool, isError) — not shown

        $payload = [
            'ok' => ! $result->isError,
            'site' => $site->slug,
            'tool' => $validated['tool'],
            'content' => $result->content,                 // the remote's content items verbatim
            'structuredContent' => $result->structuredContent,
        ];

        $response = $result->isError
            ? Response::error($result->text() !== '' ? $result->text() : __('The site tool reported an error.'))   // Response.php:107
            : Response::text($result->text());

        return Response::make($response)->withStructuredContent($payload);        // Response.php:120; ResponseFactory.php:58
    }

    private function failure(string $kind, string $message, array $extra = []): ResponseFactory
    {
        return Response::make(Response::error($message))
            ->withStructuredContent(['ok' => false, 'error' => ['kind' => $kind, 'message' => $message, ...$extra]]);   // ToolSearch.php:269–280 shape
    }
}
```

`ListSiteTools` is the same skeleton with `$client->tools()` (`Client.php:113`) mapped to `['name' => $t->name, 'description' => $t->description, 'inputSchema' => $t->inputSchema, 'annotations' => $t->annotations]` (`Primitives/Tool.php:20–29`), answered as `{ok, tools, hasMore}` and cached per site under a key the `sites` module owns. Testing: `ApplicationServer::tool(CallSiteTool::class, [...])` with `Http::fake()` for the site (`mcp-development/SKILL.md:95–130`; `HttpTransport.php:103` goes through the `Http` facade, so `Http::fake()` intercepts it).

Wire cost per `call-site-tool`: one inbound POST to `/mcp` (counted by `throttle:mcp`), and outbound `initialize` + `notifications/initialized` + `tools/call` (+ `DELETE` if the site returned a session id) to the site.

---

## 5. Surprises worth carrying to other tickets

1. **`withHeaders()` really does override `Authorization`** (case-insensitive replace, `HttpTransport.php:196–204`), so application-password Basic auth needs no custom transport.
2. **Three outbound POSTs per fresh client** before the first `tools/call` answers, plus a DELETE at destruct if the site returned `MCP-Session-Id`. Whether RestlessWP returns one decides whether that fourth request happens (RestlessWP ticket).
3. **Serialising an ad-hoc `WebClient` leaks the credential into the payload** (`HttpTransport::recipe()`, `:87–96`): queue site ids, not clients.
4. **401 and 403 collapse into one exception**; only `$e->challenge->error`/`errorDescription` distinguishes them, and only if the site sends `WWW-Authenticate`.
5. **A JSON-RPC error keeps the connection; every other fault drops it and the next call re-initialises** (`Protocol.php:147–153, 155–166`).
6. **The docs are ahead of v0.9.5**: `capabilities()`/`serverInfo()` on the client do not exist; use `initializeResult()`.
7. **No result-size cap anywhere**; the gateway must add one, and `mcp.tool_search.max_output_bytes` (65 536) with `{ok:false, error:{kind:'OutputLimitExceeded'}}` is the in-box precedent.
8. **`throttle:mcp` counts every JSON-RPC message per actor per minute** (default 60), including `initialize`/`ping`; the application, not a module, is the intended place to redefine the `mcp` limiter.
9. **A tool declaring another active module's capability passes registry validation** (`Registry.php:247–259`), so `gateway` tools may be gated on `sites:*` capabilities if the elevation ADR wants that; the `ChecksToolCapabilities` check only insists on *a* capability or `#[Open]`.
10. **`ToolSearch` cannot be offered through the registry** without a change to the `mcp` module; not needed for D4.
