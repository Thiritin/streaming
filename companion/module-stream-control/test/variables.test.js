import { test } from 'node:test'
import assert from 'node:assert/strict'
import { variableValuesFrom } from '../src/variables.js'

const idle = {
	source: { name: 'Main Stage', slug: 'main-stage', status: 'online' },
	live: false,
	live_show: null,
	next_show: {
		title: 'Panel: The Art of Fursuit Construction, Part Two',
		title_short: 'Panel: The Art of Fursuit…',
		scheduled_start: '2026-08-17T17:00:00+02:00',
		scheduled_start_clock: '17:00',
	},
	next_action: 'start_next',
	viewer_count: 0,
	server_time: '2026-08-17T15:30:00+02:00',
}

const live = {
	source: { name: 'Main Stage', slug: 'main-stage', status: 'online' },
	live: true,
	live_show: {
		title: 'Fursuit Parade',
		title_short: 'Fursuit Parade',
		actual_start: '2026-08-17T14:03:20+02:00',
		actual_start_clock: '14:03',
	},
	next_show: {
		title: 'Game Show Hour',
		title_short: 'Game Show Hour',
		scheduled_start: '2026-08-17T17:00:00+02:00',
		scheduled_start_clock: '17:00',
	},
	next_action: 'none',
	viewer_count: 412,
	server_time: '2026-08-17T15:04:25+02:00',
}

test('an idle source reports the show Start would start', () => {
	const values = variableValuesFrom(idle)

	assert.equal(values.source_name, 'Main Stage')
	assert.equal(values.live, 'no')
	assert.equal(values.live_title, '')
	assert.equal(values.next_title, 'Panel: The Art of Fursuit Construction, Part Two')
	// The buttons print the short form; the full title stays available for anything wider.
	assert.equal(values.next_title_short, 'Panel: The Art of Fursuit…')
	assert.equal(values.next_start, '17:00')
	assert.equal(values.next_in, '1:30:00')
	assert.equal(values.next_action, 'start_next')
})

test('a live source reports the show on air and how long it has run', () => {
	const values = variableValuesFrom(live)

	assert.equal(values.live, 'yes')
	assert.equal(values.live_title, 'Fursuit Parade')
	assert.equal(values.live_title_short, 'Fursuit Parade')
	assert.equal(values.live_since, '14:03')
	assert.equal(values.live_elapsed, '1:01:05')
	assert.equal(values.viewers, 412)
	assert.equal(values.next_action, 'none')
})

// The surface may be a box in a rack running UTC. Clock strings come preformatted from the
// server so it never renders the schedule in its own timezone.
test('clock times are taken from the server, not reformatted locally', () => {
	const previous = process.env.TZ
	process.env.TZ = 'UTC'

	try {
		assert.equal(variableValuesFrom(idle).next_start, '17:00')
		assert.equal(variableValuesFrom(live).live_since, '14:03')
	} finally {
		process.env.TZ = previous
	}
})

test('a countdown to a slot that already began is blank rather than negative', () => {
	const values = variableValuesFrom({
		...idle,
		server_time: '2026-08-17T17:20:00+02:00',
	})

	assert.equal(values.next_in, '')
})

test('an empty schedule leaves every field blank rather than undefined', () => {
	const values = variableValuesFrom({
		source: { name: 'Main Stage', status: 'offline' },
		live: false,
		live_show: null,
		next_show: null,
		next_action: 'none',
		viewer_count: 0,
		server_time: '2026-08-17T15:30:00+02:00',
	})

	for (const value of Object.values(values)) {
		assert.notEqual(value, undefined)
	}
	assert.equal(values.next_title, '')
	assert.equal(values.next_title_short, '')
	assert.equal(values.live_elapsed, '')
})
