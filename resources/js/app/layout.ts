// The one wide-screen breakpoint (PLT-002, NAV-003; docs/architecture.md, "Wide screens").
// Tailwind's md: prefix (48rem) and shell.css's @media block use the same 768 px.

/** From this window width up, the tab bar is a left rail and each screen sits in a centred column. */
export const WIDE_MIN_PX = 768;

/** WIDE_MIN_PX as a media query, for window.matchMedia. */
export const WIDE_QUERY = '(min-width: 768px)';
