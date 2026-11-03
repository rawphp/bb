<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { cached, readKey, store } from '@/app/cache';
import { CapabilityError, read, readFresh, TIMEOUT_MS } from '@/app/call';
import { cancel, events, history, send, type ChatHistory, type ChatMessage } from '@/app/chatTransport';
import { failureMessage, writeFailureMessage } from '@/app/errors';
import type { AgentStatusShowOutput } from '@/contracts/agent_status_show';
import type { CoachChangesListInput, CoachChangesListOutput } from '@/contracts/coach_changes_list';
import BottomSheet from '@/design/BottomSheet.vue';
import OfflineNotice from '@/design/OfflineNotice.vue';
import ChangeCard from './ChangeCard.vue';
import { toolLine } from './toolLabels';

// The Coach chat (AGT-021, 19-views.md): a full-height sheet over the user's one
// conversation, which agent_status_show names; the client never keeps the ULID itself.
// A turn's progress is a poll of the package's events, about once a second, until its
// terminal event. Each tool call adds a line when it comes back. The package does not
// stream at v0.10.0, so the reply shows in one piece, from history, when the turn ends,
// and the turn's writes follow as change cards (AGT-013). Offline it shows the history
// it last loaded and needs a connection to send (AGT-023). No photo attach (AGT-022 is later).
const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();

// `lost`: the events route stopped answering, so the client stopped asking; the turn
// may still finish on the server, and the next open shows its reply.
type TurnState = 'running' | 'done' | 'cancelled' | 'failed' | 'lost';
// `message` is the user message that started the turn. `ended` is set once the terminal
// event or a cancel lands; `state` follows once the reply and its cards are on screen, so
// nothing is sent in between. `failures` counts polls in a row that got no answer.
type Turn = { ulid: string; message: string; lines: string[]; state: TurnState; cursor: number; ended: boolean; failures: number };
type Changes = CoachChangesListOutput['changes'];

const POLL_MS = 1000;
// A poll with no answer waits twice as long as the last, up to this, and after
// LOST_AFTER of them in a row (about two minutes) the client stops asking.
const BACKOFF_MAX_MS = 30_000;
const LOST_AFTER = 8;
// The history's cache key: beside the capability reads, so a sign-out clears it (OFF-012).
const historyKey = (conversation: string) => readKey('capabilities-ai.history', { conversation_ulid: conversation });

const status = ref<AgentStatusShowOutput | null>(null);
const messages = ref<ChatMessage[]>([]);
const turn = ref<Turn | null>(null);
// A turn's change cards, under the reply they belong to.
const cards = ref<{ after: string | null; changes: Changes }[]>([]);
const draft = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
// A message whose send got no answer but which history shows the Coach received.
const landed = ref(false);
// A message whose send got no answer and whose fate the conversation has not shown yet,
// with the messages on screen before it. Nothing is sent until a check settles it.
// `graceOver` turns true once the server can no longer be taking it (GRACE_MS after the
// failure); until then a conversation without it means "not yet", not "not sent".
type Unanswered = { content: string; before: Set<string>; failure: CapabilityError; graceOver: boolean };
let unanswered: Unanswered | null = null;
let graceTimer: ReturnType<typeof setTimeout> | undefined;
// Twice the client's deadline: a request the server is still working on past the
// deadline has landed by then or will not land.
const GRACE_MS = 2 * TIMEOUT_MS;
const UNCHECKED = "Couldn't check whether the Coach got your message, so it was not sent again. Try again once you're connected.";
const WAITING = 'The Coach may still be taking your message, so it was not sent again. It will be checked again in a moment.';
// The device's own word: a request that gets no answer while online is an error, not offline.
const offline = ref(typeof navigator !== 'undefined' && navigator.onLine === false);
const end = ref<HTMLElement | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let unmounted = false;
// Bumped by each send, so a status or history answer asked for before it can't undo it.
let sends = 0;
let historyAsked = 0;

const conversation = computed(() => status.value?.conversation_ulid ?? null);
const running = computed(() => turn.value?.state === 'running');
const shown = computed(() => messages.value.filter((message) => (message.role === 'user' || message.role === 'assistant') && message.content));
// Cards whose reply is not on screen (its history did not load) follow the last message.
const unplaced = computed(() => cards.value.filter((block) => !shown.value.some((message) => message.ulid === block.after)));
const canSend = computed(() => !offline.value && !sending.value && !running.value && draft.value.trim() !== '');

async function load(): Promise<void> {
    error.value = null;
    const at = sends;
    let answer: AgentStatusShowOutput;
    try {
        answer = await read<AgentStatusShowOutput>('agent_status_show', {}, (fresh) => {
            if (sends !== at) return;
            const before = conversation.value;
            status.value = fresh;
            if (conversation.value !== before) void loadHistory();
        });
    } catch (failed) {
        if (!offline.value) error.value = failureMessage(failed, 'The Coach could not be loaded.');
        return;
    }
    if (sends !== at) return;
    status.value = answer;
    await loadHistory();
}

/** The server's status now; false when it did not answer, and what is shown stays. */
async function refreshStatus(): Promise<boolean> {
    try {
        status.value = await readFresh<AgentStatusShowOutput>('agent_status_show', {});
        return true;
    } catch {
        return false;
    }
}

/**
 * The cached history at once, then the server's; offline, the cached one stays. Only the
 * newest ask, and none from before a send, changes what is shown. With no conversation
 * there is none to show.
 */
async function loadHistory(): Promise<ChatHistory | undefined> {
    const ulid = conversation.value;
    const ticket = ++historyAsked;
    if (ulid === null) {
        messages.value = [];
        return undefined;
    }
    const hit = await cached(historyKey(ulid)).catch(() => undefined);
    if (hit !== undefined && ticket === historyAsked && messages.value.length === 0) messages.value = (hit.data as ChatHistory).messages;
    try {
        const fresh = await history(ulid);
        if (ticket !== historyAsked) return undefined;
        messages.value = fresh.messages;
        await store(historyKey(ulid), fresh).catch(() => undefined);
        scrollToEnd();
        return fresh;
    } catch (failed) {
        if (ticket === historyAsked && !offline.value) error.value = failureMessage(failed, 'The conversation could not be loaded.');
        return undefined;
    }
}

async function submit(): Promise<void> {
    const content = draft.value.trim();
    if (!canSend.value) return;
    sending.value = true;
    error.value = null;
    landed.value = false;
    // A message sent before has a conversation, which a failed status read may not show yet.
    if (conversation.value === null && sends > 0 && !(await refreshStatus())) {
        error.value = "The Coach couldn't take that message. Try again.";
        sending.value = false;
        return;
    }
    if (unanswered !== null) {
        const earlier = unanswered;
        const outcome = await settle(earlier);
        if (outcome !== 'absent') {
            if (outcome === 'landed' && content === earlier.content) draft.value = '';
            sending.value = false;
            return;
        }
        error.value = null;
    }
    let sent;
    try {
        sent = await send(content, conversation.value);
    } catch (failed) {
        if (failed instanceof CapabilityError && failed.status === 0) {
            const message: Unanswered = { content, before: new Set(messages.value.map((m) => m.ulid)), failure: failed, graceOver: false };
            clearTimeout(graceTimer);
            graceTimer = setTimeout(() => {
                message.graceOver = true;
                void recheck(message);
            }, GRACE_MS);
            if ((await settle(message)) === 'landed') draft.value = '';
        } else {
            error.value = writeFailureMessage(failed, "The Coach couldn't take that message. Try again.");
        }
        sending.value = false;
        return;
    }
    sends++;
    historyAsked++;
    draft.value = '';
    messages.value = [...messages.value, { ulid: sent.message_ulid, role: 'user', content, created_at: null }];
    turn.value = { ulid: sent.turn_ulid, message: sent.message_ulid, lines: [], state: 'running', cursor: 0, ended: false, failures: 0 };
    scrollToEnd();
    // The next message carries the conversation the server now names (AGT-024).
    await refreshStatus();
    sending.value = false;
    poll(turn.value);
}

/**
 * A send with no answer may still have reached the package, and sending it again would
 * run a second turn. So the conversation is read again. `landed`: it now holds this
 * message, which was not on screen before; its turn is not known, so it is not polled, and
 * its reply shows on the next open. `absent`: the conversation answered without it after
 * the grace period, so it was not sent and may be sent again. `waiting`: it answered
 * without it within the grace period, when the server may still be taking it. `unknown`:
 * the conversation could not be read. While waiting or unknown the message stays
 * unanswered, every Send checks again first, and the end of the grace period checks too.
 */
async function settle(message: Unanswered): Promise<'landed' | 'absent' | 'waiting' | 'unknown'> {
    unanswered = message;
    // Only a read that starts after the grace period can show the message was not sent.
    const graceOver = message.graceOver;
    let outcome: 'landed' | 'absent' | 'waiting' | 'unknown';
    if (conversation.value === null && !(await refreshStatus())) {
        outcome = 'unknown';
    } else if (conversation.value === null) {
        // No conversation yet: the package makes one with the first message, so it did not land.
        outcome = 'absent';
    } else {
        const fresh = await loadHistory();
        if (fresh === undefined) outcome = 'unknown';
        else outcome = fresh.messages.some((m) => m.role === 'user' && m.content === message.content && !message.before.has(m.ulid)) ? 'landed' : 'absent';
    }
    if (outcome === 'absent' && !graceOver) outcome = 'waiting';
    if (outcome === 'landed' || outcome === 'absent') {
        unanswered = null;
        clearTimeout(graceTimer);
    }
    if (outcome === 'landed') {
        sends++;
        error.value = null;
        landed.value = true;
    }
    if (outcome === 'absent') error.value = writeFailureMessage(message.failure, "The Coach couldn't take that message. Try again.");
    if (outcome === 'waiting') error.value = WAITING;
    if (outcome === 'unknown') error.value = UNCHECKED;
    return outcome;
}

/** The grace period ended: check once more, unless a Send has settled it meanwhile. */
async function recheck(message: Unanswered): Promise<void> {
    if (unanswered !== message || unmounted) return;
    if (sending.value) {
        // A Send is checking now, from before the grace ended: check again after it.
        graceTimer = setTimeout(() => void recheck(message), POLL_MS);
        return;
    }
    if ((await settle(message)) === 'landed' && draft.value.trim() === message.content) draft.value = '';
}

function poll(current: Turn, delayMs = POLL_MS): void {
    clearTimeout(timer);
    timer = setTimeout(() => void tick(current), delayMs);
}

/** How long to wait after `failures` polls in a row with no answer, or longer when a limit says (`retry_after` seconds). */
function backoffMs(failures: number, failed: CapabilityError): number {
    const seconds = (failed.body as { error?: { retry_after?: unknown } } | null)?.error?.retry_after;
    const asked = typeof seconds === 'number' && seconds > 0 ? seconds * 1000 : 0;

    return Math.max(asked, Math.min(POLL_MS * 2 ** failures, BACKOFF_MAX_MS));
}

async function tick(current: Turn): Promise<void> {
    if (unmounted || turn.value !== current || current.ended) return;
    try {
        const answer = await events(current.ulid, current.cursor);
        current.failures = 0;
        for (const event of answer.events) {
            if (event.index < current.cursor || current.ended) continue;
            current.cursor = event.index + 1;
            if (event.kind === 'tool') {
                current.lines.push(toolLine(String((event.data as { name?: unknown } | null)?.name ?? '')));
                scrollToEnd();
            } else if (event.kind === 'terminal') {
                const ended = (event.data as { status?: unknown } | null)?.status;
                await finish(current, ended === 'cancelled' ? 'cancelled' : ended === 'failed' ? 'failed' : 'done');
                return;
            }
        }
    } catch (failed) {
        // No answer, a server or gateway error, or a limit: ask again on the next tick. The
        // turn runs on the server either way. A refusal (signed out, not this user's turn) ends it here.
        if (!(failed instanceof CapabilityError && (failed.status === 0 || failed.status === 429 || failed.status >= 500))) {
            await finish(current, 'failed', false);
            return;
        }
        current.failures++;
        if (current.failures >= LOST_AFTER) {
            await finish(current, 'lost', false);
            return;
        }
        if (turn.value === current && !current.ended) poll(current, backoffMs(current.failures, failed));
        return;
    }
    if (turn.value === current && !current.ended) poll(current);
}

/**
 * The turn ended: its reply from a fresh history, then its change cards under it. A turn
 * the server refused, or one the client lost touch with, reads neither.
 */
async function finish(current: Turn, state: TurnState, reload = true): Promise<void> {
    if (current.ended) return;
    current.ended = true;
    clearTimeout(timer);
    if (reload) await showOutcome(current);
    current.state = state;
    scrollToEnd();
}

async function showOutcome(current: Turn): Promise<void> {
    if (conversation.value === null) await refreshStatus();
    // Still none: what is shown stays rather than being cleared for want of a ULID.
    const fresh = conversation.value === null ? undefined : await loadHistory();
    // The turn's reply is an assistant message after the turn's own message. A cancelled or
    // failed turn may have none, and then its cards follow the message that started it.
    const after = fresh?.messages.slice(fresh.messages.findIndex((message) => message.ulid === current.message) + 1) ?? [];
    const reply = fresh?.messages.some((message) => message.ulid === current.message)
        ? after.filter((message) => message.role === 'assistant' && message.content).at(-1)
        : undefined;
    try {
        const { changes } = await readFresh<CoachChangesListOutput>('coach_changes_list', { turn: current.ulid } satisfies CoachChangesListInput);
        if (changes.length > 0) cards.value = [...cards.value, { after: reply?.ulid ?? current.message, changes }];
    } catch {
        // The reply stands without its cards; every change is also in Activity (AGT-013).
    }
}

async function stop(): Promise<void> {
    const current = turn.value;
    if (current === null || current.ended) return;
    try {
        await cancel(current.ulid);
    } catch (failed) {
        // The turn ended before the cancel landed: its terminal event is on its way.
        if (failed instanceof CapabilityError && failed.status === 409) return;
        error.value = failureMessage(failed, "Couldn't cancel. Try again.");
        return;
    }
    await finish(current, 'cancelled');
}

function scrollToEnd(): void {
    void nextTick(() => end.value?.scrollIntoView?.({ block: 'end' }));
}

function wentOnline(): void {
    offline.value = false;
    void load();
}

function wentOffline(): void {
    offline.value = true;
}

onMounted(() => {
    window.addEventListener('online', wentOnline);
    window.addEventListener('offline', wentOffline);
});

onBeforeUnmount(() => {
    unmounted = true;
    clearTimeout(timer);
    clearTimeout(graceTimer);
    window.removeEventListener('online', wentOnline);
    window.removeEventListener('offline', wentOffline);
});

// Each open shows the conversation as the server has it now.
watch(
    () => props.open,
    (open) => {
        if (open) void load();
    },
    { immediate: true },
);
</script>

<template>
    <BottomSheet :open="open" title="Coach" :save-title="null" cancel-title="Close" :error="error" @cancel="emit('close')">
        <div class="flex min-h-full flex-col">
            <ol class="flex flex-1 flex-col gap-3 p-4" aria-label="Conversation">
                <li v-if="shown.length === 0 && !running" class="py-8 text-center text-sm text-secondary-text">
                    Ask the Coach about your training or meals, or to change your plan.
                </li>
                <template v-for="message in shown" :key="message.ulid">
                    <li
                        class="max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap"
                        :class="message.role === 'user' ? 'self-end bg-accent text-on-accent' : 'self-start bg-background text-primary-text'"
                        :data-role="message.role"
                    >
                        {{ message.content }}
                    </li>
                    <template v-for="(block, index) in cards" :key="`${message.ulid}-${index}`">
                        <li v-if="block.after === message.ulid" class="flex flex-col gap-2 self-stretch">
                            <ChangeCard v-for="change in block.changes" :key="change.id" :change="change" @open="emit('close')" />
                        </li>
                    </template>
                </template>
                <li v-for="(block, index) in unplaced" :key="`unplaced-${index}`" class="flex flex-col gap-2 self-stretch">
                    <ChangeCard v-for="change in block.changes" :key="change.id" :change="change" @open="emit('close')" />
                </li>
                <li v-if="landed" class="self-start text-sm text-secondary-text" data-test="landed">
                    Your message reached the Coach. Its reply will show here when you open the chat again.
                </li>
                <li v-if="turn && turn.state !== 'done'" class="flex flex-col gap-1 self-start" aria-live="polite">
                    <p v-for="(line, index) in turn.lines" :key="index" class="text-sm text-secondary-text" data-test="tool-line">{{ line }}</p>
                    <p v-if="turn.state === 'running' && turn.lines.length === 0" class="text-sm text-secondary-text">Thinking…</p>
                    <p v-if="turn.state === 'cancelled'" class="text-sm font-medium text-secondary-text" data-test="turn-state">Cancelled</p>
                    <p v-if="turn.state === 'failed'" class="text-sm text-tone-warning" data-test="turn-state">The Coach couldn't finish that. Try again.</p>
                    <p v-if="turn.state === 'lost'" class="text-sm text-tone-warning" data-test="turn-state">Lost touch with the Coach. Close and open the chat to see its reply.</p>
                </li>
            </ol>
            <div ref="end" />
            <form class="sticky bottom-0 flex flex-col gap-2 border-t border-secondary-text/25 bg-surface p-3" @submit.prevent="submit">
                <OfflineNotice v-if="offline" needs="The Coach needs a connection." data-test="offline" />
                <div class="flex items-end gap-2">
                    <textarea
                        v-model="draft"
                        rows="1"
                        aria-label="Message the Coach"
                        placeholder="Message the Coach"
                        :disabled="offline"
                        class="max-h-32 min-h-tap flex-1 resize-none rounded-xl border border-secondary-text/25 bg-background px-3 py-2.5 text-base text-primary-text disabled:opacity-50"
                        @keydown.enter.exact.prevent="submit"
                    />
                    <button
                        v-if="running"
                        type="button"
                        :disabled="turn?.ended"
                        data-test="cancel-turn"
                        class="min-h-tap min-w-tap rounded-xl px-3 text-sm font-semibold text-accent"
                        @click="stop"
                    >
                        Cancel
                    </button>
                    <button
                        v-else
                        type="submit"
                        data-test="send"
                        :disabled="!canSend"
                        class="min-h-tap min-w-tap rounded-xl bg-accent px-4 text-sm font-semibold text-on-accent disabled:opacity-40"
                    >
                        Send
                    </button>
                </div>
            </form>
        </div>
    </BottomSheet>
</template>
