import { nextTick, type Ref } from 'vue';

// Focus handling every modal sheet shares (UI-030): focus moves in on open, Tab and
// Shift+Tab cycle through the modal's own controls, and focus goes back on close.

const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function useModalFocus(modal: Ref<HTMLElement | null | undefined>) {
    let returnTo: HTMLElement | null = null;

    /** As the modal opens: focus moves into it once it renders, and returns to `opener` (by default the focused element) on close. */
    async function opened(opener?: HTMLElement | null): Promise<void> {
        returnTo = opener ?? (document.activeElement instanceof HTMLElement ? document.activeElement : null);
        await nextTick();
        modal.value?.focus();
    }

    /** As the modal closes: focus goes back to where it came from. */
    function closed(): void {
        returnTo?.focus();
        returnTo = null;
    }

    /** On Tab: wrap at the first and last control, and bring focus back in from outside the modal. */
    function keepFocus(event: KeyboardEvent): void {
        const container = modal.value;
        if (!container) {
            return;
        }
        const controls = Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE));
        const first = controls[0];
        const last = controls[controls.length - 1];
        const active = document.activeElement;
        if (!first || !last) {
            event.preventDefault();
            container.focus();
        } else if (!(active instanceof Node) || !container.contains(active) || active === container) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
        } else if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    return { opened, closed, keepFocus };
}
