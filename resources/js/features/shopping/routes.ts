import type { RouteRecordRaw } from 'vue-router';

// The shopping list (SHOP-006): from the More menu and from the Nutrition tab. Until
// those menus exist, each tab's placeholder links its route.
export default [
    { path: '/shopping', name: 'shopping', component: () => import('./ShoppingListView.vue'), meta: { screen: 'shopping_show', tab: 'more' } },
    { path: '/nutrition/shopping', name: 'nutrition-shopping', component: () => import('./ShoppingListView.vue'), meta: { screen: 'shopping_show', tab: 'nutrition' } },
] satisfies RouteRecordRaw[];
