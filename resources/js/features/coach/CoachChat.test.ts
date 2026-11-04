import 'fake-indexeddb/auto';
import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cached, clearReads, readKey } from '@/app/cache';
import { CapabilityError, read, readFresh } from '@/app/call';
import { cancel, events, history, send, type ChatHistory, type TurnEvent } from '@/app/chatTransport';
import type { AgentStatusShowOutput } from '@/contracts/agent_status_show';
import type { CoachChangesListOutput } from '@/contracts/coach_changes_list';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import { screenFixture } from '@/testing/schemas';
import OfflineNotice from '@/design/OfflineNotice.vue';
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

function mountChat(onboarding = false) {
    return mount(CoachChat, { props: { open: true, onboarding }, global: { stubs: { teleport: true, RouterLink: RouterLinkStub } } });
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
    // A chat left mounted keeps its online listener and would read again in the next test.
    enableAutoUnmount(afterEach);

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
        // A history ask would first wait on IndexedDB, which answers on its own clock.
        await new Promise((resolve) => setTimeout(resolve, 50));

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
        expect(sendMock).toHaveBeenCalledWith('I ran this morning, fix my meals', null, expect.any(String));
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
        expect(sendMock).toHaveBeenLastCalledWith('Thanks', CONVERSATION, expect.any(String));
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
        expect(sendMock).toHaveBeenLastCalledWith('Thanks', CONVERSATION, expect.any(String));
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
        // After a poll with no answer the next waits twice as long.
        await vi.advanceTimersByTimeAsync(2000);
        await vi.waitFor(() => expect(wrapper.text()).toContain('Done. I moved carbs to breakfast.'));

        expect(wrapper.find('[data-test="turn-state"]').exists()).toBe(false);
        expect(eventsMock).toHaveBeenCalledTimes(2);
    });

    it('AGT-023 a send that gets no answer while online says so and leaves the input on', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        readMock.mockResolvedValue(status);
        historyMock.mockResolvedValue(earlier);
        sendMock.mockRejectedValue(new CapabilityError('chat.send', 0, { reason: 'network' }));
        const wrapper = mountChat();
        await flushPromises();

        await sendMessage(wrapper, 'Plan my week');
        // It first reads the conversation again, to see whether the message landed anyway.
        await vi.waitFor(() => expect(wrapper.find('[role="alert"]').exists()).toBe(true));
        // Once the server can no longer be taking it, it says plainly that it did not go.
        await vi.advanceTimersByTimeAsync(30_000);
        await vi.waitFor(() => expect(wrapper.get('[role="alert"]').text()).toBe('No connection. Try again when you are online.'));

        expect(wrapper.find('[data-test="offline"]').exists()).toBe(false);
        expect(wrapper.get('textarea').attributes('disabled')).toBeUndefined();
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

    it('AGT-013 shows the turn\'s change cards when its reply cannot be loaded', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        readMock.mockResolvedValue(status);
        historyMock.mockResolvedValueOnce(earlier);
        historyMock.mockRejectedValue(new CapabilityError('chat.history', 503, null));
        sendMock.mockResolvedValue(ids);
        reads({ agent_status_show: status, coach_changes_list: changes });
        playStream([[0, 1, 2, 3, 4]]);
        const wrapper = mountChat();
        await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));

        await sendMessage(wrapper, 'I ran this morning, fix my meals');
        await vi.advanceTimersByTimeAsync(1000);
        await vi.waitFor(() => expect(readFreshMock).toHaveBeenCalledWith('coach_changes_list', { turn: TURN }));
        await flushPromises();

        // Not under the earlier reply, and not dropped: after the message that started the turn.
        const items = wrapper.findAll('ol > li');
        expect(items.at(-1)!.findAll('[data-test="change-card"]')).toHaveLength(2);
        expect(items.at(-2)!.text()).toBe('I ran this morning, fix my meals');
    });

    it('AGT-022 offers no photo attach control', async () => {
        readMock.mockResolvedValue(status);
        historyMock.mockResolvedValue(earlier);

        const wrapper = mountChat();
        await flushPromises();

        expect(wrapper.find('input[type="file"]').exists()).toBe(false);
    });
    describe('a turn that does not end well', () => {
        beforeEach(() => {
            vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
            readMock.mockResolvedValue(status);
            historyMock.mockResolvedValue(earlier);
            sendMock.mockResolvedValue(ids);
            reads({ agent_status_show: status, coach_changes_list: { changes: [] } });
        });

        const terminal = (ended: string): TurnEvent => ({ kind: 'terminal', data: { status: ended }, index: 3 });

        async function started() {
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));
            await sendMessage(wrapper, 'Plan my week');
            return wrapper;
        }

        it.each([
            ['failed', "The Coach couldn't finish that. Try again."],
            ['cancelled', 'Cancelled'],
        ])('AGT-021 shows a turn whose terminal event says %s, and stops polling', async (ended, words) => {
            eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [...stream.slice(0, 3), terminal(ended)] });
            const wrapper = await started();

            await vi.advanceTimersByTimeAsync(1000);
            await vi.waitFor(() => expect(wrapper.find('[data-test="turn-state"]').exists()).toBe(true));

            expect(wrapper.get('[data-test="turn-state"]').text()).toBe(words);
            await vi.advanceTimersByTimeAsync(10_000);
            expect(eventsMock).toHaveBeenCalledTimes(1);
        });

        it('AGT-021 ends the turn on a refused poll without reading history again', async () => {
            eventsMock.mockRejectedValue(new CapabilityError('chat.events', 403, { ok: false, error: { code: 'forbidden' } }));
            const wrapper = await started();
            const historyReads = historyMock.mock.calls.length;

            await vi.advanceTimersByTimeAsync(1000);
            await vi.waitFor(() => expect(wrapper.find('[data-test="turn-state"]').exists()).toBe(true));

            expect(wrapper.get('[data-test="turn-state"]').text()).toBe("The Coach couldn't finish that. Try again.");
            await vi.advanceTimersByTimeAsync(10_000);
            expect(eventsMock).toHaveBeenCalledTimes(1);
            expect(historyMock).toHaveBeenCalledTimes(historyReads);
        });

        it('AGT-013 puts a cancelled turn\'s change cards under its own message, not the earlier reply', async () => {
            playStream([[0, 1, 2], []]);
            cancelMock.mockResolvedValue({ turn_ulid: TURN, status: 'cancelled' });
            reads({ agent_status_show: status, coach_changes_list: changes });
            // The cancelled turn wrote no reply: history ends with the turn's own message.
            historyMock.mockResolvedValue({ conversation_ulid: CONVERSATION, messages: [...earlier.messages, { ulid: ids.message_ulid, role: 'user', content: 'Plan my week', created_at: null }] });
            const wrapper = await started();
            await vi.advanceTimersByTimeAsync(1000);

            await wrapper.get('[data-test="cancel-turn"]').trigger('click');
            await vi.waitFor(() => expect(wrapper.findAll('[data-test="change-card"]')).toHaveLength(2));

            const items = wrapper.findAll('ol > li');
            const cardsAt = items.findIndex((item) => item.find('[data-test="change-card"]').exists());
            expect(items[cardsAt - 1]!.text()).toBe('Plan my week');
        });

        it('AGT-021 a cancel that lands after the turn ended keeps polling to its terminal event', async () => {
            playStream([[0, 1], [2, 3, 4]]);
            historyMock.mockResolvedValueOnce(earlier).mockResolvedValue(after);
            cancelMock.mockRejectedValue(new CapabilityError('chat.cancel', 409, { ok: false, error: { code: 'conflict', message: 'Turn cannot be cancelled' } }));
            const wrapper = await started();
            await vi.advanceTimersByTimeAsync(1000);

            await wrapper.get('[data-test="cancel-turn"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-test="turn-state"]').exists()).toBe(false);
            expect(wrapper.find('[role="alert"]').exists()).toBe(false);

            await vi.advanceTimersByTimeAsync(1000);
            await vi.waitFor(() => expect(wrapper.text()).toContain('Done. I moved carbs to breakfast.'));
            expect(wrapper.find('[data-test="turn-state"]').exists()).toBe(false);
        });

        it('AGT-021 a cancel the server refuses says so and the turn keeps running', async () => {
            playStream([[0, 1], []]);
            cancelMock.mockRejectedValue(new CapabilityError('chat.cancel', 500, { ok: false, error: { code: 'server_error', message: 'Something went wrong.' } }));
            const wrapper = await started();
            await vi.advanceTimersByTimeAsync(1000);

            await wrapper.get('[data-test="cancel-turn"]').trigger('click');
            await flushPromises();

            expect(wrapper.get('[role="alert"]').text()).toBe('Something went wrong.');
            expect(wrapper.find('[data-test="cancel-turn"]').exists()).toBe(true);
            const polls = eventsMock.mock.calls.length;
            await vi.advanceTimersByTimeAsync(1000);
            expect(eventsMock.mock.calls.length).toBeGreaterThan(polls);
        });

        it('AGT-024 sends nothing while the conversation of an earlier message cannot be read', async () => {
            readMock.mockResolvedValue({ ...status, conversation_ulid: null });
            readFreshMock.mockImplementation(async (name) => {
                if (name === 'coach_changes_list') return { changes: [] };
                throw new CapabilityError('agent_status_show', 0, { reason: 'timeout' });
            });
            eventsMock.mockResolvedValue({ turn_ulid: TURN, events: stream });
            const wrapper = mountChat();
            await flushPromises();
            await sendMessage(wrapper, 'Plan my week');
            await vi.advanceTimersByTimeAsync(1000);
            await vi.waitFor(() => expect(wrapper.find('[data-test="cancel-turn"]').exists()).toBe(false));

            await sendMessage(wrapper, 'And next week?');

            expect(sendMock).toHaveBeenCalledTimes(1);
            expect(wrapper.get('[role="alert"]').text()).toBe("The Coach couldn't take that message. Try again.");
            expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('And next week?');
        });

        it('AGT-021 backs off while the events route fails, then stops and says so', async () => {
            eventsMock.mockRejectedValue(new CapabilityError('chat.events', 503, null));
            const wrapper = await started();

            await vi.advanceTimersByTimeAsync(1000);
            expect(eventsMock).toHaveBeenCalledTimes(1);
            // The next ask waits twice as long, and so on.
            await vi.advanceTimersByTimeAsync(1999);
            expect(eventsMock).toHaveBeenCalledTimes(1);
            await vi.advanceTimersByTimeAsync(1);
            expect(eventsMock).toHaveBeenCalledTimes(2);

            await vi.advanceTimersByTimeAsync(10 * 60_000);
            const asked = eventsMock.mock.calls.length;
            expect(asked).toBeLessThanOrEqual(10);
            expect(wrapper.get('[data-test="turn-state"]').text()).toBe("Lost touch with the Coach. Close and open the chat to see its reply.");
            expect(wrapper.find('[data-test="cancel-turn"]').exists()).toBe(false);
            await vi.advanceTimersByTimeAsync(10 * 60_000);
            expect(eventsMock).toHaveBeenCalledTimes(asked);
        });

        it('AGT-021 waits as long as a limit asks before polling again', async () => {
            eventsMock.mockRejectedValueOnce(new CapabilityError('chat.events', 429, { ok: false, error: { code: 'rate_limited', retry_after: 10 } }));
            eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [] });
            await started();

            await vi.advanceTimersByTimeAsync(1000);
            await vi.advanceTimersByTimeAsync(9999);
            expect(eventsMock).toHaveBeenCalledTimes(1);
            await vi.advanceTimersByTimeAsync(1);
            expect(eventsMock).toHaveBeenCalledTimes(2);
        });
    });

    describe('a message that gets no answer', () => {
        const waiting = 'The Coach may still be taking your message, so it was not sent again. It will be checked again in a moment.';

        beforeEach(() => {
            vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
            readMock.mockResolvedValue(status);
            reads({ agent_status_show: status });
        });

        it('AGT-021 sends nothing again until the conversation shows the unanswered message is not there', async () => {
            historyMock.mockResolvedValueOnce(earlier).mockRejectedValue(new CapabilityError('chat.history', 0, { reason: 'network' }));
            sendMock.mockRejectedValueOnce(new CapabilityError('chat.send', 0, { reason: 'network' }));
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));

            await sendMessage(wrapper, 'Plan my week');
            const unknown = "Couldn't check whether the Coach got your message, so it was not sent again. Try again once you're connected.";
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).toBe(unknown));

            // Still no way to check: Send checks again and sends nothing.
            await wrapper.get('form').trigger('submit');
            await vi.waitFor(() => expect(historyMock).toHaveBeenCalledTimes(3));
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).toBe(unknown));
            expect(sendMock).toHaveBeenCalledTimes(1);
            expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('Plan my week');

            // The conversation answers without the message once the server can no longer be
            // taking it (its deadline twice over): now it is sent again.
            historyMock.mockResolvedValue(earlier);
            await vi.advanceTimersByTimeAsync(30_000);
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).not.toBe(unknown));
            sendMock.mockResolvedValue(ids);
            eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [] });
            await wrapper.get('form').trigger('submit');
            await vi.waitFor(() => expect(sendMock).toHaveBeenCalledTimes(2));
            expect(sendMock).toHaveBeenLastCalledWith('Plan my week', CONVERSATION, expect.any(String));
        });

        /** The message key each send carried, in order. */
        function keys(): string[] {
            return sendMock.mock.calls.map(([, , key]) => key);
        }

        /** Sends `text` with no answer, then lets the grace period pass with history lacking it. */
        async function unansweredSend(wrapper: ReturnType<typeof mountChat>, text: string): Promise<void> {
            sendMock.mockRejectedValueOnce(new CapabilityError('chat.send', 0, { reason: 'network' }));
            await sendMessage(wrapper, text);
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).toBe(waiting));
            await vi.advanceTimersByTimeAsync(30_000);
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).toBe('No connection. Try again when you are online.'));
        }

        it('CAP-014 sends an unanswered message again with the same key', async () => {
            historyMock.mockResolvedValue(earlier);
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));

            await unansweredSend(wrapper, 'Plan my week');
            sendMock.mockResolvedValue(ids);
            eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [] });
            await wrapper.get('form').trigger('submit');
            await vi.waitFor(() => expect(sendMock).toHaveBeenCalledTimes(2));

            expect(keys()[0]).toMatch(/^chat\.send:/);
            expect(keys()[1]).toBe(keys()[0]);
        });

        it('CAP-014 sends an edited message with a new key, and the next message after an answer with another', async () => {
            historyMock.mockResolvedValue(earlier);
            eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [{ kind: 'terminal', data: { status: 'completed' }, index: 0 }] });
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));

            await unansweredSend(wrapper, 'Plan my week');
            sendMock.mockResolvedValue(ids);
            await sendMessage(wrapper, 'Plan my week, no squats');
            await vi.waitFor(() => expect(sendMock).toHaveBeenCalledTimes(2));
            await vi.advanceTimersByTimeAsync(1000);

            // The server answered, so the same words again are a new message.
            await wrapper.get('textarea').setValue('Plan my week, no squats');
            await vi.waitFor(() => expect(wrapper.get('[data-test="send"]').attributes('disabled')).toBeUndefined());
            await wrapper.get('form').trigger('submit');
            await vi.waitFor(() => expect(sendMock).toHaveBeenCalledTimes(3));

            expect(new Set(keys()).size).toBe(3);
        });

        it('CAP-014 sends the same words with a new key once the unanswered message landed', async () => {
            const landed: ChatHistory = { conversation_ulid: CONVERSATION, messages: [...earlier.messages, { ulid: ids.message_ulid, role: 'user', content: 'Plan my week', created_at: null }] };
            historyMock.mockResolvedValueOnce(earlier).mockResolvedValue(landed);
            sendMock.mockRejectedValueOnce(new CapabilityError('chat.send', 0, { reason: 'timeout' }));
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));
            await sendMessage(wrapper, 'Plan my week');
            await vi.waitFor(() => expect(wrapper.find('[data-test="landed"]').exists()).toBe(true));

            sendMock.mockResolvedValue(ids);
            eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [] });
            await sendMessage(wrapper, 'Plan my week');
            await vi.waitFor(() => expect(sendMock).toHaveBeenCalledTimes(2));

            expect(keys()[1]).not.toBe(keys()[0]);
        });

        it('CAP-014 keeps the key and the draft when the first send is still being taken (409)', async () => {
            historyMock.mockResolvedValue(earlier);
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));

            sendMock.mockRejectedValueOnce(new CapabilityError('chat.send', 409, { error: { code: 'conflict', message: 'A message with client_message_id is already stored', http_status: 409 } }));
            await sendMessage(wrapper, 'Plan my week');
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').exists()).toBe(true));
            expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('Plan my week');

            sendMock.mockResolvedValue(ids);
            eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [] });
            await wrapper.get('form').trigger('submit');
            await vi.waitFor(() => expect(sendMock).toHaveBeenCalledTimes(2));

            expect(keys()[1]).toBe(keys()[0]);
        });

        it('AGT-021 does not send an unanswered message again once the conversation shows it landed', async () => {
            const landed: ChatHistory = { conversation_ulid: CONVERSATION, messages: [...earlier.messages, { ulid: ids.message_ulid, role: 'user', content: 'Plan my week', created_at: null }] };
            historyMock.mockResolvedValueOnce(earlier).mockRejectedValueOnce(new CapabilityError('chat.history', 0, { reason: 'network' })).mockResolvedValue(landed);
            sendMock.mockRejectedValueOnce(new CapabilityError('chat.send', 0, { reason: 'timeout' }));
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));
            await sendMessage(wrapper, 'Plan my week');
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').exists()).toBe(true));

            await wrapper.get('form').trigger('submit');
            await vi.waitFor(() => expect(wrapper.find('[data-test="landed"]').exists()).toBe(true));

            expect(sendMock).toHaveBeenCalledTimes(1);
            expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('');
        });

        it('AGT-021 checks the conversation and does not offer to send again when the message landed', async () => {
            const landed: ChatHistory = { conversation_ulid: CONVERSATION, messages: [...earlier.messages, { ulid: ids.message_ulid, role: 'user', content: 'Plan my week', created_at: null }] };
            historyMock.mockResolvedValueOnce(earlier).mockResolvedValue(landed);
            sendMock.mockRejectedValue(new CapabilityError('chat.send', 0, { reason: 'timeout' }));
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));

            await sendMessage(wrapper, 'Plan my week');
            await vi.waitFor(() => expect(wrapper.find('[data-test="landed"]').exists()).toBe(true));

            expect(wrapper.text()).toContain('Plan my week');
            expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('');
            expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        });

        it('AGT-021 keeps the draft and says it may have gone when the message is not in the conversation', async () => {
            historyMock.mockResolvedValue(earlier);
            sendMock.mockRejectedValue(new CapabilityError('chat.send', 0, { reason: 'timeout' }));
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));

            await sendMessage(wrapper, 'Plan my week');
            // Absent now may only mean not yet: the server can still be taking it.
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).toBe(waiting));
            await wrapper.get('form').trigger('submit');
            await flushPromises();
            expect(sendMock).toHaveBeenCalledTimes(1);

            await vi.advanceTimersByTimeAsync(30_000);
            await vi.waitFor(() => expect(wrapper.get('[role="alert"]').text()).toBe('The server did not answer in time, so this may have worked. Check, then try again.'));
            expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('Plan my week');

            // Settled as not sent: Send sends it again.
            sendMock.mockResolvedValue(ids);
            eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [] });
            await wrapper.get('form').trigger('submit');
            await vi.waitFor(() => expect(sendMock).toHaveBeenCalledTimes(2));
        });

        it('AGT-021 a message found at the end of the grace period keeps a draft the user has since changed', async () => {
            const landed: ChatHistory = { conversation_ulid: CONVERSATION, messages: [...earlier.messages, { ulid: ids.message_ulid, role: 'user', content: 'Plan my week', created_at: null }] };
            historyMock.mockResolvedValue(earlier);
            sendMock.mockRejectedValue(new CapabilityError('chat.send', 0, { reason: 'timeout' }));
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));
            await sendMessage(wrapper, 'Plan my week');
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).toBe(waiting));

            await wrapper.get('textarea').setValue('And next week?');
            historyMock.mockResolvedValue(landed);
            await vi.advanceTimersByTimeAsync(30_000);
            await vi.waitFor(() => expect(wrapper.find('[data-test="landed"]').exists()).toBe(true));

            expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('And next week?');
            expect(sendMock).toHaveBeenCalledTimes(1);
        });

        it('AGT-021 a chat closed during the grace period checks nothing more', async () => {
            historyMock.mockResolvedValue(earlier);
            sendMock.mockRejectedValue(new CapabilityError('chat.send', 0, { reason: 'timeout' }));
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));
            await sendMessage(wrapper, 'Plan my week');
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).toBe(waiting));
            const reads = historyMock.mock.calls.length;

            wrapper.unmount();
            await vi.advanceTimersByTimeAsync(60_000);
            // A check would first wait on IndexedDB, which answers on its own clock.
            for (let i = 0; i < 20; i++) await new Promise((resolve) => setImmediate(resolve));

            expect(historyMock).toHaveBeenCalledTimes(reads);
        });

        it('AGT-021 a Send whose check began before the grace ended still waits, and the check after it settles the message', async () => {
            historyMock.mockResolvedValue(earlier);
            sendMock.mockRejectedValue(new CapabilityError('chat.send', 0, { reason: 'timeout' }));
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));
            await sendMessage(wrapper, 'Plan my week');
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).toBe(waiting));

            // A Send checks the conversation, and that read is still out when the grace ends.
            let answer: (value: ChatHistory) => void = () => {};
            historyMock.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));
            const reads = historyMock.mock.calls.length;
            await wrapper.get('form').trigger('submit');
            await vi.waitFor(() => expect(historyMock).toHaveBeenCalledTimes(reads + 1));
            await vi.advanceTimersByTimeAsync(30_000);
            answer(earlier);
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).toBe(waiting));
            expect(sendMock).toHaveBeenCalledTimes(1);

            // The check after it starts past the grace, so it settles the message as not sent.
            await vi.advanceTimersByTimeAsync(1000);
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).toBe('The server did not answer in time, so this may have worked. Check, then try again.'));
            expect(sendMock).toHaveBeenCalledTimes(1);
            expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('Plan my week');
        });

        it('AGT-021 a message the server takes after the deadline shows as landed, not sent twice', async () => {
            const landed: ChatHistory = { conversation_ulid: CONVERSATION, messages: [...earlier.messages, { ulid: ids.message_ulid, role: 'user', content: 'Plan my week', created_at: null }] };
            historyMock.mockResolvedValue(earlier);
            sendMock.mockRejectedValue(new CapabilityError('chat.send', 0, { reason: 'timeout' }));
            const wrapper = mountChat();
            await vi.waitFor(() => expect(wrapper.text()).toContain('Four of five sessions done.'));
            await sendMessage(wrapper, 'Plan my week');
            await vi.waitFor(() => expect(wrapper.find('[role="alert"]').text()).toBe(waiting));

            // The server finishes taking it after the first check.
            historyMock.mockResolvedValue(landed);
            await vi.advanceTimersByTimeAsync(30_000);
            await vi.waitFor(() => expect(wrapper.find('[data-test="landed"]').exists()).toBe(true));

            expect(sendMock).toHaveBeenCalledTimes(1);
            expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('');
            expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        });
    });

    it('AGT-023 shows the shared offline notice', async () => {
        setOnline(false);
        readMock.mockResolvedValue(status);
        historyMock.mockRejectedValue(new CapabilityError('chat.history', 0, { reason: 'network' }));

        const wrapper = mountChat();
        await flushPromises();

        expect(wrapper.getComponent(OfflineNotice).props('needs')).toBe('The Coach needs a connection.');
    });

    describe('setup by chat', () => {
        const review = screenFixture<OnboardingShowOutput>('onboarding_show');
        const screening = screenFixture<OnboardingShowOutput>('onboarding_show.screening');
        const goal = screenFixture<OnboardingShowOutput>('onboarding_show.empty');

        /** One turn: running, the given tool results, then terminal. */
        function turnWith(tools: { name: string; ok: boolean }[]): void {
            const events: TurnEvent[] = [
                { kind: 'status', data: { status: 'running' }, at: '2026-11-03T08:00:01+00:00', index: 0 },
                ...tools.map((data, i) => ({ kind: 'tool', data: { ...data, payload: {}, error_code: null, tool_call_id: `toolu_${i}` }, at: '2026-11-03T08:00:02+00:00', index: i + 1 })),
                { kind: 'terminal', data: { status: 'completed' }, at: '2026-11-03T08:00:03+00:00', index: tools.length + 1 },
            ];
            eventsMock.mockImplementation(async (turn, cursor) => ({ turn_ulid: turn, events: events.filter((event) => event.index >= cursor) }));
        }

        it('AGT-031 reads no setup outside setup', async () => {
            readMock.mockResolvedValue(status);
            historyMock.mockResolvedValue(earlier);
            mountChat();
            await flushPromises();

            expect(readFreshMock).not.toHaveBeenCalledWith('onboarding_show', {});
        });

        it('AGT-031 in setup, says what the Coach does when there is no conversation yet', async () => {
            readMock.mockResolvedValue({ ...status, conversation_ulid: null });
            reads({ onboarding_show: goal });
            const wrapper = mountChat(true);
            await flushPromises();

            expect(wrapper.text()).toContain('Tell the Coach your goal');
            expect(wrapper.find('[data-test="review-card"]').exists()).toBe(false);
        });

        it('AGT-032 in setup, shows the review as a card in the chat, and Start my plan sends the confirm line', async () => {
            readMock.mockResolvedValue(status);
            historyMock.mockResolvedValue(earlier);
            sendMock.mockResolvedValue(ids);
            reads({ onboarding_show: review, agent_status_show: status, coach_changes_list: { changes: [] } });
            eventsMock.mockResolvedValue({ turn_ulid: TURN, events: [] });
            const wrapper = mountChat(true);
            await flushPromises();

            expect(readFreshMock).toHaveBeenCalledWith('onboarding_show', {});
            await wrapper.get('textarea').setValue('Can I train on Sunday');
            await wrapper.get('[data-test="start-plan"]').trigger('click');
            await flushPromises();

            expect(sendMock).toHaveBeenCalledWith('confirm: onboarding_complete{}', CONVERSATION, expect.any(String));
            expect(wrapper.text()).toContain('confirm: onboarding_complete{}');
            // What the user was typing stays theirs.
            expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('Can I train on Sunday');
        });

        it('AGT-031 reads setup again when a turn ends, so the screening sheet opens once the Coach reaches it', async () => {
            vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
            readMock.mockResolvedValue(status);
            historyMock.mockResolvedValue(earlier);
            sendMock.mockResolvedValue(ids);
            let setup = goal;
            readFreshMock.mockImplementation(async (name) => ({ onboarding_show: setup, agent_status_show: status, coach_changes_list: { changes: [] } })[name]);
            turnWith([{ name: 'onboarding_answer', ok: true }]);
            const wrapper = mountChat(true);
            await flushPromises();
            expect(wrapper.findAll('[role="dialog"]')).toHaveLength(1);

            setup = screening;
            await sendMessage(wrapper, 'Fat loss, and I have not dieted lately.');
            await vi.advanceTimersByTimeAsync(1000);

            await vi.waitFor(() => expect(wrapper.findAll('[role="dialog"]')).toHaveLength(2));
            expect(wrapper.findAll('[role="dialog"]')[1]!.text()).toContain(screening.screening.prompt);
        });

        it('AGT-032 says the plan started once the turn\'s onboarding_complete comes back done', async () => {
            vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
            readMock.mockResolvedValue(status);
            historyMock.mockResolvedValue(earlier);
            sendMock.mockResolvedValue(ids);
            reads({ onboarding_show: review, agent_status_show: status, coach_changes_list: { changes: [] } });
            turnWith([{ name: 'onboarding_complete', ok: true }]);
            const wrapper = mountChat(true);
            await flushPromises();

            await wrapper.get('[data-test="start-plan"]').trigger('click');
            await flushPromises();
            await vi.advanceTimersByTimeAsync(1000);

            await vi.waitFor(() => expect(wrapper.emitted('started')).toHaveLength(1));
            // The draft is gone once the plan starts, so there is no review card left to press.
            expect(wrapper.find('[data-test="review-card"]').exists()).toBe(false);
        });

        it('AGT-032 a refused onboarding_complete starts nothing', async () => {
            vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
            readMock.mockResolvedValue(status);
            historyMock.mockResolvedValue(earlier);
            sendMock.mockResolvedValue(ids);
            reads({ onboarding_show: review, agent_status_show: status, coach_changes_list: { changes: [] } });
            turnWith([{ name: 'onboarding_complete', ok: false }]);
            const wrapper = mountChat(true);
            await flushPromises();

            await sendMessage(wrapper, 'Start it');
            await vi.advanceTimersByTimeAsync(2000);
            await flushPromises();

            expect(wrapper.emitted('started')).toBeUndefined();
            expect(wrapper.find('[data-test="review-card"]').exists()).toBe(true);
        });
    });
});
