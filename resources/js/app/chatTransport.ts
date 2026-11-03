import { CapabilityError, TIMEOUT_MS } from './call';

// The chat's transport to capabilities-ai's own routes (AGT-021), the one other place
// that calls fetch. The package answers these outside the capability rail, so there is
// no capability to call: history, a new message, cancel and a turn's events, one
// function per route. They go with call.ts's credentials and headers, and a failure is
// the same CapabilityError call.ts throws: the package's error envelope as the body, or
// status 0 when no answer came.

const PREFIX = '/capabilities-ai/chat';

export type ChatMessage = { ulid: string; role: string; content: string | null; created_at: string | null };

export type ChatHistory = { conversation_ulid: string; messages: ChatMessage[] };

export type SentMessage = { conversation_ulid: string; message_ulid: string; turn_ulid: string };

export type CancelledTurn = { turn_ulid: string; status: string };

/** One progress event: `status`, `tool`, `error` or `terminal`, at its place in the turn. */
export type TurnEvent = { kind: string; data?: unknown; at?: string; index: number };

export type TurnEvents = { turn_ulid: string; events: TurnEvent[] };

export function history(conversationUlid: string): Promise<ChatHistory> {
    return request('chat.history', `conversations/${encodeURIComponent(conversationUlid)}`);
}

/** A new user message, which starts a turn. Without a conversation, the package starts one. */
export function send(content: string, conversationUlid: string | null): Promise<SentMessage> {
    return request('chat.send', 'messages', { content, ...(conversationUlid === null ? {} : { conversation_ulid: conversationUlid }) });
}

export function cancel(turnUlid: string): Promise<CancelledTurn> {
    return request('chat.cancel', `turns/${encodeURIComponent(turnUlid)}/cancel`, {});
}

/** The turn's events from `cursor` on: pass the last event's index plus one to get only new ones. */
export function events(turnUlid: string, cursor: number): Promise<TurnEvents> {
    return request('chat.events', `turns/${encodeURIComponent(turnUlid)}/events?cursor=${cursor}`);
}

async function request<T>(label: string, path: string, body?: Record<string, unknown>): Promise<T> {
    const deadline = new AbortController();
    const timer = setTimeout(() => deadline.abort(), TIMEOUT_MS);

    try {
        let response: Response;
        try {
            const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            if (body !== undefined) {
                headers['Content-Type'] = 'application/json';
                headers['X-XSRF-TOKEN'] = await csrfToken(deadline.signal);
            }
            response = await fetch(`${PREFIX}/${path}`, {
                method: body === undefined ? 'GET' : 'POST',
                credentials: 'same-origin',
                headers,
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
                signal: deadline.signal,
            });
        } catch {
            throw new CapabilityError(label, 0, { reason: deadline.signal.aborted ? 'timeout' : 'network' });
        }
        // Read before the deadline is cleared, so a stalled body can't hang the chat.
        const answer: unknown = await response.json().catch(() => null);
        if (!response.ok) throw new CapabilityError(label, response.status, answer);

        return answer as T;
    } finally {
        clearTimeout(timer);
    }
}

/** Sanctum's XSRF-TOKEN cookie, fetched first when missing, as call.ts does. */
async function csrfToken(signal: AbortSignal): Promise<string> {
    let token = cookie('XSRF-TOKEN');

    if (token === null) {
        await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', signal });
        token = cookie('XSRF-TOKEN');
    }

    return token ?? '';
}

function cookie(name: string): string | null {
    const match = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));

    return match === undefined ? null : decodeURIComponent(match.slice(name.length + 1));
}
