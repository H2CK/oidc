// SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const assert = require('node:assert/strict')
const { readFileSync } = require('node:fs')
const path = require('node:path')
const test = require('node:test')
const vm = require('node:vm')

// Exercise the actual Options API methods with browser/network dependencies mocked.
// No generated bundle, Nextcloud instance or third-party test dependency is needed.
function component(relativePath, globals = {}) {
	const source = readFileSync(path.join(__dirname, '../../src', relativePath), 'utf8')
	const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
		.replace(/^import .*$/gm, '')
		.replace('export default', 'module.exports =')
	const sandbox = {
		module: { exports: {} },
		t: (_app, message) => message,
		generateUrl: value => value,
		NcNoteCard: {},
		getRequestToken: () => 'synthetic-test-value',
		confirm: () => true,
		console: { error() {}, log() {} },
		...globals,
	}
	vm.runInNewContext(script, sandbox, { filename: relativePath })
	const options = sandbox.module.exports
	const state = options.data()
	Object.entries(options.methods).forEach(([name, method]) => {
		state[name] = method.bind(state)
	})
	return { options, state, sandbox }
}

function applications(globals = {}) {
	const result = component('components/AuthorizedApps.vue', globals)
	result.state.consents = [
		{ id: 10, clientId: '1', clientName: 'First' },
		{ id: 20, clientId: 2, clientName: 'Second' },
	]
	return result
}

test('pageshow resets submission and the listener is removed on unmount', () => {
	const listeners = new Map()
	const { options, state } = component('Consent.vue', {
		window: {
			addEventListener: (name, handler) => listeners.set(name, handler),
			removeEventListener: (name, handler) => {
				assert.equal(listeners.get(name), handler)
				listeners.delete(name)
			},
		},
	})
	options.mounted.call(state)
	state.submitting = true
	listeners.get('pageshow')({ persisted: true })
	assert.equal(state.submitting, false)
	options.beforeUnmount.call(state)
	assert.equal(listeners.size, 0)
})

test('double submission is prevented and a restored page can submit again', () => {
	let submissions = 0
	const { state } = component('Consent.vue', {
		document: {
			createElement: () => ({ appendChild() {}, submit() { submissions++ } }),
			body: { appendChild() {} },
		},
	})
	state.selectedScopes = ['openid']
	state.handleGrant()
	state.handleDeny()
	assert.equal(submissions, 1)
	state.resetSubmitting()
	state.handleDeny()
	assert.equal(submissions, 2)
})

test('successful revocation removes the row and reports success without OC.Notification', async () => {
	const { state } = applications({ fetch: async () => ({ ok: true }) })
	await state.revokeAccess(1, 'First')
	assert.equal(state.consents.length, 1)
	assert.equal(state.consents[0].clientId, 2)
	assert.equal(state.notification.type, 'success')
	assert.equal(state.revoking, null)
})

test('failed revocation preserves the row and reports an error', async () => {
	const { state } = applications({ fetch: async () => ({ ok: false }) })
	await state.revokeAccess(1, 'First')
	assert.equal(state.consents.length, 2)
	assert.equal(state.notification.type, 'error')
	assert.equal(state.revoking, null)
})

test('network failure preserves the row and reports an error', async () => {
	const { state } = applications({ fetch: async () => { throw new Error('Synthetic failure') } })
	await state.revokeAccess(1, 'First')
	assert.equal(state.consents.length, 2)
	assert.equal(state.notification.type, 'error')
	assert.equal(state.revoking, null)
})

test('a pending load cannot restore a revoked row', async () => {
	let resolveLoad
	const pending = new Promise(resolve => { resolveLoad = resolve })
	const { state, sandbox } = applications({ fetch: () => pending })
	const oldRows = [...state.consents]
	const loading = state.loadConsents()
	sandbox.fetch = async () => ({ ok: true })
	await state.revokeAccess(1, 'First')
	resolveLoad({ ok: true, json: async () => oldRows })
	await loading
	assert.equal(state.consents.length, 1)
	assert.equal(state.consents[0].id, 20)
	assert.equal(state.loading, false)
})

test('a newly granted consent for the same client is visible', async () => {
	const { state, sandbox } = applications({ fetch: async () => ({ ok: true }) })
	await state.revokeAccess(1, 'First')
	sandbox.fetch = async () => ({ ok: true, json: async () => [{ id: 30, clientId: '1' }] })
	await state.loadConsents()
	assert.equal(state.consents.length, 1)
	assert.equal(state.consents[0].id, 30)
})

test('loading failure remains visible without OC.Notification', async () => {
	const { state } = applications({ fetch: async () => ({ ok: false, status: 500 }) })
	await state.loadConsents()
	assert.equal(state.notification.type, 'error')
	assert.equal(state.loading, false)
})

test('a pending revocation prevents a second concurrent request', async () => {
	let requests = 0
	const { state } = applications({ fetch: () => { requests++ } })
	state.revoking = 1
	await state.revokeAccess(2)
	assert.equal(requests, 0)
	assert.equal(state.consents.length, 2)
})

test('revocation uses the current supported request token', async () => {
	let headers
	const { state } = applications({
		getRequestToken: () => 'current-synthetic-csrf',
		fetch: async (_url, options) => { headers = options.headers; return { ok: true } },
	})
	await state.revokeAccess(1)
	assert.equal(headers.requesttoken, 'current-synthetic-csrf')
})

test('empty client scope limit allows previously requested scopes for editing', () => {
	const { state } = applications()
	const scopes = state.getRequestedScopes({ scopesGranted: 'openid', scopesRequested: 'openid Files:Read', allowedScopes: '' })
	assert.deepEqual([...scopes], ['openid', 'Files:Read'])
})

test('a client scope limit is case-sensitive and restricts the permission editor', () => {
	const { state } = applications()
	const scopes = state.getRequestedScopes({ scopesRequested: 'openid Files:Read files:read', allowedScopes: 'openid Files:Read' })
	assert.deepEqual([...scopes], ['openid', 'Files:Read'])
})

test('permission changes send selected scopes and update the displayed expiration', async () => {
	let request
	const { state } = applications({ fetch: async (url, options) => {
		request = { url, options }
		return { ok: true, json: async () => ({ scopesGranted: 'openid', updatedAt: 1000, expiresAt: 7777000 }) }
	} })
	state.selectedScopes = ['openid']
	state.editingClient = '1'
	await state.saveScopes(state.consents[0])
	assert.equal(request.url, '/apps/oidc/api/consents/1/scopes')
	assert.equal(request.options.method, 'PATCH')
	assert.deepEqual(JSON.parse(request.options.body), { scopes: ['openid'] })
	assert.equal(state.consents[0].expiresAt, 7777000)
	assert.equal(state.editingClient, null)
	assert.equal(state.notification.type, 'success')
})

test('failed permission changes keep the previous consent and the editor open', async () => {
	const { state } = applications({ fetch: async () => ({ ok: false }) })
	state.consents[0].scopesGranted = 'openid Files:Read'
	state.editingClient = '1'
	await state.saveScopes(state.consents[0])
	assert.equal(state.consents[0].scopesGranted, 'openid Files:Read')
	assert.equal(state.editingClient, '1')
	assert.equal(state.notification.type, 'error')
	assert.equal(state.savingScopes, false)
})
