// Where the server's chart series land on the screen (ANA-001). This is drawing
// only: the server decides every value; these functions turn values into pixels.

const DAY_MS = 86_400_000;

export interface Point {
    x: number;
    y: number;
}

/** Days ("2026-08-31") along x from `left` to `right`, with a tick every whole number of weeks. */
export function timeAxis(dates: string[], left: number, right: number): { x: (date: string) => number; ticks: string[] } {
    const times = dates.map((date) => Date.parse(date));
    const first = Math.min(...times);
    const last = Math.max(...times);
    const span = last - first;
    const x = (date: string) => (span === 0 ? (left + right) / 2 : left + ((Date.parse(date) - first) / span) * (right - left));

    // Three or so ticks, as Swift Charts picks: two weeks apart over six weeks.
    const days = span / DAY_MS;
    const step = days >= 21 ? Math.ceil(days / 3 / 7) * 7 : Math.max(1, Math.ceil(days / 3));
    const ticks: string[] = [];
    for (let t = first; t <= last && dates.length > 0; t += step * DAY_MS) ticks.push(new Date(t).toISOString().slice(0, 10));

    return { x, ticks };
}

/**
 * Values along y from `bottom` to `top`, widened to whole numbers and then by `pad`
 * on each side, with a tick on each whole number.
 */
export function valueAxis(values: number[], bottom: number, top: number, pad = 0): { y: (value: number) => number; ticks: number[] } {
    const low = Math.floor(Math.min(...values)) - pad;
    let high = Math.ceil(Math.max(...values)) + pad;
    if (high === low) high = low + 1;

    const step = Math.max(1, Math.ceil((high - low) / 5));
    const ticks: number[] = [];
    for (let v = low; v <= high; v += step) ticks.push(v);

    return { y: (value: number) => bottom + ((value - low) / (high - low)) * (top - bottom), ticks };
}

/** One SVG path through the points, in order. */
export function linePath(points: Point[]): string {
    return points.map((p, i) => `${i === 0 ? 'M' : 'L'}${round(p.x)} ${round(p.y)}`).join('');
}

function round(n: number): number {
    return Math.round(n * 10) / 10;
}
