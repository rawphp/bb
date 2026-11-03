import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, writeNow } from '@/app/call';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import WeighInsView from './WeighInsView.vue';

const push = vi.fn();
const back = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ push, back }) }));
vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const content = screenFixture<WeighInsShowOutput>('weigh_ins_show');
const empty = screenFixture<WeighInsShowOutput>('weigh_ins_show.empty');

let wrapper: VueWrapper | undefined;

function show(props: { payload?: WeighInsShowOutput; pending?: boolean; log?: string } = {}): VueWrapper {
    wrapper = mount(WeighInsView, { props, attachTo: document.body });
    return wrapper;
}

function dialogTitle(): string | null {
    return document.body.querySelector('[role="dialog"] h2')?.textContent ?? null;
}

function field(name: string): HTMLInputElement {
    return document.body.querySelector<HTMLInputElement>(`[role="dialog"] [name="${name}"]`)!;
}

async function type(name: string, value: string): Promise<void> {
    const el = field(name);
    el.value = value;
    el.dispatchEvent(new Event('input'));
    await flushPromises();
}

async function save(): Promise<void> {
    document.body.querySelector<HTMLButtonElement>('[data-save]')!.click();
    await flushPromises();
}

describe('WeighInsView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(content);
        vi.mocked(readFresh).mockReset().mockResolvedValue(content);
        writeMock.mockReset().mockResolvedValue({});
        push.mockReset();
        back.mockReset();
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('WT-010 shows the server\'s trend weight under its label, without calling the server', () => {
        const view = show({ payload: content });

        expect(view.get('h1').text()).toBe('Weigh-ins');
        expect(view.get('[data-test="trend"]').text()).toBe('Trend weight83.0 kg');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('WT-004 lists every weigh-in by date with its time and weight, and marks missed days with the server\'s pill', () => {
        const view = show({ payload: content });

        const rows = view.findAll('[data-test="weigh-in"]');
        expect(rows.map((row) => row.text())).toEqual([
            'Sat, 10 Oct8:35 AM82.5 kgWaist 86 cm',
            'Fri, 9 OctMissed',
            'Wed, 7 OctMissed',
            'Mon, 5 Oct7:00 AM82.9 kg',
            'Sun, 4 Oct9:05 AM83.2 kg',
        ]);
        expect(rows[1]!.get('[data-test="missed"]').classes()).toContain('bg-tone-warning-bg');
    });

    it('WT-021 shows a weigh-in\'s waist when it has one', () => {
        const view = show({ payload: content });

        const rows = view.findAll('[data-test="weigh-in"]');
        expect(rows[0]!.get('[data-test="waist"]').text()).toBe('Waist 86 cm');
        expect(rows[3]!.find('[data-test="waist"]').exists()).toBe(false);
    });

    it('WT-001 the schedule row says the server\'s summary and opens the schedule', async () => {
        const view = show({ payload: content });

        const row = view.get('[data-test="schedule"]');
        expect(row.text()).toBe('Schedule: 4 days a week');
        await row.trigger('click');
        expect(push).toHaveBeenCalledWith('/weigh-ins/schedule');
    });

    it('WT-005 the help button tells how to weigh in, in the server\'s words', async () => {
        const view = show({ payload: content });

        await view.get('button[aria-label="Help"]').trigger('click');

        expect(dialogTitle()).toBe('Weighing in');
        expect(document.body.textContent).toContain('only the trend matters');
    });

    it('WT-004 shows the server\'s empty line when there are no weigh-ins', () => {
        const view = show({ payload: empty });

        expect(view.findAll('[data-test="weigh-in"]')).toHaveLength(0);
        expect(view.text()).toContain('No weigh-ins yet. Tap + to log your first.');
        expect(view.get('[data-test="trend"]').text()).toBe('Trend weightNo weigh-ins yet');
    });

    it('CAP-004 shows a skeleton while it reads weigh_ins_show, then the list', async () => {
        const view = show();

        expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('weigh_ins_show', {}, expect.any(Function), expect.any(Function));
        expect(view.find('[data-test="skeleton"]').exists()).toBe(false);
        expect(view.findAll('[data-test="weigh-in"]')).toHaveLength(5);
    });

    it('CAP-004 says why the weigh-ins did not load, and Try again reads them again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('weigh_ins_show', 500, null)).mockResolvedValueOnce(content);
        const view = show();
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('Your weigh-ins could not be loaded.');
        await view.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(view.findAll('[data-test="weigh-in"]')).toHaveLength(5);
    });

    it('OFF-008 marks the trend, each weight and each step count as waiting to sync', () => {
        const view = show({ payload: content, pending: true });

        expect(view.get('[data-test="trend"]').text()).toContain('Waiting to sync');
        const logged = view.findAll('[data-test="weigh-in"]').filter((row) => row.text().includes('kg'));
        expect(logged.every((row) => row.text().includes('Waiting to sync'))).toBe(true);
        expect(view.findAll('[data-test="steps-row"]').every((row) => row.text().includes('Waiting to sync'))).toBe(true);
    });

    it('OFF-008 marks nothing when nothing waits to sync', () => {
        const view = show({ payload: content });

        expect(view.text()).not.toContain('Waiting to sync');
    });

    it('WT-006 + opens a blank weigh-in in the user\'s units, and saves it with a new id', async () => {
        const view = show({ payload: content });

        await view.get('button[aria-label="Log weigh-in"]').trigger('click');
        expect(dialogTitle()).toBe('Log weigh-in');
        expect(field('weight').value).toBe('');
        expect(document.body.querySelector('[role="dialog"]')!.textContent).toContain('kg');
        await type('weight', '82,4');
        await type('waist', '85.5');
        await save();

        const [name, input] = writeMock.mock.calls[0]!;
        expect(name).toBe('weigh_in_log');
        expect(input).toEqual({
            id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/),
            weighed_at: expect.any(String),
            weight: { value: 82.4, unit: 'kg' },
            waist: { value: 85.5, unit: 'cm' },
        });
        expect(inputViolations('weigh_in_log', input)).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('weigh_ins_show', {}, expect.any(Function), expect.any(Function));
        expect(dialogTitle()).toBeNull();
    });

    it('WT-021 a weigh-in saved with no waist sends none', async () => {
        const view = show({ payload: content });

        await view.get('button[aria-label="Log weigh-in"]').trigger('click');
        await type('weight', '82');
        await save();

        expect(writeMock.mock.calls[0]![1]).toMatchObject({ waist: null });
        expect(inputViolations('weigh_in_log', writeMock.mock.calls[0]![1])).toEqual([]);
    });

    it('WT-004 a weigh-in opens for editing with its weight, waist and time, and saves under its own id', async () => {
        const view = show({ payload: content });

        await view.findAll('[data-test="weigh-in"]')[0]!.trigger('click');
        expect(dialogTitle()).toBe('Edit weigh-in');
        expect(field('weight').value).toBe('82.5');
        expect(field('waist').value).toBe('86');
        await type('weight', '82.7');
        await save();

        const [name, input] = writeMock.mock.calls[0]!;
        expect(name).toBe('weigh_in_update');
        expect(input).toMatchObject({ id: '01JA00000000000000000WEIGH3', weight: { value: 82.7, unit: 'kg' }, waist: { value: 86, unit: 'cm' } });
        expect(Date.parse((input as { weighed_at: string }).weighed_at)).toBe(Date.parse('2026-10-10T08:35:00+00:00'));
        expect(inputViolations('weigh_in_update', input)).toEqual([]);
    });

    it('WT-004 the date field shows the time as the server wrote it, and a save keeps the server\'s offset', async () => {
        const view = show({ payload: content });

        await view.findAll('[data-test="weigh-in"]')[0]!.trigger('click');
        expect(field('weighed_at').value).toBe('2026-10-10T08:35');
        await type('weighed_at', '2026-10-10T09:05');
        await save();

        expect((writeMock.mock.calls[0]![1] as { weighed_at: string }).weighed_at).toBe('2026-10-10T09:05:00+00:00');
    });

    it('WT-002 a new weigh-in for now starts at the device\'s time, with its offset', async () => {
        vi.useFakeTimers({ now: new Date('2026-10-11T06:30:00Z'), toFake: ['Date'] });
        try {
            const view = show({ payload: content });

            await view.get('button[aria-label="Log weigh-in"]').trigger('click');
            await type('weight', '82');
            await save();

            const sent = (writeMock.mock.calls[0]![1] as { weighed_at: string }).weighed_at;
            expect(sent).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00[+-]\d{2}:\d{2}$/);
            expect(Date.parse(sent)).toBe(Date.parse('2026-10-11T06:30:00Z'));
        } finally {
            vi.useRealTimers();
        }
    });

    it('WT-004 Delete weigh-in deletes it and closes the sheet', async () => {
        const view = show({ payload: content });

        await view.findAll('[data-test="weigh-in"]')[3]!.trigger('click');
        document.body.querySelector<HTMLButtonElement>('[data-test="delete-weigh-in"]')!.click();
        await flushPromises();

        expect(writeMock).toHaveBeenCalledWith('weigh_in_delete', { id: '01JA00000000000000000WEIGH2' }, expect.anything());
        expect(inputViolations('weigh_in_delete', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(dialogTitle()).toBeNull();
    });

    it('WT-004 a missed day opens a new weigh-in for that day, at the time the server gives', async () => {
        const view = show({ payload: content });

        await view.findAll('[data-test="weigh-in"]')[1]!.trigger('click');
        expect(dialogTitle()).toBe('Log weigh-in');
        expect(document.body.querySelector('[data-test="delete-weigh-in"]')).toBeNull();
        await type('weight', '83');
        await save();

        const [name, input] = writeMock.mock.calls[0]!;
        expect(name).toBe('weigh_in_log');
        expect(Date.parse((input as { weighed_at: string }).weighed_at)).toBe(Date.parse('2026-10-09T07:00:00+00:00'));
    });

    it('CAP-012 a refused weigh-in keeps the sheet open with the server\'s words', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('weigh_in_log', 422, { error: { message: 'Enter a weight between 20 and 1000 kg.' } }));
        const view = show({ payload: content });

        await view.get('button[aria-label="Log weigh-in"]').trigger('click');
        await type('weight', '5');
        await save();

        expect(dialogTitle()).toBe('Log weigh-in');
        expect(document.body.querySelector('[role="dialog"]')!.textContent).toContain('Enter a weight between 20 and 1000 kg.');
    });

    it('WT-002 opened to log one, it starts a new weigh-in at the time asked, and closing it goes back', async () => {
        const view = show({ payload: content, log: '2026-10-07T07:00:00+00:00' });
        await flushPromises();

        expect(dialogTitle()).toBe('Log weigh-in');
        await type('weight', '83.1');
        await save();

        expect(Date.parse((writeMock.mock.calls[0]![1] as { weighed_at: string }).weighed_at)).toBe(Date.parse('2026-10-07T07:00:00+00:00'));
        expect(back).toHaveBeenCalled();
        expect(view.findAll('[data-test="weigh-in"]')).toHaveLength(5);
    });

    it('WT-002 opened to log one with no time, Cancel goes back without saving', async () => {
        show({ payload: content, log: '' });
        await flushPromises();

        expect(dialogTitle()).toBe('Log weigh-in');
        document.body.querySelector<HTMLButtonElement>('[data-cancel]')!.click();
        await flushPromises();

        expect(writeMock).not.toHaveBeenCalled();
        expect(back).toHaveBeenCalled();
    });

    it('WT-020 lists the step counts with how each was logged', () => {
        const view = show({ payload: content });

        expect(view.get('[data-test="steps"] h2').text()).toBe('Steps');
        expect(view.findAll('[data-test="steps-row"]').map((row) => row.text())).toEqual(['Fri, 9 OctTyped8,412 steps', 'Thu, 8 OctShortcut10,230 steps']);
    });

    it('WT-020 Add steps starts on the server\'s day, and saves the count as typed', async () => {
        const view = show({ payload: empty });

        await view.get('button[aria-label="Add steps"]').trigger('click');
        expect(dialogTitle()).toBe('Log steps');
        expect(field('date').value).toBe('2026-10-09');
        await type('steps', '9500');
        await save();

        expect(writeMock).toHaveBeenCalledWith('steps_log', { date: '2026-10-09', steps: 9500 }, expect.anything());
        expect(inputViolations('steps_log', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(dialogTitle()).toBeNull();
    });

    it('WT-020 a day\'s steps open with its count, and Save stays off for anything but a whole number', async () => {
        const view = show({ payload: content });

        await view.findAll('[data-test="steps-row"]')[1]!.trigger('click');
        expect(dialogTitle()).toBe('Edit steps');
        expect(field('date').value).toBe('2026-10-08');
        expect(field('steps').value).toBe('10230');
        await type('steps', '10.5');
        expect(document.body.querySelector<HTMLButtonElement>('[data-save]')!.disabled).toBe(true);
        await type('steps', '11000');
        await save();

        expect(writeMock).toHaveBeenCalledWith('steps_log', { date: '2026-10-08', steps: 11000 }, expect.anything());
    });
});
