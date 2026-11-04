import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, expectTypeOf, it } from 'vitest';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import GalleryView from '@/features/dev/GalleryView.vue';
import onboardingEmpty from '../../../tests/fixtures/screens/onboarding_show.empty.json';
import StepFlow, { type StepFlowMark, type StepFlowStep } from './StepFlow.vue';
import StepFlowGallery from './gallery/StepFlow.gallery.vue';

const step: StepFlowStep = {
    counter: 'Step 2 of 4',
    title: 'About you',
    next_title: 'Next',
    can_go_back: true,
    can_continue: true,
};

function flow(extra: Record<string, unknown> = {}, content = '<p>Height</p>', stepExtra: Partial<StepFlowStep> = {}) {
    return mount(StepFlow, { props: { step: { ...step, ...stepExtra }, ...extra }, slots: { default: content } });
}

function back(wrapper: ReturnType<typeof flow>) {
    return wrapper.get('button[data-step="back"]');
}

function next(wrapper: ReturnType<typeof flow>) {
    return wrapper.get('button[data-step="next"]');
}

describe('StepFlow', () => {
    it('UI-007 shows the step counter, the title, the content, then Back and Next', () => {
        const wrapper = flow();

        expect(wrapper.get('p.text-secondary-text').text()).toBe('Step 2 of 4');
        expect(wrapper.get('h2').text()).toBe('About you');
        expect(wrapper.text()).toContain('Height');
        expect(back(wrapper).text()).toBe('Back');
        expect(next(wrapper).text()).toBe('Next');
        expect(wrapper.text()).toBe('Step 2 of 4About youHeight Back Next');
    });

    it("UI-007 takes the server's step payload as it is sent", () => {
        expectTypeOf<OnboardingShowOutput['step']>().toExtend<StepFlowStep>();

        const wrapper = mount(StepFlow, { props: { step: onboardingEmpty.step as OnboardingShowOutput['step'] } });

        expect(wrapper.get('h2').text()).toBe(onboardingEmpty.step.title);
        expect(next(wrapper).text()).toBe(onboardingEmpty.step.next_title);
        expect(back(wrapper).attributes('disabled')).toBeDefined();
        expect(next(wrapper).attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Pick a goal and answer the question to continue.');
    });

    it('UI-007 the server names the last button, such as Done', () => {
        expect(next(flow({}, '<p>Height</p>', { next_title: 'Done' })).text()).toBe('Done');
    });

    it('UI-007 Back and Next emit back and next', async () => {
        const wrapper = flow();

        await back(wrapper).trigger('click');
        await next(wrapper).trigger('click');

        expect(wrapper.emitted('back')).toHaveLength(1);
        expect(wrapper.emitted('next')).toHaveLength(1);
    });

    it('UI-007 Back is off on the first step', async () => {
        const wrapper = flow({}, '<p>Height</p>', { can_go_back: false });

        expect(back(wrapper).attributes('disabled')).toBeDefined();
        await back(wrapper).trigger('click');
        expect(wrapper.emitted('back')).toBeUndefined();
    });

    it('UI-007 Next is off while the step cannot continue', async () => {
        const wrapper = flow({}, '<p>Height</p>', { can_continue: false });

        expect(next(wrapper).attributes('disabled')).toBeDefined();
        expect(back(wrapper).attributes('disabled')).toBeUndefined();
        await next(wrapper).trigger('click');
        expect(wrapper.emitted('next')).toBeUndefined();
    });

    it("UI-030 the server's hint says why Next is off and describes the Next button", () => {
        const wrapper = flow({}, '<p>Height</p>', { can_continue: false, continue_hint: 'Pick a goal to continue.' });
        const hint = wrapper.get(`#${next(wrapper).attributes('aria-describedby')}`);

        expect(hint.text()).toBe('Pick a goal to continue.');
    });

    it('UI-007 the hint is hidden while Next is on', () => {
        const wrapper = flow({}, '<p>Height</p>', { continue_hint: 'Pick a goal to continue.' });

        expect(wrapper.text()).not.toContain('Pick a goal');
        expect(next(wrapper).attributes('aria-describedby')).toBeUndefined();
    });

    it('UI-007 Next is the accent fill and both buttons are at least the tap size', () => {
        const wrapper = flow();

        expect(next(wrapper).classes()).toEqual(expect.arrayContaining(['bg-accent', 'text-on-accent', 'min-h-tap']));
        expect(back(wrapper).classes()).toEqual(expect.arrayContaining(['text-accent', 'min-h-tap']));
        expect(wrapper.classes()).toContain('bg-surface');
    });

    it('UI-030 the flow is a group named by its title, and the title is a heading', () => {
        const wrapper = flow();
        const heading = wrapper.get('h2');

        expect(wrapper.attributes('role')).toBe('group');
        expect(wrapper.attributes('aria-labelledby')).toBe(heading.attributes('id'));
    });

    it('VIEW-004 empty: no content leaves the counter, title and buttons', () => {
        const wrapper = flow({}, '');

        expect(wrapper.text()).toBe('Step 2 of 4About you Back Next');
    });

    it('VIEW-004 loading: the content says Loading, the flow is busy and both buttons are off', () => {
        const wrapper = flow({ loading: true, pending: true }, '<p>Height</p>', {
            can_continue: false,
            continue_hint: 'Pick a goal to continue.',
        });

        expect(wrapper.attributes('aria-busy')).toBe('true');
        expect(wrapper.text()).toContain('Loading');
        expect(wrapper.text()).not.toContain('Height');
        expect(wrapper.text()).not.toContain('Waiting to sync');
        expect(wrapper.text()).not.toContain('Pick a goal');
        expect(wrapper.find('.animate-pulse').exists()).toBe(true);
        expect(back(wrapper).attributes('disabled')).toBeDefined();
        expect(next(wrapper).attributes('disabled')).toBeDefined();
    });

    it("VIEW-004 error: the caller's message shows in place of the content, Back stays on and Next is off", async () => {
        const wrapper = flow({ error: "You're offline" });

        expect(wrapper.get('[role="alert"]').text()).toBe("You're offline");
        expect(wrapper.text()).not.toContain('Height');
        expect(back(wrapper).attributes('disabled')).toBeUndefined();
        expect(next(wrapper).attributes('disabled')).toBeDefined();
        await back(wrapper).trigger('click');
        expect(wrapper.emitted('back')).toHaveLength(1);
    });

    it('VIEW-004 pending: the step says Waiting to sync and stays usable (OFF-008)', async () => {
        const wrapper = flow({ pending: true });

        expect(wrapper.get('[role="status"]').text()).toBe('Waiting to sync');
        expect(wrapper.text()).toContain('Height');
        expect(back(wrapper).attributes('disabled')).toBeUndefined();
        expect(next(wrapper).attributes('disabled')).toBeUndefined();
        await back(wrapper).trigger('click');
        await next(wrapper).trigger('click');
        expect(wrapper.emitted('back')).toHaveLength(1);
        expect(wrapper.emitted('next')).toHaveLength(1);
    });

    it('VIEW-004 pending and error show together', () => {
        const wrapper = flow({ pending: true, error: "You're offline" });

        expect(wrapper.get('[role="alert"]').text()).toBe("You're offline");
        expect(wrapper.get('[role="status"]').text()).toBe('Waiting to sync');
    });

    it('VIEW-004 disabled: the flow is greyed out, marked disabled and both buttons are off', async () => {
        const wrapper = flow({ disabled: true });

        expect(wrapper.attributes('aria-disabled')).toBe('true');
        expect(wrapper.classes()).toContain('opacity-50');
        expect(back(wrapper).attributes('disabled')).toBeDefined();
        expect(next(wrapper).attributes('disabled')).toBeDefined();
        await next(wrapper).trigger('click');
        expect(wrapper.emitted('next')).toBeUndefined();
    });

    describe('ONB-022 the step track', () => {
        const track: StepFlowMark[] = [
            { step: 1, label: 'Goal', state: 'done', can_open: true },
            { step: 2, label: 'About you', state: 'current', can_open: false },
            { step: 3, label: 'Health check', state: 'todo', can_open: false },
            { step: 14, label: 'Review', state: 'todo', can_open: false },
        ];

        function tracked() {
            return mount(StepFlow, { props: { step: { ...step, track } }, slots: { default: '<p>Height</p>' }, attachTo: document.body });
        }

        it('ONB-022 UI-007 a segmented bar under the counter marks each step done, current or to come', () => {
            const wrapper = tracked();
            const marks = wrapper.findAll('[data-track-bar] [data-mark]');

            expect(marks.map((m) => m.attributes('data-mark'))).toEqual(['done', 'current', 'todo', 'todo']);
            expect(marks[0]!.classes()).toContain('bg-accent');
            expect(marks[2]!.classes()).toContain('bg-accent/15');
            // The current mark is the long one, so "you are here" reads apart from the done marks.
            expect(marks[1]!.classes()).toContain('flex-[3]');
            expect(marks[0]!.classes()).not.toContain('flex-[3]');
            // The current mark's fill grows in from the left as the step opens (a transform, PWA-021).
            expect(marks[1]!.get('[data-fill]').classes()).toEqual(expect.arrayContaining(['origin-left', 'bg-accent']));
            wrapper.unmount();
        });

        it('ONB-022 the list beside the step names every step; a done step opens it and the others are not buttons', async () => {
            const wrapper = tracked();
            const rail = wrapper.get('nav[data-track-rail]');
            const items = rail.findAll('li');

            expect(rail.attributes('aria-label')).toBe('Steps');
            expect(items.map((li) => li.text())).toEqual(['Goal', 'About you', 'Health check', 'Review']);
            expect(items[0]!.find('button').exists()).toBe(true);
            expect(items[1]!.find('button').exists()).toBe(false);
            expect(items[1]!.get('[aria-current]').attributes('aria-current')).toBe('step');
            expect(items[2]!.find('button').exists()).toBe(false);
            expect(items[0]!.get('button').attributes('aria-label')).toBe('Goal, done. Go back to this step');

            await items[0]!.get('button').trigger('click');
            expect(wrapper.emitted('open')).toEqual([[1]]);
            wrapper.unmount();
        });

        it('ONB-022 on a phone, All steps opens the list in a sheet, and a done step there closes it and opens the step', async () => {
            const wrapper = tracked();
            const all = wrapper.get('button[data-track-all]');

            expect(all.text()).toBe('All steps');
            expect(all.classes()).toEqual(expect.arrayContaining(['md:hidden', 'min-h-tap']));
            expect(wrapper.get('[data-track-bar]').classes()).toContain('md:hidden');
            expect(wrapper.get('nav[data-track-rail]').classes()).toEqual(expect.arrayContaining(['hidden', 'md:block']));

            await all.trigger('click');
            const sheet = document.body.querySelector<HTMLElement>('[role="dialog"]');
            expect(sheet?.textContent).toContain('Health check');
            sheet!.querySelector<HTMLButtonElement>('li button')!.click();
            await flushPromises();

            expect(wrapper.emitted('open')).toEqual([[1]]);
            expect(document.body.querySelector('[role="dialog"]')).toBeNull();
            wrapper.unmount();
        });

        it('UI-007 a flow with no track has no bar, no list and no All steps', () => {
            const wrapper = flow();

            expect(wrapper.find('[data-track-bar]').exists()).toBe(false);
            expect(wrapper.find('nav[data-track-rail]').exists()).toBe(false);
            expect(wrapper.find('button[data-track-all]').exists()).toBe(false);
        });
    });

    it('VIEW-004 ONB-022 the gallery lists StepFlow with each state and a track', () => {
        expect(
            mount(GalleryView)
                .findAll('h2')
                .map((h) => h.text()),
        ).toContain('StepFlow');

        const gallery = mount(StepFlowGallery);
        expect(gallery.findAll('h3').map((h) => h.text())).toEqual([
            'default',
            'last step',
            'empty',
            'loading',
            'error',
            'pending',
            'disabled',
            'track',
        ]);
        expect(gallery.findAllComponents(StepFlow)).toHaveLength(8);
    });
});
