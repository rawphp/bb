# The service worker, and how to take it back

`resources/js/app/sw.ts` builds to `public/sw.js` with scope `/` (PWA-004). `resources/js/app/updates.ts` registers it in production builds. Once a browser has it, it serves the cached shell and build to every app path, and keeps `bb-previous-build-*` copies of replaced builds (PWA-035).

## Rolling it back

Reverting the code does not remove the worker from devices. If `/sw.js` answers 404, the browser keeps the worker it has. If the Laravel fallback answers with the app's HTML, the update fails and the worker is kept too. A stale `public/sw.js` left on the server (it is gitignored build output) keeps the worker alive as well.

To take it off every device, serve the kill switch at `/sw.js`:

1. Deploy a build where `public/sw.js` is a copy of `public/sw-kill.js`:
   - with the PWA code still in place, run `cp public/sw-kill.js public/sw.js` after `vite build` in the deploy script;
   - after a revert of the PWA code, keep `public/sw-kill.js`, and commit it as `public/sw.js` with `git add -f public/sw.js`, since `public/sw.js` is gitignored.
2. Leave it there until the devices you care about have navigated to the app at least once. A browser checks `/sw.js` on a navigation: a cold launch, a reload, or following a link into the app. While `updates.ts` is deployed it also checks each time the app returns to the foreground. After a revert that check is gone, so an iPhone Home Screen app that stays in memory and is only brought back to the foreground picks the kill switch up only at its next cold launch. Keep the kill switch served for weeks, not days.
3. The kill switch installs at once, deletes every cache the worker made (the precache and the `bb-previous-build-*` copies) and unregisters itself. It reloads no tab, so a rollback never interrupts a workout or throws away an open sheet (PWA-035). An open tab is left under the kill switch, which has no fetch handler, so everything it loads comes from the network, and its next load is a plain page.

## What the kill switch also removes

- **Web Push subscriptions.** A push subscription belongs to the worker's registration, so unregistering ends it on that device. Reminders stop there, and Settings shows push as off. The server's `push_subscriptions` row for that device is deleted by the Web Push channel the next time a send gets the push service's 404 or 410 (`app/Jobs/SendPushNotification.php`), so it needs no manual clean-up. Users who want reminders again turn push on again once a working worker is back.
- **Turning push on while the kill switch is served hangs.** With the PWA code still deployed, `usePush().enable()` waits for `navigator.serviceWorker.ready`. That never settles, because each new registration unregisters itself. So serve the kill switch only for as long as the rollback needs, and expect "Turn on reminders" to do nothing meanwhile.

The install snooze (`bb.install-snoozed-until` in localStorage) is harmless after a rollback and needs no clean-up.

While the PWA code is in place and `/sw.js` is the kill switch, every page load registers it again, and it unregisters itself again. That costs a request per load and reloads nothing.
