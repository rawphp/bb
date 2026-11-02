import { describe, expect, it } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { featureRoutes, makeRouter } from '@/app/router';
import { findScreen } from '../dev/screens';

describe('Train routes', () => {
    it('NAV-001 gives the Train tab its root and its mesocycle screens', () => {
        const router = makeRouter(false, createMemoryHistory());
        const name = (path: string) => router.resolve(path).name;

        expect(name('/train')).toBe('train');
        expect(name('/train/current')).toBe('current-mesocycle');
        expect(name('/train/mesocycles')).toBe('mesocycles');
        expect(name('/train/mesocycles/01JB6Q3V8T2K9WZ4M5N7P1R3S5')).toBe('mesocycle');
        expect(name('/train/mesocycles/01JB6Q3V8T2K9WZ4M5N7P1R3S5/rates')).toBe('progression-rates');
        expect(router.resolve('/train/mesocycles/01JB6Q3V8T2K9WZ4M5N7P1R3S5').meta.tab).toBe('train');
    });

    it('MESO-002 opens the build flow at /train/mesocycles/build and never reads that path as a mesocycle id', () => {
        const router = makeRouter(false, createMemoryHistory());

        expect(router.resolve('/train/mesocycles/build').name).toBe('mesocycle-build');
        expect(router.resolve('/train/mesocycles/build').meta).toMatchObject({ screen: 'mesocycle_show', tab: 'train' });
        for (const path of ['/train/mesocycles/build/rates', '/train/mesocycles/x']) {
            expect(router.resolve(path).matched[0]?.path).toBe('/:pathMatch(.*)*');
        }
    });

    it('MESO-002 hands the build flow exactly the route, copy, draft and step in the address', () => {
        const router = makeRouter(false, createMemoryHistory());
        const propsOf = (path: string) => {
            const location = router.resolve(path);
            const props = location.matched[0]!.props.default as (route: typeof location) => unknown;

            return props(location);
        };

        expect(propsOf('/train/mesocycles/build?route=copy_previous&copy=01JB6Q3V8T2K9WZ4M5N7P1R3S5&draft=01JBA7X1Y3Z5A7B9C1D3E5F7G9&step=slots')).toEqual({
            route: 'copy_previous',
            copy: '01JB6Q3V8T2K9WZ4M5N7P1R3S5',
            draft: '01JBA7X1Y3Z5A7B9C1D3E5F7G9',
            step: 'slots',
        });
        expect(propsOf('/train/mesocycles/build')).toEqual({ route: undefined, copy: undefined, draft: undefined, step: undefined });
        expect(propsOf('/train/mesocycles/build?route=&draft=a&draft=b')).toEqual({ route: undefined, copy: undefined, draft: undefined, step: undefined });
    });

    it('MESO-010 opens the template library and a template in the Train tab', () => {
        const router = makeRouter(false, createMemoryHistory());

        expect(router.resolve('/train/templates').name).toBe('templates');
        expect(router.resolve('/train/templates/01J9TPL0000000000000000000').name).toBe('template-detail');
        expect(router.resolve('/train/templates/01J9TPL0000000000000000000').meta.tab).toBe('train');
        expect(router.resolve('/train/templates/3').name).toBe('template-detail');
        expect(router.resolve('/train/templates/3/x').matched[0]?.path).toBe('/:pathMatch(.*)*');
    });

    it('VIEW-001 /dev/screens/templates_show finds the library, and ?view=template-detail the detail', () => {
        const library = findScreen('templates_show', featureRoutes);

        expect(library).not.toBeNull();
        expect(findScreen('templates_show', featureRoutes, 'template-detail')).not.toBeNull();
        expect(findScreen('templates_show', featureRoutes, 'template-detail')).not.toBe(library);
    });

    it('VIEW-001 /dev/screens/mesocycle_show?view=mesocycle-build finds the build flow, apart from the mesocycle', () => {
        const build = findScreen('mesocycle_show', featureRoutes, 'mesocycle-build');

        expect(build).not.toBeNull();
        expect(build).not.toBe(findScreen('mesocycle_show', featureRoutes));
    });
});
