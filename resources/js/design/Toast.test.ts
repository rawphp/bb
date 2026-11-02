import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, write } from '@/app/call';
import GalleryView from '@/features/dev/GalleryView.vue';
import Toast from './Toast.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), write: vi.fn() }));

const writeMock = vi.mocked(write);

const message = 'Meals 3 and 4 re-targeted';

function toast(props: Record<string, unknown> = {}) {
    return mount(Toast, { props: { message, token: 'tok-1', ...props } });
}

function undoButton(wrapper: ReturnType<typeof toast>) {
    return wrapper.get('button');
}

describe('Toast', () => {
    beforeEach(() => writeMock.mockReset());

    it('UI-010 shows the message and an Undo button inside a status region', () => {
        const wrapper = toast();

        expect(wrapper.get('[role="status"]').text()).toContain(message);
        expect(undoButton(wrapper).text()).toBe('Undo');
    });

    it('UI-010 Undo calls undo_apply with the token and emits undone with the result', async () => {
        const result = { invalidates: ['nutrition_show:{}'] };
        writeMock.mockResolvedValueOnce(result);
        const wrapper = toast();

        await undoButton(wrapper).trigger('click');
        await flushPromises();

        expect(writeMock).toHaveBeenCalledExactlyOnceWith('undo_apply', { token: 'tok-1' });
        expect(wrapper.emitted('undone')).toEqual([[result]]);
    });

    it('UI-010 without a token there is no Undo button', () => {
        expect(toast({ token: null }).find('button').exists()).toBe(false);
    });

    it('UI-030 Undo says what it does to screen readers', () => {
        const wrapper = toast();
        const hint = wrapper.get(`#${undoButton(wrapper).attributes('aria-describedby')}`);

        expect(hint.text()).toBe('Reverts this change');
    });

    it('VIEW-004 loading: while undo_apply runs the toast is busy and Undo cannot be pressed again', async () => {
        let resolve: (value: unknown) => void = () => {};
        writeMock.mockReturnValueOnce(new Promise((done) => (resolve = done)));
        const wrapper = toast();

        await undoButton(wrapper).trigger('click');

        expect(wrapper.get('[role="status"]').attributes('aria-busy')).toBe('true');
        expect(undoButton(wrapper).attributes('disabled')).toBeDefined();
        await undoButton(wrapper).trigger('click');
        expect(writeMock).toHaveBeenCalledOnce();

        resolve({});
        await flushPromises();
        expect(wrapper.get('[role="status"]').attributes('aria-busy')).toBeUndefined();
    });

    it('VIEW-004 loading: the loading prop makes the toast busy with Undo disabled', () => {
        const wrapper = toast({ loading: true });

        expect(wrapper.get('[role="status"]').attributes('aria-busy')).toBe('true');
        expect(undoButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('CAP-012 a refused undo shows the server message as given and lets the user try again', async () => {
        writeMock.mockRejectedValueOnce(
            new CapabilityError('undo_apply', 409, { ok: false, error: { code: 'conflict', message: 'A later change depends on this.' } }),
        );
        const wrapper = toast();

        await undoButton(wrapper).trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('A later change depends on this.');
        expect(wrapper.emitted('undone')).toBeUndefined();
        expect(undoButton(wrapper).attributes('disabled')).toBeUndefined();

        writeMock.mockResolvedValueOnce({});
        await undoButton(wrapper).trigger('click');
        await flushPromises();
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.emitted('undone')).toHaveLength(1);
    });

    it('VIEW-004 error: an undo that gets no answer says so in plain words', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('undo_apply', 0, { reason: 'network' }));
        const wrapper = toast();

        await undoButton(wrapper).trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe("Couldn't undo. Try again.");
    });

    it('VIEW-004 error: the error prop is announced', () => {
        expect(toast({ error: "Couldn't undo. Try again." }).get('[role="alert"]').text()).toBe("Couldn't undo. Try again.");
        expect(toast().find('[role="alert"]').exists()).toBe(false);
    });

    it('VIEW-004 empty: with no message nothing is drawn', () => {
        expect(toast({ message: '' }).find('[role="status"]').exists()).toBe(false);
    });

    it('OFF-008 pending: the toast says "Waiting to sync" in text and Undo is described by it', () => {
        const wrapper = toast({ pending: true });
        const ids = undoButton(wrapper).attributes('aria-describedby')!.split(' ');

        expect(ids.map((id) => wrapper.get(`#${id}`).text())).toEqual(['Reverts this change', 'Waiting to sync']);
        expect(toast().text()).not.toContain('Waiting to sync');
    });

    it('VIEW-004 disabled: Undo cannot be pressed', async () => {
        const wrapper = toast({ disabled: true });

        expect(undoButton(wrapper).attributes('disabled')).toBeDefined();
        await undoButton(wrapper).trigger('click');
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('VIEW-004 the gallery page lists the Toast entry', () => {
        const titles = mount(GalleryView)
            .findAll('h2')
            .map((h) => h.text());

        expect(titles).toContain('Toast');
    });
});
