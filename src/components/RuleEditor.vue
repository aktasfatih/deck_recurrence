<!--
SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog :name="rule ? t('deck_recurrence', 'Edit rule') : t('deck_recurrence', 'New rule')"
		size="normal"
		@closing="$emit('close')">
		<div class="rule-editor">
			<label>{{ t('deck_recurrence', 'Type') }}</label>
			<div class="rule-editor__ends">
				<NcCheckboxRadioSwitch v-model="mode" value="clone" name="mode" type="radio">
					{{ t('deck_recurrence', 'Create a new card from a template') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="mode" value="reset" name="mode" type="radio">
					{{ t('deck_recurrence', 'Move the same card back') }}
				</NcCheckboxRadioSwitch>
			</div>

			<label>{{ t('deck_recurrence', 'Board') }}</label>
			<NcSelect v-model="board"
				:options="boards"
				label="title"
				:placeholder="t('deck_recurrence', 'Select a board')"
				@update:model-value="onBoardChange" />

			<label>{{ mode === 'reset' ? t('deck_recurrence', 'Card') : t('deck_recurrence', 'Template card') }}</label>
			<NcSelect v-model="templateCard"
				:options="cards"
				label="title"
				:disabled="!board"
				:loading="loadingStacks"
				:placeholder="mode === 'reset' ? t('deck_recurrence', 'Card to move each time') : t('deck_recurrence', 'Card to copy each time')" />

			<label>{{ mode === 'reset' ? t('deck_recurrence', 'Move the card to') : t('deck_recurrence', 'Create new cards in') }}</label>
			<NcSelect v-model="targetStack"
				:options="stacks"
				label="title"
				:disabled="!board"
				:loading="loadingStacks"
				:placeholder="t('deck_recurrence', 'Select a stack')" />

			<label>{{ t('deck_recurrence', 'Repeat') }}</label>
			<div class="rule-editor__frequency">
				<span>{{ t('deck_recurrence', 'Every') }}</span>
				<NcTextField v-model="interval"
					type="number"
					min="1"
					class="rule-editor__interval"
					:label="t('deck_recurrence', 'Interval')"
					:show-label="false" />
				<NcSelect v-model="frequency"
					:options="frequencyOptions"
					label="label"
					:clearable="false" />
			</div>

			<template v-if="frequency.id === 'WEEKLY'">
				<label>{{ t('deck_recurrence', 'On days') }}</label>
				<div class="rule-editor__weekdays">
					<NcCheckboxRadioSwitch v-for="day in WEEKDAYS"
						:key="day.id"
						:model-value="weekdays.includes(day.id)"
						@update:model-value="toggleWeekday(day.id, $event)">
						{{ day.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</template>

			<label>{{ t('deck_recurrence', 'Ends') }}</label>
			<div class="rule-editor__ends">
				<NcCheckboxRadioSwitch v-model="ends" value="never" name="ends" type="radio">
					{{ t('deck_recurrence', 'Never') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="ends" value="until" name="ends" type="radio">
					{{ t('deck_recurrence', 'On date') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="ends" value="count" name="ends" type="radio">
					{{ t('deck_recurrence', 'After') }}
				</NcCheckboxRadioSwitch>
				<NcDateTimePickerNative v-if="ends === 'until'"
					id="rule-editor-until"
					v-model="until"
					type="date"
					:label="t('deck_recurrence', 'Last day')"
					:hide-label="true" />
				<div v-if="ends === 'count'" class="rule-editor__count">
					<NcTextField v-model="count"
						type="number"
						min="1"
						class="rule-editor__interval"
						:label="t('deck_recurrence', 'Occurrences')"
						:show-label="false" />
					<span>{{ t('deck_recurrence', 'occurrences') }}</span>
				</div>
			</div>

			<label>{{ t('deck_recurrence', 'Options') }}</label>
			<NcCheckboxRadioSwitch v-if="mode === 'clone'" v-model="skipIfOpen">
				{{ t('deck_recurrence', 'Only create a new card when the previous one is done, archived or deleted') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="resetCheckboxes">
				{{ t('deck_recurrence', 'Uncheck all checklist items on the new card') }}
			</NcCheckboxRadioSwitch>

			<label for="rule-editor-first">{{ t('deck_recurrence', 'First occurrence') }}</label>
			<NcDateTimePickerNative id="rule-editor-first"
				v-model="firstOccurrence"
				type="datetime-local"
				:label="t('deck_recurrence', 'First occurrence')"
				:hide-label="true" />
			<p class="rule-editor__hint">
				{{ t('deck_recurrence', 'Cards are created at the occurrence time and get it as their due date. The template card itself is never modified.') }}
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
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../services/api.js'
import { buildRrule, parseRrule, WEEKDAYS } from '../services/rrule.js'

export default {
	name: 'RuleEditor',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDateTimePickerNative,
		NcDialog,
		NcSelect,
		NcTextField,
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
			WEEKDAYS,
			mode: 'clone',
			board: null,
			stacks: [],
			templateCard: null,
			targetStack: null,
			loadingStacks: false,
			frequency: null,
			interval: '1',
			weekdays: [],
			ends: 'never',
			until: null,
			count: '10',
			skipIfOpen: false,
			resetCheckboxes: false,
			firstOccurrence: new Date(),
			saving: false,
			frequencyOptions: [
				{ id: 'DAILY', label: t('deck_recurrence', 'day(s)') },
				{ id: 'WEEKLY', label: t('deck_recurrence', 'week(s)') },
				{ id: 'MONTHLY', label: t('deck_recurrence', 'month(s)') },
				{ id: 'YEARLY', label: t('deck_recurrence', 'year(s)') },
			],
		}
	},
	computed: {
		cards() {
			return this.stacks.flatMap((stack) => stack.cards ?? [])
		},
		valid() {
			return this.templateCard !== null
				&& this.targetStack !== null
				&& parseInt(this.interval, 10) >= 1
				&& this.firstOccurrence instanceof Date
				&& (this.frequency?.id !== 'WEEKLY' || this.weekdays.length > 0)
				&& (this.ends !== 'until' || this.until instanceof Date)
				&& (this.ends !== 'count' || parseInt(this.count, 10) >= 1)
		},
	},
	async created() {
		this.frequency = this.frequencyOptions[1]
		if (this.rule) {
			const parsed = parseRrule(this.rule.rrule)
			this.frequency = this.frequencyOptions.find((f) => f.id === parsed.frequency) ?? this.frequencyOptions[1]
			this.interval = String(parsed.interval)
			this.weekdays = parsed.weekdays
			if (parsed.count) {
				this.ends = 'count'
				this.count = String(parsed.count)
			} else if (parsed.until) {
				this.ends = 'until'
				this.until = parsed.until
			}
			this.mode = this.rule.mode ?? 'clone'
			this.skipIfOpen = this.rule.skipIfOpen ?? false
			this.resetCheckboxes = this.rule.resetCheckboxes ?? false
			this.firstOccurrence = new Date(this.rule.dtstart * 1000)
			await this.preselectFromRule()
		}
	},
	methods: {
		t,
		async onBoardChange(board) {
			this.templateCard = null
			this.targetStack = null
			this.stacks = []
			if (!board) {
				return
			}
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
		async preselectFromRule() {
			for (const board of this.boards) {
				const stacks = await api.listStacks(board.id).catch(() => [])
				const stack = stacks.find((s) => s.id === this.rule.targetStackId)
				const card = stacks.flatMap((s) => s.cards ?? []).find((c) => c.id === this.rule.templateCardId)
				if (stack || card) {
					this.board = board
					this.stacks = stacks
					this.targetStack = stack ?? null
					this.templateCard = card ?? null
					return
				}
			}
		},
		toggleWeekday(id, checked) {
			this.weekdays = checked
				? [...this.weekdays, id]
				: this.weekdays.filter((d) => d !== id)
		},
		async save() {
			this.saving = true
			try {
				const payload = {
					templateCardId: this.templateCard.id,
					targetStackId: this.targetStack.id,
					rrule: buildRrule({
						frequency: this.frequency.id,
						interval: parseInt(this.interval, 10),
						weekdays: this.weekdays,
						count: this.ends === 'count' ? parseInt(this.count, 10) : null,
						until: this.ends === 'until' ? this.until : null,
					}),
					dtstart: Math.floor(this.firstOccurrence.getTime() / 1000),
					skipIfOpen: this.mode === 'clone' && this.skipIfOpen,
					resetCheckboxes: this.resetCheckboxes,
					mode: this.mode,
				}
				const saved = this.rule
					? await api.updateRule({ ...payload, id: this.rule.id, enabled: this.rule.enabled })
					: await api.createRule(payload)
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

.rule-editor__frequency {
	display: flex;
	align-items: center;
	gap: 8px;
}

.rule-editor__interval {
	max-width: 80px;
}

/* The field is already labelled by the surrounding sentence
   ("Every … week(s)"); hide the input's own floating label. */
.rule-editor__interval :deep(label) {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
}

.rule-editor__weekdays {
	display: flex;
	flex-wrap: wrap;
	gap: 0 12px;
}

.rule-editor__ends {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0 12px;
}

.rule-editor__count {
	display: flex;
	align-items: center;
	gap: 8px;
}

.rule-editor__hint {
	color: var(--color-text-maxcontrast);
	margin-block-start: 8px;
}
</style>
