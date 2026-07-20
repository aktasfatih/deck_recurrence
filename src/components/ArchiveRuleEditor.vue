<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog :name="rule ? t('deck_recurrence', 'Edit auto-archive rule') : t('deck_recurrence', 'New auto-archive rule')"
		size="normal"
		@closing="$emit('close')">
		<div class="rule-editor">
			<label>{{ t('deck_recurrence', 'Board') }}</label>
			<NcSelect v-model="board"
				:options="boards"
				label="title"
				:placeholder="t('deck_recurrence', 'Select a board')"
				@update:model-value="onBoardChange" />

			<label>{{ t('deck_recurrence', 'Stack') }}</label>
			<NcSelect v-model="stack"
				:options="stacks"
				label="title"
				:disabled="!board"
				:loading="loadingStacks"
				:placeholder="t('deck_recurrence', 'All stacks')" />

			<label>{{ t('deck_recurrence', 'Archive a done card when') }}</label>
			<div class="rule-editor__ends">
				<NcCheckboxRadioSwitch v-model="mode" value="done_for" name="archive-mode" type="radio">
					{{ t('deck_recurrence', 'It has been done for at least') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="mode" value="min_age" name="archive-mode" type="radio">
					{{ t('deck_recurrence', 'It was created at least this long ago') }}
				</NcCheckboxRadioSwitch>
			</div>
			<div class="rule-editor__count">
				<input v-model="amount"
					type="number"
					min="1"
					class="rule-editor__number"
					:aria-label="t('deck_recurrence', 'Threshold')">
				<NcSelect v-model="unit"
					:options="unitOptions"
					label="label"
					:clearable="false" />
			</div>
			<p class="rule-editor__hint">
				{{ t('deck_recurrence', 'The background job archives matching cards every 15 minutes, a batch at a time. Cards that are not marked as done are never touched.') }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('deck_recurrence', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!valid || saving" @click="save">
				{{ rule ? t('deck_recurrence', 'Save') : t('deck_recurrence', 'Create') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import api from '../services/api.js'

export default {
	name: 'ArchiveRuleEditor',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
	},
	props: {
		rule: {
			type: Object,
			default: null,
		},
		boards: {
			type: Array,
			required: true,
		},
	},
	emits: ['saved', 'close'],
	data() {
		return {
			board: null,
			stacks: [],
			stack: null,
			loadingStacks: false,
			mode: 'done_for',
			amount: '5',
			unit: null,
			saving: false,
			unitOptions: [
				{ seconds: 3600, label: t('deck_recurrence', 'hour(s)') },
				{ seconds: 86400, label: t('deck_recurrence', 'day(s)') },
				{ seconds: 604800, label: t('deck_recurrence', 'week(s)') },
			],
		}
	},
	computed: {
		valid() {
			return this.board !== null && parseInt(this.amount, 10) >= 1
		},
	},
	async created() {
		this.unit = this.unitOptions[1]
		if (this.rule) {
			this.mode = this.rule.mode ?? 'done_for'
			this.applyThreshold(this.rule.threshold)
			this.board = this.boards.find((b) => b.id === this.rule.boardId) ?? null
			if (this.board) {
				await this.loadStacks(this.board)
				this.stack = this.stacks.find((s) => s.id === this.rule.stackId) ?? null
			}
		}
	},
	methods: {
		t,
		async onBoardChange(board) {
			this.stack = null
			this.stacks = []
			if (board) {
				await this.loadStacks(board)
			}
		},
		async loadStacks(board) {
			this.loadingStacks = true
			try {
				this.stacks = await api.listStacks(board.id)
			} catch (e) {
				console.error(e)
				showError(t('deck_recurrence', 'Could not load stacks'))
			} finally {
				this.loadingStacks = false
			}
		},
		applyThreshold(threshold) {
			// Largest unit that fits evenly; rules created over the OCS API
			// with an odd threshold fall back to (rounded, at least one) hours.
			const unit = [...this.unitOptions].reverse().find((u) => threshold % u.seconds === 0)
				?? this.unitOptions[0]
			this.unit = unit
			this.amount = String(Math.max(1, Math.round(threshold / unit.seconds)))
		},
		async save() {
			this.saving = true
			try {
				const payload = {
					boardId: this.board.id,
					stackId: this.stack?.id ?? null,
					mode: this.mode,
					threshold: parseInt(this.amount, 10) * this.unit.seconds,
				}
				const saved = this.rule
					? await api.updateArchiveRule({ ...payload, id: this.rule.id, enabled: this.rule.enabled })
					: await api.createArchiveRule(payload)
				this.$emit('saved', saved)
			} catch (e) {
				console.error(e)
				showError(e.response?.data?.message ?? t('deck_recurrence', 'Could not save the rule'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="css">
.rule-editor {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-block-end: 8px;
}

.rule-editor label {
	font-weight: bold;
	margin-block-start: 12px;
}

.rule-editor__count {
	display: flex;
	align-items: center;
	gap: 8px;
}

.rule-editor__count :deep(.v-select.select) {
	margin: 0;
}

/* Shares the geometry rationale documented in RuleEditor.vue */
.rule-editor__number {
	width: 80px;
	height: calc(var(--default-clickable-area) + 2px) !important;
	margin: 0;
	padding-inline: 12px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
	background-color: transparent;
	font-size: inherit;
}

.rule-editor__ends {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0 12px;
}

.rule-editor__hint {
	color: var(--color-text-maxcontrast);
	margin-block-start: 8px;
}
</style>
