// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test'
import { addUser, deck, login, ocs, resetState, runArchiveJob, sql } from './helpers.js'

test.describe.configure({ mode: 'serial' })

const DAY = 86400

let boardId
let choresStackId
let miscStackId
let foreignBoardId

const isArchived = (cardId) => sql(`SELECT archived FROM oc_deck_cards WHERE id = ${cardId}`) === 't'
const markDoneDaysAgo = (cardId, days) =>
	sql(`UPDATE oc_deck_cards SET done = NOW() - interval '${days} days' WHERE id = ${cardId}`)
const backdateCreation = (cardId, days) =>
	sql(`UPDATE oc_deck_cards SET created_at = extract(epoch from now())::int - ${days * DAY} WHERE id = ${cardId}`)

test.beforeAll(async () => {
	await resetState()
	const board = await deck.createBoard('E2E Archive')
	boardId = board.id
	choresStackId = (await deck.createStack(boardId, 'Chores', 1)).id
	miscStackId = (await deck.createStack(boardId, 'Misc', 2)).id

	addUser('bob', 'BobSecret2026!')
	foreignBoardId = (await deck.createBoard('E2E Archive Foreign')).id
})

test.afterAll(async () => {
	const attempt = (fn) => { try { fn() } catch (e) { /* table may not exist */ } }
	attempt(() => sql('DELETE FROM oc_deck_rec_arch_rules'))
	for (const id of [boardId, foreignBoardId]) {
		if (id) {
			await deck.deleteBoard(id).catch(() => {})
		}
	}
})

test('validation rejects bad input with proper status codes', async () => {
	const base = { boardId, mode: 'done_for', threshold: 5 * DAY }

	expect((await ocs('POST', '/api/v1/archive-rules', { ...base, mode: 'bogus' })).status).toBe(400)
	expect((await ocs('POST', '/api/v1/archive-rules', { ...base, threshold: 0 })).status).toBe(400)
	expect((await ocs('POST', '/api/v1/archive-rules', { ...base, threshold: -5 })).status).toBe(400)

	// A stack that exists but belongs to a different board
	const foreignStack = await deck.createStack(foreignBoardId, 'Elsewhere', 1)
	const crossBoard = await ocs('POST', '/api/v1/archive-rules', { ...base, stackId: foreignStack.id })
	expect(crossBoard.status).toBe(400)

	// Nonexistent board reads as "no permission", not "not found"
	expect((await ocs('POST', '/api/v1/archive-rules', { ...base, boardId: 999999 })).status).toBe(403)
})

test('the scheduled sweep archives cards done past the threshold and only those', async () => {
	const oldDone = await deck.createCard(boardId, choresStackId, { title: 'Done a week ago' })
	const freshDone = await deck.createCard(boardId, choresStackId, { title: 'Done an hour ago' })
	const notDone = await deck.createCard(boardId, choresStackId, { title: 'Still open' })
	markDoneDaysAgo(oldDone.id, 6)
	sql(`UPDATE oc_deck_cards SET done = NOW() - interval '1 hour' WHERE id = ${freshDone.id}`)

	const rule = await ocs('POST', '/api/v1/archive-rules', { boardId, mode: 'done_for', threshold: 5 * DAY })
	expect(rule.status).toBe(200)
	expect(rule.data.enabled).toBe(true)

	runArchiveJob()

	expect(isArchived(oldDone.id)).toBe(true)
	expect(isArchived(freshDone.id)).toBe(false)
	expect(isArchived(notDone.id)).toBe(false)

	const after = await ocs('GET', `/api/v1/archive-rules/${rule.data.id}`)
	expect(after.data.lastRun).not.toBeNull()

	await ocs('DELETE', `/api/v1/archive-rules/${rule.data.id}`)
})

test('a stack-scoped rule leaves the rest of the board alone', async () => {
	const inScope = await deck.createCard(boardId, choresStackId, { title: 'Old done in scope' })
	const outOfScope = await deck.createCard(boardId, miscStackId, { title: 'Old done elsewhere' })
	markDoneDaysAgo(inScope.id, 6)
	markDoneDaysAgo(outOfScope.id, 6)

	const rule = await ocs('POST', '/api/v1/archive-rules', {
		boardId, stackId: choresStackId, mode: 'done_for', threshold: 5 * DAY,
	})
	runArchiveJob()

	expect(isArchived(inScope.id)).toBe(true)
	expect(isArchived(outOfScope.id)).toBe(false)

	await ocs('DELETE', `/api/v1/archive-rules/${rule.data.id}`)
})

test('min_age mode archives freshly done cards that are old enough', async () => {
	const oldCard = await deck.createCard(boardId, miscStackId, { title: 'Created long ago, done today' })
	const newCard = await deck.createCard(boardId, miscStackId, { title: 'Created today, done today' })
	backdateCreation(oldCard.id, 10)
	markDoneDaysAgo(oldCard.id, 0)
	markDoneDaysAgo(newCard.id, 0)

	const rule = await ocs('POST', '/api/v1/archive-rules', { boardId, mode: 'min_age', threshold: 5 * DAY })
	runArchiveJob()

	expect(isArchived(oldCard.id)).toBe(true)
	expect(isArchived(newCard.id)).toBe(false)

	await ocs('DELETE', `/api/v1/archive-rules/${rule.data.id}`)
})

test('run now sweeps immediately and reports the count', async () => {
	// Own stack so counts cannot be polluted by earlier tests' leftovers
	const sweepStackId = (await deck.createStack(boardId, 'Sweep', 3)).id
	const done = await deck.createCard(boardId, sweepStackId, { title: 'Manual sweep target' })
	markDoneDaysAgo(done.id, 6)

	const rule = await ocs('POST', '/api/v1/archive-rules', { boardId, stackId: sweepStackId, mode: 'done_for', threshold: 5 * DAY })
	const run = await ocs('POST', `/api/v1/archive-rules/${rule.data.id}/run`)
	expect(run.status).toBe(200)
	expect(run.data.archived).toBe(1)
	expect(isArchived(done.id)).toBe(true)

	// Idempotent: nothing new qualifies, so a second run archives nothing
	const again = await ocs('POST', `/api/v1/archive-rules/${rule.data.id}/run`)
	expect(again.data.archived).toBe(0)

	await ocs('DELETE', `/api/v1/archive-rules/${rule.data.id}`)
})

test('bob cannot touch admin rules or boards', async () => {
	const asBob = (method, path, body) => ocs(method, path, body, 'bob:BobSecret2026!')

	const rule = await ocs('POST', '/api/v1/archive-rules', { boardId, mode: 'done_for', threshold: 5 * DAY })
	const id = rule.data.id

	expect((await asBob('GET', '/api/v1/archive-rules')).data).toHaveLength(0)
	expect((await asBob('GET', `/api/v1/archive-rules/${id}`)).status).toBe(404)
	expect((await asBob('PUT', `/api/v1/archive-rules/${id}`, { boardId, enabled: false, mode: 'done_for', threshold: DAY })).status).toBe(404)
	expect((await asBob('POST', `/api/v1/archive-rules/${id}/run`)).status).toBe(404)
	expect((await asBob('DELETE', `/api/v1/archive-rules/${id}`)).status).toBe(404)

	// Bob cannot point a rule of his own at admin's board either
	expect((await asBob('POST', '/api/v1/archive-rules', { boardId, mode: 'done_for', threshold: DAY })).status).toBe(403)

	await ocs('DELETE', `/api/v1/archive-rules/${id}`)
})

test('create and delete an auto-archive rule through the UI', async ({ page }) => {
	await login(page)
	await page.goto('/apps/deck_recurrence/')

	await page.getByRole('button', { name: 'New auto-archive rule' }).click()
	await page.getByRole('combobox', { name: 'Select a board' }).click()
	await page.locator('[role="option"]').filter({ hasText: /^\s*E2E Archive\s*$/ }).click()
	await page.getByRole('button', { name: 'Create' }).click()

	const row = page.getByRole('row').filter({ hasText: 'E2E Archive (all stacks)' })
	await expect(row).toContainText('Cards done for 5 day(s) or more')
	expect(sql('SELECT count(*) FROM oc_deck_rec_arch_rules')).toBe('1')

	await row.getByRole('button', { name: 'Actions' }).click()
	await page.getByRole('menuitem', { name: 'Delete' }).click()
	await expect(page.getByText('No auto-archive rules yet', { exact: false })).toBeVisible()
	expect(sql('SELECT count(*) FROM oc_deck_rec_arch_rules')).toBe('0')
})
