import type { RouteRecordRaw } from 'vue-router';

export default [
    { path: '/learn', component: () => import('./LearnIndexView.vue'), meta: { screen: 'learn_show', tab: 'more' } },
    { path: '/learn/:slug', component: () => import('./ArticleView.vue'), props: true, meta: { screen: 'article_show', tab: 'more' } },
] satisfies RouteRecordRaw[];
