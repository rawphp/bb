import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { ExerciseHistoryShowOutput } from '@/contracts/exercise_history_show';
import schema from '../../../../tests/fixtures/schemas/exercise_history_show.input.json';
import emptyFixture from '../../../../tests/fixtures/screens/exercise_history_show.empty.json';
import contentFixture from '../../../../tests/fixtures/screens/exercise_history_show.json';
import progress from '../../../../tests/fixtures/screens/progress_show.json';
import progressEmpty from '../../../../tests/fixtures/screens/progress_show.empty.json';
import ExerciseHistoryContent from './ExerciseHistoryContent.vue';
import ExerciseHistoryView from './ExerciseHistoryView.vue';
import ExerciseListView from './ExerciseListView.vue';
import { expectMatchesInputSchema } from '@/testing/inputSchema';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
const content = contentFixture as ExerciseHistoryShowOutput;
const empty = emptyFixture as ExerciseHistoryShowOutput;
const stubs = { global: { stubs: { RouterLink: RouterLinkStub } } };

describe('ExerciseHistoryContent', () => {
    it('ANA-009 shows every session with its sets and marks the best e1RM', () => {
        const wrapper = mount(ExerciseHistoryContent, { props: { payload: content } });
        const sessions = wrapper.findAll('[data-session]');

        expect(sessions).toHaveLength(2);
        expect(sessions[0]!.text()).toContain('Thu, 8 Oct 2026');
        expect(sessions[0]!.text()).toContain('Best e1RM 81.3 kg');
        const sets = sessions[0]!.findAll('[data-set]');
        expect(sets.map((s) => s.get('span').text())).toEqual(['65.0 kg × 8', '65.0 kg × 7', '62.5 kg × 8']);
        expect(sets.map((s) => s.text().includes('Best e1RM'))).toEqual([true, false, false]);
        expect(sets[0]!.attributes('aria-description')).toBe('Best estimated one rep max');
    });

    it('ANA-009 says when no session is logged yet', () => {
        const wrapper = mount(ExerciseHistoryContent, { props: { payload: empty } });

        expect(wrapper.text()).toContain('No sessions logged yet.');
        expect(wrapper.find('[data-session]').exists()).toBe(false);
    });

    it('PWA-024 shows skeletons while loading', () => {
        const wrapper = mount(ExerciseHistoryContent);

        expect(wrapper.get('[role="status"]').text()).toBe('Loading history');
    });

    it('CAP-012 OFF-003 says why the history did not load', () => {
        expect(mount(ExerciseHistoryContent, { props: { error: 'History could not be loaded.' } }).get('[role="alert"]').text()).toBe(
            'History could not be loaded.',
        );
        expect(mount(ExerciseHistoryContent, { props: { offline: true } }).text()).toContain('Exercise history needs a connection.');
    });

    it('OFF-008 dims the sessions and says they are waiting to sync', () => {
        const wrapper = mount(ExerciseHistoryContent, { props: { payload: content, pending: true } });

        expect(wrapper.get('[data-session]').text()).toContain('Waiting to sync');
    });
});

describe('ExerciseHistoryView', () => {
    beforeEach(() => readMock.mockReset());

    it('CAP-001 renders the payload it is given without calling the server', () => {
        const wrapper = mount(ExerciseHistoryView, { props: { payload: content } });

        expect(wrapper.get('h1').text()).toBe('Bench Press');
        expect(wrapper.text()).toContain('Chest');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('ANA-009 reads exercise_history_show for the exercise in the route', async () => {
        readMock.mockResolvedValueOnce(content);
        const wrapper = mount(ExerciseHistoryView, { props: { exerciseId: '01JA000000000000000000EX01' } });
        await flushPromises();

        const input = readMock.mock.calls[0]![1]!;
        expect(readMock.mock.calls[0]![0]).toBe('exercise_history_show');
        expect(input).toEqual({ exercise_id: '01JA000000000000000000EX01' });
        expectMatchesInputSchema(schema, input);
        expect(wrapper.get('h1').text()).toBe('Bench Press');
    });

    it.each([
        [new CapabilityError('exercise_history_show', 0, null), 'Exercise history needs a connection.'],
        [new CapabilityError('exercise_history_show', 404, null), 'History could not be loaded.'],
    ])('CAP-012 says why the history did not load', async (error, message) => {
        readMock.mockRejectedValueOnce(error);
        const wrapper = mount(ExerciseHistoryView, { props: { exerciseId: 'x' } });
        await flushPromises();

        expect(wrapper.text()).toContain(message);
    });
});

describe('ExerciseListView', () => {
    beforeEach(() => readMock.mockReset());

    it('ANA-009 lists logged exercises from progress_show, each linking to its history', async () => {
        readMock.mockResolvedValueOnce(progress);
        const wrapper = mount(ExerciseListView, stubs);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('progress_show', {}, expect.any(Function), expect.any(Function));
        const links = wrapper.findAllComponents(RouterLinkStub);
        expect(links.map((l) => l.props('to'))).toEqual(['/progress/exercises/01JA000000000000000000EX01', '/progress/exercises/01JA000000000000000000EX02']);
        expect(links[0]!.text()).toContain('Bench Press');
        expect(links[0]!.text()).toContain('Chest');
    });

    it('ANA-009 says when no exercise is logged yet', async () => {
        readMock.mockResolvedValueOnce(progressEmpty);
        const wrapper = mount(ExerciseListView, stubs);
        await flushPromises();

        expect(wrapper.text()).toContain('No exercises logged yet.');
    });

    it.each([
        [0, 'Exercise history needs a connection.'],
        [401, 'You are signed out.'],
    ])('OFF-003 CAP-012 says why the list did not load (%i)', async (status, message) => {
        readMock.mockRejectedValueOnce(new CapabilityError('progress_show', status, null));
        const wrapper = mount(ExerciseListView, stubs);
        await flushPromises();

        expect(wrapper.text()).toContain(message);
        expect(wrapper.find('[data-placeholder]').exists()).toBe(false);
    });

        it('PWA-024 CAP-012 shows skeletons, then why the list did not load', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('progress_show', 500, null));
        const wrapper = mount(ExerciseListView, stubs);
        expect(wrapper.find('[data-placeholder]').exists()).toBe(true);
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Exercises could not be loaded.');
    });
});
