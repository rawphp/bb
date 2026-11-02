import type { RouteRecordRaw } from 'vue-router';

// The scanner (FOOD-040 to FOOD-046). The meal screen opens it over the meal; until it does, and for screenshots,
// it opens on its own here, in local and testing only. /dev/screens/<fixture> opens it in a fixture's state, and
// /dev/scan?meal=<id> opens it empty, adding to that meal, to try the camera.
const scanner = (path: string, name: string, screen: string, fixture = true): RouteRecordRaw => ({
    path: `/dev/scan${path}`,
    name,
    component: () => import('./ScanView.vue'),
    props: (route) => ({ mealId: typeof route.query.meal === 'string' ? route.query.meal : undefined }),
    meta: { dev: true, shell: false, screen, fixture },
});

// Dev routes stay out of a production build (CAP-004), as features/dev's do.
export default (import.meta.env.DEV
    ? [
          scanner('', 'scan', 'scan', false),
          scanner('/barcode', 'scan-barcode', 'food_barcode_lookup'),
          scanner('/photo', 'scan-photo', 'scan_photo'),
          scanner('/label', 'scan-label', 'scan_label'),
      ]
    : []) satisfies RouteRecordRaw[];
