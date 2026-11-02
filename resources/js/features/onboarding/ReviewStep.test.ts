import { flushPromises, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import { offerInstall } from '@/app/install';
import { toneIcons } from '@/design/toneIcons';
import { back, fixture, next, sent, testRouter, view, writes } from './onboardingTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));
vi.mock('@/app/install', () => ({ offerInstall: vi.fn(async () => undefined) }));

const review = fixture();
const goal = fixture('empty');

describe('the review', () => {
    let wrapper: VueWrapper | undefined;

    beforeEach(() => {
        wrapper?.unmount();
        vi.mocked(read).mockReset().mockResolvedValue(review);
        vi.mocked(write).mockReset().mockResolvedValue({});
        vi.mocked(offerInstall).mockClear();
    });

    it('ONB-018 summarises every section of the plan with its rows, and an Edit link for each', () => {
        wrapper = view(review);

        expect(wrapper.get('h2').text()).toBe('Review your plan');
        for (const section of review.review.sections) {
            expect(wrapper.text()).toContain(section.title);
            for (const row of section.rows) expect(wrapper.text()).toContain(row.value);
            expect(wrapper.find(`button[aria-label="Edit ${section.title}"]`).exists()).toBe(section.can_edit);
        }
    });

    it('ONB-018 UI-004 shows the calorie target marked Selected next to Recommended, as status pills in the server\'s tones', () => {
        wrapper = view(review);

        const calories = wrapper.findAll('[data-test="review-section"]').at(-1)!;
        const icons = calories.findAll('svg path').map((path) => path.attributes('d'));
        expect(calories.text()).toContain('Recommended');
        expect(calories.text()).toContain('Selected');
        expect(icons).toEqual([toneIcons.good, toneIcons.info]);
    });

    it('ONB-019 says exact meal times and amounts can be fine-tuned after starting', () => {
        wrapper = view(review);

        expect(wrapper.text()).toContain('Exact meal times and amounts can be fine-tuned after you start.');
    });

    it('ONB-018 a section that cannot be edited has no Edit link', () => {
        const fixed = structuredClone(review);
        fixed.review.sections[0]!.can_edit = false;
        wrapper = view(fixed);

        expect(wrapper.find('button[aria-label="Edit Goal"]').exists()).toBe(false);
    });

    it('ONB-018 Edit goes back to the section\'s step', async () => {
        vi.mocked(read).mockResolvedValue(goal);
        wrapper = view(review);

        await wrapper.get('button[aria-label="Edit Phase"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([{ answers: {}, move: 'edit', edit_step: 6 }]);
        expect(wrapper.get('h2').text()).toBe(goal.step.title);
    });

    it('SAFE-003 shows the training-only notice the server sends on the review', () => {
        const trainingOnly = structuredClone(review);
        trainingOnly.training_only = true;
        trainingOnly.notices = [{ text: 'Nutrition targets are turned off.', tone: 'warning' }];
        wrapper = view(trainingOnly);

        expect(wrapper.text()).toContain('Nutrition targets are turned off.');
    });

    it('OFF-008 marks the summary while a change waits to sync', () => {
        wrapper = view(review, { pending: true });

        expect(wrapper.text()).toContain('Waiting to sync');
        const value = wrapper.findAll('span.text-right').find((span) => span.text() === 'Fat loss');
        expect(value!.element.parentElement!.classList.contains('opacity-60')).toBe(true);
    });

    it('PWA-024 shows a skeleton while the review loads', () => {
        vi.mocked(read).mockReturnValueOnce(new Promise(() => {}));
        wrapper = view();

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-004 says setup did not load, and tries again', async () => {
        vi.mocked(read).mockRejectedValueOnce(new CapabilityError('onboarding_show', 500, null)).mockResolvedValueOnce(review);
        wrapper = view();
        await flushPromises();
        expect(wrapper.text()).toContain('could not be loaded');

        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('h2').text()).toBe('Review your plan');
    });

    describe('Start my plan', () => {
        it('ONB-020 shows the starting screen while onboarding_complete builds the plan, then opens Today (ONB-030)', async () => {
            const router = testRouter();
            await router.push('/onboarding');
            let finish: (value: unknown) => void = () => {};
            vi.mocked(write).mockReturnValueOnce(new Promise((resolve) => (finish = resolve)));
            wrapper = view(review, { router });

            await next(wrapper).trigger('click');

            expect(writes().map(([name, input]) => [name, input])).toEqual([['onboarding_complete', {}]]);
            expect(wrapper.get('[role="status"]').text()).toContain('Building your plan');
            expect(wrapper.text()).toContain('Working out your targets and saving your plan.');
            expect(wrapper.find('button[data-step="next"]').exists()).toBe(false);
            finish({});
            await flushPromises();

            expect(router.currentRoute.value.fullPath).toBe('/');
            expect(read).not.toHaveBeenCalled();
        });

        it('ONB-020 Today replaces setup in history, so Back does not return to it', async () => {
            const router = testRouter();
            await router.push('/');
            await router.push('/onboarding');
            const replace = vi.spyOn(router, 'replace');
            wrapper = view(review, { router });

            await next(wrapper).trigger('click');
            await flushPromises();

            expect(replace).toHaveBeenCalledWith('/');
        });

        it('PWA-003 offers the install on the Start my plan tap, before the plan is sent', async () => {
            vi.mocked(write).mockReturnValueOnce(new Promise(() => {}));
            wrapper = view(review);

            await next(wrapper).trigger('click');

            expect(offerInstall).toHaveBeenCalledOnce();
            expect(vi.mocked(offerInstall).mock.invocationCallOrder[0]).toBeLessThan(vi.mocked(write).mock.invocationCallOrder[0]!);
        });

        it('CAP-012 after a refused start the answers open again, and the reason goes once one is saved', async () => {
            vi.mocked(write).mockRejectedValueOnce(new CapabilityError('onboarding_complete', 422, { ok: false, error: { message: 'Answer every step first.' } }));
            vi.mocked(read).mockResolvedValue(goal);
            wrapper = view(review);

            await next(wrapper).trigger('click');
            await flushPromises();
            await wrapper.get('button[aria-label="Edit Goal"]').trigger('click');
            await flushPromises();

            expect(wrapper.get('h2').text()).toBe(goal.step.title);
            expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        });

        it('CAP-014 after a start that got no answer, Edit and Back stay off, so a retry replays the answers it was sent with', async () => {
            vi.mocked(write).mockRejectedValueOnce(new CapabilityError('onboarding_complete', 0, { reason: 'timeout' }));
            wrapper = view(review);

            await next(wrapper).trigger('click');
            await flushPromises();
            await wrapper.get('button[aria-label="Edit Phase"]').trigger('click');
            await back(wrapper).trigger('click');
            await flushPromises();

            expect(wrapper.get('button[aria-label="Edit Phase"]').attributes('disabled')).toBeDefined();
            expect(back(wrapper).attributes('disabled')).toBeDefined();
            expect(next(wrapper).attributes('disabled')).toBeUndefined();
            expect(writes().map(([name]) => name)).toEqual(['onboarding_complete']);
        });

        it('CAP-012 a refused start stays on the review with the server\'s reason', async () => {
            const router = testRouter();
            await router.push('/onboarding');
            vi.mocked(write).mockRejectedValueOnce(new CapabilityError('onboarding_complete', 422, { ok: false, error: { message: 'Answer every step first.' } }));
            wrapper = view(review, { router });

            await next(wrapper).trigger('click');
            await flushPromises();

            expect(wrapper.get('[role="alert"]').text()).toBe('Answer every step first.');
            expect(wrapper.get('h2').text()).toBe('Review your plan');
            expect(router.currentRoute.value.fullPath).toBe('/onboarding');
        });

        it('CAP-014 a start that got no answer, pressed again, is sent with the same key', async () => {
            vi.mocked(write).mockRejectedValueOnce(new CapabilityError('onboarding_complete', 0, { reason: 'timeout' }));
            wrapper = view(review);

            await next(wrapper).trigger('click');
            await flushPromises();
            expect(wrapper.get('[role="alert"]').text()).toContain('may have worked');
            await next(wrapper).trigger('click');
            await flushPromises();

            const keys = vi.mocked(write).mock.calls.map(([, , options]) => options?.idempotencyKey);
            expect(keys).toHaveLength(2);
            expect(keys[0]).toBe(keys[1]);
        });

        it('CAP-010 a second tap while the plan is building sends nothing more, and Back is gone', async () => {
            vi.mocked(write).mockReturnValueOnce(new Promise(() => {}));
            wrapper = view(review);

            await next(wrapper).trigger('click');
            await flushPromises();

            expect(write).toHaveBeenCalledOnce();
            expect(wrapper.find('button[data-step="back"]').exists()).toBe(false);
        });

        it('ONB-002 Back on the review still goes back a step', async () => {
            wrapper = view(review);

            await back(wrapper).trigger('click');
            await flushPromises();

            expect(sent()).toEqual([{ answers: {}, move: 'back' }]);
        });
    });
});
