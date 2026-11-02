import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, write } from '@/app/call';
import { refusal, sent, sheet } from './sheetTesting';
import { useWeekdayChange } from './useWeekdayChange';
import WeekdayChangeSheet from './WeekdayChangeSheet.vue';
import WeekdayChips from './WeekdayChips.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), write: vi.fn() }));

const writeMock = vi.mocked(write);

beforeEach(() => {
    writeMock.mockReset().mockResolvedValue({});
});

describe('WeekdayChangeSheet', () => {
    it('SCH-039 asks whether to restructure the mesocycle or keep all its sessions', () => {
        const wrapper = sheet(WeekdayChangeSheet);
        const options = wrapper.findAll('[role="radio"]');

        expect(wrapper.text()).toContain('Training days changed');
        expect(wrapper.text()).toContain('Your new training days start next plan week. What should your mesocycle do?');
        expect(options.map((option) => option.attributes('aria-label'))).toEqual(['Restructure from next week', 'Keep all sessions']);
        expect(wrapper.find('[data-save]').exists()).toBe(false);
    });

    it('SCH-039 shows the server\'s words on what the change does', async () => {
        const wrapper = sheet(WeekdayChangeSheet, { question: 'Chest and back fall below the minimum on 2 days.' });

        expect(wrapper.text()).toContain('Chest and back fall below the minimum on 2 days.');
    });

    it('SCH-039 answers at once on a tap, and nothing stays picked', async () => {
        const wrapper = sheet(WeekdayChangeSheet);

        await wrapper.findAll('[role="radio"]')[0]!.trigger('click');
        await wrapper.findAll('[role="radio"]')[0]!.trigger('click');

        expect(wrapper.emitted('choose')).toEqual([['restructure'], ['restructure']]);
        expect(wrapper.find('[role="radio"][aria-checked="true"]').exists()).toBe(false);
    });

    it('SCH-039 Cancel saves nothing, and is off while the choice saves', async () => {
        const wrapper = sheet(WeekdayChangeSheet);
        await wrapper.find('[data-cancel]').trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);

        await wrapper.setProps({ busy: true, error: 'The mesocycle could not be changed.' });
        expect(wrapper.find('[data-cancel]').attributes('disabled')).toBeDefined();
        expect(wrapper.findAll('[role="radio"]').every((option) => option.attributes('disabled') !== undefined)).toBe(true);
        expect(wrapper.find('[role="alert"]').text()).toBe('The mesocycle could not be changed.');
    });
});

describe('useWeekdayChange', () => {
    function host() {
        let change!: ReturnType<typeof useWeekdayChange>;
        mount({ setup: () => ((change = useWeekdayChange()), () => null) });
        return change;
    }

    it('SCH-039 asks only when the server asks for the fewer-days choice', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('training_weekdays_change', 422, refusal('Choose.', 'fewer_days')));
        const change = host();

        expect(await change.change([1, 5])).toBe(false);
        expect(change.asking.value).toEqual([1, 5]);
        expect(change.question.value).toBe('Choose.');
        expect(change.error.value).toBeNull();

        expect(await change.choose('restructure')).toBe(true);
        expect(change.asking.value).toBeNull();
        expect(sent()).toEqual([
            ['training_weekdays_change', { weekdays: [1, 5], fewer_days: null }],
            ['training_weekdays_change', { weekdays: [1, 5], fewer_days: 'restructure' }],
        ]);
    });

    it('CAP-014 retries a change that got no answer with the same idempotency key', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('training_weekdays_change', 0, { reason: 'timeout' }));
        const change = host();

        expect(await change.change([1, 3])).toBe(false);
        expect(change.error.value).toContain('may have worked');
        expect(await change.change([1, 3])).toBe(true);

        const [first, second] = writeMock.mock.calls.map((call) => call[2]?.idempotencyKey);
        expect(second).toBe(first);
        expect(first).toMatch(/^training_weekdays_change:/);
    });

    it('SCH-039 Cancel drops the question and any error', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('training_weekdays_change', 422, refusal('Choose.', 'fewer_days')));
        writeMock.mockRejectedValueOnce(new CapabilityError('training_weekdays_change', 422, refusal('The mesocycle has ended.', 'fewer_days')));
        const change = host();
        await change.change([1]);
        await change.choose('extend');
        expect(change.error.value).toBe('The mesocycle has ended.');

        change.cancel();

        expect(change.asking.value).toBeNull();
        expect(change.error.value).toBeNull();
    });
});

describe('WeekdayChips', () => {
    const days = [
        { iso: 1, label: 'Mon' },
        { iso: 2, label: 'Tue' },
    ];

    it('SCH-033 shows a chip per weekday, Monday first, the chosen ones pressed', async () => {
        const wrapper = mount(WeekdayChips, { props: { days, selected: [2] } });
        const chips = wrapper.findAll('button');

        expect(chips.map((chip) => chip.text())).toEqual(['M', 'T']);
        expect(chips.map((chip) => chip.attributes('aria-pressed'))).toEqual(['false', 'true']);
        await chips[0]!.trigger('click');
        expect(wrapper.emitted('toggle')).toEqual([[1]]);
    });
});
