# 15. Capabilities

Every product operation is one capability in `rawphp/laravel-capabilities`, defined once and invoked from every channel under the same rules. The Vue app is one more client of those capabilities, next to the agent, MCP and the `capabilities` CLI. This file holds the rules that make "the agent can do everything the user can" true by construction.

## The server decides (CAP)

- **CAP-001** [B] The browser must hold no business rule. Vue code may only:
  - render what a read capability returned;
  - send what the user typed to a write capability;
  - look a value up in a table the server sent (CAP-016);
  - show the user's own pending input (OFF-009).
  
  Anything else that decides a number, a status, an eligibility or a next step belongs on the server. Unit conversion is a server job too (CAP-015).
- **CAP-002** [B] Every action the user can take on a screen must be a write capability. Every screen's data must come from one read capability that returns what the screen renders, named `<screen>_show` (for example `today_show`, `workout_show`, `meal_show`, `review_show`). The agent reads the same capabilities, so what it sees matches what the user sees.
- **CAP-003** [B] Capability names must match `^[a-z][a-z0-9_]{0,63}$`, written `<domain>_<verb>` or `<domain>_<object>_<verb>` (`workout_set_log`, `meal_check_in`, `phase_add`). No dots: Anthropic and MCP tool names do not allow them. Each capability also has a CLI domain and verb (`capabilities workout set-log`). Duplicate names fail at boot.
- **CAP-004** [B] There is one invoke path over HTTP: the package's `POST /capabilities/{name}`. The Vue app calls it with Sanctum SPA cookie auth. Tokens (CAP-023) call it with a bearer header. The host adds no REST controllers for domain reads or writes. Host routes exist only for sign-in, sign-out, the Stripe webhook (SUB-021) and the web app shell.
- **CAP-005** [B] Every capability's description must be written for both a person and a model. It says what the operation does, when to use it and what it changes, in two or three sentences. The description is the agent's tool description (AGT-003) and the CLI's help text.

## Results (CAP)

- **CAP-010** [B] A write must return:
  - `invalidates`, the read keys (CAP-017) whose data it changed;
  - `toast`, an optional message written by the server ("Meals 3 and 4 re-targeted");
  - `undo`, an optional undo token (CAP-013).
  
  The client refetches the invalidated reads that are on screen and marks the others stale.
- **CAP-011** [B] Every capability's catalog entry must declare `affects`, the read names its writes can change (`workout_set_log` affects `workout_show`, `today_show`, `train_show`). The offline outbox uses it to mark data pending before the server has answered (OFF-008).
- **CAP-012** [B] Errors must use the package's result shape. Validation errors name the field. Every user-facing message (validation, deny, conflict) is written by the server in plain words, and the client shows it as given.
- **CAP-013** [B] A write marked `reversible` must return an undo token, and `undo_apply` must revert it, together with any automatic change it caused (re-targeted meals, added sets). Undo is refused with a clear message when a later write depends on the change. The UI-010 toast's Undo and the agent's "undo that" both use `undo_apply`. Every UI-010 action must be reversible.
- **CAP-014** [B] Every write must accept an `Idempotency-Key` header, and the app always sends one. The same key with the same input returns the first result without running again. The same key with different input returns 422.

## Data shape (CAP)

- **CAP-015** [B] Reads must return values ready to show: in the user's units, rounded the way the spec says, with the unit label. Writes accept values in the user's units, tagged with the unit, and the server converts to metric for storage (SET-001).
- **CAP-016** [B] Where a screen must react to input faster than a round trip, or offline, the server must send a lookup table instead of a rule. The only v1 case is PROG-024 and WO-005. `workout_show` sends, for each set, the target reps at every weight the exercise's load steps allow within 15% of the planned weight. Outside that range it sends the PROG-025 "just log reps" text. The client looks the typed weight up in the table and does no arithmetic.
- **CAP-017** [B] A read key is the capability name plus its input in canonical JSON (sorted keys), for example `today_show{"date":"2026-11-01"}`. The offline cache (OFF-003) and `invalidates` both use it.
- **CAP-018** [B] Statuses must arrive decided. A pill or bar carries its text and a tone (`good`, `neutral`, `warning`, `info`, from UI-004). The client never picks a tone by comparing numbers.
- **CAP-019** [B] Writes that create a row must accept a client-made ULID for it, so the offline outbox can queue a create and then an edit of the same row (OFF-006). A ULID that already belongs to another user returns 404. Exception: `custom_exercise_save` creates without an id and the server assigns the integer `exercises.id`, because exercise ids are integers across the training tables; a custom exercise created offline cannot be edited or noted until it syncs.

## Who may call what (CAP)

- **CAP-020** [B] One host authorizer must decide every invoke, on every channel. It checks, in order:
  1. a signed-in actor exists;
  2. any resource ID in the input belongs to the actor (another user's ID returns 404, not 403);
  3. the token's scope allows the capability (CAP-023);
  4. billing (SUB-022);
  5. the agent policy (AGT-010).
  
  There is no other check, in a controller or in Vue.
- **CAP-021** [B] Users must be able to create personal access tokens in Settings with a name, an expiry (30, 90 or 365 days) and a scope: one of "Everything", "Read only", "Health bridge" (INT-001), or a custom list of capabilities. The token is shown once. Settings lists each token with when it was last used, and revokes it.
- **CAP-022** [B] Every write must be audited: who, through which channel (`ui`, `agent`, `mcp`, `cli`, `bridge`), when, the input and the result. The More menu's "Activity" list shows the user their last 100 writes, with the channel.
- **CAP-023** [B] Token scopes are Sanctum abilities whose names are capability names. MCP and the CLI use these tokens. The in-app agent does not; it runs in-process as the user (AGT-004).

## Parity (CAP)

- **CAP-030** [B] A test must fail when the Vue app calls a capability the agent cannot call. It reads every capability name used in `resources/js` and checks each one is in the agent's tool profile or on the agent exclusion list.
- **CAP-031** [B] The agent exclusion list must be short and say why for each entry. v1 entries: `account_delete` (a person must do it, ACC-005), `token_create` and `token_revoke` (an agent must not mint its own credentials), and the billing capabilities (money needs a person). Everything else is callable by the agent.
- **CAP-032** [B] Every capability must have a schema snapshot test, and a test that it registers with the surfaces it declares (`http`, `job`, `mcp`, `cli`, `agent`).

## Capability inventory

The full list is fixed in each milestone's plan. The table below is the starting map: rp-clone's API routes and client rules regrouped by domain. It shows the grain, not the final names.

| Domain | Reads | Writes (examples) |
|---|---|---|
| account | `account_show` | `account_update`, `account_delete`, `token_create`, `token_revoke` |
| onboarding | `onboarding_show` | `onboarding_answer`, `onboarding_complete` |
| profile and settings | `settings_show` | `profile_update`, `settings_update`, `diet_filters_set`, `notification_prefs_set` |
| plan and phases | `plan_show`, `phase_show` | `phase_add`, `phase_length_set`, `phase_goal_change`, `phase_end`, `phase_transition_complete`, `prebuilt_plan_apply` |
| schedule and today | `today_show`, `week_show` | `workout_move`, `workout_time_set`, `day_move`, `busy_period_set`, `away_week_set`, `schedule_update`, `training_weekdays_change`, `missed_week_resolve` |
| meals and food | `meal_show`, `nutrition_show`, `food_search`, `food_barcode_lookup` | `meal_food_add`, `meal_food_remove`, `meal_food_amount_set`, `meal_check_in`, `meal_check_in_undo`, `meal_planning_done`, `meal_copy`, `meal_redistribute`, `off_plan_log`, `custom_food_save`, `food_favorite_set` |
| scan and AI food | | `scan_photo`, `scan_label`, `scan_describe` (return a draft; nothing is saved until the user confirms) |
| saved meals, recipes, shopping | `saved_meals_show`, `recipes_show`, `recipe_show`, `shopping_show` | `saved_meal_save`, `recipe_save`, `recipe_generate`, `recipe_import`, `shopping_item_check` |
| mesocycles and exercises | `train_show`, `mesocycle_show`, `templates_show`, `exercises_show`, `exercise_show` | `mesocycle_build`, `mesocycle_copy`, `mesocycle_end`, `priority_set`, `exercise_swap`, `custom_exercise_save`, `exercise_note_set`, `exercise_load_step_set` |
| workouts | `workout_show` | `workout_start`, `workout_set_log`, `workout_set_skip`, `workout_set_add`, `workout_feedback_answer`, `workout_exercise_reorder`, `workout_finish` |
| review | `review_show` | `review_answer`, `review_complete` |
| weigh-ins and steps | `weigh_ins_show` | `weigh_in_log`, `weigh_in_update`, `weigh_in_delete`, `weigh_in_schedule_set`, `steps_log` |
| progress and learn | `progress_show`, `exercise_history_show`, `learn_show`, `article_show` | |
| undo | | `undo_apply` |
