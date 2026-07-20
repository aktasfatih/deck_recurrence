# Deck Recurrence

Recurring cards and board cleanup for [Nextcloud Deck](https://github.com/nextcloud/deck).

Two things Deck boards accumulate by hand today, automated:

- **Recurring cards** — define a template card and a schedule; a new card
  appears in the stack of your choice every time an occurrence is due.
- **Auto-archive** — done cards are archived automatically once they have
  been done long enough (say, five days), keeping boards tidy without the
  weekly manual sweep.

Both are long-standing Deck feature requests: recurring cards since 2018
([nextcloud/deck#480](https://github.com/nextcloud/deck/issues/480), with
duplicates [#3055](https://github.com/nextcloud/deck/issues/3055),
[#3258](https://github.com/nextcloud/deck/issues/3258),
[#3851](https://github.com/nextcloud/deck/issues/3851),
[#6058](https://github.com/nextcloud/deck/issues/6058)), and automatic
archiving of old cards since 2020
([nextcloud/deck#2203](https://github.com/nextcloud/deck/issues/2203),
[#1821](https://github.com/nextcloud/deck/issues/1821)).

## Features

### Recurring cards

- **Any schedule**: daily, weekly (with weekday selection), monthly or
  yearly, at any interval — stored as standard RFC 5545 RRULEs, so
  anything an RRULE can express works via the API.
- **Two modes**:
  - *Clone* — each occurrence copies a template card (title, description,
    labels, assignees) into a target stack. The template itself is never
    modified.
  - *Reset* — the same card is moved back to a stack each occurrence, its
    done state cleared and its due date re-armed. Good for household-chore
    style boards where you want one card, not a growing pile.
- **Due dates, your way**: spawned cards can be due at the occurrence
  time, a configurable delay later (for example one week), or carry no
  due date at all.
- **No pile-ups**: optionally skip an occurrence while the previously
  spawned card is still open (not done, archived or deleted).
- **Checklist reset**: optionally uncheck all checklist items on the new
  (or reset) card.
- **End conditions**: repeat forever, until a date, or for a fixed number
  of occurrences. Exhausted rules disable themselves.
- **Create now**: stamp a card from the template on demand, ignoring the
  schedule.

### Auto-archive

- **Per board or per stack**: a rule sweeps a whole board or a single
  stack.
- **Two conditions**: archive cards that have been *marked done* for at
  least N days/weeks, or done cards that were *created* at least that
  long ago.
- **Safe by construction**: cards that are not marked done, are already
  archived, or are deleted are never touched. Sweeps are idempotent and
  batched (100 cards per rule per run) so enabling a rule on an old board
  cannot stall cron.
- **Archive now**: run a rule's sweep on demand; it reports how many
  cards it archived.

### Both

- Run under Nextcloud cron with the **rule owner's permissions** — if you
  lose access to a board, your rules fail safely and you get a
  notification instead of silent breakage.
- Show up in Deck's **activity stream** like manual actions.
- Fully manageable via **web UI and OCS API** (scripting/integration
  friendly, works with app passwords).

## Requirements

- Nextcloud 30–32
- The Deck app installed and enabled
- Nextcloud cron configured (system cron recommended; jobs run every
  15 minutes)

## Installation

From source (until the App Store listing is up):

```bash
cd deck_recurrence
npm ci
npm run build
```

Then copy/symlink the app directory into your Nextcloud `custom_apps/`
(or `apps-extra/`) and enable it:

```bash
occ app:enable deck_recurrence
```

## Usage

Open **Deck Recurrence** from the app menu.

- **Recurring cards** → *New rule*: pick a board, the template card, the
  target stack, a schedule, and (optionally) a due-date behaviour and end
  condition. Tip: keep template cards in a dedicated "Templates" stack so
  they don't clutter the board.
- **Auto-archive** → *New auto-archive rule*: pick a board (optionally a
  single stack), a condition and a threshold — e.g. "archive cards that
  have been done for 5 days".

To trigger the background jobs manually while testing:

```bash
occ background-job:list --class 'OCA\DeckRecurrence\Cron\SpawnRecurringCards'
occ background-job:list --class 'OCA\DeckRecurrence\Cron\ArchiveDoneCards'
occ background-job:execute <job-id> --force-execute
```

## OCS API

Both rule types can be managed by scripts and integrations via OCS
(works with [app passwords](https://docs.nextcloud.com/server/latest/user_manual/en/session_management.html)).

### Recurrence rules

```
GET    /ocs/v2.php/apps/deck_recurrence/api/v1/rules
POST   /ocs/v2.php/apps/deck_recurrence/api/v1/rules
GET    /ocs/v2.php/apps/deck_recurrence/api/v1/rules/{id}
PUT    /ocs/v2.php/apps/deck_recurrence/api/v1/rules/{id}
DELETE /ocs/v2.php/apps/deck_recurrence/api/v1/rules/{id}
POST   /ocs/v2.php/apps/deck_recurrence/api/v1/rules/{id}/spawn
```

Fields: `templateCardId`, `targetStackId`, `rrule` (RFC 5545), `dtstart`
(unix timestamp of the first occurrence), `enabled`, `skipIfOpen`,
`resetCheckboxes`, `mode` (`clone` or `reset`), `dueOffset` (seconds
between the occurrence and the spawned card's due date; `0` = due at the
occurrence, `-1` = no due date).

```bash
curl -u user:app-password -H 'OCS-APIRequest: true' \
  -H 'Content-Type: application/json' \
  -X POST 'https://cloud.example.com/ocs/v2.php/apps/deck_recurrence/api/v1/rules?format=json' \
  -d '{"templateCardId": 42, "targetStackId": 7, "rrule": "FREQ=WEEKLY;BYDAY=MO", "dtstart": 1789000000, "dueOffset": 604800}'
```

`POST .../rules/{id}/spawn` creates (or, in reset mode, resets) the card
immediately, ignoring the schedule.

### Auto-archive rules

```
GET    /ocs/v2.php/apps/deck_recurrence/api/v1/archive-rules
POST   /ocs/v2.php/apps/deck_recurrence/api/v1/archive-rules
GET    /ocs/v2.php/apps/deck_recurrence/api/v1/archive-rules/{id}
PUT    /ocs/v2.php/apps/deck_recurrence/api/v1/archive-rules/{id}
DELETE /ocs/v2.php/apps/deck_recurrence/api/v1/archive-rules/{id}
POST   /ocs/v2.php/apps/deck_recurrence/api/v1/archive-rules/{id}/run
```

Fields: `boardId`, `stackId` (optional — omit for the whole board),
`mode` (`done_for` = done for at least the threshold, `min_age` = done
and created at least the threshold ago), `threshold` (seconds),
`enabled`.

```bash
curl -u user:app-password -H 'OCS-APIRequest: true' \
  -H 'Content-Type: application/json' \
  -X POST 'https://cloud.example.com/ocs/v2.php/apps/deck_recurrence/api/v1/archive-rules?format=json' \
  -d '{"boardId": 2, "mode": "done_for", "threshold": 432000}'
```

`POST .../archive-rules/{id}/run` sweeps immediately and returns
`{"archived": <count>}`.

## How it works

- Rules live in the app's own tables (`*_deck_rec_rules`,
  `*_deck_rec_arch_rules`); Deck's schema is never modified.
- Occurrences are computed with `Sabre\VObject`'s RRULE iterator (bundled
  with the server) in the rule owner's configured timezone, so weekly
  rules keep their local time across DST changes.
- Two independent `TimedJob`s (spawning and archiving) run with cron.
  Card writes go through Deck's own mapper layer rather than its service
  layer, because the service layer assumes a logged-in session that does
  not exist under cron; permission checks run as the rule's owner via
  Deck's `PermissionService`.
- A broken rule (deleted template card, lost board access) is skipped
  with a notification to its owner — it can never stall the queue for
  other rules.

## Development

A throwaway dev instance (Nextcloud 32 + Deck + this app mounted from
the checkout) is one command away:

```bash
cd dev && ./setup.sh   # http://localhost:8890, admin/admin
```

Tests:

```bash
composer run test:unit   # PHP unit tests (recurrence engine)
npm run test:e2e         # Playwright end-to-end suite against the dev instance
```

## License

AGPL-3.0-or-later
