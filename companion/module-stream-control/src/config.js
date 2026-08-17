export function GetConfigFields() {
	return [
		{
			type: 'static-text',
			id: 'info',
			width: 12,
			label: 'Stream Control',
			value:
				'The API base URL is on the source page in the admin panel: Sources, then the source, then Control surface. It ends in the stream name, and that is what picks the source this connection drives. The control key is the same for every source.',
		},
		{
			type: 'textinput',
			id: 'baseUrl',
			label: 'API base URL',
			width: 12,
			default: 'http://streaming.test/api/companion/main-stage',
		},
		{
			// The key controls what goes on air, so Companion keeps it in its secret store
			// rather than in the config it shows in the web UI.
			type: 'secret-text',
			id: 'token',
			label: 'Control key',
			width: 12,
		},
		{
			type: 'number',
			id: 'pollInterval',
			label: 'Status poll interval (seconds)',
			width: 4,
			min: 1,
			max: 60,
			default: 3,
		},
	]
}
