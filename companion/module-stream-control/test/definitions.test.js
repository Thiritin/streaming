import { test } from 'node:test'
import assert from 'node:assert/strict'
import { UpdateActions } from '../src/actions.js'
import { UpdateFeedbacks } from '../src/feedbacks.js'
import { UpdatePresets } from '../src/presets.js'
import { UpdateVariableDefinitions } from '../src/variables.js'
import { GetConfigFields } from '../src/config.js'

/**
 * Stands in for the module instance. Companion's own InstanceBase needs a host process
 * behind it, and none of this code depends on one: the definitions are plain data and the
 * callbacks only touch `state` and `request`.
 */
function fakeSelf(state = null) {
	return {
		state,
		calls: [],
		actions: {},
		feedbacks: {},
		variables: {},
		presets: null,
		structure: null,
		setActionDefinitions(defs) {
			this.actions = defs
		},
		setFeedbackDefinitions(defs) {
			this.feedbacks = defs
		},
		setVariableDefinitions(defs) {
			this.variables = defs
		},
		setPresetDefinitions(structure, presets) {
			this.structure = structure
			this.presets = presets
		},
		async request(method, path) {
			this.calls.push(`${method} ${path}`)
			return {}
		},
	}
}

test('the actions hit the endpoints they claim to', async () => {
	const self = fakeSelf()
	UpdateActions(self)

	assert.deepEqual(Object.keys(self.actions).sort(), ['refresh', 'start', 'stop'])

	await self.actions.start.callback({})
	await self.actions.stop.callback({})
	await self.actions.refresh.callback({})

	assert.deepEqual(self.calls, ['POST /start', 'POST /stop', 'GET /status'])
})

test('the feedbacks read the last status reply', () => {
	const self = fakeSelf({
		live: true,
		next_show: { title: 'Game Show Hour' },
		source: { status: 'online' },
	})
	UpdateFeedbacks(self)

	assert.equal(self.feedbacks.live.callback({}), true)
	assert.equal(self.feedbacks.has_next.callback({}), true)
	assert.equal(self.feedbacks.source_online.callback({}), true)
})

test('the feedbacks are false before the first reply arrives', () => {
	const self = fakeSelf(null)
	UpdateFeedbacks(self)

	assert.equal(self.feedbacks.live.callback({}), false)
	assert.equal(self.feedbacks.has_next.callback({}), false)
	assert.equal(self.feedbacks.source_online.callback({}), false)
})

test('the feedbacks are false while the source is idle and empty', () => {
	const self = fakeSelf({ live: false, next_show: null, source: { status: 'offline' } })
	UpdateFeedbacks(self)

	assert.equal(self.feedbacks.live.callback({}), false)
	assert.equal(self.feedbacks.has_next.callback({}), false)
	assert.equal(self.feedbacks.source_online.callback({}), false)
})

// A preset naming an action or feedback that does not exist produces a button that looks
// right and does nothing, which is exactly the failure nobody notices until showtime.
test('every preset references defined actions, feedbacks and variables', () => {
	const self = fakeSelf()
	UpdateActions(self)
	UpdateFeedbacks(self)
	UpdateVariableDefinitions(self)
	UpdatePresets(self)

	const variableIds = Object.keys(self.variables)

	for (const [id, preset] of Object.entries(self.presets)) {
		assert.equal(preset.type, 'simple', `${id} must be a simple preset`)

		for (const step of preset.steps) {
			for (const action of [...step.down, ...step.up]) {
				assert.ok(self.actions[action.actionId], `${id} references unknown action ${action.actionId}`)
			}
		}

		for (const feedback of preset.feedbacks) {
			assert.ok(self.feedbacks[feedback.feedbackId], `${id} references unknown feedback ${feedback.feedbackId}`)
		}

		for (const [, name] of preset.style.text.matchAll(/\$\(stream-control:([a-z_]+)\)/g)) {
			assert.ok(variableIds.includes(name), `${id} references unknown variable ${name}`)
		}
	}
})

test('the preset structure lists presets that exist', () => {
	const self = fakeSelf()
	UpdatePresets(self)

	const referenced = self.structure.flatMap((section) => section.definitions.flatMap((group) => group.presets))

	assert.deepEqual(referenced.sort(), Object.keys(self.presets).sort())
})

test('the config asks for a base URL, a secret token and a poll interval', () => {
	const fields = GetConfigFields()
	const byId = Object.fromEntries(fields.map((field) => [field.id, field]))

	assert.equal(byId.baseUrl.type, 'textinput')
	// Not a plain textinput: the token controls what goes on air, so Companion keeps it in
	// its secret store rather than in the config it displays.
	assert.equal(byId.token.type, 'secret-text')
	assert.equal(byId.pollInterval.type, 'number')
})
