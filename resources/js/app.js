import './bootstrap';
import '../css/app.css';

import {createApp, h} from 'vue';
import {createInertiaApp} from '@inertiajs/vue3';
import {resolvePageComponent} from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import VueCookies from 'vue-cookies'
import VueAxios from "vue-axios";
import { installViewTransitions } from './viewTransitions';
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

// Registered after the app so the router exists. A no-op in a browser without the
// View Transitions API, and for anyone who has asked for reduced motion;
// navigation falls back to the plain Inertia swap.
installViewTransitions();
