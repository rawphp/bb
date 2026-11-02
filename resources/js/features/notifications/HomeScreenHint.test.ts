import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import HomeScreenHint from './HomeScreenHint.vue';

describe('HomeScreenHint', () => {
    it('NOTIF-010 explains on iPhone that notifications need the app on the home screen', () => {
        expect(mount(HomeScreenHint, { props: { show: true } }).text()).toContain('add Bodybuilder to your Home Screen');
    });

    it('NOTIF-010 shows nothing elsewhere', () => {
        expect(mount(HomeScreenHint, { props: { show: false } }).text()).toBe('');
    });
});
