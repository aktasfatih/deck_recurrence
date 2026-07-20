// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test'
import { cardsInStack, deck, makeRuleDue, ocs, resetState, runSpawnJob, sql } from './helpers.js'

test.describe.configure({ mode: 'serial' })

let boardId
let cardId
let stackId
let ruleId

test.beforeAll(async () => {
	await resetState()
	const board = await deck.createBoard('E2E OCS')
	boardId = board.id
	const templates = await deck.createStack(boardId, 'Templates', 1)
	const target = await deck.createStack(boardId, 'Target', 2)
	stackId = target.id
	const card = await deck.createCard(boardId, templates.id, { title: 'API template' })
	cardId = card.id
})

test.afterAll(async () => {
	try { sql('DELETE FROM oc_deck_rec_spawns') } catch (e) {}
	try { sql('DELETE FROM oc_deck_rec_rules') } catch (e) {}
	if (boardId) {
		await deck.deleteBoard(boardId).catch(() => {})
	}
})

test('rejects unauthenticated requests with 401', async () => {
	const response = await fetch('http://localhost:8890/ocs/v2.php/apps/deck_recurrence/api/v1/rules?format=json', {
		headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	expect(response.status).toBe(401)
})

test('creates a rule', async () => {
	const { status, data } = await ocs('POST', '/api/v1/rules', {
		templateCardId: cardId,
		targetStackId: stackId,
		rrule: 'FREQ=DAILY',
		dtstart: Math.floor(Date.now() / 1000),
	})
	expect(status).toBe(200)
	expect(data.mode).toBe('clone')
	expect(data.enabled).toBe(true)
	expect(data.nextRun).not.toBeNull()
	ruleId = data.id
})

test('lists and shows rules', async () => {
	const list = await ocs('GET', '/api/v1/rules')
	expect(list.status).toBe(200)
	expect(list.data).toHaveLength(1)

	const show = await ocs('GET', `/api/v1/rules/${ruleId}`)
	expect(show.status).toBe(200)
	expect(show.data.templateCardId).toBe(cardId)
})

test('updates a rule', async () => {
	const { status, data } = await ocs('PUT', `/api/v1/rules/${ruleId}`, {
		templateCardId: cardId,
		targetStackId: stackId,
		rrule: 'FREQ=WEEKLY;BYDAY=MO',
		dtstart: Math.floor(Date.now() / 1000),
		enabled: false,
		skipIfOpen: true,
	})
	expect(status).toBe(200)
	expect(data.enabled).toBe(false)
	expect(data.skipIfOpen).toBe(true)
	expect(data.nextRun).toBeNull()
})

test('spawns a card on demand', async () => {
	const { status, data } = await ocs('POST', `/api/v1/rules/${ruleId}/spawn`)
	expect(status).toBe(200)
	expect(data.title).toBe('API template')

	const cards = await cardsInStack(boardId, 'Target')
	expect(cards).toHaveLength(1)
	expect(cards[0].duedate).toBeNull()
})

test('due offset shifts or suppresses the spawned card due date', async () => {
	const week = 7 * 86400
	const base = {
		templateCardId: cardId,
		targetStackId: stackId,
		rrule: 'FREQ=DAILY',
		dtstart: Math.floor(Date.now() / 1000),
	}
	const offsetRule = await ocs('POST', '/api/v1/rules', { ...base, dueOffset: week })
	expect(offsetRule.status).toBe(200)
	expect(offsetRule.data.dueOffset).toBe(week)
	const noDueRule = await ocs('POST', '/api/v1/rules', { ...base, dueOffset: -1 })
	expect(noDueRule.status).toBe(200)
	expect(noDueRule.data.dueOffset).toBe(-1)

	makeRuleDue(`WHERE id IN (${offsetRule.data.id}, ${noDueRule.data.id})`)
	runSpawnJob()

	// The two newest cards in the target stack are this test's spawns
	const spawned = (await cardsInStack(boardId, 'Target')).slice(-2)
	const withDue = spawned.filter((c) => c.duedate !== null)
	expect(withDue).toHaveLength(1)
	// Occurrence was forced to now - 60s, so due ≈ one week from now
	const expected = Date.now() + week * 1000
	expect(Math.abs(new Date(withDue[0].duedate).getTime() - expected)).toBeLessThan(600_000)

	await ocs('DELETE', `/api/v1/rules/${offsetRule.data.id}`)
	await ocs('DELETE', `/api/v1/rules/${noDueRule.data.id}`)
})

test('rejects invalid input with proper status codes', async () => {
	const badRrule = await ocs('POST', '/api/v1/rules', {
		templateCardId: cardId,
		targetStackId: stackId,
		rrule: 'FREQ=BOGUS',
		dtstart: Math.floor(Date.now() / 1000),
	})
	expect(badRrule.status).toBe(400)

	const badMode = await ocs('POST', '/api/v1/rules', {
		templateCardId: cardId,
		targetStackId: stackId,
		rrule: 'FREQ=DAILY',
		dtstart: Math.floor(Date.now() / 1000),
		mode: 'bogus',
	})
	expect(badMode.status).toBe(400)

	const badDueOffset = await ocs('POST', '/api/v1/rules', {
		templateCardId: cardId,
		targetStackId: stackId,
		rrule: 'FREQ=DAILY',
		dtstart: Math.floor(Date.now() / 1000),
		dueOffset: -5,
	})
	expect(badDueOffset.status).toBe(400)

	const missingRule = await ocs('GET', '/api/v1/rules/999999')
	expect(missingRule.status).toBe(404)

	const foreignCard = await ocs('POST', '/api/v1/rules', {
		templateCardId: 999999,
		targetStackId: stackId,
		rrule: 'FREQ=DAILY',
		dtstart: Math.floor(Date.now() / 1000),
	})
	expect(foreignCard.status).toBe(403)
})

test('deletes a rule', async () => {
	const { status } = await ocs('DELETE', `/api/v1/rules/${ruleId}`)
	expect(status).toBe(200)

	const list = await ocs('GET', '/api/v1/rules')
	expect(list.data).toHaveLength(0)
	expect(sql('SELECT count(*) FROM oc_deck_rec_spawns')).toBe('0')
})
