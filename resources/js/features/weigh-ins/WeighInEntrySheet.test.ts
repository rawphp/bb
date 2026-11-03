import { flushPromises, mount } from '@vue/test-utils';
import { afterAll, afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { writeNow } from '@/app/call';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import WeighInEntrySheet from './WeighInEntrySheet.vue';

// The device sits in another zone than the server's offsets, so a field read in the
// device's zone would show a different wall time than the server's labels.
const zone = vi.hoisted(() => {
    const before = process.env.TZ;
    process.env.TZ = 'America/Los_Angeles';
    return before;
});

afterAll(() => {
    if (zone === undefined) delete process.env.TZ;
    else process.env.TZ = zone;
});

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const writeMock = vi.mocked(writeNow);
const content = screenFixture<WeighInsShowOutput>('weigh_ins_show');
const logged = { ...content.rows[0]!, weighed_at: '2026-10-10T08:35:00+10:00' };
const missed = { ...content.rows[1]!, weighed_at: '2026-10-09T07:00:00-05:00' };

function show(props: { row?: WeighInsShowOutput['rows'][number] | null; at?: string | null }) {
    return mount(WeighInEntrySheet, { props: { open: true, units: content.units, reload: vi.fn().mockResolvedValue(undefined), ...props }, attachTo: document.body });
}

function field(name: string): HTMLInputElement {
    return document.body.querySelector<HTMLInputElement>(`[role="dialog"] [name="${name}"]`)!;
}

async function type(name: string, value: string): Promise<void> {
    field(name).value = value;
    field(name).dispatchEvent(new Event('input'));
    await flushPromises();
}

async function save(): Promise<string> {
    document.body.querySelector<HTMLButtonElement>('[data-save]')!.click();
    await flushPromises();
    const input = writeMock.mock.calls[0]![1];
    expect(inputViolations(writeMock.mock.calls[0]![0], input)).toEqual([]);
    return (input as { weighed_at: string }).weighed_at;
}

describe('WeighInEntrySheet in a time zone the server did not write', () => {
    beforeEach(() => writeMock.mockReset().mockResolvedValue({}));
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('WT-004 an edited weigh-in at +10:00 shows the server\'s wall time and saves with +10:00', async () => {
        const sheet = show({ row: logged });

        expect(field('weighed_at').value).toBe('2026-10-10T08:35');
        expect(await save()).toBe('2026-10-10T08:35:00+10:00');
        sheet.unmount();
    });

    it('WT-004 a changed time on a +10:00 weigh-in is read in +10:00', async () => {
        show({ row: logged });

        await type('weighed_at', '2026-10-10T06:15');

        expect(await save()).toBe('2026-10-10T06:15:00+10:00');
    });

    it('WT-004 a missed day at -05:00 starts at the server\'s wall time and saves with -05:00', async () => {
        show({ at: missed.weighed_at });

        expect(field('weighed_at').value).toBe('2026-10-09T07:00');
        await type('weight', '83');
        expect(await save()).toBe('2026-10-09T07:00:00-05:00');
        expect(writeMock.mock.calls[0]![0]).toBe('weigh_in_log');
    });

    it('WT-002 a new weigh-in for now takes the device\'s wall time and offset', async () => {
        vi.useFakeTimers({ now: new Date('2026-10-11T06:30:00Z'), toFake: ['Date'] });
        try {
            show({});

            expect(field('weighed_at').value).toBe('2026-10-10T23:30');
            await type('weight', '82');
            expect(await save()).toBe('2026-10-10T23:30:00-07:00');
        } finally {
            vi.useRealTimers();
        }
    });
});
