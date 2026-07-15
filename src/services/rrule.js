// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

export const WEEKDAYS = [
	{ id: 'MO', label: t('deck_recurrence', 'Monday') },
	{ id: 'TU', label: t('deck_recurrence', 'Tuesday') },
	{ id: 'WE', label: t('deck_recurrence', 'Wednesday') },
	{ id: 'TH', label: t('deck_recurrence', 'Thursday') },
	{ id: 'FR', label: t('deck_recurrence', 'Friday') },
	{ id: 'SA', label: t('deck_recurrence', 'Saturday') },
	{ id: 'SU', label: t('deck_recurrence', 'Sunday') },
]

export function buildRrule({ frequency, interval, weekdays }) {
	const parts = [`FREQ=${frequency}`]
	if (interval > 1) {
		parts.push(`INTERVAL=${interval}`)
	}
	if (frequency === 'WEEKLY' && weekdays.length > 0) {
		parts.push(`BYDAY=${weekdays.join(',')}`)
	}
	return parts.join(';')
}

export function parseRrule(rrule) {
	const parts = Object.fromEntries(
		rrule.split(';').filter(Boolean).map((part) => part.split('=')),
	)
	return {
		frequency: parts.FREQ ?? 'WEEKLY',
		interval: parseInt(parts.INTERVAL ?? '1', 10),
		weekdays: parts.BYDAY ? parts.BYDAY.split(',') : [],
	}
}

export function describeRrule(rrule) {
	const { frequency, interval, weekdays } = parseRrule(rrule)
	const days = weekdays
		.map((id) => WEEKDAYS.find((d) => d.id === id)?.label)
		.filter(Boolean)
		.join(', ')
	switch (frequency) {
		case 'DAILY':
			return interval === 1
				? t('deck_recurrence', 'Every day')
				: n('deck_recurrence', 'Every %n day', 'Every %n days', interval)
		case 'WEEKLY': {
			const base = interval === 1
				? t('deck_recurrence', 'Every week')
				: n('deck_recurrence', 'Every %n week', 'Every %n weeks', interval)
			return days ? `${base} (${days})` : base
		}
		case 'MONTHLY':
			return interval === 1
				? t('deck_recurrence', 'Every month')
				: n('deck_recurrence', 'Every %n month', 'Every %n months', interval)
		case 'YEARLY':
			return interval === 1
				? t('deck_recurrence', 'Every year')
				: n('deck_recurrence', 'Every %n year', 'Every %n years', interval)
		default:
			return rrule
	}
}
