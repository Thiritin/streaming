import { test } from 'node:test'
import assert from 'node:assert/strict'
import { InstanceStatus } from '@companion-module/base'
import StreamControlInstance from '../src/main.js'

/**
 * The request path is exercised against a fake `this` rather than a real instance:
 * constructing InstanceBase needs Companion's host process, and none of this code touches
 * it beyond the four methods stubbed here.
 */
function harness(fetchImpl) {
	const calls = { status: [], variables: [], feedbacks: [], logs: [] }

	const self = {
		config: { baseUrl: 'http://app.test/api/companion/main-stage/', pollInterval: 3 },
		secrets: { token: 'a-token' },
		state: null,
		log: (level, message) => calls.logs.push(`${level}: ${message}`),
		updateStatus: (status, message) => calls.status.push([status, message]),
		setVariableValues: (values) => calls.variables.push(values),
		checkFeedbacks: (...ids) => calls.feedbacks.push(ids),
		applyState: StreamControlInstance.prototype.applyState,
		request: StreamControlInstance.prototype.request,
	}

	globalThis.fetch = fetchImpl

	return { self, calls }
}

function reply(status, body) {
	return {
		status,
		ok: status >= 200 && status < 300,
		json: async () => body,
	}
}

const okBody = {
	ok: true,
	action: 'started_next',
	message: "'Opening Ceremony' is now live.",
	source: { name: 'Main Stage', status: 'online' },
	live: true,
	live_show: { title: 'Opening Ceremony', title_short: 'Opening Ceremony', actual_start_clock: '17:00' },
	next_show: null,
	next_action: 'none',
	viewer_count: 3,
	server_time: '2026-08-17T17:00:10+02:00',
}

test('a successful reply updates status, variables and feedbacks', async () => {
	let seen
	const { self, calls } = harness(async (url, options) => {
		seen = { url, options }
		return reply(200, okBody)
	})

	const body = await self.request.call(self, 'POST', '/start')

	// The trailing slash on the configured base URL must not produce a double slash.
	assert.equal(seen.url, 'http://app.test/api/companion/main-stage/start')
	assert.equal(seen.options.method, 'POST')
	assert.equal(seen.options.headers['X-Companion-Token'], 'a-token')
	assert.equal(body.action, 'started_next')
	assert.deepEqual(calls.status.at(-1), [InstanceStatus.Ok, undefined])
	assert.equal(calls.variables.at(-1).live_title, 'Opening Ceremony')
	assert.deepEqual(calls.feedbacks.at(-1), ['live', 'has_next', 'source_online'])
})

// Nothing queued is an answer about the schedule, not a broken connection: the module has
// to stay green so the operator sees the message rather than a red connection.
test('a 409 still updates the surface and keeps the connection healthy', async () => {
	const { self, calls } = harness(async () =>
		reply(409, {
			...okBody,
			ok: false,
			action: 'none',
			message: 'No scheduled show is queued on this source.',
			live: false,
			live_show: null,
		}),
	)

	await self.request.call(self, 'POST', '/start')

	assert.deepEqual(calls.status.at(-1), [InstanceStatus.Ok, undefined])
	assert.equal(calls.variables.at(-1).live, 'no')
	assert.match(calls.logs.at(-1), /No scheduled show is queued/)
})

test('a rejected key reports an authentication failure', async () => {
	const { self, calls } = harness(async () => reply(401, { error: 'Unauthorized' }))

	await assert.rejects(() => self.request.call(self, 'GET', '/status'), /Invalid control key/)
	assert.equal(calls.status.at(-1)[0], InstanceStatus.AuthenticationFailure)
})

test('an unreachable server reports a connection failure', async () => {
	const { self, calls } = harness(async () => {
		throw new Error('fetch failed')
	})

	await assert.rejects(() => self.request.call(self, 'GET', '/status'), /fetch failed/)
	assert.equal(calls.status.at(-1)[0], InstanceStatus.ConnectionFailure)
})

test('a non-JSON reply is reported rather than swallowed', async () => {
	const { self, calls } = harness(async () => ({
		status: 502,
		ok: false,
		json: async () => {
			throw new Error('not json')
		},
	}))

	await assert.rejects(() => self.request.call(self, 'GET', '/status'), /Unexpected reply \(HTTP 502\)/)
	assert.equal(calls.status.at(-1)[0], InstanceStatus.UnknownError)
})

test('a server error leaves the connection marked as failing', async () => {
	const { self, calls } = harness(async () => reply(500, { error: 'Server Error' }))

	await assert.rejects(() => self.request.call(self, 'GET', '/status'), /HTTP 500/)
	assert.deepEqual(calls.status.at(-1), [InstanceStatus.UnknownError, 'HTTP 500'])
})
