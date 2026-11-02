import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, expectTypeOf, it, vi } from 'vitest';
import { CapabilityError, write } from '@/app/call';
import type { BillingCheckoutStartOutput } from '@/contracts/billing_checkout_start';
import type { EligibilityRecheckOutput } from '@/contracts/eligibility_recheck';
import type { TokenCreateOutput } from '@/contracts/token_create';
import GalleryView from '@/features/dev/GalleryView.vue';
import Toast, { type ToastResult } from './Toast.vue';
import { TOAST_HOST_ID } from '@/app/hosts';
import { defineComponent, nextTick } from 'vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), write: vi.fn() }));

const writeMock = vi.mocked(write);

const message = 'Meals 3 and 4 re-targeted';

function toast(props: Record<string, unknown> = {}, result: ToastResult = { toast: message, undo: 'tok-1' }) {
    return mount(Toast, { props: { result, ...props } });
}

function undoButton(wrapper: ReturnType<typeof toast>) {
    return wrapper.get('button');
}

// With no TOAST_HOST in the page (as here), the toast stays in place in its own live region.
function root(wrapper: ReturnType<typeof toast>) {
    return wrapper.get('[role="status"]');
}

function withHost(): HTMLElement {
    const host = document.createElement('div');
    host.id = TOAST_HOST_ID;
    host.setAttribute('role', 'status');
    host.setAttribute('aria-live', 'polite');
    document.body.append(host);

    return host;
}

const refusal = () =>
    new CapabilityError('undo_apply', 409, { ok: false, error: { code: 'conflict', message: 'A later change depends on this.' } });

describe('Toast', () => {
    beforeEach(() => writeMock.mockReset());

    it("CAP-010 takes a write's answer as the server sends it", () => {
        expectTypeOf<TokenCreateOutput>().toExtend<ToastResult>();
        expectTypeOf<EligibilityRecheckOutput>().toExtend<ToastResult>();
        expectTypeOf<BillingCheckoutStartOutput>().toExtend<ToastResult>();

        const wrapper = toast({}, { invalidates: [], toast: message, undo: 'tok-1' } as ToastResult);

        expect(root(wrapper).text()).toContain(message);
        expect(undoButton(wrapper).text()).toBe('Undo');
    });

    it('UI-010 shows the message and an Undo button inside a status region', () => {
        const wrapper = toast();

        expect(root(wrapper).text()).toContain(message);
        expect(undoButton(wrapper).text()).toBe('Undo');
    });

    it('UI-010 in the shell the toast goes into TOAST_HOST, which announces it, and adds no live region of its own', async () => {
        const host = withHost();
        const wrapper = mount(Toast, { props: { result: { toast: message, undo: 'tok-1' } }, attachTo: document.body });
        await nextTick();

        expect(host.textContent).toContain(message);
        expect(host.querySelector('button')?.textContent?.trim()).toBe('Undo');
        expect(host.querySelectorAll('[role], [aria-live]')).toHaveLength(0);

        await wrapper.setProps({ result: { toast: '', undo: null } });
        expect(host.textContent).toBe('');

        wrapper.unmount();
        host.remove();
    });

    it('UI-010 finds TOAST_HOST when the shell draws it after the screen, as AppShell does', async () => {
        const Shell = defineComponent({
            components: { Toast },
            setup: () => ({ result: { toast: message, undo: 'tok-1' }, id: TOAST_HOST_ID }),
            template: '<div><Toast :result="result" /><div :id="id" role="status" aria-live="polite" /></div>',
        });
        const wrapper = mount(Shell, { attachTo: document.body });
        await nextTick();

        const host = document.getElementById(TOAST_HOST_ID)!;
        expect(host.textContent).toContain(message);
        expect(host.querySelectorAll('[role], [aria-live]')).toHaveLength(0);

        wrapper.unmount();
    });

    it('VIEW-004 inline stays in place in its own status region, even in the shell', async () => {
        const host = withHost();
        const wrapper = mount(Toast, { props: { result: { toast: message, undo: 'tok-1' }, inline: true }, attachTo: document.body });
        await nextTick();

        expect(host.textContent).toBe('');
        expect(root(wrapper).text()).toContain(message);

        wrapper.unmount();
        host.remove();
    });

    it('UI-010 Undo calls undo_apply with the token and emits undone with the result', async () => {
        const result = { invalidates: ['nutrition_show:{}'] };
        writeMock.mockResolvedValueOnce(result);
        const wrapper = toast();

        await undoButton(wrapper).trigger('click');
        await flushPromises();

        expect(writeMock).toHaveBeenCalledExactlyOnceWith('undo_apply', { token: 'tok-1' });
        expect(wrapper.emitted('undone')).toEqual([[result, 'tok-1']]);
    });

    it('UI-010 without a token there is no Undo button', () => {
        expect(toast({}, { toast: message, undo: null }).find('button').exists()).toBe(false);
        expect(toast({}, { toast: message }).find('button').exists()).toBe(false);
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

        expect(root(wrapper).attributes('aria-busy')).toBe('true');
        expect(undoButton(wrapper).attributes('disabled')).toBeDefined();
        await undoButton(wrapper).trigger('click');
        expect(writeMock).toHaveBeenCalledOnce();

        resolve({});
        await flushPromises();
        expect(root(wrapper).attributes('aria-busy')).toBeUndefined();
    });

    it('VIEW-004 loading: the loading prop makes the toast busy with Undo disabled', () => {
        const wrapper = toast({ loading: true });

        expect(root(wrapper).attributes('aria-busy')).toBe('true');
        expect(undoButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('CAP-012 a refused undo shows the server message as given and lets the user try again', async () => {
        writeMock.mockRejectedValueOnce(
            new CapabilityError('undo_apply', 409, { ok: false, error: { code: 'conflict', message: 'A later change depends on this.' } }),
        );
        const wrapper = toast();

        await undoButton(wrapper).trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-toast-error]').text()).toBe('A later change depends on this.');
        expect(wrapper.emitted('undone')).toBeUndefined();
        expect(undoButton(wrapper).attributes('disabled')).toBeUndefined();

        writeMock.mockResolvedValueOnce({});
        await undoButton(wrapper).trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-toast-error]').exists()).toBe(false);
        expect(wrapper.emitted('undone')).toHaveLength(1);
    });

    it.each([
        ['no answer', new CapabilityError('undo_apply', 0, { reason: 'network' })],
        ['an answer with an empty message', new CapabilityError('undo_apply', 409, { ok: false, error: { code: 'conflict', message: '' } })],
        // Only the server's answer is shown as given, even when another error carries a body.
        ['an error that is not a capability answer', Object.assign(new TypeError('Failed to fetch'), { body: { error: { message: 'Not the server' } } })],
    ])('VIEW-004 error: an undo that gets %s says so in plain words', async (_, error) => {
        writeMock.mockRejectedValueOnce(error);
        const wrapper = toast();

        await undoButton(wrapper).trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-toast-error]').text()).toBe("Couldn't undo. Try again.");
    });

    it('CAP-012 a refused undo shows the server message in place of the error prop', async () => {
        writeMock.mockRejectedValueOnce(
            new CapabilityError('undo_apply', 409, { ok: false, error: { code: 'conflict', message: 'A later change depends on this.' } }),
        );
        const wrapper = toast({ error: 'Earlier problem' });

        await undoButton(wrapper).trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-toast-error]').text()).toBe('A later change depends on this.');
    });

    it('VIEW-004 error: the error prop is announced', () => {
        expect(toast({ error: "Couldn't undo. Try again." }).get('[data-toast-error]').text()).toBe("Couldn't undo. Try again.");
        expect(toast().find('[data-toast-error]').exists()).toBe(false);
    });

    it('VIEW-004 empty: with no toast nothing is drawn, and the status region stays for the next one', async () => {
        for (const empty of [{ toast: '', undo: 'tok-1' }, { toast: null, undo: 'tok-1' }, { undo: 'tok-1' }]) {
            const wrapper = toast({ error: 'Nope' }, empty);

            expect(root(wrapper).text()).toBe('');
            expect(wrapper.find('button').exists()).toBe(false);
        }

        const wrapper = toast({}, { toast: '', undo: null });
        await wrapper.setProps({ result: { toast: message, undo: 'tok-1' } });
        expect(root(wrapper).text()).toContain(message);
    });

    it('CAP-012 a refusal does not carry over to the next toast', async () => {
        writeMock.mockRejectedValueOnce(
            new CapabilityError('undo_apply', 409, { ok: false, error: { code: 'conflict', message: 'A later change depends on this.' } }),
        );
        const wrapper = toast();
        await undoButton(wrapper).trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-toast-error]').exists()).toBe(true);

        await wrapper.setProps({ result: { toast: 'Set added', undo: 'tok-2' } });

        expect(wrapper.find('[data-toast-error]').exists()).toBe(false);
    });

    it('CAP-012 a refusal that lands after the next toast is shown is not shown on it', async () => {
        let reject: (error: unknown) => void = () => {};
        writeMock.mockReturnValueOnce(new Promise((_, fail) => (reject = fail)));
        const wrapper = toast();
        await undoButton(wrapper).trigger('click');

        await wrapper.setProps({ result: { toast: 'Set added', undo: 'tok-2' } });
        reject(new CapabilityError('undo_apply', 409, { ok: false, error: { code: 'conflict', message: 'A later change depends on this.' } }));
        await flushPromises();

        expect(wrapper.find('[data-toast-error]').exists()).toBe(false);
        expect(wrapper.emitted('undo-failed')).toEqual([['A later change depends on this.', 'tok-1']]);
    });

    it('CAP-012 a refused undo is also emitted with its token, for the host', async () => {
        writeMock.mockRejectedValueOnce(refusal());
        const wrapper = toast();

        await undoButton(wrapper).trigger('click');
        await flushPromises();

        expect(wrapper.emitted('undo-failed')).toEqual([['A later change depends on this.', 'tok-1']]);
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

    it('UI-010 an undo that finishes after the next toast is shown neither blocks it nor is lost', async () => {
        let resolve: (value: unknown) => void = () => {};
        writeMock.mockReturnValueOnce(new Promise((done) => (resolve = done)));
        const wrapper = toast();
        await undoButton(wrapper).trigger('click');

        await wrapper.setProps({ result: { toast: 'Set added', undo: 'tok-2' } });

        expect(root(wrapper).attributes('aria-busy')).toBeUndefined();
        expect(undoButton(wrapper).attributes('disabled')).toBeUndefined();

        resolve({ toast: 'Undone.' });
        await flushPromises();
        expect(wrapper.emitted('undone')).toEqual([[{ toast: 'Undone.' }, 'tok-1']]);
        expect(root(wrapper).text()).toContain('Set added');
    });

    it('UI-010 the old undo finishing does not end the busy state of the next one', async () => {
        let first: (value: unknown) => void = () => {};
        writeMock.mockReturnValueOnce(new Promise((done) => (first = done)));
        writeMock.mockReturnValueOnce(new Promise(() => {}));
        const wrapper = toast();
        await undoButton(wrapper).trigger('click');
        await wrapper.setProps({ result: { toast: 'Set added', undo: 'tok-2' } });
        await undoButton(wrapper).trigger('click');

        first({});
        await flushPromises();

        expect(writeMock).toHaveBeenLastCalledWith('undo_apply', { token: 'tok-2' });
        expect(root(wrapper).attributes('aria-busy')).toBe('true');
        expect(undoButton(wrapper).attributes('disabled')).toBeDefined();
    });
});
