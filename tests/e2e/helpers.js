// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { execSync } from 'node:child_process'

const NC = 'deck_recurrence-dev'
const DB = 'deck_recurrence-dev-db'
const BASE = 'http://localhost:8890'
const AUTH = 'Basic ' + Buffer.from('admin:admin').toString('base64')

export function occ(args) {
	return execSync(`docker exec -u www-data ${NC} php occ ${args}`, { encoding: 'utf8' })
}

export function addUser(name, password) {
	try {
		execSync(
			`docker exec -e OC_PASS=${password} -u www-data ${NC} php occ user:add --password-from-env ${name}`,
			{ encoding: 'utf8', stdio: 'pipe' },
		)
	} catch (e) {
		const output = String(e.stdout ?? '') + String(e.stderr ?? '')
		if (!output.includes('already exists')) {
			throw e
		}
	}
}

export function sql(query) {
	return execSync(
		`docker exec ${DB} psql -U nextcloud -d nextcloud -t -A -c ${JSON.stringify(query)}`,
		{ encoding: 'utf8' },
	).trim()
}

function runJob(className) {
	const list = occ(`background-job:list --class '${className}'`)
	const id = list.match(/^\|\s*(\d+)/m)?.[1]
	if (!id) {
		throw new Error(`${className} job not registered`)
	}
	occ(`background-job:execute ${id} --force-execute`)
}

export function runSpawnJob() {
	runJob('OCA\\DeckRecurrence\\Cron\\SpawnRecurringCards')
}

export function runArchiveJob() {
	runJob('OCA\\DeckRecurrence\\Cron\\ArchiveDoneCards')
}

export function makeRuleDue(extra = '') {
	sql(`UPDATE oc_deck_rec_rules SET next_run = extract(epoch from now())::bigint - 60 ${extra}`)
}

async function api(method, path, body) {
	const response = await fetch(BASE + path, {
		method,
		headers: {
			Authorization: AUTH,
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
			Accept: 'application/json',
		},
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!response.ok) {
		throw new Error(`${method} ${path} -> ${response.status}`)
	}
	const text = await response.text()
	return text ? JSON.parse(text) : null
}

export const deck = {
	createBoard: (title) => api('POST', '/index.php/apps/deck/api/v1.0/boards', { title, color: '33AA55' }),
	deleteBoard: (id) => api('DELETE', `/index.php/apps/deck/api/v1.0/boards/${id}`),
	listBoards: () => api('GET', '/index.php/apps/deck/api/v1.0/boards'),
	createStack: (boardId, title, order) =>
		api('POST', `/index.php/apps/deck/api/v1.0/boards/${boardId}/stacks`, { title, order }),
	createCard: (boardId, stackId, card) =>
		api('POST', `/index.php/apps/deck/api/v1.0/boards/${boardId}/stacks/${stackId}/cards`, card),
	assignUser: (boardId, stackId, cardId, userId) =>
		api('PUT', `/index.php/apps/deck/api/v1.0/boards/${boardId}/stacks/${stackId}/cards/${cardId}/assignUser`, { userId }),
	createLabel: (boardId, title, color) =>
		api('POST', `/index.php/apps/deck/api/v1.0/boards/${boardId}/labels`, { title, color }),
	assignLabel: (boardId, stackId, cardId, labelId) =>
		api('PUT', `/index.php/apps/deck/api/v1.0/boards/${boardId}/stacks/${stackId}/cards/${cardId}/assignLabel`, { labelId }),
	listStacks: (boardId) => api('GET', `/index.php/apps/deck/api/v1.0/boards/${boardId}/stacks`),
	shareBoard: (boardId, userId) =>
		api('POST', `/index.php/apps/deck/api/v1.0/boards/${boardId}/acl`, {
			type: 0,
			participant: userId,
			permissionEdit: true,
			permissionShare: false,
			permissionManage: false,
		}),
	unshareBoard: (boardId, aclId) =>
		api('DELETE', `/index.php/apps/deck/api/v1.0/boards/${boardId}/acl/${aclId}`),
}

export async function cardsInStack(boardId, stackTitle) {
	const stacks = await deck.listStacks(boardId)
	const stack = stacks.find((s) => s.title === stackTitle)
	return (stack?.cards ?? []).sort((a, b) => a.id - b.id)
}

/** Call the app's OCS API as a client would (basic auth, no session).
 * Returns status and unwrapped payload instead of throwing, so error
 * responses can be asserted. */
export async function ocs(method, path, body, auth = 'admin:admin') {
	const url = `${BASE}/ocs/v2.php/apps/deck_recurrence${path}${path.includes('?') ? '&' : '?'}format=json`
	const response = await fetch(url, {
		method,
		headers: {
			Authorization: 'Basic ' + Buffer.from(auth).toString('base64'),
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
			Accept: 'application/json',
		},
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	const json = await response.json().catch(() => null)
	return { status: response.status, data: json?.ocs?.data ?? null, message: json?.ocs?.meta?.message ?? null }
}

export const notifications = {
	list: async () => (await api('GET', '/ocs/v2.php/apps/notifications/api/v2/notifications?format=json')).ocs.data,
	clear: () => api('DELETE', '/ocs/v2.php/apps/notifications/api/v2/notifications?format=json'),
}

/** Remove app data and boards left over from previous runs. A failed
 * migration-replay run may have left the schema rewound, so replay the
 * app's migrations first and tolerate missing tables. */
export async function resetState() {
	try {
		occ('app:enable deck_recurrence')
	} catch (e) { /* already enabled */ }
	for (const table of ['oc_deck_rec_spawns', 'oc_deck_rec_rules', 'oc_deck_rec_arch_rules']) {
		try {
			sql(`DELETE FROM ${table}`)
		} catch (e) { /* table missing until migrations replay */ }
	}
	await notifications.clear()
	for (const board of await deck.listBoards()) {
		if (board.title.startsWith('E2E ') && !board.deletedAt) {
			await deck.deleteBoard(board.id)
		}
	}
}

export async function login(page, user = 'admin', password = 'admin') {
	await page.goto('http://localhost:8890/login')
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(password)
	await page.locator('button[type="submit"]').click()
	await page.waitForURL('**/apps/**')
}
