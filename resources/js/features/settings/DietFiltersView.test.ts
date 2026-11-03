import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { SettingsShowOutput } from '@/contracts/settings_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import DietFiltersView from './DietFiltersView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const settings = screenFixture<SettingsShowOutput>('settings_show');
const empty = screenFixture<SettingsShowOutput>('settings_show.empty');

function render(payload?: SettingsShowOutput) {
    return mount(DietFiltersView, { props: payload ? { payload } : {} });
}

function sent(): unknown {
    const call = writeMock.mock.calls.find(([name]) => name === 'diet_filters_set')!;
    expect(inputViolations('diet_filters_set', call[1])).toEqual([]);
    return call[1];
}

describe('DietFiltersView', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
    });

    it('SET-006 lists every filter the server offers and ticks the ones on', () => {
        const wrapper = render(settings);

        const rows = wrapper.findAll('[data-test^="filter-"]');
        expect(rows.map((row) => row.text())).toEqual(settings.diet_filters.choices.map((choice) => choice.label));
        expect(wrapper.get('[data-test="filter-dairy_free"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.get('[data-test="filter-vegan"]').attributes('aria-pressed')).toBe('false');
        expect(wrapper.text()).toContain('Foods that do not match every filter you pick are hidden.');
    });

    it('SET-006 turns a filter on beside the ones already on', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(settings);
        const wrapper = render(settings);

        await wrapper.get('[data-test="filter-vegan"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual({ filters: [{ filter: 'dairy_free' }, { filter: 'vegan' }] });
    });

    it('SET-006 turns a filter off', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(empty);
        const wrapper = render(settings);

        await wrapper.get('[data-test="filter-dairy_free"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual({ filters: [] });
        expect(wrapper.get('[data-test="filter-dairy_free"]').attributes('aria-pressed')).toBe('false');
    });

    it('CAP-012 keeps the filters as the server holds them and says why', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('diet_filters_set', 0, { reason: 'network' }));
        const wrapper = render(settings);

        await wrapper.get('[data-test="filter-vegan"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="filter-vegan"]').attributes('aria-pressed')).toBe('false');
        expect(wrapper.get('[role="alert"]').text()).toContain('No connection');
    });

    it('PWA-024 shows a skeleton while it loads', () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));

        expect(render().find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-004 says the filters did not load', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('settings_show', 500, null));
        const wrapper = render();
        await flushPromises();

        expect(wrapper.text()).toContain('Your diet filters could not be loaded.');
    });
});
