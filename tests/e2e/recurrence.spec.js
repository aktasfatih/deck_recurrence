// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test'
import {
	cardsInStack,
	deck,
	login,
	makeRuleDue,
	notifications,
	resetState,
	runSpawnJob,
	sql,
} from './helpers.js'

test.describe.configure({ mode: 'serial' })

let boardId

test.beforeAll(async () => {
	await resetState()
	const board = await deck.createBoard('E2E Chores')
	boardId = board.id
	const templates = await deck.createStack(boardId, 'Templates', 1)
	await deck.createStack(boardId, 'Weekly', 2)
	const template = await deck.createCard(boardId, templates.id, {
		title: 'Clean the bathroom',
		description: '- [x] sink\n- [X] shower\n- [ ] floor',
	})
	await deck.assignUser(boardId, templates.id, template.id, 'admin')
})

test.beforeEach(async ({ page }) => {
	await login(page)
	await page.goto('/apps/deck_recurrence/')
})

test('create a rule through the editor', async ({ page }) => {
	await page.getByRole('button', { name: 'New rule' }).click()

	// NcSelect splits option text into spans (middle-ellipsis), which breaks
	// accessible-name matching; match on rendered text instead.
	const option = (text) => page.locator('[role="option"]').filter({ hasText: text })
	await page.getByRole('combobox', { name: 'Select a board' }).click()
	await option('E2E Chores').click()
	await page.getByRole('combobox', { name: 'Card to copy each time' }).click()
	await option('Clean the bathroom').click()
	await page.getByRole('combobox', { name: 'Select a stack' }).click()
	await option('Weekly').click()
	await page.getByText('Monday', { exact: true }).click()
	await page.getByText('Uncheck all checklist items on the new card').click()

	await page.getByRole('button', { name: 'Create' }).click()

	const row = page.getByRole('row').filter({ hasText: 'Clean the bathroom' })
	await expect(row).toContainText('E2E Chores / Weekly')
	await expect(row).toContainText('Every week (Monday)')
	expect(sql('SELECT count(*) FROM oc_deck_rec_rules')).toBe('1')
})

test('scheduled spawn creates a card with due date, assignee and reset checklist', async () => {
	makeRuleDue()
	runSpawnJob()

	const cards = await cardsInStack(boardId, 'Weekly')
	expect(cards).toHaveLength(1)
	const card = cards[0]
	expect(card.title).toBe('Clean the bathroom')
	expect(card.duedate).not.toBeNull()
	expect(card.description).toBe('- [ ] sink\n- [ ] shower\n- [ ] floor')
	expect(card.assignedUsers.map((a) => a.participant.uid)).toEqual(['admin'])
})

test('skip-if-open waits for the previous card, then respawns', async () => {
	sql('UPDATE oc_deck_rec_rules SET skip_if_open = true')

	makeRuleDue()
	runSpawnJob()
	expect(sql('SELECT count(*) FROM oc_deck_rec_spawns')).toBe('1') // skipped

	sql('UPDATE oc_deck_cards SET done = NOW() WHERE id = (SELECT card_id FROM oc_deck_rec_spawns ORDER BY id DESC LIMIT 1)')
	makeRuleDue()
	runSpawnJob()
	expect(sql('SELECT count(*) FROM oc_deck_rec_spawns')).toBe('2')
	expect(await cardsInStack(boardId, 'Weekly')).toHaveLength(2)
})

test('create card now spawns immediately without a due date', async ({ page }) => {
	await page.getByRole('button', { name: 'Actions' }).click()
	await page.getByRole('menuitem', { name: 'Create card now' }).click()
	await expect(page.getByText('Card "Clean the bathroom" created')).toBeVisible()

	const cards = await cardsInStack(boardId, 'Weekly')
	expect(cards).toHaveLength(3)
	const manual = cards[cards.length - 1]
	expect(manual.duedate).toBeNull()
	expect(manual.description).toBe('- [ ] sink\n- [ ] shower\n- [ ] floor')
	expect(manual.assignedUsers.map((a) => a.participant.uid)).toEqual(['admin'])
})

test('a failing rule notifies its owner exactly once', async () => {
	sql('UPDATE oc_deck_rec_rules SET template_card_id = 99999, skip_if_open = false')

	makeRuleDue()
	runSpawnJob()
	makeRuleDue()
	runSpawnJob()

	const active = await notifications.list()
	const failures = active.filter((n) => n.app === 'deck_recurrence')
	expect(failures).toHaveLength(1)
	expect(failures[0].subject).toContain('could not be created')
	expect(failures[0].link).toContain('/apps/deck_recurrence')
})
