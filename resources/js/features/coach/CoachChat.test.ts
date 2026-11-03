import 'fake-indexeddb/auto';
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cached, clearReads, readKey } from '@/app/cache';
import { CapabilityError, read, readFresh } from '@/app/call';
import { cancel, events, history, send, type ChatHistory, type TurnEvent } from '@/app/chatTransport';
import type { AgentStatusShowOutput } from '@/contracts/agent_status_show';
import type { CoachChangesListOutput } from '@/contracts/coach_changes_list';
import { screenFixture } from '@/testing/schemas';
import CoachChat from './CoachChat.vue';
import recorded from './fixtures/turn-events.json';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn() }));
vi.mock('@/app/chatTransport', async (original) => ({
    ...(await original<typeof import('@/app/chatTransport')>()),
    history: vi.fn(),
    send: vi.fn(),
    cancel: vi.fn(),
    events: vi.fn(),
}));

const readMock = vi.mocked(read);
const readFreshMock = vi.mocked(readFresh);
const historyMock = vi.mocked(history);
const sendMock = vi.mocked(send);
const cancelMock = vi.mocked(cancel);
const eventsMock = vi.mocked(events);

const status = screenFixture<AgentStatusShowOutput>('agent_status_show');
const CONVERSATION = status.conversation_ulid!;
const changes = screenFixture<CoachChangesListOutput>('coach_changes_list');
const stream = recorded.events as TurnEvent[];
const TURN = recorded.turn_ulid;
const ids = { conversation_ulid: CONVERSATION, message_ulid: '01JC0ACH0000000000000MSG02', turn_ulid: TURN };

const earlier: ChatHistory = {
    conversation_ulid: CONVERSATION,
    messages: [
        { ulid: '01JC0ACH0000000000000MSG00', role: 'user', content: 'How was my week?', created_at: null },
        { ulid: '01JC0ACH0000000000000MSG01', role: 'assistant', content: 'Four of five sessions done.', created_at: null },
    ],
};
const after: ChatHistory = {
    conversation_ulid: CONVERSATION,
    messages: [
        ...earlier.messages,
        { ulid: ids.message_ulid, role: 'user', content: 'I ran this morning, fix my meals', created_at: null },
        { ulid: '01JC0ACH0000000000000MSG03', role: 'assistant', content: 'Done. I moved carbs to breakfast.', created_at: null },
    ],
};

/** The recorded stream, handed out a few events per poll, from the cursor asked for. */
function playStream(polls: number[][]): void {
    let call = 0;
    eventsMock.mockImplementation(async (turn, cursor) => {
        const due = new Set(polls[Math.min(call++, polls.length - 1)]);
        return { turn_ulid: turn, events: stream.filter((event) => due.has(event.index) && event.index >= cursor) };
    });
}

function reads(fresh: Partial<Record<string, unknown>>): void {
    readFreshMock.mockImplementation(async (name) => fresh[name]);
}

function mountChat() {
    return mount(CoachChat, { props: { open: true }, global: { stubs: { teleport: true, RouterLink: RouterLinkStub } } });
}

function lines(wrapper: ReturnType<typeof mountChat>): string[] {
    return wrapper.findAll('[data-test="tool-line"]').map((line) => line.text());
}

async function sendMessage(wrapper: ReturnType<typeof mountChat>, text: string): Promise<void> {
    await wrapper.get('textarea').setValue(text);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
}

function setOnline(online: boolean): void {
    Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => online });
    window.dispatchEvent(new Event(online ? 'online' : 'offline'));
}

describe('CoachChat', () => {
    beforeEach(async () => {
        document.cookie = 'bb_cache_owner=a1; path=/';
        await clearReads();
        setOnline(true);
        for (const mock of [readMock, readFreshMock, historyMock, sendMock, cancelMock, eventsMock]) mock.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
        setOnline(true);
    });

    it('AGT-021 loads the conversation\'s history on open', async () => {
        readMock.mockResolvedValue(status);
        historyMock.mockResolvedValue(earlier);

        const wrapper = mountChat();
        await flushPromises();

        expect(historyMock).toHaveBeenCalledWith(CONVERSATION);
        expect(wrapper.text()).toContain('How was my week?');
        expect(wrapper.text()).toContain('Four of five sessions done.');
    });

    it('AGT-024 asks for no history before the user has a conversation', async () => {
        readMock.mockResolvedValue({ ...status, conversation_ulid: null });

        const wrapper = mountChat();
        await flushPromises();

        expect(historyMock).not.toHaveBeenCalled();
        expect(wrapper.find('textarea').attributes('disabled')).toBeUndefined();
    });

    it('AGT-021 sends a message, plays the turn from its events and stops polling at terminal', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        readMock.mockResolvedValue({ ...status, conversation_ulid: null });
        sendMock.mockResolvedValue(ids);
        reads({ agent_status_show: status, coach_changes_list: changes });
        historyMock.mockResolvedValue(after);
        // queued and running, then each tool call as it returns, then terminal.
        playStream([[0, 1], [2], [3], [4]]);
        const wrapper = mountChat();
        await flushPromises();

        await sendMessage(wrapper, 'I ran this morning, fix my meals');

        // The first message starts the conversation, then the next one carries it (AGT-024).
        expect(sendMock).toHaveBeenCalledWith('I ran this morning, fix my meals', null);
        expect(readFreshMock).toHaveBeenCalledWith('agent_status_show', {});
        expect(wrapper.text()).toContain('I ran this morning, fix my meals');
        expect(lines(wrapper)).toEqual([]);

        await vi.advanceTimersByTimeAsync(1000);
        expect(lines(wrapper)).toEqual([]);
        await vi.advanceTimersByTimeAsync(1000);
        expect(lines(wrapper)).toEqual(['Checking this week…']);
        await vi.advanceTimersByTimeAsync(1000);
        expect(lines(wrapper)).toEqual(['Checking this week…', 'Working…']);
        await vi.advanceTimersByTimeAsync(1000);
        // The history is stored in IndexedDB, which answers on its own clock.
        await vi.waitFor(() => expect(wrapper.findAll('[data-test="change-card"]')).toHaveLength(2));

        // Each poll passes back the cursor after the last event it got.
        expect(eventsMock.mock.calls).toEqual([
            [TURN, 0],
            [TURN, 2],
            [TURN, 3],
            [TURN, 4],
        ]);
        await vi.advanceTimersByTimeAsync(5000);
        expect(eventsMock).toHaveBeenCalledTimes(4);

        // The reply comes in one piece from history, then the turn's change cards under it.
        expect(historyMock).toHaveBeenCalledWith(CONVERSATION);
        expect(wrapper.text()).toContain('Done. I moved carbs to breakfast.');
        expect(readFreshMock).toHaveBeenCalledWith('coach_changes_list', { turn: TURN });
        const cards = wrapper.findAll('[data-test="change-card"]');
        expect(cards.map((card) => card.text())).toEqual([expect.stringContaining('Meals updated for Run'), expect.stringContaining('Weigh in log')]);
        expect(lines(wrapper)).toEqual([]);

        // The next message carries the conversation agent_status_show now names.
        eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [] });
        await sendMessage(wrapper, 'Thanks');
        expect(sendMock).toHaveBeenLastCalledWith('Thanks', CONVERSATION);
    });

    it('AGT-021 cancels a running turn and shows it cancelled', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        readMock.mockResolvedValue(status);
        historyMock.mockResolvedValue(earlier);
        sendMock.mockResolvedValue(ids);
        reads({ agent_status_show: status, coach_changes_list: { changes: [] } });
        playStream([[0, 1], [2], []]);
        cancelMock.mockResolvedValue({ turn_ulid: TURN, status: 'cancelled' });
        const wrapper = mountChat();
        await flushPromises();
        await sendMessage(wrapper, 'Plan my week');
        await vi.advanceTimersByTimeAsync(2000);
        expect(lines(wrapper)).toEqual(['Checking this week…']);

        await wrapper.get('[data-test="cancel-turn"]').trigger('click');
        await vi.waitFor(() => expect(wrapper.find('[data-test="turn-state"]').exists()).toBe(true));

        expect(cancelMock).toHaveBeenCalledWith(TURN);
        expect(readFreshMock).toHaveBeenCalledWith('coach_changes_list', { turn: TURN });
        expect(wrapper.get('[data-test="turn-state"]').text()).toBe('Cancelled');
        expect(wrapper.find('[data-test="cancel-turn"]').exists()).toBe(false);
        const polls = eventsMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(5000);
        expect(eventsMock).toHaveBeenCalledTimes(polls);
    });

    it('AGT-023 offline shows the last history it loaded from cache and disables the input', async () => {
        readMock.mockResolvedValue(status);
        historyMock.mockResolvedValue(earlier);
        const online = mountChat();
        await vi.waitFor(async () => expect(await cached(readKey('capabilities-ai.history', { conversation_ulid: CONVERSATION }))).toBeDefined());
        online.unmount();

        setOnline(false);
        historyMock.mockReset();
        historyMock.mockRejectedValue(new CapabilityError('chat.history', 0, { reason: 'network' }));
        const wrapper = mountChat();
        await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));

        expect(historyMock).toHaveBeenCalledWith(CONVERSATION);
        expect(wrapper.text()).toContain('The Coach needs a connection');
        expect(wrapper.get('textarea').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-test="send"]').attributes('disabled')).toBeDefined();
    });

    it('AGT-024 keeps the conversation when the status read after the first message fails', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        readMock.mockResolvedValue({ ...status, conversation_ulid: null });
        sendMock.mockResolvedValue(ids);
        let statusReads = 0;
        readFreshMock.mockImplementation(async (name) => {
            if (name === 'coach_changes_list') return { changes: [] };
            if (statusReads++ === 0) throw new CapabilityError('agent_status_show', 0, { reason: 'timeout' });
            return status;
        });
        historyMock.mockResolvedValue(after);
        playStream([[0, 1, 2, 3, 4]]);
        const wrapper = mountChat();
        await flushPromises();

        await sendMessage(wrapper, 'I ran this morning, fix my meals');
        await vi.advanceTimersByTimeAsync(1000);
        await vi.waitFor(() => expect(wrapper.text()).toContain('Done. I moved carbs to breakfast.'));

        // The turn's end reads the status again rather than losing the conversation.
        expect(historyMock).toHaveBeenCalledWith(CONVERSATION);
        expect(wrapper.text()).toContain('I ran this morning, fix my meals');
        eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [] });
        await sendMessage(wrapper, 'Thanks');
        expect(sendMock).toHaveBeenLastCalledWith('Thanks', CONVERSATION);
    });

    it('AGT-021 keeps polling through a server error until the terminal event', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        readMock.mockResolvedValue(status);
        historyMock.mockResolvedValue(after);
        sendMock.mockResolvedValue(ids);
        reads({ agent_status_show: status, coach_changes_list: { changes: [] } });
        playStream([[0, 1, 2, 3, 4]]);
        const played = eventsMock.getMockImplementation()!;
        eventsMock.mockImplementationOnce(async () => {
            throw new CapabilityError('chat.events', 503, null);
        });
        eventsMock.mockImplementation(played);
        const wrapper = mountChat();
        await flushPromises();

        await sendMessage(wrapper, 'Plan my week');
        await vi.advanceTimersByTimeAsync(1000);
        await vi.advanceTimersByTimeAsync(1000);
        await vi.waitFor(() => expect(wrapper.text()).toContain('Done. I moved carbs to breakfast.'));

        expect(wrapper.find('[data-test="turn-state"]').exists()).toBe(false);
        expect(eventsMock).toHaveBeenCalledTimes(2);
    });

    it('AGT-023 a send that gets no answer while online says so and leaves the input on', async () => {
        readMock.mockResolvedValue(status);
        historyMock.mockResolvedValue(earlier);
        sendMock.mockRejectedValue(new CapabilityError('chat.send', 0, { reason: 'network' }));
        const wrapper = mountChat();
        await flushPromises();

        await sendMessage(wrapper, 'Plan my week');

        expect(wrapper.find('[data-test="offline"]').exists()).toBe(false);
        expect(wrapper.get('textarea').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[role="alert"]').text()).toBe('No connection. Try again when you are online.');
    });

    it('AGT-021 an older history answer does not take away a message sent after it', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        readMock.mockResolvedValue(status);
        let answer: (value: ChatHistory) => void = () => {};
        historyMock.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));
        sendMock.mockResolvedValue(ids);
        reads({ agent_status_show: status });
        eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [] });
        const wrapper = mountChat();
        await vi.waitFor(() => expect(historyMock).toHaveBeenCalled());

        await sendMessage(wrapper, 'I ran this morning, fix my meals');
        answer(earlier);
        await flushPromises();

        expect(wrapper.text()).toContain('I ran this morning, fix my meals');
    });

    it('AGT-022 offers no photo attach control', async () => {
        readMock.mockResolvedValue(status);
        historyMock.mockResolvedValue(earlier);

        const wrapper = mountChat();
        await flushPromises();

        expect(wrapper.find('input[type="file"]').exists()).toBe(false);
    });
});
