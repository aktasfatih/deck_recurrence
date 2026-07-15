// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createApp } from 'vue'
import App from './App.vue'

const mount = document.getElementById('deck_recurrence')
const app = createApp(App, {
	deckEnabled: mount.dataset.deckEnabled === '1',
})
app.mount(mount)
