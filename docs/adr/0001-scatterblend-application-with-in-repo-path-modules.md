# A Scatterblend application whose modules are in-repo path packages under the `unhost` vendor

unhost is a Scatterblend application: the starter-kit skeleton with the first-party `oauth` and `mcp` modules active, plus its own modules as Composer path packages under `packages/{key}`, scaffolded by `make:module {key} --path --vendor=unhost` and named `unhost/scatterblend-{key}` with namespace `Unhost\Scatterblend\{Key}`. The v1 modules are `sites` (the Site record, grants, the site connector contract), `wordpress` (the first integration) and `gateway` (the pass-through, its audit, tool classification and elevation, the outbound guard). A module is extracted to its own repository only when a real driver appears; the seam is enforced now by core's convention tests, so extraction stays mechanical.

## Decisions folded in

- **Module keys stay plain nouns even where they double.** `sites` yields `sites_sites`, `sites:sites:manage`, `sites:sites:view` beside `sites_grants`. Alternatives (`fleet`, `portfolio`, `inventory`, or renaming the record `Website`) were rejected: none is a word the operator would say unprompted, and a key that needs explaining costs more, every day, than one doubled word in three strings. The record is **Site**, matching authwp and Dashbird.
- **Vendor `unhost`, not `nonboxed` (the stub default) or the studio's own name.** `nonboxed/*` would make unhost's modules indistinguishable from first-party Scatterblend modules; `unhost/*` is already the right published name if a module is ever extracted, and the `scatterblend-` segment says what it plugs into.
- **The Scatterblend packages are path repositories for now, `vcs` before the first deploy.** None of `nonboxed/scatterblend{,-oauth,-mcp,-starter-kit}` has a git remote yet, and while unhost's modules are being built core will need fixes seen live across the symlink. This is the workbench posture of Scatterblend ADR 0007, and ADR 0007's lock guard stays off until the switch. Pushing `nonboxed/*` to GitHub is a precondition of deploying unhost, filed as an ask on the Scatterblend tracker and carried in this spec's deployment notes.
- **`gateway` is one module; elevation is a service inside it behind an `Elevation` contract, marked as the extraction seam.** Its only consumer is the gateway; switching the gateway off should remove the concentration risk whole; a separate `elevation` module would have to publish a contract for a second consumer that does not exist. If one appears, `make:module elevation` and moving the service across is mechanical.

## Consequences

- `config/scatterblend.php`: `modules` = `['oauth', 'mcp', 'sites', 'wordpress', 'gateway']`, `tenancy.mode` = `single`; `search` stays off until a feature needs it.
- The application owns only what Scatterblend leaves to it: the role set (content decided by the tenancy-and-grants ticket), dashboard composition and overrides by key, all in the published `ScatterblendServiceProvider`.
- `docs/adr/` and `CONTEXT.md` live at this repository's root (single context); the modules ship their own Boost guideline and skill stubs as `make:module` scaffolds them.
