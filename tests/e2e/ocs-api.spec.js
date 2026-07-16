// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test'
import { cardsInStack, deck, ocs, resetState, sql } from './helpers.js'

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
