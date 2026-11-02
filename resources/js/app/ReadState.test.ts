import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ReadState from './ReadState.vue';
import { readFailure } from './screenRead';
import { CapabilityError } from './call';

const failed = (status: number) => readFailure(new CapabilityError('token_list', status, null), 'Your tokens could not be loaded.');

describe('ReadState', () => {
    it('PWA-024 shows a skeleton while the read has neither data nor a failure', () => {
        const wrapper = mount(ReadState, { props: { failure: null, what: 'Your tokens' } });

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it.each([
        [419, 'Your session has ended. Sign in again.'],
        [401, 'You are signed out.'],
    ])('CAP-004 a gone session (%i) says so, with no Try again', (status, words) => {
        const wrapper = mount(ReadState, { props: { failure: failed(status), what: 'Your tokens' } });

        expect(wrapper.get('[role="alert"] p').text()).toBe(words);
        expect(wrapper.find('[data-test="retry"]').exists()).toBe(false);
        // ACC-001: the way out of a gone session is the sign-in screen.
        expect(wrapper.get('[data-test="sign-in"]').attributes('href')).toBe('/sign-in');
    });


    it('OFF-003 offline says what needs a connection, with no retry', () => {
        const wrapper = mount(ReadState, { props: { failure: failed(0), what: 'Your tokens' } });

        expect(wrapper.text()).toContain('Not available offline');
        expect(wrapper.text()).toContain("Your tokens will load when you're back online.");
        expect(wrapper.find('[data-test="retry"]').exists()).toBe(false);
    });


    it('CAP-010 a failed read says what did not load and offers Try again', async () => {
        const wrapper = mount(ReadState, { props: { failure: failed(500), what: 'Your tokens' } });

        expect(wrapper.get('[role="alert"]').text()).toContain('Your tokens could not be loaded.');
        expect(wrapper.find('[data-test="sign-in"]').exists()).toBe(false);
        await wrapper.get('[data-test="retry"]').trigger('click');

        expect(wrapper.emitted('retry')).toHaveLength(1);
    });
});
