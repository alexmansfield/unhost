# authwp's guarded fetch, and what authwp expects of a server-side Client

Resolves: unhost #7 (part of #1). Researched 21 Sep 2026.

Sources, all primary, all under `~/Cove/Sites/authwp/` at commit `5ff7d46` (GitHub `alexmansfield/authwp`,
branch `main`): `docs/spec.md` (the ready-for-agent spec, 16 Sep 2026 plus dated amendments), `docs/CONTEXT.md`
(the glossary), `docs/map.md`, `docs/issues/07-…`, `09-…`, `14-…`, `docs/research/01-…` and `02-…`, the
Auth Server source under `auth-server/app/`, its tests, the shared fixtures under `contract/`, and the plugin
source under `wp-plugin/`. Line numbers are of those files at that commit. WordPress core facts were checked
against the 7.1.1 checkout at `~/Cove/Sites/example.localhost/public/`. authwp's own GitHub
issues (#1–#23) were listed; #5 is the guarded-fetch implementation ticket.

Vocabulary is authwp's (`~/Cove/Sites/authwp/docs/CONTEXT.md`): **Client**,
**Site**, **Connection**, **Access token**, **Consent**, **Issuer**, **Instance**, **Operator**. Where this note
says "the gateway" it means unhost acting as an authwp Client.

---

## Answers

- **Guarded fetch — contract.** One `final class GuardedFetch` with one public method, `get(string $url,
  ?FetchLimits $limits = null, array $headers = []): FetchResult`, over an injected `Transport` interface
  (`resolve(hostname): list<string>` + `send(method, url, host, port, address, headers, limits): RawResponse`).
  Order: https-only → resolve **once** → check **every** address against `AddressPolicy` → pin the connection
  to one checked address (`CURLOPT_RESOLVE`) → refuse any 3xx → refuse over-cap bodies. It never throws; every
  outcome is a `FetchResult` with a `FetchFailure` enum (`not_https`, `unresolvable`, `address_refused`,
  `redirect`, `too_large`, `timeout`, `transport_failure`). Defaults 5 KB / 5 s; **any port** is accepted.
- **Guarded fetch — liftability.** The `App\Outbound` namespace (9 files, ~550 lines) has **zero framework
  imports** — pure PHP 8.3 + `ext-curl` — bound by one line in `AppServiceProvider`. Lifting it into a plain
  Composer library is mechanical (rename namespace, add a provider, move `GuardedFetchTest` +
  `FakeTransport`). It is the **wrong shape for a Scatterblend module** (no tenant, no capability, no slot);
  the right shape is a library the `gateway` module requires or vendors. But it is **GET-only, body-less,
  https-only, buffered, and refuses loopback with no override** — `site-request` needs a body and method,
  MCP streaming needs an unbuffered client, and Cove `*.localhost` sites resolve to `127.0.0.1` and are
  refused. Recommendation: copy as a template (MIT) into `gateway`, extend, and lift only `AddressPolicy` +
  the resolve→check→pin idea under whatever HTTP client the MCP path uses.
- **Client side.** authwp constructs **one kind of client: public**, PKCE **S256 only**, no client
  secret, no scopes (a `scope` parameter is `invalid_scope`), no refresh, `state` optional-but-echoed,
  RFC 9207 `iss` always appended. A CIMD `client_id` is the gateway's own `https` URL **with a path**; the
  document must answer 200, ≤5 KB, in 5 s, with no redirect, from a public address, and carry `client_id`
  equal to the fetch URL plus a non-empty `redirect_uris` (`client_name` optional, defaults to the host, cut
  at 100 chars). It is fetched **fresh on every authorization**; failures are negative-cached 60 s
  Instance-wide. The consent card headlines the **gateway's hostname**, frames `client_name` as a claim, and
  the application password is named `{gateway-host} — via AuthWP` with `app_id` = UUIDv5(URL ns, client_id)
  and `authwp_client` = the client_id. The token request needs all of `grant_type`, `code`, `code_verifier`,
  `client_id`, `redirect_uri`; the response is exactly `{"access_token":"authwp_…","token_type":"bearer"}`.
  Re-consent **adds** a Connection, never replaces. Revocation reaches the Client **only as a 401** carrying
  the discovery challenge; there is no feed, no webhook, and the Auth Server keeps no Connection list.
- **The 401 challenge when authwp is active** — on **any** REST 401, attached at `rest_post_dispatch`:
  `WWW-Authenticate: Bearer resource_metadata="{rest_url}authwp/v1/protected-resource?resource={requested URL, encoded}"`
  — a **plugin REST route, not the RFC 9728 well-known** — with no `scope`. The PRM there is
  `{"resource": <exact URL>, "authorization_servers": ["https://{as}/t/{site-id}"]}`. When authwp is
  installed but not serving OAuth, ordinary 401s are **bare**; only the sentinel `Bearer authwp_probe` gets
  `Bearer error="invalid_token", error_description="authwp_inactive"`. Core itself never sends
  `WWW-Authenticate`, so "401 with no header" means *no authwp, not serving, or header stripped*.
- **Spec edges a server-side Client forces.** (1) No local-dev path: the address policy has no override, so a
  local authwp cannot fetch a `*.localhost` CIMD document and a local gateway cannot fetch local sites. (2) The
  wrapper is decodable by the Client (the never-decode rule binds the Auth Server), which the brief's D3
  "store the plain pair" relies on — but spec §6 reserves the right to change the format. (3) Long-lived
  browser-mediated re-consent is the only renewal; a gateway must surface "needs re-consent" to a human.
  (4) `resource`, if sent, must have the Site's **Registered origin** (origin of `rest_url()`), not the MCP
  path. (5) Nothing tells a Client the WP user or Connection UUID; use core's
  `GET /wp/v2/users/me/application-passwords/introspect`. (6) Bulk registration of Sites with an Instance is
  out of authwp's scope (pre-issued-code sketch only) — the gateway as Operator is a future authwp effort.

---

## 1. The guarded fetch

### 1.1 Where it lives and who calls it

`auth-server/app/Outbound/` holds nine files: `GuardedFetch`, `Transport` (interface), `CurlTransport`,
`AddressPolicy`, `FetchLimits`, `FetchResult`, `FetchFailure` (enum), `RawResponse`, `TransportException`.
The class doc-comment states the rule that owns it: "Every server-side fetch of a URL the Auth Server did not
mint goes through here — the CIMD fetch, the Consent URL ping and both discovery probes (spec §11, §21 rule 9)
— and nothing fetches any other way" (`GuardedFetch.php` L5-9; spec §11 L657-658 and §21 rule 9 L1350-1351
say the same).

Exactly two production callers: `App\OAuth\Clients\CimdFetcher` (L104-108) and `App\Probe\DiscoveryProbe`
(L91, L151, L173). Binding: `AppServiceProvider::register()` L25, `$this->app->singleton(Transport::class,
CurlTransport::class)`; `GuardedFetch` itself is constructed by the container from that one dependency
(`GuardedFetch.php` L32). Tests rebind `Transport` to `tests/Support/FakeTransport.php` (README L432-437;
`AppServiceProvider` L15-20 names it as one of the three permitted seams below the HTTP boundary, with the
clock and the random source).

### 1.2 The contract, as an interface sketch

Exact signatures from the source:

```php
namespace App\Outbound;

final class GuardedFetch
{
    public function __construct(private readonly Transport $transport) {}                       // L32

    /** @param array<string,string> $headers */
    public function get(string $url, ?FetchLimits $limits = null, array $headers = []): FetchResult; // L37
    // private function send(string $method, …) exists (L45) but only GET is exposed.
}

interface Transport
{
    /** @return list<string>  IPv4 and IPv6 alike; [] = does not resolve; never called for a literal IP */
    public function resolve(string $hostname): array;                                            // L26

    /** Send over TLS to $address, presenting $host as SNI and Host header; never follow redirects.
     *  @throws TransportException  (connection, TLS, reset, or budget expiry) */
    public function send(string $method, string $url, string $host, int $port, string $address,
                         array $headers, FetchLimits $limits): RawResponse;                      // L42-50
}

final class FetchLimits
{
    public const int DEFAULT_MAX_BYTES = 5 * 1024;     // L16
    public const int DEFAULT_BUDGET_SECONDS = 5;       // L18
    public function __construct(public readonly int $maxBytes = …, public readonly int $budgetSeconds = …);
    // throws InvalidArgumentException when either < 1                                            // L24-26
}

final class FetchResult
{
    public function received(): bool;        // failure === null                                  // L39
    public function failure(): ?FetchFailure;                                                     // L44
    public function status(): ?int;          // also set (the 3xx) on a refused redirect          // L49
    public function header(string $name): ?string;   // first value, case-insensitive              // L57
    public function headers(): array;        // lowercased name → list<string>                     // L65
    public function body(): string;                                                               // L70
    public function detail(): string;        // human-readable reason for a report                 // L75
}

enum FetchFailure: string { NotHttps='not_https'; Unresolvable='unresolvable'; AddressRefused='address_refused';
                            Redirect='redirect'; TooLarge='too_large'; Timeout='timeout';
                            TransportFailure='transport_failure'; }                               // L11-36

final class AddressPolicy { public static function refuses(string $address): ?string; }           // L44

final class RawResponse   { public function __construct(public readonly int $status,
                              public readonly array $headers, public readonly string $body) {} }  // L14-18
```

A non-2xx status is **not** a failure: "what to make of a 401 or a 404 is the caller's business (the probe
reads meaning into both; the CIMD fetch requires 200)" (`FetchResult.php` L12-14).

### 1.3 The steps, in the order the code applies them

All in `GuardedFetch::send()` (L45-98):

1. **https only, before any lookup.** `parse_url`; scheme must be `https` and a host must be present, else
   `NotHttps` (L47-51). Host is lowercased with IPv6 brackets stripped; port defaults to 443 (L53-54).
2. **Resolve once.** A literal IP skips resolution and "takes the same check, pinned to itself" (L56-58).
   Otherwise `$this->transport->resolve($host)`; an empty answer is `Unresolvable` (L60-64). In production
   `CurlTransport::resolve()` calls `dns_get_record()` for `DNS_A` then `DNS_AAAA` and returns the unique
   addresses (`CurlTransport.php` L25-40) — the system resolver, no cache of its own.
3. **Check every address.** `foreach ($addresses …) AddressPolicy::refuses($address)` — one refused answer
   refuses the whole fetch and **nothing is sent** (L67-71; test
   `test_a_name_with_any_non_public_address_among_its_answers_is_refused`, `GuardedFetchTest.php` L141-149).
4. **Pin.** `send(…, self::preferIpv4($addresses), …)` — IPv4 first when the host has one, "because an
   Instance without IPv6 routing would otherwise report every dual-stack Site unreachable" (L74, L100-117).
   `CurlTransport` pins with `CURLOPT_RESOLVE => ["{$host}:{$port}:{$pin}"]` while curl still presents the
   hostname for SNI and `Host`, "and the pin cannot be bypassed by a second lookup because curl never performs
   one" (`CurlTransport.php` L7-13, L62). `CURLOPT_PROXY => ''` because "an environment proxy would un-pin the
   connection" (L71). TLS verification is on (`VERIFYPEER` true, `VERIFYHOST` 2, L66-67);
   `CURLOPT_PROTOCOLS_STR`/`REDIR_PROTOCOLS_STR` are `https` (L63-64); `FOLLOWLOCATION` false (L65).
   **DNS rebinding** is therefore harmless by construction — one lookup, the connect goes to the checked
   address — and the test reproduces the race (`GuardedFetchTest.php` L151-171: public first, private second;
   the first fetch is pinned to the public answer, the second is refused).
5. **Timeout and transport failure.** `TransportException::isTimeout()` → `Timeout` ("No complete response
   within N seconds"), else `TransportFailure` with curl's message (L75-79). The budget is a single number
   covering connect and read: `CURLOPT_CONNECTTIMEOUT_MS` and `CURLOPT_TIMEOUT_MS` are both set to it
   (`CurlTransport.php` L68-69).
6. **No redirect following.** Any 300–399 → `Redirect`, with the `Location` in the detail and the status
   preserved (L81-89). "A redirect is an error, not a hop, which also closes the redirect-to-internal vector"
   (L21-22).
7. **Size cap.** Refused if the received body exceeds `maxBytes` **or** a declared `Content-Length` does
   (L91-95). `CurlTransport`'s write callback returns 0 once the cap is exceeded, aborting the transfer
   mid-stream (L82-93), and the resulting `CURLE_WRITE_ERROR` is tolerated only when the overflow flag is set
   (L105-109).

### 1.4 What `AddressPolicy` refuses — and does not

Refused IPv4 (`AddressPolicy.php` L20-30): `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10` (CGNAT),
`127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`, `192.168.0.0/16`, `224.0.0.0/4`, `240.0.0.0/4`.
Refused IPv6 (L33-39): `::/128`, `::1/128`, `fc00::/7`, `fe80::/10`, `ff00::/8`. IPv4-mapped
(`::ffff:a.b.c.d`), NAT64 (`64:ff9b::/96`) and the deprecated IPv4-compatible form (`::a.b.c.d`) are judged by
the embedded IPv4 (L46-52, L69-100). Anything that is not an IP is refused as `'not an IP address'` (L62).
Matching is a packed-byte prefix compare (L105-142); the reason strings are human-readable (L144-155).

Candidly, the table is a hand-picked list, not the full IANA special-purpose registry. Not covered: `192.0.0.0/24`
(IETF protocol assignments), `192.0.2.0/24` / `198.51.100.0/24` / `203.0.113.0/24` (documentation),
`198.18.0.0/15` (benchmarking), IPv6 `2001:db8::/32`, and the 6to4 (`2002::/16`) and Teredo (`2001::/32`)
forms that also embed an IPv4 address. None of these is routable to anything interesting on a typical host,
but a lift should add them rather than inherit the omission. **Ports are not policed at all**: the URL's port
is passed to the pin (`GuardedFetch.php` L54, L74; test L218-229 asserts `8443` reaches the transport).

### 1.5 How it is configured

There is **no configuration**. `config/authwp.php` has rate limits, log retention and the CIMD negative-cache
TTL (L1-60) and nothing about the fetch. Limits are per call: `CimdFetcher` passes
`new FetchLimits(5*1024, 5)` (`CimdFetcher.php` L36-38, L104-108); `DiscoveryProbe` passes `1 MiB` because
"the REST index of a large site runs to hundreds of kilobytes" (`DiscoveryProbe.php` L58-62, L91). The user
agent is the constant `authwp-auth-server` (`CurlTransport.php` L23). The refused ranges are `private const`
arrays with no allow-list and no environment override — searched `config/`, `README.md` and the source;
nothing relaxes the policy for local development. (Implication in §4.)

### 1.6 Lifting it: Scatterblend module, Composer package, or copy

**Dependencies.** The nine files import only `InvalidArgumentException`, `RuntimeException` and `CurlHandle`.
No Laravel, no PSR-7, no Guzzle. They use PHP 8.3 typed class constants (`const int`, `const array`,
`const string`) — matching Scatterblend's `php ^8.3` (scatterblend-development SKILL.md, step 1). Licence is
MIT (`auth-server/composer.json` L12). The `CimdNegativeCache` (Laravel cache + injected clock) sits
**outside** `Outbound` and is authwp-specific; it does not come along.

**Shape.** A Scatterblend *module* is "an ordinary Composer package" whose provider extends
`ModuleServiceProvider` with a manifest, capabilities, tiers, slots and tenant-owned tables (SKILL.md, "The
module contract"). A guarded fetch has none of those: no tenant, no UI, no capability, no table. It is a
**library**, and the Scatterblend rule that "modules never depend on application code" (unhost #1 Notes) is
satisfied either by (a) vendoring the nine files into the `gateway` module's `src/Outbound/` as a template —
exactly what authwp did with laravel/mcp's register controller, "copied as templates and attributed with the
derived-from version" (spec §8 L494-498; `RegisteredRedirectUri.php` L12-15 shows the attribution style) — or
(b) extracting a standalone Composer library that both authwp and unhost require. (b) is only worth it if
authwp agrees to depend on it, which is an ask filed at authwp, not resolved here; the map's rule is that asks
to other repos are filed there (#1 Notes). Until then, (a) with `derived from alexmansfield/authwp@5ff7d46`
in the file header, plus the test and the fake.

**What must change for unhost's uses**, in decreasing importance:

1. **Method and body.** `Transport::send()` has no body parameter and `GuardedFetch::send()` is private
   (L45). `site-request` (POST/PUT/DELETE with JSON bodies) needs `request(string $method, string $url,
   array $headers, ?string $body, FetchLimits $limits)` on the fetch and `?string $body` on the transport.
   `CurlTransport` uses `CURLOPT_CUSTOMREQUEST` already (L60); adding `CURLOPT_POSTFIELDS` is the whole change.
2. **Buffered, capped body.** The body is accumulated in memory and cut at the cap (`CurlTransport.php` L82-93).
   That is right for a probe and wrong for MCP streamable-HTTP responses that may be `text/event-stream`.
   Whatever MCP client the `wordpress` module ends up with (the compatibility spike, unhost discovery item 6)
   will bring its own HTTP layer. The reusable core there is **not** `GuardedFetch` but **`AddressPolicy` plus
   the resolve→check→pin recipe** — resolve with `dns_get_record`, refuse, then hand the client a
   `CURLOPT_RESOLVE` entry (Guzzle exposes raw curl options) and forbid redirects and proxies. Design the lift
   as two layers: a `Pinning` helper that yields `{host, port, address}` for any curl-based client, and the
   small `GuardedFetch` on top for `site-request` and verification fetches.
3. **Origin binding is a separate guard.** `AddressPolicy` answers "is this address public?"; the gateway
   also needs "is this URL under *this Site's* recorded URL?" — a check against the `sites` row, not the
   address. Not in authwp (its callers fetch URLs the Site itself reported, under an origin an Operator
   approved — spec §7 L464-474). A wrapper in `gateway` owns it.
4. **Local development.** `*.localhost` sites resolve to `127.0.0.1` and are `AddressRefused`; there is no
   override (§1.5). unhost needs an explicit, environment-gated allow (e.g. `local` only), or its dev sites on
   public DNS, or tests only through the fake. authwp has the same gap for *its* development (see §4.1).
5. **Limits are the caller's.** 5 KB / 5 s are CIMD's numbers (`FetchLimits.php` L7-12). Tool responses need
   bigger caps; `DiscoveryProbe` shows the pattern (1 MiB).
6. **http.** Refused before lookup (L49). Fine for production sites; relevant only to the dev-site question.
7. **Headers are single-valued** (`array<string,string>`, L34-37) — enough for `Authorization`, `Accept`,
   `Content-Type`.
8. **Ports** are unrestricted (§1.4). Decide whether `site-request` should be 443-only.

**Tests come with it.** `tests/Feature/GuardedFetchTest.php` has 17 cases (L36-315) driven entirely through
`FakeTransport` — per-lookup scripted DNS (so a rebinding race is reproducible), per-URL responses, and a log of
every lookup and every request with the address it was pinned to (`FakeTransport.php` L7-17). Scatterblend's
testing rule "extend the contract test for any seam you rebind" (SKILL.md step 13) fits this seam exactly.

---

## 2. What authwp expects of a Client — the flow from the gateway's side

### 2.1 What kind of client authwp constructs

"There is one kind of OAuth client" (spec §8 L514-518). `ClientEntity::isConfidential()` returns `false`
unconditionally: "Never confidential: there is no client-secret tier … and a public client is one league
requires PKCE from" (`ClientEntity.php` L48-55). Metadata advertises
`token_endpoint_auth_methods_supported: ["none"]` and `client_id_metadata_document_supported: true`
(`AuthorizationServerMetadataController.php` L43-53) — the pair that makes both Claude surfaces prefer CIMD
(research 02 L84-87; spec §11 L629-632). So the gateway is a **public client** with **no secret**, exactly as
the brief predicted (wp-gateway-brief.md L50-52).

Three client-lookup cases (`ClientLookup.php` L35-50; spec §11 L622-628): an `https://…` `client_id` is
CIMD (identity **verified**); an opaque id is a DCR row or an Operator-preregistered row under **this Site's
Issuer** (identity **asserted**). A DCR `client_id` is bound to one Issuer, so a DCR gateway would register
once **per site**; a CIMD `client_id` "is portable across authorization servers" (research 01 L310-313). The
gateway should be CIMD.

### 2.2 What the gateway must serve: the CIMD document

`CimdFetcher::fetch()` applies, each refusing on its own (`CimdFetcher.php` L64-144; spec §11 L633-646;
ticket 14 §5 L155-170):

| Rule | Source |
|---|---|
| `client_id` is an `https` URL **with a path** and **no fragment** | L68-78 |
| Not negative-cached: a failed URL is refused for 60 s Instance-wide, keyed by sha256 of the URL, storing only the failure kind | L80-82; `CimdNegativeCache.php` L42, L80-83 |
| Fetched through `GuardedFetch::get()` with `FetchLimits(5120, 5)` and `Accept: application/json` | L104-108 |
| So: public address, no redirect (a `www`→apex or trailing-slash redirect is fatal), TLS valid for the hostname, answer within 5 s connect+read | §1 |
| HTTP **200** required | L114-116 |
| Body is a JSON object | L118-122 |
| `client_id` in the document `===` the URL fetched | L124-126 |
| `redirect_uris` is a non-empty list of non-empty strings | L128-138 |
| `client_name` optional; defaults to the lowercased host; cut to 100 chars | L44, L140-143 |

Nothing else in the document is read. `token_endpoint_auth_method`, `grant_types`, `response_types`,
`client_uri` are ignored by authwp but the IETF draft requires the first three fields above and forbids
shared-secret auth methods (research 01 L272, L284-286), and other Auth Servers will read them. A safe
document, modelled on the two Anthropic ones authwp was tested against (research 02 L92-100, L119-126):

```json
{
  "client_id": "https://unhost.example/oauth/client-metadata",
  "client_name": "unhost",
  "client_uri": "https://unhost.example",
  "redirect_uris": ["https://unhost.example/oauth/authwp/callback"],
  "grant_types": ["authorization_code"],
  "response_types": ["code"],
  "token_endpoint_auth_method": "none"
}
```

Serve it static and fast (the 5 s budget is the Auth Server's, and a slow answer poisons the negative cache
for a minute). It is fetched **fresh on every authorization** — "a recorded deviation from the draft's caching
SHOULD" (spec §11 L647-652; ticket 14 §6 L180-196) — and once per authorization: the entity is rebuilt from the
Consent row afterwards, so "a CIMD document that changes mid-flow cannot move the goalposts"
(`ParkedAuthorization.php` L30-35).

`redirect_uri` is **exact-matched** against `redirect_uris`; only plain-`http` loopback hosts get the port
ignored (`RedirectUriMatch.php` L15-41). The gateway's callback is `https`, so byte-exact.

### 2.3 Discovery, per site

The gateway learns each site's Issuer from the site, never by configuration (spec §5 L343-356, the sequence diagram):

1. Any unauthenticated or invalid-token REST request → **401** with
   `WWW-Authenticate: Bearer resource_metadata="…"` (§3 below).
2. `GET` that URL → `{"resource": "<the exact URL asked about>", "authorization_servers": ["https://{as}/t/{site-id}"]}`
   (`class-discovery.php` L185-197). Exactly two keys; no `scopes_supported`.
3. `GET https://{as}/.well-known/oauth-authorization-server/t/{site-id}` — the RFC 8414 **path-inserted** form
   (the well-known string between host and the Issuer's path; `routes/wire.php` L41-45) → metadata:
   `issuer`, `authorization_endpoint` = `{issuer}/authorize`, `token_endpoint` = `{issuer}/token`,
   `registration_endpoint`, `response_types_supported: ["code"]`, `code_challenge_methods_supported: ["S256"]`,
   `grant_types_supported: ["authorization_code"]`, `token_endpoint_auth_methods_supported: ["none"]`,
   `client_id_metadata_document_supported: true` (`AuthorizationServerMetadataController.php` L43-53).
   No `scopes_supported`, no `offline_access`, no refresh grant (L23-27).

An unknown, revoked, deregistered or not-yet-exchanged Site id is **404** on every per-Issuer route
(`routes/wire.php` L33-38). The Issuer is "stable for the life of the Registration" (CONTEXT.md, *Site id*),
but a Site that is revoked and registers again gets a **new** Site id and Issuer (CONTEXT.md, *Registration*
and *Re-registration*). So: cache the Issuer and endpoints per site, and re-discover from the 401 whenever a
cached Issuer 404s.

### 2.4 The authorization request

`GET {issuer}/authorize` is a browser navigation, session-less on the Auth Server, throttled 30/min per source
IP per Site (`routes/wire.php` L47-49; spec Further Notes 1(b) L1568-1574). `AuthorizeController` in order
(L59-116):

| Parameter | Rule | Failure |
|---|---|---|
| `client_id` | required, string | 400 error **page** (L67-69) |
| `redirect_uri` | required, must match the CIMD document | 400 error page — "Until this succeeds the redirect URI is untrusted, so a failure renders a plain error page and never redirects" (L27-31, L75-79) |
| `state` | **optional**; echoed on every later refusal and on success (L82-83, L137-146) | — |
| `code_challenge_method` | must be `S256`; `plain` refused before league sees it (L85-89) | redirect `invalid_request` |
| `code_challenge` | `/^[A-Za-z0-9-._~]{43,128}$/` (L91-95; `Pkce` helper, spec §8 L522-523) | redirect `invalid_request` |
| `scope` | **must be absent**; any value → `invalid_scope` (L97-99; spec §12 L668-671) | redirect |
| `resource` | optional; if present its **origin** must equal the Site's **Registered origin** (L101-106, L119-130; spec §2) | redirect `invalid_target` |
| `response_type` | `code` (league validates, L108-112) | redirect |

Then the request's scalars are parked on a 10-minute Consent row (`ParkedAuthorization.php` L72-92;
`Consent::LIFETIME_MINUTES`; spec §10 L584-586) and the browser is sent to
`{consent_url}?consent_id=…` — only the id, "so a crafted URL cannot put words on the consent screen"
(L114-116; spec §5 L388-390).

**For the gateway:** generate `code_verifier` (43–128 unreserved chars) and `state` per attempt, bind both
to the initiating actor and target site in the gateway's session (the brief's D4, wp-gateway-brief.md
L102-104), and **do not send `scope`** — generic OAuth client libraries that default one must be told not to.
Send `resource` with the site's MCP URL; its origin is what is checked, so any URL under the site's REST origin
passes and the value is "never used to select anything" (L119-122).

### 2.5 What the site owner sees, and what is minted

Consent is a `wp-login.php?action=authwp-consent` screen (spec §15 L863-865). The actor logs in to **their own
WordPress account** on that site (login and consent are two steps on one surface, L882-884); any user who can
create an application password may connect, narrowed only by the `authwp_user_can_connect` filter (L898-909).

The card for a CIMD Client (`class-consent-screen.php` L526-539, L499-510, L403-404; spec §15 L914-915, L948-950):

- Headline: the **`client_id` hostname** — for the gateway, its own host.
- Claim line: *identifies itself as "{client_name}"*.
- Recency line: *Approve only if you just asked {gateway-host} to connect.*
- The role handed over, the Site, the redirect hostname, the unconditional breadth copy ("…and the full
  WordPress REST API as you — anything an Administrator can do"), and that the Connection never expires and
  where to remove it (spec §15 L960-967).

On Approve the plugin reaps that user's never-used rows for the **same** `client_id` (spec §13 L732-734), mints
a core application password named **`{anchor} — via AuthWP`** with `app_id` = **UUIDv5 of the `client_id` in
the URL namespace** (`class-connections.php` L251-259, L284-286, L289-292; `anchor_for()` L552-561), then
writes `authwp_client` = the `client_id` onto the stored item (L263-275). So every gateway Connection on
every site carries the same `app_id` and the same `authwp_client`, and the profile row reads
`unhost.example — via AuthWP`.

The plugin then posts `{decision: "approved", token}` with its Site secret (`ConsentCompleteController.php`
L38-53; token ≤ 512 bytes, L30) and receives a one-time `return_to` under the Issuer (60 s nonce), to which it
redirects the browser (spec §5 L374-380; `contract/consent-complete.json`).

### 2.6 The callback

`ConsentReturnController` spends the nonce, rebuilds the request from the Consent row, and lets league issue the
code (L60-76). The redirect the gateway receives is `{redirect_uri}?code=…&state=…&iss={issuer}` — `iss` is
appended unconditionally, byte-equal to the Issuer (L82; spec §2). Deny yields
`{redirect_uri}?error=access_denied&…&state=…&iss=…` (L77-80; spec §15 L1001-1004). The auth code is
encrypted with the Instance's `APP_KEY`, lives **10 minutes** (`AuthorizationServerFactory.php` L40, L71-74)
and is single-use by affected-row-count `UPDATE` (`TokenController.php` L96-100).

**For the gateway:** verify `state` against the session before anything else; verify `iss` equals the Issuer
the flow started against (RFC 9207; the MCP spec says "clients MUST validate if present", research 01 L14);
treat `error=access_denied` as the actor's Deny and say so.

### 2.7 The token request and response

`POST {issuer}/token`, form-encoded, **no client authentication**. `TokenController` requires
`grant_type=authorization_code` and **all four** of `code`, `code_verifier`, `client_id`, `redirect_uri`
(L77-90) — some client libraries omit `redirect_uri` or `client_id` on the exchange; authwp 400s
(`invalid_request`). `code_verifier` is syntax-checked (L92-94), then S256-verified against the parked
challenge (L115-119). Success is exactly:

```json
{ "access_token": "authwp_…", "token_type": "bearer" }
```

with `Cache-Control: no-store` (`AccessTokenResponse.php` L28-31; `TokenController.php` L141-146; spec §13
L683-687). **No `expires_in`, no `refresh_token`, no `scope`** — league's `BearerTokenResponse` was replaced
because it "adds `expires_in` unconditionally" (`AccessTokenResponse.php` L16-17). A client library that
requires `expires_in` to construct a token must be told otherwise. Errors are JSON 400 `{error,
error_description}` (L160-163). `/token` is deliberately unthrottled (Further Notes 1, L1595-1597).

Whatever league answered, the Auth Server **deletes** its encrypted copy of the token at this point and logs
`issued` or `abandoned` (L46-50, L153-158) — "brief credential custody" ends at `/token` (spec §21 rule 8
L1347-1349).

### 2.8 What the gateway stores

The Access token is `authwp_` + unpadded base64url of `user_login:password`, **split on the first colon**
(spec §6 L399-401; `Bearer_Mapping::encode()`/`decode()` L133-161). The rule "the Auth Server must never decode
the Access token" binds the **Auth Server** (L410-411, §21 rule 7 L1346); the plugin decodes it on every request, and
nothing forbids a Client doing so. The brief's D3 — store `username:app_password`, not the wrapper, so the
gateway keeps working if authwp is deactivated (wp-gateway-brief.md L96-99) — is therefore feasible: decode at
intake, store the pair encrypted, discard the wrapper. Two caveats to record in the ADR: spec §6 says the format
"can evolve without touching the Auth Server" (L400-401), so the decoder is coupled to the plugin's recipe and
should fail loudly on an unrecognised shape; and with the plain pair the gateway authenticates with HTTP Basic,
which core validates identically (spec §17 L1157-1161) — the Bearer wrapper buys nothing once the pair is held.

Nothing on the wire tells the Client **which WordPress user** consented or the Connection's UUID — the Auth
Server never knows and the token response carries nothing else. Core answers both:
`GET /wp/v2/users/me` (user id, roles — the brief's verification step) and
`GET /wp/v2/users/me/application-passwords/introspect`, which returns the authenticated item's `uuid`,
`app_id`, `name`, `created`, `last_used` (WP 7.1.1 `class-wp-rest-application-passwords-controller.php` L62,
L510, L616-617). The `app_id` there will equal UUIDv5(URL namespace, the gateway's `client_id`), a cheap
"this row is ours" check.

### 2.9 Re-consent adds; revocation is a 401

"Add, never replace, no cap": a second consent by the same user for the same Client mints an **additional**
Connection; replace was rejected because "two machines running the same Client share one `client_id`" and
because a consent click must not carry "an invisible destructive side effect" (spec §13 L736-741). For the
gateway that means: never start the flow casually when a grant already exists (the brief's D4 already says so,
wp-gateway-brief.md L105-107); "reconnect" is explicit, and the old row stays in the user's profile until they
revoke it there — the profile UI is "the only selective path" (spec §13 L717-718).

What ends a Connection (spec §13 L706-715): profile-UI revoke (one), Disconnect in plugin settings (all),
Operator revocation of the Site (all, via the daily check, ~24–48 h end to end — `class-revocation.php`
L11-36), plugin uninstall (all), the orphan sweep (never-used only, >24 h). **Deactivating the plugin revokes
nothing** (L720-723). None of these tells the Client anything. The Auth Server keeps "no long-lived token state
and no Connection records" and "needs no revocation feed at all" (spec §10 L595-598). The Client learns on its
next request: core's 401, with the discovery challenge attached "so re-consent is available on the only path
there is" (spec §14 L756-757; user story 40 L119). Renewal "is re-consent on a 401, and always a human click"
(spec §13 L694-696).

**For the gateway:** a 401 on a stored grant is terminal for that grant, not transient — mark it
"needs re-consent", surface it to the actor, and never retry into it.

---

## 3. The 401 challenge, exactly

Attached by `add_filter('rest_post_dispatch', [Discovery::class, 'challenge'], 10, 3)` (`authwp.php` L39) to
**any** `WP_HTTP_Response` whose status is 401 (`class-discovery.php` L121-124) — "including the error response
when authentication short-circuits dispatch, so no custom transport is needed" (L24-27; spec §14 L749-753).

**Serving OAuth** — registered, not revoked, and served from the Registered origin
(`Options::serving_oauth()`, `class-options.php` L105-107):

```
WWW-Authenticate: Bearer resource_metadata="https://blog.example.org/wp-json/authwp/v1/protected-resource?resource=https%3A%2F%2Fblog.example.org%2Fwp-json%2Fmcp%2Fv1"
```

(L145; `prm_url()` L202-206; the reflected `resource` is the origin of `rest_url()` plus `REQUEST_URI`
verbatim, L213-221.) No `scope`, no `realm`, no `error`. The PRM URL is a **plugin REST route**,
`{rest base}/authwp/v1/protected-resource?resource=…` (L67-77), **not** the RFC 9728 host-root well-known —
chosen because it works "on every install topology … with no rewrite rule and no gate" and can reflect both
REST URL forms (L30-45). The route is public (`permission_callback: __return_true`, L102) and answers
`{"resource": <the value passed>, "authorization_servers": [<issuer>]}` for any URL under the site's own REST
base, 404 `authwp_not_a_resource` otherwise (L156-197, L226-239).

The host-root document `/.well-known/oauth-protected-resource{path}` is served **in addition** only when
`home_url()` has no path, path-aware only, with `X-Robots-Tag: noindex` — "redundancy and nothing else"
(`class-well-known.php` L15-45; spec §14 L762-773).

**Installed but not serving OAuth** (unregistered, revoked, or a moved/cloned copy): an ordinary 401 is
**bare** — "there is nothing honest to say to a Client". Only a request carrying exactly
`Authorization: Bearer authwp_probe` gets

```
WWW-Authenticate: Bearer error="invalid_token", error_description="authwp_inactive"
```

(L128-142, constant L90; `Bearer_Mapping::SENTINEL` L49; spec §14 amendment L808-825.) The sentinel is
short-circuited at `rest_authentication_errors` priority 1 and never reaches core's application-password
path, so no failed-login counter moves (`class-bearer-mapping.php` L64-76, L93-103).

**Not installed:** WordPress core "never sends `WWW-Authenticate`" (spec §14 L749; research 04 in map.md
L80). So the gateway's onboarding-form rule — offer the manual path "when the 401 challenge on the site's
MCP endpoint carries no authwp metadata" (wp-gateway-brief.md L108-110) — becomes, precisely:

| Observed on a REST 401 | Meaning | Offer |
|---|---|---|
| `Bearer resource_metadata="…"` | authwp active and registered | the authwp path |
| `Bearer error="invalid_token", error_description="authwp_inactive"` (sentinel only) | authwp installed, not serving OAuth | manual, and say why |
| no `WWW-Authenticate` | no authwp — **or** installed-but-inactive on a non-sentinel request — **or** header stripped outbound | manual |
| 200 with the REST index despite a Bearer header | inbound `Authorization` stripping: no credential will ever authenticate | neither; the site is unusable until the host is fixed |

The four rows are authwp's own probe matrix (`DiscoveryProbe.php` L86-136; spec §14 L796-806). Probing with
the sentinel string is what distinguishes rows 2 and 3; the string "is named by the spec so both sides agree
on it" (`DiscoveryProbe.php` L39-46) and is public, but it is an authwp contract the gateway would be
borrowing — worth a line in the ADR either way.

---

## 4. Spec edges a server-side Client forces

The brief predicted the gateway "will exercise CIMD, consent wording and re-consent behaviour in ways Claude
alone does not. Expect to surface spec edges" (wp-gateway-brief.md L56-57). Found:

### 4.1 No local-development path, on either side

`AddressPolicy` refuses loopback and private ranges with no override (§1.5). Consequences: a local authwp
Instance cannot fetch a CIMD document from `https://unhost.localhost/…` (`AddressRefused` before any
connection, `GuardedFetch.php` L67-71), so the full consent flow cannot be run between two Cove sites; and the
gateway's own guard, if lifted unchanged, refuses every `*.localhost` site. authwp's tests avoid the network
entirely through `FakeTransport`; its README's development section runs `php artisan serve` and does not address
CIMD against a local Client. **Forces**: an environment-gated relaxation in unhost's copy, and an ask to
authwp for the same (or an Operator-preregistered client row for the gateway, which needs no fetch —
`ClientLookup.php` L44-48 — but renders the *preregistered* card and names the redirect host, not the
gateway's, spec §15 L923-937).

### 4.2 The token format is plugin-owned and the Client decodes it

Spec §6 keeps the wrapper's format the plugin's to change (L399-400). The brief's D3 depends on decoding it
(§2.8). Not a conflict today — the recipe is three lines in `Bearer_Mapping::decode()` — but the ADR should
name the coupling, and it is a candidate ask to authwp: promise the `authwp_` + base64url(`login:password`)
shape, or add the pair to the wire. (The pull already carries `client_id` because the plugin needed it — spec
§7 L442-445 — so the wire is amendable when a consumer exists.)

### 4.3 Renewal is browser-mediated and human

No refresh, no expiry, re-consent only on a 401 (spec §13 L683-696; "Deliberately closed branches" L1621-1633
lists refresh tokens and consent-skip as closed). A gateway holding ~100 grants for one actor cannot renew
any of them without that actor visiting each site's consent screen. This is by design ("always a human
click") and the gateway must plan its UI around it: a grants list with a "needs re-consent" state and a
one-click restart per site. Bulk re-consent is not something authwp will offer.

### 4.4 `resource` is origin-checked against the Registered origin

If the gateway sends RFC 8707 `resource` (MCP says clients MUST, research 01 L11), its origin must equal the
origin of the site's `rest_url()` as recorded at Registration (`AuthorizeController.php` L119-130; spec §7
L464-466). A site whose MCP endpoint is on a different origin from its REST base (unusual, but decoupled
installs are "flagged, never refused" — spec §7 L474) would fail with `invalid_target`. Sending the
site's REST-base URL rather than a deeper MCP path is the safe value; omitting it is also accepted (user story
36).

### 4.5 Nothing identifies the user or the Connection to the Client

Covered in §2.8: the token response is two fields; the Issuance log is anonymous by rule (spec §10 L592-593).
Use core's `/wp/v2/users/me` and `…/application-passwords/introspect`. This is not a gap authwp will fill —
naming the WordPress user on the Auth Server side is a rule (§21 rule 7 L1346).

### 4.6 One CIMD document, many Instances, per-Site Issuers

A CIMD `client_id` is portable, so one document serves every Instance and every Site (research 01 L310-313).
But every Site has its own Issuer, metadata URL and token endpoint, and different clients' sites may sit on
different Instances. The gateway's OAuth-client code must be **per-Issuer**, keyed by the Issuer string it
discovered from the 401 (§2.3), and must tolerate an Instance it has never seen. This is ordinary for an MCP
client and unusual for a server-side OAuth client library (which typically configures one provider).

### 4.7 The negative cache is Instance-wide and keyed on the gateway's URL

A single failed fetch of the gateway's CIMD document — a deploy hiccup, a slow response — blocks **every**
authorization against that Instance for 60 s and names the remembered kind on the refusal page
(`CimdNegativeCache.php` L10-31; spec §11 L653-656). With ~100 sites onboarding in a batch this is visible.
Serve the document statically, from the edge if possible.

### 4.8 Bulk registration of Sites is authwp's out-of-scope item, unchanged

"Bulk zero-touch provisioning — scripted plugin installs that auto-register across many sites. A pre-issued
code in the site's configuration file would cover it later without redesigning the redirect flow" (spec Out
of Scope L1532-1533; ticket 07 L32; map.md L110). The gateway as a Client needs none of it; the gateway as an
**Operator** registering ~100 Sites with an Instance (wp-gateway-brief.md L165-166) does, and unhost #1 already
rules that "authwp's side of onboarding" out of this map. Nothing in the Client-side flow changes when that
lands: Registration and Client registration are separate acts (CONTEXT.md, *Registration*).

### 4.9 Small things a client library will trip on

- `token_type` is lowercase `bearer` (RFC 6749 makes it case-insensitive; some libraries compare
  case-sensitively) — `AccessTokenResponse.php` L20-22.
- No `expires_in` (§2.7).
- All five token-request parameters required (§2.7).
- `scope` must be absent (§2.4).
- `iss` on every redirect, including the error one (§2.6).
- `/authorize` errors before client resolution are a 400 **page** in the actor's browser, not a redirect
  (§2.4) — the gateway's "connect" button should open the flow in a way that a dead end is visible.

---

## 5. Recommendations for the `wordpress` module's onboarding seam

So the authwp path "admits … later without redesign" (unhost #7):

1. **Grant row fields the authwp path will need** (beyond `username`, `password`, `source`): the site's
   discovered **Issuer** (nullable), the Connection **`uuid`** and **`app_id`** from introspection, and a
   **`needs_reconsent`** state reached only from a 401. `source ∈ {authwp, manual}` stays a displayed fact
   (the brief's D2).
2. **Site detection** returns one of the four rows in §3, not a boolean; the manual form is offered on rows
   2–3 and the authwp button on row 1.
3. **The Client identity is one static document** at a fixed gateway URL with a path, published from day
   one even before the flow exists — it is the `client_id` and must never move (§2.2, §4.7).
4. **One redirect URI**, `https`, exact.
5. **The outbound guard** is a `gateway`-owned copy of `App\Outbound` with a public `request()` taking a
   method and body, a separate origin-binding check, an environment-gated private-address allow, and
   `AddressPolicy` reused as-is (plus the missing special-purpose ranges). The MCP transport pins through the
   same policy via curl options rather than through `GuardedFetch` (§1.6).
6. **Do not decode the wrapper anywhere but one intake function**, and fail loudly on an unrecognised shape
   (§4.2).

## 6. Asks to authwp (to file there, per #1's rule)

- An environment-gated private-address allow for local development (§4.1).
- A stated commitment to the `authwp_` + base64url(`login:password`) token shape, or the pair on the wire
  (§4.2).
- Whether extracting `App\Outbound` to a shared library is wanted (§1.6); until answered, unhost copies with
  attribution.
