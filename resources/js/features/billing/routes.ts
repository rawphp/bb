import type { RouteRecordRaw } from 'vue-router';

// The paywall (SUB-004) opens over the end of onboarding, and the lapsed banner
// (SUB-010) sits over every tab, so neither has a page of its own. Billing on only
// (SUB-020). Until the shell and onboarding mount them, and for screenshots, each opens
// on its own here, in local and testing only: /dev/screens/billing_show?view=<name>
// renders it from a fixture. Dev routes stay out of a production build (CAP-004).
export default (import.meta.env.DEV
    ? [
          { path: '/dev/paywall', name: 'paywall', component: () => import('./PaywallView.vue'), meta: { dev: true, shell: false, screen: 'billing_show' } },
          {
              path: '/dev/paywall-sheet',
              name: 'paywall-sheet',
              component: () => import('./PaywallSheet.vue'),
              props: { open: true },
              meta: { dev: true, shell: false, screen: 'billing_show' },
          },
          {
              path: '/dev/lapsed-banner',
              name: 'lapsed-banner',
              component: () => import('./LapsedBannerView.vue'),
              meta: { dev: true, shell: false, screen: 'billing_show' },
          },
      ]
    : []) satisfies RouteRecordRaw[];
