import { flushPromises, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { read, write } from '@/app/call';
import { answers, commit, fixture, radio, view } from './onboardingTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const rateLength = fixture('rate_length');
const warning = fixture('warning');

function custom() {
    const state = structuredClone(rateLength);
    state.rate_length.is_custom = true;
    state.rate_length.options.forEach((option) => (option.is_selected = option.value === 'Custom'));
    return state;
}

describe('step 6, rate and length', () => {
    let wrapper: VueWrapper | undefined;

    beforeEach(() => {
        wrapper?.unmount();
        vi.mocked(read).mockReset().mockResolvedValue(rateLength);
        vi.mocked(write).mockReset().mockResolvedValue({});
    });

    it('ONB-008 shows the presets with their rates, the length, the dates and the target, as the server sends them', () => {
        wrapper = view(rateLength);

        for (const option of rateLength.rate_length.options) expect(wrapper.text()).toContain(option.title);
        expect(radio(wrapper, 'Rate', 'Highest success').attributes('aria-checked')).toBe('true');
        expect(wrapper.text()).toContain('Lose 0.75% of body weight per week');
        expect(wrapper.text()).toContain('12 weeks');
        const dates = wrapper.findAll('input[type="date"]');
        expect(dates.map((d) => (d.element as HTMLInputElement).value)).toEqual(['2026-10-11', '2027-01-02']);
        expect((wrapper.get('input[aria-label="Target weight in lb"]').element as HTMLInputElement).value).toBe('160.5');
    });

    it('ONB-008 hides the custom rate slider until Custom is picked', () => {
        wrapper = view(rateLength);
        expect(wrapper.find('input[aria-label="Custom rate"]').exists()).toBe(false);

        wrapper.unmount();
        wrapper = view(custom());
        const slider = wrapper.get('input[aria-label="Custom rate"]');
        expect(slider.attributes()).toMatchObject({ min: '0.25', max: '1', step: '0.025' });
    });

    it('ONB-009 bounds the length slider by the server\'s limits and shows the recommended range under it', () => {
        wrapper = view(rateLength);

        const slider = wrapper.get('input[aria-label="Length in weeks"]');
        expect(slider.attributes()).toMatchObject({ min: '2', max: '16', step: '1' });
        expect(slider.attributes('aria-valuetext')).toBe('12 weeks');
        expect(wrapper.text()).toContain('Recommended: 6 to 12 weeks');
    });

    it('UI-030 shows the server\'s warnings for the phase', () => {
        wrapper = view(warning);

        expect(warning.notices).toHaveLength(2);
        for (const notice of warning.notices) expect(wrapper.text()).toContain(notice.text);
    });

    it('ONB-008 sends the preset picked', async () => {
        wrapper = view(rateLength);

        await radio(wrapper, 'Rate', 'Slow & steady').trigger('click');
        await flushPromises();

        expect(answers()).toEqual([{ rate_option: 'Slow & steady' }]);
    });

    it('ONB-008 sends the custom rate and the length when a slider is let go, not while it is dragged, and the server works out the rest', async () => {
        vi.mocked(read).mockResolvedValue(custom());
        wrapper = view(custom());

        for (const [label, value] of [
            ['Custom rate', '0.5'],
            ['Length in weeks', '10'],
        ]) {
            const slider = wrapper.get(`input[aria-label="${label}"]`);
            (slider.element as HTMLInputElement).value = value!;
            await slider.trigger('input');
            await flushPromises();
            expect(write, `${label} is sent while it is dragged`).not.toHaveBeenCalled();
            await slider.trigger('change');
            await flushPromises();
            expect(answers()).toEqual([label === 'Custom rate' ? { custom_rate: 0.5 } : { weeks: 10 }]);
            vi.mocked(write).mockClear();
        }
    });

    it('ONB-008 sends a start date and a last day picked, and a cleared one sends nothing and shows the saved date', async () => {
        wrapper = view(rateLength);
        const [start, last] = wrapper.findAll('input[type="date"]');

        await commit(start!, '2026-10-12');
        await flushPromises();
        await commit(last!, '2027-01-09');
        await flushPromises();
        await commit(last!, '');
        await flushPromises();

        expect(answers()).toEqual([{ start_date: '2026-10-12' }, { last_day: '2027-01-09' }]);
        expect((last!.element as HTMLInputElement).value).toBe('2027-01-02');
    });

    it('ONB-008 sends the target weight in the unit on screen when the field is left', async () => {
        wrapper = view(rateLength);
        const target = wrapper.get('input[aria-label="Target weight in lb"]');

        await target.trigger('focus');
        await target.setValue('155');
        expect(write).not.toHaveBeenCalled();
        await target.trigger('blur');
        await flushPromises();

        expect(answers()).toEqual([{ target_weight: { unit: 'lb', value: 155 } }]);
    });

    it('SAFE-017 a locked start date shows as text, and a target that cannot change says why', () => {
        const locked = structuredClone(rateLength);
        locked.rate_length.start_date.is_locked = true;
        locked.rate_length.target_weight = { ...locked.rate_length.target_weight, can_edit: false, note: 'A steady weight has no target to change.' };
        wrapper = view(locked);

        expect(wrapper.findAll('input[type="date"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('Oct 11, 2026');
        expect(wrapper.get('input[aria-label="Target weight in lb"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('A steady weight has no target to change.');
    });

    it('SAFE-017 a locked last day cannot be picked', () => {
        const locked = structuredClone(rateLength);
        locked.rate_length.last_day.is_locked = true;
        wrapper = view(locked);

        expect(wrapper.findAll('input[type="date"]')[1]!.attributes('disabled')).toBeDefined();
    });

    it('ONB-009 shows no recommended range when the goal has none', () => {
        const none = structuredClone(rateLength);
        none.rate_length.recommended_text = '';
        wrapper = view(none);

        expect(wrapper.text()).not.toContain('Recommended:');
    });

    it('OFF-008 marks every number the server worked out while a change waits to sync: rates, length, dates and target', () => {
        wrapper = view(custom(), { pending: true });

        expect(wrapper.text()).toContain('Waiting to sync');
        const dimmed = wrapper.findAll('[data-value]').map((value) => value.text());
        expect(dimmed).toEqual(['Lose 0.75% of body weight per week', '12 weeks']);
        expect(wrapper.get('[role="radiogroup"][aria-label="Rate"]').element.closest('.opacity-60')).not.toBeNull();
        for (const date of wrapper.findAll('input[type="date"]')) expect(date.element.closest('.opacity-60')).not.toBeNull();
        expect(wrapper.get('input[aria-label="Target weight in lb"]').element.closest('.opacity-60')).not.toBeNull();
    });

    it('OFF-008 dims nothing when nothing waits to sync', () => {
        wrapper = view(custom());

        expect(wrapper.find('.opacity-60').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Waiting to sync');
    });

    it('CAP-010 the sliders, dates and target take no input while an answer saves', async () => {
        let finish: (value: unknown) => void = () => {};
        vi.mocked(write).mockReturnValueOnce(new Promise((resolve) => (finish = resolve)));
        wrapper = view(custom());

        await radio(wrapper, 'Rate', 'Slow & steady').trigger('click');

        expect(wrapper.get('input[aria-label="Length in weeks"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('input[aria-label="Custom rate"]').attributes('disabled')).toBeDefined();
        for (const date of wrapper.findAll('input[type="date"]')) expect(date.attributes('readonly')).toBeDefined();
        expect(wrapper.get('input[aria-label="Target weight in lb"]').attributes('readonly')).toBeDefined();
        finish({});
        await flushPromises();
    });
});
