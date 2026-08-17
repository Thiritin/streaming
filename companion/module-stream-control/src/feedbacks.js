import { combineRgb } from '@companion-module/base'

export function UpdateFeedbacks(self) {
	self.setFeedbackDefinitions({
		live: {
			name: 'A show is live',
			type: 'boolean',
			defaultStyle: {
				bgcolor: combineRgb(200, 0, 0),
				color: combineRgb(255, 255, 255),
			},
			options: [],
			callback: () => Boolean(self.state?.live),
		},
		has_next: {
			name: 'A show is queued',
			type: 'boolean',
			defaultStyle: {
				bgcolor: combineRgb(0, 80, 0),
				color: combineRgb(255, 255, 255),
			},
			options: [],
			callback: () => Boolean(self.state?.next_show),
		},
		source_online: {
			name: 'The source is online',
			type: 'boolean',
			defaultStyle: {
				bgcolor: combineRgb(0, 0, 120),
				color: combineRgb(255, 255, 255),
			},
			options: [],
			callback: () => self.state?.source?.status === 'online',
		},
	})
}
