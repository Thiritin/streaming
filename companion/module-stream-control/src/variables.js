const DEFINITIONS = {
	source_name: { name: 'Source name' },
	source_status: { name: 'Source status' },
	viewers: { name: 'Viewers on this source' },
	live: { name: 'Live (yes/no)' },
	live_title: { name: 'Title of the live show' },
	live_title_short: { name: 'Title of the live show, cut to fit a button' },
	live_since: { name: 'Clock time the live show started' },
	live_elapsed: { name: 'How long the live show has been on air (h:mm:ss)' },
	next_title: { name: 'Title of the show Start would start' },
	next_title_short: { name: 'Title of that show, cut to fit a button' },
	next_start: { name: 'Clock time that show is scheduled for' },
	next_in: { name: 'Time until that show is scheduled (h:mm:ss, empty once its slot is running)' },
	next_action: { name: 'What Start would do: start_current, start_next or none' },
	last_message: { name: 'The last reply from the server' },
}

function duration(fromIso, toIso) {
	if (!fromIso || !toIso) {
		return ''
	}

	const seconds = Math.floor((new Date(toIso).getTime() - new Date(fromIso).getTime()) / 1000)
	if (seconds < 0) {
		return ''
	}

	const hours = Math.floor(seconds / 3600)
	const minutes = Math.floor((seconds % 3600) / 60)
	const remainder = seconds % 60

	return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`
}

/**
 * Every time here comes from the server: clock times arrive preformatted in the event's
 * timezone, and elapsed/countdown are measured against the server clock in the same reply.
 * A Companion box on UTC, or with a drifting clock, still shows the schedule the system is
 * working to.
 */
export function variableValuesFrom(state) {
	const live = state.live_show
	const next = state.next_show

	return {
		source_name: state.source?.name ?? '',
		source_status: state.source?.status ?? '',
		viewers: state.viewer_count ?? 0,
		live: state.live ? 'yes' : 'no',
		live_title: live?.title ?? '',
		live_title_short: live?.title_short ?? '',
		live_since: live?.actual_start_clock ?? '',
		live_elapsed: duration(live?.actual_start, state.server_time),
		next_title: next?.title ?? '',
		next_title_short: next?.title_short ?? '',
		next_start: next?.scheduled_start_clock ?? '',
		next_in: duration(state.server_time, next?.scheduled_start),
		next_action: state.next_action ?? 'none',
		last_message: state.message ?? '',
	}
}

export function UpdateVariableDefinitions(self) {
	self.setVariableDefinitions(DEFINITIONS)
}
