<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { postHost, sync } from '@/app/call';
import { loadOutbox, othersWaiting, removeOthers } from '@/app/outbox';
import { hostFailure } from '@/app/hostFailure';
import { toneClasses } from '@/design/tones';

// Sign in (ACC-001), ported from rp-clone's SignInView without Sign in with Apple:
// email and password, an emailed 6-digit code, or a new account. It posts AUTH's host
// routes (CAP-004), and the server decides who gets in; this script holds what is typed,
// which form shows, the answer to show, and the 30-second wait before Resend that the
// issue sets for this screen (the server has its own limit on codes sent).
type Mode = 'signIn' | 'createAccount' | 'code';
type Field = 'name' | 'email' | 'password' | 'code';

const RESEND_AFTER_S = 30;
const FIELDS: Field[] = ['name', 'email', 'password', 'code'];

const router = useRouter();
const mode = ref<Mode>('signIn');
const name = ref('');
const email = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const code = ref('');
// Where the code went: the email as it was when the code was sent.
const codeSentTo = ref('');
const busy = ref(false);
const fieldErrors = ref<Partial<Record<Field, string>>>({});
// A problem that belongs to no field: offline, throttled, a server failure.
const generalError = ref<string | null>(null);
// The device is already signed in, so the screen offers the app instead.
const signedIn = ref(false);
// A friendly answer, such as where the reset link went.
const info = ref<string | null>(null);
const resendIn = ref(0);
// Signed in on a device holding another account's unsynced changes (OFF-012).
const switching = ref(false);
let ticker: ReturnType<typeof setInterval> | undefined;

const canSubmit = computed(() => {
    if (busy.value) return false;
    if (mode.value === 'code') return code.value !== '';
    if (email.value === '' || password.value === '') return false;
    return mode.value === 'signIn' || (name.value !== '' && passwordConfirmation.value !== '');
});

const subtitle = computed(() => {
    if (mode.value === 'createAccount') return 'Create your account';
    if (mode.value === 'code') return 'Enter the code from your email';
    return 'Sign in to your account';
});

function clearMessages(): void {
    fieldErrors.value = {};
    generalError.value = null;
    signedIn.value = false;
    info.value = null;
}

/** Runs one call to the server at a time, and shows its refusal (app/hostFailure.ts). */
async function run(work: () => Promise<void>): Promise<void> {
    if (busy.value) return;
    clearMessages();
    busy.value = true;
    try {
        await work();
    } catch (failed) {
        const failure = hostFailure(failed, FIELDS);
        fieldErrors.value = failure.fields;
        generalError.value = failure.message;
        signedIn.value = failure.signedIn;
    } finally {
        busy.value = false;
    }
}

async function submit(): Promise<void> {
    if (!canSubmit.value) return;
    await run(async () => {
        if (mode.value === 'code') await postHost('/sign-in/code/verify', { email: codeSentTo.value, code: code.value });
        else if (mode.value === 'signIn') await postHost('/sign-in', { email: email.value, password: password.value });
        else {
            await postHost('/register', {
                name: name.value,
                email: email.value,
                password: password.value,
                password_confirmation: passwordConfirmation.value,
            });
        }
        await enter();
    });
}

// Signed in. When the device holds changes another account made and never synced, the
// user is told they will be deleted, and they go only when the user says so (OFF-012).
// Otherwise the app opens.
async function enter(): Promise<void> {
    await loadOutbox();
    if (othersWaiting.value > 0) switching.value = true;
    else await openApp();
}

// Replay the user's own outbox and fill the offline cache as them (OFF-004), then open the app.
async function openApp(): Promise<void> {
    sync().catch(() => {
        // Offline or refused: the next reconnect syncs again.
    });
    await router.replace('/');
}

async function deleteOthers(): Promise<void> {
    await run(async () => {
        await removeOthers();
        switching.value = false;
        await openApp();
    });
}

// The other account can then sign in on this device and sync its changes.
async function signOutInstead(): Promise<void> {
    await run(async () => {
        await postHost('/sign-out', {});
        await loadOutbox();
        switching.value = false;
    });
}

async function sendCode(): Promise<void> {
    const to = mode.value === 'code' ? codeSentTo.value : email.value;
    await run(async () => {
        await postHost('/sign-in/code', { email: to });
        codeSentTo.value = to;
        code.value = '';
        mode.value = 'code';
        startResendWait();
    });
}

function startResendWait(): void {
    stopResendWait();
    resendIn.value = RESEND_AFTER_S;
    ticker = setInterval(() => {
        resendIn.value -= 1;
        if (resendIn.value <= 0) stopResendWait();
    }, 1000);
}

function stopResendWait(): void {
    if (ticker !== undefined) clearInterval(ticker);
    ticker = undefined;
}

onUnmounted(stopResendWait);

async function forgotPassword(): Promise<void> {
    const to = email.value;
    await run(async () => {
        await postHost('/forgot-password', { email: to });
        info.value = `If ${to} has an account, a link to reset its password is on its way.`;
    });
}

function switchTo(next: Mode): void {
    mode.value = next;
    stopResendWait();
    resendIn.value = 0;
    clearMessages();
}

const fieldClass = 'min-h-tap w-full rounded-xl bg-surface px-3.5 text-primary-text placeholder:text-secondary-text/70';
const linkClass = 'min-h-tap px-2 text-accent active:opacity-70 disabled:opacity-40';
</script>

<template>
    <main class="mx-auto w-full max-w-[440px] px-6 pb-6 pt-[max(1.5rem,env(safe-area-inset-top))]">
        <div class="flex flex-col gap-6">
            <header class="flex flex-col items-center gap-2 pt-6 text-center">
                <svg class="h-10 w-20 text-accent" viewBox="0 0 48 24" fill="currentColor" aria-hidden="true">
                    <rect x="2" y="7" width="4" height="10" rx="2" />
                    <rect x="8" y="3" width="6" height="18" rx="3" />
                    <rect x="14" y="10" width="20" height="4" />
                    <rect x="34" y="3" width="6" height="18" rx="3" />
                    <rect x="42" y="7" width="4" height="10" rx="2" />
                </svg>
                <h1 class="text-[34px] font-bold leading-tight text-primary-text">Bodybuilder</h1>
                <p class="text-[15px] text-secondary-text">{{ subtitle }}</p>
            </header>

            <section v-if="switching" class="flex flex-col gap-4" role="alertdialog" aria-labelledby="switch-title" aria-describedby="switch-words">
                <h2 id="switch-title" class="text-[17px] font-semibold">Unsynced changes from another account</h2>
                <p id="switch-words" class="rounded-xl p-3 text-[15px]" :class="toneClasses.warning">
                    {{ othersWaiting }} {{ othersWaiting === 1 ? 'change' : 'changes' }} made on this device by another account
                    {{ othersWaiting === 1 ? "hasn't" : "haven't" }} synced. If you continue, {{ othersWaiting === 1 ? 'it is' : 'they are' }} deleted.
                </p>
                <p v-if="generalError" class="rounded-xl p-3 text-[13px]" :class="toneClasses.warning" role="alert">{{ generalError }}</p>
                <button
                    type="button"
                    class="min-h-tap w-full rounded-xl bg-accent text-[17px] font-semibold text-on-accent active:opacity-80 disabled:opacity-40"
                    data-test="discard-others"
                    :disabled="busy"
                    @click="deleteOthers"
                >
                    Delete them and continue
                </button>
                <button type="button" :class="linkClass" data-test="sign-out-instead" :disabled="busy" @click="signOutInstead">
                    Sign out, so that account can sign in and sync them
                </button>
            </section>

            <template v-else>
            <form class="flex flex-col gap-6" novalidate @submit.prevent="submit">
                <fieldset class="flex flex-col gap-4" :disabled="busy">
                    <template v-if="mode === 'code'">
                        <p class="text-[15px] text-secondary-text">We emailed a 6-digit code to {{ codeSentTo }}.</p>
                        <div>
                            <label for="sign-in-code" class="sr-only">Code</label>
                            <input
                                id="sign-in-code"
                                v-model.trim="code"
                                name="code"
                                type="text"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="6"
                                placeholder="6-digit code"
                                :class="[fieldClass, 'tracking-[0.3em] placeholder:tracking-normal']"
                                :aria-invalid="fieldErrors.code !== undefined"
                                :aria-describedby="fieldErrors.code ? 'error-code' : undefined"
                            />
                            <p v-if="fieldErrors.code" id="error-code" class="mt-1.5 text-[13px] text-tone-warning" data-test="error-code">{{ fieldErrors.code }}</p>
                        </div>
                    </template>
                    <template v-else>
                        <div v-if="mode === 'createAccount'">
                            <label for="sign-in-name" class="sr-only">Name</label>
                            <input
                                id="sign-in-name"
                                v-model="name"
                                name="name"
                                type="text"
                                autocomplete="name"
                                autocapitalize="words"
                                placeholder="Name"
                                :class="fieldClass"
                                :aria-invalid="fieldErrors.name !== undefined"
                                :aria-describedby="fieldErrors.name ? 'error-name' : undefined"
                            />
                            <p v-if="fieldErrors.name" id="error-name" class="mt-1.5 text-[13px] text-tone-warning" data-test="error-name">{{ fieldErrors.name }}</p>
                        </div>
                        <div>
                            <label for="sign-in-email" class="sr-only">Email</label>
                            <input
                                id="sign-in-email"
                                v-model.trim="email"
                                name="email"
                                type="email"
                                autocomplete="email"
                                autocapitalize="off"
                                autocorrect="off"
                                spellcheck="false"
                                placeholder="Email"
                                :class="fieldClass"
                                :aria-invalid="fieldErrors.email !== undefined"
                                :aria-describedby="fieldErrors.email ? 'error-email' : undefined"
                            />
                            <p v-if="fieldErrors.email" id="error-email" class="mt-1.5 text-[13px] text-tone-warning" data-test="error-email">{{ fieldErrors.email }}</p>
                        </div>
                        <div>
                            <label for="sign-in-password" class="sr-only">Password</label>
                            <input
                                id="sign-in-password"
                                v-model="password"
                                name="password"
                                type="password"
                                :autocomplete="mode === 'signIn' ? 'current-password' : 'new-password'"
                                placeholder="Password"
                                :class="fieldClass"
                                :aria-invalid="fieldErrors.password !== undefined"
                                :aria-describedby="fieldErrors.password ? 'error-password' : undefined"
                            />
                            <p v-if="fieldErrors.password" id="error-password" class="mt-1.5 text-[13px] text-tone-warning" data-test="error-password">
                                {{ fieldErrors.password }}
                            </p>
                        </div>
                        <div v-if="mode === 'createAccount'">
                            <label for="sign-in-password-confirmation" class="sr-only">Confirm password</label>
                            <input
                                id="sign-in-password-confirmation"
                                v-model="passwordConfirmation"
                                name="password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                placeholder="Confirm password"
                                :class="fieldClass"
                            />
                        </div>
                    </template>
                </fieldset>

                <div v-if="generalError" class="flex flex-col gap-2 rounded-xl p-3 text-[13px]" :class="toneClasses.warning" role="alert">
                    <p>{{ generalError }}</p>
                    <RouterLink v-if="signedIn" to="/" replace class="font-semibold underline" data-test="open-app">Open the app</RouterLink>
                </div>
                <p v-if="info" class="rounded-xl bg-surface p-3 text-[13px] text-secondary-text" role="status">{{ info }}</p>

                <button
                    type="submit"
                    class="min-h-tap w-full rounded-xl bg-accent text-[17px] font-semibold text-on-accent active:opacity-80 disabled:opacity-40"
                    data-test="submit"
                    :disabled="!canSubmit"
                    :aria-busy="busy"
                >
                    {{ mode === 'createAccount' ? 'Create account' : 'Sign in' }}
                </button>
            </form>

            <div class="flex flex-col items-center gap-2 text-[15px]">
                <template v-if="mode === 'signIn'">
                    <div class="flex w-full items-center gap-3 text-[13px] text-secondary-text" aria-hidden="true">
                        <span class="h-px flex-1 bg-secondary-text/30" />or<span class="h-px flex-1 bg-secondary-text/30" />
                    </div>
                    <button type="button" :class="linkClass" data-test="send-code" :disabled="busy" @click="sendCode">Email me a code</button>
                    <button type="button" :class="linkClass" data-test="forgot" :disabled="busy" @click="forgotPassword">Forgot password?</button>
                </template>
                <template v-if="mode === 'code'">
                    <button type="button" :class="linkClass" data-test="resend" :disabled="busy || resendIn > 0" @click="sendCode">
                        {{ resendIn > 0 ? `Resend code in ${resendIn} s` : 'Resend code' }}
                    </button>
                    <button type="button" :class="linkClass" data-test="use-password" :disabled="busy" @click="switchTo('signIn')">Use a password instead</button>
                </template>
                <button
                    v-else
                    type="button"
                    :class="linkClass"
                    data-test="toggle-mode"
                    :disabled="busy"
                    @click="switchTo(mode === 'signIn' ? 'createAccount' : 'signIn')"
                >
                    {{ mode === 'signIn' ? 'New here? Create an account' : 'Have an account? Sign in' }}
                </button>
            </div>
            </template>
        </div>
    </main>
</template>
