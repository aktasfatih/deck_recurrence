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
let trashCardId
let waterCardId
let weeklyStackId

test.beforeAll(async () => {
	await resetState()
	const board = await deck.createBoard('E2E Chores')
	boardId = board.id
	const templates = await deck.createStack(boardId, 'Templates', 1)
	const weekly = await deck.createStack(boardId, 'Weekly', 2)
	weeklyStackId = weekly.id
	const done = await deck.createStack(boardId, 'Done', 3)

	const template = await deck.createCard(boardId, templates.id, {
		title: 'Clean the bathroom',
		description: '- [x] sink\n- [X] shower\n- [ ] floor',
	})
	await deck.assignUser(boardId, templates.id, template.id, 'admin')
	const label = await deck.createLabel(boardId, 'E2E Label', 'FF0000')
	await deck.assignLabel(boardId, templates.id, template.id, label.id)

	// Marked done, with a checked box that must be PRESERVED (its rule
	// will not enable the reset-checkboxes option)
	const trash = await deck.createCard(boardId, done.id, {
		title: 'Take out trash',
		description: '- [x] bins to the curb',
	})
	trashCardId = trash.id
	sql(`UPDATE oc_deck_cards SET done = NOW() WHERE id = ${trashCardId}`)

	const water = await deck.createCard(boardId, templates.id, { title: 'Water plants' })
	waterCardId = water.id
})

test.afterAll(async () => {
	// Leave the dev instance clean: no rules pointing at deleted state
	sql('DELETE FROM oc_deck_rec_spawns')
	sql('DELETE FROM oc_deck_rec_rules')
	await notifications.clear()
	if (boardId) {
		await deck.deleteBoard(boardId)
	}
})

test.beforeEach(async ({ page }) => {
	await login(page)
	await page.goto('/apps/deck_recurrence/')
})

const option = (page, text) => page.locator('[role="option"]').filter({ hasText: text })
const row = (page, text) => page.getByRole('row').filter({ hasText: text })

test('create a rule through the editor', async ({ page }) => {
	await page.getByRole('button', { name: 'New rule' }).click()

	// The interval field must line up with the frequency select (regression
	// check for the misaligned "Repeat" row).
	const boxes = await page.evaluate(() => {
		const input = document.querySelector('.rule-editor__frequency .rule-editor__number')
		const select = document.querySelector('.rule-editor__frequency .v-select')
		const r = (el) => el.getBoundingClientRect()
		return { input: r(input), select: r(select) }
	})
	expect(Math.abs(boxes.input.top - boxes.select.top)).toBeLessThanOrEqual(1)
	expect(Math.abs(boxes.input.height - boxes.select.height)).toBeLessThanOrEqual(1)

	await page.getByRole('combobox', { name: 'Select a board' }).click()
	await option(page, 'E2E Chores').click()
	await page.getByRole('combobox', { name: 'Card to copy each time' }).click()
	await option(page, 'Clean the bathroom').click()
	await page.getByRole('combobox', { name: 'Select a stack' }).click()
	await option(page, 'Weekly').click()
	await page.getByText('Monday', { exact: true }).click()
	await page.getByText('Uncheck all checklist items on the new card').click()

	await page.getByRole('button', { name: 'Create' }).click()

	await expect(row(page, 'Clean the bathroom')).toContainText('E2E Chores / Weekly')
	await expect(row(page, 'Clean the bathroom')).toContainText('Every week (Monday)')
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
	expect(card.labels.map((l) => l.title)).toEqual(['E2E Label'])
})

test('skip-if-open waits for the previous card, then respawns', async () => {
	sql("UPDATE oc_deck_rec_rules SET skip_if_open = true WHERE mode = 'clone'")

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
	await row(page, 'Clean the bathroom').getByRole('button', { name: 'Actions' }).click()
	await page.getByRole('menuitem', { name: 'Create card now' }).click()
	await expect(page.getByText('Card "Clean the bathroom" created')).toBeVisible()

	const cards = await cardsInStack(boardId, 'Weekly')
	expect(cards).toHaveLength(3)
	const manual = cards[cards.length - 1]
	expect(manual.duedate).toBeNull()
	expect(manual.description).toBe('- [ ] sink\n- [ ] shower\n- [ ] floor')
	expect(manual.assignedUsers.map((a) => a.participant.uid)).toEqual(['admin'])
})

test('edit a rule through the editor', async ({ page }) => {
	await row(page, 'Clean the bathroom').getByRole('button', { name: 'Actions' }).click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()

	await expect(page.getByRole('heading', { name: 'Edit rule' })).toBeVisible()
	// The editor preselects board/card/stack asynchronously; the form is
	// stable once it validates and Save becomes clickable.
	await expect(page.getByRole('button', { name: 'Save' })).toBeEnabled()
	await page.locator('.rule-editor__frequency .rule-editor__number').fill('2')
	await page.getByRole('button', { name: 'Save' }).click()

	await expect(row(page, 'Clean the bathroom')).toContainText('Every 2 weeks')
	expect(sql("SELECT rrule FROM oc_deck_rec_rules WHERE mode = 'clone'")).toBe('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO')
})

test('the active switch pauses and resumes a rule', async ({ page }) => {
	const ruleRow = row(page, 'Clean the bathroom')

	await ruleRow.getByRole('checkbox').click({ force: true })
	await expect(ruleRow).toContainText('—')
	await expect.poll(() => sql("SELECT enabled FROM oc_deck_rec_rules WHERE mode = 'clone'")).toBe('f')

	await ruleRow.getByRole('checkbox').click({ force: true })
	await expect(ruleRow).not.toContainText('—')
	await expect.poll(() => sql("SELECT enabled FROM oc_deck_rec_rules WHERE mode = 'clone'")).toBe('t')
})

test('reset mode moves the same card back on schedule', async ({ page }) => {
	await page.getByRole('button', { name: 'New rule' }).click()
	await page.getByText('Move the same card back').click()

	await page.getByRole('combobox', { name: 'Select a board' }).click()
	await option(page, 'E2E Chores').click()
	await page.getByRole('combobox', { name: 'Card to move each time' }).click()
	await option(page, 'Take out trash').click()
	await page.getByRole('combobox', { name: 'Select a stack' }).click()
	await option(page, 'Weekly').click()
	await page.getByText('Monday', { exact: true }).click()
	await page.getByRole('button', { name: 'Create' }).click()
	await expect(row(page, 'Take out trash')).toContainText('E2E Chores / Weekly')

	makeRuleDue(`WHERE template_card_id = ${trashCardId}`)
	runSpawnJob()

	const weekly = await cardsInStack(boardId, 'Weekly')
	const moved = weekly.find((c) => c.id === trashCardId)
	expect(moved).toBeTruthy()
	expect(moved.done).toBeNull()
	expect(moved.duedate).not.toBeNull()
	// Reset-checkboxes was NOT enabled: the checked item stays checked
	expect(moved.description).toBe('- [x] bins to the curb')
	expect(await cardsInStack(boardId, 'Done')).toHaveLength(0)
})

test('reset card now clears done state without touching the due date', async ({ page }) => {
	sql(`UPDATE oc_deck_cards SET done = NOW() WHERE id = ${trashCardId}`)
	const dueBefore = sql(`SELECT duedate FROM oc_deck_cards WHERE id = ${trashCardId}`)

	await row(page, 'Take out trash').getByRole('button', { name: 'Actions' }).click()
	await page.getByRole('menuitem', { name: 'Reset card now' }).click()
	await expect(page.getByText('Card "Take out trash" reset')).toBeVisible()

	await expect.poll(() => sql(`SELECT done IS NULL FROM oc_deck_cards WHERE id = ${trashCardId}`)).toBe('t')
	expect(sql(`SELECT duedate FROM oc_deck_cards WHERE id = ${trashCardId}`)).toBe(dueBefore)
})

test('a count end condition disables the rule after its last occurrence', async ({ page }) => {
	await page.getByRole('button', { name: 'New rule' }).click()
	await page.getByRole('combobox', { name: 'Select a board' }).click()
	await option(page, 'E2E Chores').click()
	await page.getByRole('combobox', { name: 'Card to copy each time' }).click()
	await option(page, 'Water plants').click()
	await page.getByRole('combobox', { name: 'Select a stack' }).click()
	await option(page, 'Weekly').click()
	await page.getByText('Monday', { exact: true }).click()
	await page.getByText('After', { exact: true }).click()
	await page.locator('.rule-editor__count .rule-editor__number').fill('2')
	await page.getByRole('button', { name: 'Create' }).click()
	await expect(row(page, 'Water plants')).toContainText('2 times')

	// Advancement follows wall-clock time, so exhausting COUNT=2 requires
	// both occurrences to lie in the past: backdate the rule a month.
	sql(`UPDATE oc_deck_rec_rules SET dtstart = extract(epoch from now())::bigint - 2592000, next_run = extract(epoch from now())::bigint - 60 WHERE template_card_id = ${waterCardId}`)
	runSpawnJob()
	expect(sql(`SELECT enabled FROM oc_deck_rec_rules WHERE template_card_id = ${waterCardId}`)).toBe('f')
	expect(sql(`SELECT next_run IS NULL FROM oc_deck_rec_rules WHERE template_card_id = ${waterCardId}`)).toBe('t')

	await page.reload()
	await expect(row(page, 'Water plants')).toContainText('—')
})

test('the API rejects an unparseable recurrence rule', async ({ page }) => {
	const status = await page.evaluate(async ({ cardId, stackId }) => {
		const response = await fetch('/apps/deck_recurrence/rules', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: window.OC.requestToken,
			},
			body: JSON.stringify({
				templateCardId: cardId,
				targetStackId: stackId,
				rrule: 'FREQ=BOGUS',
				dtstart: Math.floor(Date.now() / 1000),
			}),
		})
		return response.status
	}, { cardId: waterCardId, stackId: weeklyStackId })
	expect(status).toBe(400)
})

test('a broken rule reports clearly in the UI and notifies once', async ({ page }) => {
	sql(`UPDATE oc_deck_rec_rules SET template_card_id = 99999, skip_if_open = false WHERE mode = 'clone' AND template_card_id <> ${waterCardId}`)
	await page.reload()

	await row(page, 'Card #99999').getByRole('button', { name: 'Actions' }).click()
	await page.getByRole('menuitem', { name: 'Create card now' }).click()
	await expect(page.getByText('The template card no longer exists')).toBeVisible()

	makeRuleDue('WHERE template_card_id = 99999')
	runSpawnJob()
	makeRuleDue('WHERE template_card_id = 99999')
	runSpawnJob()

	const failures = (await notifications.list()).filter((n) => n.app === 'deck_recurrence')
	expect(failures).toHaveLength(1)
	expect(failures[0].subject).toContain('could not be created')
	expect(failures[0].link).toContain('/apps/deck_recurrence')
})

test('deleting rules through the UI empties the list', async ({ page }) => {
	for (const title of ['Card #99999', 'Take out trash', 'Water plants']) {
		await row(page, title).getByRole('button', { name: 'Actions' }).click()
		await page.getByRole('menuitem', { name: 'Delete' }).click()
		await expect(row(page, title)).toHaveCount(0)
	}
	await expect(page.getByText('No recurring cards yet')).toBeVisible()
	expect(sql('SELECT count(*) FROM oc_deck_rec_rules')).toBe('0')
	expect(sql('SELECT count(*) FROM oc_deck_rec_spawns')).toBe('0')
})
