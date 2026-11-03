import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

// The weekly review (REV-001 to REV-050), opened from Today's and Progress's
// "Weekly review available" cards. ?step=<key> opens one step, so
// /dev/screens/review_show.<state>?step=calories shows that step with its fixture.
export default [
    {
        path: '/review',
        name: 'weekly-review',
        component: () => import('./WeeklyReviewView.vue'),
        props: (route: RouteLocationNormalized) => ({ step: typeof route.query.step === 'string' ? route.query.step : undefined }),
        meta: { screen: 'review_show', tab: 'today' },
    },
] satisfies RouteRecordRaw[];
