<script setup lang="ts">
import { computed, ref } from 'vue';
import { CapabilityError, writeNow } from '@/app/call';
import { failureMessage } from '@/app/errors';
import ReadState from '@/app/ReadState.vue';
import { useRetryKey } from '@/app/retryKey';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import type { HealthBridgeShowOutput } from '@/contracts/health_bridge_show';
import type { TokenCreateInput, TokenCreateOutput } from '@/contracts/token_create';
import type { TokenRevokeInput } from '@/contracts/token_revoke';
import BottomSheet from '@/design/BottomSheet.vue';
import StatusPill from '@/design/StatusPill.vue';
import { toneClasses } from '@/design/tones';

// Settings → Health bridge (INT-001, INT-004): set up the Shortcut's token, see it once,
// follow the steps, see the last post, and revoke it. The server words every line.
const props = defineProps<{ payload?: HealthBridgeShowOutput }>();

const { data: page, failure, load } = useRead<HealthBridgeShowOutput>('health_bridge_show', {}, 'The Health bridge could not be loaded.', props.payload);

const busy = ref(false);
const error = ref<string | null>(null);
const shown = ref<TokenCreateOutput['token'] | null>(null);
const copied = ref(false);
const confirming = ref(false);
const revokeError = ref<string | null>(null);
// One id per set-up, sent with its idempotency key (CAP-019, CAP-014): a retry after a
// lost answer replays the first create instead of minting a second token.
const tokenId = ref(ulid());
const createKey = useRetryKey('token_create');
const revokeKey = useRetryKey('token_revoke');
const SECRET_LOST = "The bridge's token was made, but it can't be shown again. Revoke it and set up the bridge again.";
const notice = ref<string | null>(null);

const token = computed(() => page.value?.token ?? null);

async function setUp(): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    error.value = null;
    notice.value = null;
    // The bridge runs unattended, so its token lasts the longest a token may (CAP-021).
    const input: TokenCreateInput = { id: tokenId.value, name: 'Health bridge', expires_in_days: 365, scope: 'health_bridge', capabilities: [] };
    try {
        shown.value = (await writeNow<TokenCreateOutput>('token_create', { ...input }, { idempotencyKey: createKey.key.value })).token;
        createKey.settle();
        copied.value = false;
    } catch (failed) {
        createKey.settle(failed);
        if (failed instanceof CapabilityError && failed.status === 409 && !createKey.unsure.value) {
            // An earlier try made this token and its answer, with the secret, was lost.
            notice.value = SECRET_LOST;
            tokenId.value = ulid();
            await load();
        } else {
            error.value = failureMessage(failed, 'The bridge could not be set up. Try again.');
        }
    } finally {
        busy.value = false;
    }
}

async function copy(): Promise<void> {
    if (!shown.value?.plain_text) return;
    await navigator.clipboard?.writeText(shown.value.plain_text).then(() => (copied.value = true), () => undefined);
}

function done(): void {
    shown.value = null;
    tokenId.value = ulid();
    void load();
}

async function revoke(id: string): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    revokeError.value = null;
    try {
        await writeNow('token_revoke', { id } satisfies TokenRevokeInput, { idempotencyKey: revokeKey.key.value });
        revokeKey.settle();
        confirming.value = false;
        await load();
    } catch (failed) {
        revokeKey.settle(failed);
        if (failed instanceof CapabilityError && failed.status === 404) {
            // The token is already gone, which is what the user asked for.
            confirming.value = false;
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
        <h1 class="text-center text-lg font-semibold">Health bridge</h1>

        <section v-if="shown" class="space-y-3 rounded-2xl bg-surface p-4" aria-labelledby="bridge-token">
            <h2 id="bridge-token" class="font-semibold">{{ shown.name }}</h2>
            <p class="text-sm text-secondary-text">{{ shown.scope_text }} · {{ shown.expires_text }}</p>
            <template v-if="shown.plain_text">
                <p class="rounded-xl p-3 text-sm" :class="toneClasses.warning">Copy this token into the Shortcut now. You will not see it again.</p>
                <code class="block rounded-xl bg-background p-3 font-mono text-sm break-all select-all" data-test="plain-text">{{ shown.plain_text }}</code>
            </template>
            <p v-else class="rounded-xl p-3 text-sm" :class="toneClasses.warning" data-test="secret-lost">{{ SECRET_LOST }}</p>
            <div class="flex gap-2">
                <button v-if="shown.plain_text" type="button" class="min-h-tap flex-1 rounded-xl border border-secondary-text/25 px-4 active:opacity-70" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</button>
                <button type="button" class="min-h-tap flex-1 rounded-xl bg-accent px-4 font-semibold text-on-accent active:opacity-70" data-test="done" @click="done">Done</button>
            </div>
        </section>

        <template v-else-if="page">
            <section class="space-y-2 rounded-2xl bg-surface p-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-semibold">Status</h2>
                    <StatusPill :text="page.status.text" :tone="page.status.tone" />
                </div>
                <p class="text-sm text-secondary-text" data-test="scope">{{ page.scope_text }}</p>
            </section>

            <p v-if="notice" class="rounded-2xl p-4" :class="toneClasses.warning" role="alert" data-test="notice">{{ notice }}</p>

            <section v-if="token" class="space-y-1 rounded-2xl bg-surface p-4" data-test="token">
                <p class="font-medium">{{ token.name }}</p>
                <p class="text-sm text-secondary-text">{{ token.expires_text }} · {{ token.last_used_text }}</p>
                <button type="button" class="min-h-tap text-tone-warning active:opacity-70" data-test="revoke" @click="(confirming = true), (revokeError = null), revokeKey.reset()">
                    Revoke
                </button>
            </section>
            <template v-else>
                <p v-if="error" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert">{{ error }}</p>
                <button
                    type="button"
                    class="min-h-tap w-full rounded-2xl bg-accent font-semibold text-on-accent active:opacity-70 disabled:opacity-50"
                    data-test="set-up"
                    :disabled="busy"
                    :aria-busy="busy"
                    @click="setUp"
                >
                    Set up the Health bridge
                </button>
            </template>

            <section v-if="page.last_post" class="space-y-2 rounded-2xl bg-surface p-4" aria-labelledby="bridge-last-post" data-test="last-post">
                <div class="flex items-center justify-between gap-2">
                    <h2 id="bridge-last-post" class="font-semibold">Last post</h2>
                    <span class="text-sm text-secondary-text">{{ page.last_post.when_text }}</span>
                </div>
                <dl class="divide-y divide-secondary-text/20">
                    <div v-for="item in page.last_post.items" :key="item.label" class="flex justify-between gap-4 py-2">
                        <dt>{{ item.label }}</dt>
                        <dd class="text-right text-secondary-text">{{ item.value }}</dd>
                    </div>
                </dl>
            </section>

            <section class="space-y-3 rounded-2xl bg-surface p-4" aria-labelledby="bridge-shortcut">
                <h2 id="bridge-shortcut" class="font-semibold">The Shortcut</h2>
                <a
                    v-if="page.shortcut_url"
                    :href="page.shortcut_url"
                    target="_blank"
                    rel="noopener"
                    class="flex min-h-tap items-center justify-center rounded-xl border border-secondary-text/25 px-4 font-medium text-accent active:opacity-70"
                    data-test="shortcut"
                >
                    Get the Shortcut
                </a>
                <ol class="list-decimal space-y-2 pl-5 text-sm" data-test="steps">
                    <li v-for="(step, index) in page.steps" :key="index" class="break-words">{{ step.text }}</li>
                </ol>
            </section>

            <BottomSheet
                :open="confirming && token !== null"
                title="Revoke token"
                save-title="Revoke"
                :heights="['half']"
                :save-disabled="busy"
                :cancel-disabled="busy"
                :dismissible="!busy"
                :error="revokeError"
                @cancel="confirming = false"
                @save="token && revoke(token.id)"
            >
                <p class="p-4">Revoke this Health bridge token? Posts made with it are refused from now on. To start again, set up the bridge and paste the new token into the Shortcut.</p>
            </BottomSheet>
        </template>

        <ReadState v-else :failure="failure" what="The Health bridge" @retry="load" />
    </main>
</template>
