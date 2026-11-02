import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { CapabilityError, postHost, warm } from '@/app/call';
import routes from './routes';
import SignInView from './SignInView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), postHost: vi.fn(), warm: vi.fn() }));

const postMock = vi.mocked(postHost);

// What AUTH's routes answer (RedeemSignInCode, SignInController, the throttle middleware).
const REFUSED = 'That code is wrong or no longer works. Ask for a new code if you need one.';
const TOO_MANY_CODES = 'Too many wrong codes for this email. Sign in with your password, or try again later.';
const NO_MATCH = 'That email and password do not match an account.';

function refused(path: string, status: number, errors?: Record<string, string[]>): CapabilityError {
    const message = errors ? Object.values(errors)[0]![0]! : 'Too Many Attempts.';
    return new CapabilityError(path, status, errors ? { message, errors } : { message });
}

async function view() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    await router.push('/sign-in');
    const wrapper = mount(SignInView, { global: { plugins: [router] } });
    return { wrapper, router };
}

const button = (wrapper: VueWrapper, test: string) => wrapper.get<HTMLButtonElement>(`[data-test="${test}"]`);

async function fill(wrapper: VueWrapper, fields: Record<string, string>): Promise<void> {
    for (const [name, value] of Object.entries(fields)) await wrapper.get(`input[name="${name}"]`).setValue(value);
}

async function sendCode(wrapper: VueWrapper, email = 'sam@example.com'): Promise<void> {
    postMock.mockResolvedValueOnce(undefined);
    await fill(wrapper, { email });
    await button(wrapper, 'send-code').trigger('click');
    await flushPromises();
}

describe('SignInView', () => {
    beforeEach(() => {
        postMock.mockReset();
        vi.mocked(warm).mockReset().mockResolvedValue(undefined);
    });

    afterEach(() => vi.useRealTimers());

    it('ACC-001 opens at /sign-in outside the shell, and in /dev/screens with no fixture', () => {
        const route = routes.find((r) => r.path === '/sign-in');

        expect(route?.meta).toMatchObject({ screen: 'sign_in', fixture: false, shell: false });
    });

    it('ACC-001 signed out: email and password, with Sign in off until both are typed', async () => {
        const { wrapper } = await view();

        expect(wrapper.text()).toContain('Sign in to your account');
        expect(wrapper.find('input[name="name"]').exists()).toBe(false);
        expect(wrapper.find('input[name="code"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Forgot password?');
        expect(wrapper.text()).toContain('Email me a code');
        expect(wrapper.text()).toContain('New here? Create an account');
        expect(button(wrapper, 'submit').element.disabled).toBe(true);

        await fill(wrapper, { email: 'sam@example.com' });
        expect(button(wrapper, 'submit').element.disabled).toBe(true);

        await fill(wrapper, { password: 'secret' });
        expect(button(wrapper, 'submit').element.disabled).toBe(false);
    });

    it('ACC-001 signs in with email and password, warms the cache and opens the app', async () => {
        postMock.mockResolvedValueOnce(undefined);
        const { wrapper, router } = await view();

        await fill(wrapper, { email: 'sam@example.com', password: 'secret' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(postMock).toHaveBeenCalledWith('/sign-in', { email: 'sam@example.com', password: 'secret' });
        expect(warm).toHaveBeenCalled();
        expect(router.currentRoute.value.path).toBe('/');
    });

    it('ACC-001 a wrong password shows the server\'s words under the email and stays', async () => {
        postMock.mockRejectedValueOnce(refused('/sign-in', 422, { email: [NO_MATCH] }));
        const { wrapper, router } = await view();

        await fill(wrapper, { email: 'sam@example.com', password: 'wrong' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-test="error-email"]').text()).toBe(NO_MATCH);
        expect(router.currentRoute.value.path).toBe('/sign-in');
        expect(warm).not.toHaveBeenCalled();
        // The fields keep what was typed, so a typo is one fix away.
        expect(wrapper.get<HTMLInputElement>('input[name="password"]').element.value).toBe('wrong');
    });

    it('ACC-001 a sign-in in flight sends once and turns the form off', async () => {
        let answer!: () => void;
        postMock.mockReturnValueOnce(new Promise<void>((resolve) => (answer = resolve)));
        const { wrapper } = await view();

        await fill(wrapper, { email: 'sam@example.com', password: 'secret' });
        await wrapper.get('form').trigger('submit');
        await wrapper.get('form').trigger('submit');

        expect(postMock).toHaveBeenCalledTimes(1);
        expect(button(wrapper, 'submit').element.disabled).toBe(true);
        expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined();
        answer();
        await flushPromises();
    });

    it('ACC-001 Email me a code sends one to the typed email and asks for the 6-digit code', async () => {
        const { wrapper } = await view();

        await sendCode(wrapper);

        expect(postMock).toHaveBeenCalledWith('/sign-in/code', { email: 'sam@example.com' });
        expect(wrapper.text()).toContain('We emailed a 6-digit code to sam@example.com.');
        const code = wrapper.get('input[name="code"]');
        expect(code.attributes()).toMatchObject({ inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6' });
        expect(wrapper.find('input[name="password"]').exists()).toBe(false);
        expect(button(wrapper, 'submit').text()).toBe('Sign in');
        expect(button(wrapper, 'submit').element.disabled).toBe(true);
    });

    it('ACC-001 signs in with the emailed code and opens the app', async () => {
        const { wrapper, router } = await view();
        await sendCode(wrapper);

        postMock.mockResolvedValueOnce(undefined);
        await fill(wrapper, { code: '123456' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(postMock).toHaveBeenLastCalledWith('/sign-in/code/verify', { email: 'sam@example.com', code: '123456' });
        expect(warm).toHaveBeenCalled();
        expect(router.currentRoute.value.path).toBe('/');
    });

    it.each([
        ['wrong', REFUSED],
        ['expired', REFUSED],
        ['tried too often', TOO_MANY_CODES],
    ])('ACC-001 a %s code shows the server\'s words under the code field and stays', async (_, words) => {
        const { wrapper, router } = await view();
        await sendCode(wrapper);

        postMock.mockRejectedValueOnce(refused('/sign-in/code/verify', 422, { code: [words] }));
        await fill(wrapper, { code: '000000' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-test="error-code"]').text()).toBe(words);
        expect(wrapper.find('input[name="code"]').exists()).toBe(true);
        expect(router.currentRoute.value.path).toBe('/sign-in');
    });

    it('ACC-001 Resend turns on 30 seconds after a code is sent, and sends another', async () => {
        vi.useFakeTimers();
        const { wrapper } = await view();
        await sendCode(wrapper);

        expect(button(wrapper, 'resend').element.disabled).toBe(true);
        expect(button(wrapper, 'resend').text()).toBe('Resend code in 30 s');

        await vi.advanceTimersByTimeAsync(29_000);
        expect(button(wrapper, 'resend').text()).toBe('Resend code in 1 s');
        expect(button(wrapper, 'resend').element.disabled).toBe(true);

        await vi.advanceTimersByTimeAsync(1_000);
        expect(button(wrapper, 'resend').text()).toBe('Resend code');
        expect(button(wrapper, 'resend').element.disabled).toBe(false);

        postMock.mockResolvedValueOnce(undefined);
        await button(wrapper, 'resend').trigger('click');
        await flushPromises();

        expect(postMock).toHaveBeenCalledTimes(2);
        expect(postMock).toHaveBeenLastCalledWith('/sign-in/code', { email: 'sam@example.com' });
        expect(button(wrapper, 'resend').element.disabled).toBe(true);
        expect(button(wrapper, 'resend').text()).toBe('Resend code in 30 s');
    });

    it('ACC-001 rate-limited: too many codes asked for says to wait and asks for no code', async () => {
        postMock.mockRejectedValueOnce(refused('/sign-in/code', 429));
        const { wrapper } = await view();

        await fill(wrapper, { email: 'sam@example.com' });
        await button(wrapper, 'send-code').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Too many tries. Wait a few minutes, then try again.');
        expect(wrapper.find('input[name="code"]').exists()).toBe(false);
    });

    it('ACC-001 rate-limited: too many sign-ins says to wait', async () => {
        postMock.mockRejectedValueOnce(refused('/sign-in', 429));
        const { wrapper, router } = await view();

        await fill(wrapper, { email: 'sam@example.com', password: 'secret' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Too many tries. Wait a few minutes, then try again.');
        expect(router.currentRoute.value.path).toBe('/sign-in');
    });

    it('ACC-001 offline says the server can\'t be reached', async () => {
        postMock.mockRejectedValueOnce(new CapabilityError('/sign-in', 0, { reason: 'network' }));
        const { wrapper } = await view();

        await fill(wrapper, { email: 'sam@example.com', password: 'secret' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('No connection. Try again when you are online.');
    });

    it('ACC-001 a device already signed in is told so and offered the app, not let in as someone else', async () => {
        postMock.mockRejectedValueOnce(new CapabilityError('/sign-in', 302, { reason: 'signed_in' }));
        const { wrapper, router } = await view();

        await fill(wrapper, { email: 'other@example.com', password: 'anything' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('You are already signed in on this device.');
        expect(wrapper.get('[data-test="open-app"]').attributes('href')).toBe('/');
        expect(warm).not.toHaveBeenCalled();
        expect(router.currentRoute.value.path).toBe('/sign-in');
    });

    it('ACC-001 any other refusal (a stale CSRF token) says something went wrong and stays', async () => {
        postMock.mockRejectedValueOnce(new CapabilityError('/sign-in', 419, { message: 'CSRF token mismatch.' }));
        const { wrapper, router } = await view();

        await fill(wrapper, { email: 'sam@example.com', password: 'secret' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Something went wrong. Try again.');
        expect(wrapper.find('[data-test="open-app"]').exists()).toBe(false);
        expect(router.currentRoute.value.path).toBe('/sign-in');
    });

    it('OFF-004 a warm that fails after sign-in still opens the app', async () => {
        postMock.mockResolvedValueOnce(undefined);
        vi.mocked(warm).mockRejectedValueOnce(new CapabilityError('sync_manifest', 0, { reason: 'network' }));
        const { wrapper, router } = await view();

        await fill(wrapper, { email: 'sam@example.com', password: 'secret' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it('ACC-001 Use a password instead goes back to the password form with the email kept', async () => {
        const { wrapper } = await view();
        await sendCode(wrapper);

        await button(wrapper, 'use-password').trigger('click');

        expect(wrapper.find('input[name="code"]').exists()).toBe(false);
        expect(wrapper.get<HTMLInputElement>('input[name="email"]').element.value).toBe('sam@example.com');
        expect(wrapper.find('input[name="password"]').exists()).toBe(true);
    });

    it('ACC-004 Forgot password sends a reset link to the typed email and says so', async () => {
        postMock.mockResolvedValueOnce(undefined);
        const { wrapper } = await view();

        await fill(wrapper, { email: 'sam@example.com' });
        await button(wrapper, 'forgot').trigger('click');
        await flushPromises();

        expect(postMock).toHaveBeenCalledWith('/forgot-password', { email: 'sam@example.com' });
        expect(wrapper.get('[role="status"]').text()).toBe('If sam@example.com has an account, a link to reset its password is on its way.');
    });

    it('ACC-004 Forgot password with no email shows the server\'s words under the email', async () => {
        postMock.mockRejectedValueOnce(refused('/forgot-password', 422, { email: ['The email field is required.'] }));
        const { wrapper } = await view();

        await button(wrapper, 'forgot').trigger('click');
        await flushPromises();

        expect(postMock).toHaveBeenCalledWith('/forgot-password', { email: '' });
        expect(wrapper.get('[data-test="error-email"]').text()).toBe('The email field is required.');
    });

    it('ACC-001 creates an account with name, email and the password typed twice', async () => {
        postMock.mockResolvedValueOnce(undefined);
        const { wrapper, router } = await view();

        await button(wrapper, 'toggle-mode').trigger('click');
        expect(wrapper.text()).toContain('Create your account');
        expect(wrapper.text()).not.toContain('Forgot password?');
        expect(wrapper.text()).not.toContain('Email me a code');
        expect(button(wrapper, 'toggle-mode').text()).toBe('Have an account? Sign in');

        await fill(wrapper, { email: 'sam@example.com', password: 'long secret', password_confirmation: 'long secret' });
        expect(button(wrapper, 'submit').element.disabled).toBe(true);
        await fill(wrapper, { name: 'Sam', password_confirmation: '' });
        expect(button(wrapper, 'submit').element.disabled).toBe(true);
        await fill(wrapper, { password_confirmation: 'long secret' });
        expect(button(wrapper, 'submit').text()).toBe('Create account');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(postMock).toHaveBeenCalledWith('/register', {
            name: 'Sam',
            email: 'sam@example.com',
            password: 'long secret',
            password_confirmation: 'long secret',
        });
        expect(router.currentRoute.value.path).toBe('/');
    });

    it('ACC-001 a refused sign-up shows each field\'s first error under it', async () => {
        postMock.mockRejectedValueOnce(
            refused('/register', 422, {
                name: ['The name field is required.'],
                email: ['The email has already been taken.'],
                password: ['The password field confirmation does not match.', 'The password field must be at least 8 characters.'],
            }),
        );
        const { wrapper } = await view();

        await button(wrapper, 'toggle-mode').trigger('click');
        await fill(wrapper, { name: 'Sam', email: 'sam@example.com', password: 'a', password_confirmation: 'b' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-test="error-name"]').text()).toBe('The name field is required.');
        expect(wrapper.get('input[name="name"]').attributes('aria-describedby')).toBe('error-name');
        expect(wrapper.get('[data-test="error-email"]').text()).toBe('The email has already been taken.');
        expect(wrapper.get('[data-test="error-password"]').text()).toBe('The password field confirmation does not match.');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it('ACC-001 switching between sign in and create account clears the last answer', async () => {
        postMock.mockRejectedValueOnce(refused('/sign-in', 422, { email: [NO_MATCH] }));
        const { wrapper } = await view();
        await fill(wrapper, { email: 'sam@example.com', password: 'wrong' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        await button(wrapper, 'toggle-mode').trigger('click');

        expect(wrapper.find('[data-test="error-email"]').exists()).toBe(false);
    });

    it('ACC-001 a 422 with no field the form shows falls back to the server\'s message', async () => {
        postMock.mockRejectedValueOnce(new CapabilityError('/sign-in', 422, { message: 'Something is off.', errors: { remember: ['Something is off.'] } }));
        const { wrapper } = await view();

        await fill(wrapper, { email: 'sam@example.com', password: 'secret' });
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Something is off.');
    });
});
