<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { cached, readKey, store } from '@/app/cache';
import { CapabilityError, read, readFresh } from '@/app/call';
import { cancel, events, history, send, type ChatHistory, type ChatMessage } from '@/app/chatTransport';
import { failureMessage } from '@/app/errors';
import type { AgentStatusShowOutput } from '@/contracts/agent_status_show';
import type { CoachChangesListInput, CoachChangesListOutput } from '@/contracts/coach_changes_list';
import BottomSheet from '@/design/BottomSheet.vue';
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

type TurnState = 'running' | 'done' | 'cancelled' | 'failed';
type Turn = { ulid: string; lines: string[]; state: TurnState; cursor: number };
type Changes = CoachChangesListOutput['changes'];

const POLL_MS = 1000;
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
const offline = ref(typeof navigator !== 'undefined' && navigator.onLine === false);
const end = ref<HTMLElement | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let unmounted = false;

const conversation = computed(() => status.value?.conversation_ulid ?? null);
const running = computed(() => turn.value?.state === 'running');
const shown = computed(() => messages.value.filter((message) => (message.role === 'user' || message.role === 'assistant') && message.content));
const canSend = computed(() => !offline.value && !sending.value && !running.value && draft.value.trim() !== '');

async function load(): Promise<void> {
    error.value = null;
    try {
        status.value = await read<AgentStatusShowOutput>('agent_status_show', {}, (fresh) => {
            const before = conversation.value;
            status.value = fresh;
            if (conversation.value !== before) void loadHistory();
        });
    } catch (failed) {
        noteOffline(failed);
        if (!offline.value) error.value = failureMessage(failed, 'The Coach could not be loaded.');
        return;
    }
    await loadHistory();
}

/** The cached history at once, then the server's. Offline, the cached one stays. */
async function loadHistory(): Promise<ChatHistory | undefined> {
    const ulid = conversation.value;
    if (ulid === null) {
        messages.value = [];
        return undefined;
    }
    const hit = await cached(historyKey(ulid)).catch(() => undefined);
    if (hit !== undefined && messages.value.length === 0) messages.value = (hit.data as ChatHistory).messages;
    try {
        const fresh = await history(ulid);
        offline.value = false;
        messages.value = fresh.messages;
        await store(historyKey(ulid), fresh).catch(() => undefined);
        scrollToEnd();
        return fresh;
    } catch (failed) {
        noteOffline(failed);
        if (!offline.value) error.value = failureMessage(failed, 'The conversation could not be loaded.');
        return undefined;
    }
}

function noteOffline(failed: unknown): void {
    if (failed instanceof CapabilityError && failed.status === 0 && (failed.body as { reason?: unknown } | null)?.reason === 'network') offline.value = true;
}

async function submit(): Promise<void> {
    const content = draft.value.trim();
    if (!canSend.value) return;
    sending.value = true;
    error.value = null;
    let sent;
    try {
        sent = await send(content, conversation.value);
    } catch (failed) {
        noteOffline(failed);
        error.value = failureMessage(failed, "The Coach couldn't take that message. Try again.");
        sending.value = false;
        return;
    }
    draft.value = '';
    messages.value = [...messages.value, { ulid: sent.message_ulid, role: 'user', content, created_at: null }];
    turn.value = { ulid: sent.turn_ulid, lines: [], state: 'running', cursor: 0 };
    scrollToEnd();
    // The next message carries the conversation the server now names (AGT-024).
    status.value = await readFresh<AgentStatusShowOutput>('agent_status_show', {}).catch(() => status.value);
    sending.value = false;
    poll(turn.value);
}

function poll(current: Turn): void {
    clearTimeout(timer);
    timer = setTimeout(() => void tick(current), POLL_MS);
}

async function tick(current: Turn): Promise<void> {
    if (unmounted || turn.value !== current || current.state !== 'running') return;
    try {
        const answer = await events(current.ulid, current.cursor);
        for (const event of answer.events) {
            if (event.index < current.cursor || current.state !== 'running') continue;
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
        // No answer: ask again on the next tick. Any other answer ends the turn here.
        if (!(failed instanceof CapabilityError && failed.status === 0)) {
            await finish(current, 'failed');
            return;
        }
    }
    if (turn.value === current && current.state === 'running') poll(current);
}

/** The turn ended: its reply from a fresh history, then its change cards under it. */
async function finish(current: Turn, state: TurnState): Promise<void> {
    if (current.state !== 'running') return;
    current.state = state;
    clearTimeout(timer);
    await loadHistory();
    const reply = shown.value.at(-1);
    try {
        const { changes } = await readFresh<CoachChangesListOutput>('coach_changes_list', { turn: current.ulid } satisfies CoachChangesListInput);
        if (changes.length > 0) cards.value = [...cards.value, { after: reply?.ulid ?? null, changes }];
    } catch {
        // The reply stands without its cards; every change is also in Activity (AGT-013).
    }
    scrollToEnd();
}

async function stop(): Promise<void> {
    const current = turn.value;
    if (current === null || current.state !== 'running') return;
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
                <li v-if="turn && turn.state !== 'done'" class="flex flex-col gap-1 self-start" aria-live="polite">
                    <p v-for="(line, index) in turn.lines" :key="index" class="text-sm text-secondary-text" data-test="tool-line">{{ line }}</p>
                    <p v-if="turn.state === 'running' && turn.lines.length === 0" class="text-sm text-secondary-text">Thinking…</p>
                    <p v-if="turn.state === 'cancelled'" class="text-sm font-medium text-secondary-text" data-test="turn-state">Cancelled</p>
                    <p v-if="turn.state === 'failed'" class="text-sm text-tone-warning" data-test="turn-state">The Coach couldn't finish that. Try again.</p>
                </li>
            </ol>
            <div ref="end" />
            <form class="sticky bottom-0 flex flex-col gap-2 border-t border-secondary-text/25 bg-surface p-3" @submit.prevent="submit">
                <p v-if="offline" class="text-sm text-secondary-text" data-test="offline">The Coach needs a connection</p>
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
