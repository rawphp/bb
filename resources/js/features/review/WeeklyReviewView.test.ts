import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, writeNow } from '@/app/call';
import { isPending } from '@/app/outbox';
import type { ReviewShowOutput } from '@/contracts/review_show';
import WeeklyReviewView from './WeeklyReviewView.vue';
import { review, sent, states } from './reviewTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));
vi.mock('@/app/outbox', async (original) => ({ ...(await original<typeof import('@/app/outbox')>()), isPending: vi.fn(() => false) }));
const push = vi.fn();
const replace = vi.fn();
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => ({ push, replace }) }));

type Wrapper = ReturnType<typeof show>;

function show(props: Record<string, unknown> = { payload: review() }) {
    return mount(WeeklyReviewView, { props, attachTo: document.body, global: { stubs: { teleport: true } } });
}

/** Serves each read after a write with `next`, as the server would answer review_show. */
function answersWith(next: ReviewShowOutput): void {
    vi.mocked(read).mockResolvedValue(next);
}

const counter = (wrapper: Wrapper) => wrapper.find('[role="group"] p').text();
const title = (wrapper: Wrapper) => wrapper.find('[role="group"] h2').text();
const texts = (wrapper: Wrapper, test: string) => wrapper.findAll(`[data-test="${test}"]`).map((row) => row.text());
const radios = (wrapper: Wrapper, test: string) => wrapper.findAll(`[data-test="${test}"] [role="radio"]`);
const dialog = () => document.body.querySelector<HTMLElement>('[role="dialog"]');

async function next(wrapper: Wrapper): Promise<void> {
    await wrapper.find('[data-step="next"]').trigger('click');
    await flushPromises();
}

async function back(wrapper: Wrapper): Promise<void> {
    await wrapper.find('[data-step="back"]').trigger('click');
}

describe('WeeklyReviewView', () => {
    beforeEach(() => {
        vi.mocked(read).mockReset().mockResolvedValue(review());
        vi.mocked(readFresh).mockReset().mockResolvedValue(review());
        vi.mocked(writeNow).mockReset().mockResolvedValue({});
        vi.mocked(isPending).mockReturnValue(false);
        push.mockReset();
        replace.mockReset();
        document.body.innerHTML = '';
    });

    it.each(states)('VIEW-001 renders the review_show fixture "%s" on every step it has', async (state) => {
        const payload = review(state || undefined);
        const wrapper = show({ payload });

        expect(wrapper.find('h1').text()).toBe('Weekly review');
        if (payload.state === 'unavailable') {
            expect(wrapper.find('[data-test="unavailable"]').text()).toBe(payload.message);
            expect(wrapper.find('[role="group"]').exists()).toBe(false);
            return;
        }
        for (const [index, step] of payload.steps.entries()) {
            expect(counter(wrapper)).toBe(`Step ${index + 1} of ${payload.steps.length}`);
            expect(title(wrapper)).toBe(step.title);
            expect(wrapper.find('[data-test="week"]').text()).toBe(payload.week_label);
            expect(wrapper.find(`[data-test="step-${step.key}"]`).exists()).toBe(true);
            if (index < payload.steps.length - 1) await next(wrapper);
        }
        expect(wrapper.find('[data-step="next"]').text()).toBe(payload.save_title);
        expect(read).not.toHaveBeenCalled();
    });

    it('REV-003 walks the seven steps with Back and Next, Back off on the first', async () => {
        const wrapper = show();

        expect(counter(wrapper)).toBe('Step 1 of 7');
        expect(title(wrapper)).toBe('Weigh-ins');
        expect(wrapper.find('[data-step="back"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-step="next"]').text()).toBe('Next');
        await next(wrapper);
        await next(wrapper);
        expect(title(wrapper)).toBe('Training');
        await back(wrapper);
        expect(title(wrapper)).toBe('Meals');
        expect(counter(wrapper)).toBe('Step 2 of 7');
    });

    it('REV-003 opens on the step a link names', () => {
        const wrapper = show({ payload: review(), step: 'calories' });

        expect(counter(wrapper)).toBe('Step 6 of 7');
        expect(title(wrapper)).toBe('Calories');
    });

    it('REV-005 training-only shows weigh-ins, training and progress, and saves on progress', async () => {
        const wrapper = show({ payload: review('training_only'), step: 'progress' });

        expect(counter(wrapper)).toBe('Step 3 of 3');
        expect(wrapper.find('[data-step="next"]').text()).toBe('Save');
        await next(wrapper);
        expect(sent()).toEqual([['review_complete', { week_start: '2026-10-05' }]]);
    });

    it('SCH-061 an away week shows its weigh-ins, then the preview', async () => {
        const payload = review('away');
        const wrapper = show({ payload });

        expect(wrapper.find('[data-test="step-weigh_ins"]').text()).toContain('No weigh-ins were scheduled or logged this week.');
        expect(wrapper.findAll('[data-test="weigh-in"]')).toHaveLength(0);
        await next(wrapper);
        expect(title(wrapper)).toBe('Preview');
        expect(counter(wrapper)).toBe('Step 2 of 2');
    });

    it('REV-003 saves with review_complete for the reviewed week, then leaves', async () => {
        const wrapper = show({ payload: review(), step: 'preview' });

        await next(wrapper);

        expect(sent()).toEqual([['review_complete', { week_start: '2026-10-05' }]]);
        expect(replace).toHaveBeenCalledWith('/today');
    });

    it('REV-003 a refused save stays on the step and says why', async () => {
        vi.mocked(writeNow).mockRejectedValueOnce(new CapabilityError('review_complete', 422, { error: { message: 'The week could not be built.' } }));
        const wrapper = show({ payload: review(), step: 'preview' });

        await next(wrapper);

        expect(wrapper.find('[data-test="message"]').text()).toBe('The week could not be built.');
        expect(replace).not.toHaveBeenCalled();
    });

    it('REV-004 Save is off while the server says the week cannot be saved, and shows why on the last step', () => {
        const payload = review();
        payload.can_save = false;
        payload.preview!.error = 'Monday would drop below your minimum.';
        const wrapper = show({ payload, step: 'preview' });

        expect(wrapper.find('[data-step="next"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-test="message"]').text()).toBe('Monday would drop below your minimum.');
    });

    it('Close leaves the review', async () => {
        const wrapper = show();

        await wrapper.find('[data-test="close"]').trigger('click');

        expect(replace).toHaveBeenCalledWith('/today');
    });

    it('CAP-002 reads review_show, showing a skeleton while it loads', async () => {
        vi.mocked(read).mockReturnValue(new Promise(() => {}));
        const wrapper = show({});
        await flushPromises();

        expect(read).toHaveBeenCalledWith('review_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-012 a failed read says why and Try again reads again', async () => {
        vi.mocked(read).mockRejectedValueOnce(new CapabilityError('review_show', 500, {}));
        const wrapper = show({});
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toContain('Your week could not be loaded.');
        vi.mocked(read).mockResolvedValue(review());
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(title(wrapper)).toBe('Weigh-ins');
    });

    it.each([
        ['meals', '[data-test="note"]'],
        ['training', '[data-test="lines"]'],
        ['progress', '[data-test="lines"]'],
        ['calories', '[data-test="lines"]'],
        ['preview', '[data-test="kcal"]'],
    ])('OFF-008 waiting writes dim the %s step\'s server numbers and say so', (step, numbers) => {
        const wrapper = show({ payload: review(), pending: true, step });

        expect(wrapper.text()).toContain('Waiting to sync');
        expect(wrapper.find(numbers).classes()).toContain('opacity-60');
    });

    it('OFF-008 the picked calorie option shows as pending', () => {
        const wrapper = show({ payload: review(), pending: true, step: 'calories' });

        expect(radios(wrapper, 'calorie-options')[0]!.attributes('aria-describedby')).toContain('pending');
    });

    it('OFF-008 an outbox entry for review_show marks the screen pending', () => {
        vi.mocked(isPending).mockImplementation((name) => name === 'review_show');
        const wrapper = show({ payload: review(), step: 'progress' });

        expect(wrapper.text()).toContain('Waiting to sync');
    });

    describe('weigh-ins', () => {
        it('REV-003 lists the week with missing weigh-ins in orange, each with Missing · Add', () => {
            const wrapper = show();

            expect(texts(wrapper, 'weigh-in')).toEqual(['Mon, 5 Oct82.9 kg', 'Wed, 7 OctMissing · Add', 'Fri, 9 OctMissing · Add', 'Sat, 10 Oct82.5 kg']);
            const missing = wrapper.findAll('[data-test="add-missing"]');
            expect(missing).toHaveLength(2);
            expect(missing[0]!.attributes('aria-label')).toBe('Add the missing weigh-in for Wed, 7 Oct');
            expect(wrapper.findAll('[data-test="weigh-in"]')[1]!.find('span').classes()).toContain('text-tone-warning');
        });

        it('REV-003 adds a missing weigh-in late, for its day', async () => {
            const wrapper = show();

            await wrapper.findAll('[data-test="add-missing"]')[0]!.trigger('click');

            expect(push).toHaveBeenCalledWith({ path: '/weigh-ins/log', query: { at: '2026-10-07T07:00:00+01:00' } });
        });

        it('REV-003 adds a weigh-in now', async () => {
            const wrapper = show();

            await wrapper.find('[data-test="add-weigh-in"]').trigger('click');

            expect(push).toHaveBeenCalledWith({ path: '/weigh-ins/log' });
        });
    });

    describe('meals', () => {
        it('REV-003 says how many meals were checked in and lists the rest', () => {
            const wrapper = show({ payload: review(), step: 'meals' });

            expect(wrapper.find('[data-test="note"]').text()).toBe('You checked in 24 of 28 meals.');
            expect(texts(wrapper, 'unchecked')).toEqual(['Meal 3Tue, 6 OctCheck in now', 'Meal 4Thu, 8 OctCheck in now']);
        });

        it('REV-003 checks a meal in now with meal_check_in, then reads the review again', async () => {
            const wrapper = show({ payload: review(), step: 'meals' });

            await wrapper.findAll('[data-test="check-in"]')[1]!.trigger('click');
            await flushPromises();

            expect(sent()).toEqual([['meal_check_in', { meal_id: '01JA0000000000000000MEAL02' }]]);
            expect(read).toHaveBeenCalledWith('review_show', {}, expect.any(Function), expect.any(Function));
        });
    });

    describe('training and progress', () => {
        it('REV-003 shows sessions, sets, flags and what next week is', () => {
            const wrapper = show({ payload: review(), step: 'training' });

            expect(texts(wrapper, 'line')).toEqual(['Sessions done2', 'Skipped1', 'Missed1', 'Sets done13 of 20 planned']);
            expect(wrapper.findAll('[data-test="line"] span')[5]!.classes()).toContain('text-tone-warning');
            expect(texts(wrapper, 'note')).toEqual(['Missed rep targets 2 sessions running: Chest', 'At the volume ceiling: Back', 'Bench Press: Moderate pain']);
            expect(wrapper.find('[data-test="next-week"]').text()).toBe('Next weekLast hard week');
        });

        it('REV-003 shows trend weight against the goal range and the week\'s rate against target', () => {
            const wrapper = show({ payload: review(), step: 'progress' });

            expect(texts(wrapper, 'line')).toEqual(['Trend weight82.7 kg', 'Goal range82.1 kg to 82.9 kg', "This week's rate-0.42% a week", 'Target rate-0.50% a week']);
            expect(wrapper.find('[data-test="pills"]').text()).toBe('Inside the goal rangeOn target');
        });

        it('REV-003 says when the phase has too little trend weight', () => {
            const payload = review();
            payload.progress = { lines: [], pills: [], empty_message: 'There is not enough trend weight in this phase to show progress yet.' };
            const wrapper = show({ payload, step: 'progress' });

            expect(wrapper.find('[data-test="step-progress"]').text()).toBe('There is not enough trend weight in this phase to show progress yet.');
        });
    });

    describe('goal', () => {
        it('REV-003 offers keeping the goal, recommended and picked, a new goal, or ending the plan', () => {
            const wrapper = show({ payload: review(), step: 'goal' });

            const options = wrapper.findAll('[data-test="goal-option"]');
            expect(options.map((o) => o.attributes('aria-label'))).toEqual(['Keep current goal, recommended', 'Choose a new goal', 'End the plan']);
            expect(options.map((o) => o.attributes('aria-pressed'))).toEqual(['true', 'false', 'false']);
        });

        it.each([1, 2])('PH-030 option %i opens the phase transition', async (index) => {
            const wrapper = show({ payload: review(), step: 'goal' });

            await wrapper.findAll('[data-test="goal-option"]')[index]!.trigger('click');

            expect(push).toHaveBeenCalledWith('/train/plan/transition');
            expect(writeNow).not.toHaveBeenCalled();
        });

        it('PH-041 keeping the goal stays in the review, and arrow keys move focus without leaving', async () => {
            const wrapper = show({ payload: review(), step: 'goal' });

            const options = wrapper.findAll('[data-test="goal-option"]');
            await options[0]!.trigger('click');
            await options[0]!.trigger('keydown', { key: 'ArrowDown' });

            expect(push).not.toHaveBeenCalled();
        });
    });

    describe('calories', () => {
        it('REV-010 shows last week\'s target and average, the reason, and the options', () => {
            const wrapper = show({ payload: review(), step: 'calories' });

            expect(texts(wrapper, 'line')).toEqual(["Last week's target2400 kcal", "Last week's average2239 kcal"]);
            expect(texts(wrapper, 'note')).toEqual(['You are on target, so calories stay as they are.']);
            const options = radios(wrapper, 'calorie-options');
            expect(options.map((o) => o.attributes('aria-label'))).toEqual(['Take the recommendation, recommended', 'Repeat this week', 'Choose my own calories', 'Drop fatigue']);
            expect(options[0]!.attributes('aria-checked')).toBe('true');
        });

        it('REV-013 each option shows next week\'s calories and macros, then its own words on the next line', () => {
            const wrapper = show({ payload: review(), step: 'calories' });

            const detail = radios(wrapper, 'calorie-options')[3]!.find('[id]');
            expect(detail.text()).toBe('Next week: 2424 kcal · P 195 · F 67 · C 259\nTake a break. Eating closer to maintenance will drop fatigue and may let you judge your progress better');
            expect(detail.classes()).toContain('whitespace-pre-line');
        });

        it('REV-031 picking an option sends the whole answer with review_answer, and nothing more', async () => {
            const wrapper = show({ payload: review(), step: 'calories' });

            await radios(wrapper, 'calorie-options')[1]!.trigger('click');
            await flushPromises();

            expect(sent()).toEqual([
                ['review_answer', { week_start: '2026-10-05', option: 'repeat_this_week', own_kcal: null, deload_too: null, update_step_plan: false, moves: [] }],
            ]);
        });

        it('REV-031 a new option clears the own amount, the deload answer and the moves the old one had', async () => {
            const payload = review('drop_fatigue');
            payload.preview!.moves = [{ from_index: 2, to_index: 0, kcal: 100 }];
            const wrapper = show({ payload, step: 'calories' });

            await radios(wrapper, 'calorie-options')[1]!.trigger('click');
            await flushPromises();

            expect(sent()).toEqual([
                ['review_answer', { week_start: '2026-10-05', option: 'repeat_this_week', own_kcal: null, deload_too: null, update_step_plan: false, moves: [] }],
            ]);
        });

        it('REV-031 a new own amount or deload answer clears the moves, as the week they were made on changes', async () => {
            const own = review('choose_own');
            own.preview!.moves = [{ from_index: 2, to_index: 0, kcal: 100 }];
            const wrapper = show({ payload: own, step: 'calories' });
            const field = wrapper.find('[data-test="own-kcal"]');
            await field.setValue('2300');
            await field.trigger('change');
            await flushPromises();
            const fatigue = review('drop_fatigue');
            fatigue.preview!.moves = [{ from_index: 2, to_index: 0, kcal: 100 }];
            const deload = show({ payload: fatigue, step: 'calories' });
            await deload.findAll('[data-test="deload"] [role="radio"]')[1]!.trigger('click');
            await flushPromises();

            expect(sent().map(([, input]) => input.moves)).toEqual([[], []]);
        });

        it('REV-027 a stall shows its notes and the server\'s pick', () => {
            const wrapper = show({ payload: review('stall'), step: 'calories' });

            expect(texts(wrapper, 'note')).toEqual(['Your progress has stalled', 'Your fat intake is close to its floor, so steps go up first.']);
            expect(radios(wrapper, 'calorie-options')[1]!.attributes('aria-checked')).toBe('true');
        });

        it('REV-012 a planned break says so and offers only Repeat and Choose my own besides it', () => {
            const wrapper = show({ payload: review('planned_break'), step: 'calories' });

            expect(texts(wrapper, 'note')).toEqual(['Next week is a deload, so it is a diet break at maintenance.']);
            expect(radios(wrapper, 'calorie-options')).toHaveLength(3);
        });

        it('REV-010 Choose my own shows the amount, and a new amount is sent when the field is left', async () => {
            const wrapper = show({ payload: review('choose_own'), step: 'calories' });

            const field = wrapper.find<HTMLInputElement>('[data-test="own-kcal"]');
            expect(field.element.value).toBe('2400');
            expect(wrapper.find('[data-test="own"]').text()).toContain('kcal a day');
            await field.setValue('2300');
            await field.trigger('change');
            await flushPromises();

            expect(sent()).toEqual([
                ['review_answer', { week_start: '2026-10-05', option: 'choose_own', own_kcal: 2300, deload_too: null, update_step_plan: false, moves: [] }],
            ]);
        });

        it('REV-010 an empty own amount sends nothing', async () => {
            const wrapper = show({ payload: review('choose_own'), step: 'calories' });

            const field = wrapper.find('[data-test="own-kcal"]');
            await field.setValue('');
            await field.trigger('change');

            expect(writeNow).not.toHaveBeenCalled();
        });

        it('REV-011 Drop fatigue asks to make it the deload week too, and sends the answer', async () => {
            const wrapper = show({ payload: review('drop_fatigue'), step: 'calories' });

            const deload = wrapper.find('[data-test="deload"]');
            expect(deload.text()).toContain('Make this your deload week too?');
            expect(deload.findAll('[role="radio"]').map((r) => r.attributes('aria-checked'))).toEqual(['true', 'false']);
            expect(deload.text()).toContain('The mesocycle ends a week early.');
            expect(deload.text()).toContain('Target RIR by week: 3, 2, 1, 0');
            await deload.findAll('[role="radio"]')[1]!.trigger('click');
            await flushPromises();

            expect(sent()).toEqual([
                ['review_answer', { week_start: '2026-10-05', option: 'drop_fatigue', own_kcal: null, deload_too: false, update_step_plan: false, moves: [] }],
            ]);
        });

        it('REV-011 a deload one or two weeks away becomes the break, with no question', () => {
            const wrapper = show({ payload: review('drop_fatigue_deload'), step: 'calories' });

            const deload = wrapper.find('[data-test="deload"]');
            expect(deload.find('[role="radio"]').exists()).toBe(false);
            expect(deload.text()).toContain('Deload now: the diet break and the deload are one week, starting next week.');
            expect(deload.text()).toContain('Target RIR by week: 3, 2, 1');
        });

        it('REV-050 offers to update the step plan, and sends the answer', async () => {
            const wrapper = show({ payload: review(), step: 'calories' });

            const offer = wrapper.find<HTMLInputElement>('[data-test="step-offer"]');
            expect(wrapper.find('[data-test="step-offer-row"]').text()).toBe('Update my step plan to 6,100 steps a day');
            expect(offer.element.checked).toBe(false);
            await offer.setValue(true);
            await flushPromises();

            expect(sent()).toEqual([
                ['review_answer', { week_start: '2026-10-05', option: 'recommendation', own_kcal: null, deload_too: null, update_step_plan: true, moves: [] }],
            ]);
        });

        it('REV-050 a step plan answer keeps the moves', async () => {
            const payload = review();
            payload.preview!.moves = [{ from_index: 2, to_index: 0, kcal: 100 }];
            const wrapper = show({ payload, step: 'calories' });

            await wrapper.find('[data-test="step-offer"]').setValue(true);
            await flushPromises();

            expect(sent()[0]![1].moves).toEqual([{ from_index: 2, to_index: 0, kcal: 100 }]);
        });

        it('CAP-012 a refused step plan answer puts the switch back, and a refused own amount puts the field back', async () => {
            vi.mocked(writeNow).mockRejectedValue(new CapabilityError('review_answer', 422, { error: { message: 'No.' } }));
            const offer = show({ payload: review(), step: 'calories' });
            await offer.find('[data-test="step-offer"]').setValue(true);
            await flushPromises();
            expect(offer.find<HTMLInputElement>('[data-test="step-offer"]').element.checked).toBe(false);

            const own = show({ payload: review('choose_own'), step: 'calories' });
            await own.find('[data-test="own-kcal"]').setValue('100');
            await own.find('[data-test="own-kcal"]').trigger('change');
            await flushPromises();
            expect(own.find<HTMLInputElement>('[data-test="own-kcal"]').element.value).toBe('2400');
        });

        it('CAP-012 a refused answer says why', async () => {
            vi.mocked(writeNow).mockRejectedValueOnce(new CapabilityError('review_answer', 422, { error: { message: 'That is below your safe minimum.' } }));
            const wrapper = show({ payload: review(), step: 'calories' });

            await radios(wrapper, 'calorie-options')[1]!.trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-test="message"]').text()).toBe('That is below your safe minimum.');
        });
    });

    describe('preview', () => {
        it('REV-003 shows next week\'s days with their type, workout and calories, and the hint', () => {
            const wrapper = show({ payload: review(), step: 'preview' });

            const days = texts(wrapper, 'day');
            expect(days).toHaveLength(7);
            expect(days[0]).toBe('Mon, 12 OctTraining · Push A2500 kcal');
            expect(wrapper.find('[data-test="hint"]').text()).toBe("Tap a day to move calories between days. The week's total stays the same.");
        });

        it('REV-004 a day that cannot move is not tappable, and a past day shows a lock', () => {
            const payload = review();
            payload.preview!.days[0] = { ...payload.preview!.days[0]!, can_move: false, kcal_label: null };
            payload.preview!.days[1] = { ...payload.preview!.days[1]!, is_locked: true };
            const wrapper = show({ payload, step: 'preview' });

            const days = wrapper.findAll('[data-test="day"]');
            expect(days[0]!.attributes('disabled')).toBeDefined();
            expect(days[1]!.find('[data-test="locked"]').exists()).toBe(true);
            expect(days[2]!.find('[data-test="locked"]').exists()).toBe(false);
        });

        it('REV-004 moves calories from a tapped day to another with review_answer, then closes the sheet', async () => {
            const payload = review();
            const wrapper = show({ payload, step: 'preview' });

            await wrapper.findAll('[data-test="day"]')[2]!.trigger('click');
            expect(dialog()?.textContent).toContain('Move calories');
            expect(dialog()?.textContent).toContain('Wed, 14 Oct');
            const others = [...dialog()!.querySelectorAll('[data-test="other-day"] option')].map((o) => o.textContent?.trim());
            expect(others).toEqual(['Mon, 12 Oct · 2500 kcal', 'Tue, 13 Oct · 2500 kcal', 'Thu, 15 Oct · 2500 kcal', 'Fri, 16 Oct · 2500 kcal', 'Sat, 17 Oct · 2250 kcal', 'Sun, 18 Oct · 2500 kcal']);
            expect(dialog()?.textContent).toContain('100 kcal');
            answersWith(payload);
            dialog()!.querySelector<HTMLButtonElement>('[data-save]')!.click();
            await flushPromises();

            expect(sent()).toEqual([
                ['review_answer', { week_start: '2026-10-05', option: 'recommendation', own_kcal: null, deload_too: null, update_step_plan: false, moves: [{ from_index: 2, to_index: 0, kcal: 100 }] }],
            ]);
            expect(wrapper.find('[data-test="move-sheet"]').exists()).toBe(false);
        });

        it('REV-004 a move sends the answered moves and every other answer as the server shows them', async () => {
            const payload = review('choose_own');
            payload.calories!.step_offer = { label: 'Update my step plan to 6,100 steps a day', accepted: true };
            payload.preview!.moves = [{ from_index: 1, to_index: 3, kcal: 50 }];
            const fatigue = review('drop_fatigue');
            const wrapper = show({ payload, step: 'preview' });
            const other = show({ payload: fatigue, step: 'preview' });

            await wrapper.findAll('[data-test="day"]')[2]!.trigger('click');
            await wrapper.find('[role="dialog"] [data-save]').trigger('click');
            await flushPromises();
            await other.findAll('[data-test="day"]')[2]!.trigger('click');
            await other.findAll('[role="dialog"]').at(-1)!.find('[data-save]').trigger('click');
            await flushPromises();

            expect(sent()).toEqual([
                [
                    'review_answer',
                    {
                        week_start: '2026-10-05',
                        option: 'choose_own',
                        own_kcal: 2400,
                        deload_too: null,
                        update_step_plan: true,
                        moves: [
                            { from_index: 1, to_index: 3, kcal: 50 },
                            { from_index: 2, to_index: 0, kcal: 100 },
                        ],
                    },
                ],
                ['review_answer', { week_start: '2026-10-05', option: 'drop_fatigue', own_kcal: null, deload_too: true, update_step_plan: false, moves: [{ from_index: 2, to_index: 0, kcal: 100 }] }],
            ]);
        });

        it('REV-004 the move sheet offers only the other days that can still move', async () => {
            const payload = review();
            payload.preview!.days[0] = { ...payload.preview!.days[0]!, is_locked: true };
            payload.preview!.days[1] = { ...payload.preview!.days[1]!, can_move: false };
            const wrapper = show({ payload, step: 'preview' });

            await wrapper.findAll('[data-test="day"]')[2]!.trigger('click');

            const others = [...dialog()!.querySelectorAll('[data-test="other-day"] option')].map((o) => o.textContent?.trim());
            expect(others).toEqual(['Thu, 15 Oct · 2500 kcal', 'Fri, 16 Oct · 2500 kcal', 'Sat, 17 Oct · 2250 kcal', 'Sun, 18 Oct · 2500 kcal']);
        });

        it('REV-004 gives calories to the tapped day from the picked one, by the stepper\'s amount', async () => {
            const wrapper = show({ payload: review(), step: 'preview' });

            await wrapper.findAll('[data-test="day"]')[0]!.trigger('click');
            const sheet = wrapper.find('[role="dialog"]');
            await sheet.findAll('[role="radio"]')[1]!.trigger('click');
            await sheet.find('[data-test="other-day"]').setValue('5');
            await sheet.find('button[aria-label="More: Calories"]').trigger('click');
            await sheet.find('[data-save]').trigger('click');
            await flushPromises();

            expect(sent().map(([, input]) => input.moves)).toEqual([[{ from_index: 5, to_index: 0, kcal: 125 }]]);
        });

        it('REV-004 a move the week refuses keeps the sheet open with why, sends nothing more, and a sheet opened later starts clear', async () => {
            vi.mocked(writeNow).mockRejectedValueOnce(new CapabilityError('review_answer', 422, { error: { message: 'Monday would drop below your minimum.' } }));
            const wrapper = show({ payload: review(), step: 'preview' });

            await wrapper.findAll('[data-test="day"]')[0]!.trigger('click');
            await wrapper.find('[role="dialog"] [data-save]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-test="move-sheet"]').exists()).toBe(true);
            expect(dialog()?.textContent).toContain('Monday would drop below your minimum.');
            expect(sent()).toHaveLength(1);
            await wrapper.find('[role="dialog"] [data-cancel]').trigger('click');
            await wrapper.findAll('[data-test="day"]')[3]!.trigger('click');
            expect(dialog()?.textContent).not.toContain('Monday would drop below your minimum.');
        });

        it('REV-004 Cancel closes the sheet and sends nothing', async () => {
            const wrapper = show({ payload: review(), step: 'preview' });

            await wrapper.findAll('[data-test="day"]')[0]!.trigger('click');
            await wrapper.find('[role="dialog"] [data-cancel]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-test="move-sheet"]').exists()).toBe(false);
            expect(writeNow).not.toHaveBeenCalled();
        });

        it('REV-004 a locked day cannot move', async () => {
            const payload = review();
            payload.preview!.days[0] = { ...payload.preview!.days[0]!, is_locked: true };
            const wrapper = show({ payload, step: 'preview' });

            await wrapper.findAll('[data-test="day"]')[0]!.trigger('click');

            expect(wrapper.find('[role="dialog"] [data-save]').attributes('disabled')).toBeDefined();
        });
    });
});
