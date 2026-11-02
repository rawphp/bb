import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { TemplatesShowOutput } from '@/contracts/templates_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import PriorityBarsView from '../PriorityBarsView.vue';
import TemplateDetailView from './TemplateDetailView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
const templates = screenFixture<TemplatesShowOutput>('templates_show');
const noTemplate = screenFixture<TemplatesShowOutput>('templates_show.empty');
const detail = templates.detail!;

function show(props: Record<string, unknown> = { payload: templates }) {
    return mount(TemplateDetailView, { props, global: { stubs: { RouterLink: RouterLinkStub } } });
}

describe('TemplateDetailView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(templates);
    });

    it('MESO-012 MESO-015 shows the name, split, and each day with its slots and sets', () => {
        const wrapper = show();

        expect(wrapper.find('h1').text()).toBe('Full Body 2-Day Essentials');
        expect(wrapper.text()).toContain('2 days/week · Full body');
        const days = wrapper.findAll('[data-test="day"]');
        expect(days.map((d) => d.attributes('aria-label'))).toEqual(['Full Body A', 'Full Body B']);
        expect(days[0]!.findAll('[data-test="slot"]')).toHaveLength(7);
        expect(days[0]!.findAll('[data-test="slot"]')[0]!.text()).toContain('Barbell Back Squat');
        expect(days[0]!.findAll('[data-test="slot"]')[0]!.text()).toContain('Quads');
        expect(days[0]!.findAll('[data-test="slot"]')[0]!.text()).toContain('3 sets');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('MESO-012 shows the muscle priorities with bars and the weekly sets per muscle', () => {
        const wrapper = show();
        const priorities = wrapper.find('section[aria-label="Muscle priorities"]');
        const weekly = wrapper.find('section[aria-label="Weekly sets per muscle"]');

        expect(priorities.findAll('[data-test="priority"]')).toHaveLength(detail.priorities.length);
        expect(priorities.findAllComponents(PriorityBarsView)[0]!.props('bars')).toBe(detail.priorities[0]!.bars);
        expect(priorities.text()).toContain(detail.priorities[0]!.priority_title);
        expect(weekly.findAll('[data-test="weekly"]')).toHaveLength(detail.weekly_sets.length);
        expect(weekly.text()).toContain(detail.weekly_sets[0]!.muscle_title);
        expect(weekly.text()).toContain(detail.weekly_sets[0]!.sets_text);
    });

    it('MESO-012 reads the template it is opened for, with a skeleton first', async () => {
        const wrapper = show({ templateId: detail.id });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('templates_show', { template_id: detail.id }, expect.any(Function), expect.any(Function));
        expect(inputViolations('templates_show', readMock.mock.calls[0]![1])).toEqual([]);
        expect(wrapper.find('h1').text()).toBe(detail.name);
    });

    it('MESO-015 links back to the library, unless the build flow embeds it', () => {
        expect(show().findAllComponents(RouterLinkStub)[0]!.props('to')).toBe('/train/templates');

        const embedded = show({ payload: templates, embedded: true });
        expect(embedded.find('h1').exists()).toBe(false);
        expect(embedded.findAllComponents(RouterLinkStub)).toHaveLength(0);
        expect(embedded.text()).toContain('Full Body A');
    });

    it('MESO-012 says so when the template is not found', () => {
        const wrapper = show({ payload: noTemplate });

        expect(wrapper.text()).toContain('This template could not be found.');
    });

    it('CAP-002 says when the template could not load, and Try again reads it', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('templates_show', 500, null));
        const wrapper = show({ templateId: detail.id });
        await flushPromises();

        expect(wrapper.text()).toContain('The template could not be loaded.');
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('h1').text()).toBe(detail.name);
    });

    it('CAP-004 says when the user is signed out', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('templates_show', 401, null));
        const wrapper = show({ templateId: detail.id });
        await flushPromises();

        expect(wrapper.text()).toContain('You are signed out.');
    });
});
