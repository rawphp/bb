// Weekday names of a plan date ("2026-09-23"), formatting only: the date is a calendar
// day, so it is read as UTC midnight and named in UTC.
const long = new Intl.DateTimeFormat('en-US', { weekday: 'long', timeZone: 'UTC' });
const short = new Intl.DateTimeFormat('en-US', { weekday: 'short', timeZone: 'UTC' });

const midnight = (date: string) => new Date(`${date}T00:00:00Z`);

/** "Wednesday". */
export function weekdayName(date: string): string {
    return long.format(midnight(date));
}

/** The ISO weekday of `date`, looked up by its short name ("Wed") in the weekdays the server lists. */
export function isoWeekdayOf(date: string, weekdays: { iso: number; label: string }[]): number | undefined {
    const name = short.format(midnight(date));

    return weekdays.find((day) => day.label === name)?.iso;
}
