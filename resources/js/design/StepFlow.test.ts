import { mount } from '@vue/test-utils';
import { describe, expect, expectTypeOf, it } from 'vitest';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import GalleryView from '@/features/dev/GalleryView.vue';
import onboardingEmpty from '../../../tests/fixtures/screens/onboarding_show.empty.json';
import StepFlow, { type StepFlowStep } from './StepFlow.vue';
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

        const wrapper = mount(StepFlow, { props: { step: onboardingEmpty.step } });

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

    it('VIEW-004 the gallery lists StepFlow with each state', () => {
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
        ]);
        expect(gallery.findAllComponents(StepFlow)).toHaveLength(7);
    });
});
