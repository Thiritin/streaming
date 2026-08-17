import { InstanceBase, InstanceStatus } from '@companion-module/base'
import { GetConfigFields } from './config.js'
import { UpdateActions } from './actions.js'
import { UpdateFeedbacks } from './feedbacks.js'
import { UpdatePresets } from './presets.js'
import { UpdateVariableDefinitions, variableValuesFrom } from './variables.js'
import { UpgradeScripts } from './upgrades.js'

export { UpgradeScripts }

// The surface is a status display as much as a controller, so it polls rather than waiting
// for a press to find out what is on air.
const DEFAULT_POLL_SECONDS = 3
const REQUEST_TIMEOUT_MS = 5000

export default class StreamControlInstance extends InstanceBase {
	async init(config, _isFirstInit, secrets) {
		this.config = config
		this.secrets = secrets ?? {}
		this.state = null

		this.updateActions()
		this.updateFeedbacks()
		this.updatePresets()
		this.updateVariableDefinitions()

		this.startPolling()
	}

	async destroy() {
		this.stopPolling()
	}

	async configUpdated(config, secrets) {
		this.config = config
		this.secrets = secrets ?? {}

		this.startPolling()
	}

	getConfigFields() {
		return GetConfigFields()
	}

	updateActions() {
		UpdateActions(this)
	}

	updateFeedbacks() {
		UpdateFeedbacks(this)
	}

	updatePresets() {
		UpdatePresets(this)
	}

	updateVariableDefinitions() {
		UpdateVariableDefinitions(this)
	}

	startPolling() {
		this.stopPolling()

		if (!this.config?.baseUrl || !this.secrets?.token) {
			this.updateStatus(InstanceStatus.BadConfig, 'Set the base URL and the control key')
			return
		}

		this.updateStatus(InstanceStatus.Connecting)
		void this.poll()

		const seconds = Number(this.config.pollInterval) || DEFAULT_POLL_SECONDS
		this.pollTimer = setInterval(() => void this.poll(), seconds * 1000)
	}

	stopPolling() {
		if (this.pollTimer) {
			clearInterval(this.pollTimer)
			this.pollTimer = undefined
		}
	}

	async poll() {
		// An app that has gone slow must not accumulate polls: with a 1s interval and a
		// request that takes 5s to time out, the unguarded version has five in flight.
		if (this.polling) {
			return
		}

		this.polling = true

		try {
			await this.request('GET', '/status')
		} catch (error) {
			// The poll is the connection check, so a failed one is what marks the module down.
			this.log('debug', `Status poll failed: ${error.message}`)
		} finally {
			this.polling = false
		}
	}

	/**
	 * Every endpoint answers with the same status block, so one code path both performs the
	 * action and refreshes the buttons: a press updates the surface without waiting for the
	 * next poll.
	 */
	async request(method, path) {
		const base = String(this.config.baseUrl).replace(/\/+$/, '')

		let response
		try {
			response = await fetch(`${base}${path}`, {
				method,
				headers: {
					'X-Companion-Token': this.secrets.token,
					Accept: 'application/json',
				},
				signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
			})
		} catch (error) {
			this.updateStatus(InstanceStatus.ConnectionFailure, error.message)
			throw error
		}

		if (response.status === 401) {
			this.updateStatus(InstanceStatus.AuthenticationFailure, 'The control key was rejected')
			throw new Error('Invalid control key')
		}

		// The stream name is the last part of the base URL, so a 404 is a configuration
		// mistake rather than a server problem, and saying so saves an operator from
		// hunting a network fault that is not there.
		if (response.status === 404) {
			this.updateStatus(InstanceStatus.BadConfig, 'No source with that stream name - check the API base URL')
			throw new Error('Unknown stream name')
		}

		let body
		try {
			body = await response.json()
		} catch {
			this.updateStatus(InstanceStatus.UnknownError, `Unexpected reply (HTTP ${response.status})`)
			throw new Error(`Unexpected reply (HTTP ${response.status})`)
		}

		// 409 means "nothing queued to start": a real answer about the schedule rather than a
		// broken connection, so the module stays green and the message goes to the log.
		if (!response.ok && response.status !== 409) {
			this.updateStatus(InstanceStatus.UnknownError, `HTTP ${response.status}`)
			throw new Error(`HTTP ${response.status}`)
		}

		this.applyState(body)

		if (body.ok === false) {
			this.log('warn', body.message ?? 'The request was refused')
		}

		return body
	}

	applyState(state) {
		this.state = state

		this.updateStatus(InstanceStatus.Ok)
		this.setVariableValues(variableValuesFrom(state))
		this.checkFeedbacks('live', 'has_next', 'source_online')
	}
}
