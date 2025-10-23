<!--
  - SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="authorized-apps">
		<h3>{{ t('oidc', 'Authorized Applications') }}</h3>
		<p class="description">
			{{ t('oidc', 'These applications have access to your account. You can revoke access at any time.') }}
		</p>

		<div v-if="loading" class="loading">
			<span class="icon-loading-small"></span>
			{{ t('oidc', 'Loading...') }}
		</div>

		<div v-else-if="consents.length === 0" class="empty-content">
			<div class="icon-checkmark"></div>
			<p>{{ t('oidc', 'No authorized applications') }}</p>
		</div>

		<div v-else class="consents-list">
			<div v-for="consent in consents" :key="consent.id" class="consent-item">
				<div class="consent-info">
					<h4>{{ consent.clientName }}</h4>
					<p class="client-id">
						<strong>{{ t('oidc', 'Client ID:') }}</strong>
						<code>{{ consent.clientIdentifier }}</code>
					</p>
					<div class="scopes">
						<strong>{{ t('oidc', 'Permissions:') }}</strong>
						<div class="scope-badges">
							<span
								v-for="scope in getAllowedScopes(consent)"
								:key="scope"
								class="scope-badge"
								:class="{
									active: isScopeGranted(consent, scope),
									inactive: !isScopeGranted(consent, scope),
									mandatory: scope === 'openid'
								}">
								{{ scope }}
							</span>
						</div>
					</div>
					<p class="date">
						{{ t('oidc', 'Authorized on:') }} {{ formatDate(consent.createdAt) }}
					</p>
				</div>
				<div class="consent-actions">
					<button
						class="button"
						@click="openModifyModal(consent)">
						{{ t('oidc', 'Modify Permissions') }}
					</button>
					<button
						class="button secondary"
						:disabled="revoking === consent.clientId"
						@click="revokeAccess(consent.clientId, consent.clientName)">
						<span v-if="revoking === consent.clientId" class="icon-loading-small"></span>
						<span v-else>{{ t('oidc', 'Revoke Access') }}</span>
					</button>
				</div>
			</div>
		</div>

		<!-- Modify Permissions Modal -->
		<div v-if="showModal" class="modal-backdrop" @click.self="closeModal">
			<div class="modal-content">
				<div class="modal-header">
					<h3>{{ t('oidc', 'Modify Permissions') }}</h3>
					<button class="close-button" @click="closeModal">&times;</button>
				</div>
				<div class="modal-body">
					<p class="modal-client-name">{{ editingConsent ? editingConsent.clientName : '' }}</p>
					<p class="modal-description">
						{{ t('oidc', 'Select the permissions you want to grant to this application:') }}
					</p>
					<div class="scope-list">
						<label
							v-for="scope in modalScopes"
							:key="scope"
							class="scope-checkbox-label">
							<input
								type="checkbox"
								:checked="modalSelectedScopes.includes(scope)"
								:disabled="scope === 'openid'"
								@change="toggleModalScope(scope, $event.target.checked)">
							<span :class="{ mandatory: scope === 'openid' }">
								{{ scope }}
								<span v-if="scope === 'openid'" class="mandatory-note">({{ t('oidc', 'required') }})</span>
							</span>
						</label>
					</div>
				</div>
				<div class="modal-footer">
					<button class="button secondary" @click="closeModal">
						{{ t('oidc', 'Cancel') }}
					</button>
					<button
						class="button primary"
						:disabled="saving"
						@click="savePermissions">
						<span v-if="saving" class="icon-loading-small"></span>
						<span v-else>{{ t('oidc', 'Save') }}</span>
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AuthorizedApps',
	data() {
		return {
			consents: [],
			loading: true,
			revoking: null,
			showModal: false,
			editingConsent: null,
			modalScopes: [],
			modalSelectedScopes: [],
			saving: false,
		}
	},
	mounted() {
		this.loadConsents()
	},
	methods: {
		t,
		async loadConsents() {
			console.log('[loadConsents] Loading consents...')
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/oidc/api/consents'), {
					headers: {
						requesttoken: OC.requestToken,
					},
				})

				if (!response.ok) {
					const text = await response.text()
					console.error('[loadConsents] API Error:', response.status, text)
					OC.Notification.showTemporary(t('oidc', 'Failed to load authorized applications') + ': ' + response.status)
					return
				}

				const data = await response.json()
				console.log('[loadConsents] Loaded consents:', data)
				this.consents = data
			} catch (error) {
				console.error('[loadConsents] Exception:', error)
				OC.Notification.showTemporary(t('oidc', 'Failed to load authorized applications') + ': ' + error.message)
			} finally {
				this.loading = false
			}
		},
		async revokeAccess(clientId, clientName) {
			const message = t('oidc', 'Are you sure you want to revoke access for "{clientName}"?').replace('{clientName}', clientName)
			if (!confirm(message)) {
				return
			}

			this.revoking = clientId
			try {
				const response = await fetch(generateUrl('/apps/oidc/api/consents/' + clientId), {
					method: 'DELETE',
					headers: {
						requesttoken: OC.requestToken,
					},
				})

				if (response.ok) {
					OC.Notification.showTemporary(t('oidc', 'Access revoked successfully'))
					this.loadConsents() // Reload list
				} else {
					OC.Notification.showTemporary(t('oidc', 'Failed to revoke access'))
				}
			} catch (error) {
				console.error('Error revoking consent:', error)
				OC.Notification.showTemporary(t('oidc', 'Failed to revoke access'))
			} finally {
				this.revoking = null
			}
		},
		getScopes(scopesString) {
			return scopesString.split(' ').filter(s => s.trim())
		},
		getAllowedScopes(consent) {
			// Return all scopes allowed by the client
			if (consent.allowedScopes) {
				return consent.allowedScopes.split(' ').filter(s => s.trim())
			}
			// Fallback to granted scopes if allowedScopes not available
			return this.getScopes(consent.scopesGranted)
		},
		isScopeGranted(consent, scope) {
			const grantedScopes = this.getScopes(consent.scopesGranted)
			return grantedScopes.includes(scope)
		},
		openModifyModal(consent) {
			this.editingConsent = consent
			// Get all allowed scopes for this client
			this.modalScopes = consent.allowedScopes ? consent.allowedScopes.split(' ').filter(s => s.trim()) : []

			// Debug logging
			console.log('Opening modal for client:', consent.clientName)
			console.log('Allowed scopes:', consent.allowedScopes)
			console.log('Parsed modal scopes:', this.modalScopes)
			console.log('Currently granted scopes:', consent.scopesGranted)

			// If no allowed scopes, show error and don't open modal
			if (this.modalScopes.length === 0) {
				OC.Notification.showTemporary(
					t('oidc', 'This client has no allowed scopes configured. Please contact the administrator.')
				)
				return
			}

			// Pre-select currently granted scopes, but only those that are still allowed
			const grantedScopes = this.getScopes(consent.scopesGranted)
			this.modalSelectedScopes = grantedScopes.filter(scope => this.modalScopes.includes(scope))

			// Log if any scopes were filtered out (no longer allowed)
			const removedScopes = grantedScopes.filter(scope => !this.modalScopes.includes(scope))
			if (removedScopes.length > 0) {
				console.log('Scopes no longer allowed by client (filtered out):', removedScopes)
			}

			this.showModal = true
		},
		closeModal() {
			this.showModal = false
			this.editingConsent = null
			this.modalScopes = []
			this.modalSelectedScopes = []
		},
		toggleModalScope(scope, checked) {
			if (checked) {
				// Add scope if not already selected
				if (!this.modalSelectedScopes.includes(scope)) {
					this.modalSelectedScopes.push(scope)
				}
			} else {
				// Remove scope
				this.modalSelectedScopes = this.modalSelectedScopes.filter(s => s !== scope)
			}
		},
		async savePermissions() {
			if (!this.editingConsent) return

			// Ensure openid is always included
			if (!this.modalSelectedScopes.includes('openid')) {
				this.modalSelectedScopes.push('openid')
			}

			console.log('[savePermissions] Saving scopes:', this.modalSelectedScopes)
			console.log('[savePermissions] Client ID:', this.editingConsent.clientId)

			this.saving = true

			try {
				const url = generateUrl('/apps/oidc/api/consents/' + this.editingConsent.clientId + '/scopes')
				console.log('[savePermissions] Sending PATCH to:', url)
				console.log('[savePermissions] Request body:', JSON.stringify({ scopes: this.modalSelectedScopes }))

				const response = await fetch(url, {
					method: 'PATCH',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ scopes: this.modalSelectedScopes }),
				})

				if (response.ok) {
					const data = await response.json()

					// Update the consent in the list
					const consentIndex = this.consents.findIndex(c => c.clientId === this.editingConsent.clientId)
					if (consentIndex !== -1) {
						this.consents[consentIndex].scopesGranted = data.scopesGranted
						this.consents[consentIndex].updatedAt = data.updatedAt
					}

					OC.Notification.showTemporary(t('oidc', 'Permissions updated successfully'))
					this.closeModal()
				} else {
					const error = await response.json()
					OC.Notification.showTemporary(t('oidc', 'Failed to update permissions') + ': ' + (error.error || response.status))
				}
			} catch (error) {
				console.error('Error updating permissions:', error)
				OC.Notification.showTemporary(t('oidc', 'Failed to update permissions'))
			} finally {
				this.saving = false
			}
		},
		formatScopes(scopesString) {
			const scopeLabels = {
				openid: t('oidc', 'Basic authentication'),
				profile: t('oidc', 'Profile information'),
				email: t('oidc', 'Email address'),
				roles: t('oidc', 'Group memberships'),
				groups: t('oidc', 'Group memberships'),
			}

			return scopesString.split(' ')
				.map(scope => scopeLabels[scope] || scope)
				.join(', ')
		},
		formatDate(timestamp) {
			return new Date(timestamp * 1000).toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.authorized-apps {
	margin-top: 20px;
}

.description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
}

.loading {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 20px;
	color: var(--color-text-maxcontrast);
}

.empty-content {
	text-align: center;
	padding: 40px 20px;
	color: var(--color-text-maxcontrast);
}

.empty-content .icon-checkmark {
	font-size: 64px;
	margin-bottom: 15px;
	opacity: 0.3;
}

.consents-list {
	display: flex;
	flex-direction: column;
	gap: 15px;
}

.consent-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 15px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.consent-item:hover {
	background: var(--color-background-dark);
}

.consent-info {
	flex: 1;
}

.consent-info h4 {
	margin: 0 0 10px 0;
	font-weight: bold;
}

.consent-info .client-id {
	margin: 5px 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.consent-info .client-id code {
	background: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 3px;
	font-family: monospace;
	font-size: 11px;
}

.consent-info .scopes {
	margin: 5px 0;
	font-size: 14px;
}

.scope-badges {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}

.scope-badge {
	display: inline-block;
	padding: 4px 10px;
	border-radius: 12px;
	font-size: 13px;
	transition: all 0.2s ease;
}

.scope-badge.active {
	background: var(--color-primary-element-light);
	border: 2px solid var(--color-primary-element);
	color: var(--color-main-text);
	font-weight: 500;
}

.scope-badge.inactive {
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	opacity: 0.7;
}

.scope-badge.mandatory {
	font-weight: bold;
}

.consent-actions {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: stretch;
}

.consent-info .date {
	margin: 5px 0 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

button {
	padding: 8px 16px;
	white-space: nowrap;
	display: flex;
	align-items: center;
	gap: 5px;
}

button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

/* Modal Styles */
.modal-backdrop {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.modal-content {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 20px rgba(0, 0, 0, 0.3);
	max-width: 500px;
	width: 90%;
	max-height: 80vh;
	display: flex;
	flex-direction: column;
}

.modal-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 20px;
	border-bottom: 1px solid var(--color-border);
}

.modal-header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: bold;
}

.close-button {
	background: none;
	border: none;
	font-size: 24px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	padding: 0;
	width: 32px;
	height: 32px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.close-button:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
	border-radius: 50%;
}

.modal-body {
	padding: 20px;
	overflow-y: auto;
	flex: 1;
}

.modal-client-name {
	font-weight: bold;
	font-size: 16px;
	margin: 0 0 10px 0;
}

.modal-description {
	color: var(--color-text-maxcontrast);
	margin: 0 0 20px 0;
}

.scope-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.scope-checkbox-label {
	display: flex;
	align-items: center;
	gap: 10px;
	cursor: pointer;
	padding: 10px;
	border-radius: var(--border-radius);
	transition: background 0.2s;
}

.scope-checkbox-label:hover {
	background: var(--color-background-hover);
}

.scope-checkbox-label input[type="checkbox"] {
	cursor: pointer;
	margin: 0;
}

.scope-checkbox-label input[type="checkbox"]:disabled {
	cursor: not-allowed;
}

.scope-checkbox-label span.mandatory {
	font-weight: bold;
}

.mandatory-note {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-left: 5px;
}

.modal-footer {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	padding: 20px;
	border-top: 1px solid var(--color-border);
}

.modal-footer button {
	padding: 10px 20px;
}
</style>
