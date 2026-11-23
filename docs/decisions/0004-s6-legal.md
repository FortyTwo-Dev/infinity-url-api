# ADR-0004 : S6 legal documents, public pages and self-host documentation

- **Status** : `proposed`
- **Date** : 2026-11-23

## Validation

| Field        | Value |
|--------------|-------|
| Validated    | no |
| Validated by | [À FOURNIR : validateur] |
| Validated on | [À FOURNIR : date] |

## Context

S6 publishes documents that bind the publisher: legal notice, terms, acceptable
use policy, privacy policy, subprocessor list, client clause, DPA and the
self-host quickstart. `docs/03` and `docs/06` contain the facts, not the
clauses. The rule is therefore source-first: every factual sentence of a future
page must point at a file in `docs/` or a code path, and a sentence without a
source is either removed or raised as a question.

The identity data is not available yet: SIREN, RNE number, registered address,
publication director, OVH registered office and the general contact address.
S6.1 is therefore limited to the internal documents. S6.2 cannot start, and no
public page can be merged, until those values are provided and the texts are
reviewed.

The S5 code is merged and changes the factual base: destination inspection,
anonymous reports, account suspension and the signup kill switch now exist.
The audit below checks the documents against that code, not against memory.

## Decision

- **The public legal prose is French only in S6.** The English routes serve the
  same French text with a translated notice, because the French version
  prevails. This is an explicit exception to D-92 for legal content: the prose
  lives in a typed content module, not in the i18n catalogs, because it is
  content and not an interface label. The exception is recorded in the web ADR.
- **No clause is invented.** A statement without a source is removed or raised
  as a question. The audit table below is the contract between the documents and
  the published pages.
- **The entity is InfinityApp, entrepreneur individuel (micro-entreprise).**
  "Infinity Project" is the public brand, never the contracting party (D-81,
  D-85).
- **The public promise on reports is qualification under 48 hours.** The 24-hour
  acknowledgment of `docs/04 §4.2` becomes an internal target, not a public
  promise: no automatic email exists, and an anonymous report has no address to
  answer to. A reporter who leaves an email may receive a reply.
- **D-125 caps the transactional messages at four; the code sends three.**
  `VerifyEmail`, `ResetPassword` and `LinkBlocked` are the implemented messages.
  No public text counts them. The fourth message must be confirmed before v0.1
  validation; the point is left open in the validation gate rather than filled
  with an invented template.
- **The display preference is disclosed.** The public and legal pages read
  `iurl:theme` from `localStorage` and write it when the visitor uses the theme
  toggle. It is not a cookie, it is never transmitted, and it does not identify
  anyone. The privacy policy says so in one sentence; the "no cookie" promise is
  unchanged.
- **The country is out of v0.1.** D-120 is enforced by the code: the column
  exists, the dimension stays empty. The privacy policy, the LIA-2 and the
  client clause describe device class only. The country is announced for v0.2,
  not as a current processing.
- **Aggregate retention is 30 days by default, driven by
  `STATS_RETENTION_DAYS`.** The previous claim of a 12-month self-host default
  was not implemented; the document is corrected, the code is not changed.
- **There is no object storage in v0.1.** D-102 is a local encrypted dump pulled
  from the operator's machine, rotated 30 days, RPO 24 hours. The subprocessor
  page does not list a backup bucket.
- **Destination inspection has recipients, not subprocessors.** The target site
  receives a `HEAD` then a ranged `GET` from our address, with the tested URL and
  up to three redirect hops. The RDAP service and the authoritative registry
  receive the registrable domain only. Neither is a subprocessor; both are
  recipients of the check. No client URL is sent to a reputation service: the
  blocklists are downloaded dumps (D-96).
- **The DPA is a versioned template, the client clause is published.** The DPA
  lives in `docs/legal/dpa.md`, is available on request and is not a public
  page. The clause of `docs/03 §4` is published and copyable, with the contact
  address.
- **The legal pages are prerendered and indexable.** They use the public layout,
  carry `robots: index, follow`, set `lang="fr"` even on `/en/legal/*`, load no
  third party and hold at 360 px. The marketing page follows the same rules.
- **No price, SLA, certification, DPO or "100% EU" claim.** There is no paid
  offer in v0.1 (D-127) and no SLA (D-27). The phrasing on transfers is
  "no navigation data leaves the European Union", never a global "100% EU".

## Claim-to-source audit

The tables list the statements the future pages may make. A statement absent
from this table must not be published.

### Legal notice

| Claim | Source |
| --- | --- |
| The publisher is InfinityApp, entrepreneur individuel (micro-entreprise) | D-81, `docs/00` |
| "Infinity Project" is the public brand, not the contracting party | D-85, `docs/00` |
| SIREN, RNE number, registered address, publication director | `[À FOURNIR]` |
| The host is OVH | D-60, `docs/03 §7` |
| OVH registered office | `[À FOURNIR]` |
| Abuse contact is `abuse@iurl.fr` | `docs/04 §4.1`, `app/pages/abuse.vue` |
| General contact address | `[À FOURNIR]` |
| The required mentions follow LCEN art. 6-III | Law, not a product fact |

### Terms

| Claim | Source |
| --- | --- |
| The service shortens a URL and redirects with a `302`, no interstitial | D-106, `docs/10 §8` |
| An account and a verified email are required before the first link | D-22, `docs/02 §1`, `app/Actions/Links/CreateLink.php` |
| The free plan allows five active links | D-21, `config/identity.php`, `app/Services/LinkQuota.php` |
| A deleted or expired link answers `410`; a deleted slug is never reused | D-51, D-117, `docs/10 §8` |
| Custom domains are not available in v0.1 | D-127 |
| There is no SLA | D-27, `docs/02 §2` |
| There is no paid offer in v0.1 | D-127 |
| Liability is limited; the clause does not cover regulatory sanctions | D-83 |
| Registrations can be closed without notice through a kill switch | D-66, `app/Actions/Auth/RegisterUser.php` |
| A malicious link can be blocked; the account can be suspended | `docs/04 §4.2`, §4.3, `app/Actions/Abuse/BlockLink.php`, `app/Actions/Abuse/SuspendAccount.php` |
| A blocked link keeps its statistics; the owner is notified with the reason | `docs/04 §4.2`, `app/Mail/LinkBlocked.php` |
| The account can be deleted; deletion is logical for 30 days, then physical | D-119, `app/Actions/Auth/DeleteAccount.php`, `app/Actions/Auth/PurgeDeletedUsers.php` |
| The account data can be exported in JSON | D-111, `GET /v1/me/export`, `app/Services/PersonalDataInventory.php` |
| The code is AGPL-3.0-only and the source link is in the footer | D-70, `LICENSE`, `app/components/app/Footer.vue` |
| Applicable law | `[À VALIDER : droit français]` |

### Acceptable use policy

| Claim | Source |
| --- | --- |
| The link owner is responsible for the destination | `docs/04 §3.2`, §4.3 |
| Prohibited uses: phishing, malware, illegal content, evasion of a block | `docs/04 §3.1`, §4, `docs/02 §5` |
| At creation, the destination is inspected once: scheme, DNS, private addresses, blocklists, typosquat, RDAP, TLS, redirects | `docs/04 §3.1`, `app/Services/Abuse/DestinationInspector.php` |
| The inspection sends a `HEAD`, then a ranged `GET`, follows at most three redirects, times out after three seconds and signs `InfinityURL-AbuseCheck/1.0` | `app/Services/Abuse/HttpDestinationProbe.php`, `config/abuse.php` |
| The target site sees our address and the tested URL | `docs/04 §3.3`, `app/Services/Abuse/HttpDestinationProbe.php` |
| RDAP receives the registrable domain only, never the full URL | `app/Services/Abuse/RdapClient.php`, `config/abuse.php` |
| Blocklists are local dumps; no client URL is sent to a reputation service | D-96, `app/Actions/Abuse/RefreshBlocklists.php` |
| A timeout or a DNS failure accepts the link: no proof of danger is not proof of safety | D-126, `app/Services/Abuse/DestinationInspector.php` |
| A report is anonymous, answers `202` whether the link exists or not, and never blocks automatically | `docs/04 §4.1 bis`, `app/Http/Controllers/ReportController.php`, `app/Actions/Abuse/CreateReport.php` |
| A human qualifies a report under 48 hours | `docs/04 §4.2`, `i18n/locales/fr.json` (`abuse.report.responseTime`) |
| The 24-hour acknowledgment is an internal target, not a public promise | This ADR, `docs/04 §4.2` |
| A malicious link answers `403` with an explanation, not a `404` | `docs/04 §4.2`, `docs/10 §8`, `resources/views/errors/403.blade.php` |
| A suspended account keeps its session, export and tokens; only link creation is refused | `docs/04 §4.3`, `app/Actions/Abuse/SuspendAccount.php`, `app/Actions/Links/CreateLink.php` |
| The service is a hosting service under the DSA, with a contact point, notification and action, motivated restrictions and no general monitoring obligation | `docs/04 §5` |
| Reports and appeals go to `abuse@iurl.fr` and `/report` | `docs/04 §4.1`, `app/pages/abuse.vue` |

### Privacy policy

| Claim | Source |
| --- | --- |
| InfinityApp is the controller for accounts, logs and anti-abuse; the client is the controller for the audience measurement, InfinityApp being the processor | `docs/03 §3`, `docs/06` |
| Redirection processes the IP in memory, the slug and the user-agent in memory, and persists nothing | `docs/03 §2`, `docs/10 §8` |
| Audience measurement stores a daily and a weekly HMAC, a device class and a date; the IP is never persisted | `docs/03 §2`, `docs/06 B.2`, `app/Actions/Analytics/CaptureClick.php` |
| The key contains the link id and the salt, so two links cannot be correlated | D-02, `docs/06 B.4` |
| The salt is destroyed at rotation; a past fingerprint cannot be recomputed | D-30, `app/Services/Analytics/AnalyticsSalt.php`, `app/Console/Commands/RotateAnalyticsSaltCommand.php` |
| The country is not processed in v0.1; the column exists and stays empty | D-120, `app/Services/Analytics/StatsStore.php` |
| Aggregates are kept 30 days by default on the hosted instance, configurable through `STATS_RETENTION_DAYS` | D-39, `config/analytics.php`, `app/Console/Commands/PurgeStatsCommand.php` |
| The email is encrypted at rest with a blind index; the password is Argon2id; the TOTP secret is encrypted; recovery codes are hashed | D-110, `app/Models/User.php`, `config/hashing.php` |
| The account lives for its duration plus 30 days of grace | D-119, `app/Actions/Auth/PurgeDeletedUsers.php` |
| The export is an allowlist: account, workspace, links and aggregated statistics | D-111, `app/Services/PersonalDataInventory.php` |
| Reports store the slug, the category, the free text and an optional encrypted email; never an IP, a user-agent, a referer or a rate-limit fingerprint | `docs/04 §4.1 bis`, `app/Models/Report.php`, `app/Actions/Abuse/CreateReport.php` |
| A report is kept 12 months, 90 days if rejected | `docs/03 §5`, `app/Actions/Abuse/PurgeReports.php` |
| The report rate limit is an HMAC of the address with the analytics salt under the `report` context, five per hour, one hour in Valkey, never persisted | `app/Providers/AppServiceProvider.php`, `config/abuse.php` |
| The operator alert contains the reference, the slug and the category, never the reporter's text, email or address | `app/Services/Abuse/ReportNotifier.php` |
| Technical logs keep the IP at the access layer, the method, the URI without query string and the status, for 72 hours | D-36, `docs/03 §2`, `docs/08 §9` |
| The application logger never receives `REMOTE_ADDR` | `docs/06 C.1`, privacy rule |
| Backups are local, encrypted, rotated 30 days and RPO 24 hours; a restoration exercise is required before public opening | D-102, `docs/08 §10` |
| Emails are transactional only: verification, password reset, blocked link; no image, no pixel | D-125, `app/Mail/TransactionalEmail.php`, `app/Mail/*.php` |
| The sender is `noreply@infinityproject.fr`, the reply-to `contact@infinityproject.fr` | D-125, `app/Mail/TransactionalEmail.php` |
| The email is sent through OVH MX Plan | D-98, `docs/03 §6` |
| The destination inspection sends a request to the target and to RDAP; the target sees our address and the tested URL, RDAP sees the registrable domain only | `docs/04 §3.3`, `app/Services/Abuse/HttpDestinationProbe.php`, `app/Services/Abuse/RdapClient.php` |
| No cookie is set on `iurl.fr` | D-35, D-77, D-80, `tests/Feature/RedirectPrivacyTest.php` |
| The site stores the display preference in `localStorage`; it is never transmitted | `app/lib/theme/theme.ts`, `nuxt.config.ts` |
| No third-party script, font, pixel or analytics is loaded | D-77, frontend rule |
| Rights: access, rectification, erasure, portability, objection; a complaint can be sent to the CNIL | RGPD arts. 15-21 and 77; `GET /v1/me/export` |

### Subprocessors

| Claim | Source |
| --- | --- |
| OVH hosts the service in France | D-60, `docs/03 §6` |
| OVH MX Plan sends the transactional emails from France | D-98, `docs/03 §6` |
| URLhaus / abuse.ch provides a public blocklist dump, downloaded daily; no client data is sent, and Switzerland benefits from an adequacy decision | D-96, `docs/03 §6` |
| Prometheus, Grafana and ntfy are self-hosted on the same server; they receive aggregated metrics and internal alerts, no personal data beyond the report reference, slug and category | D-99, `docs/03 §6`, `app/Services/Abuse/ReportNotifier.php` |
| There is no object storage in v0.1; the backup is a local encrypted dump | D-102, `docs/08 §10` |
| RDAP and the target site are recipients of the check, not subprocessors | `docs/04 §3.3`, this ADR |
| The AI triage and the payment provider are v0.2, not active in v0.1 | D-129, D-127 |
| The DPA is available on request | `docs/03 §7`, `docs/legal/dpa.md` |

### Client clause

| Claim | Source |
| --- | --- |
| The client is the controller; InfinityApp is the processor | `docs/06 B.1`, `docs/03 §3` |
| The links record aggregated clicks and device type; the country is not processed in v0.1 | `docs/03 §4` corrected, D-120 |
| No cookie is set on the visitor's device | D-35, `docs/03 §4` |
| The IP is used transiently and transformed into a fingerprint tied to one link | `docs/06 B.2`, `docs/06 B.4` |
| The fingerprint is deleted within one week and cannot be recomputed | D-30, `docs/03 §4` |
| The statistics are never cross-referenced between links and cannot identify the visitor | D-02, D-03, `docs/03 §4` |
| The visitor can object by contacting the client | `docs/03 §4` |
| The client must publish the clause and handle the objection | `docs/03 §4`, `docs/06 B.6` |

### Marketing page

| Claim | Source |
| --- | --- |
| A short link redirects with a `302`, no interstitial | D-106, `docs/10 §8` |
| No cookie on `iurl.fr` | D-35, D-77, D-80 |
| No IP stored | D-32, privacy rule |
| No row per click | D-15, `docs/07` |
| Aggregated statistics: clicks and device type | `docs/03 §2`, D-120 |
| The QR code is generated in the browser | D-24, `app/components/LinkQr.vue` |
| The v0.1 offer is free, without SLA and without a paid plan | D-127, D-27, D-20 |
| The source is AGPL-3.0-only | D-70, `LICENSE` |

### README and quickstart

| Claim | Source |
| --- | --- |
| PHP, Composer, Podman, PostgreSQL, Valkey and Mailpit are the local prerequisites | `docs/08 §5`, `compose.yml` |
| The API runs on port 8000 and the dashboard on 3000 by default | `README.md`, `scripts/e2e.sh` |
| `SIGNUPS_OPEN`, `ABUSE_CHECK_ENABLED`, `PSL_PATH`, `URLHAUS_*`, `RDAP_URL` and `NTFY_URL` drive the S5 behaviour | `.env.example`, `config/abuse.php`, `config/identity.php` |
| The test database is created once, then `php artisan test` runs on PostgreSQL and Valkey | `AGENTS.md`, `docs/09 §2.4` |
| `iurl.fr` is exercised locally with `LINK_HOST=iurl.fr` and `curl -H 'Host: iurl.fr'` | `.env.example`, `README.md` |
| The scheduler runs the salt rotation, the analytics purge and the abuse commands | `routes/console.php` |
| The brand import and the blocklist refresh are manual first-run commands | `docs/08 §10.3` |
| The legal pages are published in French; the DPA is available on request | This ADR, `docs/legal/dpa.md` |

## Open questions and validation gate

| # | Question | Status |
| --- | --- | --- |
| Q-01 | SIREN, RNE number, registered address, publication director, OVH registered office, general contact address | **Blocking S6.2 and the merge** |
| Q-02 | Which message is the fourth of D-125, if any? | **Open until v0.1 validation**; the public text lists the three implemented messages |
| Q-03 | Who reviews the legal texts, and on what date? | **Blocking the merge** |
| Q-04 | Applicable law and jurisdiction clause | **To validate** |
| Q-05 | Does the destination URL appear in the application log when a probe fails? | **To verify**; the LIA and the register must reflect the answer |
| Q-06 | D-39 says a 12-month self-host default, while `config/analytics.php` and `.env.example` default to 30 days | **Open**; the S6 texts state the implemented 30-day default and do not change D-39 |

The ADR stays `proposed`, and no page is merged, until Q-01 and Q-03 are
answered.

## Consequences

- S6.1 can be written without identity data; S6.2, S6.3 and the public part of
  S6.4 cannot.
- The documents now state three transactional messages, 30-day aggregate
  retention, no country in v0.1, no object storage and a 48-hour public report
  promise. Each correction removes a false statement rather than adding a
  feature.
- The DPA is a template with `[À FOURNIR]` fields. It is not published as a
  page and is not signed.
- The legal pages will be French only. The English route carries a translated
  notice and the same French text, with `lang="fr"`.
- The theme preference is disclosed, not removed. The dashboard and the public
  pages keep the toggle.

## Rejected alternatives

- **Invent the missing identity values.** Rejected: a false SIREN or address in
  a legal notice is worse than an unpublished page.
- **Publish four emails because D-125 says four.** Rejected: the fourth template
  does not exist. The cap stays, the count is not published.
- **Keep the 24-hour acknowledgment as a public promise.** Rejected: no
  automatic email exists and an anonymous report has no address. The public
  promise is 48 hours; 24 hours stays an internal target.
- **Translate the legal texts into English.** Rejected for S6: the French text
  is the only normative version, and a translation would create two authorities.
- **Put the legal prose in `fr.json` and `en.json`.** Rejected: prose is
  content, not an interface label; duplicating it in the catalogs would create
  two sources of truth.
- **List the v0.2 subprocessors as current.** Rejected: the AI triage and the
  payment provider are not active in v0.1.
- **Keep the object storage row because `docs/03 §6` had it.** Rejected: D-102
  is the later, frozen decision and the code follows it.
- **Write a DPIA anyway.** Rejected: the criteria of art. 35 are not met; the
  decision not to perform one is motivated in `docs/16`.
