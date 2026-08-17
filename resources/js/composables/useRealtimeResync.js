import { onMounted, onUnmounted } from 'vue';

/**
 * Events broadcast while the websocket is down are gone for good: Echo resubscribes
 * on reconnect but nothing replays what was missed, so a viewer whose socket dropped
 * keeps rendering the state it had when the connection died until a hard reload.
 * Pull fresh props back whenever the connection returns or the tab becomes visible
 * again (mobile suspends sockets without telling the page).
 */
export function useRealtimeResync(resync) {
    const connection = () => window.Echo?.connector?.pusher?.connection;

    const onStateChange = ({ current, previous }) => {
        if (current === 'connected' && previous && previous !== 'initialized') {
            resync();
        }
    };

    const onVisibility = () => {
        if (document.visibilityState === 'visible') {
            resync();
        }
    };

    onMounted(() => {
        connection()?.bind('state_change', onStateChange);
        document.addEventListener('visibilitychange', onVisibility);
    });

    onUnmounted(() => {
        connection()?.unbind('state_change', onStateChange);
        document.removeEventListener('visibilitychange', onVisibility);
    });
}
