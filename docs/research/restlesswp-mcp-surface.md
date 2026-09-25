# Research: RestlessWP's MCP surface

**Ticket:** #3 (part of map #1). **Date:** 21 September 2026. **Status:** resolved.

**What was read.** RestlessWP source at three versions — the local checkouts
`example.localhost` (**0.9.11**, with the official `WordPress/mcp-adapter` 0.5.0 installed) and
`example-two.localhost` (**0.9.14**, no adapter), and the upstream `alexmansfield/restlesswp` `main` tarball
(**0.13.0**, pushed 17 Sep 2026, which is what the live `example.com` / `example.net` connectors
expose). WordPress core 7.1 (`wp-includes/abilities-api/`, `rest-api/`, `user.php`) and the adapter plugin's
transport. Every number below was produced by executing the plugin inside WordPress via WP-CLI
(`wp eval-file`), not by reading schemas off the page, and the wire shapes were confirmed with `curl` against
both local sites. Temporary application passwords were minted for the probes and deleted afterwards; no
credential from any `.mcp.json` was read or used.

Citations are `file:line` relative to the plugin root; RestlessWP lines refer to **0.13.0** unless marked
`(0.9.14)`; adapter lines are the 0.5.0 copy under `example.localhost/public/wp-content/plugins/mcp-adapter/`;
core lines are `example-two.localhost/public/wp-includes/`.

## Answers

- **Endpoint / transport.** `POST {rest_url}/restlesswp/v1/mcp`, JSON-RPC 2.0 over plain JSON (no SSE),
  methods `initialize`, `ping`, `tools/list`, `tools/call` only. **In practice the built-in fallback server
  always answers POST, even when the official adapter is installed** (registration-order bug, §1.2), so the
  effective transport is **stateless**: no `Mcp-Session-Id` is ever issued or required, notifications get
  `202`, GET/DELETE are `404` (`rest_no_route`) on a plain site and `401`/`405` via the adapter's handler on
  an adapter site. A request without a session gets a normal answer.
- **Auth challenge.** A `401` with **no `WWW-Authenticate` header and no authwp metadata** — body is a WP
  REST error `{"code":"restlesswp_not_authenticated","message":"Authentication is required to access this
  endpoint.","data":{"status":401}}`. A wrong or unknown application password gets the **same** 401 as no
  credentials (core never surfaces `incorrect_password` here, §2.2). Basic auth needs `user:app-password`
  (spaces optional), `is_ssl()` or `WP_ENVIRONMENT_TYPE=local`, and the user must hold each tool's capability.
- **Catalog.** 0.13.0 registers **166 tools** when every module is active (ACF Pro, ACSS, Etch, Gravity
  Forms, HappyFiles Pro, Safe Redirect Manager, The SEO Framework, plus the always-on core content module);
  a site with ACF + ACSS + Etch + GF gets **131**. `inputSchema` is small: median **138 B**, mean 590 B,
  max **4,992 B** (`list-posts`), ≈ 98 KB total across 166 tools; a whole `tools/list` is **134–153 KB**
  (≈ 35–40k tokens). Capabilities are per tool (table in §3.3): `manage_options` 60, `edit_posts` 57,
  `read` 10, GF's `gravityforms_*` 17, `srm_manage_redirects` 5, `edit_others_posts` 4, `edit_users` 2,
  `upload_files` 8, plus per-item `edit_post` / `unfiltered_html` checks inside some handlers. **48 tools**
  fall in the ticket's dangerous list (26 deletions, 6 site-option writes, 16 CSS/HTML code writes); no tool
  installs/activates plugins or themes or changes users/roles; SRM's create/update-redirect are outside the
  list but recommended dangerous.
- **Per-site variance.** The catalog is **site-dependent**: each module registers only when its target plugin
  is active and meets a version floor (§4.1); ACF adds resources by ACF version/Pro; the content module's
  schemas are derived from the **live REST route table** so they pick up plugin-registered fields; the
  fallback server (≥ 0.11.0) also **bridges any third-party ability** flagged `mcp.public` (that is where
  `meta-box-*` on example.net comes from); and **tool names changed between versions** (0.13.0 renamed
  every `get-{plural}` to `list-{plural}`). It does **not** vary by the calling user's role — `tools/list`
  is the same for every authenticated user; capability is enforced only at `tools/call`. So
  `list-site-tools` may be cached **per site keyed on plugin version + active plugin set**, never globally.
- **Errors, sizes, rate limits.** Protocol errors are JSON-RPC errors over **HTTP 200** (`-32600` invalid
  request, `-32601` method not found, `-32602` unknown tool). Every tool failure — bad input, missing
  capability, not found, handler `WP_Error`, thrown exception — is a **`CallToolResult` with `isError:true`**
  and one text block carrying the message (permission denial reads `Ability "restlesswp/x" does not have
  necessary permission.`; the specific 403 message never leaks). Results are `content[0].text` =
  `wp_json_encode(result)` plus `structuredContent` when the result is an object (not for list results).
  **No size cap, no pagination beyond what a tool offers, no rate limiting** anywhere in RestlessWP, the
  adapter or core; mutating tools default to a lean summary and take `verbose:true` for the full resource.

## 1. Endpoint and transport

### 1.1 Route, methods, protocol

- The route is `restlesswp/v1` + `mcp`, built with `rest_url()` so it honours `?rest_route=` sites
  (`classes/class-mcp-server.php:35-42, 74-76`). Since 0.12.0 a **Settings → RestlessWP** admin page shows
  the URL and a client-config snippet (readme changelog 0.12.0).
- Two serving paths share that route: the official adapter's `HttpTransport` when `mcp_adapter_init`
  succeeds (`class-mcp-server.php:94-124`), else a built-in JSON-RPC handler registered on `rest_api_init`
  for `POST` only (`:131-145`).
- Fallback handler: rejects non-`2.0`/method-less bodies with `-32600`; treats a message without `id` as a
  notification and returns **`202` with no body**; dispatches `initialize`, `ping`, `tools/list`,
  `tools/call`; anything else is `-32601` (`class-mcp-server.php:172-200`). All JSON-RPC errors are sent with
  **HTTP 200** (`:268-280`). No batching support (one message per POST), no SSE, no `resources/*`,
  `prompts/*`, no `notifications/tools/list_changed`.
- Protocol versions accepted: `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`; unknown → `2025-06-18`
  (`class-mcp-server.php:49-56, 208-220`). `initialize` returns `capabilities:{tools:{}}` and
  `serverInfo:{name:"RestlessWP",version:<plugin version>}` — the plugin version is therefore readable from
  the handshake, which the gateway can use as the cache key (§4).
- Observed (`curl`, both sites, authenticated):
  `{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{}},"serverInfo":{"name":"RestlessWP","version":"0.9.14"}}}`,
  response headers carry no `Mcp-Session-Id`; `notifications/initialized` → `202`; `tools/list` **without
  any session header** → `200` with the full list.

### 1.2 The adapter path never serves POST over HTTP (bug)

RestlessWP intends the adapter to take over the route when present ("a connecting agent cannot tell them
apart", `class-mcp-server.php:13-16`). It does not happen on a real request:

1. The adapter initialises on `rest_api_init` **priority 15** (`includes/Core/McpAdapter.php:63`) and fires
   `mcp_adapter_init` from there (`:93`); its transport registers its route at `rest_api_init` **priority 16**
   (`includes/Transport/HttpTransport.php:47`).
2. RestlessWP's `maybe_register_fallback` runs at `rest_api_init` **priority 10** (`class-mcp-server.php:85`),
   when `$served_by_adapter` is still `false`, so the fallback route is registered first.
3. Core merges a second registration of the same route by **appending** handlers
   (`rest-api/class-wp-rest-server.php:929-933`), and dispatch takes the first handler whose method matches —
   the fallback's `POST`. GET/DELETE have no fallback handler, so they reach the adapter's.

Evidence on `example.localhost` (adapter 0.5.0 active): POST `initialize` → RestlessWP's own
`restlesswp_not_authenticated` body when anonymous (the adapter would answer `rest_forbidden`, because it
casts a `WP_Error` from the permission callback to `false`, `HttpTransport.php:88-99`), `serverInfo.name`
"RestlessWP", no `Mcp-Session-Id`, `tools/list` entries without `title`/`outputSchema`; GET/DELETE →
`rest_forbidden` 401 (adapter). The ordering flips only under WP-CLI, where the adapter hooks `init`
(`McpAdapter.php:60`) — which is why an in-process dump sees the adapter server with 127 tools.

Consequence for unhost: design against the **fallback** semantics (stateless, no sessions, no batching, no
outputSchema). If RestlessWP fixes the ordering, the adapter path adds user-meta sessions (§1.3) and roughly
doubles `tools/list` (§3.2). File this as an ask on `alexmansfield/restlesswp`.

### 1.3 Sessions (adapter path, for completeness)

Only the adapter implements sessions: `initialize` creates a UUID stored in **user meta**
`mcp_adapter_sessions` (max 32 per user, FIFO eviction) and returns it in `Mcp-Session-Id`
(`Transport/Infrastructure/SessionManager.php:28-98`, `HttpRequestHandler.php:219-223, 285-305`). Every
non-`initialize` request must carry the header or gets `-32600 "Missing Mcp-Session-Id header"`; an unknown or
expired id gets `-32005` → **HTTP 404** (`HttpSessionValidator.php:32-51`, `McpErrorFactory.php:43, 401-407`).
Expiry is **24 h of inactivity** (`SessionManager.php:42`), `last_activity` writes throttled to once a minute
(`:49`). `DELETE` with the header terminates (`HttpRequestHandler.php:324-334`). GET returns `405` (SSE not
implemented, `:312-315`). An unsupported `Mcp-Protocol-Version` header → `-32600` (`:255-272`); the adapter
lists `2025-11-25`, `2025-06-18`, `2024-11-05` (`Core/McpVersionNegotiator.php:30-33`).

## 2. Authentication and the 401

### 2.1 What a 401 carries

- Transport permission is `is_user_logged_in()`; otherwise a `WP_Error('restlesswp_not_authenticated', …,
  ['status'=>401])` (`class-mcp-server.php:154-164`). Core serialises that as the JSON body shown in the
  Answers, with `Access-Control-*`, `Link`, `X-Robots-Tag: noindex` — and **no `WWW-Authenticate`**. Core
  never sends one; authwp's spec confirms this and plans to attach `WWW-Authenticate: Bearer
  resource_metadata="…"` to every REST 401 from `rest_post_dispatch` once a site is registered with it
  (`~/Cove/Sites/authwp/docs/spec.md:749-756, 802-815`). A RestlessWP site without authwp therefore offers
  **no discovery**; the gateway must know the endpoint and credential a priori (it does).
- Per-tool denial is a **403** `restlesswp_forbidden` on the REST routes (`classes/class-auth-handler.php:40-76`)
  but on the MCP path the callback is coerced to a boolean (`classes/class-abilities-registrar.php:199-215`)
  and `WP_Ability::execute()` replaces any denial with the generic `ability_invalid_permissions` error
  (`abilities-api/class-wp-ability.php:826-839`), returned as `isError:true` (§5).

### 2.2 Basic auth with an application password

- Core's `wp_validate_application_password` reads `PHP_AUTH_USER`/`PHP_AUTH_PW` and calls
  `wp_authenticate_application_password` (`user.php:520-543`); non-alphanumerics are stripped so the
  `xxxx xxxx …` display form works (`user.php:453`). Requires `is_ssl()` **or** `wp_get_environment_type()
  === 'local'` (`user.php:5155-5157`) and the user not being blocked for app passwords (`:425-433`).
- Observed: an unknown user or a wrong password produces the **same** `401 restlesswp_not_authenticated`
  (and `rest_not_logged_in` on `wp/v2/users/me`), not core's `incorrect_password`/`invalid_username`. Reason:
  `rest_api_loaded` fires on `parse_request` (`default-filters.php:549`), before `WP::init()` determines the
  current user (`class-wp.php:690-691`); `rest_application_password_check_errors` (priority 90) reads a
  status global that is still unset, and `rest_cookie_check_errors` (priority 100) is what finally triggers
  `determine_current_user` (`rest-api.php:1248-1272`, `default-filters.php:345-346`). The gateway cannot
  distinguish "no credential" from "revoked credential" from the body; both must be treated as
  *credential invalid, re-onboard*.
- The readme's stated auth story is application passwords only, "No OAuth" (readme 322-333).

## 3. The tool catalog

### 3.1 How tools are made

- Every tool is a WordPress **Ability** (`wp_register_ability`, WP ≥ 6.9) in category `restlesswp` with
  `meta.mcp.public = true` and an annotation flag; the MCP tool name is the ability name with `/` → `-`
  (`classes/class-mcp-tools.php:246-257`). Ability names are `restlesswp/{module}-{action}-{resource}`;
  since 0.13.0 collections are `list-{plural}` and singles `get-{singular}` (`classes/trait-ability-naming.php`,
  readme "Abilities API and MCP").
- Standard modules are introspected from their REST controllers: for each supported operation the registrar
  builds the input schema from the controller's item schema (or a per-operation override), appends a shared
  `verbose` boolean to `create/update/patch/append/import/bulk-replace`, and wires the permission callback
  from the controller's read or write capability (`class-abilities-registrar.php:524-560, 506-521, 625-634`).
  Operation → annotation/capability-kind map: `list/get/orphan-detect/list-backups/get-backup` → readonly,
  read; `create/import/append` → open_world, write; `update/bulk-update/convert/patch` → idempotent, write;
  `delete/bulk-replace` → destructive (`:42-113`).
- Gravity Forms uses descriptors over GF's own REST controllers with GF capabilities checked through
  `GFCommon::current_user_can_any` (`modules/class-gf-module.php:162, 303-313`,
  `classes/trait-descriptor-abilities.php:177-190`). ACF and Etch add hand-written field-level abilities
  (`modules/class-acf-module.php:105-116`, `modules/class-etch-module.php:126-137`). The content module
  (0.13.0) registers 15 abilities that dispatch into core's `wp/v2` controllers through `rest_do_request`
  (`modules/class-content-module.php:103-168`, `classes/class-rest-bridge.php:79-105`), so core's per-post-type
  and per-post capability checks run exactly as over HTTP, on top of a coarse `read`/`edit_posts` gate
  (`class-content-module.php:54-59`).
- Execution: `WP_Ability::execute()` → `wp_pre_execute_ability` short-circuit → input normalisation →
  JSON-Schema validation (`rest_validate_value_from_schema`) → permission → callback → output validation
  (`abilities-api/class-wp-ability.php:769-860`). RestlessWP's execute callback synthesises a `WP_REST_Request`,
  fires `rest_request_before_callbacks`/`after_callbacks` so route-aware plugins behave, and unwraps the
  `{success,data}` envelope (`class-abilities-registrar.php:675-720`). `tools/list` on the fallback is
  `{name, description, inputSchema, annotations?}` with empty `properties` forced to `{}`
  (`class-mcp-tools.php:266-322`); the adapter would add `title` and `outputSchema`
  (`Domain/Tools/RegisterAbilityAsMcpTool.php:105-150`).

### 3.2 Sizes (measured)

| Measurement (0.13.0 unless noted) | Value |
|---|---:|
| Tools, all seven modules + content | **166** |
| Tools, ACF Pro + ACSS + Etch + GF + content (example-two) | **131** |
| Tools, 0.9.14 on example-two (no content module) | 116 |
| Tools, 0.9.11 on example-site (+ HappyFiles) | 127 |
| `tools/list` body, fallback, 166 tools | **153,207 B** |
| `tools/list` body, fallback, 131 tools | **134,369 B** |
| `tools/list` body, adapter path (0.9.11, 127 tools, includes `outputSchema`) | 216,264 B |
| `inputSchema` per tool: min / median / mean / max | 33 / **138** / 590 / **4,992 B** |
| `inputSchema` total, 166 tools | 97,983 B |
| `description` per tool: median / max / total | 114 / 1,429 / 36,268 B |
| `outputSchema` per tool: median / max / total (not on the wire today) | 636 / 8,000 / 171,493 B |
| Largest `inputSchema`s | `list-posts` 4,992; `update-post` 3,370; `create-post` 3,351; `create-post-autosave` 3,190; `acf-update-post-type` 2,731; `acf-create-post-type` 2,669; `acf-update-taxonomy` 2,597; `acf-create-taxonomy` 2,526 |

At ≈ 4 bytes/token a full list is **≈ 35–40k tokens**, i.e. roughly 100 sites × 40k if schemas were inlined
per site — which confirms D4's constant surface and per-tool schema fetch. The per-tool schema is cheap
(most are ≤ 200 B; only the eight generic/ACF-config tools exceed 2 KB). Description text is ≈ 25 % of the
list; the eight `*-get-guide` tools carry long descriptions and return multi-KB reference guides.

### 3.3 Every tool

Capability is the one checked by the ability's `permission_callback`; some handlers add per-item checks noted
in §3.4. "Dangerous?" is judged against the ticket's list (plugin/theme install/activate/update, user and
role changes, site options, file/code writes, deletions). `no*` = outside the list but worth a second look.
Schema size is `wp_json_encode(input_schema)` in bytes.

| Tool | Purpose | WP capability | Annotation | Dangerous? | inputSchema bytes |
|---|---|---|---|---|---:|
| `restlesswp-acf-create-field` | Add a single field to an ACF field group or as a sub-field inside a repeater, group, or fl… | `manage_options` | open_world | no | 772 |
| `restlesswp-acf-create-field-group` | Create a new ACF field group with optional fields and location rules. | `manage_options` | open_world | no | 1512 |
| `restlesswp-acf-create-options-page` | Create a new options page. | `manage_options` | open_world | no | 1927 |
| `restlesswp-acf-create-post-type` | Create a new post type. | `manage_options` | open_world | no | 2669 |
| `restlesswp-acf-create-taxonomy` | Create a new taxonomy. | `manage_options` | open_world | no | 2526 |
| `restlesswp-acf-delete-field` | Delete a single field from an ACF field group | `manage_options` | destructive | **yes** (deletion) | 225 |
| `restlesswp-acf-delete-field-group` | Delete an ACF field group and all its fields. | `manage_options` | destructive | **yes** (deletion) | 127 |
| `restlesswp-acf-delete-options-page` | Delete a options page. | `manage_options` | destructive | **yes** (deletion) | 127 |
| `restlesswp-acf-delete-post-type` | Delete a post type. | `manage_options` | destructive | **yes** (deletion) | 127 |
| `restlesswp-acf-delete-taxonomy` | Delete a taxonomy. | `manage_options` | destructive | **yes** (deletion) | 127 |
| `restlesswp-acf-get-field-group` | Get a single ACF field group by key, including all fields. | `edit_posts` | readonly | no | 127 |
| `restlesswp-acf-get-guide` | Get an ACF reference guide by topic | `edit_posts` | readonly | no | 127 |
| `restlesswp-acf-get-options-page` | Get a single options page by ID. | `edit_posts` | readonly | no | 127 |
| `restlesswp-acf-get-options-value` | Get all ACF field values stored on a specific options page | `edit_posts` | readonly | no | 139 |
| `restlesswp-acf-get-post-type` | Get a single post type by ID. | `edit_posts` | readonly | no | 127 |
| `restlesswp-acf-get-post-value` | Get all ACF field values for a specific post | `edit_posts` | readonly | no | 127 |
| `restlesswp-acf-get-taxonomy` | Get a single taxonomy by ID. | `edit_posts` | readonly | no | 127 |
| `restlesswp-acf-list-field-groups` | List all ACF field groups with their fields and location rules. | `edit_posts` | readonly | no | 61 |
| `restlesswp-acf-list-options-pages` | List all options pages. | `edit_posts` | readonly | no | 61 |
| `restlesswp-acf-list-post-types` | List all post types. | `edit_posts` | readonly | no | 61 |
| `restlesswp-acf-list-taxonomies` | List all taxonomies. | `edit_posts` | readonly | no | 61 |
| `restlesswp-acf-update-field` | Update a single field within an ACF field group using merge semantics | `manage_options` | idempotent | no | 253 |
| `restlesswp-acf-update-field-group` | Update an ACF field group | `manage_options` | idempotent | no | 1586 |
| `restlesswp-acf-update-options-page` | Update an existing options page. | `manage_options` | idempotent | no | 1984 |
| `restlesswp-acf-update-options-value` | Set ACF field values on a specific options page (e.g | `edit_posts` | idempotent | **yes** (site options) | 432 |
| `restlesswp-acf-update-post-type` | Update an existing post type. | `manage_options` | idempotent | no | 2731 |
| `restlesswp-acf-update-post-value` | Set ACF field values on a specific post | `edit_posts` | idempotent | no | 420 |
| `restlesswp-acf-update-taxonomy` | Update an existing taxonomy. | `manage_options` | idempotent | no | 2597 |
| `restlesswp-acss-bulk-update-variables` | Update multiple ACSS settings at once | `manage_options` | idempotent | **yes** (site options (ACSS settings in wp_options)) | 176 |
| `restlesswp-acss-create-class` | Create a new class. | `manage_options` | open_world | **yes** (code write (site CSS)) | 197 |
| `restlesswp-acss-create-variable` | Create a new ACSS setting | `manage_options` | open_world | **yes** (site options (ACSS settings in wp_options)) | 376 |
| `restlesswp-acss-delete-class` | Delete a class. | `manage_options` | destructive | **yes** (deletion) | 127 |
| `restlesswp-acss-get-class` | Get a single class by ID. | `manage_options` | readonly | no | 127 |
| `restlesswp-acss-get-css-variable` | Get a single computed CSS custom property value by name | `manage_options` | readonly | no | 127 |
| `restlesswp-acss-get-guide` | Get an Automatic CSS (ACSS) reference guide by topic | `edit_posts` | readonly | no | 127 |
| `restlesswp-acss-get-variable` | Get a single ACSS dashboard setting by its setting key | `manage_options` | readonly | no | 127 |
| `restlesswp-acss-list-classes` | List all classes. | `manage_options` | readonly | no | 61 |
| `restlesswp-acss-list-css-variables` | List all computed CSS custom properties from the ACSS generated stylesheet | `manage_options` | readonly | no | 61 |
| `restlesswp-acss-list-variables` | List all ACSS dashboard settings (raw input values) | `manage_options` | readonly | no | 61 |
| `restlesswp-acss-update-class` | Update an existing class. | `manage_options` | idempotent | **yes** (code write (site CSS)) | 292 |
| `restlesswp-acss-update-variable` | Update an existing ACSS setting | `manage_options` | idempotent | **yes** (site options (ACSS settings in wp_options)) | 368 |
| `restlesswp-create-post` | Create a post, page, or custom post type item — equivalent to POST /wp/v2/{type} | `edit_posts` | open_world | no* (content write; raw HTML/scripts only for unfiltered_html users — core rules) | 3351 |
| `restlesswp-create-post-autosave` | Create an autosave of a post without changing the live post — equivalent to POST /wp/v2/{t… | `edit_posts` | open_world | no (autosave, live post unchanged) | 3190 |
| `restlesswp-delete-post` | Delete a post, page, or custom post type item — equivalent to DELETE /wp/v2/{type}/{id} | `edit_posts` | destructive | **yes** (deletion) | 356 |
| `restlesswp-delete-post-revision` | Permanently delete one revision of a post — equivalent to DELETE /wp/v2/{type}/{id}/revisi… | `edit_posts` | destructive | **yes** (deletion) | 461 |
| `restlesswp-etch-append-page` | Append blocks to an existing page without replacing current content | `edit_posts` | open_world | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 685 |
| `restlesswp-etch-bulk-replace-loops` | Replace ALL loop presets at once | `manage_options` | destructive | **yes** (deletion) | 369 |
| `restlesswp-etch-bulk-replace-styles` | Replace ALL styles at once | `manage_options` | destructive | **yes** (deletion) | 369 |
| `restlesswp-etch-bulk-replace-stylesheets` | Replace ALL stylesheets at once | `manage_options` | destructive | **yes** (deletion) | 369 |
| `restlesswp-etch-bulk-update-styles` | Update multiple Etch styles at once | `manage_options` | idempotent | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 187 |
| `restlesswp-etch-convert-block` | Convert blocks to Etch storage format | `manage_options` | idempotent | no (pure format transform, persists nothing) | 150 |
| `restlesswp-etch-create-component` | Create a new Etch component | `manage_options` | open_world | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 2102 |
| `restlesswp-etch-create-field` | Add a single field to an Etch custom field group | `manage_options` | open_world | no | 800 |
| `restlesswp-etch-create-field-group` | Create a new Etch custom field group | `manage_options` | open_world | no | 1911 |
| `restlesswp-etch-create-loop` | Create a new Etch loop preset | `manage_options` | open_world | no (query preset config) | 956 |
| `restlesswp-etch-create-style` | Create a new Etch style | `manage_options` | open_world | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 1046 |
| `restlesswp-etch-create-stylesheet` | Create a new Etch global stylesheet | `manage_options` | open_world | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 662 |
| `restlesswp-etch-create-template` | Create a new block template scoped to the active theme | `manage_options` | open_world | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 1292 |
| `restlesswp-etch-delete-component` | Delete an Etch component by `id` (post ID). | `manage_options` | destructive | **yes** (deletion) | 126 |
| `restlesswp-etch-delete-field` | Delete a single field from an Etch custom field group | `manage_options` | destructive | **yes** (deletion) | 223 |
| `restlesswp-etch-delete-field-group` | Delete an Etch custom field group by storage id | `manage_options` | destructive | **yes** (deletion) | 125 |
| `restlesswp-etch-delete-loop` | Delete an Etch loop preset by `id` | `manage_options` | destructive | **yes** (deletion) | 125 |
| `restlesswp-etch-delete-style` | Delete an Etch style by `id` | `manage_options` | destructive | **yes** (deletion) | 125 |
| `restlesswp-etch-delete-stylesheet` | Delete an Etch global stylesheet by `id`. | `manage_options` | destructive | **yes** (deletion) | 125 |
| `restlesswp-etch-delete-template` | Delete a block template by `id` (post ID) | `manage_options` | destructive | **yes** (deletion) | 126 |
| `restlesswp-etch-get-backup-field-group` | Retrieve a full Etch field-group definitions backup by slot index (0-4) | `edit_posts` | readonly | no | 132 |
| `restlesswp-etch-get-backup-page` | Retrieve a full page content backup by slot index (0-4) | `edit_posts` | readonly | no | 132 |
| `restlesswp-etch-get-backup-style` | Retrieve a full style backup by slot index (0-4) | `edit_posts` | readonly | no | 132 |
| `restlesswp-etch-get-backup-template` | Retrieve a full template content backup by slot index (0-4) | `edit_posts` | readonly | no | 132 |
| `restlesswp-etch-get-component` | Get a single Etch component by `id` (post ID — matches Etch's URL convention) | `edit_posts` | readonly | no | 126 |
| `restlesswp-etch-get-field-group` | Get a single Etch custom field group by storage id, including its field definitions and as… | `edit_posts` | readonly | no | 125 |
| `restlesswp-etch-get-field-value` | Get all Etch-native custom field values for a specific post | `edit_posts` | readonly | no | 126 |
| `restlesswp-etch-get-guide` | Get an Etch block format reference guide by topic | `edit_posts` | readonly | no | 127 |
| `restlesswp-etch-get-loop` | Get a single Etch loop preset by `id` | `edit_posts` | readonly | no | 125 |
| `restlesswp-etch-get-page` | Read a page's content as raw block markup and parsed block tree | `edit_posts` | readonly | no | 140 |
| `restlesswp-etch-get-style` | Get a single Etch style by `id` | `edit_posts` | readonly | no | 125 |
| `restlesswp-etch-get-stylesheet` | Get a single Etch global stylesheet by `id` | `edit_posts` | readonly | no | 125 |
| `restlesswp-etch-get-template` | Get a single block template by `id` (post ID — matches Etch's URL convention) | `edit_posts` | readonly | no | 126 |
| `restlesswp-etch-import-page` | Import a complete Etch design onto a page in one call | `edit_posts` | open_world | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 1291 |
| `restlesswp-etch-list-backups-field-groups` | List available Etch field-group definition backups | `edit_posts` | readonly | no | 61 |
| `restlesswp-etch-list-backups-pages` | List available page content backups | `edit_posts` | readonly | no | 61 |
| `restlesswp-etch-list-backups-styles` | List available style backups | `edit_posts` | readonly | no | 61 |
| `restlesswp-etch-list-backups-templates` | List available template content backups | `edit_posts` | readonly | no | 61 |
| `restlesswp-etch-list-components` | List all Etch components | `edit_posts` | readonly | no | 61 |
| `restlesswp-etch-list-field-groups` | List all Etch-native custom field groups (defined in Etch itself, not ACF) | `edit_posts` | readonly | no | 61 |
| `restlesswp-etch-list-loops` | List all Etch loop presets | `edit_posts` | readonly | no | 61 |
| `restlesswp-etch-list-styles` | List all Etch styles | `edit_posts` | readonly | no | 61 |
| `restlesswp-etch-list-stylesheets` | List all Etch global stylesheets | `edit_posts` | readonly | no | 61 |
| `restlesswp-etch-list-template-slots` | List the canonical template slugs the Etch builder will surface in its Templates panel | `edit_posts` | readonly | no | 33 |
| `restlesswp-etch-list-templates` | List all block templates for the active theme | `edit_posts` | readonly | no | 61 |
| `restlesswp-etch-orphan-detect-style` | Find style ids not referenced by any block | `edit_posts` | readonly | no | 61 |
| `restlesswp-etch-patch-page` | Modify or remove a specific block within a page WITHOUT replacing the entire content | `edit_posts` | idempotent | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 1865 |
| `restlesswp-etch-patch-template` | Modify or remove a specific block within a template WITHOUT replacing the entire content | `manage_options` | idempotent | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 1865 |
| `restlesswp-etch-update-component` | Update an existing Etch component by `id` (post ID) | `manage_options` | idempotent | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 2170 |
| `restlesswp-etch-update-field` | Update a single field within an Etch custom field group using merge semantics | `manage_options` | idempotent | no | 747 |
| `restlesswp-etch-update-field-group` | Update an Etch custom field group using merge semantics | `manage_options` | idempotent | no | 1080 |
| `restlesswp-etch-update-field-value` | Set Etch-native custom field values on a specific post | `edit_posts` | idempotent | no | 481 |
| `restlesswp-etch-update-loop` | Update an existing Etch loop preset by `id` | `manage_options` | idempotent | no (query preset config) | 945 |
| `restlesswp-etch-update-page` | WARNING: To add sections to an existing page, use etch-append-page instead — it is non-des… | `edit_posts` | idempotent | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 1104 |
| `restlesswp-etch-update-style` | Update an existing Etch style by `id` | `manage_options` | idempotent | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 1034 |
| `restlesswp-etch-update-stylesheet` | Update an existing Etch global stylesheet by `id` | `manage_options` | idempotent | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 735 |
| `restlesswp-etch-update-template` | Update an existing block template by `id` (post ID) | `manage_options` | idempotent | **yes** (code write (CSS/HTML; element scripts need unfiltered_html)) | 1365 |
| `restlesswp-get-post` | Get a single post, page, or custom post type item by ID — equivalent to GET /wp/v2/{type}/… | `read` | readonly | no | 1072 |
| `restlesswp-get-post-autosave` | Get one autosave of a post — equivalent to GET /wp/v2/{type}/{id}/autosaves/{autosave_id}. | `read` | readonly | no | 964 |
| `restlesswp-get-post-revision` | Get one revision of a post — equivalent to GET /wp/v2/{type}/{id}/revisions/{revision_id}. | `read` | readonly | no | 964 |
| `restlesswp-get-post-status` | Get one post status — equivalent to GET /wp/v2/statuses/{status}. | `read` | readonly | no | 774 |
| `restlesswp-get-post-type` | Get one post type — equivalent to GET /wp/v2/types/{type}. | `read` | readonly | no | 768 |
| `restlesswp-gf-create-entry` | Create a new Gravity Forms entry. | `gravityforms_edit_entries` | open_world | no | 842 |
| `restlesswp-gf-create-entry-note` | Create a new Gravity Forms entry note. | `gravityforms_edit_entry_notes` | open_world | no | 492 |
| `restlesswp-gf-create-feed` | Create a new Gravity Forms feed. | `gravityforms_edit_forms` | open_world | no | 480 |
| `restlesswp-gf-create-form` | Create a new Gravity Forms form. | `gravityforms_edit_forms` | open_world | no | 1592 |
| `restlesswp-gf-delete-entry` | Delete a Gravity Forms entry. | `gravityforms_delete_entries` | destructive | **yes** (deletion) | 138 |
| `restlesswp-gf-delete-feed` | Delete a Gravity Forms feed. | `gravityforms_edit_forms` | destructive | **yes** (deletion) | 136 |
| `restlesswp-gf-delete-form` | Delete a Gravity Forms form. | `gravityforms_delete_forms` | destructive | **yes** (deletion) | 126 |
| `restlesswp-gf-get-entry` | Get a single Gravity Forms entry by ID. | `gravityforms_view_entries` | readonly | no | 138 |
| `restlesswp-gf-get-feed` | Get a single Gravity Forms feed by ID. | `gravityforms_edit_forms` | readonly | no | 136 |
| `restlesswp-gf-get-form` | Get a single Gravity Forms form by ID. | `gravityforms_edit_forms` | readonly | no | 126 |
| `restlesswp-gf-get-guide` | Get a Gravity Forms reference guide by topic | `edit_posts` | open_world | no | 109 |
| `restlesswp-gf-list-entries` | List all Gravity Forms entries. | `gravityforms_view_entries` | readonly | no | 1703 |
| `restlesswp-gf-list-entry-notes` | List all Gravity Forms entry notes. | `gravityforms_view_entry_notes` | readonly | no | 61 |
| `restlesswp-gf-list-feeds` | List all Gravity Forms feeds. | `gravityforms_edit_forms` | readonly | no | 61 |
| `restlesswp-gf-list-forms` | List all Gravity Forms forms. | `gravityforms_edit_forms` | readonly | no | 61 |
| `restlesswp-gf-update-entry` | Update an existing Gravity Forms entry. | `gravityforms_edit_entries` | idempotent | no | 861 |
| `restlesswp-gf-update-feed` | Update an existing Gravity Forms feed. | `gravityforms_edit_forms` | idempotent | no | 531 |
| `restlesswp-gf-update-form` | Update an existing Gravity Forms form. | `gravityforms_edit_forms` | idempotent | no | 1655 |
| `restlesswp-happyfiles-create-folder` | Create a new HappyFiles folder | `upload_files` | open_world | no | 355 |
| `restlesswp-happyfiles-create-folder-item` | Assign items to a HappyFiles folder | `upload_files` | open_world | no | 479 |
| `restlesswp-happyfiles-delete-folder` | Delete a HappyFiles folder | `upload_files` | destructive | **yes** (deletion) | 127 |
| `restlesswp-happyfiles-delete-folder-item` | Remove items from a HappyFiles folder without deleting the items | `upload_files` | destructive | no* (annotated destructive, but only unassigns terms — wp_remove_object_terms) | 314 |
| `restlesswp-happyfiles-get-folder` | Get a single HappyFiles folder by its term ID. | `upload_files` | readonly | no | 127 |
| `restlesswp-happyfiles-get-guide` | Get a HappyFiles reference guide by topic | `edit_posts` | readonly | no | 127 |
| `restlesswp-happyfiles-list-folder-items` | List items (posts/attachments) in a HappyFiles folder with pagination | `upload_files` | readonly | no | 368 |
| `restlesswp-happyfiles-list-folders` | List all HappyFiles folders for a given post type | `upload_files` | readonly | no | 61 |
| `restlesswp-happyfiles-list-settings` | List all HappyFiles settings with current values, types, labels, and descriptions. | `manage_options` | readonly | no | 61 |
| `restlesswp-happyfiles-update-folder` | Rename a HappyFiles folder by its term ID. | `upload_files` | idempotent | no | 430 |
| `restlesswp-happyfiles-update-setting` | Update one or more HappyFiles settings | `manage_options` | idempotent | **yes** (site options) | 333 |
| `restlesswp-list-post-autosaves` | List the autosaves of a post — equivalent to GET /wp/v2/{type}/{id}/autosaves | `read` | readonly | no | 881 |
| `restlesswp-list-post-revisions` | List the revisions of a post — equivalent to GET /wp/v2/{type}/{id}/revisions | `read` | readonly | no | 1827 |
| `restlesswp-list-post-statuses` | List the post statuses exposed in REST — equivalent to GET /wp/v2/statuses | `read` | readonly | no | 691 |
| `restlesswp-list-post-types` | List the post types exposed in REST — equivalent to GET /wp/v2/types | `read` | readonly | no | 691 |
| `restlesswp-list-posts` | List posts, pages, or any custom post type — equivalent to GET /wp/v2/{type} | `read` | readonly | no | 4992 |
| `restlesswp-srm-create-redirect` | Create a new redirect. | `srm_manage_redirects` | open_world | no* per list — recommend **yes**: can redirect any URL incl. home | 1102 |
| `restlesswp-srm-delete-redirect` | Delete a redirect. | `srm_manage_redirects` | destructive | **yes** (deletion) | 126 |
| `restlesswp-srm-get-guide` | Get a Safe Redirect Manager reference guide by topic | `edit_posts` | readonly | no | 127 |
| `restlesswp-srm-get-redirect` | Get a single redirect by ID. | `srm_manage_redirects` | readonly | no | 126 |
| `restlesswp-srm-list-redirects` | List all redirects. | `srm_manage_redirects` | readonly | no | 61 |
| `restlesswp-srm-update-redirect` | Update an existing redirect. | `srm_manage_redirects` | idempotent | no* per list — recommend **yes**: can redirect any URL incl. home | 1171 |
| `restlesswp-tsf-delete-post-seo` | Remove all custom The SEO Framework data for one post by post ID (key), reverting it to TS… | `edit_others_posts` | destructive | **yes** (deletion) | 127 |
| `restlesswp-tsf-delete-term-seo` | Delete a term seo. | `edit_others_posts` | destructive | **yes** (deletion) | 127 |
| `restlesswp-tsf-delete-user-seo` | Delete a user seo. | `edit_users` | destructive | **yes** (deletion) | 127 |
| `restlesswp-tsf-get-post-seo` | Get The SEO Framework data for one post by post ID (key) | `edit_posts` | readonly | no | 127 |
| `restlesswp-tsf-get-pta-seo` | Get a single pta seo by ID. | `manage_options` | readonly | no | 127 |
| `restlesswp-tsf-get-setting` | Get a single setting by ID. | `manage_options` | readonly | no | 127 |
| `restlesswp-tsf-get-term-seo` | Get a single term seo by ID. | `edit_posts` | readonly | no | 127 |
| `restlesswp-tsf-get-user-seo` | Get a single user seo by ID. | `edit_posts` | readonly | no | 127 |
| `restlesswp-tsf-list-post-seo` | List The SEO Framework data (SEO title, meta description, canonical, robots, social) for e… | `edit_posts` | readonly | no | 61 |
| `restlesswp-tsf-list-pta-seo` | List all pta seo. | `manage_options` | readonly | no | 61 |
| `restlesswp-tsf-list-settings` | List all settings. | `manage_options` | readonly | no | 61 |
| `restlesswp-tsf-list-term-seo` | List all term seo. | `edit_posts` | readonly | no | 61 |
| `restlesswp-tsf-list-user-seo` | List all user seo. | `edit_posts` | readonly | no | 61 |
| `restlesswp-tsf-update-post-seo` | Set The SEO Framework data for one post by post ID (key): title (SEO title), description (… | `edit_others_posts` | idempotent | no | 1633 |
| `restlesswp-tsf-update-pta-seo` | Update an existing pta seo. | `manage_options` | idempotent | no | 1345 |
| `restlesswp-tsf-update-setting` | Update an existing setting. | `manage_options` | idempotent | **yes** (site options) | 333 |
| `restlesswp-tsf-update-term-seo` | Update an existing term seo. | `edit_others_posts` | idempotent | no | 1413 |
| `restlesswp-tsf-update-user-seo` | Update an existing user seo. | `edit_users` | idempotent | no* (user *meta* write, not a role/account change) | 508 |
| `restlesswp-update-post` | Update a post, page, or custom post type item — equivalent to POST/PUT /wp/v2/{type}/{id} | `edit_posts` | idempotent | no* (content write; same caveat; can also change status/author/slug) | 3370 |

Totals: **48 dangerous** by the ticket's list — 26 deletions (every `delete-*` plus the three Etch
`bulk-replace-*`), 6 site-option writes (`acf-update-options-value`, `acss-create/update/bulk-update-variable(s)`,
`happyfiles-update-setting`, `tsf-update-setting`), 16 code writes (ACSS classes, Etch styles/stylesheets/
components/templates/pages). **0** plugin/theme installs or activations, **0** user or role changes, **0**
file writes. Two `no*` rows (`srm-create/update-redirect`) are recommended for the dangerous set: a
redirect from `/` or a catch-all regex takes a site down as surely as a deletion.

The classification is derivable mechanically: everything RestlessWP annotates `destructive` is dangerous
except `happyfiles-delete-folder-item`; the other 22 dangerous tools are writes to settings or CSS/markup.
The `readonly` annotation is reliable for "open": all 76 readonly tools are pure reads. A gateway rule of
"`readOnlyHint` → open; `destructiveHint` → dangerous; everything else by a per-tool list (16 + 6)" needs a
22-name override list for 0.13.0.

### 3.4 Per-item checks the capability column does not show

- ACF post values: `edit_posts` at the route plus `edit_post` for the specific post (readme "ACF Post Values").
- HappyFiles folder-item assign/remove: `upload_files` plus `edit_post` on every target ID
  (`modules/happyfiles/class-happyfiles-folder-items-controller.php:165-219`; changelog 0.9.13).
- Etch: persisting an element script requires `unfiltered_html`
  (`modules/class-etch-normalizer.php:416-438`; changelog 0.9.13). RestlessWP also **blocks external access to
  Etch's own `etch-api` namespace by default** (`modules/class-etch-api-guard.php`; readme "Etch API
  Protection"), which matters for `site-request` — a raw REST call into `etch-api/*` with an app password gets
  403 unless the site opts out with `restlesswp_block_etch_api`.
- Content module: core's `WP_REST_Posts_Controller` permission callbacks run inside `rest_do_request`
  (per-type `create_posts`/`edit_others_posts`/`publish_posts`, per-post `edit_post`/`delete_post`, and the
  `unfiltered_html` KSES pass) on top of the coarse gate (`classes/class-rest-bridge.php:69-105`).
- Gravity Forms: capabilities are GF's own and honour GF's `gform_*` capability mapping
  (`modules/class-gf-module.php:290-305`).

## 4. Does the catalog vary per site?

### 4.1 Yes — by installed plugins and their versions

- The module registry instantiates every `modules/class-*-module.php` and keeps only those whose
  `is_active()` (target plugin file active, `classes/class-base-module.php:107-121`) and `check_version()`
  (`:131-149`) pass (`classes/class-module-registry.php:156-175`). Floors: ACF 6.7.0 (free or Pro), ACSS
  3.0.0, Etch 1.5.0, GF 2.4, HappyFiles Pro 1.6.0, SRM 2.0, TSF 5.0.0 (each module's `get_min_version`).
  The content module is always active (`modules/class-content-module.php:75-77`).
- Within a module the resource set can vary: ACF post-type/taxonomy controllers need ACF ≥ 6.1 and
  options pages need ACF Pro (`modules/class-acf-module.php:79-96`). Measured: example-site (ACF Pro, ACSS, Etch, GF,
  HappyFiles) 127 tools on 0.9.11; example-two (ACF Pro, ACSS, Etch, GF) 116 on 0.9.14 and 131 on 0.13.0.
- The content module derives `list-posts`/`create-post`/`update-post` input schemas from the **live route
  table** (`modules/trait-content-post-abilities.php:5-12, 183-262`), by design picking up fields other plugins
  register on `wp/v2/posts` and `wp/v2/pages` (ACF's `acf`, SEO plugins). On example-two the derived
  `create-post` had 21 core properties and no `acf` (no field group had *Show in REST* on), but the schema
  bytes for these eight tools are site-specific in principle.
- The fallback server bridges **third-party abilities** with `meta.mcp.public = true` and type `tool`
  (`classes/class-mcp-tools.php:59-121, 167-192`; changelog 0.11.0), so a site with Meta Box's or ICF's
  abilities adds tools outside the `restlesswp-` prefix (example.net shows 20 `meta-box-*`; example-site registers
  `icf/get-guide`). WordPress core's own `core/get-site-info`, `get-user-info`, `get-environment-info` carry no
  `mcp` meta and are **not** bridged. Filters `restlesswp_mcp_expose_third_party` and
  `restlesswp_mcp_exposed_abilities` can change the set per site.
- **Version drift renames tools.** 0.13.0 renamed every collection `get-{plural}` → `list-{plural}`
  (`acf-get-field-groups` → `acf-list-field-groups`, `gf-get-forms` → `gf-list-forms`, …) and added the 15
  content tools; 0.10.0 had added a different `create-post` that 0.13.0 replaced with incompatible inputs
  and error codes (readme changelog 0.13.0, 0.10.0). Before 0.13.0 the four TSF single-item `get-*-seo`
  tools never existed on any site because the list tool occupied the same name (confirmed: the 0.9.14 dump
  logs "already registered" for `tsf-get-post-seo` etc., and the changelog says so).

### 4.2 No — not by user role

`tools/list` enumerates every exposed ability regardless of the caller (`class-mcp-tools.php:266-290`);
capability is only consulted in `tools/call`. The adapter path has an `mcp_adapter_tools_list` filter for
per-role hiding (`Handlers/Tools/ToolsHandler.php:76-92`) but RestlessWP does not use it. So two application
passwords on the same site see the same list; what differs is which calls succeed.

### 4.3 Caching decision

`list-site-tools(site)` can be cached **per site**, keyed on `serverInfo.version` from `initialize` plus the
site's active-plugin set (or simply invalidated on a tool-not-found `-32602`, which is what a stale name
produces). It cannot be keyed on plugin version alone across sites, and cannot be shared across sites with
different plugin sets. Per-tool `inputSchema` fetch is cheap (median 138 B); the expensive part is the list
itself (134–153 KB), so the cache should hold the whole `tools/list` response and serve names + one-line
descriptions from it.

## 5. Error shapes, result sizes, rate limiting

| Situation | Fallback server (what serves today) | Adapter path (if the ordering bug is fixed) |
|---|---|---|
| No / bad credential | HTTP **401**, WP REST body `restlesswp_not_authenticated`, no `WWW-Authenticate` | HTTP 401, `rest_forbidden` (`WP_Error` cast to `false`) |
| Malformed JSON-RPC | HTTP 200, `{"error":{"code":-32600,"message":"Invalid Request."},"id":null}` | HTTP 400, `-32600`/`-32700` with detail |
| Unknown method | HTTP 200, `-32601 "Method not found: …"` | HTTP 404, `-32601` |
| Unknown tool | HTTP 200, `-32602 "Unknown tool: …"` | HTTP 404, `-32003 "Tool not found: …"` |
| Notification / client response | HTTP 202, empty body | HTTP 202, empty body |
| Invalid input | `result:{content:[{type:"text",text:"Ability \"restlesswp/x\" has invalid input. Reason: key is a required property of input."}],isError:true}` | same (permission checked first, then `execute` re-validates) |
| Missing capability | `isError:true`, text `Ability "restlesswp/x" does not have necessary permission.` | `isError:true`, text `Permission denied` |
| Handler `WP_Error` (404/409/424/400) | `isError:true`, text = the handler's message (e.g. `Field group not found.`); HTTP status and `code` are **dropped** | same, message only |
| Exception in handler | `isError:true`, text `Ability "…" callback threw an exception: …` | same |
| Success | `content[0].text` = `wp_json_encode(result)`; `structuredContent` when result is an assoc array | same, plus `structuredContent` always |

Sources: `class-mcp-tools.php:351-380` (result/isError), `class-mcp-server.php:172-200, 226-240, 268-280`
(protocol errors), `abilities-api/class-wp-ability.php:519-571, 826-839, 590-603` (validation, permission,
exception messages), `Handlers/Tools/ToolsHandler.php:119-231, 333-341` and
`Infrastructure/ErrorHandling/McpErrorFactory.php:382-422` (adapter). Observed on both sites for the
fallback column.

- **Result size.** Nothing truncates or paginates a tool result; `list-*` tools return the whole collection
  (Etch pages return raw block markup and a parsed tree; GF `list-entries` honours `paging`; content
  `list-posts` honours `per_page`/`page`/`_fields` and returns `{items,total,total_pages}`). Mutations return a
  lean summary unless `verbose:true` (`class-abilities-registrar.php:506-521`). The gateway should expect
  results from a few hundred bytes (`acf-list-field-groups` on an empty site: 85 B) to tens of KB
  (guides, full pages, `list-posts` with `context=edit`).
- **Rate limiting.** None in RestlessWP, the adapter, or the Abilities API (searched for throttle/rate
  limit; only the adapter's session `last_activity` write throttle exists). The only cost control is
  core's `wp_pre_execute_ability` short-circuit hook, unused. Sites behind a host WAF may still throttle.
- **Error information loss.** The REST envelope's `code` (`not_found`, `conflict`, `forbidden`,
  `validation_error`, `module_inactive`, `version_unsupported`; readme "Error Codes") and HTTP status survive
  only on the REST routes; over MCP the gateway sees a message string and `isError`. If unhost wants
  typed failures for audit rows, it must classify by message text or use `site-request` for the REST route.

## 6. Surprises for other tickets

1. **The adapter never serves POST** (§1.2). The stateless fallback is the real transport, so the
   laravel/mcp client compatibility spike is simpler than feared (no `Mcp-Session-Id` round-trip) — but
   the client must tolerate a server that answers `tools/list` without ever having issued a session, and
   must not fail on the absence of `outputSchema`. Ask upstream to register the fallback at a later
   priority (> 16) or from `mcp_adapter_init`.
2. **No `WWW-Authenticate`, no distinction between missing and wrong credentials** (§2). The `wordpress`
   module's "verify via `/wp/v2/users/me`" is the right health check; a 401 there means re-onboard.
3. **Tool names are not stable across plugin versions** (§4.1). The gateway must never hardcode a
   RestlessWP tool name; the open/dangerous classification should key on **annotations plus a small
   override list**, not on names, and the cache must invalidate on `-32602`.
4. **The dangerous set is 48 of 166 and mechanically derivable** (§3.3): `destructiveHint` minus one, plus
   22 named settings/CSS writers. The ticket's categories "plugin/theme install", "user/role changes" and
   "file writes" are **empty** on RestlessWP today; `site-request` is the only route to those (core
   `wp/v2/users`, `wp/v2/plugins`, `wp/v2/themes`) and needs its own classification.
5. **Third-party bridging** (§4.1): a site can grow tools outside the `restlesswp-` prefix with unknown
   capabilities and annotations. The classification must have a default for unannotated tools (treat as
   dangerous).
6. **`site-request` into `etch-api/*` is blocked** by RestlessWP itself (§3.4).
7. RestlessWP 0.12.0+ is licensed via SureCart but function is not gated by the licence (readme
   "Licensing & Updates").

## Sources

- RestlessWP 0.13.0 — `https://github.com/alexmansfield/restlesswp` (private), tarball of `main` at
  `96c18c4`, pushed 2026-09-17: `restlesswp.php`, `classes/class-mcp-server.php`, `classes/class-mcp-tools.php`,
  `classes/class-abilities-registrar.php`, `classes/class-auth-handler.php`, `classes/class-base-module.php`,
  `classes/class-module-registry.php`, `classes/trait-ability-naming.php`, `classes/trait-descriptor-abilities.php`,
  `classes/class-rest-bridge.php`, `modules/class-content-module.php`, `modules/trait-content-post-abilities.php`,
  `modules/class-*-module.php`, `modules/class-etch-normalizer.php`, `modules/class-etch-api-guard.php`,
  `modules/happyfiles/*.php`, `readme.txt` (Authentication, Etch API Protection, Error Codes, Abilities API and
  MCP, Changelog 0.9.12–0.13.0), `release.json`.
- RestlessWP 0.9.14 — `~/Cove/Sites/example-two.localhost/public/wp-content/plugins/restlesswp/` (same files, older
  naming); 0.9.11 — `~/Cove/Sites/example.localhost/public/wp-content/plugins/restlesswp/`.
- WordPress MCP Adapter 0.5.0 — `~/Cove/Sites/example.localhost/public/wp-content/plugins/mcp-adapter/includes/`:
  `Core/McpAdapter.php`, `Core/McpVersionNegotiator.php`, `Transport/HttpTransport.php`,
  `Transport/Infrastructure/{HttpRequestHandler,HttpRequestContext,HttpSessionValidator,SessionManager,RequestRouter}.php`,
  `Handlers/Tools/ToolsHandler.php`, `Domain/Tools/{McpTool,RegisterAbilityAsMcpTool}.php`,
  `Infrastructure/ErrorHandling/McpErrorFactory.php`, `Servers/DefaultServerFactory.php`.
- WordPress 7.1 core — `~/Cove/Sites/example-two.localhost/public/wp-includes/`: `abilities-api.php`,
  `abilities-api/class-wp-ability.php`, `rest-api.php`, `rest-api/class-wp-rest-server.php`, `user.php`,
  `default-filters.php`, `class-wp.php`.
- authwp — `~/Cove/Sites/authwp/docs/spec.md` §14 (the 401 challenge it will add).
- Measurements: `wp eval-file` dumps of `RestlessWP_MCP_Tools::list_tools()` / `tool_map()` and each
  ability's schemas with the permission capability recovered by reflection, on both local sites at their
  installed versions and with 0.13.0 booted in-process (`--skip-plugins=restlesswp`), with SRM/TSF/HappyFiles
  force-registered in memory where their target plugin was absent; `curl` probes of the endpoint on both
  sites, anonymous and with a temporary application password (created and deleted for the purpose).
