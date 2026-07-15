<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent app-name="deck_recurrence">
		<NcAppContent>
			<div class="deck-recurrence">
				<div class="deck-recurrence__header">
					<h2>{{ t('deck_recurrence', 'Recurring cards') }}</h2>
					<NcButton v-if="deckEnabled" variant="primary" @click="openEditor()">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('deck_recurrence', 'New rule') }}
					</NcButton>
				</div>

				<NcEmptyContent v-if="!deckEnabled"
					:name="t('deck_recurrence', 'Deck is not enabled')"
					:description="t('deck_recurrence', 'Install and enable the Deck app to use recurring cards.')">
					<template #icon>
						<AlertIcon />
					</template>
				</NcEmptyContent>

				<NcLoadingIcon v-else-if="loading" :size="44" class="deck-recurrence__loading" />

				<NcEmptyContent v-else-if="rules.length === 0"
					:name="t('deck_recurrence', 'No recurring cards yet')"
					:description="t('deck_recurrence', 'Create a rule to spawn a Deck card on a schedule.')">
					<template #icon>
						<RepeatIcon />
					</template>
				</NcEmptyContent>

				<table v-else class="deck-recurrence__table">
					<thead>
						<tr>
							<th>{{ t('deck_recurrence', 'Card') }}</th>
							<th>{{ t('deck_recurrence', 'Creates in') }}</th>
							<th>{{ t('deck_recurrence', 'Schedule') }}</th>
							<th>{{ t('deck_recurrence', 'Next card') }}</th>
							<th>{{ t('deck_recurrence', 'Active') }}</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="rule in rules" :key="rule.id">
							<td>{{ cardTitle(rule.templateCardId) }}</td>
							<td>{{ stackTitle(rule.targetStackId) }}</td>
							<td>{{ describeRrule(rule.rrule) }}</td>
							<td>{{ rule.nextRun ? formatDate(rule.nextRun) : '—' }}</td>
							<td>
								<NcCheckboxRadioSwitch :model-value="rule.enabled"
									type="switch"
									@update:model-value="toggleRule(rule, $event)" />
							</td>
							<td class="deck-recurrence__actions">
								<NcActions>
									<NcActionButton @click="openEditor(rule)">
										<template #icon>
											<PencilIcon :size="20" />
										</template>
										{{ t('deck_recurrence', 'Edit') }}
									</NcActionButton>
									<NcActionButton @click="removeRule(rule)">
										<template #icon>
											<DeleteIcon :size="20" />
										</template>
										{{ t('deck_recurrence', 'Delete') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>

				<RuleEditor v-if="editorOpen"
					:rule="editingRule"
					:boards="boards"
					@saved="onSaved"
					@close="editorOpen = false" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import RepeatIcon from 'vue-material-design-icons/Repeat.vue'
import RuleEditor from './components/RuleEditor.vue'
import api from './services/api.js'
import { describeRrule } from './services/rrule.js'

export default {
	name: 'App',
	components: {
		AlertIcon,
		DeleteIcon,
		NcActionButton,
		NcActions,
		NcAppContent,
		NcButton,
		NcCheckboxRadioSwitch,
		NcContent,
		NcEmptyContent,
		NcLoadingIcon,
		PencilIcon,
		PlusIcon,
		RepeatIcon,
		RuleEditor,
	},
	props: {
		deckEnabled: {
			type: Boolean,
			required: true,
		},
	},
	data() {
		return {
			loading: true,
			rules: [],
			boards: [],
			stacksById: {},
			cardsById: {},
			editorOpen: false,
			editingRule: null,
		}
	},
	async mounted() {
		if (!this.deckEnabled) {
			this.loading = false
			return
		}
		try {
			const [rules, boards] = await Promise.all([api.listRules(), api.listBoards()])
			this.rules = rules
			this.boards = boards
			await this.indexDeckEntities()
		} catch (e) {
			console.error(e)
			showError(t('deck_recurrence', 'Could not load recurring card rules'))
		} finally {
			this.loading = false
		}
	},
	methods: {
		t,
		describeRrule,
		async indexDeckEntities() {
			const stackLists = await Promise.all(
				this.boards.map((board) => api.listStacks(board.id).catch(() => [])),
			)
			for (const stacks of stackLists) {
				for (const stack of stacks) {
					this.stacksById[stack.id] = stack
					for (const card of stack.cards ?? []) {
						this.cardsById[card.id] = card
					}
				}
			}
		},
		cardTitle(cardId) {
			return this.cardsById[cardId]?.title ?? t('deck_recurrence', 'Card #{id}', { id: cardId })
		},
		stackTitle(stackId) {
			const stack = this.stacksById[stackId]
			if (!stack) {
				return t('deck_recurrence', 'Stack #{id}', { id: stackId })
			}
			const board = this.boards.find((b) => b.id === stack.boardId)
			return board ? `${board.title} / ${stack.title}` : stack.title
		},
		formatDate(timestamp) {
			return new Date(timestamp * 1000).toLocaleString()
		},
		openEditor(rule = null) {
			this.editingRule = rule
			this.editorOpen = true
		},
		onSaved(rule) {
			const index = this.rules.findIndex((r) => r.id === rule.id)
			if (index >= 0) {
				this.rules.splice(index, 1, rule)
			} else {
				this.rules.unshift(rule)
			}
			this.editorOpen = false
		},
		async toggleRule(rule, enabled) {
			try {
				const updated = await api.updateRule({ ...rule, enabled })
				this.rules.splice(this.rules.indexOf(rule), 1, updated)
			} catch (e) {
				console.error(e)
				showError(t('deck_recurrence', 'Could not update the rule'))
			}
		},
		async removeRule(rule) {
			try {
				await api.deleteRule(rule.id)
				this.rules = this.rules.filter((r) => r.id !== rule.id)
			} catch (e) {
				console.error(e)
				showError(t('deck_recurrence', 'Could not delete the rule'))
			}
		},
	},
}
</script>

<style scoped lang="css">
.deck-recurrence {
	max-width: 900px;
	margin: 0 auto;
	padding: 24px;
}

.deck-recurrence__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.deck-recurrence__loading {
	margin-top: 80px;
}

.deck-recurrence__table {
	width: 100%;
	border-collapse: collapse;
}

.deck-recurrence__table th,
.deck-recurrence__table td {
	text-align: start;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.deck-recurrence__actions {
	width: 44px;
}
</style>
