# Changelog

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
