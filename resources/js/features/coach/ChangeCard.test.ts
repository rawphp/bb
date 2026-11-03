import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, writeNow } from '@/app/call';
import type { CoachChangesListOutput } from '@/contracts/coach_changes_list';
import { inputViolations, screenFixture } from '@/testing/schemas';
import ChangeCard from './ChangeCard.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), writeNow: vi.fn() }));

const writeMock = vi.mocked(writeNow);
const [withScreen, withoutScreen] = screenFixture<CoachChangesListOutput>('coach_changes_list').changes as [
    CoachChangesListOutput['changes'][number],
    CoachChangesListOutput['changes'][number],
];

function mountCard(change: CoachChangesListOutput['changes'][number]) {
    return mount(ChangeCard, { props: { change }, global: { stubs: { RouterLink: RouterLinkStub } } });
}

describe('ChangeCard', () => {
    beforeEach(() => writeMock.mockReset());

    it('AGT-013 shows what changed, a link to its screen and Undo', () => {
        const card = mountCard(withScreen);

        expect(card.text()).toContain('Meals updated for Run');
        expect(card.getComponent(RouterLinkStub).props('to')).toBe('/today');
        expect(card.find('[data-test="undo"]').exists()).toBe(true);
    });

    it('AGT-013 shows no link without a screen and no Undo without a token', () => {
        const card = mountCard(withoutScreen);

        expect(card.text()).toContain('Weigh in log');
        expect(card.findComponent(RouterLinkStub).exists()).toBe(false);
        expect(card.find('[data-test="undo"]').exists()).toBe(false);
    });

    it('AGT-013 Undo calls undo_apply with the change\'s token and then hides', async () => {
        writeMock.mockResolvedValueOnce({ toast: 'Undone', invalidates: [] });
        const card = mountCard(withScreen);

        await card.get('[data-test="undo"]').trigger('click');
        await flushPromises();

        expect(writeMock).toHaveBeenCalledWith('undo_apply', { token: withScreen.undo });
        expect(inputViolations('undo_apply', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(card.find('[data-test="undo"]').exists()).toBe(false);
    });

    it('CAP-013 shows the server\'s refusal on a conflict and keeps Undo', async () => {
        const body = { ok: false, error: { code: 'conflict', message: 'That set has changed since, so it can\'t be undone.' } };
        writeMock.mockRejectedValueOnce(new CapabilityError('undo_apply', 409, body));
        const card = mountCard(withScreen);

        await card.get('[data-test="undo"]').trigger('click');
        await flushPromises();

        expect(card.get('[data-test="undo-error"]').text()).toBe('That set has changed since, so it can\'t be undone.');
        expect(card.find('[data-test="undo"]').exists()).toBe(true);
    });
});
