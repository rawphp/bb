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
    /** What Show older's reads answer, in turn: a page, or an error to fail with. */
    let olderAnswers: (WeighInsShowOutput | Error)[];
    /** The `before` of each Show older read. */
    const olderReads = () => readMock.mock.calls.filter(([, input]) => input?.before !== undefined).map(([, input]) => input!.before);

    beforeEach(() => {
        olderAnswers = [];
        // Each read answers with its own copy, as the server and the cache do.
        readMock.mockReset().mockImplementation(async (_name, input) => {
            if (input?.before === undefined) return structuredClone(newest);
            const answer = olderAnswers.shift();
            if (answer === undefined || answer instanceof Error) throw answer ?? new Error('no answer');
            return answer;
        });
        readFreshMock.mockReset();
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    async function showOlder(page: VueWrapper): Promise<void> {
        await page.get('[data-test="show-older"]').trigger('click');
        await flushPromises();
    }

    it('WT-004 WT-020 Show older reads the page before next_before and adds its weigh-ins and steps after the first', async () => {
        olderAnswers = [olderPage('Old')];
        const page = await view();
        const first = dates(page);

        await showOlder(page);

        expect(olderReads()).toEqual(['2026-07-13']);
        expect(dates(page)).toEqual([...first, ...first.map((date) => `Old ${date}`)]);
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Old Fri, 9 Oct', 'Old Thu, 8 Oct']);
        expect(page.find('[data-test="show-older"]').exists()).toBe(false);
    });

    it('WT-008 a week a page boundary cut in two shows under one header, the first page\'s', async () => {
        const week = content.weeks[1]!;
        const older: WeighInsShowOutput = {
            ...olderPage('Old'),
            rows: olderPage('Old').rows.map((row) => ({ ...row, week: week.week })),
            weeks: [{ ...week, title: 'Old title' }],
        };
        olderAnswers = [older];
        const page = await view();

        await showOlder(page);

        const weeks = page.findAll('[data-test="week"]');
        expect(weeks.map((header) => header.get('h2').text())).toEqual(['This week', '28 Sep – 4 Oct']);
        expect(weeks[1]!.findAll('[data-test="weigh-in"]')).toHaveLength(1 + older.rows.length);
    });

    it('WT-004 reads on from the page it last added', async () => {
        olderAnswers = [olderPage('Old', '2026-04-14'), olderPage('Older')];
        const page = await view();

        await showOlder(page);
        await showOlder(page);

        expect(olderReads()).toEqual(['2026-07-13', '2026-04-14']);
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Old Fri, 9 Oct', 'Old Thu, 8 Oct', 'Older Fri, 9 Oct', 'Older Thu, 8 Oct']);
    });

    it('OFF-003 an older page cached on the device shows at once, and the server\'s copy replaces it', async () => {
        let refresh: ((data: WeighInsShowOutput) => void) | undefined;
        const page = await view();
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            refresh = onUpdate as (data: WeighInsShowOutput) => void;
            return olderPage('Cached');
        });

        await showOlder(page);
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Cached Fri, 9 Oct', 'Cached Thu, 8 Oct']);
        refresh!(olderPage('Fresh'));
        await flushPromises();

        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Fresh Fri, 9 Oct', 'Fresh Thu, 8 Oct']);
    });

    it('WT-004 a failed older read keeps what is shown and says so, and Show older stays to try again', async () => {
        olderAnswers = [new CapabilityError('weigh_ins_show', 0, null), olderPage('Old')];
        const page = await view();
        const first = dates(page);

        await showOlder(page);

        expect(dates(page)).toEqual(first);
        expect(page.get('[role="alert"]').text()).toBe('Older weigh-ins could not be loaded.');
        await showOlder(page);
        expect(page.find('[role="alert"]').exists()).toBe(false);
        expect(dates(page)).toHaveLength(first.length * 2);
    });

    it('WT-004 when the newest page reads again, it reads each older page it had again, in order, and shows their rows', async () => {
        olderAnswers = [olderPage('Old', '2026-04-14'), olderPage('Older')];
        const page = await view();
        await showOlder(page);
        await showOlder(page);
        readFreshMock.mockResolvedValueOnce(olderPage('Edited', '2026-04-14')).mockResolvedValueOnce(olderPage('Older'));

        // A write's reload: the newest page reads again.
        await page.getComponent(WeighInsScreen).props('reload')();
        await flushPromises();

        expect(readFreshMock.mock.calls).toEqual([
            ['weigh_ins_show', { before: '2026-07-13' }],
            ['weigh_ins_show', { before: '2026-04-14' }],
        ]);
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Edited Fri, 9 Oct', 'Edited Thu, 8 Oct', 'Older Fri, 9 Oct', 'Older Thu, 8 Oct']);
    });

    it('WT-004 when the newest page has moved on a day, the older pages read again from where it now ends, so no day goes missing', async () => {
        olderAnswers = [olderPage('Old', '2026-04-14'), olderPage('Older')];
        const page = await view();
        await showOlder(page);
        await showOlder(page);
        // Past UTC midnight the newest page ends a day later.
        readMock.mockImplementationOnce(async () => ({ ...structuredClone(newest), next_before: '2026-07-14' }));
        readFreshMock.mockResolvedValueOnce(olderPage('Moved', '2026-04-15')).mockResolvedValueOnce(olderPage('Older'));

        await page.getComponent(WeighInsScreen).props('reload')();
        await flushPromises();

        expect(readFreshMock.mock.calls).toEqual([
            ['weigh_ins_show', { before: '2026-07-14' }],
            ['weigh_ins_show', { before: '2026-04-15' }],
        ]);
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Moved Fri, 9 Oct', 'Moved Thu, 8 Oct', 'Older Fri, 9 Oct', 'Older Thu, 8 Oct']);
    });

    it('WT-004 a failed read of an older page again keeps its old rows and says so', async () => {
        olderAnswers = [olderPage('Old')];
        const page = await view();
        await showOlder(page);
        readFreshMock.mockRejectedValue(new CapabilityError('weigh_ins_show', 500, null));

        await page.getComponent(WeighInsScreen).props('reload')();
        await flushPromises();

        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Old Fri, 9 Oct', 'Old Thu, 8 Oct']);
        expect(page.get('[role="alert"]').text()).toBe('Older weigh-ins could not be loaded.');
    });

    it('WT-004 when a re-read page has nothing older now, the pages below it go', async () => {
        olderAnswers = [olderPage('Old', '2026-04-14'), olderPage('Older')];
        const page = await view();
        await showOlder(page);
        await showOlder(page);
        // The oldest weigh-ins were deleted: the first older page is now the last.
        readFreshMock.mockResolvedValueOnce(olderPage('Edited'));

        await page.getComponent(WeighInsScreen).props('reload')();
        await flushPromises();

        expect(readFreshMock).toHaveBeenCalledTimes(1);
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Edited Fri, 9 Oct', 'Edited Thu, 8 Oct']);
        expect(page.find('[data-test="show-older"]').exists()).toBe(false);
    });

    it('WT-004 when the newest page has nothing older now, every older page goes', async () => {
        olderAnswers = [olderPage('Old')];
        const page = await view();
        await showOlder(page);
        readMock.mockImplementationOnce(async () => ({ ...structuredClone(newest), next_before: null }));

        await page.getComponent(WeighInsScreen).props('reload')();
        await flushPromises();

        expect(readFreshMock).not.toHaveBeenCalled();
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct']);
    });

    it('WT-004 a page that fails to read again keeps its rows, and the pages below it read on from them', async () => {
        olderAnswers = [olderPage('Old', '2026-04-14'), olderPage('Older')];
        const page = await view();
        await showOlder(page);
        await showOlder(page);
        readFreshMock.mockRejectedValueOnce(new CapabilityError('weigh_ins_show', 500, null)).mockResolvedValueOnce(olderPage('Older again'));

        await page.getComponent(WeighInsScreen).props('reload')();
        await flushPromises();

        expect(readFreshMock.mock.calls.map(([, input]) => input)).toEqual([{ before: '2026-07-13' }, { before: '2026-04-14' }]);
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Old Fri, 9 Oct', 'Old Thu, 8 Oct', 'Older again Fri, 9 Oct', 'Older again Thu, 8 Oct']);
        expect(page.get('[role="alert"]').text()).toBe('Older weigh-ins could not be loaded.');
    });

    it('WT-004 a re-read that goes through clears the alert a failed one left', async () => {
        olderAnswers = [olderPage('Old')];
        const page = await view();
        await showOlder(page);
        readFreshMock.mockRejectedValueOnce(new CapabilityError('weigh_ins_show', 500, null)).mockResolvedValueOnce(olderPage('Old'));
        const reload = page.getComponent(WeighInsScreen).props('reload') as () => Promise<void>;

        await reload();
        await flushPromises();
        expect(page.find('[role="alert"]').exists()).toBe(true);
        await reload();
        await flushPromises();

        expect(page.find('[role="alert"]').exists()).toBe(false);
    });

    it('WT-004 a failure from a re-read the next one overtook says nothing', async () => {
        olderAnswers = [olderPage('Old')];
        const page = await view();
        await showOlder(page);
        let failFirst: ((error: Error) => void) | undefined;
        readFreshMock
            .mockImplementationOnce(() => new Promise((_resolve, reject) => (failFirst = reject)))
            .mockResolvedValueOnce(olderPage('Second'));
        const reload = page.getComponent(WeighInsScreen).props('reload') as () => Promise<void>;

        await reload();
        await reload();
        await flushPromises();
        failFirst!(new CapabilityError('weigh_ins_show', 500, null));
        await flushPromises();

        expect(page.find('[role="alert"]').exists()).toBe(false);
        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Second Fri, 9 Oct', 'Second Thu, 8 Oct']);
    });

    it('OFF-003 a cached page\'s late refresh does not replace the page a newer re-read put there', async () => {
        let refresh: ((data: WeighInsShowOutput) => void) | undefined;
        const page = await view();
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            refresh = onUpdate as (data: WeighInsShowOutput) => void;
            return olderPage('Cached');
        });
        await showOlder(page);
        readMock.mockImplementationOnce(async () => ({ ...structuredClone(newest), next_before: '2026-07-14' }));
        readFreshMock.mockResolvedValueOnce(olderPage('Moved'));
        await page.getComponent(WeighInsScreen).props('reload')();
        await flushPromises();

        refresh!(olderPage('Late'));
        await flushPromises();

        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Moved Fri, 9 Oct', 'Moved Thu, 8 Oct']);
    });

    it('OFF-003 a cached page\'s late refresh does not replace a re-read of the same page after a write', async () => {
        let refresh: ((data: WeighInsShowOutput) => void) | undefined;
        const page = await view();
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            refresh = onUpdate as (data: WeighInsShowOutput) => void;
            return olderPage('Cached');
        });
        await showOlder(page);
        readFreshMock.mockResolvedValueOnce(olderPage('Edited'));
        await page.getComponent(WeighInsScreen).props('reload')();
        await flushPromises();

        // The refresh asked before the write, so it holds the rows from before it.
        refresh!(olderPage('Before the write'));
        await flushPromises();

        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Edited Fri, 9 Oct', 'Edited Thu, 8 Oct']);
    });

    it('WT-004 an older answer from a re-read the next one overtook is dropped', async () => {
        olderAnswers = [olderPage('Old')];
        const page = await view();
        await showOlder(page);
        let answerFirst: ((data: WeighInsShowOutput) => void) | undefined;
        readFreshMock
            .mockImplementationOnce(() => new Promise((resolve) => (answerFirst = resolve)))
            .mockResolvedValueOnce(olderPage('Second'));
        const reload = page.getComponent(WeighInsScreen).props('reload') as () => Promise<void>;

        await reload();
        await reload();
        await flushPromises();
        answerFirst!(olderPage('First'));
        await flushPromises();

        expect(stepDates(page)).toEqual(['Fri, 9 Oct', 'Thu, 8 Oct', 'Second Fri, 9 Oct', 'Second Thu, 8 Oct']);
    });
});
