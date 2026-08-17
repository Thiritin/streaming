import { combineRgb } from '@companion-module/base'

const WHITE = combineRgb(255, 255, 255)
const BLACK = combineRgb(0, 0, 0)
const RED = combineRgb(200, 0, 0)

export function UpdatePresets(self) {
	const structure = [
		{
			id: 'transport',
			name: 'Stream control',
			definitions: [
				{
					id: 'buttons',
					type: 'simple',
					name: 'Buttons',
					description: 'Start and stop the show on this source, plus two status displays.',
					presets: ['start', 'stop', 'status', 'next'],
				},
			],
		},
	]

	const presets = {
		start: {
			type: 'simple',
			name: 'Start (next show)',
			style: {
				text: 'START\n$(stream-control:next_title_short)',
				size: 'auto',
				color: WHITE,
				bgcolor: combineRgb(0, 60, 0),
			},
			steps: [{ down: [{ actionId: 'start', options: {} }], up: [] }],
			// Red while live: the button has nothing left to do, and the surface should read as
			// "on air" from across the room.
			feedbacks: [{ feedbackId: 'live', options: {}, style: { bgcolor: RED, color: WHITE } }],
		},
		stop: {
			type: 'simple',
			name: 'Stop',
			style: {
				text: 'STOP',
				size: '18',
				color: WHITE,
				bgcolor: combineRgb(60, 0, 0),
			},
			steps: [{ down: [{ actionId: 'stop', options: {} }], up: [] }],
			feedbacks: [{ feedbackId: 'live', options: {}, style: { bgcolor: RED, color: WHITE } }],
		},
		status: {
			type: 'simple',
			name: 'Status display',
			style: {
				text: '$(stream-control:source_name)\n$(stream-control:live_title_short)\n$(stream-control:live_elapsed)',
				size: 'auto',
				color: WHITE,
				bgcolor: BLACK,
			},
			steps: [{ down: [{ actionId: 'refresh', options: {} }], up: [] }],
			feedbacks: [
				{ feedbackId: 'live', options: {}, style: { bgcolor: combineRgb(120, 0, 0), color: WHITE } },
			],
		},
		next: {
			type: 'simple',
			name: 'Next up',
			style: {
				text: 'NEXT\n$(stream-control:next_title_short)\n$(stream-control:next_start)',
				size: 'auto',
				color: WHITE,
				bgcolor: BLACK,
			},
			steps: [{ down: [{ actionId: 'refresh', options: {} }], up: [] }],
			feedbacks: [
				{ feedbackId: 'has_next', options: {}, style: { bgcolor: combineRgb(0, 40, 60), color: WHITE } },
			],
		},
	}

	self.setPresetDefinitions(structure, presets)
}
