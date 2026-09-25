# Research: Dashbird's Site, Client, roles and WordPress client

Resolves [#8](https://github.com/alexmansfield/unhost/issues/8), part of the wayfinder map [#1](https://github.com/alexmansfield/unhost/issues/1).

**Question.** What in Dashbird's site, client and access model should carry into unhost's `sites` and `wordpress` modules, and what should be left behind?

**Sources.** All primary, read on 2026-09-21. Paths below are relative to `~/Cove/Sites/dashbird.localhost/` (Dashbird at `main` = `99b2911796d8`, Laravel `^12.0`) unless prefixed. RestlessWP is `example.localhost/public/wp-content/plugins/restlesswp` at 0.9.11; WordPress core there is 7.0.1. `dashbird-bridge.zip` was unzipped into the session scratchpad, never into this repo. No `.env` file was opened.

## Answers

- **The `Site` record.** 17 columns (see table): identity (`name`, `url` as a normalized bare host), three nullable FKs (`client_id`, `registered_domain_id`, `server_id`), the encrypted `wp_username`/`wp_app_password` pair, four feature remote-ids (`plausible_site_id`, `better_uptime_monitor_id`, `wpremote_site_id`, `wpremote_connection_status`), and five sync flags (`is_paused`, `last_sync_succeeded`, `has_bridge`, `has_authenticated_api`, `last_synced_at`). There is **no `platform` column** — Dashbird is WordPress-only. `connection_status` is derived, never stored: `paused` / `limited` / `disconnected` / `connected`. The credential is `Crypt::encryptString` at rest and used as HTTP Basic auth with `https://` forced, Guzzle `allow_redirects.strict`, 30 s timeout. Carry: `name`, `url`, a user-controlled paused state, the derived-status pattern. Leave behind: every feature remote-id column, the bridge flag, and the credential columns (they move to the `wordpress` module's credential table).
- **The `Client` record.** `id`, `name`, `slug` (unique, auto from name), `created_by`, timestamps — **nothing else**: no contacts, no billing, no address. Sites attach by nullable `sites.client_id`; users by nullable `users.client_id`; client-scoped integration credentials by `integration_credentials.client_id`. `ClientPolicy` exists but only gates who may rename it and manage its members. Dashbird's `Client` is purely a tenancy key with a label — under D2 (grants) that job is done by grant rows, so **v1 needs no client record**; a grouping label can be added additively later.
- **Roles.** `super_admin` is unscoped and the only role that can touch providers/hosts/servers/registrars; `client_owner` manages its client's sites, members and WP users; `editor` may create/update/trigger-update sites but not delete; `viewer` reads. `Site::forUser` = unscoped for `super_admin`, else `where('client_id', $user->client_id)`. Maps onto Scatterblend as: `super_admin` → a **staff role holding every capability** (not the operator flag, which is bootstrap-only in single mode); `client_owner`/`editor`/`viewer` → **one client role** in the role set plus a **grant level** per site (viewer/editor/admin). Hazard: Dashbird's `null === null` client match leaks unassigned sites to client-less users; grants have no such trap.
- **`WordPressClient`.** 15 public methods over `wp/v2/{plugins,themes,posts,pages,comments,users}`, `wp-site-health/v1/*`, the root index, and two `dashbird/v1/*` bridge routes. Error handling is "swallow and return empty/false/null + `Log::warning`"; transport exceptions propagate except in the two `test*` probes. It is the precedent for `site-request`: stored Basic credential, forced `https`, strict redirects, fixed timeout, `X-WP-Total` pagination. Two latent bugs: `getSystemInfo` reads a `version` key the WP root index never returns, and `update` info from `wp/v2/plugins` is always null (core has no such field) — only the bridge supplies it.
- **`dashbird-bridge`.** A 123-line mu-style plugin adding `GET dashbird/v1/updates` (core/plugin/theme update transients, including non-wordpress.org sources) and `POST dashbird/v1/update-plugin` (runs `Plugin_Upgrader` with a transient lock, self-update blocked), both gated on `update_plugins && install_plugins`. **RestlessWP 0.9.11 does not make it redundant** — RestlessWP has no updates/upgrade module at all (modules: ACF, ACSS, Etch, GF, SRM, TSF, HappyFiles). It is redundant *for the gateway-first slice* (no update tooling there); a future "updates" feature module would either absorb it as a RestlessWP module or keep it as a second plugin.
- **Integration manifest.** `app/Integrations/<Name>/integration.php` returns `['key' => …, 'label' => …]` plus optional `'credentials' => [[name,label,type],…]` and/or one class-string per contract implemented: `hosting`, `backup`, `registrar`, `dns`. `IntegrationResolver` globs the manifests, exposes `hosting()/backup()/registrar()/dns()/resolve($feature,$key)/credentialFields($key)/features($key)`, and falls back to the provider class's static `credentialFields()` when the manifest has none. This is the provider-contract seam: **one integration, many contracts, credentials owned by the integration**.

## 1. The `Site` record

### 1.1 Schema (final state after all migrations)

| Column | Type | Purpose | Recommendation | Reason |
|---|---|---|---|---|
| `id` | bigint PK | — | keep | — |
| `name` | string | display label | **keep** | identity |
| `url` | string | normalized host: lowercased, scheme and `www.` stripped, no trailing slash (`app/Support/NormalizesDomain.php:7-15`, mutator `app/Models/Site.php:211-214`, backfill `database/migrations/2026_04_23_100001_normalize_site_urls.php`) | **keep the column, drop the normalization** | `WordPressClient` has to re-add `https://` (`WordPressClient.php:20-24`); a `platform`-agnostic site should store its canonical origin URL and derive the bare host for matching |
| `client_id` | FK `clients`, nullable, `nullOnDelete` | tenancy key (`Site::forUser`, `SitePolicy`) | **leave behind** | replaced by grant rows (discovery D2); was `cascadeOnDelete` non-null at creation (`2024_01_01_000005:15`), relaxed 2026-02-08 (`2026_02_08_030007`) so sites can exist unassigned |
| `registered_domain_id` | FK `registered_domains`, nullable | link to the Registrable Apex (ADR 0006) | **leave behind** | belongs to a domains/DNS feature module; cross-module FK is forbidden in Scatterblend |
| `server_id` | FK `servers`, nullable | link to hosting server (`Server` → `Host` → `Integration`) | **leave behind** | belongs to the hosting feature module |
| `wp_username` | text, nullable, encrypted | Basic-auth user | **move to `wordpress` credential table** | discovery D3: per-user-per-site credential; a site is not its credential |
| `wp_app_password` | text, nullable, encrypted | Basic-auth application password | **move to `wordpress` credential table** | same |
| `plausible_site_id` | string, nullable | Plausible remote id | leave behind | feature remote-id; the later `backup_sources` pattern (`site_id` + `remote_id` in the feature's own table, `2026_04_25_075054_create_backup_sources_table.php`) is the shape to copy instead |
| `better_uptime_monitor_id` | string, nullable | Better Uptime remote id | leave behind | same |
| `wpremote_site_id` | string, nullable | WP Remote remote id (`2026_04_22_000001`) | leave behind | same |
| `wpremote_connection_status` | string, nullable | WP Remote's own status (`2026_04_22_100000`) | leave behind | same |
| `is_paused` | bool, default false | user-controlled "stop monitoring" (`2026_02_08_074758:13-14`) | **keep as the one user-set status** | it is the only status input a human sets; everything else is derived |
| `last_sync_succeeded` | bool, nullable | system: last sync result | move to the `wordpress` credential/verification record | it describes the credential's last use, not the site |
| `has_bridge` | bool, default false | sync detected an active `dashbird-bridge` plugin (`SiteSyncService.php:226-237`) | leave behind | bridge is out of the slice (§5) |
| `has_authenticated_api` | bool, nullable | null = unknown, false = reachable but auth failed, true = ok (`2026_04_30_100001`) | move to the `wordpress` credential/verification record | same as `last_sync_succeeded` — it is the outcome of `testAuthentication()` |
| `last_synced_at` | timestamp, nullable | last successful sync | move to the feature module that syncs | a gateway site has no sync |
| `created_at`/`updated_at` | timestamps | — | keep | — |

Dropped along the way: `is_active` (replaced by `is_paused` + `last_sync_succeeded`, `2026_02_08_074758:22-43`).

Related tables hanging off `sites` (all feature-module material, all leave-behind for the slice): `site_snapshots` (`wp_version`, `php_version`, `plugins` json, `themes` json, post/page/comment counts, `failed_logins`, backup status trio, `captured_at`; `2024_01_01_000007`, `2026_04_22_000001:15-19`), `site_taxonomy_value` (tags, `2024_01_01_000006`), `remote_sites` (hosting discovery, `2026_04_23_100002`), `backup_sources` (`2026_04_25_075054`), `site_wordpress_user` pivot (`remote_id`, `role`, `last_synced_at`; `2026_04_30_000002`), `dns_zones`, `notifications`.

### 1.2 Statuses

Connection status is a derived accessor, never a column (`app/Models/Site.php:55-72`, restated in ADR 0012 §2):

```
is_paused                    → 'paused'
has_authenticated_api === false → 'limited'   (reachable, auth failed)
last_sync_succeeded !== true → 'disconnected' (never synced, unreachable, or failed)
otherwise                    → 'connected'
```

`scopeWithConnectionStatus` mirrors the same precedence in SQL for filtering (`Site.php:94-114`). `SiteSyncService::sync` is the only writer of the three inputs (`SiteSyncService.php:17-42, 81-86`): no credentials → `last_sync_succeeded=false`; `testConnection()` fails → `last_sync_succeeded=false, has_authenticated_api=null`; `testAuthentication()` fails → `has_authenticated_api=false, last_sync_succeeded=false`; success → all three true/now.

**Carry:** the pattern (one human-set flag + system-set probe outcomes → derived status). **Change:** the probe outcomes belong on the `wordpress` credential record, since in unhost the credential is per user per site, not per site.

### 1.3 `platform`-like fields

None. The nearest things are the per-integration remote-id columns and the `server_id → Server → Host → Integration` chain (`app/Models/Integration.php:33-51`). `platform` on unhost's Site is new; Dashbird offers no precedent for its values.

### 1.4 How the WordPress credential is stored and used

- **At rest:** `wp_username` and `wp_app_password` are `text` columns; the model's accessors/mutators call `CredentialEncryption::encrypt/decrypt`, which is `Crypt::encryptString/decryptString` — i.e. `APP_KEY` AES with Laravel's encrypter, per value (`app/Models/Site.php:237-256`, `app/Services/CredentialEncryption.php:9-17`). `IntegrationCredential` uses the same helper for `token` and a JSON `credentials` blob (`app/Models/IntegrationCredential.php:40-58`). `hasCredentials()` requires both non-empty (`Site.php:258-261`).
- **Entry:** the site form takes `wp_user` / `wp_secret` (`max:255`, nullable) and writes them through the mutators; on update an empty `wp_secret` keeps the old password unless `wp_user` is also cleared, in which case both are nulled (`app/Http/Controllers/SiteController.php:59-85, 143-216`). Nothing verifies the credential at save time — verification happens on the next sync.
- **In flight:** `WordPressClient::__construct` builds `Http::withBasicAuth($site->wp_username, $site->wp_app_password)->withOptions(['allow_redirects' => ['strict' => true]])->timeout(30)` after forcing an `https://` scheme onto the bare host (`app/Services/WordPress/WordPressClient.php:16-34`).
- **What `allow_redirects.strict` actually does** (Guzzle 7.15.5, `vendor/guzzlehttp/guzzle/src/RedirectMiddleware.php:170-180`): keeps the request method on 301/302 instead of downgrading to GET. It is *not* what protects the credential — Guzzle strips `Authorization` and `Cookie` on any cross-origin redirect regardless of `strict` (`RedirectMiddleware.php:219-222`). The code comment's "avoid auth-stripping redirects" (`WordPressClient.php:20-21`) is achieved by forcing `https://`, so no `http→https` hop happens; `strict` just keeps POSTs as POSTs. Both are worth keeping in `site-request`; the rationale should be stated correctly.
- **Modern equivalent:** Laravel's `encrypted` cast does what the two accessor pairs do by hand.

### 1.5 Where Dashbird's Site is read from MCP

`ListSites` projects `id, name, url, registrable_apex, connection_status, last_synced_at, host, client` (`app/Modules/Mcp/Tools/ListSites.php:57-66`); `GetSite` adds `is_paused`, the latest snapshot and latest backup source (`GetSite.php:21-40`). The `client` filter is operator-only and silently ignored for customers (`ListSites.php:29-33`). Not-found and forbidden are the same error (`GetSite.php:23-27`, ADR 0009).

## 2. The `Client` record

### 2.1 Schema

| Column | Type | Purpose | Recommendation | Reason |
|---|---|---|---|---|
| `id` | bigint PK | — | — | — |
| `name` | string | label | only if a client record exists at all | — |
| `slug` | string, unique | URL key, auto-generated from `name` on create (`app/Models/Client.php:21-28`) | leave behind | nothing routes on it in the gateway |
| `created_by` | FK `users`, nullable, `nullOnDelete` | who created it | leave behind | audit belongs to the audit table |
| timestamps | — | — | — | — |

Source: `database/migrations/2024_01_01_000001_create_clients_table.php:11-17`, never altered since. No contact, billing, address, notes or status fields anywhere (`grep -riE 'billing|contact'` over the model, controller and views returns nothing). The controller validates only `name` and `slug` (`app/Http/Controllers/ClientController.php:32-34, 71-73`).

### 2.2 How things attach

- `sites.client_id` — nullable FK (§1.1). `Client::sites()` is `hasMany` (`Client.php:40-43`).
- `users.client_id` — nullable FK added with `role` (`database/migrations/2024_01_01_000002_add_role_client_to_users_table.php:12-13`). A user belongs to at most one client; `super_admin` users are forced to `client_id = null` (`app/Http/Controllers/UserController.php:79-82`).
- `integration_credentials.client_id` — nullable; null means a global (operator) credential, and `IntegrationCredential::forService` resolves integration-specific → client-specific → global (`app/Models/IntegrationCredential.php:65-100`).

### 2.3 What permissions on it

`ClientPolicy` (`app/Policies/ClientPolicy.php`): `viewAny/create/delete` → `super_admin` only; `view` → own client for any role; `update`/`manageMembers` → `super_admin` or the client's `client_owner`. `User::canManageClient()` duplicates `update` (`app/Models/User.php:76-83`). So the Client record is a permission *boundary* (every tenanted policy compares `client_id`) but carries no permissioned *content* of its own beyond its name and member list.

### 2.4 Evidence for "is `client` a record?" (discovery §5 open item 1)

Dashbird's `Client` does exactly one job: it is the value that `users.client_id` and `sites.client_id` are compared against. It holds no data anyone would want to read back. Under discovery D2 (one tenant, clients are users with grants), the same job is done by grant rows (user × site × level) with no intermediate record. So the honest reading of the predecessor is that **`client` is not a record in v1**. What would justify one later — and each is additive:

- a label to group sites in the Sites UI (Dashbird also had free-form taxonomies for that, `site_taxonomy_value`);
- client-scoped integration credentials (Dashbird's `integration_credentials.client_id`) — but discovery already moves credentials to Integrations and Scatterblend #80;
- billing/terms — explicitly out of scope on the map.

**Hazard to carry into the grant design:** because both `users.client_id` and `sites.client_id` are nullable and `UserController` only forces null for `super_admin` (`UserController.php:71, 79-82`), a non-admin user can exist with no client. `SitePolicy::view` then passes on `null === null` (`app/Policies/SitePolicy.php:21`), and `Site::forUser` becomes `where('client_id', null)` → `IS NULL`, returning every unassigned site (`Site.php:128`). A grant table (explicit rows) cannot have this failure mode; a `client_id`-style column can.

## 3. Roles and `forUser`

### 3.1 The role set

`users.role` is an enum `['super_admin','client_owner','editor','viewer']`, default `viewer` (`2024_01_01_000002:12`); constants and `isX()` helpers on `User` (`app/Models/User.php:17-23, 56-74`); `canEditSites()` = the first three (`User.php:85-92`).

### 3.2 Role → permission matrix

Read from the policies; ✓ = allowed, "own" = only when `user.client_id === model.client_id` (or, for WP users, when the WP user has a site in that client).

| Ability | `super_admin` | `client_owner` | `editor` | `viewer` | Source |
|---|---|---|---|---|---|
| Site: viewAny | ✓ | ✓ | ✓ | ✓ | `SitePolicy.php:10-13` (rows narrowed by `forUser`) |
| Site: view | ✓ | own | own | own | `SitePolicy.php:15-22` |
| Site: create | ✓ | ✓ | ✓ | ✗ | `SitePolicy.php:24-27` |
| Site: update / triggerUpdate | ✓ | own | own | ✗ | `SitePolicy.php:29-40, 51-54` |
| Site: delete | ✓ | own | ✗ | ✗ | `SitePolicy.php:42-49` |
| Client: viewAny / create / delete | ✓ | ✗ | ✗ | ✗ | `ClientPolicy.php:10-13, 24-27, 38-41` |
| Client: view | ✓ | own | own | own | `ClientPolicy.php:15-22` |
| Client: update / manageMembers | ✓ | own | ✗ | ✗ | `ClientPolicy.php:29-36, 43-50` |
| User: viewAny / create / invite | ✓ | ✓ | ✗ | ✗ | `UserPolicy.php:9-12, 27-35` |
| User: view / update | ✓ | own client | self | self | `UserPolicy.php:14-25, 37-48` |
| User: delete (never self) | ✓ | own client | ✗ | ✗ | `UserPolicy.php:50-63` |
| User: changeRole | ✓ | own client | ✗ | ✗ | `UserPolicy.php:65-73` (the "not to super_admin" restriction in the comment is enforced by the controller's role list, not the policy) |
| WordPressUser: viewAny | ✓ | ✓ | ✓ | ✗ | `WordPressUserPolicy.php:10-13` |
| WordPressUser: view | ✓ | own | own | own | `WordPressUserPolicy.php:15-22` |
| WordPressUser: create / bulkManage | ✓ | ✓ | ✗ | ✗ | `WordPressUserPolicy.php:24-27, 51-54` |
| WordPressUser: delete (never if locked) | ✓ | own | ✗ | ✗ | `WordPressUserPolicy.php:29-44` |
| WordPressUser: lock | ✓ | ✗ | ✗ | ✗ | `WordPressUserPolicy.php:46-49` |
| Host / Server / DnsProvider / Registrar / BackupProvider: everything | ✓ | ✗ | ✗ | ✗ | `HostPolicy.php`, `ServerPolicy.php`, `DnsProviderPolicy.php`, `RegistrarPolicy.php`, `BackupProviderPolicy.php` (all five are `isSuperAdmin()` on every method) |

Two things the matrix shows: (a) **provider/infrastructure objects are operator-only without exception**; (b) the customer roles differ only in *depth* on the same client-scoped rows — `viewer` reads, `editor` edits sites, `client_owner` additionally deletes sites and manages people. That is exactly a per-site *level*, which is the discovery's leaning for the grant row.

### 3.3 How `forUser` scopes queries (ADR 0010)

- `Site::scopeForUser`: unscoped for `super_admin`, else `where('client_id', $user->client_id)` (`Site.php:122-129`).
- `RegisteredDomain::scopeForUser`: relationship-derived — a customer sees an apex iff every site under it is theirs (`whereHas` ∧ `whereDoesntHave`, `app/Models/RegisteredDomain.php:66-78`, ADR 0011).
- `Notification::scopeForUser`: `super_admin` sees `user_id IS NULL OR user_id = me`; others `user_id = me` (`app/Models/Notification.php:42-52`).
- Callers: `DashboardController:15,41`, `PluginController:15`, `WordPressUserController:52`, `NotificationController:13,59`, `LayoutComposer:48,53`, and the MCP tools `ListSites:27`, `ListRegisteredDomains:24` — one implementation shared by UI and MCP (ADR 0009 §"Collections delegate to query scopes").
- Deliberately a local scope, not a global one, so the unscoped operator path is the explicit call rather than a forgotten bypass (ADR 0010 "Considered options"; `CONTEXT.md:31-33`).

### 3.4 Mapping onto Scatterblend's role set + a grant policy

Scatterblend's vocabulary (`scatterblend.localhost/CONTEXT.md`): *tenant* (never "client"), *membership* (role travels with it), *operator* (platform admin, "avoid: super admin"), *capability* `key:noun:verb`, *tier*, *role set* (core defines no role names), single *tenancy mode*.

| Dashbird | unhost | Note |
|---|---|---|
| `super_admin` (unscoped, all providers) | a **staff/admin role** in the role set holding every `sites:*`/`wordpress:*`/`gateway:*` capability; the Scatterblend *operator* flag remains bootstrap/gating only in single mode (`CONTEXT.md:128-130, 174-176`) | Dashbird's "unscoped" is *not* operator in Scatterblend's sense — it is "sees every row in the one tenant", which in single mode is just a role with the capabilities and no grant restriction |
| `client_owner` / `editor` / `viewer` | **one `client` role** (capabilities: `sites:sites:view` and whatever the gateway needs) + a **grant** row `user × site × level` where level ∈ {viewer, editor, admin} | the three Dashbird roles collapse to one membership role because the only thing distinguishing them is depth on the same rows — which is the grant level |
| `Site::forUser($user)` | `SitePolicy` + a `forUser`-style scope that becomes `whereHas('grants', user_id = me)` unless the actor holds an unscoped capability | keep ADR 0010's explicit-scope rule; the row filter changes from a column match to a grant join |
| `SitePolicy::view` (`client_id ===`) | `SitePolicy::view` → grant exists (any level) | never a nullable column compare (§2.4 hazard) |
| `SitePolicy::update` (owner/editor own) | grant level ≥ editor, or an unscoped capability | — |
| `SitePolicy::delete` (owner own) | grant level = admin, or unscoped | — |
| `ClientPolicy::manageMembers` | not needed in v1 (no client record); staff manage grants via `sites:grants:manage` | — |
| provider policies (`isSuperAdmin()` everywhere) | integration/provider capabilities granted only to the staff role | direct carry-over |

What must *not* carry: `role` as a column on `users` (Scatterblend keeps role on membership), `client_id` on users, and any `isSuperAdmin()` call inside application code (Scatterblend's rule is capabilities only — `discovery.md:53`).

## 4. `WordPressClient` (`app/Services/WordPress/WordPressClient.php`)

### 4.1 Construction

Lines 16-34: takes a `Site`; forces `https://` if the normalized host has no scheme; `rtrim('/')`; `Http::withBasicAuth(username, password)`, `allow_redirects.strict = true`, `timeout(30)`. No retry, no `throw()`, no user-agent, no per-call override.

### 4.2 Methods

| Method | HTTP | Route | Returns | On failure |
|---|---|---|---|---|
| `getPlugins()` :36 | GET | `/wp-json/wp/v2/plugins` | mapped `[plugin,name,version,status,update,author,requires_wp,requires_php]` | non-2xx → `Log::warning`, `[]` |
| `getThemes()` :62 | GET | `/wp-json/wp/v2/themes` | mapped `[stylesheet,name,version,status,update,author]` | non-2xx → warning, `[]` |
| `getSiteHealth()` :86 | GET | `/wp-json/wp-site-health/v1/tests/background-updates` | raw json | `[]` (unused by sync) |
| `getWordPressVersion()` :97 | GET | `/wp-json/` | `$data['description']` — the site **tagline**, not a version | `null` (unused by sync) |
| `getContentStats()` :112 | GET ×3 | `/wp/v2/posts`, `/pages`, `/comments` with `per_page=1` | counts from `X-WP-Total` header | each failing call leaves 0 |
| `getSystemInfo()` :141 | GET ×2 | `/wp-site-health/v1/directory-sizes` (response discarded), then `/wp-json/` reading `$data['version']` | `[wp_version, php_version]` | both are **always null**: WP's root index has no `version` key (`class-wp-rest-server.php:1360-1375`) and `php_version` is never assigned |
| `updatePlugin(string)` :172 | POST | `/wp-json/dashbird/v1/update-plugin` `{plugin}` | `{success, message}` | non-2xx → message from body or `HTTP n` |
| `updateTheme()` :199, `updateCore()` :207 | — | — | `false` stubs | — |
| `getBridgeUpdates()` :219 | GET | `/wp-json/dashbird/v1/updates` | raw json | `null` (= bridge absent) |
| `testAuthentication()` :230 | GET | `/wp/v2/plugins?per_page=1` | `successful()` | exception → `false` |
| `testConnection()` :241 | GET | `/wp-json/` | `successful()` | exception → `Log::error`, `false` |
| `getUsers()` :268 | GET, paged | `/wp/v2/users?context=edit&per_page=100&page=n` (loops on `X-WP-TotalPages`) | mapped `[id,email,username,name,roles]` | non-2xx → warning, `[]` |
| `createUser(array)` :314 | POST | `/wp/v2/users` | mapped user | non-2xx → warning (logs body), `null` |
| `deleteUser(int,int)` :343 | DELETE | `/wp/v2/users/{id}` `{reassign, force:true}` | `bool` | non-2xx → warning, `false` |
| `get/post/delete` :255,363,368 | private | `$baseUrl . $endpoint` | `Response` | — |

Consumers: `SiteSyncService` (sync), `SiteController::triggerPluginUpdate` (`:283`), Livewire `SitePlugins:46`, `SiteWordPressUsers:53,127`, jobs `AddWordPressUserJob:36`, `RemoveWordPressUserJob:51`.

### 4.3 Error handling, summarised

- Non-2xx is never thrown; every method collapses it to `[]`/`false`/`null` plus a log line with `site_id` and status. A site with zero plugins and a site whose token lacks `activate_plugins` look identical to callers of `getPlugins()`.
- Transport exceptions (`ConnectionException` on timeout/DNS) propagate from every data method; only `testConnection()`/`testAuthentication()` catch them. `SiteSyncService::syncAll` wraps each site in `try/catch` and marks `last_sync_succeeded=false` (`SiteSyncService.php:111-128`).
- "Authenticated" is defined as *`GET wp/v2/plugins` returned 2xx* (`:230-239`), i.e. the credential's user has `activate_plugins`. A lower-privileged but valid app password reads as `limited`. unhost's discovery uses `/wp/v2/users/me` instead, which any authenticated user can read — the right probe for a per-user credential.
- `getUsers` infers permission by checking whether any returned user has an `email` (`SiteSyncService.php:305-315`) — a `context=edit` request without `list_users`/`edit_users` silently degrades to `view` context.

### 4.4 As precedent for the raw-REST `site-request` tool

Carry: Basic auth from a stored per-site credential; force `https`; strict redirects (with the correct rationale, §1.4); a fixed timeout; base URL + relative path composition; reading `X-WP-Total`/`X-WP-TotalPages` for pagination. Change: surface the status code and body to the caller instead of swallowing; catch transport exceptions at the tool boundary and return them as tool errors; add the outbound origin guard the discovery already flags (`discovery.md` §5 item 5) — Dashbird has none because its base URL is always the stored site host.

## 5. `dashbird-bridge`

Zip contents: `dashbird-bridge/dashbird-bridge.php` (123 lines, "Dashbird Bridge" v1.2.0, GPL-2.0+) and `notes.md`. Read from the scratchpad copy.

**What it does** (`dashbird-bridge.php:18-123`): on `rest_api_init` it registers two routes in the `dashbird/v1` namespace, both with `permission_callback` = `current_user_can('update_plugins') && current_user_can('install_plugins')`:

1. `GET /wp-json/dashbird/v1/updates` — requires `wp-admin/includes/update.php` and returns `['core' => get_core_updates(), 'plugins' => get_plugin_updates(), 'themes' => get_theme_updates()]` — the cached update transients, which include non-wordpress.org update sources (the comment at `:19`; `notes.md` says the intent is to add a `?force=1` refresh later).
2. `POST /wp-json/dashbird/v1/update-plugin` with `plugin` (validated against `^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+\.php$`, ≤255 chars) — verifies the plugin is installed (`get_plugins()`), refuses to update itself, takes a 120 s transient lock keyed on `wp_hash($plugin)` (409 if held), runs `Plugin_Upgrader::upgrade()` with `Automatic_Upgrader_Skin`, releases the lock, returns `{success, plugin}` or a 500 `WP_Error`.

**Why Dashbird needs it:** WordPress core's `wp/v2/plugins` controller exposes `plugin, status, name, plugin_uri, author, author_uri, description, version, network_only, requires_wp, requires_php, textdomain` — **no `update` field** (`class-wp-rest-plugins-controller.php:590-600, 940-965`; routes at `:37-90` are list/install, get/activate-deactivate/delete). So `WordPressClient::getPlugins()`'s `'update' => $plugin['update'] ?? null` is always null from core; update availability exists in Dashbird only because `SiteSyncService` merges `getBridgeUpdates()` into the snapshot (`SiteSyncService.php:52-62, 242-289`), and `hasUpdatesAvailable()`/the "Updates available" notification depend on it. Core has no plugin-upgrade endpoint either; the bridge's POST is the only write path (`SiteController.php:278-281` refuses without `has_bridge`).

**Does RestlessWP make it redundant?** No, not today. RestlessWP 0.9.11's modules are ACF, ACSS, Etch, Gravity Forms, Safe Redirect Manager, The SEO Framework and HappyFiles (`restlesswp/modules/`); a grep for `Plugin_Upgrader|get_plugin_updates|update_plugins` across the plugin returns nothing; its readme describes it as "REST API endpoints for WordPress plugin configurations not natively accessible" (`readme.txt:12-22`). Two conclusions for the map:

- For the **gateway-first slice** the bridge is irrelevant: `sites`, `wordpress` and `gateway` do no update detection or upgrading, so nothing needs it.
- For the later **updates/maintenance feature module** (out of scope on the map), the bridge's two capabilities are still unserved by RestlessWP. The natural path is a RestlessWP module (RestlessWP's third-party extension hook `restlesswp_modules`, `readme.txt:29`) exposing the same two operations as abilities — which would make the bridge redundant *then* and remove a second plugin from every site. That is a RestlessWP ask, not an unhost decision.

## 6. The integration manifest (`app/Integrations/*/integration.php`)

### 6.1 Shape

Each of the nine folders returns a plain array (`BetterUptime`, `Cloudflare`, `GridPane`, `Namecheap`, `Plausible`, `Porkbun`, `Postmark`, `RocketNet`, `WpRemote`):

```php
return [
    'key'         => 'rocketnet',                      // required; the registry key
    'label'       => 'Rocket.net',                     // display name
    'credentials' => [                                 // optional, only when no provider class declares them
        ['name' => 'token', 'label' => 'API Token', 'type' => 'password'],
    ],
    'hosting'     => RocketNetHostingProvider::class,  // optional: one class-string per contract implemented
    'backup'      => RocketNetBackupProvider::class,   //   contracts: hosting | backup | registrar | dns
];
```

Observed variants: credentials-only (`Cloudflare`, `BetterUptime`, `Plausible`, `Postmark` — used by feature code directly, no contract yet); single-contract (`GridPane` hosting, `Namecheap`/`Porkbun` registrar, `WpRemote` backup); multi-contract (`RocketNet` hosting + backup) (`app/Integrations/*/integration.php`).

### 6.2 Resolution

`IntegrationResolver` (`app/Services/IntegrationResolver.php`) globs `Integrations/*/integration.php` once (`:209-226`), skips entries without `key`, and exposes `all()`, `hosting()/backup()/registrar()/dns()` (class-strings keyed by integration key, `:27-100`), `resolve($feature, $key)` (`:117-122`), `features($key)` (`:162-186`), `label($key)` and `credentialFields($key)` — manifest `credentials` first, else the first contract class's static `credentialFields()` (`:132-155`). Per-feature resolvers (`HostingProviderResolver`, `BackupProviderResolver`, `RegistrarResolver`, `DnsProviderResolver`) are thin wrappers: `HostingProviderResolver::resolve(Host)` → `IntegrationResolver::resolve('hosting', $host->integration->integration_key)` → `new $class()` (`app/Services/HostingProvider/HostingProviderResolver.php:13-26`).

### 6.3 Contracts

Each feature service owns its interface; every one begins with the same two statics plus `providerName()`:

- `HostingProviderContract` (`app/Services/HostingProvider/HostingProviderContract.php`): `providerName()`, `static credentialFields()`, `static authenticate(array): ?{token, token_expires_at, credentials}`, `shouldHideEmptyServers()`, `getServers(Host)`, `getServerDetails(Host, Server)`, `getSites(Host)`.
- `BackupProviderContract` (`app/Services/BackupProvider/BackupProviderContract.php`): same head, then `supportsDiscovery()`, `getSites(BackupProvider)`, `getStatus(BackupProvider, $remoteId)`.
- `RegistrarProviderContract`, `DnsProviderContract` follow the pattern.

`authenticate()` returns the normalized credential triple that `IntegrationCredential` stores (`token` encrypted, `token_expires_at`, `credentials` encrypted JSON) — so **the integration owns the credential and every contract it implements shares it** (`discovery.md:80-82`, "one Cloudflare token serves DNS and hosting").

### 6.4 Storage side

`integrations` table: `name`, `integration_key`, `metadata` json (`2026_04_25_092756`); `integration_credentials`: `service`, encrypted `token`, `token_expires_at`, encrypted `credentials`, nullable `client_id`, nullable `integration_id` (`2024_01_01_000009`, `2026_04_24_000001`); `hosts`/`backup_providers`/`registrars`/`dns_providers` each carry `integration_id` (`app/Models/Integration.php:28-51`).

### 6.5 What carries into unhost's provider-contract seam

- **Carry:** one integration key; one label; credential *shape* declared by the integration (fields with `name/label/type`); a map of contract → implementation; a feature-owned interface whose first members are `credentialFields()`/`authenticate()`; a registry keyed by integration key that a feature resolves through; credentials owned by the integration record, not the feature.
- **Change:** ADR 0008 itself notes the flat manifest "can neither contribute a route/migration/view nor be withheld when inactive" (`docs/adr/0008:12`) — in unhost an integration is a Scatterblend module with a manifest of properties, and the contract map becomes an offer into the feature module's registry from `bootModule()` behind `class_exists` (map Notes; Scatterblend #79). Dashbird's `IntegrationResolver::hosting()/backup()/…` hard-codes the four contract names (`:148, 169-184`); a registry that any feature module can define a contract for removes that list.
- The `wordpress` module is the first integration under this seam, implementing the `sites` **site connector** contract for `platform = wordpress`; Dashbird has no equivalent because WordPress was the only platform and its "integration" was the `Site` columns themselves.

## 7. Surprises worth telling other tickets

1. **`client` has no content** in the predecessor — a name and a slug. The "is client a record?" question is really "do we want a grouping label for sites", not "do we need to port a Client model".
2. **Nullable `client_id` on both `users` and `sites` + `===` compare = unassigned sites visible to client-less users** (`SitePolicy.php:21`, `Site.php:128`). Grants avoid this; any `client_id`-style column would have to be non-null or guarded.
3. **`wp_version`/`php_version` in snapshots are always null** from Dashbird's own sync (`getSystemInfo` reads a key WP's root index never sends). Whoever specs a snapshot module should not copy those fields as if they worked.
4. **Update availability only exists via the bridge** — core `wp/v2/plugins` has no `update` field. An updates module needs a site-side component (bridge or a RestlessWP module); RestlessWP 0.9.11 has none.
5. **"Authenticated" in Dashbird means `activate_plugins`** (`wp/v2/plugins` 2xx), which is why an editor-role credential shows as `limited`. unhost's `/wp/v2/users/me` probe is the right replacement for per-user credentials; the *derived-status* pattern (never store a computed status) is still worth keeping.
6. **`allow_redirects.strict` does not protect the credential**; Guzzle strips `Authorization` cross-origin regardless. Keep both the forced `https` and `strict`, but the `site-request` ADR should state what each does.
7. **Dashbird's `super_admin` is not Scatterblend's operator**: it is "unscoped inside the one tenant", which in single mode is a role with every capability and no grant filter. Don't map it to the operator flag.
