import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { makeRouter } from '@/app/router';
import { CapabilityError, read, write } from '@/app/call';
import type { EligibilityRecheckShowOutput } from '@/contracts/eligibility_recheck_show';
import type { EligibilityRecheckOutput } from '@/contracts/eligibility_recheck';
import { inputViolations, screenFixture } from '@/testing/schemas';
import EligibilityRecheckView from './EligibilityRecheckView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const content = screenFixture<EligibilityRecheckShowOutput>('eligibility_recheck_show');
const trainingOnly = screenFixture<EligibilityRecheckShowOutput>('eligibility_recheck_show.warning');

function result(over: Partial<EligibilityRecheckOutput>): EligibilityRecheckOutput {
    return { invalidates: [], outcome: 'keep', message: 'Nothing changed. Your answers are saved.', next: 'done', ...over };
}

async function view(payload?: EligibilityRecheckShowOutput) {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    await router.push('/settings/eligibility');
    const wrapper = mount(EligibilityRecheckView, { props: { payload }, global: { plugins: [router] } });
    return { wrapper, router };
}

function sent(): Record<string, unknown> {
    const [name, input] = writeMock.mock.calls.at(-1)!;
    expect(name).toBe('eligibility_recheck');
    expect(inputViolations('eligibility_recheck', input)).toEqual([]);
    return input as Record<string, unknown>;
}

describe('EligibilityRecheckView', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
    });

    it('SAFE-017 shows the current mode and every screening question', async () => {
        const { wrapper } = await view(content);

        expect(wrapper.text()).toContain('Training and nutrition');
        for (const question of content.questions) expect(wrapper.text()).toContain(question.label);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SAFE-017 shows why a training-only user is training only', async () => {
        const { wrapper } = await view(trainingOnly);

        expect(wrapper.text()).toContain('Training only');
        expect(wrapper.text()).toContain(trainingOnly.reasons[0]!.text);
    });

    it('UI-030 shows the mode as a pill with its tone icon', async () => {
        const { wrapper } = await view(trainingOnly);

        expect(wrapper.getComponent({ name: 'StatusPill' }).props()).toMatchObject({ text: 'Training only', tone: 'warning' });
    });

    it('SAFE-017 locks the questions the profile answers', async () => {
        const { wrapper } = await view(content);
        const boxes = wrapper.findAll<HTMLInputElement>('input[type="checkbox"]');

        content.questions.forEach((question, index) => {
            expect(boxes[index]!.element.disabled).toBe(question.measured);
            expect(boxes[index]!.element.checked).toBe(question.applies);
        });
    });

    it('SAFE-017 sends every answer, as ticked, and shows the server\'s outcome', async () => {
        writeMock.mockResolvedValueOnce(result({ outcome: 'switchToTrainingOnly', message: 'You are now on training only.' }));
        const { wrapper } = await view(content);

        const pregnant = content.questions.findIndex((q) => q.item === 'pregnantOrBreastfeeding');
        await wrapper.findAll('input[type="checkbox"]')[pregnant]!.setValue(true);
        await wrapper.get('[data-test="check"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual({ answers: content.questions.map((q) => ({ item: q.item, applies: q.item === 'pregnantOrBreastfeeding' ? true : q.applies })) });
        expect(wrapper.text()).toContain('You are now on training only.');
        expect(wrapper.find('[data-test="check"]').exists()).toBe(false);
    });

    it('SAFE-017 sends a user who now passes to the nutrition steps', async () => {
        writeMock.mockResolvedValueOnce(result({ outcome: 'runNutritionSetup', message: 'Set up your nutrition next.', next: 'onboarding' }));
        const { wrapper, router } = await view(trainingOnly);

        await wrapper.get('[data-test="check"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-test="continue"]').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/onboarding');
    });

    it('NAV-001 Done goes back down the tab stack to the screen it came from', async () => {
        writeMock.mockResolvedValueOnce(result({}));
        const router = makeRouter(false, createMemoryHistory());
        await router.push('/more');
        await router.push('/settings/eligibility');
        const wrapper = mount(EligibilityRecheckView, { props: { payload: content }, global: { plugins: [router] } });

        await wrapper.get('[data-test="check"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-test="continue"]').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 50));

        expect(router.currentRoute.value.path).toBe('/more');
        wrapper.unmount();
    });

    it('NAV-001 Done on a screen opened on its own goes to More instead of leaving the app', async () => {
        writeMock.mockResolvedValueOnce(result({}));
        const { wrapper, router } = await view(content);
        const back = vi.spyOn(router, 'back');

        await wrapper.get('[data-test="check"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-test="continue"]').trigger('click');
        await flushPromises();

        expect(back).not.toHaveBeenCalled();
        expect(router.currentRoute.value.path).toBe('/more');
    });

    it('SAFE-017 a background refresh does not untick what the user ticked', async () => {
        let refresh: (data: EligibilityRecheckShowOutput) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            refresh = onUpdate as typeof refresh;
            return content;
        });
        writeMock.mockResolvedValueOnce(result({}));
        const { wrapper } = await view();
        await flushPromises();

        const pregnant = content.questions.findIndex((q) => q.item === 'pregnantOrBreastfeeding');
        const diabetes = content.questions.findIndex((q) => q.item === 'diabetes');
        await wrapper.findAll('input[type="checkbox"]')[pregnant]!.setValue(true);
        refresh({ ...content, questions: content.questions.map((q) => ({ ...q, applies: q.item === 'diabetes' })) });
        await flushPromises();

        const boxes = wrapper.findAll<HTMLInputElement>('input[type="checkbox"]');
        expect(boxes[pregnant]!.element.checked).toBe(true);
        // An untouched box follows the server.
        expect(boxes[diabetes]!.element.checked).toBe(true);

        await wrapper.get('[data-test="check"]').trigger('click');
        await flushPromises();
        expect((sent().answers as { item: string; applies: boolean }[]).find((a) => a.item === 'pregnantOrBreastfeeding')?.applies).toBe(true);
    });

    it.each([
        ['got no answer', new CapabilityError('eligibility_recheck', 0, { reason: 'timeout' })],
        ['the server is still running', new CapabilityError('eligibility_recheck', 409, { ok: false, error: { code: 'conflict', retryable: true } })],
    ])('CAP-014 retries a check that %s with the same key and the boxes locked', async (_label, first) => {
        writeMock.mockRejectedValueOnce(first).mockResolvedValueOnce(
            result({ outcome: 'switchToTrainingOnly', message: 'You are now on training only.' }),
        );
        const { wrapper } = await view(content);

        await wrapper.get('[data-test="check"]').trigger('click');
        await flushPromises();
        const open = content.questions.findIndex((q) => !q.measured);
        expect(wrapper.findAll<HTMLInputElement>('input[type="checkbox"]')[open]!.element.disabled).toBe(true);

        await wrapper.get('[data-test="check"]').trigger('click');
        await flushPromises();

        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[0]).toMatch(/^eligibility_recheck:/);
        expect(keys[1]).toBe(keys[0]);
        // The replayed first outcome, not "Nothing changed".
        expect(wrapper.text()).toContain('You are now on training only.');
    });

    it('CAP-014 a refresh that lands while a check is unanswered changes neither the boxes nor what the retry sends', async () => {
        let refresh: (data: EligibilityRecheckShowOutput) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            refresh = onUpdate as typeof refresh;
            return content;
        });
        writeMock.mockRejectedValueOnce(new CapabilityError('eligibility_recheck', 0, { reason: 'timeout' })).mockResolvedValueOnce(result({}));
        const { wrapper } = await view();
        await flushPromises();

        await wrapper.get('[data-test="check"]').trigger('click');
        await flushPromises();
        const shown = () => wrapper.findAll<HTMLInputElement>('input[type="checkbox"]').map((box) => box.element.checked);
        const before = shown();
        // The first check may have saved these answers; the server now shows them back changed.
        refresh({ ...content, questions: content.questions.map((q) => ({ ...q, applies: !q.applies })) });
        await flushPromises();
        expect(shown()).toEqual(before);
        // A refresh can also change the questions themselves; the retry still sends the first check.
        refresh({ ...content, questions: content.questions.slice(1) });
        await flushPromises();

        await wrapper.get('[data-test="check"]').trigger('click');
        await flushPromises();

        const [first, second] = writeMock.mock.calls.map(([, input]) => input);
        expect(second).toEqual(first);
    });

    it('CAP-012 shows a refused check in the server\'s words and keeps the answers', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('eligibility_recheck', 422, { ok: false, error: { message: 'Answer every question.' } }));
        const { wrapper } = await view(content);

        await wrapper.get('[data-test="check"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Answer every question.');
        expect(wrapper.find('[data-test="check"]').exists()).toBe(true);
    });

    it('PWA-024 shows a skeleton while it loads', async () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const { wrapper } = await view();

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(readMock).toHaveBeenCalledWith('eligibility_recheck_show', {}, expect.any(Function), expect.any(Function));
    });

    it('CAP-004 says the screening did not load, and tries again', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('eligibility_recheck_show', 500, null)).mockResolvedValueOnce(content);
        const { wrapper } = await view();
        await flushPromises();
        expect(wrapper.text()).toContain('could not be loaded');

        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain(content.questions[0]!.label);
    });
});
