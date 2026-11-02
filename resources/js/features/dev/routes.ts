import type { RouteRecordRaw } from 'vue-router';

// Local and testing only: the router drops meta.dev routes unless the server flags them on.
export default [
    // Every single-valued query key but view is a prop of the screen, as a string:
    // /dev/screens/food_search?mealId=… opens the Pantry for a meal, and ?pending= turns a Boolean prop on.
    {
        path: '/dev/screens/:name',
        component: () => import('./ScreenView.vue'),
        props: (route) => ({
            name: route.params.name,
            view: typeof route.query.view === 'string' ? route.query.view : undefined,
            screenProps: Object.fromEntries(Object.entries(route.query).filter(([key, value]) => key !== 'view' && typeof value === 'string')),
        }),
        meta: { dev: true, shell: false },
    },
    // Settings → Developer (SET-011) on its own page, until the Settings list mounts DeveloperSection.
    { path: '/dev/clock', component: () => import('@/features/settings/DeveloperView.vue'), meta: { dev: true, screen: 'developer', fixture: false, tab: 'more' } },
    { path: '/dev/gallery', component: () => import('./GalleryView.vue'), meta: { dev: true, shell: false } },
] satisfies RouteRecordRaw[];
