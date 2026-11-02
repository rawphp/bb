// The SF Symbols each tone draws in rp-clone's Tokens+SwiftUI.swift, as 24×24
// SVG paths: checkmark.circle.fill, minus.circle.fill,
// exclamationmark.triangle.fill and info.circle.fill.
const circle = 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z';

export const toneIcons = {
    good: `${circle}m-1.2 14.2-4-4 1.4-1.4 2.6 2.6 5.6-5.6 1.4 1.4z`,
    neutral: `${circle}M7 11h10v2H7z`,
    warning: 'M10.3 3.9a2 2 0 0 1 3.4 0l8 13.6A2 2 0 0 1 20 20.5H4a2 2 0 0 1-1.7-3zM11 9v5h2V9zm0 7v2h2v-2z',
    info: `${circle}m-1 5h2v2h-2zm0 4h2v6h-2z`,
} as const;
