# Unhost — discovery record

**Status:** what was learned and agreed before any code was written, in sessions on 16–18 September 2026.
Everything under *Decisions* was agreed in conversation and is ready to be grilled into ADRs; nothing has
been built. `wp-gateway-brief.md` in the project root is the exploratory conversation that preceded this and
is **superseded** where the two disagree (notably on tenancy and on the gateway being "the product").

## 1. What unhost is

A tool for managing many websites in which **human operators and AI agents are first-class users**. It
replaces Dashbird (`../dashbird.localhost`) feature by feature, built **gateway-first** on Scatterblend so
every feature is reachable by an agent by construction, not as an afterthought.

- **Operator:** the studio, managing ~100 client WordPress sites.
- **Clients:** see a subset of the operator's data — the sites they have hired the studio to work on. A
  client is *not* a tenant with its own instance.
- **Agents:** Claude in the browser (custom connector), Claude Cowork, Claude Code, and any MCP client.
  One connection to unhost; a site connected to unhost once is visible to every agent already connected.
- **The gateway:** the first feature, a pass-through to each site's own MCP (WordPress via RestlessWP today)
  with a **constant tool surface** so 100 connected sites cost no more context than one. It is one module
  among many, not the product.
- **Not WordPress-only:** the site layer must take other platforms later (Cloudflare Pages was the example).

## 2. What was surveyed

| Path | What it is | Relevance |
| --- | --- | --- |
| `../scatterblend` | `nonboxed/scatterblend` — core: accounts, Fortify auth (2FA, passkeys), tenancy, roles→capabilities, dashboard shell with slots, module system with convention tests. Laravel 13, PHP 8.3, `dev-main`. | The base unhost is built on |
| `../scatterblend-oauth` | First-party `oauth` module: Passport wrapped; connections (user × client, remembering tenant), personal tokens with reach, client-credentials **agents**, consent + device grant, `me` route | Inbound OAuth for every agent — already built |
| `../scatterblend-mcp` | First-party `mcp` module: one laravel/mcp server at `/mcp` behind `oauth`; tools declare `#[Capability]`; `whoami`, `switch-tenant`; a registry other modules offer tools into | The MCP server — already built |
| `../scatterblend-search` | First-party `search` module; searchers offered from other modules | A third example of the offer-into-registry pattern |
| `../scatterblend-starter-kit` | Stock Laravel with core installed; `scatterblend:link`/`unlink` to swap in sibling checkouts | How unhost's app skeleton starts |
| `../scatterblend.localhost` | The reference application and the **GitHub tracker** (`alexmansfield/scatterblend`, private, 80 issues); carries `packages/demo` as an in-repo path-package module | Model for in-repo modules; where Scatterblend issues go |
| `../dashbird.localhost` | Laravel 12 predecessor: Client → Sites, users with roles, WordPress REST client, sync/snapshots, DNS, backups, uptime, hosting, `app/Integrations/*`, an in-app module seam and a read-only MCP module (ADRs 0008–0012) | The feature source; the vocabulary Scatterblend's module system grew from |
| `../authwp` | Separate product: an auth broker that hands MCP clients a WordPress application password after a one-click consent on the site | A later onboarding path for client sites; deliberately kept a separate product |
| `laravel/mcp` 0.9 (vendored) | Server *and* **client** (`Client::web($url)`, custom headers, sessions, OAuth discovery/PKCE/DCR); `ToolSearch`/`SearchTools`/`ExecuteTools`; **`listChanged` hardcoded false** | The outbound half of the gateway is in the box; dynamic tool lists are not |

### Scatterblend facts that constrain the design

Read from `resources/boost/skills/scatterblend-development/` and the module sources; each is enforced by a
convention test or a boot error, not just documented.

1. **Modules are Composer packages** whose provider extends `ModuleServiceProvider`; the manifest is
   properties (`key`, `requires`, `capabilities`, `tiers`); bindings in `registerModule()`, everything else
   in `bootModule()`. Inactive is indistinguishable from removed. The application is never a module.
2. **Foreign keys across modules: never.** An indexed, unconstrained id column plus `requires` instead. Keys
   to core's tables (`users`, `tenants`, `memberships`) are fine. Every module table is key-prefixed.
3. **Modules cannot depend on application code** — they can only `require` other modules. So anything
   several modules hang off (a Site) must itself be a module.
4. **Every tenant-owned table carries `tenant_id` even in single mode**, with `BelongsToTenant` (a
   fail-closed global scope). Single mode is "the whole experience, not a special case".
5. **Capabilities are `key:noun:verb`, never take a model; record-level rules live in policies** that call
   capabilities inside. Services take the actor first. Never read a role name, tenant id, `tokenCan` or
   Passport scopes.
6. **Offering into another module** is done from `bootModule()` behind `class_exists(...)`; when the owning
   module is installed but inactive the offer lands in a throwaway registry. Three registries do this today
   (contributions, MCP tools, searchers) — see Scatterblend issue #79.
7. **The MCP registry is static**: filled at boot, validated once on `booted`, instantiating every tool.
   `shouldRegister()` is evaluated per actor per request, but the *list* cannot change at runtime.
8. **Token surfaces never consult the operator-access policy**: a token reaches only tenants its user is a
   member of. (Moot in single mode.)
9. **Vocabulary is strict** and `CONTEXT.md` is the source: *tenant, membership, actor, capability, tier,
   module key, operator, agent, connection (OAuth), contribution, slot*. "Connection" is taken.
10. **API throttling is opt-in**: the application must call `$middleware->throttleApi()`; the `mcp` module
    throttles itself (`throttle:mcp`, 60/min per actor).
11. **Consent skip** (the brief's open item 1) is already decided by the `oauth` module: skipped only while a
    token *and* a connection stand.

### laravel/mcp facts

- Server: `Mcp::web()` mounted by the `mcp` module; `Mcp::oauthRoutes()` gives RFC 8414 discovery and DCR,
  so Claude's custom connector can self-register.
- Client: `Laravel\Mcp\Client::web($url)` with `withHeaders()` (Basic auth works), session ids, 401/403 →
  `AuthorizationRequiredException` with the parsed `WWW-Authenticate` challenge.
- No `notifications/tools/list_changed` support (`listChanged => false` in `Server.php`).
- `ToolSearch` is a local-catalog `search_tools` / `execute_tools` pair — the same shape as Claude Code's
  own deferred-tool mechanism; useful as precedent, not directly reusable for remote catalogs.

### Dashbird facts

- `Site` stores `wp_username` / `wp_app_password` (encrypted) and `WordPressClient` calls REST with Basic
  auth, `allow_redirects.strict`, 30s timeout.
- Roles: `super_admin` (unscoped), `client_owner`, `editor`, `viewer` (client-scoped via a `forUser` query
  scope, ADR 0010). Maps to: operator; a role set; `BelongsToTenant` + a grant policy.
- **Integration-first**: `app/Integrations/{BetterUptime,Cloudflare,GridPane,Namecheap,Plausible,Porkbun,
  Postmark,RocketNet,WpRemote}` each with an `integration.php` manifest (key, label, credentials shape, and
  which contracts it implements: `hosting`, `backup`, `registrar`, `dns`). Feature services define the
  contracts (`UptimeMonitorContract`, `HostingProviderContract`, …). Rule: **credentials belong to
  Integrations, not to features** — one Cloudflare token serves DNS and hosting.
- Its module seam (ADR 0008) is Scatterblend's ancestor; its MCP server (ADR 0012) is a read-only four-tool
  spine over `list_sites` / `get_site` / `list_registered_domains` / `list_snapshots`.
- `dashbird-bridge.zip` is a small WordPress helper plugin (not yet examined in detail).

### RestlessWP

The WordPress-side MCP plugin. Endpoint `https://{site}/wp-json/restlesswp/v1/mcp`, Streamable HTTP, Basic
auth with an application password. Today a handful of sites have this in per-project `.mcp.json` files
(`example-site`, `example-two`, …) — **those files contain live credentials in plain text**; do not
paste them into transcripts or commits. Not yet verified: that laravel/mcp's client and RestlessWP agree on
session handling — a five-minute spike before committing to the proxy.

## 3. Decisions

### D1. Unhost is a Scatterblend application of modules

Starter-kit skeleton with `oauth` and `mcp` active, plus unhost's own modules as **in-repo path packages**
under `packages/{key}` (as `scatterblend.localhost/packages/demo` does), each scaffolded by `make:module`
with its own suite, extracted to its own repository only when a real driver appears. Application code
(role set, contributions under `app`, overrides by key) lives in the published `ScatterblendServiceProvider`.

*Why:* modules are the activation unit people configure; the seam is enforced by core's convention tests,
so later extraction is mechanical ("seam-first, location-later", Dashbird ADR 0008).

### D2. Single tenancy mode; clients are users with grants

One tenant (the studio). Every person — operator, staff, client — is a member with a role from the role set.
Which sites a client sees is a **grant** row (user × site, plus a level), enforced by a `SitePolicy`.
Not multi mode; not tenant = client; not tenant = site.

*Why:* a client sees a subset of the operator's data, not an instance of their own; this drops the need
for `switch-tenant` on the MCP surface entirely (it is not even registered in single mode). Every table
still carries `tenant_id`, so multi mode later is additive.

*Leaning, not settled:* the access **level** (viewer / editor / admin) lives on the grant row rather than
on the membership role, so one client can hold different levels on different sites; the role says what
kind of user you are (operator / staff / client), the grant says which sites and how deep.

### D3. Module layering: feature modules and integration modules

| Module | Owns | Requires |
| --- | --- | --- |
| `sites` | Site record (`platform`, url, status), grants + `SitePolicy`, the **site connector** contract and its registry, Sites pages, `list-sites` tool | — |
| `wordpress` | Integration: RestlessWP MCP client, per-user-per-site application-password credential table, verify via `/wp/v2/users/me`, onboarding form (authwp path later) | `sites` |
| `gateway` | `list-site-tools`, `call-site-tool`, `site-request`; audit table; tool tiers + elevation; outbound origin guard | `sites` |
| `uptime`, `dns`, `backups`, `hosting`, … | Dashbird's features, one each: a **provider contract**, a registry, tenant-owned tables (`site_id` unconstrained, `provider` a string key), sync jobs, dashboard panels, capability-gated tools | `sites` |
| `better-uptime`, `uptime-robot`, `cloudflare`, `gridpane`, … | Integrations: one credential each; implement one or more provider contracts; offer into feature registries behind `class_exists` | nothing hard |

Rules:
- **Features define provider contracts; integrations implement them and own the credential.** Never bundle
  providers into a feature module (Cloudflare serves `dns` *and* `sites`; it can only live in one place).
- **Per-provider, not per-feature, modules** — the unit a person switches on is "Better Uptime", and its
  credential and onboarding UI come with it.
- `gateway` stays separate from `sites` because the pass-through is the concentration risk; "off is
  indistinguishable from removed" is worth having on exactly that surface, and monitoring without a
  pass-through is a legitimate configuration.
- Credentials come in two grains: **account-level** (an API token per tenant per integration) and
  **per-user-per-site** (a WordPress application password, each person's own identity on that site). The
  connector contract lets each integration resolve a credential for `(actor, site)` its own way.

### D4. The gateway's tool surface is constant and site-parameterised

- `list-sites` — sites the actor may reach (in `sites`)
- `list-site-tools(site)` — the site's tools with full `inputSchema`, cached per site
- `call-site-tool(site, tool, args)` — the proxy; one audit row per call
- `site-request(site, method, path, body)` — raw REST for what the plugin does not cover
- later: an `elevate` challenge, bulk variants over a list of sites

*Why:* forced by the static registry and the absence of `listChanged`, and wanted anyway — context cost
is per tool schema, not per site. The model fetches a schema before its first call to a site tool, as
Claude Code's own deferred tools do. If native per-site schemas are ever wanted, `/mcp/sites/{slug}` as a
proxying server per site is additive.

### D5. Vocabulary carried into unhost

From Scatterblend, unchanged: tenant, membership, actor, capability, tier, module, operator, agent,
connection (OAuth only). From Dashbird: **provider contract**, **integration**, registrable apex, site
snapshot. New: **grant** (user × site × level), **site connector** (the `sites` contract an integration
implements for a platform), **tier** in the gateway sense (open vs dangerous classification of a site
tool — *note the collision with Scatterblend's tier; rename before the ADR*), **elevation**.
Avoid: "connection" for anything but OAuth; "proxy", "hub"; "client" as a model name until decided (§5).

### D6. Build order

`sites` → `wordpress` → `gateway`. First demoable slice: add a site by hand with an application password,
see it from `list-sites` in Claude Code through the OAuth flow. Second: the pass-through. Then, as
separate slices: authwp onboarding; elevation; bulk; the first Dashbird feature module with its first
integration.

## 4. Filed elsewhere

- Scatterblend #79 — *Core exposes the offer-into-a-registry pattern as a seam.* Three registries
  re-implement the same behaviour; unhost would add four more.
  https://github.com/alexmansfield/scatterblend/issues/79
- Scatterblend #80 — *A first-party credentials module for integration modules*, to be extracted after
  unhost has two integrations working against their own tables.
  https://github.com/alexmansfield/scatterblend/issues/80

## 5. Open

1. **Is `client` a record?** A name, a contact, a group of sites, billing later (Dashbird's `Client`) — or
   are v1 clients just users with grants? Additive either way; decides whether `sites` gets a
   `sites_clients` table now.
2. **Level on the grant or on the role** (leaning grant; see D2).
3. **Module key names**: `sites` gives `sites:sites:manage`; alternatives `fleet`, `gateway`. Naming of
   the gateway's tool classification (collides with Scatterblend's *tier*).
4. **Elevation** (the brief's D6): a TOTP/passkey unlock page setting `elevated_until` — custom on top of
   Fortify; small but not free. Whether editor-role WordPress credentials are workable day to day (brief
   open item 8) is unchanged.
5. **Outbound origin guard**: `site-request` is an SSRF vector without one; the brief points at authwp's
   guarded-fetch component for reuse.
6. **RestlessWP ↔ laravel/mcp client compatibility** (spike).
7. **Where the packages live**: the core checkouts (`../scatterblend*`) have **no git remotes**; only
   `scatterblend.localhost` is on GitHub. The starter kit expects `github.com/nonboxed/*`, which does not
   exist or is not visible. Path repositories are fine for now.
8. Whether this becomes a distributed product (billing, terms) — the grant table and module activation are
   the hedge that keeps it additive.

## 6. Hazards noted along the way

- All of Scatterblend and laravel/mcp are `dev-main` / pre-1.0 and days old; the module contract is strict
  and its convention tests fail builds. Budget for that discipline.
- Rotating `APP_KEY` orphans every passkey unless `PASSKEYS_USER_HANDLE_SECRET` is set first.
- Core's `api` guard refuses everyone when `oauth` is inactive — a 401, not a 500, but easy to misread.
- Per-project `.mcp.json` files in sibling directories hold live Basic-auth headers.
