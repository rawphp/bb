// The five tabs of the bottom bar (NAV-001), in rp-clone's RootView order. Each
// tab's root screen lives at its path; a feature takes the root by declaring a
// route at that path, and until one does the shell shows a placeholder.

export const TABS = [
    { id: 'today', label: 'Today', path: '/today' },
    { id: 'train', label: 'Train', path: '/train' },
    { id: 'nutrition', label: 'Nutrition', path: '/nutrition' },
    { id: 'progress', label: 'Progress', path: '/progress' },
    { id: 'more', label: 'More', path: '/more' },
] as const;

export type TabId = (typeof TABS)[number]['id'];

export const HOME_TAB: TabId = 'today';

export function tabPath(tab: TabId): string {
    return TABS.find((t) => t.id === tab)!.path;
}

/** The tab whose root is at this path, if any. */
export function tabAtRoot(path: string): TabId | undefined {
    return TABS.find((t) => t.path === path)?.id;
}
