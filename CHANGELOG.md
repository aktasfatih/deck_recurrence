# Changelog

## 0.1.0 – unreleased

### Added

- Recurrence rules for Deck cards: template card + RFC 5545 RRULE + target stack
- Background job that clones the template card when an occurrence is due,
  setting the occurrence time as due date
- Management UI listing rules with schedule, next occurrence, enable/disable
  toggle and a rule editor (daily/weekly/monthly/yearly, interval, weekdays)
