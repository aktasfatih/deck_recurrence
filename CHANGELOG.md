# Changelog

## 0.4.2 – unreleased

### Fixed

- The interval field in the "Repeat" row now lines up with the
  frequency select next to it (covered by an e2e regression check)

## 0.4.0 – unreleased

### Added

- Reset mode: instead of cloning a template, a rule can move the same
  card back to a stack on schedule, clearing its done state and
  re-arming the due date (household chores workflow)
- Full UI-driven end-to-end coverage: editing rules, pausing via the
  active switch, reset mode, error paths and deletion; the suite now
  cleans up after itself

### Fixed

- "Create card now" on a rule whose template card was deleted showed a
  misleading permission error; it now says the template no longer exists

## 0.3.0 – unreleased

### Added

- "Create card now" action: stamp a card from the template on demand,
  without waiting for the schedule (card templates use case)
- Assignees of the template card are copied to spawned cards
- Option to uncheck all checklist items on the new card
- The rule owner gets a Nextcloud notification when a spawn fails
  (deleted template card, lost board access)

### Fixed

- Stray floating label on the interval field in the rule editor

## 0.2.0 – unreleased

### Added

- End conditions for rules: repeat until a date or for a number of
  occurrences (RFC 5545 UNTIL/COUNT), exposed in the rule editor
- "Only create a new card when the previous one is done" option so
  recurring chores do not pile up
- Spawn history table recording every card created by a rule

## 0.1.1

### Fixed

- Component styles were not loaded, leaving the UI unstyled

## 0.1.0 – unreleased

### Added

- Recurrence rules for Deck cards: template card + RFC 5545 RRULE + target stack
- Background job that clones the template card when an occurrence is due,
  setting the occurrence time as due date
- Management UI listing rules with schedule, next occurrence, enable/disable
  toggle and a rule editor (daily/weekly/monthly/yearly, interval, weekdays)
