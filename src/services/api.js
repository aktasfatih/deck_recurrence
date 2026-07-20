// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const rulesUrl = (path = '') => generateUrl('/apps/deck_recurrence/rules' + path)
const archiveRulesUrl = (path = '') => generateUrl('/apps/deck_recurrence/archive-rules' + path)
const deckUrl = (path) => generateUrl('/apps/deck/api/v1.0' + path)
const deckHeaders = { 'OCS-APIRequest': 'true' }

export default {
	async listRules() {
		return (await axios.get(rulesUrl())).data
	},
	async createRule(rule) {
		return (await axios.post(rulesUrl(), rule)).data
	},
	async updateRule(rule) {
		return (await axios.put(rulesUrl('/' + rule.id), rule)).data
	},
	async deleteRule(id) {
		return (await axios.delete(rulesUrl('/' + id))).data
	},
	async spawnRule(id) {
		return (await axios.post(rulesUrl('/' + id + '/spawn'))).data
	},

	async listArchiveRules() {
		return (await axios.get(archiveRulesUrl())).data
	},
	async createArchiveRule(rule) {
		return (await axios.post(archiveRulesUrl(), rule)).data
	},
	async updateArchiveRule(rule) {
		return (await axios.put(archiveRulesUrl('/' + rule.id), rule)).data
	},
	async deleteArchiveRule(id) {
		return (await axios.delete(archiveRulesUrl('/' + id))).data
	},
	async runArchiveRule(id) {
		return (await axios.post(archiveRulesUrl('/' + id + '/run'))).data
	},

	async listBoards() {
		return (await axios.get(deckUrl('/boards'), { headers: deckHeaders })).data
	},
	async listStacks(boardId) {
		return (await axios.get(deckUrl(`/boards/${boardId}/stacks`), { headers: deckHeaders })).data
	},
}
