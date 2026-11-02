// Teleport targets the shell renders (AppShell.vue). BottomSheet (UI-005) and
// Toast (UI-010) render into these, so they sit over the tab bar and inside
// the phone column on wide screens (PLT-002).
export const SHEET_HOST_ID = 'bb-sheet-host';
export const TOAST_HOST_ID = 'bb-toast-host';
export const SHEET_HOST = `#${SHEET_HOST_ID}`;
export const TOAST_HOST = `#${TOAST_HOST_ID}`;
