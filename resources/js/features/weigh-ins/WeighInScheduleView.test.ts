import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, writeNow } from '@/app/call';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import WeighInScheduleView from './WeighInScheduleView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const content = screenFixture<WeighInsShowOutput>('weigh_ins_show');

let wrapper: VueWrapper | undefined;

function show(props: { payload?: WeighInsShowOutput; pending?: boolean } = {}): VueWrapper {
    wrapper = mount(WeighInScheduleView, { props, attachTo: document.body });
    return wrapper;
}

function dialog(): HTMLElement | null {
    return document.body.querySelector<HTMLElement>('[role="dialog"]');
}

function day(name: string): HTMLButtonElement {
    return dialog()!.querySelector<HTMLButtonElement>(`button[aria-label="${name}"]`)!;
}

function button(selector: string): HTMLButtonElement {
    return document.body.querySelector<HTMLButtonElement>(selector)!;
}

async function click(el: HTMLElement): Promise<void> {
    el.click();
    await flushPromises();
}

async function setTime(value: string): Promise<void> {
    const input = dialog()!.querySelector<HTMLInputElement>('input[type="time"]')!;
    input.value = value;
    input.dispatchEvent(new Event('change'));
    await flushPromises();
}

function sentEntries(): unknown {
    return (writeMock.mock.calls[0]![1] as { entries: unknown }).entries;
}

describe('WeighInScheduleView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(content);
        vi.mocked(readFresh).mockReset().mockResolvedValue(content);
        writeMock.mockReset().mockResolvedValue({});
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('WT-007 lists each schedule entry with its days and time, and the server\'s note, without calling the server', () => {
        const view = show({ payload: content });

        expect(view.get('h1').text()).toBe('Schedule');
        expect(view.findAll('[data-test="entry"]').map((row) => row.text())).toEqual(['Mon, Wed, Fri7:00 AM', 'Sat8:30 AM']);
        expect(view.text()).toContain('The time only sets your reminder. You can weigh in at any time.');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-004 shows a skeleton while it reads weigh_ins_show, then the schedule', async () => {
        const view = show();

        expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('weigh_ins_show', {}, expect.any(Function), expect.any(Function));
        expect(view.findAll('[data-test="entry"]')).toHaveLength(2);
    });

    it('CAP-004 says why the schedule did not load, and Try again reads it again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('weigh_ins_show', 500, null)).mockResolvedValueOnce(content);
        const view = show();
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('Your weigh-in schedule could not be loaded.');
        await view.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(view.findAll('[data-test="entry"]')).toHaveLength(2);
    });

    it('OFF-008 marks the server\'s day and time labels as waiting to sync', () => {
        const view = show({ payload: content, pending: true });

        expect(view.findAll('[data-test="entry"]').every((row) => row.text().includes('Waiting to sync'))).toBe(true);
    });

    it('WT-007 an entry opens with its days on and its time, and Save sends the whole schedule with it changed', async () => {
        const view = show({ payload: content });

        await view.findAll('[data-test="entry"]')[1]!.trigger('click');
        expect(dialog()!.querySelector('h2')!.textContent).toBe('Edit schedule');
        expect(day('Saturday').getAttribute('aria-pressed')).toBe('true');
        expect(day('Monday').getAttribute('aria-pressed')).toBe('false');
        expect(dialog()!.querySelector<HTMLInputElement>('input[type="time"]')!.value).toBe('08:30');
        await click(day('Sunday'));
        await setTime('09:15');
        await click(button('[data-save]'));

        expect(writeMock.mock.calls[0]![0]).toBe('weigh_in_schedule_set');
        expect(sentEntries()).toEqual([
            { weekdays: [1, 3, 5], time: '07:00' },
            { weekdays: [6, 7], time: '09:15' },
        ]);
        expect(inputViolations('weigh_in_schedule_set', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('weigh_ins_show', {}, expect.any(Function), expect.any(Function));
        expect(dialog()).toBeNull();
    });

    it('WT-007 Select all days turns every day on, then turns itself off', async () => {
        const view = show({ payload: content });

        await view.findAll('[data-test="entry"]')[0]!.trigger('click');
        await click(button('[data-test="select-all"]'));

        expect(content.schedule.weekdays.every((weekday) => day(weekday.full).getAttribute('aria-pressed') === 'true')).toBe(true);
        expect(button('[data-test="select-all"]').disabled).toBe(true);
        await click(button('[data-save]'));
        expect(sentEntries()).toEqual([
            { weekdays: [1, 2, 3, 4, 5, 6, 7], time: '07:00' },
            { weekdays: [6], time: '08:30' },
        ]);
    });

    it('WT-007 + adds an entry to the schedule; Save stays off until a day is picked', async () => {
        const view = show({ payload: content });

        await view.get('button[aria-label="Add to schedule"]').trigger('click');
        expect(dialog()!.querySelector('h2')!.textContent).toBe('Add to schedule');
        expect(dialog()!.querySelector('[data-test="delete-entry"]')).toBeNull();
        expect(button('[data-save]').disabled).toBe(true);
        await click(day('Thursday'));
        await click(day('Tuesday'));
        await setTime('06:45');
        await click(button('[data-save]'));

        expect(sentEntries()).toEqual([
            { weekdays: [1, 3, 5], time: '07:00' },
            { weekdays: [6], time: '08:30' },
            { weekdays: [2, 4], time: '06:45' },
        ]);
        expect(inputViolations('weigh_in_schedule_set', writeMock.mock.calls[0]![1])).toEqual([]);
    });

    it('WT-007 Delete schedule sends the schedule without the entry', async () => {
        const view = show({ payload: content });

        await view.findAll('[data-test="entry"]')[1]!.trigger('click');
        await click(button('[data-test="delete-entry"]'));

        expect(sentEntries()).toEqual([{ weekdays: [1, 3, 5], time: '07:00' }]);
        expect(inputViolations('weigh_in_schedule_set', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(dialog()).toBeNull();
    });

    it('WT-007 Delete schedule is off, with the server\'s reason, where the server says deleting leaves too few days', async () => {
        const view = show({ payload: content });

        await view.findAll('[data-test="entry"]')[0]!.trigger('click');

        expect(button('[data-test="delete-entry"]').disabled).toBe(true);
        expect(dialog()!.textContent).toContain('Deleting this would leave fewer than 2 weigh-in days a week.');
    });

    it('CAP-014 a delete that got no answer is sent again as it was, though the read after it moved the entries', async () => {
        const after: WeighInsShowOutput = { ...content, schedule: { ...content.schedule, entries: [content.schedule.entries[1]!] } };
        vi.mocked(readFresh).mockReset().mockResolvedValue(after);
        readMock.mockReset().mockResolvedValue(after);
        writeMock.mockReset().mockRejectedValueOnce(new CapabilityError('weigh_in_schedule_set', 502, null)).mockResolvedValueOnce({});
        const view = show({ payload: { ...content, schedule: { ...content.schedule, entries: [content.schedule.entries[1]!, content.schedule.entries[0]!] } } });

        await view.findAll('[data-test="entry"]')[0]!.trigger('click');
        await click(button('[data-test="delete-entry"]'));
        expect(dialog()!.querySelector('h2')!.textContent).toBe('Edit schedule');
        await click(button('[data-test="delete-entry"]'));

        expect(writeMock).toHaveBeenCalledTimes(2);
        expect(writeMock.mock.calls[1]![1]).toEqual(writeMock.mock.calls[0]![1]);
        expect(writeMock.mock.calls[1]![2]).toEqual(writeMock.mock.calls[0]![2]);
    });

    it('CAP-012 a refused schedule keeps the sheet open with the server\'s words', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('weigh_in_schedule_set', 422, { error: { message: 'Weigh in at least 2 days a week.' } }));
        const view = show({ payload: content });

        await view.findAll('[data-test="entry"]')[1]!.trigger('click');
        await click(day('Saturday'));
        await click(day('Sunday'));
        await click(button('[data-save]'));

        expect(dialog()!.textContent).toContain('Weigh in at least 2 days a week.');
    });
});
