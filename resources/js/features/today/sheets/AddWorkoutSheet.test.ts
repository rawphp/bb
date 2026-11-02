import { flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, writeNow } from '@/app/call';
import AddWorkoutSheet from './AddWorkoutSheet.vue';
import { refusal, sent, sheet, today } from './sheetTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const day = today();
const fresh = today('empty');

beforeEach(() => {
    readMock.mockReset().mockResolvedValue(day);
    vi.mocked(readFresh).mockReset().mockResolvedValue(day);
    writeMock.mockReset().mockResolvedValue({});
});

describe('AddWorkoutSheet', () => {
    it('SCH-032 offers the next mesocycle session, moved to this day', () => {
        const wrapper = sheet(AddWorkoutSheet, { payload: fresh });

        expect(wrapper.text()).toContain('Add workout');
        expect(wrapper.text()).toContain('Mesocycle session');
        expect(wrapper.find('[data-test="session"]').text()).toContain('Week 1 · Day 1 · Upper A');
        expect(wrapper.text()).toContain('Moves this session to Saturday, Sep 19.');
    });

    it('SCH-032 moves the next session here with a valid workout_move', async () => {
        const wrapper = sheet(AddWorkoutSheet, { payload: fresh });

        await wrapper.find('[data-test="session"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_move', { mesocycle_id: '01JBWZK7Q8R9S0T1V2W3X4Y5Z6', week: 1, day: 1, date: '2026-09-19' }]]);
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('SCH-038 offers no session on a day that has one, and says why', () => {
        const wrapper = sheet(AddWorkoutSheet, { payload: day });

        expect(wrapper.find('[data-test="session"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Next planned session');
        expect(wrapper.text()).toContain('This day already has a workout. Pick a different day.');
    });

    it('SCH-032 starts a new Other activity at 6 PM for 45 minutes, moderate, and needs a name', async () => {
        const wrapper = sheet(AddWorkoutSheet, { payload: fresh });

        expect(wrapper.find<HTMLInputElement>('input[type="text"]').element.value).toBe('');
        expect(wrapper.find<HTMLInputElement>('input[type="time"]').element.value).toBe('18:00');
        expect(wrapper.text()).toContain('Length: 45 min');
        expect(wrapper.find('[role="radio"][aria-checked="true"]').text()).toBe('Moderate');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Logged for nutrition only. Two activities in one day go in as one, like "Run + football".');

        await wrapper.find('input[type="text"]').setValue('   ');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('NUT-005 saves a new Other activity with the intensity picked, as a valid other_activity_save', async () => {
        const wrapper = sheet(AddWorkoutSheet, { payload: fresh });

        await wrapper.find('input[type="text"]').setValue(' Football ');
        await wrapper.find('input[type="time"]').setValue('19:00');
        await wrapper.find('[aria-label="More: Length"]').trigger('click');
        await wrapper.findAll('[role="radio"]').find((radio) => radio.text() === 'Hard')!.trigger('click');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([
            ['other_activity_save', { id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/), date: '2026-09-19', name: 'Football', start: '19:00', minutes: 50, intensity: 'hard' }],
        ]);
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('SCH-032 edits the day\'s activity in place: a day holds one', async () => {
        const wrapper = sheet(AddWorkoutSheet, { payload: day });

        expect(wrapper.text()).toContain('Edit activity');
        expect(wrapper.find<HTMLInputElement>('input[type="text"]').element.value).toBe('Tennis');
        expect(wrapper.find<HTMLInputElement>('input[type="time"]').element.value).toBe('18:45');

        await wrapper.find('[aria-label="Fewer: Length"]').trigger('click');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['other_activity_save', { id: '01JBX0A1B2C3D4E5F6G7H8J9K0', date: '2026-09-23', name: 'Tennis', start: '18:45', minutes: 40, intensity: 'moderate' }]]);
    });

    it('CAP-012 shows why the activity was not saved and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('other_activity_save', 422, refusal('Name is too long.', 'name')));
        const wrapper = sheet(AddWorkoutSheet, { payload: today('warning') });

        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('Name is too long.');
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('NUT-024 adds nothing to a past day', () => {
        const wrapper = sheet(AddWorkoutSheet, { payload: { ...day, add_workout: null } });

        expect(wrapper.text()).toContain('A workout can\'t be added to a past day.');
        expect(wrapper.find('input[type="text"]').exists()).toBe(false);
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('OFF-008 says the day is waiting to sync', () => {
        const wrapper = sheet(AddWorkoutSheet, { payload: day, pending: true });

        expect(wrapper.text()).toContain('Waiting to sync');
    });

    it('CAP-002 reads the day with a skeleton, then says why it failed', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('today_show', 500, null));
        const wrapper = sheet(AddWorkoutSheet, { date: '2026-09-23' });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(wrapper.text()).toContain('This day could not be loaded.');
    });

    it('CAP-002 fills the fields from the day once it is read', async () => {
        const wrapper = sheet(AddWorkoutSheet, { date: '2026-09-23' });
        await flushPromises();

        expect(wrapper.find<HTMLInputElement>('input[type="text"]').element.value).toBe('Tennis');
    });
});
