import './bootstrap';
import '../css/app.css';

import {createApp, h} from 'vue';
import {createInertiaApp} from '@inertiajs/vue3';
import {resolvePageComponent} from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import VueCookies from 'vue-cookies'
import VueAxios from "vue-axios";
// import { installViewTransitions } from './viewTransitions'; // see the note at the bottom
import { mediaHeroDirective } from './composables/useMediaHero';

// Read the shared branding off the initial Inertia payload so the tab title and
// progress bar follow whatever this installation is branded as, rather than a
// name baked in at build time.
const initialPage = JSON.parse(document.getElementById('app')?.dataset.page || '{}');
const appName = initialPage?.props?.branding?.siteName || import.meta.env.VITE_APP_NAME || 'Streaming';
const progressColor = getComputedStyle(document.documentElement)
    .getPropertyValue('--color-primary-400')
    .trim() || '#0f766e';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({el, App, props, plugin}) {
        return createApp({render: () => h(App, props)})
            .use(plugin)
            .use(ZiggyVue, Ziggy)
            .use(VueCookies, {})
            .use(VueAxios, axios)
            .directive('media-hero', mediaHeroDirective)
            .provide('axios', {
                get: axios.get,
                post: axios.post,
                put: axios.put,
                delete: axios.delete,
            })
            .mount(el);
    },
    progress: {
        color: progressColor,
    },
});

// Page transitions are off.
//
// `installViewTransitions()` starts a view transition on Inertia's `before` event
// and holds it open until `finish`, i.e. across the whole network request. While
// one is pending the browser suppresses painting, so on a real connection the page
// visibly freezes for the length of the round trip and then repaints all at once.
// Users reported it as "pressing anything just reloads the page in place", which is
// exactly what a frozen document followed by a whole-page cross-fade looks like.
//
// It was invisible in development because the response is already there: the freeze
// is only as long as the request, and locally that is a few milliseconds.
//
// Deliberately left installed-but-uncalled rather than deleted. The effect is worth
// having; what it needs is to start when the DOM is about to swap rather than when
// the request is sent, so that painting is only suppressed for the swap itself.
// Inertia exposes no pre-swap hook, so that is a real piece of work and not a
// hotfix. See resources/js/viewTransitions.js.
//
// installViewTransitions();
