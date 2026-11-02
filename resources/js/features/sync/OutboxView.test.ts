import 'fake-indexeddb/auto';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { discardEntry, retryEntry } from '@/app/call';
import OutboxView from './OutboxView.vue';
import routes from './routes';
import { queue, resetOutbox } from '@/testing/outbox';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), retryEntry: vi.fn(), discardEntry: vi.fn() }));

const retryMock = vi.mocked(retryEntry);
const discardMock = vi.mocked(discardEntry);

const weightRefused = {
    status: 422,
    body: { ok: false, error: { code: 'validation_failed', message: 'Enter a weight above zero.', violations: [{ field: 'weight', message: 'Enter a weight above zero.' }] } },
};

async function view(): Promise<VueWrapper> {
    const wrapper = mount(OutboxView);
    await flushPromises();
    return wrapper;
}

const row = (wrapper: VueWrapper, seq: number) => wrapper.get(`[data-entry="${seq}"]`);

describe('OutboxView', () => {
    beforeEach(async () => {
        retryMock.mockReset();
        discardMock.mockReset();
        await resetOutbox();
    });

    it('OFF-010 opens at /sync from the More tab', () => {
        expect(routes).toContainEqual(expect.objectContaining({ path: '/sync', meta: expect.objectContaining({ tab: 'more' }) }));
    });

    it('OFF-010 lists each entry in plain words, when it was made and its state', async () => {
        const waiting = await queue({ label: 'Logged set 3 of Bench Press, 100 kg × 8', occurredAt: '2026-11-02T09:30:00.000Z' });
        const failed = await queue({ name: 'workout_set_add', label: 'Added a set to Bench Press', creates: ['01NEW'], refusal: { status: 404, body: { ok: false, error: { message: 'Not found.' } } } });
        const held = await queue({ name: 'workout_set_log', label: 'Logged the new set', input: { set_id: '01NEW' } });
        const wrapper = await view();

        expect(row(wrapper, waiting.seq).text()).toContain('Logged set 3 of Bench Press, 100 kg × 8');
        expect(row(wrapper, waiting.seq).get('time').attributes('datetime')).toBe('2026-11-02T09:30:00.000Z');
        expect(row(wrapper, waiting.seq).text()).toContain('Waiting to sync');
        expect(row(wrapper, failed.seq).text()).toContain("Couldn't sync");
        expect(row(wrapper, failed.seq).get('[role="alert"]').text()).toBe('Not found.');
        expect(row(wrapper, held.seq).text()).toContain("Waiting on a change that couldn't sync");
        expect(row(wrapper, waiting.seq).find('[data-test="retry"]').exists()).toBe(false);
    });

    it('OFF-010 says when nothing is waiting', async () => {
        const wrapper = await view();

        expect(wrapper.text()).toContain('Nothing is waiting to sync.');
    });

    it("OFF-011 retries a failed entry as it was when the server named no field to fix", async () => {
        const failed = await queue({ label: 'Added a set', refusal: { status: 404, body: { ok: false, error: { message: 'Not found.' } } } });
        const wrapper = await view();

        expect(row(wrapper, failed.seq).find('[data-test="edit"]').exists()).toBe(false);
        await row(wrapper, failed.seq).get('[data-test="retry"]').trigger('click');

        expect(retryMock).toHaveBeenCalledWith(expect.objectContaining({ seq: failed.seq }), failed.input);
    });

    it('OFF-011 edits the fields the server named, keeps their type, and retries with them', async () => {
        const failed = await queue({ name: 'weigh_in_log', label: 'Logged a weigh-in of -5 kg', input: { weight: -5, unit: 'kg' }, refusal: weightRefused });
        const wrapper = await view();

        await row(wrapper, failed.seq).get('[data-test="edit"]').trigger('click');
        const field = row(wrapper, failed.seq).get<HTMLInputElement>('input[name="weight"]');
        expect(field.element.value).toBe('-5');
        expect(row(wrapper, failed.seq).text()).toContain('Enter a weight above zero.');
        await field.setValue('80.5');
        await row(wrapper, failed.seq).get('form').trigger('submit');

        expect(retryMock).toHaveBeenCalledWith(expect.objectContaining({ seq: failed.seq }), { weight: 80.5, unit: 'kg' });
    });

    it('OFF-011 offers Try again and Discard for a change refused for when it was made, which a later try may pass', async () => {
        const message = 'This change is dated in the future. Check the date and time on this device.';
        const failed = await queue({
            label: 'Logged 9,000 steps',
            refusal: { status: 422, body: { ok: false, error: { code: 'validation_failed', message, violations: [{ field: 'occurred_at', message }] } } },
        });
        const wrapper = await view();

        expect(row(wrapper, failed.seq).get('[role="alert"]').text()).toBe(message);
        expect(row(wrapper, failed.seq).find('[data-test="edit"]').exists()).toBe(false);
        expect(row(wrapper, failed.seq).find('[data-test="retry"]').exists()).toBe(true);
        expect(row(wrapper, failed.seq).find('[data-test="discard"]').exists()).toBe(true);
    });

    it("OFF-011 says a change the server never finished may already be saved, before the user sends it again", async () => {
        const failed = await queue({
            label: 'Logged set 3 of Bench Press',
            refusal: { status: 409, body: { ok: false, error: { code: 'conflict', message: 'This is still being processed.', retryable: true } } },
        });
        const wrapper = await view();

        expect(row(wrapper, failed.seq).get('[role="alert"]').text()).toBe(
            "The server didn't finish this change, so it may already be saved. Check before you send it again.",
        );
        expect(row(wrapper, failed.seq).find('[data-test="retry"]').exists()).toBe(true);
        expect(row(wrapper, failed.seq).find('[data-test="discard"]').exists()).toBe(true);
    });

    it('OFF-011 discards a failed entry only once the user confirms', async () => {
        const failed = await queue({ label: 'Logged a weigh-in', refusal: weightRefused });
        const wrapper = await view();

        await row(wrapper, failed.seq).get('[data-test="discard"]').trigger('click');
        expect(discardMock).not.toHaveBeenCalled();
        expect(row(wrapper, failed.seq).text()).toContain('It will not be saved.');
        await row(wrapper, failed.seq).get('[data-test="keep"]').trigger('click');
        expect(row(wrapper, failed.seq).find('[data-test="confirm-discard"]').exists()).toBe(false);

        await row(wrapper, failed.seq).get('[data-test="discard"]').trigger('click');
        await row(wrapper, failed.seq).get('[data-test="confirm-discard"]').trigger('click');

        expect(discardMock).toHaveBeenCalledWith(expect.objectContaining({ seq: failed.seq }));
    });
});
