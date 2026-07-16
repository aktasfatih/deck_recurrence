// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Multi-tenancy and abuse tests: every scenario is an attack that must
// fail. bob is a second account trying to read, use or corrupt rules
// and cards belonging to admin.
import { expect, test } from '@playwright/test'
import { addUser, deck, login, ocs, resetState, sql } from './helpers.js'

test.describe.configure({ mode: 'serial' })

const BOB = 'bob:BobSecret2026!'

let boardId // admin's private board
let cardId
let stackId
let adminRuleId
let bobBoardId // bob's own board
let bobStackId

test.beforeAll(async () => {
	await resetState()
	addUser('bob', 'BobSecret2026!')

	const board = await deck.createBoard('E2E Private')
	boardId = board.id
	const stack = await deck.createStack(boardId, 'Stack', 1)
	stackId = stack.id
	const card = await deck.createCard(boardId, stack.id, { title: 'Private card' })
	cardId = card.id

	const rule = await ocs('POST', '/api/v1/rules', {
		templateCardId: cardId,
		targetStackId: stackId,
		rrule: 'FREQ=DAILY',
		dtstart: Math.floor(Date.now() / 1000),
	})
	adminRuleId = rule.data.id
})

test.afterAll(async () => {
	try { sql('DELETE FROM oc_deck_rec_spawns') } catch (e) {}
	try { sql('DELETE FROM oc_deck_rec_rules') } catch (e) {}
	if (boardId) {
		await deck.deleteBoard(boardId).catch(() => {})
	}
})

test("bob cannot see admin's rules in his list", async () => {
	const list = await ocs('GET', '/api/v1/rules', undefined, BOB)
	expect(list.status).toBe(200)
	expect(list.data).toHaveLength(0)
})

test("bob cannot read, modify, spawn or delete admin's rule by id", async () => {
	const show = await ocs('GET', `/api/v1/rules/${adminRuleId}`, undefined, BOB)
	expect(show.status).toBe(404)

	const update = await ocs('PUT', `/api/v1/rules/${adminRuleId}`, {
		templateCardId: cardId,
		targetStackId: stackId,
		rrule: 'FREQ=DAILY',
		dtstart: Math.floor(Date.now() / 1000),
		enabled: false,
	}, BOB)
	expect(update.status).toBe(404)

	const spawn = await ocs('POST', `/api/v1/rules/${adminRuleId}/spawn`, undefined, BOB)
	expect(spawn.status).toBe(404)

	const destroy = await ocs('DELETE', `/api/v1/rules/${adminRuleId}`, undefined, BOB)
	expect(destroy.status).toBe(404)

	// admin's rule is untouched
	expect(sql(`SELECT enabled FROM oc_deck_rec_rules WHERE id = ${adminRuleId}`)).toBe('t')
})

test("bob cannot create a rule against admin's private card, and cannot probe card existence", async () => {
	const payload = (templateCardId) => ({
		templateCardId,
		targetStackId: stackId,
		rrule: 'FREQ=DAILY',
		dtstart: Math.floor(Date.now() / 1000),
	})

	const privateCard = await ocs('POST', '/api/v1/rules', payload(cardId), BOB)
	const missingCard = await ocs('POST', '/api/v1/rules', payload(99999999), BOB)

	// Forbidden — and indistinguishable from a card that does not exist,
	// so the API cannot be used to enumerate card ids
	expect(privateCard.status).toBe(403)
	expect(missingCard.status).toBe(403)
	expect(privateCard.message).toBe(missingCard.message)
})

test('read access to a card does not allow reset rules or targeting its board', async () => {
	// Share the board with bob, then downgrade to read-only
	const acl = await deck.shareBoard(boardId, 'bob')
	sql(`UPDATE oc_deck_board_acl SET permission_edit = false WHERE id = ${acl.id}`)

	// reset mode rewrites admin's card: must require edit rights
	const reset = await ocs('POST', '/api/v1/rules', {
		templateCardId: cardId,
		targetStackId: stackId,
		rrule: 'FREQ=DAILY',
		dtstart: Math.floor(Date.now() / 1000),
		mode: 'reset',
	}, BOB)
	expect(reset.status).toBe(403)

	// cloning INTO a read-only board must also be rejected
	const cloneIntoReadOnly = await ocs('POST', '/api/v1/rules', {
		templateCardId: cardId,
		targetStackId: stackId,
		rrule: 'FREQ=DAILY',
		dtstart: Math.floor(Date.now() / 1000),
	}, BOB)
	expect(cloneIntoReadOnly.status).toBe(403)

	await deck.unshareBoard(boardId, acl.id)
})

test('malformed and hostile input cannot break the API or the database', async () => {
	const attempts = [
		{ rrule: "FREQ=DAILY'; DROP TABLE oc_deck_rec_rules;--" },
		{ rrule: 'FREQ=DAILY;UNTIL=not-a-date' },
		{ rrule: 'X'.repeat(10000) },
		{ mode: "clone'; DELETE FROM oc_deck_cards;--" },
	]
	for (const overrides of attempts) {
		const response = await ocs('POST', '/api/v1/rules', {
			templateCardId: cardId,
			targetStackId: stackId,
			rrule: 'FREQ=DAILY',
			dtstart: Math.floor(Date.now() / 1000),
			...overrides,
		})
		expect(response.status).toBe(400)
	}

	// Non-numeric ids must not cause server errors
	const weirdId = await ocs('GET', '/api/v1/rules/abc')
	expect([400, 404]).toContain(weirdId.status)

	// The tables survived every attempt
	expect(sql('SELECT count(*) FROM oc_deck_rec_rules')).toBe('1')
	expect(Number(sql('SELECT count(*) FROM oc_deck_cards'))).toBeGreaterThan(0)
})

test('a hostile card title is rendered inert in the rules table', async ({ page }) => {
	const payload = '<img src=x onerror="document.title=\'pwned\'">'
	await deck.createCard(boardId, stackId, { title: payload }).then((card) =>
		ocs('POST', '/api/v1/rules', {
			templateCardId: card.id,
			targetStackId: stackId,
			rrule: 'FREQ=DAILY',
			dtstart: Math.floor(Date.now() / 1000),
		}),
	)

	await login(page)
	await page.goto('/apps/deck_recurrence/')
	// The title text appears, escaped, and never executes
	await expect(page.getByRole('cell', { name: payload })).toBeVisible()
	expect(await page.title()).not.toBe('pwned')
	expect(await page.locator('table img[src="x"]').count()).toBe(0)
})

test('internal routes reject requests without a CSRF token', async ({ page }) => {
	await login(page)
	await page.goto('/apps/deck_recurrence/')
	const status = await page.evaluate(async () => {
		const response = await fetch('/apps/deck_recurrence/rules', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ templateCardId: 1, targetStackId: 1, rrule: 'FREQ=DAILY', dtstart: 0 }),
		})
		return response.status
	})
	expect(status).toBe(412)
})
