import type { RouterHistory } from 'vue-router';

export type HistoryOp = { type: 'push' } | { type: 'replace' } | { type: 'pop'; delta: number };

export interface TrackedHistory extends RouterHistory {
    /** What the last history change was, before the router's guards and hooks see it. */
    peekOp(): HistoryOp | undefined;
    /** The same, cleared, so the next navigation starts fresh. */
    takeOp(): HistoryOp | undefined;
}

/**
 * Wraps the router's history so navigation.ts can tell a push from a replace and
 * a browser back or forward (PWA-025). The router itself does not say which.
 */
export function trackHistory(history: RouterHistory): TrackedHistory {
    let op: HistoryOp | undefined;

    return {
        get base() {
            return history.base;
        },
        get location() {
            return history.location;
        },
        get state() {
            return history.state;
        },
        createHref: (location) => history.createHref(location),
        push(to, data) {
            op = { type: 'push' };
            history.push(to, data);
        },
        replace(to, data) {
            op = { type: 'replace' };
            history.replace(to, data);
        },
        go: (delta, triggerListeners) => history.go(delta, triggerListeners),
        listen: (callback) =>
            history.listen((to, from, info) => {
                op = { type: 'pop', delta: info.delta };
                callback(to, from, info);
            }),
        destroy: () => history.destroy(),
        peekOp: () => op,
        takeOp() {
            const taken = op;
            op = undefined;
            return taken;
        },
    };
}
