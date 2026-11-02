import { watch } from 'vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { TodayShowOutput } from '@/contracts/today_show';

/** What every Today sheet takes: the day Today shows (or a /dev/screens fixture), else the date to read. */
export interface DaySheetProps {
    payload?: TodayShowOutput;
    date?: string;
    /** The day's last write is waiting in the outbox (OFF-008). */
    pending?: boolean;
}

/**
 * A Today sheet's read and writes. It reads the day it edits (today_show) unless it was
 * given Today's copy, and each write reads the day again once the server takes it
 * (CAP-010). `seed` fills the sheet's fields once, from the first copy of the day it gets.
 * A sheet is open while it is mounted: its parent mounts it with v-if, so each opening
 * starts fresh, with its own create id and fields.
 */
export function useDaySheet(props: DaySheetProps) {
    const date = props.date ?? props.payload?.date;
    const read = useRead<TodayShowOutput>('today_show', date ? { date } : {}, 'This day could not be loaded.', props.payload);

    function seed(fill: (day: TodayShowOutput) => void): void {
        let filled = false;
        watch(
            read.data,
            (day) => {
                if (day && !filled) {
                    filled = true;
                    fill(day);
                }
            },
            { immediate: true },
        );
    }

    return { ...read, ...useWrite(read.load), seed };
}
