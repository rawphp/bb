import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import { toneIcons } from '@/design/toneIcons';
import { inputViolations, screenFixture } from '@/testing/schemas';
import BucketCard from './BucketCard.vue';
import OnboardingView from './OnboardingView.vue';
import ScreeningRowView from './ScreeningRowView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);

// The contract's three states, and one per step this issue draws.
const review = screenFixture<OnboardingShowOutput>('onboarding_show');
const goal = screenFixture<OnboardingShowOutput>('onboarding_show.empty');
const rateLength = screenFixture<OnboardingShowOutput>('onboarding_show.warning');
const aboutYou = screenFixture<OnboardingShowOutput>('onboarding_show.about_you');
const screening = screenFixture<OnboardingShowOutput>('onboarding_show.screening');
const glp1 = screenFixture<OnboardingShowOutput>('onboarding_show.glp1');
const bodyFat = screenFixture<OnboardingShowOutput>('onboarding_show.body_fat');

function view(payload?: OnboardingShowOutput): VueWrapper {
    return mount(OnboardingView, { props: { payload }, attachTo: document.body });
}

/** Each onboarding_answer sent, checked against the write's schema snapshot. */
function sent(): Record<string, unknown>[] {
    return writeMock.mock.calls.map(([name, input]) => {
        expect(name).toBe('onboarding_answer');
        expect(inputViolations('onboarding_answer', input)).toEqual([]);
        return input as Record<string, unknown>;
    });
}

function next(wrapper: VueWrapper) {
    return wrapper.get('button[data-step="next"]');
}

function back(wrapper: VueWrapper) {
    return wrapper.get('button[data-step="back"]');
}

function radio(wrapper: VueWrapper, group: string, title: string) {
    const row = wrapper
        .get(`[role="radiogroup"][aria-label="${group}"]`)
        .findAll('[role="radio"]')
        .find((r) => r.text() === title || r.attributes('aria-label') === title);
    if (!row) throw new Error(`No ${title} in ${group}`);
    return row;
}

describe('OnboardingView', () => {
    let wrapper: VueWrapper | undefined;

    beforeEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        readMock.mockReset().mockResolvedValue(goal);
        writeMock.mockReset().mockResolvedValue({});
    });

    describe('every step', () => {
        it('UI-007 shows the server\'s counter, title and Next title, and reads nothing with a fixture', () => {
            wrapper = view(review);

            expect(wrapper.text()).toContain('Review');
            expect(wrapper.get('h2').text()).toBe('Review your plan');
            expect(next(wrapper).text()).toBe('Start my plan');
            expect(readMock).not.toHaveBeenCalled();
        });

        it('UI-007 a step another issue draws still shows its counter, title, Back and Next', () => {
            wrapper = view(rateLength);

            expect(wrapper.text()).toContain('Step 6 of 13');
            expect(wrapper.get('h2').text()).toBe('Rate and length');
            expect(next(wrapper).attributes('disabled')).toBeUndefined();
        });

        it('UI-030 shows the server\'s notices as callouts with their tone icon', () => {
            wrapper = view(rateLength);

            for (const notice of rateLength.notices) expect(wrapper.text()).toContain(notice.text);
            const icons = wrapper.findAll('p svg path').map((path) => path.attributes('d'));
            expect(icons.filter((d) => d === toneIcons.warning)).toHaveLength(rateLength.notices.length);
        });

        it('ONB-002 Next sends move next and reads the next step', async () => {
            wrapper = view(glp1);

            await next(wrapper).trigger('click');
            await flushPromises();

            expect(sent()).toEqual([{ answers: {}, move: 'next' }]);
            expect(readMock).toHaveBeenCalledWith('onboarding_show', {}, expect.any(Function), expect.any(Function));
            expect(wrapper.get('h2').text()).toBe(goal.step.title);
        });

        it('ONB-002 Back sends move back', async () => {
            wrapper = view(glp1);

            await back(wrapper).trigger('click');
            await flushPromises();

            expect(sent()).toEqual([{ answers: {}, move: 'back' }]);
        });

        it('UI-007 Next is off with the server\'s reason until the step can continue', () => {
            wrapper = view(goal);

            expect(next(wrapper).attributes('disabled')).toBeDefined();
            expect(back(wrapper).attributes('disabled')).toBeDefined();
            expect(wrapper.text()).toContain('Pick a goal and answer the question to continue.');
        });

        it('CAP-012 shows a refused answer in the server\'s words and stays on the step', async () => {
            writeMock.mockRejectedValueOnce(new CapabilityError('onboarding_answer', 422, { ok: false, error: { message: 'Pick a goal first.' } }));
            wrapper = view(glp1);

            await next(wrapper).trigger('click');
            await flushPromises();

            expect(wrapper.get('[role="alert"]').text()).toBe('Pick a goal first.');
            expect(wrapper.get('h2').text()).toBe('Weight-loss drugs');
        });

        it('CAP-010 a Next pressed while an answer saves is sent after it', async () => {
            const ready = { ...glp1, step: { ...glp1.step, can_continue: true } };
            readMock.mockResolvedValue(ready);
            let finish: (value: unknown) => void = () => {};
            writeMock.mockReturnValueOnce(new Promise((resolve) => (finish = resolve)));
            wrapper = view(ready);

            await radio(wrapper, glp1.glp1.question, 'Yes').trigger('click');
            await next(wrapper).trigger('click');
            await next(wrapper).trigger('click');
            expect(writeMock).toHaveBeenCalledOnce();
            finish({});
            await flushPromises();

            expect(sent()).toEqual([
                { answers: { uses_glp1: true }, move: 'stay' },
                { answers: {}, move: 'next' },
            ]);
        });

        it('CAP-010 while Back or Next is out the step is locked, and nothing tapped then is sent later', async () => {
            readMock.mockResolvedValue(glp1);
            let finish: (value: unknown) => void = () => {};
            writeMock.mockReturnValueOnce(new Promise((resolve) => (finish = resolve)));
            wrapper = view(screening);
            const row = wrapper.findAllComponents(ScreeningRowView)[5]!.get('[role="checkbox"]');

            await next(wrapper).trigger('click');
            expect(row.element.matches(':disabled')).toBe(true);
            expect(next(wrapper).attributes('disabled')).toBeDefined();
            expect(back(wrapper).attributes('disabled')).toBeDefined();
            await row.trigger('click');
            finish({});
            await flushPromises();
            await radio(wrapper, glp1.glp1.question, 'Yes').trigger('click');
            await flushPromises();

            expect(sent()).toEqual([
                { answers: {}, move: 'next' },
                { answers: { uses_glp1: true }, move: 'stay' },
            ]);
        });

        it('CAP-010 answers made while one saves are sent after it, in the order made', async () => {
            readMock.mockResolvedValue(screening);
            let finish: (value: unknown) => void = () => {};
            writeMock.mockReturnValueOnce(new Promise((resolve) => (finish = resolve)));
            wrapper = view(screening);
            const rows = wrapper.findAllComponents(ScreeningRowView);
            const at = (item: string) => screening.screening.rows.findIndex((r) => r.item === item);

            await rows[at('diabetes')]!.get('[role="checkbox"]').trigger('click');
            await rows[at('autoimmuneDisease')]!.get('[role="checkbox"]').trigger('click');
            await rows[at('gallbladderDisease')]!.get('[role="checkbox"]').trigger('click');
            await rows[at('weightLossSurgery')]!.get('[role="checkbox"]').trigger('click');
            expect(writeMock).toHaveBeenCalledOnce();
            finish({});
            await flushPromises();

            expect(sent().map((input) => input.answers)).toEqual([
                { screening: [{ item: 'diabetes', is_on: true }] },
                { screening: [{ item: 'autoimmuneDisease', is_on: true }] },
                { screening: [{ item: 'gallbladderDisease', is_on: false }] },
                { screening: [{ item: 'weightLossSurgery', is_on: true }] },
            ]);
            expect(next(wrapper).attributes('disabled')).toBeUndefined();
        });

        it.each([
            ['refused', new CapabilityError('onboarding_answer', 422, { ok: false, error: { message: 'Answer step 2 first.' } }), 'Answer step 2 first.'],
            ['unanswered', new CapabilityError('onboarding_answer', 0, { reason: 'timeout' }), 'may have worked'],
        ])('CAP-012 an answer %s drops the answers and the Next made behind it', async (_label, failure, message) => {
            const ready = { ...screening, step: { ...screening.step, can_continue: true } };
            readMock.mockResolvedValue(ready);
            let fail: (error: unknown) => void = () => {};
            writeMock.mockReturnValueOnce(new Promise((_resolve, reject) => (fail = reject)));
            wrapper = view(ready);
            const rows = wrapper.findAllComponents(ScreeningRowView);
            const at = (item: string) => screening.screening.rows.findIndex((r) => r.item === item);

            await rows[at('diabetes')]!.get('[role="checkbox"]').trigger('click');
            await rows[at('autoimmuneDisease')]!.get('[role="checkbox"]').trigger('click');
            await next(wrapper).trigger('click');
            fail(failure);
            await flushPromises();

            expect(writeMock).toHaveBeenCalledOnce();
            expect(wrapper.get('[role="alert"]').text()).toContain(message);
        });

        it('PWA-024 shows a skeleton while the step loads', () => {
            readMock.mockReturnValueOnce(new Promise(() => {}));
            wrapper = view();

            expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
            expect(readMock).toHaveBeenCalledWith('onboarding_show', {}, expect.any(Function), expect.any(Function));
        });

        it('CAP-004 says setup did not load, and tries again', async () => {
            readMock.mockRejectedValueOnce(new CapabilityError('onboarding_show', 500, null)).mockResolvedValueOnce(glp1);
            wrapper = view();
            await flushPromises();
            expect(wrapper.text()).toContain('could not be loaded');

            await wrapper.get('[data-test="retry"]').trigger('click');
            await flushPromises();

            expect(wrapper.get('h2').text()).toBe('Weight-loss drugs');
        });
    });

    describe('step 1, goal', () => {
        it('ONB-003 shows the help text and the three goals, none picked yet', () => {
            wrapper = view(goal);

            expect(wrapper.text()).toContain('Pick Fat loss. There is no separate recomp goal.');
            const rows = wrapper.get('[role="radiogroup"][aria-label="Goal"]').findAll('[role="radio"]');
            expect(rows.map((r) => r.text())).toEqual(['Fat loss', 'Muscle gain', 'Maintenance']);
            expect(rows.every((r) => r.attributes('aria-checked') === 'false')).toBe(true);
        });

        it('ONB-003 sends the goal picked and reads the step again', async () => {
            wrapper = view(goal);

            await radio(wrapper, 'Goal', 'Muscle gain').trigger('click');
            await flushPromises();

            expect(sent()).toEqual([{ answers: { goal: 'muscleGain' }, move: 'stay' }]);
            expect(readMock).toHaveBeenCalledOnce();
        });

        it('ONB-005 shows a goal the screening rules out as dimmed, with the server\'s reason', () => {
            const blocked = structuredClone(goal);
            blocked.goal.options[0] = { ...blocked.goal.options[0]!, is_enabled: false, detail: 'Not while trying to conceive.' };
            wrapper = view(blocked);

            expect(radio(wrapper, 'Goal', 'Fat loss').attributes('disabled')).toBeDefined();
            expect(wrapper.text()).toContain('Not while trying to conceive.');
        });

        it('ONB-021 asks about a recent diet and sends the answer', async () => {
            wrapper = view(goal);

            await radio(wrapper, goal.goal.recent_diet.question, 'Yes').trigger('click');
            await flushPromises();

            expect(wrapper.text()).toContain('In the past 8 weeks');
            expect(sent()).toEqual([{ answers: { finished_diet_recently: true }, move: 'stay' }]);
        });

        it('ONB-021 shows the maintenance-first advice, and its button sends the goal it names', async () => {
            const advised = structuredClone(goal);
            advised.goal.advice = { text: 'Start with Maintenance first.', action_title: 'Start with Maintenance', action_goal: 'maintenance' };
            wrapper = view(advised);

            expect(wrapper.text()).toContain('Start with Maintenance first.');
            await wrapper.get('[data-test="advice"]').trigger('click');
            await flushPromises();

            expect(sent()).toEqual([{ answers: { goal: 'maintenance' }, move: 'stay' }]);
        });
    });

    describe('step 2, about you', () => {
        beforeEach(() => {
            readMock.mockResolvedValue(aboutYou);
        });

        it('ONB-004 shows name, sex, date of birth, height and weight with their units', () => {
            wrapper = view(aboutYou);

            expect(wrapper.get<HTMLInputElement>('input[autocomplete="given-name"]').element.value).toBe('Sam');
            expect(wrapper.get('input[autocomplete="given-name"]').attributes('maxlength')).toBe('100');
            expect(radio(wrapper, 'Sex', 'Female').attributes('aria-checked')).toBe('true');
            expect(wrapper.get('[data-test="set-birth-date"]').text()).toBe('Set date of birth');
            expect(radio(wrapper, 'Height unit', 'cm').attributes('aria-checked')).toBe('true');
            expect(wrapper.find('input[aria-label="Height in cm"]').exists()).toBe(true);
            expect(radio(wrapper, 'Weight unit', 'kg').attributes('aria-checked')).toBe('true');
            expect(wrapper.find('input[aria-label="Weight in kg"]').exists()).toBe(true);
        });

        it('ONB-004 shows feet and inches when that is the unit', () => {
            wrapper = view({ ...aboutYou, about_you: review.about_you });

            expect(wrapper.get<HTMLInputElement>('input[aria-label="Feet in ft"]').element.value).toBe('5');
            expect(wrapper.get<HTMLInputElement>('input[aria-label="Inches in in"]').element.value).toBe('7');
            expect(wrapper.get<HTMLInputElement>('input[aria-label="Weight in lb"]').element.value).toBe('176.4');
        });

        it('ONB-004 saves a typed field when it is left, not on each keystroke, and keeps the server\'s Next and hint', async () => {
            wrapper = view(aboutYou);
            const weight = wrapper.get('input[aria-label="Weight in kg"]');

            await weight.setValue('80,5');
            expect(writeMock).not.toHaveBeenCalled();
            expect(next(wrapper).attributes('disabled')).toBeDefined();
            expect(wrapper.text()).toContain('Fill in every field to continue.');
            await weight.trigger('blur');
            await flushPromises();

            expect(sent()).toEqual([{ answers: { weight: { unit: 'kg', value: 80.5 } }, move: 'stay' }]);
            expect(readMock).toHaveBeenCalledOnce();
        });

        it('ONB-004 saves the name when it changes, and only then', async () => {
            wrapper = view(aboutYou);
            const name = wrapper.get('input[autocomplete="given-name"]');

            // setValue fires change, as leaving the field does.
            await name.trigger('change');
            await name.setValue('Sam Lee');
            await flushPromises();

            expect(sent()).toEqual([{ answers: { name: 'Sam Lee' }, move: 'stay' }]);
        });

        it('ONB-004 Set date of birth saves the server\'s starting date, as rp-clone does, and shows the date field', async () => {
            const dated = structuredClone(aboutYou);
            dated.about_you.birth_date = { ...dated.about_you.birth_date, date: dated.about_you.birth_date.initial, text: 'Oct 10, 1996' };
            readMock.mockResolvedValue(dated);
            wrapper = view(aboutYou);

            await wrapper.get('[data-test="set-birth-date"]').trigger('click');
            await flushPromises();

            expect(sent()).toEqual([{ answers: { birth_date: aboutYou.about_you.birth_date.initial }, move: 'stay' }]);
            expect(wrapper.get<HTMLInputElement>('input[type="date"]').element.value).toBe(aboutYou.about_you.birth_date.initial);
        });

        it('ONB-004 saves a date of birth picked, and puts back the saved one when the field is cleared', async () => {
            const dated = structuredClone(aboutYou);
            dated.about_you.birth_date = review.about_you.birth_date;
            wrapper = view(dated);
            const date = wrapper.get<HTMLInputElement>('input[type="date"]');

            expect(date.element.value).toBe('1990-04-09');
            expect(date.attributes('max')).toBe('2026-10-09');
            await date.setValue('');
            expect(date.element.value).toBe('1990-04-09');
            await date.setValue('1991-05-01');
            await flushPromises();

            expect(sent()).toEqual([{ answers: { birth_date: '1991-05-01' }, move: 'stay' }]);
        });

        it('ONB-004 saves whole feet, and each half with the other as its field shows it', async () => {
            const feetInches = { ...aboutYou, about_you: review.about_you };
            readMock.mockResolvedValue(feetInches);
            wrapper = view(feetInches);

            await wrapper.get('input[aria-label="Feet in ft"]').setValue('6.7');
            await wrapper.get('input[aria-label="Feet in ft"]').trigger('blur');
            await flushPromises();
            await wrapper.get('input[aria-label="Inches in in"]').setValue('1.5');
            await wrapper.get('input[aria-label="Inches in in"]').trigger('blur');
            await flushPromises();

            expect(sent().map((input) => input.answers)).toEqual([
                { height: { unit: 'feetInches', feet: 6, inches: 7 } },
                { height: { unit: 'feetInches', feet: 6, inches: 1.5 } },
            ]);
        });

        it('ONB-004 a number field cleared and left sends nothing and shows the saved number again', async () => {
            wrapper = view({ ...aboutYou, about_you: review.about_you });
            const weight = wrapper.get<HTMLInputElement>('input[aria-label="Weight in lb"]');

            await weight.setValue('');
            await weight.trigger('blur');
            await flushPromises();

            expect(writeMock).not.toHaveBeenCalled();
            expect(weight.element.value).toBe('176.4');
        });

        it('ONB-004 saves the sex picked at once, and a tap on the one picked sends nothing', async () => {
            wrapper = view(aboutYou);

            await radio(wrapper, 'Sex', 'Female').trigger('click');
            await radio(wrapper, 'Sex', 'Male').trigger('click');
            await flushPromises();

            expect(sent()).toEqual([{ answers: { sex: 'male' }, move: 'stay' }]);
        });

        it('ONB-004 a unit switched straight after typing saves the number first, in its own unit, so the server converts it', async () => {
            wrapper = view(aboutYou);
            const weight = wrapper.get('input[aria-label="Weight in kg"]');

            // A tap on lb takes focus from the field first, so its save goes out first.
            await weight.setValue('80');
            await weight.trigger('blur');
            await radio(wrapper, 'Weight unit', 'lb').trigger('click');
            await flushPromises();

            expect(sent()).toEqual([
                { answers: { weight: { unit: 'kg', value: 80 } }, move: 'stay' },
                { answers: { weight: { unit: 'lb' } }, move: 'stay' },
            ]);
        });

        it('ONB-004 switches the height unit with no number', async () => {
            wrapper = view(aboutYou);

            await radio(wrapper, 'Height unit', 'ft and in').trigger('click');
            await flushPromises();

            expect(sent()).toEqual([{ answers: { height: { unit: 'feetInches' } }, move: 'stay' }]);
        });

        it('ONB-004 a background refresh does not wipe a name or a number being typed', async () => {
            let refresh: (data: OnboardingShowOutput) => void = () => {};
            readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
                refresh = onUpdate as typeof refresh;
                return aboutYou;
            });
            wrapper = view();
            await flushPromises();
            const changed = structuredClone(aboutYou);
            changed.about_you.name = 'Samantha';
            changed.about_you.weight = { ...changed.about_you.weight, value: 82, text: '82 kg' };

            const name = wrapper.get<HTMLInputElement>('input[autocomplete="given-name"]');
            name.element.focus();
            name.element.value = 'Al';
            await name.trigger('input');
            refresh(structuredClone(changed));
            await flushPromises();
            expect(name.element.value).toBe('Al');

            const weight = wrapper.get<HTMLInputElement>('input[aria-label="Weight in kg"]');
            weight.element.focus();
            await weight.setValue('7');
            refresh(structuredClone(changed));
            await flushPromises();
            expect(weight.element.value).toBe('7');
        });

        it('ONB-004 feet and inches left while the first save is out save the feet as typed', async () => {
            const feetInches = { ...aboutYou, about_you: review.about_you };
            readMock.mockResolvedValue(feetInches);
            let finish: (value: unknown) => void = () => {};
            writeMock.mockReturnValueOnce(new Promise((resolve) => (finish = resolve)));
            wrapper = view(feetInches);

            await wrapper.get('input[aria-label="Feet in ft"]').setValue('6');
            await wrapper.get('input[aria-label="Feet in ft"]').trigger('blur');
            await wrapper.get('input[aria-label="Inches in in"]').setValue('2');
            await wrapper.get('input[aria-label="Inches in in"]').trigger('blur');
            finish({});
            await flushPromises();

            expect(sent().map((input) => input.answers)).toEqual([
                { height: { unit: 'feetInches', feet: 6, inches: 7 } },
                { height: { unit: 'feetInches', feet: 6, inches: 2 } },
            ]);
        });

        it('CAP-012 a typed value the server refuses gives way to the saved one, beside the server\'s reason', async () => {
            writeMock.mockRejectedValueOnce(new CapabilityError('onboarding_answer', 422, { ok: false, error: { message: 'Enter a weight under 400 kg.' } }));
            const saved = { ...aboutYou, about_you: { ...aboutYou.about_you, weight: { ...aboutYou.about_you.weight, value: 75, text: '75 kg' } } };
            wrapper = view(saved);

            await wrapper.get('input[aria-label="Weight in kg"]').setValue('900');
            await wrapper.get('input[aria-label="Weight in kg"]').trigger('blur');
            await flushPromises();

            expect(wrapper.get('[role="alert"]').text()).toBe('Enter a weight under 400 kg.');
            expect(wrapper.get<HTMLInputElement>('input[aria-label="Weight in kg"]').element.value).toBe('75');
        });
    });

    describe('step 3, health check', () => {
        it('SAFE-001 lists every screening item with its detail, and locks the ones step 2 answers', () => {
            wrapper = view(screening);
            const rows = wrapper.findAllComponents(ScreeningRowView);

            expect(rows).toHaveLength(screening.screening.rows.length);
            screening.screening.rows.forEach((row, index) => {
                const box = rows[index]!.get('[role="checkbox"]');
                expect(box.text()).toContain(row.label);
                if (row.detail) expect(box.text()).toContain(row.detail);
                expect(box.attributes('aria-checked')).toBe(String(row.is_on));
                expect(box.attributes('disabled') !== undefined).toBe(row.is_locked);
                expect(rows[index]!.find('[data-test="locked"]').exists()).toBe(row.is_locked);
            });
            expect(wrapper.text()).toContain(screening.screening.prompt);
        });

        it('SAFE-002 SAFE-014 shows the medical clearance and medical diets notes in their tones', () => {
            wrapper = view(screening);
            const icons = wrapper.findAll('p svg path').map((path) => path.attributes('d'));

            expect(wrapper.text()).toContain(screening.screening.clearance_note.text);
            expect(wrapper.text()).toContain(screening.screening.medical_diets_note.text);
            expect(icons).toEqual([toneIcons.warning, toneIcons.info]);
        });

        it('ONB-005 ticks or unticks one item at a time', async () => {
            readMock.mockResolvedValue(screening);
            wrapper = view(screening);
            const rows = wrapper.findAllComponents(ScreeningRowView);
            const diabetes = screening.screening.rows.findIndex((r) => r.item === 'diabetes');
            const gallbladder = screening.screening.rows.findIndex((r) => r.item === 'gallbladderDisease');

            await rows[diabetes]!.get('[role="checkbox"]').trigger('click');
            await flushPromises();
            await rows[gallbladder]!.get('[role="checkbox"]').trigger('click');
            await flushPromises();

            expect(sent()).toEqual([
                { answers: { screening: [{ item: 'diabetes', is_on: true }] }, move: 'stay' },
                { answers: { screening: [{ item: 'gallbladderDisease', is_on: false }] }, move: 'stay' },
            ]);
        });
    });

    describe('step 4, weight-loss drugs', () => {
        it('ONB-006 asks the question with its examples and sends the answer', async () => {
            wrapper = view(glp1);

            expect(wrapper.text()).toContain('For example semaglutide, tirzepatide or retatrutide.');
            expect(radio(wrapper, glp1.glp1.question, 'No').attributes('aria-checked')).toBe('true');
            await radio(wrapper, glp1.glp1.question, 'Yes').trigger('click');
            await flushPromises();

            expect(sent()).toEqual([{ answers: { uses_glp1: true }, move: 'stay' }]);
        });
    });

    describe('step 5, body fat', () => {
        it('ONB-007 shows the help text and the four buckets for the user\'s sex, the saved one picked', () => {
            wrapper = view(bodyFat);
            const cards = wrapper.findAllComponents(BucketCard);

            expect(wrapper.text()).toContain('Accuracy isn\'t critical. Don\'t use body-fat readings from a smart scale.');
            expect(cards.map((c) => c.text())).toEqual(['under 15%', '15-22%', '22-30%', 'over 30%']);
            expect(cards.map((c) => c.get('button').attributes('aria-pressed'))).toEqual(['false', 'true', 'false', 'false']);
            expect(cards.map((c) => c.get('button').attributes('aria-label'))).toEqual(bodyFat.body_fat.options.map((o) => `Body fat ${o.title}`));
            expect(cards[0]!.props('sex')).toBe('male');
        });

        it('ONB-007 sends the bucket picked', async () => {
            wrapper = view(bodyFat);

            await wrapper.findAllComponents(BucketCard)[3]!.get('button').trigger('click');
            await flushPromises();

            expect(sent()).toEqual([{ answers: { body_fat: 'highest' }, move: 'stay' }]);
        });

        it('ONB-007 draws the female figures before a sex is saved', () => {
            const noSex = structuredClone(bodyFat);
            noSex.body_fat.sex = null;
            wrapper = view(noSex);

            expect(wrapper.findAllComponents(BucketCard)[0]!.props('sex')).toBe('female');
        });
    });
});
