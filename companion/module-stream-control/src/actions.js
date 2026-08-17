export function UpdateActions(self) {
	self.setActionDefinitions({
		start: {
			name: 'Start show',
			description:
				'Starts the show whose slot is running now, or the next scheduled show if no slot has begun. Does nothing while a show is already live.',
			options: [],
			callback: async () => {
				await self.request('POST', '/start')
			},
		},
		stop: {
			name: 'Stop show',
			description: 'Ends whatever is live on this source. Does nothing when nothing is live.',
			options: [],
			callback: async () => {
				await self.request('POST', '/stop')
			},
		},
		refresh: {
			name: 'Refresh status',
			description: 'Polls the status endpoint immediately instead of waiting for the next interval.',
			options: [],
			callback: async () => {
				await self.request('GET', '/status')
			},
		},
	})
}
