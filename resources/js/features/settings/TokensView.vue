<script setup lang="ts">
import { computed, ref } from 'vue';
import { CapabilityError, write } from '@/app/call';
import { failureMessage } from '@/app/errors';
import { useRetryKey } from '@/app/retryKey';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import type { TokenCreateInput, TokenCreateOutput } from '@/contracts/token_create';
import type { TokenListOutput } from '@/contracts/token_list';
import type { TokenRevokeInput } from '@/contracts/token_revoke';
import BottomSheet from '@/design/BottomSheet.vue';
import ChoiceList from '@/design/ChoiceList.vue';
import StatusPill from '@/design/StatusPill.vue';
import ReadState from './ReadState.vue';
import { toneClasses } from '@/design/tones';

// Settings → Personal access tokens (CAP-021): create one, see it once, list and revoke.
const props = defineProps<{ payload?: TokenListOutput }>();

type Scope = TokenCreateInput['scope'];
type Days = TokenCreateInput['expires_in_days'];

const { data: list, failure, load } = useRead<TokenListOutput>('token_list', props.payload);

const creating = ref(false);
const name = ref('');
const days = ref<Days | null>(null);
const scope = ref<Scope | null>(null);
const picked = ref<string[]>([]);
const busy = ref(false);
const error = ref<string | null>(null);
const shown = ref<TokenCreateOutput['token'] | null>(null);
const copied = ref(false);
const confirming = ref<string | null>(null);
const revokeError = ref<string | null>(null);
const revoking = computed(() => list.value?.tokens.find((token) => token.id === confirming.value) ?? null);
const notice = ref<string | null>(null);
// One id per create form (CAP-019), sent with its idempotency key (CAP-014): a retry
// after a lost answer replays the first create instead of minting a second token.
// While the last create got no answer (createKey.unsure), a token may exist that the
// list doesn't show yet, and the form is locked so a retry is the same create.
const tokenId = ref('');
const createKey = useRetryKey('token_create');
const revokeKey = useRetryKey('token_revoke');
const SECRET_LOST = "This token was made, but its secret can't be shown again. Revoke it from the list and make a new one.";

const expiryChoices = computed(() => (list.value?.expiries ?? []).map((e) => ({ id: e.days as Days, title: e.label })));
const scopeChoices = computed(() => (list.value?.scopes ?? []).map((s) => ({ id: s.value, title: s.label, detail: s.description })));

// Expiry and scope start unpicked: a token's power is the user's choice, never a default.
// A create that got no answer may have run, so its retry must send the same details.
const locked = computed(() => busy.value || createKey.unsure.value);
const ready = computed(() => name.value.trim() !== '' && days.value !== null && scope.value !== null);

function startCreate(): void {
    creating.value = true;
    notice.value = null;
    tokenId.value = ulid();
    createKey.reset();
    name.value = '';
    days.value = null;
    scope.value = null;
    picked.value = [];
    error.value = null;
}

async function create(): Promise<void> {
    if (busy.value || !ready.value || days.value === null || scope.value === null) return;
    busy.value = true;
    error.value = null;
    const input: TokenCreateInput = {
        id: tokenId.value,
        name: name.value.trim(),
        expires_in_days: days.value,
        scope: scope.value,
        capabilities: scope.value === 'custom' ? picked.value.map((capability) => ({ name: capability })) : [],
    };
    try {
        shown.value = (await write<TokenCreateOutput>('token_create', { ...input }, { idempotencyKey: createKey.key.value })).token;
        createKey.settle();
        creating.value = false;
        copied.value = false;
    } catch (failed) {
        createKey.settle(failed);
        if (failed instanceof CapabilityError && failed.status === 409) {
            // This form's id is already a token of this user's: an earlier try ran, and its
            // answer, with the secret, was lost. List it so it can be revoked.
            creating.value = false;
            notice.value = SECRET_LOST;
            await load();
        } else {
            error.value = failureMessage(failed, 'The token could not be created. Try again.');
        }
    } finally {
        busy.value = false;
    }
}

// Closing the form after a create with no answer reads the list again, so a token
// that create made shows up for revoking.
function cancelCreate(): void {
    creating.value = false;
    if (createKey.unsure.value) {
        createKey.reset();
        void load();
    }
}

async function copy(): Promise<void> {
    if (!shown.value?.plain_text) return;
    await navigator.clipboard?.writeText(shown.value.plain_text).then(() => (copied.value = true), () => undefined);
}

function done(): void {
    shown.value = null;
    void load();
}

async function revoke(id: string): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    revokeError.value = null;
    try {
        // The key makes a retry after a lost answer replay the first revoke (CAP-014).
        await write('token_revoke', { id } satisfies TokenRevokeInput, { idempotencyKey: revokeKey.key.value });
        revokeKey.settle();
        confirming.value = null;
        await load();
    } catch (failed) {
        revokeKey.settle(failed);
        if (failed instanceof CapabilityError && failed.status === 404) {
            // The token is already gone, which is what the user asked for.
            confirming.value = null;
            await load();
        } else {
            revokeError.value = failureMessage(failed, 'The token could not be revoked. Try again.');
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <h1 class="text-center text-lg font-semibold">Personal access tokens</h1>

        <section v-if="shown" class="space-y-3 rounded-2xl bg-surface p-4" aria-labelledby="token-shown">
            <h2 id="token-shown" class="font-semibold">{{ shown.name }}</h2>
            <p class="text-sm text-secondary-text">{{ shown.scope_text }} · {{ shown.expires_text }}</p>
            <template v-if="shown.plain_text">
                <p class="rounded-xl p-3 text-sm" :class="toneClasses.warning">Copy this token now. You will not see it again.</p>
                <code class="block rounded-xl bg-background p-3 font-mono text-sm break-all select-all" data-test="plain-text">{{ shown.plain_text }}</code>
            </template>
            <!-- A retried create whose first answer was lost: the token exists, but its secret was shown to nobody. -->
            <p v-else class="rounded-xl p-3 text-sm" :class="toneClasses.warning" data-test="secret-lost">
                {{ SECRET_LOST }}
            </p>
            <div class="flex gap-2">
                <button v-if="shown.plain_text" type="button" class="min-h-tap flex-1 rounded-xl border border-secondary-text/25 px-4 disabled:opacity-50 active:opacity-70" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</button>
                <button type="button" class="min-h-tap flex-1 rounded-xl bg-accent px-4 font-semibold text-on-accent active:opacity-70" data-test="done" @click="done">Done</button>
            </div>
        </section>

        <template v-else-if="list">
            <p class="px-1 text-sm text-secondary-text">A token lets an MCP client, the capabilities CLI or the health bridge act as you.</p>

            <p v-if="notice" class="rounded-2xl p-4" :class="toneClasses.warning" role="alert" data-test="notice">{{ notice }}</p>
            <ul v-if="list.tokens.length" class="divide-y divide-secondary-text/20 overflow-hidden rounded-2xl bg-surface">
                <li v-for="token in list.tokens" :key="token.id" class="space-y-1 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium">{{ token.name }}</span>
                        <StatusPill :text="token.status.text" :tone="token.status.tone" />
                    </div>
                    <p class="text-sm text-secondary-text">{{ token.scope_text }} · {{ token.last_used_text }}</p>
                    <p class="text-sm text-secondary-text">{{ token.created_text }} · {{ token.expires_text }}</p>
                    <button
                        type="button"
                        class="min-h-tap text-tone-warning active:opacity-70"
                        :data-test="`revoke-${token.id}`"
                        @click="(confirming = token.id), (revokeError = null), revokeKey.reset()"
                    >
                        Revoke
                    </button>
                </li>
            </ul>
            <p v-else class="rounded-2xl bg-surface p-4 text-secondary-text">No tokens yet.</p>

            <BottomSheet
                :open="revoking !== null"
                title="Revoke token"
                save-title="Revoke"
                :heights="['half']"
                :save-disabled="busy"
                :cancel-disabled="busy"
                :dismissible="!busy"
                :error="revokeError"
                @cancel="confirming = null"
                @save="revoking && revoke(revoking.id)"
            >
                <p v-if="revoking" class="p-4">Revoke “{{ revoking.name }}”? Anything using it stops working.</p>
            </BottomSheet>

            <form v-if="creating" class="space-y-4 rounded-2xl bg-surface p-4" @submit.prevent>
                <h2 class="font-semibold">New token</h2>
                <label class="block">
                    <span class="text-sm text-secondary-text">Name</span>
                    <input
                        v-model="name"
                        name="token-name"
                        type="text"
                        maxlength="100"
                        autocomplete="off"
                        placeholder="Claude Desktop"
                        :disabled="locked"
                        class="mt-1 min-h-tap w-full rounded-xl border border-secondary-text/25 bg-background px-3"
                    />
                </label>
                <ChoiceList v-model="days" label="Expires after" :choices="expiryChoices" :disabled="locked" />
                <ChoiceList v-model="scope" label="Scope" :choices="scopeChoices" :disabled="locked" />
                <fieldset v-if="scope === 'custom'" class="space-y-2">
                    <legend class="mb-2 text-sm text-secondary-text">Actions it may take</legend>
                    <label v-for="capability in list.capabilities" :key="capability.name" class="flex min-h-tap items-start gap-3 py-1">
                        <input v-model="picked" type="checkbox" class="mt-1 size-5 accent-accent" :value="capability.name" :disabled="locked" />
                        <span>
                            <span class="block font-mono text-sm">{{ capability.name }}</span>
                            <span class="block text-sm text-secondary-text">{{ capability.description }}</span>
                        </span>
                    </label>
                </fieldset>
                <p v-if="error" class="rounded-xl p-3 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>
                <p v-if="createKey.unsure.value" class="text-sm text-secondary-text" data-test="locked">
                    The details stay as sent until the server answers, so Create again finishes this token rather than making a second one.
                </p>
                <div class="flex gap-2">
                    <button type="button" class="min-h-tap flex-1 rounded-xl border border-secondary-text/25 px-4 disabled:opacity-50 active:opacity-70" :disabled="busy" @click="cancelCreate">Cancel</button>
                    <button
                        type="button"
                        class="min-h-tap flex-1 rounded-xl bg-accent px-4 font-semibold text-on-accent active:opacity-70 disabled:opacity-50"
                        data-test="create"
                        :disabled="busy || !ready"
                        :aria-busy="busy"
                        @click="create"
                    >
                        Create
                    </button>
                </div>
            </form>
            <button
                v-else
                type="button"
                class="min-h-tap w-full rounded-2xl bg-surface font-medium text-accent active:opacity-70"
                data-test="new"
                @click="startCreate"
            >
                New token
            </button>
        </template>

        <ReadState v-else :failure="failure" what="Your tokens" @retry="load" />
    </main>
</template>
