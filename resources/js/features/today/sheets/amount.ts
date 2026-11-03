/**
 * A typed macro amount as a number of zero or more; an empty field is 0, and anything else
 * is none. A comma is the decimal point too: it is the only one on a comma-decimal region's
 * keypad. Either mark followed by three digits could be a thousands separator ("1,200",
 * "1.200"), so that is none. The off-plan and manual check-in sheets read their fields with it.
 */
export function amount(text: string): number | null {
    if (/[.,]\d{3}$/.test(text.trim())) return null;
    const trimmed = text.trim().replace(',', '.');
    if (trimmed === '') return 0;
    return /^(\d+\.?\d*|\.\d+)$/.test(trimmed) ? Number(trimmed) : null;
}
