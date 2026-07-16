# Deck Recurrence

Recurring cards for [Nextcloud Deck](https://github.com/nextcloud/deck).

Define a **template card** and a **recurrence rule** (daily, weekly, monthly,
yearly — any interval). A background job clones the template into the stack of
your choice when each occurrence is due, with the due date set to the
occurrence time. Labels, assigned users and the description are carried over;
the template card itself is never modified.

This fills the long-standing Deck feature request
[nextcloud/deck#480](https://github.com/nextcloud/deck/issues/480).

## How it works

- Rules are stored in the app's own table (`*_deck_rec_rules`) as
  RFC 5545 RRULE strings plus a first-occurrence timestamp.
- A `TimedJob` runs with Nextcloud cron (every 15 minutes) and calls Deck's
  own `CardService::cloneCard()` for each due rule, scoped to the rule owner's
  permissions via Deck's `PermissionService`. If the owner loses access to the
  board, spawning fails safely and is logged.
- Occurrences are evaluated in the rule owner's configured timezone.
- Cards created this way trigger Deck's normal activity stream and due-date
  notifications.

## Requirements

- Nextcloud 30–32
- The Deck app installed and enabled
- Nextcloud cron configured (system cron recommended)

## Installation (development)

```bash
cd deck_recurrence
npm ci
npm run build
```

Then copy/symlink the app directory into your Nextcloud `custom_apps/` (or
`apps-extra/`) and enable it:

```bash
occ app:enable deck_recurrence
```

## Usage

Open **Deck Recurrence** from the app menu, click **New rule**, pick a board,
the template card, the target stack and a schedule. Tip: keep template cards
in a dedicated "Templates" stack so they don't clutter your board.

To trigger the job manually while testing:

```bash
occ background-job:list --class 'OCA\DeckRecurrence\Cron\SpawnRecurringCards'
occ background-job:execute <job-id> --force-execute
```

## OCS API

Rules can be managed by scripts and integrations via OCS (works with
[app passwords](https://docs.nextcloud.com/server/latest/user_manual/en/session_management.html)):

```
GET    /ocs/v2.php/apps/deck_recurrence/api/v1/rules
POST   /ocs/v2.php/apps/deck_recurrence/api/v1/rules
GET    /ocs/v2.php/apps/deck_recurrence/api/v1/rules/{id}
PUT    /ocs/v2.php/apps/deck_recurrence/api/v1/rules/{id}
DELETE /ocs/v2.php/apps/deck_recurrence/api/v1/rules/{id}
POST   /ocs/v2.php/apps/deck_recurrence/api/v1/rules/{id}/spawn
```

Rule fields: `templateCardId`, `targetStackId`, `rrule` (RFC 5545),
`dtstart` (unix timestamp of the first occurrence), `enabled`,
`skipIfOpen`, `resetCheckboxes`, `mode` (`clone` or `reset`).

```bash
curl -u user:app-password -H 'OCS-APIRequest: true' \
  -H 'Content-Type: application/json' \
  -X POST 'https://cloud.example.com/ocs/v2.php/apps/deck_recurrence/api/v1/rules?format=json' \
  -d '{"templateCardId": 42, "targetStackId": 7, "rrule": "FREQ=WEEKLY;BYDAY=MO", "dtstart": 1789000000}'
```

`POST .../rules/{id}/spawn` creates (or, in reset mode, resets) the
card immediately, ignoring the schedule.

## License

AGPL-3.0-or-later
