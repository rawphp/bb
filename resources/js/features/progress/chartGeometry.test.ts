import { describe, expect, it } from 'vitest';
import progress from '../../../../tests/fixtures/screens/progress_show.json';
import { linePath, timeAxis, valueAxis } from './chartGeometry';

describe('chartGeometry', () => {
    const dates = progress.chart.weights.map((p) => p.date);

    it('ANA-001 places the first day on the left edge and the last on the right', () => {
        const axis = timeAxis(dates, 10, 310);

        expect(axis.x('2026-08-31')).toBe(10);
        expect(axis.x('2026-10-10')).toBe(310);
        expect(axis.x('2026-09-20')).toBeCloseTo(160);
    });

    it('ANA-001 marks the time axis every two weeks from the first day, as rp-clone does', () => {
        expect(timeAxis(dates, 0, 100).ticks).toEqual(['2026-08-31', '2026-09-14', '2026-09-28']);
    });

    it('ANA-001 marks three weeks or more on whole weeks', () => {
        expect(timeAxis(['2026-08-31', '2026-09-30'], 0, 90).ticks).toEqual(['2026-08-31', '2026-09-14', '2026-09-28']);
    });

    it('ANA-001 marks a short span about three times, a whole number of days apart', () => {
        expect(timeAxis(['2026-08-31', '2026-09-09'], 0, 90).ticks).toEqual(['2026-08-31', '2026-09-03', '2026-09-06', '2026-09-09']);
    });

    it('ANA-001 keeps a wide value range to about five ticks', () => {
        expect(valueAxis([80, 95], 100, 0).ticks).toEqual([80, 83, 86, 89, 92, 95]);
    });

    it('ANA-001 spreads the value axis over whole numbers around the series', () => {
        const axis = valueAxis([83.26, 84.8, 83.2, 84.78], 200, 0);

        expect(axis.ticks).toEqual([83, 84, 85]);
        expect(axis.y(83)).toBe(200);
        expect(axis.y(85)).toBe(0);
        expect(axis.y(84)).toBe(100);
    });

    it('WT-021 pads a secondary scale, so the waist line sits in the middle of the chart', () => {
        const axis = valueAxis([88, 87.5, 87], 300, 0, 1);

        expect(axis.ticks).toEqual([86, 87, 88, 89]);
        expect(axis.y(87.5)).toBe(150);
    });

    it('ANA-001 draws one day or one flat value without dividing by zero', () => {
        const time = timeAxis(['2026-08-31'], 0, 100);
        const value = valueAxis([80, 80], 100, 0);

        expect(Number.isFinite(time.x('2026-08-31'))).toBe(true);
        expect(time.ticks).toEqual(['2026-08-31']);
        expect(value.ticks).toEqual([80, 81]);
        expect(value.y(80)).toBe(100);
    });

    it('ANA-001 joins points into one SVG path', () => {
        expect(linePath([])).toBe('');
        expect(linePath([{ x: 0, y: 10 }, { x: 5.5, y: 2 }])).toBe('M0 10L5.5 2');
    });
});
