# 16. Offline

Gyms are often basements. The model has three parts, and none of them puts a rule in the browser (CAP-001):

1. The server sends ready-to-show screens, and the app caches them.
2. Every write goes into an outbox, which replays in order when the app is online.
3. While a write is pending, the app shows what the user typed and marks the server-derived numbers as pending.

Decided by Tom, 2026-11-01.

## Shell and cache (OFF)

- **OFF-001** [B] A workout started online must be finishable offline, and nothing the user typed may be lost. This is the acceptance test for the whole file (Playwright, OFF-050).
- **OFF-002** [B] A service worker must precache the app shell (HTML, JS, CSS, fonts, icons). The installed app opens offline straight to the last screen, from cache.
- **OFF-003** [B] Every read result must be stored in IndexedDB under its read key (CAP-017), with the time it was fetched. On screen open, the app shows the cached copy at once and refreshes it in the background (stale-while-revalidate). With no cached copy and no network, the screen shows "Not available offline" and what it needs.
- **OFF-004** [B] The server must tell the app what to keep warm. `sync_manifest` returns the read keys to prefetch:
  - today, tomorrow and the rest of the current week;
  - today's and tomorrow's workouts in full;
  - the meals on those days;
  - the current mesocycle;
  - the weigh-in screen.
  
  The app fetches them on open, on reconnect and after each replay. Which keys are listed is the server's decision.
- **OFF-005** [B] The app must ask for persistent storage (`navigator.storage.persist()`) after onboarding. Cached reads not in the current manifest and older than 30 days are dropped.

## Outbox (OFF)

- **OFF-006** [B] Every write must go through the outbox, online or not, so there is one path. An entry holds:
  - the capability name and the input;
  - the idempotency key (CAP-014);
  - any ULIDs it creates (CAP-019);
  - `occurred_at`, the moment the user acted;
  - the signed-in user's ID.
  
  Entries replay one at a time in the order they were made. A queued create followed by a queued edit of the same row works because the create carries its ULID.
- **OFF-007** [B] The server must use `occurred_at` for anything tied to a date or time: which day a set or check-in belongs to, a workout's start and end (WO-010). It accepts `occurred_at` up to 7 days old and up to 5 minutes in the future. Outside that window the write fails with a message.
- **OFF-008** [B] While an entry is pending, every cached read in that capability's `affects` list (CAP-011) must show its server-derived numbers as pending: dimmed, with "Waiting to sync" in place of the value's status text. Totals, remaining macros, re-targeted meals and set counts are all server-derived.
- **OFF-009** [B] A view must show the user's own pending input on the row it belongs to, with a small pending mark: the logged set's weight and reps, the checked meal's tick, a typed weigh-in. It shows the values as typed and computes nothing from them.
- **OFF-010** [B] The More menu's sync line (ACC-003) must show the outbox count while it is not empty. Tapping it opens the outbox: each pending entry in plain words ("Logged set 3 of Bench Press, 100 kg × 8"), when it was made, and its state.
- **OFF-011** [B] An entry with no answer must stay and retry with backoff (2 s, then doubling, at most 5 minutes), and again at once on reconnect. No answer is a network failure, a 5xx or 429 from something in front of the app (a gateway, the throttle), the app saying it is still running that write, or the app's rate limit refusing it before it ran, which gives the key back and says how long to wait. Any other answer from the app is final, because the server keeps it under the entry's idempotency key (CAP-014): a 4xx (validation, deny, not found), or a 5xx in the app's own error shape, must move the entry to "Couldn't sync", show the server's message, and hold back later entries that use a ULID the failed entry created. So does the app saying it is still running the write ten times in a row, since the run that held the key has died; the list then says the change may already be saved. The user can edit and retry a failed entry under a new key, or discard it.
- **OFF-012** [B] The outbox belongs to the user who made it. If the session expires, the outbox waits and replays after that user signs in again. If a different user signs in on the device, the app must warn that the previous user's unsynced changes will be deleted, and delete them only after the user confirms.

## Training offline (OFF)

- **OFF-020** [B] `workout_show` must contain everything the session needs without the network:
  - the exercises and every set's target;
  - the PROG-024 rep tables (CAP-016);
  - which feedback questions to ask, and after which set (FB rules);
  - the exercise notes;
  - the WO-009 next-meal line.
- **OFF-021** [B] These workout actions must work offline: start, log a set, edit a set, skip a set, add a set, answer feedback, edit a note, reorder exercises, finish. These need the network and say so: swapping an exercise, adding an exercise, looking back at earlier weeks (WO-008), unless that week is cached.
- **OFF-022** [B] No set target in a session depends on another set logged in the same session, apart from the weight a user types (WO-005, WO-006), which the rep table covers. Feedback changes the next weekly occurrence (PROG-010), so nothing in the open session goes stale offline.
- **OFF-023** [B] A workout finished offline shows "Done · syncing" on Today. The nutrition re-target it causes (SCH-052, SCH-054) appears after replay, with the usual toast.

## Nutrition and check-ins offline (OFF)

- **OFF-030** [B] These must work offline: checking a meal in as planned, undoing a check-in, logging a weigh-in, logging steps, ticking a shopping item.
- **OFF-031** [B] These need the network and say so: food search, barcode lookup, every scan and AI feature, recipe generation and import, the weekly review, editing a meal's foods (the server scales the grams), and the agent.

## Proof (OFF)

- **OFF-050** [B] Playwright must run this journey on a phone viewport. Open the app online, start today's workout, go offline, log every set and answer the feedback, finish, reload the page while still offline, go online. The server then holds every set exactly once, with the offline `occurred_at` times, and Today shows the re-targeted meals.
- **OFF-051** [B] A test must replay the same outbox twice (a lost response) and show each write applied once (CAP-014).
