// How a meal's check-in box looks in each state (MEAL-047): a shape as well as a colour
// (UI-030). ItemCard's check-in box and the meal screen's CheckInButton both draw from it.
export type CheckInState = 'not_checked_in' | 'on_track' | 'under' | 'over';

export const checkInLook: Record<CheckInState, { shape: 'empty' | 'check' | 'down' | 'up'; color: string }> = {
    not_checked_in: { shape: 'empty', color: 'text-secondary-text' },
    on_track: { shape: 'check', color: 'text-tone-good' },
    under: { shape: 'down', color: 'text-tone-warning' },
    over: { shape: 'up', color: 'text-tone-warning' },
};

/** The mark drawn in the filled box, by shape. */
export const checkMarks: Record<'check' | 'down' | 'up', string> = {
    check: 'M8 12.5l2.75 2.75L16 9.5',
    down: 'M12 7.5v9M8 12.5l4 4 4-4',
    up: 'M12 16.5v-9M8 11.5l4-4 4 4',
};
