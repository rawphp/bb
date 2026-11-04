import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import content from '../../../../tests/fixtures/screens/progress_show.json';
import ProgressChartPicker from './ProgressChartPicker.vue';
import ProgressChartView from './ProgressChartView.vue';

type Chart = NonNullable<ProgressShowOutput['chart']>;
const chart = (content as ProgressShowOutput).chart!;
const first = chart.readouts[0]!;
const last = chart.readouts.at(-1)!;

// The chart's viewBox is 340 wide; laid out 340 px wide, a client x is a chart x.
function laidOut(wrapper: VueWrapper): SVGSVGElement {
    const svg = wrapper.get('svg').element as SVGSVGElement;
    svg.getBoundingClientRect = () => ({ left: 0, top: 0, width: 340, height: 244, right: 340, bottom: 244, x: 0, y: 0, toJSON: () => ({}) });
    return svg;
}

async function pointer(svg: SVGSVGElement, type: string, clientX: number) {
    svg.dispatchEvent(new MouseEvent(type, { clientX, clientY: 100, bubbles: true }));
    await nextTick();
}

const readout = (wrapper: VueWrapper) => ({
    title: wrapper.get('[data-readout-title]').text(),
    lines: wrapper.findAll('[data-readout-line]').map((line) => [line.get('dt').text(), line.get('dd').text()]),
});

describe('ProgressChartPicker', () => {
    it('ANA-012 reads out the latest weigh-in, as the server words it, until the user picks a day', () => {
        const wrapper = mount(ProgressChartPicker, { props: { chart } });

        expect(readout(wrapper)).toEqual({ title: last.title, lines: last.lines.map((line) => [line.label, line.value]) });
        expect(wrapper.find('[data-picked]').exists()).toBe(true);
    });

    it('ANA-012 touching and dragging across the chart reads out the nearest weigh-in', async () => {
        const wrapper = mount(ProgressChartPicker, { props: { chart } });
        const svg = laidOut(wrapper);

        await pointer(svg, 'pointerdown', 0);
        expect(readout(wrapper).title).toBe(first.title);
        expect(readout(wrapper).lines).toContainEqual(['Waist', '88.0 cm']);

        await pointer(svg, 'pointermove', 340);
        expect(readout(wrapper).title).toBe(last.title);

        // Once the finger lifts, moving the pointer reads nothing new; the picked day stays.
        await pointer(svg, 'pointerup', 340);
        await pointer(svg, 'pointermove', 0);
        expect(readout(wrapper).title).toBe(last.title);
    });

    it('ANA-012 a mouse reads out the weigh-in it hovers', async () => {
        const wrapper = mount(ProgressChartPicker, { props: { chart } });
        const svg = laidOut(wrapper);

        svg.dispatchEvent(Object.assign(new MouseEvent('pointermove', { clientX: 0, bubbles: true }), { pointerType: 'mouse' }));
        await nextTick();

        expect(readout(wrapper).title).toBe(first.title);
    });

    it('ANA-012 is a slider: arrow keys step one weigh-in, Home and End jump to the ends, and the value is the readout', async () => {
        const wrapper = mount(ProgressChartPicker, { props: { chart } });
        const slider = wrapper.get('[role="slider"]');
        const n = chart.readouts.length;

        expect(slider.attributes()).toMatchObject({
            tabindex: '0',
            'aria-label': chart.accessibility_label,
            'aria-valuemin': '1',
            'aria-valuemax': String(n),
            'aria-valuenow': String(n),
        });
        expect(slider.attributes('aria-valuetext')).toBe(`${last.title}: ${last.lines.map((l) => `${l.label} ${l.value}`).join(', ')}`);

        await slider.trigger('keydown', { key: 'ArrowRight' });
        expect(slider.attributes('aria-valuenow')).toBe(String(n));
        await slider.trigger('keydown', { key: 'ArrowLeft' });
        expect(readout(wrapper).title).toBe(chart.readouts[n - 2]!.title);
        await slider.trigger('keydown', { key: 'Home' });
        expect(slider.attributes('aria-valuenow')).toBe('1');
        expect(readout(wrapper).title).toBe(first.title);
        await slider.trigger('keydown', { key: 'ArrowLeft' });
        expect(slider.attributes('aria-valuenow')).toBe('1');
        await slider.trigger('keydown', { key: 'End' });
        expect(readout(wrapper).title).toBe(last.title);
    });

    it('ANA-012 another chart (the other scope) reads out its own latest weigh-in', async () => {
        const wrapper = mount(ProgressChartPicker, { props: { chart } });
        await wrapper.get('[role="slider"]').trigger('keydown', { key: 'Home' });

        const shorter: Chart = { ...chart, weights: chart.weights.slice(0, 3), readouts: chart.readouts.slice(0, 3) };
        await wrapper.setProps({ chart: shorter });

        expect(readout(wrapper).title).toBe(chart.readouts[2]!.title);
    });

    it('ANA-012 marks the picked weigh-in on the chart, and draws no mark where nothing is picked (the share picture)', async () => {
        const picked = mount(ProgressChartView, { props: { chart, picked: first.date } });
        const plain = mount(ProgressChartView, { props: { chart } });

        expect(picked.find('[data-picked]').exists()).toBe(true);
        expect(picked.find('[data-picked-trend]').exists()).toBe(true);
        expect(plain.find('[data-picked]').exists()).toBe(false);
    });
});
