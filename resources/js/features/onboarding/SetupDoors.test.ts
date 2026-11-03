import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { read, writeNow } from '@/app/call';
import type { AgentStatusShowOutput } from '@/contracts/agent_status_show';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import { screenFixture } from '@/testing/schemas';
import OnboardingView from './OnboardingView.vue';
import { fixture, testRouter } from './onboardingTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));
vi.mock('@/app/install', () => ({ offerInstall: vi.fn(async () => undefined) }));

const readMock = vi.mocked(read);
const status = screenFixture<AgentStatusShowOutput>('agent_status_show');
const goal = fixture('empty');
const glp1 = fixture('glp1');

// The chat itself is CoachChat's; here only whether it is open, and what its close and start do.
const CoachChat = defineComponent({
    name: 'CoachChat',
    props: { open: Boolean, onboarding: Boolean },
    emits: ['close', 'started'],
    setup: (props) => () => h('div', { 'data-test': 'chat', 'data-open': String(props.open), 'data-onboarding': String(props.onboarding) }),
});

/** Setup as the server has it, and the Coach on or off for this instance and user. */
function reads(setup: OnboardingShowOutput, coach: Partial<AgentStatusShowOutput> | null): void {
    readMock.mockImplementation(async (name) => {
        if (name === 'agent_status_show') {
            if (coach === null) throw new Error('offline');
            return { ...status, ...coach };
        }
        return setup;
    });
}

function mountView(router = testRouter()): VueWrapper {
    return mount(OnboardingView, { attachTo: document.body, global: { plugins: [router], stubs: { CoachChat } } });
}

function door(wrapper: VueWrapper, title: string) {
    const button = wrapper.findAll('[data-test="door"]').find((b) => b.text().includes(title));
    if (!button) throw new Error(`No door ${title}`);
    return button;
}

function chatOpen(wrapper: VueWrapper): boolean {
    return wrapper.find('[data-test="chat"]').attributes('data-open') === 'true';
}

describe('the setup doors', () => {
    let wrapper: VueWrapper | undefined;

    beforeEach(() => {
        readMock.mockReset();
        vi.mocked(writeNow).mockReset().mockResolvedValue({});
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
    });

    it('AGT-030 with the Coach on, the first screen offers step by step or the Coach', async () => {
        reads(goal, { enabled: true, on: true });
        wrapper = mountView();
        await flushPromises();

        expect(wrapper.findAll('[data-test="door"]').map((b) => b.text())).toEqual([
            expect.stringContaining('Set up step by step'),
            expect.stringContaining('Talk it through with the Coach'),
        ]);
        expect(wrapper.find('button[data-step="next"]').exists()).toBe(false);
        expect(chatOpen(wrapper)).toBe(false);
    });

    it.each([
        ['the instance has no agent', { enabled: false, on: true }],
        ['the user turned their Coach off', { enabled: true, on: false }],
        ['the Coach status could not be read', null],
    ])('AGT-002 AGT-030 with %s, there is no door and no chat, only the stepper', async (_case, coach) => {
        reads(goal, coach);
        wrapper = mountView();
        await flushPromises();

        expect(wrapper.find('[data-test="door"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="chat"]').exists()).toBe(false);
        expect(wrapper.get('h2').text()).toBe(goal.step.title);
    });

    it('AGT-030 Set up step by step opens the stepper at the saved step', async () => {
        reads(glp1, { enabled: true, on: true });
        wrapper = mountView();
        await flushPromises();

        await door(wrapper, 'Set up step by step').trigger('click');

        expect(wrapper.get('h2').text()).toBe(glp1.step.title);
        expect(chatOpen(wrapper)).toBe(false);
    });

    it('AGT-030 Talk it through with the Coach opens the chat for setup', async () => {
        reads(goal, { enabled: true, on: true });
        wrapper = mountView();
        await flushPromises();

        await door(wrapper, 'Talk it through with the Coach').trigger('click');

        expect(chatOpen(wrapper)).toBe(true);
        expect(wrapper.get('[data-test="chat"]').attributes('data-onboarding')).toBe('true');
    });

    it('AGT-033 leaving the chat shows the stepper at the step the chat reached, with its answers read again', async () => {
        reads(goal, { enabled: true, on: true });
        wrapper = mountView();
        await flushPromises();
        await door(wrapper, 'Talk it through with the Coach').trigger('click');

        // Meanwhile the Coach saved the answers up to step 4.
        reads(glp1, { enabled: true, on: true });
        wrapper.findComponent(CoachChat).vm.$emit('close');
        await flushPromises();

        expect(chatOpen(wrapper)).toBe(false);
        expect(wrapper.find('[data-test="door"]').exists()).toBe(false);
        expect(wrapper.get('h2').text()).toBe(glp1.step.title);
        expect(readMock).toHaveBeenCalledWith('onboarding_show', {}, expect.any(Function), expect.any(Function));
    });

    it('AGT-032 leaving the chat once it started the plan opens Today', async () => {
        const router = testRouter();
        await router.push('/onboarding');
        reads(goal, { enabled: true, on: true });
        wrapper = mountView(router);
        await flushPromises();
        await door(wrapper, 'Talk it through with the Coach').trigger('click');

        wrapper.findComponent(CoachChat).vm.$emit('started');
        expect(router.currentRoute.value.path).toBe('/onboarding');
        wrapper.findComponent(CoachChat).vm.$emit('close');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/');
    });

    it('AGT-030 a setup fixture reads nothing, so it shows the stepper', () => {
        wrapper = mount(OnboardingView, { props: { payload: goal }, attachTo: document.body, global: { plugins: [testRouter()], stubs: { CoachChat } } });

        expect(readMock).not.toHaveBeenCalled();
        expect(wrapper.get('h2').text()).toBe(goal.step.title);
    });
});
