import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, expectTypeOf, it, type MockInstance, vi } from 'vitest';
import { CapabilityError, write } from '@/app/call';
import type { BillingCheckoutStartOutput } from '@/contracts/billing_checkout_start';
import type { EligibilityRecheckOutput } from '@/contracts/eligibility_recheck';
import type { TokenCreateOutput } from '@/contracts/token_create';
import GalleryView from '@/features/dev/GalleryView.vue';
import { defineComponent, nextTick } from 'vue';
import { TOAST_HOST, TOAST_HOST_ID } from '@/app/hosts';
import ToastGallery from './gallery/Toast.gallery.vue';
import Toast, { type ToastResult } from './Toast.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), write: vi.fn() }));

const writeMock = vi.mocked(write);

const message = 'Meals 3 and 4 re-targeted';

// Inline, so each state test sees the toast's own status region.
function toast(props: Record<string, unknown> = {}, result: ToastResult = { toast: message, undo: 'tok-1' }) {
    return mount(Toast, { props: { result, inline: true, onUndone: undone, onUndoFailed: undoFailed, ...props } });
}

// What the host hears: the calls made to its @undone and @undo-failed listeners, or undefined for none.
const undone = vi.fn();
const undoFailed = vi.fn();
const heard = (listener: typeof undone) => (listener.mock.calls.length > 0 ? listener.mock.calls : undefined);

// Everything attached to the page is removed after each test, whether it passed or not.
const mounted: { unmount: () => void }[] = [];
const cleanups: (() => void)[] = [];

// A toast in TOAST_HOST, as the shell's toast host draws it.
function inHost(props: Record<string, unknown> = {}) {
    const wrapper = mount(Toast, { props: { result: { toast: message, undo: 'tok-1' }, onUndone: undone, onUndoFailed: undoFailed, ...props }, attachTo: TOAST_HOST });
    mounted.push(wrapper);

    return wrapper;
}

function undoButton(wrapper: ReturnType<typeof toast>) {
    return wrapper.get('button');
}

// The toast's own status region, which it carries when inline.
function root(wrapper: ReturnType<typeof toast>) {
    return wrapper.get('[role="status"]');
}

function withHost(): HTMLElement {
    const host = document.createElement('div');
    host.id = TOAST_HOST_ID;
    host.setAttribute('role', 'status');
    host.setAttribute('aria-live', 'polite');
    document.body.append(host);
    cleanups.push(() => host.remove());

    return host;
}

const refusal = () =>
    new CapabilityError('undo_apply', 409, { ok: false, error: { code: 'conflict', message: 'A later change depends on this.' } });

describe('Toast', () => {
    let warn: MockInstance<typeof console.warn>;

    beforeEach(() => {
        writeMock.mockReset();
        undone.mockReset();
        undoFailed.mockReset();
        warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        mounted.splice(0).forEach((wrapper) => wrapper.unmount());
        cleanups.splice(0).forEach((cleanup) => cleanup());
        warn.mockRestore();
    });

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

    it('UI-010 in TOAST_HOST the toast adds no live region of its own and draws nothing without a message', async () => {
        const host = withHost();
        const wrapper = inHost();
        await nextTick();

        expect(host.textContent).toContain(message);
        expect(host.querySelector('button')?.textContent?.trim()).toBe('Undo');
        expect(host.querySelectorAll('[role], [aria-live]')).toHaveLength(0);
        expect(warn).not.toHaveBeenCalled();

        await wrapper.setProps({ result: { toast: '', undo: null } });
        expect(host.textContent).toBe('');
    });

    it('UI-010 in TOAST_HOST drawn after the screen, as AppShell does, the toast lands in the host', async () => {
        const wrapper = mount(
            defineComponent({
                components: { Toast },
                setup: () => ({ TOAST_HOST, TOAST_HOST_ID, result: { toast: message, undo: 'tok-1' } }),
                template: '<div><Teleport defer :to="TOAST_HOST"><Toast :result="result" /></Teleport><div :id="TOAST_HOST_ID" aria-live="polite" /></div>',
            }),
            { attachTo: document.body },
        );
        mounted.push(wrapper);
        await nextTick();

        expect(document.getElementById(TOAST_HOST_ID)!.textContent).toContain(message);
        expect(warn).not.toHaveBeenCalled();
    });

    it('UI-010 the toast does not place itself: a toast on a hidden screen stays in that screen, out of TOAST_HOST', async () => {
        const host = withHost();
        const screen = document.createElement('section');
        screen.setAttribute('inert', '');
        screen.setAttribute('aria-hidden', 'true');
        document.body.append(screen);
        cleanups.push(() => screen.remove());

        mounted.push(mount(Toast, { props: { result: { toast: message, undo: 'tok-1' } }, attachTo: screen }));
        await nextTick();

        expect(host.textContent).toBe('');
        expect(screen.textContent).toContain(message);
    });

    it('UI-030 a toast outside any live region and not inline warns while building', async () => {
        mounted.push(mount(Toast, { props: { result: { toast: message, undo: 'tok-1' } }, attachTo: document.body }));
        await nextTick();

        expect(warn).toHaveBeenCalledWith('Toast is outside a live region: draw it in TOAST_HOST, or pass inline.');
    });

    it('VIEW-004 in TOAST_HOST: busy while undo_apply runs, then the refusal is shown there and emitted', async () => {
        const host = withHost();
        let reject: (error: unknown) => void = () => {};
        writeMock.mockReturnValueOnce(new Promise((_, fail) => (reject = fail)));
        const wrapper = inHost();
        await nextTick();

        host.querySelector('button')!.click();
        await nextTick();
        expect(host.querySelector('[data-toast]')!.getAttribute('aria-busy')).toBe('true');
        expect(host.querySelector('button')!.disabled).toBe(true);

        reject(refusal());
        await flushPromises();
        expect(host.querySelector('[data-toast]')!.getAttribute('aria-busy')).toBeNull();
        expect(host.querySelector('[data-toast-error]')!.textContent).toBe('A later change depends on this.');
        expect(heard(undoFailed)).toEqual([['A later change depends on this.', 'tok-1']]);
    });

    it('VIEW-004 in TOAST_HOST: pending is described inside the host, and disabled stops Undo', async () => {
        const host = withHost();
        inHost({ pending: true });
        await nextTick();
        const ids = host.querySelector('button')!.getAttribute('aria-describedby')!.split(' ');
        expect(ids.map((id) => host.querySelector(`[id="${id}"]`)?.textContent)).toEqual(['Reverts this change', 'Waiting to sync']);

        mounted.pop()!.unmount();
        inHost({ disabled: true });
        await nextTick();
        host.querySelector('button')!.click();
        await flushPromises();
        expect(host.querySelector('button')!.disabled).toBe(true);
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('VIEW-004 the gallery draws every toast inline, even with TOAST_HOST in the page', async () => {
        const host = withHost();
        const gallery = mount(ToastGallery, { attachTo: document.body });
        mounted.push(gallery);
        await nextTick();

        expect(host.textContent).toBe('');
        expect(gallery.findAll('[role="status"]')).toHaveLength(6);
        expect(warn).not.toHaveBeenCalled();
    });

    it('UI-010 Undo calls undo_apply with the token and emits undone with the result', async () => {
        const result = { invalidates: ['nutrition_show:{}'] };
        writeMock.mockResolvedValueOnce(result);
        const wrapper = toast();

        await undoButton(wrapper).trigger('click');
        await flushPromises();

        expect(writeMock).toHaveBeenCalledExactlyOnceWith('undo_apply', { token: 'tok-1' });
        expect(heard(undone)).toEqual([[result, 'tok-1']]);
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
        expect(heard(undone)).toBeUndefined();
        expect(undoButton(wrapper).attributes('disabled')).toBeUndefined();

        writeMock.mockResolvedValueOnce({});
        await undoButton(wrapper).trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-toast-error]').exists()).toBe(false);
        expect(heard(undone)).toHaveLength(1);
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
        expect(heard(undoFailed)).toEqual([["Couldn't undo. Try again.", 'tok-1']]);
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
        expect(heard(undoFailed)).toEqual([['A later change depends on this.', 'tok-1']]);
    });

    it('CAP-012 a refused undo is also emitted with its token, for the host', async () => {
        writeMock.mockRejectedValueOnce(refusal());
        const wrapper = toast();

        await undoButton(wrapper).trigger('click');
        await flushPromises();

        expect(heard(undoFailed)).toEqual([['A later change depends on this.', 'tok-1']]);
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

    it('UI-010 the host hears undone even when the toast is removed before undo_apply answers', async () => {
        let answer: (value: unknown) => void = () => {};
        writeMock.mockReturnValueOnce(new Promise((done) => (answer = done)));
        const wrapper = toast();
        await undoButton(wrapper).trigger('click');

        wrapper.unmount();
        answer({ invalidates: ['nutrition_show:{}'], toast: 'Undone.' });
        await flushPromises();

        expect(heard(undone)).toEqual([[{ invalidates: ['nutrition_show:{}'], toast: 'Undone.' }, 'tok-1']]);
    });

    it('UI-010 the host hears undo-failed even when the toast is removed before undo_apply answers', async () => {
        let refuse: (error: unknown) => void = () => {};
        writeMock.mockReturnValueOnce(new Promise((_, fail) => (refuse = fail)));
        const wrapper = toast();
        await undoButton(wrapper).trigger('click');

        wrapper.unmount();
        refuse(refusal());
        await flushPromises();

        expect(heard(undoFailed)).toEqual([['A later change depends on this.', 'tok-1']]);
    });

    it('UI-010 @undone and @undo-failed bind as listeners from a parent template', async () => {
        writeMock.mockResolvedValueOnce({ toast: 'Undone.' });
        const onDone = vi.fn();
        const wrapper = mount(
            defineComponent({
                components: { Toast },
                setup: () => ({ onDone, result: { toast: message, undo: 'tok-1' } }),
                template: '<Toast inline :result="result" @undone="onDone" />',
            }),
        );

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(onDone).toHaveBeenCalledExactlyOnceWith({ toast: 'Undone.' }, 'tok-1');
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
        expect(heard(undone)).toEqual([[{ toast: 'Undone.' }, 'tok-1']]);
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
