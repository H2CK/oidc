// SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const assert = require('node:assert/strict')
const { readFileSync } = require('node:fs')
const path = require('node:path')
const test = require('node:test')
const vm = require('node:vm')

const source = readFileSync(path.join(__dirname, '../../src/DeviceAuthorization.vue'), 'utf8')
function device(post = async () => {}) {
	const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
	const sandbox = {
		module: { exports: {} },
		axios: { post },
		t: (_app, message) => message,
		generateUrl: value => value,
		defineProps: () => ({ mode: 'enter', userCode: 'ABCD-2345', scope: 'openid', message: '' }),
		ref: value => ({ value }),
		computed: getter => ({ get value() { return getter() } }),
		nextTick: callback => callback(),
		URLSearchParams,
		window: { location: { href: '' } },
	}
	vm.runInNewContext(script + '\nmodule.exports = { extractUserCode, onCodeInput, respond, busy, currentMessage, currentMode, enteredCode, verifyCode };', sandbox)
	return { ...sandbox.module.exports, sandbox }
}

test('a complete pasted verification URL reaches the input handler without truncation', () => {
	assert.doesNotMatch(source.match(/<input[\s\S]*?@input="onCodeInput">/)[0], /maxlength=/)
	const view = device()
	let caret
	const value = 'https://cloud.example/apps/oidc/device?user_code=abcd%2D2345&other=value'
	const element = { value, selectionStart: value.length, setSelectionRange: position => { caret = position } }
	view.onCodeInput({ target: element })
	assert.equal(element.value, 'ABCD-2345')
	assert.equal(caret, 9)
	view.verifyCode()
	assert.equal(view.sandbox.window.location.href, '/apps/oidc/device?user_code=ABCD-2345')
})

test('invalid characters are removed without inventing a complete code', () => {
	assert.equal(device().extractUserCode('i o 0 1 ab-cd'), 'ABCD')
})

for (const [status, expected] of [
	[403, 'not allowed'],
	[401, 'login has expired'],
	[409, 'already completed'],
	[400, 'invalid or has expired'],
	[429, 'Too many attempts'],
	[500, 'could not be completed'],
]) {
	test(`device approval explains HTTP ${status} and releases the busy state`, async () => {
		const view = device(async () => { throw { response: { status, data: {} } } })
		await view.respond('approve')
		assert.equal(view.currentMode.value, 'error')
		assert.ok(view.currentMessage.value.includes(expected))
		assert.equal(view.busy.value, false)
	})
}

test('double approval submits exactly one device request', async () => {
	let complete
	let calls = 0
	const view = device(() => { calls++; return new Promise(resolve => { complete = resolve }) })
	const first = view.respond('approve')
	await view.respond('approve')
	assert.equal(calls, 1)
	complete()
	await first
	assert.equal(view.currentMode.value, 'complete')
})
