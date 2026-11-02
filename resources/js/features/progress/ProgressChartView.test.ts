import { mount } from '@vue/test-utils';
import { afterAll, describe, expect, it, vi } from 'vitest';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import content from '../../../../tests/fixtures/screens/progress_show.json';
import ProgressChartView from './ProgressChartView.vue';

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

describe('ProgressChartView', () => {
    it('ANA-001 labels the time axis with the server dates, day then short month, in any time zone', () => {
        expect(new Date('2026-08-31').getDate()).toBe(30);

        const texts = mount(ProgressChartView, { props: { chart } })
            .findAll('text')
            .map((t) => t.text());

        expect(texts).toEqual(expect.arrayContaining(['31 Aug', '14 Sep', '28 Sep']));
        expect(texts.some((t) => t.includes('Sept') || t === '30 Aug')).toBe(false);
    });
});
