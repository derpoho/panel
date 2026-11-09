import { onBeforeUnmount, onMounted } from 'vue';

/**
 * For a page's polls: `whenVisible(fn)` runs fn now if the tab is visible,
 * or else as soon as it's shown again. A background tab no one is looking
 * at doesn't reload its page every few seconds (each reload is a request,
 * and a re-render of everything it brings back), and it catches up the
 * moment it's looked at. While hidden only the latest call waits, so a
 * poll that keeps ticking still reloads just once on return.
 */
export function useWhenVisible(): (fn: () => void) => void {
    let waiting: (() => void) | null = null;

    function onChange(): void {
        if (document.visibilityState !== 'visible' || waiting === null) return;
        const fn = waiting;
        waiting = null;
        fn();
    }

    onMounted(() => document.addEventListener('visibilitychange', onChange));
    onBeforeUnmount(() => {
        waiting = null;
        document.removeEventListener('visibilitychange', onChange);
    });

    return (fn) => {
        if (document.visibilityState === 'visible') {
            fn();
        } else {
            waiting = fn;
        }
    };
}
