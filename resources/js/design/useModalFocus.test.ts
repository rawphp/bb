import { afterEach, describe, expect, it } from 'vitest';
import { ref } from 'vue';
import { useModalFocus } from './useModalFocus';

function tab(shiftKey = false): KeyboardEvent {
    return new KeyboardEvent('keydown', { key: 'Tab', shiftKey, cancelable: true });
}

function modal(html: string): HTMLElement {
    const element = document.createElement('div');
    element.tabIndex = -1;
    element.innerHTML = html;
    document.body.appendChild(element);
    return element;
}

describe('useModalFocus', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('UI-030 Tab wraps from the last control to the first, and Shift+Tab from the first to the last', () => {
        const element = modal('<button data-a>A</button><input data-b /><button disabled>C</button>');
        const { keepFocus } = useModalFocus(ref(element));
        const a = element.querySelector<HTMLElement>('[data-a]')!;
        const b = element.querySelector<HTMLElement>('[data-b]')!;

        b.focus();
        const forward = tab();
        keepFocus(forward);
        expect(forward.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(a);

        const back = tab(true);
        keepFocus(back);
        expect(back.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(b);
    });

    it('UI-030 Tab between two controls inside the modal is left to the browser', () => {
        const element = modal('<button data-a>A</button><button>B</button><button>C</button>');
        const { keepFocus } = useModalFocus(ref(element));
        element.querySelectorAll<HTMLElement>('button')[1]!.focus();

        const event = tab();
        keepFocus(event);

        expect(event.defaultPrevented).toBe(false);
    });

    it('UI-030 Tab from outside the modal or from the modal itself lands on its first control, Shift+Tab on its last', () => {
        const element = modal('<button data-a>A</button><a href="#" data-z>Z</a>');
        const { keepFocus } = useModalFocus(ref(element));

        keepFocus(tab());
        expect(document.activeElement).toBe(element.querySelector('[data-a]'));

        element.focus();
        keepFocus(tab(true));
        expect(document.activeElement).toBe(element.querySelector('[data-z]'));
    });

    it('UI-030 a modal with no controls keeps focus on itself', () => {
        const element = modal('<p>Nothing to press</p>');
        const { keepFocus } = useModalFocus(ref(element));

        const event = tab();
        keepFocus(event);

        expect(event.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(element);
    });

    it('UI-030 focus moves into the modal on open and back to the opener on close', async () => {
        const element = modal('<button>A</button>');
        const opener = document.createElement('button');
        const other = document.createElement('button');
        document.body.append(opener, other);
        const focus = useModalFocus(ref(element));

        other.focus();
        await focus.opened(opener);
        expect(document.activeElement).toBe(element);

        focus.closed();
        expect(document.activeElement).toBe(opener);

        other.focus();
        await focus.opened();
        focus.closed();
        expect(document.activeElement).toBe(other);
    });
});
