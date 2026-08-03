/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time web applications.
 */

import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Emitted by app.blade.php from the server's broadcasting config. Taking the
// host at runtime keeps a deployment's own domain out of the built bundle, so
// one image serves any installation. The VITE_* values stay as the fallback for
// `npm run dev`, where there is no rendered page yet.
const broadcasting = window.__broadcasting ?? {};

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: broadcasting.key || import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: broadcasting.host || import.meta.env.VITE_REVERB_HOST,
    wsPort: broadcasting.port ?? import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: broadcasting.port ?? import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (broadcasting.scheme || import.meta.env.VITE_REVERB_SCHEME || 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
