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

export function buildRrule({ frequency, interval, weekdays, count, until }) {
	const parts = [`FREQ=${frequency}`]
	if (interval > 1) {
		parts.push(`INTERVAL=${interval}`)
	}
	if (frequency === 'WEEKLY' && weekdays.length > 0) {
		parts.push(`BYDAY=${weekdays.join(',')}`)
	}
	if (count) {
		parts.push(`COUNT=${count}`)
	} else if (until instanceof Date) {
		// End of the picked day, expressed in UTC as RFC 5545 requires
		const end = new Date(until)
		end.setHours(23, 59, 59, 0)
		parts.push(`UNTIL=${end.toISOString().replace(/[-:]|\.\d{3}/g, '')}`)
	}
	return parts.join(';')
}

export function parseRrule(rrule) {
	const parts = Object.fromEntries(
		rrule.split(';').filter(Boolean).map((part) => part.split('=')),
	)
	let until = null
	if (parts.UNTIL) {
		const m = parts.UNTIL.match(/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})Z?)?$/)
		if (m) {
			until = m[4] !== undefined
				? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]))
				: new Date(+m[1], +m[2] - 1, +m[3])
		}
	}
	return {
		frequency: parts.FREQ ?? 'WEEKLY',
		interval: parseInt(parts.INTERVAL ?? '1', 10),
		weekdays: parts.BYDAY ? parts.BYDAY.split(',') : [],
		count: parts.COUNT ? parseInt(parts.COUNT, 10) : null,
		until,
	}
}

export function describeRrule(rrule) {
	const { frequency, interval, weekdays, count, until } = parseRrule(rrule)
	let ending = ''
	if (count) {
		ending = ', ' + n('deck_recurrence', '%n time', '%n times', count)
	} else if (until) {
		ending = ', ' + t('deck_recurrence', 'until {date}', { date: until.toLocaleDateString() })
	}
	const days = weekdays
		.map((id) => WEEKDAYS.find((d) => d.id === id)?.label)
		.filter(Boolean)
		.join(', ')
	const base = describeBase(frequency, interval, days, rrule)
	return base + ending
}

function describeBase(frequency, interval, days, rrule) {
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
