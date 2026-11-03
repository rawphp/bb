import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

// The Weigh-ins screen and its schedule (WT-004, WT-007). The More placeholder links them
// until V_MORE's menu does. Today and the weekly review open /weigh-ins/log, with ?at= for
// the time a late one starts at (REV-003). That route has no tab, so it joins the stack it
// was opened from, and closing its sheet goes back there.
export default [
    { path: '/weigh-ins', name: 'weigh-ins', component: () => import('./WeighInsView.vue'), meta: { screen: 'weigh_ins_show', tab: 'more' } },
    {
        path: '/weigh-ins/log',
        name: 'weigh-in-log',
        component: () => import('./WeighInsView.vue'),
        props: (route: RouteLocationNormalized) => ({ log: typeof route.query.at === 'string' ? route.query.at : '' }),
        meta: { screen: 'weigh_ins_show' },
    },
    {
        path: '/weigh-ins/schedule',
        name: 'weigh-in-schedule',
        component: () => import('./WeighInScheduleView.vue'),
        meta: { screen: 'weigh_ins_show', tab: 'more' },
    },
] satisfies RouteRecordRaw[];
