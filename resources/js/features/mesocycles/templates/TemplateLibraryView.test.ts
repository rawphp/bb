import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { TemplatesShowOutput } from '@/contracts/templates_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import TemplateLibraryView from './TemplateLibraryView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
const templates = screenFixture<TemplatesShowOutput>('templates_show');
const empty = screenFixture<TemplatesShowOutput>('templates_show.empty');

function show(props: Record<string, unknown> = { payload: templates }) {
    return mount(TemplateLibraryView, { props, global: { stubs: { RouterLink: RouterLinkStub } } });
}

async function filter(wrapper: ReturnType<typeof show>, key: string, label: string): Promise<void> {
    const select = wrapper.find(`select[aria-label="${key} filter"]`).element as HTMLSelectElement;
    select.selectedIndex = [...select.options].findIndex((o) => o.text === label);
    await wrapper.find(`select[aria-label="${key} filter"]`).trigger('change');
    await flushPromises();
}

describe('TemplateLibraryView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(templates);
    });

    it('MESO-010 MESO-015 lists each template with its name and split, linking to its detail', () => {
        const wrapper = show();
        const rows = wrapper.findAll('[data-test="template"]');

        expect(wrapper.find('h1').text()).toBe('Templates');
        expect(rows).toHaveLength(8);
        expect(rows[0]!.text()).toContain('Full Body 2-Day Essentials');
        expect(rows[0]!.text()).toContain('2 days/week · Full body');
        expect(rows[7]!.text()).toContain('6 days/week · Push/pull/legs');
        expect(wrapper.findAllComponents(RouterLinkStub)[0]!.props('to')).toBe('/train/templates/01J9TPL0000000000000000000');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('MESO-011 shows a filter chip for days, sex, focus and phase, filled when it is active', () => {
        const wrapper = show();

        expect(wrapper.findAll('select').map((s) => s.attributes('aria-label'))).toEqual(['Days filter', 'Sex filter', 'Focus filter', 'Phase filter']);
        const chips = wrapper.findAll('[data-test="filter"]');
        expect(chips.map((c) => c.text())).toEqual(expect.arrayContaining(['Days: All', 'Sex: All', 'Focus: All', 'Gain']));
        expect(chips[3]!.classes()).toContain('bg-accent');
        expect(chips[0]!.classes()).not.toContain('bg-accent');
        const days = wrapper.find('select[aria-label="Days filter"]').findAll('option');
        expect(days.map((o) => o.text())).toEqual(['All', '2 days/week', '3 days/week', '4 days/week', '5 days/week', '6 days/week']);
        expect((wrapper.find('select[aria-label="Phase filter"]').element as HTMLSelectElement).selectedOptions[0]!.text).toBe('Gain');
    });

    it('MESO-011 reads again with the filter picked, All clearing it and the phase All sent as all', async () => {
        const wrapper = show();

        await filter(wrapper, 'Days', '4 days/week');
        expect(readMock).toHaveBeenLastCalledWith('templates_show', { days: '4' }, expect.any(Function), expect.any(Function));

        await filter(wrapper, 'Phase', 'All');
        expect(readMock).toHaveBeenLastCalledWith('templates_show', { days: '4', phase: 'all' }, expect.any(Function), expect.any(Function));

        await filter(wrapper, 'Days', 'All');
        expect(readMock).toHaveBeenLastCalledWith('templates_show', { days: null, phase: 'all' }, expect.any(Function), expect.any(Function));
        for (const [, input] of readMock.mock.calls) expect(inputViolations('templates_show', input)).toEqual([]);
    });

    it('MESO-011 shows what the read answers for the filter picked', async () => {
        readMock.mockResolvedValue(empty);
        const wrapper = show();

        await filter(wrapper, 'Days', '6 days/week');

        expect(wrapper.findAll('[data-test="template"]')).toHaveLength(0);
        expect(wrapper.text()).toContain('No templates match these filters.');
    });

    it('MESO-010 says why there are no templates (templates_show.empty)', () => {
        const wrapper = show({ payload: empty });

        expect(wrapper.text()).toContain('No templates match these filters.');
        expect(wrapper.find('select[aria-label="Days filter"]').exists()).toBe(true);
    });

    it('MESO-010 offers a row to the build flow instead of linking when picking', async () => {
        const wrapper = show({ payload: templates, picking: true });

        expect(wrapper.find('h1').exists()).toBe(false);
        expect(wrapper.findAllComponents(RouterLinkStub)).toHaveLength(0);
        await wrapper.findAll('[data-test="template"]')[1]!.trigger('click');
        expect(wrapper.emitted('open')).toEqual([['01J9TPL0100000000000000000']]);
    });

    it('PWA-024 reads the library with a skeleton while it loads', async () => {
        const wrapper = show({});
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('templates_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.findAll('[data-test="template"]')).toHaveLength(8);
    });

    it('CAP-002 says when the templates could not load, and Try again reads them', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('templates_show', 500, null));
        const wrapper = show({});
        await flushPromises();

        expect(wrapper.text()).toContain('The templates could not be loaded.');
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('[data-test="template"]')).toHaveLength(8);
    });

    it('CAP-004 says when the user is signed out', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('templates_show', 401, null));
        const wrapper = show({});
        await flushPromises();

        expect(wrapper.text()).toContain('You are signed out.');
    });
});
