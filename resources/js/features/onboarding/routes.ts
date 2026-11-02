import type { RouteRecordRaw } from 'vue-router';

// Setup and the intro before it, outside the shell: there are no tabs until a plan exists.
// The intro reads nothing, so /dev/screens/intro renders it without a fixture. Step 1 of
// setup opens at /dev/screens/onboarding_show.empty, steps 2 to 5 at
// /dev/screens/onboarding_show.<about_you|screening|glp1|body_fat>.
export default [
    { path: '/onboarding', component: () => import('./OnboardingView.vue'), meta: { screen: 'onboarding_show', shell: false } },
    { path: '/welcome', component: () => import('./IntroView.vue'), meta: { screen: 'intro', fixture: false, shell: false } },
] satisfies RouteRecordRaw[];
