# WordPress fleet gateway — handoff brief

**Status:** conclusions from a design conversation on 16–17 Sep 2026. Nothing here is a settled decision.
Treat every item under *Proposed decisions* as a proposal with its argument attached, to be confirmed
(or grilled) before it becomes an ADR. *Open items* are genuinely undecided.

**Read alongside:** `~/Cove/Sites/authwp/docs/spec.md` and `docs/CONTEXT.md`. This project reuses
authwp's vocabulary where the concepts coincide (Client, Site, Connection, Access token, Consent) and
must not redefine them.

---

## 1. Problem

The operator manages ~100 WordPress sites and wants Claude to work on them from both claude.ai (browser,
OAuth custom connector) and Claude Code, without one MCP connection per site and without being tied to a
project folder. Clients should eventually be able to reach their own sites the same way.

Today: a handful of sites have a RestlessWP MCP endpoint in a per-project `.mcp.json` with Basic auth and
an application password in `.env`. Most sites have no application password issued at all.

## 2. Shape

One hosted **Gateway**: a remote MCP server (Streamable HTTP) behind OAuth 2.1, registered once as a
custom connector in claude.ai and once with `claude mcp add --transport http --scope user` in Claude
Code. Claude never talks to a site directly. Every tool takes a `site` argument; the Gateway resolves it
against the caller's grants, attaches the stored credential, and proxies to the site's REST API.

Why a gateway and not 100 connectors: context cost is per tool schema, not per site. A gateway with a
`site` parameter keeps the tool list constant as the fleet grows. Claude never sees a credential.

## 3. Relationship to authwp

**Overlap is vocabulary and research, not architecture.** authwp is deliberately the opposite shape on
every load-bearing property:

| authwp Auth Server | Gateway |
| --- | --- |
| Holds no credentials | Is a credential store |
| Never in the request path (broker, not gatekeeper) | Is the request path (gatekeeper by definition) |
| Ships no MCP server, no tool surface | Is the tool surface |
| One Connection = one Client, one WP user, one Site | One connector fans out to many sites |
| Operators are peers, no isolation (rejected as "a hosted multi-tenant service, a different product") | Per-user grants — i.e. exactly that product |
| Scopes, read-only agents, allowlists: closed branches | Roles and a tool allowlist per role |
| Issuance log is deliberately anonymous | Audit log names the user on every call |

**Do not bend authwp toward the Gateway.** Its closed branches would all reopen and its three properties
would erode. Two products, one relationship:

- **The Gateway is an authwp Client.** It onboards client sites by running the authwp consent flow
  (CIMD identity — its own URL is the `client_id`; PKCE S256 as a public client, the only kind authwp
  constructs). The consent card names the Gateway's hostname.
- **For a client with one or two sites, authwp alone is the better answer** (paste site URL into
  claude.ai, consent, done — no intermediary holds their credential). The Gateway earns its keep at the
  operator's scale.
- The Gateway becomes authwp's first server-side Client and will exercise CIMD, consent wording and
  re-consent behaviour in ways Claude alone does not. Expect to surface spec edges.

authwp's *bulk zero-touch provisioning* (out of scope today; sketched as a pre-issued code in the site's
config) becomes relevant when registering ~100 sites with an Instance.

## 4. Proposed decisions

### D1. Implementation base: Laravel Passport + laravel/mcp
Every objection authwp's ticket 12 recorded against Passport inverts for the Gateway, because the Gateway
restores the textbook OAuth arrangement (one app authenticates users, issues its own tokens, validates
them, serves the resource):

- Passport signs JWTs / wants an RSA key → the Gateway is its own issuer; key from env vars.
- Passport's authorize controllers require a Laravel-authenticated resource owner → the Gateway's
  resource owner *is* a Laravel user consenting on the Gateway.
- Refresh tokens always on → wanted: short-lived access tokens with rotation.
- Five tables, persisted access tokens → wanted: revocation and audit join to the token table.
- No RFC 8414 / DCR → laravel/mcp's `oauthRoutes()` supplies both; its "single issuer" and "Laravel is
  the protected resource" assumptions are *true* here.
- No ResourceServer → Passport's api guard validates tokens on every MCP call for free.

Settings to make consciously: **S256 only** (Passport accepts `plain`); **decide the consent-skip
behaviour** (Passport skips re-consent for an already-approved client; authwp rejected this; for the
Gateway probably acceptable but a Gateway token covers many sites); **leave OAuth scopes unused** —
roles live on the grant, not the token.

Passport is inbound only. When the Gateway runs the authwp flow it is an OAuth *client*; plain HTTP.

Hosting follows Laravel (wherever authwp runs), not Cloudflare Workers.

### D2. Data model: grants, not tenants
One table: `user, site, role, credential (username + app password, encrypted), source, created_at`.
Operator holds a grant on every site; a client holds grants on theirs; a contractor can hold one for a
month. No org hierarchy. Roles: read / write / admin, each with a tool allowlist enforced by the Gateway
(so a write-role client cannot install plugins even if their WP account could). `source` ∈ {authwp,
manual} is a displayed fact; nothing permissions on it.

Build the single-user version with this table from day one (one row per site for the operator). Client
access is then real user accounts + more rows, not a rewrite.

### D3. Credentials: store the plain pair, call sites with Basic auth
Whatever the onboarding path, store `username:app_password`, not the `authwp_` wrapper, so the Gateway
keeps working if authwp is deactivated on a site. Both onboarding paths converge on identical rows.

### D4. Onboarding: two paths, no seeding, no admin-mint
- **Connect site (authwp):** user clicks in the Gateway → authwp flow for that site → user logs in to
  *their own* WP account → app password minted on that user → stored against (user, site). The Gateway
  ties the returning redirect to the initiating Gateway user via `state` + session. Many users behind one
  Client is the ordinary OAuth case (Claude is the proof). Do **not** start the flow when a grant already
  exists (authwp creates a new Connection on re-consent rather than replacing); offer explicit
  "reconnect".
- **Enter credentials (manual):** for sites without authwp. User creates the app password in their WP
  profile and pastes username + password. Gateway verifies against `/wp/v2/users/me` and stores the
  returned user id and role. Show this option when the 401 challenge on the site's MCP endpoint carries
  no authwp metadata.
- **Rejected for now:** seeding from existing `.env` files (only a handful exist); minting on a client's
  user with the operator's admin credential (works, but the client never consented — documented
  exception at most). WP-CLI over SSH can bulk-create the operator's own app passwords as a one-time
  script if all 100 are wanted at once; not a product feature.
- The operator's own sites connect **on demand**, first time each is needed.

### D5. Tool surface
Small and site-parameterised: list/search sites; call a RestlessWP MCP tool on a site (proxy); raw REST
request to a site; health check across sites; **bulk variants** taking a list of site slugs (one call, one
elevation check, one audit entry, one result table). Undecided: proxy RestlessWP's MCP tools (less code,
one source of truth, couples to the plugin) vs wrap the REST API directly.

### D6. Step-up authentication for a dangerous tier
- Tier the tools. Reads and content edits: open. **Dangerous:** plugin/theme install/activate/update,
  user and role changes, site options, file/code writes, deletions, anything bulk.
- Each Gateway user has `elevated_until`. Dangerous tools check it; if lapsed, return a structured
  error with an **unlock URL** (Claude relays it). The unlock page (browser session, already logged in)
  requires a **TOTP code or passkey — not the password** — and sets `elevated_until = now + window`
  (default 30 min, longer selectable on the page for maintenance sessions).
- Elevation is **per Gateway user, not per site**: installing a plugin across 100 sites is one unlock.
  WordPress never sees any of it.
- What it protects: a leaked MCP token (fully — a token can never satisfy the unlock page), a stolen
  session cookie (the page demands a factor), a stolen password (login needs 2FA; unlock needs TOTP).
  What it does not: server/database compromise (attacker sets the timestamp); password + TOTP together
  (that is the user).
- Do not rely on: a model-set `confirm` argument (accidents only, not attackers — fine as a second rail);
  MCP elicitation (unverified in claude.ai; the out-of-band URL works with every client).
- TOTP enrolment is **required** for the dangerous tier (unlike authwp, where it is optional).

### D7. Security posture
The Gateway is the concentration risk authwp was built to avoid — accepted deliberately in exchange for
one connector. Delta vs today is *exposure* (public, always-on, programmatic), not key count. MainWP /
WP Remote are the same shape with full admin on child sites and no re-auth; the norm is weaker than this.

Two tiers: compromised account/token → that user's grants until token expiry; compromised server →
everything (encryption at rest only helps against leaked backups/dumps, not a live compromise).

Bounds, in order of leverage:
1. **Least privilege on the WordPress side** — app passwords on a user with the smallest role the work
   allows (Editor, not Administrator, where possible). This is authwp's named narrowing mechanism.
2. **Rehearsed revocation runbook** — delete the Gateway's app password on every site; per-user from WP
   profile, fleet-wide via WP-CLI over hosting access. Do not rely on the Gateway to revoke itself
   during an incident. Revocation lives in WordPress and kills access immediately regardless of Gateway
   state (property inherited from authwp).
3. **Gateway hardening** — 2FA on login; short access tokens + refresh rotation; console behind IP
   allowlist/VPN; **outbound requests restricted to registered site origins** (a URL-fetching proxy is
   an SSRF vector; reuse authwp's guarded-fetch component).
4. **Audit log per tool call** — user, site, tool, args, timestamp. Turns "breached" into "these calls
   were made".

## 5. Open items

1. Consent-skip behaviour under Passport (see D1).
2. Bulk registration of ~100 sites with an authwp Instance — the real bottleneck, not credentials.
   Revisit authwp's pre-issued-code sketch.
3. Proxy RestlessWP MCP tools vs wrap REST directly (D5).
4. Whether claude.ai supports MCP elicitation (would allow inline confirm; not load-bearing).
5. Whether an app-password-authenticated REST request can delete app passwords in core (affects whether
   the Gateway can revoke its own credential at all; runbook assumes not).
6. Whether this becomes a product (billing, terms, support). Multi-tenant is a different build than
   single-user; the grant table is the hedge that makes it additive.
7. Naming. "Gateway" is a working title.
8. Whether Editor-role credentials are workable for the operator's day-to-day on most sites, or whether
   admin stays the default and step-up carries the load.

## 6. Vocabulary (new terms; everything else per authwp CONTEXT.md)

- **Gateway** — the hosted MCP server + OAuth server + credential store. One instance, many users, many
  sites. It is a *Client* of authwp and a *Resource* to Claude. _Avoid_: proxy, hub, auth server.
- **Grant** — one (user, site, role, credential) row. The unit of access. _Avoid_: connection (authwp
  term), permission, link.
- **Elevation** — a Gateway user's time-boxed unlock of the dangerous tier. _Avoid_: sudo (authwp's
  console term), session.
- **Tier** — open vs dangerous classification of a tool. _Avoid_: scope, capability.
