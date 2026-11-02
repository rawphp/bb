// Whether the page runs as the installed Home Screen app: the display mode, or Safari's
// own flag on iOS. The install offer (PWA-003) and Web Push (needs-home-screen) both ask.
export function isStandalone(): boolean {
    return window.matchMedia?.('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true;
}
