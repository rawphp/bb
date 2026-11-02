import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ReadState from './ReadState.vue';

describe('ReadState', () => {
    it('PWA-024 shows a skeleton while the read has neither data nor a failure', () => {
        const wrapper = mount(ReadState, { props: { failure: null, what: 'Your tokens' } });

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-004 says the user is signed out', () => {
        const wrapper = mount(ReadState, { props: { failure: 'signed-out', what: 'Your tokens' } });

        expect(wrapper.get('[role="alert"]').text()).toBe('You are signed out.');
    });

    it('OFF-003 offline says what needs a connection, with no retry', () => {
        const wrapper = mount(ReadState, { props: { failure: 'offline', what: 'Your tokens' } });

        expect(wrapper.text()).toContain('Not available offline');
        expect(wrapper.text()).toContain("Your tokens will load when you're back online.");
        expect(wrapper.find('[data-test="retry"]').exists()).toBe(false);
    });

    it('PWA-024 a screen can give its own placeholder', () => {
        const wrapper = mount(ReadState, { props: { failure: null, what: 'Learn' }, slots: { loading: '<div data-test="own" />' } });

        expect(wrapper.find('[data-test="own"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(false);
    });

    it('CAP-010 a failed read says what did not load and offers Try again', async () => {
        const wrapper = mount(ReadState, { props: { failure: 'error', what: 'Your tokens' } });

        expect(wrapper.get('[role="alert"]').text()).toContain('Your tokens could not be loaded.');
        await wrapper.get('[data-test="retry"]').trigger('click');

        expect(wrapper.emitted('retry')).toHaveLength(1);
    });
});
