import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterAll, afterEach, describe, expect, it, vi } from 'vitest';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import content from '../../../../tests/fixtures/screens/progress_show.json';
import ProgressChartView from './ProgressChartView.vue';
import ProgressShareCard from './ProgressShareCard.vue';

// West of UTC, a date read at local midnight would show the day before.
const zone = vi.hoisted(() => {
    const before = process.env.TZ;
    process.env.TZ = 'America/Los_Angeles';
    return before;
});

afterAll(() => {
    if (zone === undefined) delete process.env.TZ;
    else process.env.TZ = zone;
});

const chart = (content as ProgressShowOutput).chart!;

// jsdom lays nothing out, so the test plays the browser: it reports the width the chart is laid out at.
let laidOut: (width: number) => void = () => undefined;
class FakeResizeObserver {
    constructor(private readonly report: ResizeObserverCallback) {}
    observe(target: Element): void {
        laidOut = (width) => this.report([{ target, contentRect: { width } } as unknown as ResizeObserverEntry], this as unknown as ResizeObserver);
    }
    unobserve(): void {}
    disconnect(): void {}
}

afterEach(() => {
    vi.unstubAllGlobals();
    laidOut = () => undefined;
});

// CSS px per chart unit: the rendered width over the viewBox width.
function scale(svg: Element, renderedWidth: number): number {
    return renderedWidth / Number(svg.getAttribute('viewBox')!.split(' ')[2]);
}

describe('ProgressChartView', () => {
    it('ANA-001 labels the time axis with the server dates, day then short month, in any time zone', () => {
        expect(new Date('2026-08-31').getDate()).toBe(30);

        const texts = mount(ProgressChartView, { props: { chart } })
            .findAll('text')
            .map((t) => t.text());

        expect(texts).toEqual(expect.arrayContaining(['31 Aug', '14 Sep', '28 Sep']));
        expect(texts.some((t) => t.includes('Sept') || t === '30 Aug')).toBe(false);
    });

    it.each([358, 1000])('ANA-001 draws its axis and legend text at 11 CSS px when laid out %i px wide', async (width) => {
        vi.stubGlobal('ResizeObserver', FakeResizeObserver);
        const wrapper = mount(ProgressChartView, { props: { chart } });
        laidOut(width);
        await nextTick();

        const svg = wrapper.find('svg').element;
        const texts = wrapper.findAll('text');
        expect(texts.length).toBeGreaterThan(0);
        for (const text of texts) expect(Number(text.attributes('font-size')) * scale(svg, width)).toBe(11);
    });

    it('ANA-001 still runs its lines and points across the whole card when it is wide', async () => {
        vi.stubGlobal('ResizeObserver', FakeResizeObserver);
        const wrapper = mount(ProgressChartView, { props: { chart } });
        laidOut(1000);
        await nextTick();

        const xs = wrapper.findAll('[data-weight]').map((dot) => Number(dot.attributes('cx')) * scale(wrapper.find('svg').element, 1000));
        expect(Math.min(...xs)).toBeLessThan(20);
        expect(Math.max(...xs)).toBeGreaterThan(950);
    });

    it('ANA-010 draws the share picture at its own 340-wide size, as before', () => {
        const share = (content as ProgressShowOutput).share!;
        const wrapper = mount(ProgressShareCard, { props: { content: share, chart, summary: (content as ProgressShowOutput).summary } });

        const inner = wrapper.findAll('svg')[1]!;
        expect(inner.attributes('viewBox')).toBe('0 0 340 224');
        expect(inner.attributes('width')).toBe('340');
    });
});
