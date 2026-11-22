# ADR-0003 : S5 abuse choices

- **Status** : `accepted`
- **Date** : 2026-11-23

## Validation

| Field        | Value |
|--------------|-------|
| Validated    | yes |
| Validated by | FortyTwo-Dev |
| Validated on | 2026-11-23 |

## Context

S5 is the abuse step: destination inspection with three outcomes, deterministic
typosquat detection, a local blocklist, anonymous reporting, takedown,
suspension, the signup kill switch and domain self-monitoring. `docs/04` fixes
the strategy, D-96/D-97/D-113/D-126/D-128/D-131 to D-133 fix the arbitrations,
and the two public promises that shape every choice are: no client URL ever
leaves the server for a reputation service, and a typosquat alone never blocks.

Several details were left open by the documents: the exact shape of the brand
seed, how the PSL is available without a network call, the order of the probes,
which RDAP endpoint receives the registrable domain, how the review queue is
read, and how D-113 behaves once Safe Browsing is removed by D-97.

## Decision

- **The schema follows the seed, not the other way around.**
  `protected_brands` is `(domain, category, type, source, checked_at)`;
  `brand` and `verified_at` disappear from `docs/07`, and the 46 verified
  entries keep their real count instead of the aspirational 200. `type`
  separates a registrable brand from a public service hosted on a subdomain.
  Categories are normalised to English (`bank`, `telco`, …) so one vocabulary
  exists in the CSV, the schema and the code. Nothing is invented: forgetting a
  brand is harmless, a wrong entry blocks a legitimate link (D-132).
- **The PSL is vendored.** `resources/psl/public_suffix_list.dat` (MPL-2.0,
  unmodified) is read through `PSL_PATH`, so tests and CI never download it and
  production has a deterministic fallback. A missing file falls back to the
  last two labels and reports the incident; it never fails a redirect.
- **The score is a table, not a model.** Local signals run first and can stop
  the inspection without a network call. Then DNS and address filtering, then
  RDAP only when it can change the outcome, then the HTTP probe and the
  certificate date.

  | Signal | Outcome |
  | --- | --- |
  | Blocklist hit | `block` |
  | Private, loopback or metadata address (first hop or redirect) | `block` |
  | Mixed Unicode scripts, or a known homoglyph | `block` |
  | Protected brand buried in a third-party subdomain | `block` |
  | Typosquat and domain younger than 30 days | `block` |
  | Typosquat alone | `review` |
  | Young domain with a login-like path | `review` |
  | Public IP literal, more than three subdomains, three redirects | `review` |
  | Timeout, DNS failure, RDAP or TLS failure | `accept` |

  A reviewed link is created **active** and carries `review_required_at` and a
  machine `review_reason` (`typosquat:substitution:laposte.fr`). They are
  internal: never in `LinkResource`, never in the export, never in the API.
  `abuse:review-queue` lists them; an operator decides.
- **Network probes are contracts.** DNS, the TLS certificate and RDAP are
  injectable, so every test is offline. The HTTP probe resolves each hop again,
  refuses a private hop, pins the checked address with `CURLOPT_RESOLVE`, sends
  `HEAD` then a ranged `GET`, follows at most three redirects and uses
  `InfinityURL-AbuseCheck/1.0`. Timeouts are three seconds and every failure
  fails open (D-126). `ABUSE_CHECK_ENABLED` defaults to true in production and
  is false in `phpunit.xml` and the E2E run.
- **RDAP receives the registrable domain only.** One lookup per domain, cached
  seven days; the URL never leaves. The endpoint is configurable (`RDAP_URL`),
  so a registry-specific endpoint can replace the default without a deploy.
- **Reporting is anonymous by construction.** `POST /v1/report` answers `202`
  whether the slug exists or not, including for the honeypot, which writes
  nothing. The rate limit is an HMAC of the address with the analytics salt
  under the `report` context, five per hour, never persisted; a missing salt
  logs and disables the limit rather than refusing reports. The contact email
  is encrypted with the `encrypted` cast and has no blind index. The link is
  resolved with `withTrashed()`. The ntfy alert carries the reference, the
  slug and the category, never the reporter's text or address.
- **`iurl.fr` stays redirects only.** `GET /report/{slug}` is a 302 to the
  dashboard form, allowed by name in `RestrictLinkHost`, with no body, no
  cookie and no click. The footer of `403`, `404` and `410` links to the same
  form with the slug; the pages remain static HTML with no external resource.
- **Operators work from Artisan, not from an admin screen.** `abuse:block-link`,
  `abuse:suspend-account`, `abuse:resolve-report`, `abuse:purge-reports`,
  `abuse:monitor-reputation`, plus the refresh and proposal commands. Blocking
  keeps the statistics and invalidates the cache through the existing observer.
  Suspension sets `users.suspended_at`; sign-in, tokens and export keep working,
  and only link creation is refused with `account_suspended`.
- **`LinkBlocked` is the fourth and last transactional message** (D-125). There
  is no `AccountSuspended` email: a fifth message would break the cap, so the
  suspension motive is delivered by the operator's reply, and the ADR records
  that limitation rather than quietly adding a fifth template.
- **D-113 is implemented against the local dumps only.** Safe Browsing is out
  by D-97, and Spamhaus DBL is not queried: a daily command checks `iurl.fr`
  and every client domain against `blocklist_domains` and alerts on ntfy. That
  is the source we can act on without adding a third party to the chain.
- **`SIGNUPS_OPEN` is closed by default.** `.env.example` opens it locally, the
  config defaults to false, and `RegisterUser` refuses with `signups_closed`
  before touching the database.
- **Reports expire.** Twelve months for any report, ninety days for a rejected
  one, purged by a daily command.

## Consequences

- The destination of an existing link can still be edited to a malicious URL:
  the check runs at creation only, as `docs/04` §3 states. Re-inspecting an
  edit is a deliberate non-goal of v0.1 and would need its own decision.
- The homoglyph rows of `lookalike_domains` are largely redundant with the
  mixed-script detector. They are kept because they produce the most citable
  reason, and the table lookup runs before the script heuristic.
- A missing analytics salt disables report rate limiting. The alternative,
  refusing reports, would silence the only abuse channel during an incident.
- The blocklist is a 30-day rolling window: one failed download is harmless,
  but a parser broken for a month would empty the table. The command exits
  non-zero only when every source failed, and the stale purge is skipped in
  that case.
