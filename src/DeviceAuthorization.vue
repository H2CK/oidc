<!--
  - SPDX-FileCopyrightText: 2026 Timill
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="device-page">
		<div class="device-card">
			<h2>{{ t('oidc', 'Connect a device') }}</h2>

			<form v-if="currentMode === 'enter'" @submit.prevent="verifyCode">
				<p>{{ t('oidc', 'Enter the code displayed on your device.') }}</p>
				<input v-model="enteredCode" type="text" autocomplete="one-time-code" autofocus required>
				<button class="button primary" type="submit">{{ t('oidc', 'Continue') }}</button>
			</form>

			<div v-else-if="currentMode === 'approve'">
				<p>{{ t('oidc', '{clientName} is requesting access to your account.', { clientName }) }}</p>
				<p><strong>{{ formattedCode }}</strong></p>
				<p>{{ t('oidc', 'Requested permissions:') }} {{ scope }}</p>
				<div class="actions">
					<button class="button secondary" :disabled="busy" @click="respond('deny')">{{ t('oidc', 'Deny') }}</button>
					<button class="button primary" :disabled="busy" @click="respond('approve')">{{ t('oidc', 'Allow') }}</button>
				</div>
			</div>

			<p v-else-if="currentMode === 'complete'">{{ t('oidc', 'The device request is complete. You can close this page.') }}</p>
			<p v-else class="error">{{ currentMessage }}</p>
		</div>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'

const props = defineProps({
	mode: { type: String, required: true },
	userCode: { type: String, default: '' },
	clientName: { type: String, default: '' },
	scope: { type: String, default: '' },
	message: { type: String, default: '' },
})

const enteredCode = ref(props.userCode)
const currentMode = ref(props.mode)
const currentMessage = ref(props.message)
const busy = ref(false)
const formattedCode = computed(() => {
	const normalized = props.userCode.replace(/[^A-Za-z0-9]/g, '').toUpperCase()
	return normalized.length === 8 ? normalized.slice(0, 4) + '-' + normalized.slice(4) : normalized
})

function verifyCode() {
	window.location.href = generateUrl('/apps/oidc/device') + '?user_code=' + encodeURIComponent(enteredCode.value)
}

async function respond(action) {
	busy.value = true
	try {
		const body = new URLSearchParams({ user_code: props.userCode })
		await axios.post(generateUrl('/apps/oidc/device/' + action), body)
		currentMode.value = 'complete'
	} catch (error) {
		currentMode.value = 'error'
		currentMessage.value = t('oidc', 'The device request could not be completed. Please try again.')
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.device-page {
	min-height: 70vh;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 24px;
}

.device-card {
	width: min(560px, 100%);
	padding: 32px;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 18px var(--color-box-shadow);
}

input {
	width: 100%;
	margin: 16px 0;
	font-size: 1.4rem;
	text-transform: uppercase;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
}

.error {
	color: var(--color-error-text);
}
</style>
