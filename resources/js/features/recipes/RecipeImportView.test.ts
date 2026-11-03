import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AI_TIMEOUT_MS, CapabilityError, read, readFresh } from '@/app/call';
import type { RecipeImportOutput } from '@/contracts/recipe_import';
import { inputViolations, screenFixture } from '@/testing/schemas';
import RecipeDraftView from './RecipeDraftView.vue';
import RecipeImportView from './RecipeImportView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));
const back = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ back, push: vi.fn() }) }));

const freshMock = vi.mocked(readFresh);
const draft = screenFixture<RecipeImportOutput>('recipe_import');

let wrapper: VueWrapper | undefined;

function show(props: Record<string, unknown> = {}): VueWrapper {
    wrapper = mount(RecipeImportView, { props, attachTo: document.body, global: { stubs: { teleport: true, PantryView: true } } });
    return wrapper;
}

function address(view: VueWrapper) {
    return view.get<HTMLInputElement>('input[aria-label="Recipe web address"]');
}

function importButton(view: VueWrapper) {
    return view.get('[data-test="import"]');
}

describe('RecipeImportView', () => {
    beforeEach(() => {
        vi.mocked(read).mockReset();
        freshMock.mockReset().mockResolvedValue(draft);
        back.mockReset();
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('RCP-003 asks for the web address and reads nothing', () => {
        const view = show();

        expect(view.get('h1').text()).toBe('Import from URL');
        expect(address(view).attributes('placeholder')).toBe('https://example.com/recipe');
        expect(view.text()).toContain('We read the page, then match each ingredient to a food in the database for you to confirm.');
        expect(importButton(view).attributes('disabled')).toBeDefined();
        expect(freshMock).not.toHaveBeenCalled();
    });

    it('RCP-003 Import sends recipe_import with the trimmed address, then shows the draft to confirm', async () => {
        const view = show();

        await address(view).setValue('  https://example.com/turkey-chili ');
        expect(importButton(view).attributes('disabled')).toBeUndefined();
        await importButton(view).trigger('click');
        await flushPromises();

        expect(freshMock).toHaveBeenCalledWith('recipe_import', { url: 'https://example.com/turkey-chili' }, { timeoutMs: AI_TIMEOUT_MS });
        expect(inputViolations('recipe_import', freshMock.mock.calls[0]![1])).toEqual([]);
        expect(view.getComponent(RecipeDraftView).props('draft')).toEqual(draft);
    });

    it('RCP-003 says it is reading the page, and the form waits', async () => {
        freshMock.mockReturnValueOnce(new Promise(() => {}));
        const view = show();

        await address(view).setValue('https://example.com/r');
        await importButton(view).trigger('click');

        expect(importButton(view).text()).toBe('Reading the page…');
        expect(importButton(view).attributes('disabled')).toBeDefined();
        expect(address(view).attributes('disabled')).toBeDefined();
    });

    it('CAP-012 a refusal stays on the form with the server\'s words, until the address changes', async () => {
        freshMock.mockReset().mockRejectedValueOnce(new CapabilityError('recipe_import', 422, { error: { message: 'Enter a web address that starts with http:// or https://.' } }));
        const view = show();

        await address(view).setValue('example.com');
        await importButton(view).trigger('click');
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('Enter a web address that starts with http:// or https://.');
        expect(view.findComponent(RecipeDraftView).exists()).toBe(false);

        await address(view).setValue('https://example.com');
        expect(view.find('[role="alert"]').exists()).toBe(false);
    });

    it('RCP-003 an import past the deadline says it took too long, not that the device is offline', async () => {
        freshMock.mockReset().mockRejectedValueOnce(new CapabilityError('recipe_import', 0, { reason: 'timeout' }));
        const view = show();

        await address(view).setValue('https://example.com/r');
        await importButton(view).trigger('click');
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toBe('The recipe took too long. Try again.');
    });

    it('RCP-003 opened with an imported recipe it shows the confirm step', () => {
        const view = show({ payload: draft });

        expect(view.getComponent(RecipeDraftView).props('draft')).toEqual(draft);
    });

    it('RCP-003 Cancel goes back', async () => {
        const view = show();

        await view.get('[data-test="cancel"]').trigger('click');

        expect(back).toHaveBeenCalledOnce();
    });
});
