# 18. Native feel

Installed to the home screen, Bodybuilder must look and behave like a native phone app. Each requirement is checkable on a real iPhone and Android phone. Screens follow rp-clone's layouts (19-views.md) and the shared components in 13-shared-ui.md.

## Install and launch (PWA)

- **PWA-001** [B] The web app manifest must set `display: standalone`, portrait orientation, theme and background colours from the design tokens for light and dark, and icons at every size iOS and Android ask for, including maskable icons.
- **PWA-002** [B] iOS must get `apple-mobile-web-app-capable`, a status bar style that matches the theme, `apple-touch-icon`, and launch images for current iPhone sizes, so a cold start shows a branded splash, not a white screen.
- **PWA-003** [B] In a browser tab on a phone, the app must show a one-time install prompt after onboarding: Chrome's install prompt on Android, and on iOS a sheet showing Share → Add to Home Screen. Dismissed means not shown again for 30 days.
- **PWA-004** [B] A cold start of the installed app must show the cached Today screen within 1 second on a mid-range phone (OFF-003). Fresh data replaces it without a layout jump.

## Layout (PWA)

- **PWA-010** [B] The layout must respect safe areas (`env(safe-area-inset-*)`) on every edge, with `viewport-fit=cover`. The tab bar sits above the home indicator, and headers sit below the notch or Dynamic Island.
- **PWA-011** [B] The page must never scroll as a whole or bounce to show a blank area behind the app. Each screen scrolls its own content (`overscroll-behavior: contain`). Pull-to-refresh is the app's own, on Today, Nutrition, Train and Progress.
- **PWA-012** [B] Text in controls must not be selectable, a long press must not open the browser's callout menu, and taps must not flash a highlight. Content text (articles, notes) stays selectable.
- **PWA-013** [B] Inputs must be at least 16 px so iOS does not zoom on focus. Pinch zoom stays available for accessibility (UI-030).
- **PWA-014** [B] Numeric fields must open the right keypad: `inputmode="decimal"` for weights and grams, `inputmode="numeric"` for reps and steps.

## Navigation and motion (PWA)

- **PWA-020** [B] Each tab must keep its own navigation stack, and switching tabs keeps each stack and its scroll position. Tapping the active tab pops to its root, and tapping it again scrolls to the top.
- **PWA-021** [B] Pushing a screen must slide it in from the right over the previous one, and going back must reverse it. Screens animate with transforms only, at 60 fps, within 300 ms. With `prefers-reduced-motion`, they cross-fade instead.
- **PWA-022** [B] A swipe from the left edge must go back, following the finger and settling on release. The browser's own swipe-back must not also fire. UI-020 still holds on the workout screen: no swipe gesture fires by accident there, so its edge-swipe zone is 16 px.
- **PWA-023** [B] Bottom sheets (UI-005) must open with a spring, drag down to close (except the Feedback sheet, UI-022), and dim the screen behind. Sheets can have a half-height and a full-height position where the content needs both.
- **PWA-024** [B] Every tap must give visible feedback within 100 ms: a pressed state on buttons and rows. Loading states are skeletons in the shape of the content. There is no full-screen spinner anywhere.
- **PWA-025** [B] The browser's back button and Android's system back must follow the same stack as the in-app back. Each screen has a URL, so a notification or link opens straight into it (NOTIF-011).

## Device features (PWA)

- **PWA-030** [B] During an active workout, the app must hold a screen wake lock (Screen Wake Lock API) so the phone does not sleep between sets. It releases the lock on finish or when the user leaves the workout for over 30 minutes.
- **PWA-031** [B] Barcode scanning (FOOD-040, FOOD-041) must use the rear camera with a live viewfinder, decoding with the `BarcodeDetector` API where the browser has it and a WebAssembly decoder (zxing-wasm) where it doesn't, including iOS Safari.
- **PWA-032** [B] Photo and label scans (FOOD-042 to FOOD-044) and chat photos (AGT-022) must open the camera directly, and offer the photo library.
- **PWA-033** [B] Where the device supports it (`navigator.vibrate`, Android), logging a set, checking a meal in and finishing a workout must give a short haptic tap. iOS has no web haptics; the app adds nothing there.
- **PWA-034** [B] Share progress (ANA-010) must use the Web Share API with the generated image, falling back to a download.
- **PWA-035** [B] When a new version is deployed, the app must update in the background and show a quiet "Updated, tap to reload" banner. It never reloads by itself mid-workout or with a sheet open.

## Look (PWA)

- **PWA-040** [B] The type stack must be the system font (`-apple-system`, `system-ui`), with sizes from the design tokens. Colours, radii, spacing and macro colours (UI-011) come from rp-clone's tokens (`ios/Sources/RPCore/DesignSystem/ColorToken.swift`, `Macro.swift`, `StatusTone.swift`, and `ios/App/DesignSystem/Tokens+SwiftUI.swift`), carried into CSS custom properties.
- **PWA-041** [B] Light and dark mode must follow the system, with the manual override from SET-002, and switch without a reload.

## Proof (PWA)

- **PWA-050** [B] Every milestone that changes UI must be walked on a real iPhone, installed to the home screen, and on Android Chrome before it is called done. Screenshots go to `docs/screenshots/<area>/`.
- **PWA-051** [B] Lighthouse accessibility and best-practices audits must pass on Today, Workout and Meal with no failure. Chrome must report the app installable (DevTools → Application → Manifest shows no errors).
