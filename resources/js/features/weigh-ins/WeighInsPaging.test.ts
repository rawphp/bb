import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh } from '@/app/call';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import { screenFixture } from '@/testing/schemas';
import WeighInsScreen from './WeighInsScreen.vue';
import WeighInsView from './WeighInsView.vue';

// WT-004: the Weigh-ins screen reads 90 days at a time; Show older reads the next page.
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ push: vi.fn(), back: vi.fn(), replace: vi.fn() }) }));
vi.mock('@/app/navigation', async (original) => ({ ...(await original<typeof import('@/app/navigation')>()), findNavigation: () => undefined }));
vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const readFreshMock = vi.mocked(readFresh);
const content = screenFixture<WeighInsShowOutput>('weigh_ins_show');
const newest: WeighInsShowOutput = { ...content, next_before: '2026-07-13' };

/** An older page: the fixture's rows under other ids and labels. */
function olderPage(label: string, nextBefore: string | null = null): WeighInsShowOutput {
    return {
        ...content,
        rows: content.rows.map((row) => ({ ...row, id: `${label}-${row.id}`, date_label: `${label} ${row.date_label}` })),
        steps: { ...content.steps, rows: content.steps.rows.map((row) => ({ ...row, date: `${label}-${row.date}`, date_label: `${label} ${row.date_label}` })) },
        next_before: nextBefore,
    };
}

let wrapper: VueWrapper | undefined;

function screen(props: { data: WeighInsShowOutput; loadingMore?: boolean; moreError?: string | null }): VueWrapper {
    wrapper = mount(WeighInsScreen, { props: { reload: async () => {}, ...props }, attachTo: document.body });
    return wrapper;
}

async function view(): Promise<VueWrapper> {
    wrapper = mount(WeighInsView, { attachTo: document.body });
    await flushPromises();
    return wrapper;
}

function dates(view: VueWrapper): string[] {
    return view.findAll('[data-test="weigh-in"]').map((row) => row.attributes('aria-label')!.replace(/^.* (on|for) /, ''));
}

function stepDates(view: VueWrapper): string[] {
    return view.findAll('[data-test="steps-row"]').map((row) => row.attributes('aria-label')!.replace('Edit the steps for ', ''));
}

describe('WeighInsScreen Show older', () => {
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('WT-004 shows no Show older button when there is nothing older', () => {
        const view = screen({ data: content });

        expect(view.find('[data-test="show-older"]').exists()).toBe(false);
    });

    it('WT-004 WT-020 shows Show older under the weigh-ins and under the steps when there is more, and either asks for it', async () => {
        const view = screen({ data: newest });

        const buttons = view.findAll('[data-test="show-older"]');
        expect(buttons.map((button) => button.text())).toEqual(['Show older', 'Show older']);
        expect(buttons[1]!.element.closest('[data-test="steps"]')).not.toBeNull();
        expect(buttons[0]!.element.closest('[data-test="steps"]')).toBeNull();
        expect(buttons.every((button) => button.classes().includes('min-h-tap') && button.classes().includes('w-full'))).toBe(true);
        await buttons[0]!.trigger('click');
        await buttons[1]!.trigger('click');

        expect(view.emitted('more')).toHaveLength(2);
    });

    it('WT-004 turns both buttons off while a page loads, and says why one failed under each, keeping them to try again', () => {
        const view = screen({ data: newest, loadingMore: true, moreError: 'Older weigh-ins could not be loaded.' });

        const buttons = view.findAll('[data-test="show-older"]');
        expect(buttons.every((button) => button.attributes('disabled') !== undefined)).toBe(true);
        expect(view.findAll('[role="alert"]').map((alert) => alert.text())).toEqual(['Older weigh-ins could not be loaded.', 'Older weigh-ins could not be loaded.']);
    });

    it('WT-004 shows Show older on a page with no weigh-ins in it', () => {
        const view = screen({ data: { ...newest, rows: [], steps: { ...newest.steps, rows: [] } } });

        expect(view.findAll('[data-test="show-older"]')).toHaveLength(2);
    });
});

describe('WeighInsView paging', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(newest);
        readFreshMock.mockReset();
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('WT-004 WT-020 Show older reads the page before next_before and adds its weigh-ins and steps after the first', async () => {
        readFreshMock.mockResolvedValue(olderPage('Old'));
        const page = await view();
        const first = dates(page);

        await page.get('[data-test="show-older"]').trigger('click');
        await flushPromises();

        expect(readFreshMock).toHaveBeenCalledWith('weigh_ins_show', { before: '2026-07-13' });
        expect(dates(page)).toEqual([...first, ...first.map((date) => `Old ${date}`)]);
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Old Fri, 9 Oct', 'Old Thu, 8 Oct']);
        expect(page.find('[data-test="show-older"]').exists()).toBe(false);
    });

    it('WT-004 reads on from the page it last added', async () => {
        readFreshMock.mockResolvedValueOnce(olderPage('Old', '2026-04-14')).mockResolvedValueOnce(olderPage('Older'));
        const page = await view();

        await page.get('[data-test="show-older"]').trigger('click');
        await flushPromises();
        await page.get('[data-test="show-older"]').trigger('click');
        await flushPromises();

        expect(readFreshMock.mock.calls).toEqual([
            ['weigh_ins_show', { before: '2026-07-13' }],
            ['weigh_ins_show', { before: '2026-04-14' }],
        ]);
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Old Fri, 9 Oct', 'Old Thu, 8 Oct', 'Older Fri, 9 Oct', 'Older Thu, 8 Oct']);
    });

    it('WT-004 a failed older read keeps what is shown and says so, and Show older stays to try again', async () => {
        readFreshMock.mockRejectedValueOnce(new CapabilityError('weigh_ins_show', 0, null)).mockResolvedValueOnce(olderPage('Old'));
        const page = await view();
        const first = dates(page);

        await page.get('[data-test="show-older"]').trigger('click');
        await flushPromises();

        expect(dates(page)).toEqual(first);
        expect(page.get('[role="alert"]').text()).toBe('Older weigh-ins could not be loaded.');
        await page.get('[data-test="show-older"]').trigger('click');
        await flushPromises();
        expect(page.find('[role="alert"]').exists()).toBe(false);
        expect(dates(page)).toHaveLength(first.length * 2);
    });

    it('WT-004 when the newest page reads again, it reads each older page it had again, in order, and shows their rows', async () => {
        readFreshMock.mockResolvedValueOnce(olderPage('Old', '2026-04-14')).mockResolvedValueOnce(olderPage('Older'));
        const page = await view();
        await page.get('[data-test="show-older"]').trigger('click');
        await flushPromises();
        await page.get('[data-test="show-older"]').trigger('click');
        await flushPromises();
        readFreshMock.mockReset().mockResolvedValueOnce(olderPage('Edited', '2026-04-14')).mockResolvedValueOnce(olderPage('Older'));

        // A write's reload: the newest page reads again.
        await page.getComponent(WeighInsScreen).props('reload')();
        await flushPromises();

        expect(readFreshMock.mock.calls).toEqual([
            ['weigh_ins_show', { before: '2026-07-13' }],
            ['weigh_ins_show', { before: '2026-04-14' }],
        ]);
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Edited Fri, 9 Oct', 'Edited Thu, 8 Oct', 'Older Fri, 9 Oct', 'Older Thu, 8 Oct']);
    });

    it('WT-004 a failed read of an older page again keeps its old rows and says so', async () => {
        readFreshMock.mockResolvedValueOnce(olderPage('Old'));
        const page = await view();
        await page.get('[data-test="show-older"]').trigger('click');
        await flushPromises();
        readFreshMock.mockReset().mockRejectedValue(new CapabilityError('weigh_ins_show', 500, null));

        await page.getComponent(WeighInsScreen).props('reload')();
        await flushPromises();

        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Old Fri, 9 Oct', 'Old Thu, 8 Oct']);
        expect(page.get('[role="alert"]').text()).toBe('Older weigh-ins could not be loaded.');
    });
});
