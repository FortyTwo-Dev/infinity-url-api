## What

<what this PR does, in one to three sentences>

## Why

<the problem it solves, and the motivation>

## Testing

<the commands run, and what they showed>

## Invariants

Tick every line that applies, and leave the others unticked. An unticked line
that should be ticked is a reason to close the PR.

- [ ] No IP, user-agent or referer column was added, anywhere.
- [ ] No click-level event table was introduced.
- [ ] No cookie is set on `iurl.fr`, and no third-party script is served there.
- [ ] The redirect still answers `302` with no body — no interstitial was added.
- [ ] Every new authenticated route declares an ability.
- [ ] No personal data reaches a log line, a job payload or a report row.
- [ ] If a privacy invariant test changed, `docs/09-tests.md` changed in the same PR.

## Decisions

- [ ] No settled decision was silently overridden.
- [ ] Or: `docs/01-decisions.md` records the change, with its reason.

## Notes

<breaking changes, migrations, follow-ups, open questions>
