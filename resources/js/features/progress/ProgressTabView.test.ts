import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import schema from '../../../../tests/fixtures/schemas/progress_show.input.json';
import content from '../../../../tests/fixtures/screens/progress_show.json';
import scopeEmpty from '../../../../tests/fixtures/screens/progress_show.scope_empty.json';
import { expectMatchesInputSchema } from './inputSchema.testing';
import ProgressTabView from './ProgressTabView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
const full = content as ProgressShowOutput;

function render(props: Record<string, unknown> = {}) {
    return mount(ProgressTabView, { props, global: { stubs: { RouterLink: RouterLinkStub } } });
}

describe('ProgressTabView', () => {
    beforeEach(() => readMock.mockReset());

    it('CAP-001 renders the payload it is given without calling the server', () => {
        const wrapper = render({ payload: full });

        expect(wrapper.text()).toContain('83.2 kg');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-004 reads progress_show, showing skeletons until it answers', async () => {
        readMock.mockResolvedValueOnce(full);
        const wrapper = render();

        expect(wrapper.find('[data-placeholder]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('progress_show', {}, expect.any(Function), expect.any(Function));
        expectMatchesInputSchema(schema, readMock.mock.calls[0]![1]!);
        expect(wrapper.text()).toContain('+1.1 kg');
    });

    it('ANA-001 reads the picked scope', async () => {
        readMock.mockResolvedValueOnce(full).mockResolvedValueOnce(scopeEmpty);
        const wrapper = render();
        await flushPromises();

        await wrapper.findAll('[data-scope]')[1]!.trigger('click');
        await flushPromises();

        const input = readMock.mock.calls[1]![1]!;
        expect(input).toEqual({ scope: 'plan' });
        expectMatchesInputSchema(schema, input);
        expect(wrapper.text()).toContain('No weigh-ins in this phase yet');
    });

    it('ANA-001 reads the picked scope even when the screen was handed its payload', async () => {
        readMock.mockResolvedValueOnce(scopeEmpty);
        const wrapper = render({ payload: full });

        await wrapper.findAll('[data-scope]')[1]!.trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('progress_show', { scope: 'plan' }, expect.any(Function), expect.any(Function));
        expect(wrapper.text()).toContain('No weigh-ins in this phase yet');
    });

    it.each([
        [new CapabilityError('progress_show', 0, null), 'Progress needs a connection.'],
        [new CapabilityError('progress_show', 401, null), 'You are signed out.'],
        [new CapabilityError('progress_show', 500, null), 'Progress could not be loaded.'],
    ])('CAP-012 says why progress did not load', async (error, message) => {
        readMock.mockRejectedValueOnce(error);
        const wrapper = render();
        await flushPromises();

        expect(wrapper.text()).toContain(message);
        expect(wrapper.find('[data-placeholder]').exists()).toBe(false);
    });

    it('OFF-003 shows the cached progress at once and then the refreshed one', async () => {
        let refresh: (data: ProgressShowOutput) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            refresh = onUpdate as typeof refresh;
            return scopeEmpty;
        });
        const wrapper = render();
        await flushPromises();
        expect(wrapper.text()).toContain('No weigh-ins in this phase yet');

        refresh(full);
        await flushPromises();

        expect(wrapper.text()).not.toContain('No weigh-ins in this phase yet');
        expect(wrapper.text()).toContain('+1.1 kg');
    });

    it('OFF-008 passes pending through to the screen', () => {
        const wrapper = render({ payload: full, pending: true });

        expect(wrapper.get('[data-summary]').classes()).toContain('opacity-50');
    });
});
