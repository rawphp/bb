import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, writeNow } from '@/app/call';
import { offerInstall } from '@/app/install';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import { inputViolations } from '@/testing/schemas';
import ChatSetup from './ChatSetup.vue';
import { fixture } from './onboardingTesting';
import ScreeningRowView from './ScreeningRowView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), writeNow: vi.fn() }));
vi.mock('@/app/install', () => ({ offerInstall: vi.fn(async () => undefined) }));

const writeMock = vi.mocked(writeNow);
const screening = fixture('screening');
const review = fixture();
const goal = fixture('empty');

function mountSetup(setup: OnboardingShowOutput, busy = false): VueWrapper {
    return mount(ChatSetup, { props: { setup, busy }, attachTo: document.body, global: { stubs: { teleport: true } } });
}

/** Each onboarding_answer the sheet sent, checked against the write's schema snapshot. */
function sent(): Record<string, unknown>[] {
    return writeMock.mock.calls.map(([name, input]) => {
        expect(name).toBe('onboarding_answer');
        expect(inputViolations('onboarding_answer', input)).toEqual([]);
        return input as Record<string, unknown>;
    });
}

function sheet(wrapper: VueWrapper) {
    return wrapper.find('[role="dialog"]');
}

describe('ChatSetup', () => {
    let wrapper: VueWrapper | undefined;

    beforeEach(() => {
        writeMock.mockReset().mockResolvedValue({});
        vi.mocked(offerInstall).mockClear();
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
    });

    it('AGT-031 opens the screening step as a sheet in the chat when setup reaches it', () => {
        wrapper = mountSetup(screening);

        expect(sheet(wrapper).exists()).toBe(true);
        expect(sheet(wrapper).text()).toContain(screening.screening.prompt);
        for (const row of screening.screening.rows) expect(sheet(wrapper).text()).toContain(row.label);
    });

    it('AGT-031 shows nothing on a step the Coach asks in conversation', () => {
        wrapper = mountSetup(goal);

        expect(sheet(wrapper).exists()).toBe(false);
        expect(wrapper.text()).toBe('');
    });

    it('AGT-031 SAFE-001 sends each tick from the sheet, not the model, and asks for the step again', async () => {
        wrapper = mountSetup(screening);
        const index = screening.screening.rows.findIndex((r) => !r.is_locked);
        const row = screening.screening.rows[index]!;

        await wrapper.findAllComponents(ScreeningRowView)[index]!.trigger('click');
        await flushPromises();

        expect(sent()).toEqual([{ answers: { screening: [{ item: row.item, is_on: !row.is_on }] }, move: 'stay' }]);
        expect(wrapper.emitted('changed')).toHaveLength(1);
        expect(wrapper.emitted('say')).toBeUndefined();
    });

    it('AGT-031 Done moves setup on from the sheet, closes it and tells the Coach', async () => {
        wrapper = mountSetup(screening);

        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([{ answers: {}, move: 'next' }]);
        expect(sheet(wrapper).exists()).toBe(false);
        expect(wrapper.emitted('changed')).toHaveLength(1);
        expect(wrapper.emitted('say')).toEqual([["I've answered the health check."]]);
    });

    it('CAP-012 a refused Done stays on the sheet in the server\'s words and tells the Coach nothing', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('onboarding_answer', 422, { ok: false, error: { message: 'Setup cannot continue.' } }));
        wrapper = mountSetup(screening);

        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sheet(wrapper).text()).toContain('Setup cannot continue.');
        expect(wrapper.emitted('say')).toBeUndefined();
    });

    it('AGT-031 a closed sheet can be opened again from the chat', async () => {
        wrapper = mountSetup(screening);

        await wrapper.get('[data-cancel]').trigger('click');
        expect(sheet(wrapper).exists()).toBe(false);
        await wrapper.get('[data-test="open-screening"]').trigger('click');

        expect(sheet(wrapper).exists()).toBe(true);
    });

    it('AGT-031 Done waits while the Coach is busy', () => {
        wrapper = mountSetup(screening, true);

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('AGT-032 renders the review as a card, with every section and no Edit links', () => {
        wrapper = mountSetup(review);

        const card = wrapper.get('[data-test="review-card"]');
        for (const section of review.review.sections) {
            expect(card.text()).toContain(section.title);
            for (const row of section.rows) expect(card.text()).toContain(row.value);
        }
        expect(card.find('button[aria-label^="Edit"]').exists()).toBe(false);
        expect(sheet(wrapper).exists()).toBe(false);
    });

    it('AGT-032 AGT-010 the card\'s Start my plan sends the confirm line for onboarding_complete as the whole message', async () => {
        wrapper = mountSetup(review);

        const start = wrapper.get('[data-test="start-plan"]');
        expect(start.text()).toBe(review.step.next_title);
        await start.trigger('click');

        expect(wrapper.emitted('say')).toEqual([['confirm: onboarding_complete{}']]);
        expect(offerInstall).toHaveBeenCalledOnce();
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('AGT-032 Start my plan waits while the Coach is busy', () => {
        wrapper = mountSetup(review, true);

        expect(wrapper.get('[data-test="start-plan"]').attributes('disabled')).toBeDefined();
    });
});
