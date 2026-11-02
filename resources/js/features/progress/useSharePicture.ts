import { nextTick, ref, watch } from 'vue';
import { sharePng, toPng } from './share';

/**
 * The Share picture (ANA-010, PWA-034). It is drawn as soon as `source` holds
 * everything to share, so a tap can hand it to navigator.share at once: browsers
 * refuse a share that comes long after the tap. A new source drops the old picture.
 *
 * `drawing` is true while the card must be mounted for `card()` to draw from.
 */
export function useSharePicture(card: () => SVGSVGElement | undefined, source: () => readonly unknown[]) {
    const sharing = ref(false);
    const drawing = ref(false);
    const shareError = ref<string | null>(null);
    const picture = ref<Blob | null>(null);
    let drawn = 0;
    // The current source's picture while it is drawn, so a tap waits for it rather than drawing twice.
    let inFlight: Promise<Blob> | null = null;

    async function drawNow(ticket: number): Promise<Blob> {
        drawing.value = true;
        try {
            await nextTick();
            const png = await toPng(card()!);
            if (ticket === drawn) picture.value = png;
            return png;
        } finally {
            if (ticket === drawn) drawing.value = false;
        }
    }

    function draw(): Promise<Blob> {
        const run = drawNow(++drawn);
        inFlight = run;
        run.catch(() => {
            if (inFlight === run) inFlight = null;
        });
        return run;
    }

    watch(
        source,
        (parts) => {
            picture.value = null;
            inFlight = null;
            // Not drawn yet is fine: a tap draws it then.
            if (parts.every(Boolean)) draw().catch(() => undefined);
        },
        { immediate: true },
    );

    async function share(content: { title: string; message: string }): Promise<void> {
        if (sharing.value) return;
        sharing.value = true;
        shareError.value = null;
        try {
            // Drawn already: shared straight from the tap. Otherwise the draw under way, or a new one if that failed.
            const png = picture.value ?? (await (inFlight ?? draw()).catch(() => draw()));
            await sharePng(png, content);
        } catch {
            shareError.value = 'The progress picture could not be shared.';
        } finally {
            sharing.value = false;
        }
    }

    return { sharing, drawing, shareError, share };
}
