import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

// The recipe screens (RCP-001, RCP-003, RCP-010, RCP-020), in the Nutrition tab. The
// Pantry links Generate with ?meal=<meal id> and Import as they are. Generate and Import
// read nothing until the user asks; their /dev/screens fixture is a drafted recipe, which
// opens their confirm step.
export default [
    { path: '/nutrition/recipes', name: 'recipes', component: () => import('./RecipesView.vue'), meta: { screen: 'recipes_show', tab: 'nutrition' } },
    { path: '/nutrition/recipes/new', name: 'recipe-new', component: () => import('./RecipeEditorView.vue'), meta: { tab: 'nutrition' } },
    { path: '/nutrition/recipes/generate', name: 'recipe-generate', component: () => import('./RecipeGenerateView.vue'), meta: { screen: 'recipe_generate', tab: 'nutrition' } },
    { path: '/nutrition/recipes/import', name: 'recipe-import', component: () => import('./RecipeImportView.vue'), meta: { screen: 'recipe_import', tab: 'nutrition' } },
    {
        path: '/nutrition/recipes/:recipeId',
        name: 'recipe',
        component: () => import('./RecipeEditorView.vue'),
        props: (route: RouteLocationNormalized) => ({ recipeId: route.params.recipeId }),
        meta: { screen: 'recipe_show', tab: 'nutrition' },
    },
] satisfies RouteRecordRaw[];
