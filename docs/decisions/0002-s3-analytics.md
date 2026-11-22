# ADR-0002 : S3 analytics pipeline choices

- **Status** : `accepted`
- **Date** : 2026-11-22

## Validation

| Field        | Value |
|--------------|-------|
| Validated    | yes |
| Validated by | FortyTwo-Dev |
| Validated on | 2026-11-22 |

## Context

`docs/11-pipeline.md` fixes the pipeline, `docs/07-schema.md §7` fixes the three
aggregate tables and `docs/10-api.md §7` fixes the two statistics endpoints, but
several details were left open: how the hot path learns the internal link id when
the redirect cache only knows the host and the slug, the exact JSON and CSV shape,
the reporting window semantics, the retention formula, where the salt lives and
how it rotates, and how the "404 60/min" row of `docs/10 §6` can coexist with the
statement that rate limiting is Traefik's job. S3 also has to keep two promises
that a counter can silently break: no personal data leaves the request, and
erasing a link erases its audience.

The constraints are the frozen decisions (D-30 to D-39, D-107, D-111, D-120,
D-134), the privacy rule, and the fact that `RedirectCacheTest` describes the
cache format literally.

## Decision

- **The cached 302 carries `link_id`.** The value becomes
  `{status, location, link_id}`; `RedirectResult::isValid()` requires a valid
  location *and* a link id for a 302. The link id is needed by the analytics job:
  the hot path only knows the host and the slug, and resolving the id in the
  worker would cost one database read per click. An entry without `link_id` is
  forgotten and reloaded at the next click, so the change is self-healing. No
  cache key version is introduced, precisely to keep the assertions of
  `RedirectCacheTest` valid. 404, 403 and 410 entries carry no link id.
  `link_id` is the internal integer: it lives in Valkey only, never in an HTTP
  response and never in a log.
- **The click is pushed to the queue after the response**, from an
  `app()->terminating()` callback that swallows every failure and logs
  `analytics enqueue failed` without any payload. `dispatchAfterResponse()` is not
  used: it calls `dispatchSync()` and would run the job inside the web process,
  defeating D-52. Jobs run on their own `analytics` queue; `config/horizon.php`
  makes Horizon listen on `default` and `analytics`.
- **Dedup keys keep the documented composition**, `HMAC-SHA256(salt, ip|link_id)`
  and `HMAC-SHA256(salt, ip|link_id|date)`. Both keys survive one hour past their
  bucket, so a click made just before midnight or just before the Monday
  rotation still deduplicates when its job runs late. Past that grace the bucket
  is closed: the late job counts its click and abandons the unique instead of
  counting it twice. Rotation destroys the salt but does not sweep `u:w:*` keys:
  after the rotation the old fingerprints match nothing, and they expire on
  their own (`docs/11 §7`). `docs/07 §12` is read as "the old fingerprints are
  emptied", not as a mass delete.
- **Statistics shape.** `GET /v1/links/{id}/stats` returns `link_id` (the public
  ULID), `interval`, `from`, `to`, `retention_days`, `clamped`, `summary.clicks`,
  `series` and `devices`. A day bucket is `{date, clicks, uniques}`, a week bucket
  is `{iso_year, iso_week, week_start, clicks, uniques}`, a device row is
  `{device, clicks}`. `summary` totals clicks only. **No total of uniques exists
  anywhere**, not in the body, not in the export, not in the CSV: uniques are not
  additive (D-34). Only buckets with rows are emitted, no zero filling.
- **Range semantics.** Dates are UTC `Y-m-d`. `to` defaults to today, `from` to
  `to - 29 days`. The window is clamped to the retention window
  (`today - (retention_days - 1)`) and to today; the effective `from` and `to` are
  echoed and `clamped` tells the client whether anything moved. The cap is 366
  buckets. `interval=week` aligns both bounds on ISO weeks and drops a first week
  that is only partially retained, so clicks and uniques always describe the same
  days; when no whole ISO week fits the requested window (it sits inside the
  partially retained first week), the report is empty rather than mixing a day of
  clicks with a week of uniques.
- **Retention keeps exactly N calendar days including today**:
  `purgeBefore(today, days) = today - (days - 1)`, computed in UTC, so a purge on
  the first of a month never keeps or drops an extra day. A weekly bucket is
  deleted only once its whole ISO week is older than the window.
- **The salt is a file and nothing else.** `/secrets/analytics.salt` in
  production, `0600`, never in the database, never in a backup, never versioned.
  Rotation writes a temporary file in the same directory, restricts it to `0600`,
  flushes and renames it over the old one: the previous value is unlinked in the
  same atomic step. Readers compare `(mtime, inode, ctime, size)` and re-read on
  any change, so no restart is needed. A missing salt counts the click (it does
  not need the salt) and drops the uniques, with a `critical` log line.
- **No `device-detector` cache is configured.** Its cache is keyed from the
  user-agent, which would persist a UA-derived identifier in Valkey or on disk.
  The parse happens in memory, on every click, and the UA is then forgotten.
- **Deleting a link deletes its counters explicitly.** `Link` uses soft deletes,
  so the foreign-key cascade never fires on `DELETE /links/{id}`. `DeleteLink`
  removes the three tables inside the same transaction as the soft delete; the
  cascade still covers `forceDelete()` and the account purge. The action and the
  job serialise on a `for update` lock on the link row, so a click already in the
  queue cannot recreate counters after the deletion: it sees `deleted_at` and
  abandons its write.
- **The 404 limit is application-side, on the cold path only.** Traefik cannot
  limit by response status, so its global per-IP ceiling (1200/min) stays the
  first barrier while a route middleware spends a 60/min budget only on 404
  responses. The key is `HMAC-SHA256(salt, ip)`, never the IP, and it lives 60
  seconds; a 302 never pays. A missing salt or a Valkey failure serves the 404
  without limiting: without the salt there is no fingerprint contract, and the
  middleware never falls back to a permanent secret such as `APP_KEY`.
  `docs/10 §6` is amended accordingly, the row is not removed.
- **`GET /v1/links/{id}/stats` and `/stats.csv` require `stats:read` only.**
  Workspace isolation comes from `LinkLocator` plus the link policy: another
  workspace's link is a `404 link_not_found`, never a `403`.
- **The export adds `statistics` as a separate list** (D-111), one item per link
  with `{link_id, daily, weekly, devices}`, `link_id` being the public ULID and
  the buckets being those of the reporting window. Weekly rows are filtered with
  the same ISO-week cutoff as the purge, so a reduced retention never leaks an
  older week between two purges. The link objects keep their existing keys, and
  no hash, internal id, country or secret appears.

## Consequences

- **Positive**: every redirect can be counted with one Valkey read; the
  deployment repairs old cache entries by itself; no personal data enters the
  queue; a broken queue or Valkey never delays or breaks a redirect; erasing a
  link erases its counters even though the row stays for the tombstone; the 30-
  day promise is exact.
- **Negative**: a worker crash between the idempotence guard and the upsert
  undercounts a click (fail closed); a 30-day retention yields at most four whole
  ISO weeks, because a partially retained first week is dropped; `device-detector`
  runs on every counted click.
- **Trade-offs**: the internal link id enters Valkey (not the API, not the logs)
  to avoid a database read per click; the 404 limiter spends one Valkey write per
  unknown slug, in exchange for anti-enumeration that Traefik cannot express.

## Rejected alternatives

- **A versioned cache key (`link:v2:{host}:{slug}`)**: cleaner to reason about,
  but it invalidates the literal cache-key assertions of `RedirectCacheTest` and
  `AccountDeletionTest`, which describe the cache format. The self-healing
  predicate costs one null check in a method that already validates the payload.
- **A second cache key `link:id:{host}:{slug}`**: two Valkey reads per redirect,
  against the single-read rule of D-134.
- **Enqueuing the slug and resolving the link in the worker**: puts the slug in
  the analytics payload and adds a database read per click.
- **`dispatchAfterResponse()`**: runs queued jobs synchronously in the web
  process, which is exactly what D-52 forbids.
- **Summing uniques over a range or per device**: mathematically wrong (D-34);
  the shape offers per-bucket values and clicks-only dimensions instead.
- **Deleting the link's counters through the cascade**: soft deletes never fire
  it, so the counters would stay forever.
- **A Traefik-only 404 limit**: Traefik's `rateLimit` counts requests per router,
  not responses by status, so it cannot distinguish a 302 from a 404.
- **A `device-detector` PSR cache**: it would persist a user-agent derived key.
- **`clicks_7d` on the link resource now**: it breaks the exact resource keys
  pinned by `LinkCrudTest`; the shape is agreed as an opt-in
  `GET /links?include=clicks_7d` and lands with the S4 list screen.

## Known gaps (out of S3)

- The link expiration job (`docs/07 §12`) is not implemented. Correctness is
  already ensured: `ResolveLink` answers 410 once `expires_at` is past. The job
  only materialises `status = disabled`, which matters for the active-link quota
  (D-122). It is a separate change.
- `LogRetentionConfigTest` (docs/09 §2.2) does not exist; the 72-hour log bound
  is a journald deployment invariant, not application code.
- The 100 % coverage gate now runs in CI (PCOV plus
  `scripts/check-coverage.php` over the clover report) for the five modules of
  `docs/09 §5`. It replaced the previous absence of any coverage driver; the
  first enforced run is the CI one, since the local toolchain has no coverage
  extension.

## References

- **Project dossier**: `docs/01-decisions.md` (D-30 to D-39, D-52, D-107, D-111,
  D-120, D-134), `docs/06-lia.md` §B.4 and §C, `docs/07-schema.md` §7 and §12,
  `docs/09-tests.md` §2.2, §2.4 and §5, `docs/10-api.md` §6, §7 and §9,
  `docs/11-pipeline.md`, `docs/12-uml.md` §6.7, `docs/14-ecrans.md`.
- **S1/S2 implementation patterns**: `RedirectCache`, `ResolveLink`,
  `RedirectResult`, `LinkObserver`, `LinkLocator`, `WorkspaceContext`,
  `ApiData`, the problem renderer and the `DatabaseTruncation`/`Cache::flush`
  harness.
- **Vendor semantics consulted**: Laravel's `Bus\Dispatcher::dispatchAfterResponse`
  (it calls `dispatchSync`), `Mailable`, `RateLimiter`, `fputcsv` escape
  parameter, Carbon `startOfWeek`/`endOfWeek` and `isoWeekYear`/`isoWeek`,
  `matomo/device-detector`'s `getDevice()` constants.
