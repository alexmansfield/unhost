# unhost

A tool for managing many websites in which human operators and AI agents are first-class users; a Scatterblend application whose first feature is a gateway that lets every connected agent reach every connected site through one MCP connection. Scatterblend's own vocabulary (tenant, membership, actor, capability, tier, role, module key, operator, agent, connection, contribution, slot) is inherited unchanged from `scatterblend.localhost/CONTEXT.md` and not restated here.

## Language

### Sites and who reaches them

**Site**:
One website unhost manages, whatever platform serves it (WordPress today; others later). The `site` argument every gateway tool takes names one.
_Avoid_: Website, property, install, instance

**Slug**:
A site's name on the wire: short, kebab-case, unique, spoken by an operator and typed by a model (`example-site`). It is the site's identity — a site that moves domain keeps its slug — and staff may rename it.
_Avoid_: Handle, key, id, code

**Domain**:
The domain name where one site is served (`staging.example.org`). One per site. The site's public address, which is not necessarily the address an integration talks to.
_Avoid_: URL, host, hostname, address

**Registered domain**:
The registrable apex a later module manages with a registrar (`example.org`). Derived from a site's domain, and shared by every site under it.
_Avoid_: Domain (unqualified — that is the site's own), zone, apex

**Host**:
Reserved, unused in this slice: the company or server that hosts a site. Never the domain a site is served from.
_Avoid_: Using it for a site's domain

**Platform**:
What a site currently runs, as far as unhost is concerned (`wordpress`). A current fact rather than part of a site's identity: it may change when a site is rebuilt, and it may be absent — a site unhost knows about but cannot reach into, such as one it only monitors.
_Avoid_: Type, kind, CMS, stack

**Site connector**:
What one platform integration implements so the rest of unhost can reach sites of that platform: it names itself for screens, says whether a given actor can reach a given site and why not, and offers the platform's own panel for the site's page. A credential never leaves the integration that owns it.
_Avoid_: Driver, adapter, provider (a provider contract is a feature module's, not this)

**Paused**:
A site staff have switched off at the gateway: it is still listed and still managed, but every site tool refuses it. The one status a human sets; every other status is worked out at the moment it is asked, and is particular to the actor asking.
_Avoid_: Disabled, inactive, archived, suspended

**Client**:
An organisation the studio works for, to which sites belong and to which people are attached. Also the name of the role those people hold. A site belongs to at most one client; a site with none is the studio's own.
_Avoid_: Customer, account, company, tenant (a client is not a tenant — there is one tenant, the studio)

**Attachment**:
A client-role user's standing with one client: the row that says this person is one of the client's people and what access they hold to every site the client owns. A person is attached to one client.
_Avoid_: Grant, client membership, client user

**Assignment**:
A subcontractor-role user's standing with one site: the row that says this person works on this site and what access they hold to it. Made by staff independently of who owns the site.
_Avoid_: Grant, site membership, allocation

**Access**:
Whether an actor may act on a site, and how deeply: `view`, `edit` or `manage`. Staff have access to every site by role alone; everyone else has it only through an attachment or an assignment, and the value on that row is their access. A client's people at `manage` access are the client's **managers** and may invite, detach and set the access of the client's other people.
_Avoid_: Permission (Scatterblend's capability is the only authorization unit), level (a Scatterblend tier word), depth, reach, grant

### Roles

The studio's role set, declared by the application. Every user — person or agent — holds exactly one.

**Staff**:
A person or agent of the studio. Holds every capability and has access to every site without any attachment or assignment. The founding role.
_Avoid_: Admin, owner, super admin, operator (Scatterblend's operator is a bootstrap flag in single mode, not a role)

**Subcontractor**:
A person or agent outside the studio who works on particular sites for the studio. Has access only to the sites they are assigned to, at the access each assignment gives.
_Avoid_: Contractor, freelancer, staff, collaborator

**Client** (role):
A person or agent belonging to a client organisation. Has access to every site the client owns, at the access their attachment gives.
_Avoid_: Customer, viewer, editor (those are access values, not roles)
