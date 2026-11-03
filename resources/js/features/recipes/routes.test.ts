import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes } from '@/app/router';
import { findScreen, takesFixture } from '../dev/screens';
import RecipeEditorView from './RecipeEditorView.vue';
import RecipeGenerateView from './RecipeGenerateView.vue';
import RecipeImportView from './RecipeImportView.vue';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const ID = '01JBX5R0000000000000000003';

async function open(path: string) {
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push(path);
    const wrapper = mount(RouterView, { global: { plugins: [router], stubs: { teleport: true } } });
    await flushPromises();
    return { router, wrapper };
}

describe('recipe routes', () => {
    beforeEach(() => {
        vi.mocked(read).mockReset().mockReturnValue(new Promise(() => {}));
    });

    it('VIEW-001 /dev/screens finds each recipe screen with its fixture', () => {
        for (const name of ['recipes_show', 'recipe_show', 'recipe_generate', 'recipe_import']) {
            expect(findScreen(name, featureRoutes), name).not.toBeNull();
            expect(takesFixture(name, featureRoutes), name).toBe(true);
        }
    });

    it('RCP-020 /nutrition/recipes opens in the Nutrition tab and reads recipes_show', async () => {
        const { router } = await open('/nutrition/recipes');

        expect(router.currentRoute.value.meta.tab).toBe('nutrition');
        expect(read).toHaveBeenCalledWith('recipes_show', { segment: 'mine', meal_type: null, diets: [] }, expect.any(Function), expect.any(Function));
    });

    it('RCP-001 /nutrition/recipes/<id> reads that recipe', async () => {
        const { wrapper } = await open(`/nutrition/recipes/${ID}`);

        expect(wrapper.findComponent(RecipeEditorView).exists()).toBe(true);
        expect(read).toHaveBeenCalledWith('recipe_show', { recipe_id: ID }, expect.any(Function), expect.any(Function));
    });

    it('RCP-001 /nutrition/recipes/new opens an empty editor that reads nothing', async () => {
        const { wrapper } = await open('/nutrition/recipes/new');

        expect(wrapper.getComponent(RecipeEditorView).get('h1').text()).toBe('New recipe');
        expect(read).not.toHaveBeenCalled();
    });

    it('RCP-010 /nutrition/recipes/generate takes ?meal=, as the Pantry links it', async () => {
        const { router, wrapper } = await open('/nutrition/recipes/generate?meal=01JBX5M0000000000000000001');

        expect(router.currentRoute.value.meta.tab).toBe('nutrition');
        expect(wrapper.findComponent(RecipeGenerateView).exists()).toBe(true);
        expect(read).not.toHaveBeenCalled();
    });

    it('RCP-003 /nutrition/recipes/import opens the import form', async () => {
        const { wrapper } = await open('/nutrition/recipes/import');

        expect(wrapper.findComponent(RecipeImportView).exists()).toBe(true);
        expect(read).not.toHaveBeenCalled();
    });
});
