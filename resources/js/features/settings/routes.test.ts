import { describe, expect, it } from 'vitest';
import { featureRoutes } from '@/app/router';
import { findScreen } from '../dev/screens';
import NotificationSettingsView from './NotificationSettingsView.vue';
import DietFiltersView from './DietFiltersView.vue';
import ProfileEditView from './ProfileEditView.vue';
import ScheduleEditView from './ScheduleEditView.vue';
import SettingsView from './SettingsView.vue';
import routes from './routes';

async function screen(view?: string): Promise<unknown> {
    const route = featureRoutes.find((r) => r.meta?.screen === 'settings_show' && (view === undefined || r.name === view));
    const component = route && 'component' in route ? route.component : undefined;
    return typeof component === 'function' ? ((await (component as () => Promise<{ default: unknown }>)()).default) : component;
}

describe('settings routes', () => {
    it('VIEW-001 /dev/screens/settings_show opens Settings', async () => {
        expect(findScreen('settings_show', featureRoutes)).not.toBeNull();
        expect(await screen()).toBe(SettingsView);
    });

    it.each([
        ['settings-profile', '/settings/profile', ProfileEditView],
        ['settings-schedule', '/settings/schedule', ScheduleEditView],
        ['settings-diet-filters', '/settings/diet-filters', DietFiltersView],
        ['settings-notifications', '/settings/notifications', NotificationSettingsView],
    ])('VIEW-001 ?view=%s opens the screen at %s', async (name, path, component) => {
        expect(routes.find((route) => route.name === name)?.path).toBe(path);
        expect(await screen(name)).toBe(component);
    });

    it('NAV-002 every Settings screen opens in the More tab', () => {
        for (const route of routes.filter((r) => r.meta.screen === 'settings_show')) expect(route.meta).toMatchObject({ tab: 'more' });
    });
});
