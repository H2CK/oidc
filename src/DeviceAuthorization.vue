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
				<div class="consent-scopes">
					<h3>{{ t('oidc', 'This application will be able to:') }}</h3>
					<div class="scope-list">
						<div v-for="entry in parsedScopes" :key="entry.name" class="scope-item">
							<span class="scope-title">{{ entry.label }}</span>
							<span class="scope-description">{{ entry.description }}</span>
						</div>
					</div>
				</div>
				<div class="actions">
					<button class="button secondary" :disabled="busy" @click="respond('deny')">{{ t('oidc', 'Deny') }}</button>
					<button class="button primary" :disabled="busy" @click="respond('approve')">{{ t('oidc', 'Allow') }}</button>
				</div>
				<p class="consent-note">
					{{ t('oidc', 'You can revoke this access at any time from your account settings.') }}
				</p>
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

const scopeDescriptions = {
	openid: {
		label: t('oidc', 'Basic authentication'),
		description: t('oidc', 'Verify your identity (required)'),
	},
	profile: {
		label: t('oidc', 'Profile information'),
		description: t('oidc', 'Access your name, username, profile picture, and quota'),
	},
	email: {
		label: t('oidc', 'Email address'),
		description: t('oidc', 'Access your email address and verification status'),
	},
	roles: {
		label: t('oidc', 'Group memberships'),
		description: t('oidc', 'Access your Nextcloud groups and roles'),
	},
	groups: {
		label: t('oidc', 'Group memberships'),
		description: t('oidc', 'Access your Nextcloud group information'),
	},
	offline_access: {
		label: t('oidc', 'Access when you\'re away'),
		description: t('oidc', 'Allow this app to access your data even when you\'re not signed in'),
	},
}

const parsedScopes = computed(() => props.scope.split(' ').filter(s => s.trim() !== '').map(scope => ({
	name: scope,
	label: scopeDescriptions[scope]?.label || scope,
	description: scopeDescriptions[scope]?.description || '',
})))

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

.consent-scopes {
	margin: 20px 0;
}

.consent-scopes h3 {
	font-size: 18px;
	margin-bottom: 15px;
}

.scope-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.scope-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.scope-title {
	font-weight: bold;
	font-size: 14px;
}

.scope-description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
}

.consent-note {
	margin-top: 16px;
	text-align: center;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error-text);
}
</style>
