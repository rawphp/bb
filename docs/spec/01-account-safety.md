# 01. Account, billing, platforms and safety

## Account (ACC)

- **ACC-001** [D][H][B] Users must be able to sign up and sign in with email and password, or with a 6-digit one-time code sent by email. *Bodybuilder: Sign in with Apple and Google are dropped; both need an app-store identity or an OAuth app per self-hosted instance. Passkeys are a v2 candidate.*
- **ACC-002** [I] One account must hold both nutrition and training data. Differs from RP: RP keeps the two apps on separate logins.
- **ACC-003** [D][B] The account must sync across the user's devices. The More menu must show sync status, for example "Synced a few seconds ago", or "3 changes waiting to sync" while the outbox is not empty (OFF-010).
- **ACC-004** [G] Users must be able to reset a forgotten password by email.
- **ACC-005** [G] Users must be able to delete their account and all data from Settings, with a typed confirmation.
  - *Bodybuilder (decided 2026-11-03, #91): with billing on, deleting is refused while any of the account's Stripe subscriptions would renew (a trial, a paid subscription or a first payment still being taken, not set to end at its period's end), with a message to cancel it in Manage subscription first (SUB-007), and also while Stripe cannot be reached; nothing is deleted either way. An open Checkout page is closed first. Deleting does not cancel subscriptions in Stripe.*
- **ACC-006** [D] Users must be able to sign out from Settings.

## Billing (SUB)

Bodybuilder ships two ways from one codebase: free to self-host, and a paid hosted version run by the project. Billing is a module, off unless `BILLING_ENABLED=true`. The hosted instance turns it on; a self-hosted instance leaves it off. With the module off, SUB-001 to SUB-011 do not apply and no paywall, price or banner appears anywhere (SUB-020). Hosted-only behaviour must be configuration, never a fork or a private branch (SUB-023).

- **SUB-020** [B] With billing off, every feature must be available to every signed-in user, and no screen may mention a subscription.
- **SUB-021** [B] With billing on, payments must go through Stripe Checkout and the Stripe customer portal. The server learns subscription state only from Stripe, never from the browser: a verified Stripe webhook, or Stripe's API read back by the server.
- **SUB-022** [B] Lapsed-user write blocking (SUB-010) must be enforced once, in the capability authorizer (CAP-020), so it covers the UI, the agent, MCP and the CLI alike. Deleting the account, signing in and out, revoking an access token, turning off push, and billing actions stay allowed.
- **SUB-023** [B] Everything the hosted version needs must be in the public repo behind configuration: billing, agent usage limits (AGT-005), the instance's Anthropic key, Web Push keys, mail. A self-hosted instance with the same settings behaves the same.
- **SUB-024** [B] With billing on, the agent is part of the paid plan, and trial users get it too, within the trial's usage limit (AGT-005). A user who has never subscribed keeps it within the same limits, so chat onboarding (AGT-030) works before the paywall; a user whose subscription has ended loses it.

With billing on, these apply:


- **SUB-001** [I] One subscription must unlock every feature in the app. Differs from RP: RP sells Diet and Hypertrophy separately.
- **SUB-002** [D][H] The app must offer monthly and yearly plans, and a 6-month plan. Prices are a business decision; draft 1 suggests $34.99 per month, $179.99 per 6 months and $299.99 per year [G].
- **SUB-003** [G] New users must get a 7-day free trial, started after onboarding and before the first plan is generated.
- **SUB-004** [D][G] The paywall must appear at the end of onboarding, showing the plan preview behind it, so the user sees what they are paying for.
- **SUB-005** [D][H][B] Purchases must work through Stripe (SUB-021). *Bodybuilder: replaces the App Store. A PWA cannot use StoreKit.*
- **SUB-006** Deferred. [D][H] Purchases must carry a 30-day money-back guarantee, limited to one refund per customer. *Bodybuilder: a business policy for whoever runs a hosted instance, handled in Stripe, not in code.*
- **SUB-007** [D][B] Users must be able to switch plans and cancel from Settings, which opens the Stripe customer portal.
- **SUB-008** Dropped. [G] Restore purchases is an App Store concept. A Stripe subscription belongs to the account, so there is nothing to restore.
- **SUB-009** [D][B] The app must support promo pricing (seasonal sale prices, referral and gym codes) without a release, using Stripe coupons and promotion codes entered in Checkout.
- **SUB-010** [G] When a subscription lapses, the app must keep all data and show it read-only until the user resubscribes.
- **SUB-011** Dropped. [D][G] Checkout always starts from a signed-in account, so every purchase already belongs to one.

## Platforms (PLT)

- **PLT-001** [B] The app must be a PWA that installs to the home screen and runs in standalone mode on iPhone (iOS 17.4 or later, Safari) and Android (Chrome 120 or later). The phone is the primary target. A Laravel 13 server holds every rule, all data, food search and the AI calls.
- **PLT-002** [H][B] The same app must run in a desktop browser with full features. In a window 768 px wide or more, the app fills the window with the left rail (NAV-003) and each screen in a centred 40rem column. Narrower windows keep the phone layout, centred up to 480 px. *Bodybuilder: was a centred phone column at every width, with NAV-003 deferred.*
- **PLT-003** [G][B] Food logging by search and custom food must work everywhere. Camera scanning (barcode, label, photo) uses the device camera where there is one, and otherwise offers a photo upload.
- **PLT-004** Dropped. [D] Covered by PLT-002: any device with a modern browser runs the app.
- **PLT-005** [D] The app must support light and dark mode, following the system setting with a manual override.
- **PLT-006** Deferred. [G] Layouts must scale to iPad and wide browser windows without a separate tablet design. Differs from RP: RP has no iPad layout and users ask for one.

## Health screening (SAFE)

- **SAFE-001** [D] Before any nutrition targets are generated, the app must ask the user to confirm none of these apply. Any item except "trying to conceive" must block all calorie targets. "Trying to conceive" blocks Fat loss only, and Maintenance and Muscle gain stay available:
  - under 18 or over 65;
  - height under 54 in (137 cm) or over 84 in (213 cm);
  - weight over 350 lb (159 kg);
  - BMI under 17;
  - pregnant or breastfeeding;
  - trying to conceive or in fertility treatment (fat loss only);
  - transgender and on hormone therapy;
  - heart disease, thyroid cancer, PCOS, hyper- or hypothyroidism;
  - weight-loss surgery in the past 5 years;
  - gallbladder disease;
  - autoimmune disease;
  - type 1 or type 2 diabetes;
  - a history of eating disorders.
- **SAFE-002** [D] The screening screen must tell the user to get medical clearance before starting any diet.
- **SAFE-003** [I][G] A user who fails screening must still be able to use all training features, with nutrition targets turned off (training-only mode). The app must explain why.
- **SAFE-004** [G] Users in training-only mode must still be able to log weigh-ins and see trend weight.
- **SAFE-005** [D] Fat-loss rate must never exceed 1.0% of bodyweight per week, whatever the user enters.
- **SAFE-006** [D] Fat-loss phases must not be set longer than 16 weeks.
- **SAFE-007** [D] Muscle-gain phases must not be set longer than 16 weeks.
- **SAFE-008** [D] Calorie reductions must come from fat and carbohydrate only. Protein targets must never drop during a deficit.
- **SAFE-009** [D] Daily fat must never fall below 15% of daily calories.
- **SAFE-010** [D] Every meal must keep at least 5 g fat and 5 g carbohydrate.
- **SAFE-011** [D] If trend weight puts the user's BMI at 17 or below, the app must stop all further calorie cuts and recommend raising calories to maintenance.
- **SAFE-012** [D] If a calorie cut would break SAFE-009 or SAFE-010, the app must refuse it and recommend raising calories or ending the phase.
- **SAFE-013** [D][G] The app must ask during setup whether the user takes a GLP-1 or similar weight-loss drug (semaglutide, tirzepatide, retatrutide). If yes:
  - the protein target must be shown first on every meal;
  - if the consumed average stays under 80% of target for 2 weeks running, the weekly review must warn about losing muscle and recommend eating to target;
  - the fat-loss rate cap stays at 1.0% per week.
- **SAFE-014** [D] The app must state that it does not support medical diets and that users with metabolic conditions should consult a physician.
- **SAFE-015** [M] The app must warn, and not block, when a user sets a daily step target above 12,000 during a fat-loss phase. The app's own recommendations never go above 10,000 (REV-027). The warning must say that very high step counts can hurt recovery from lifting.
- **SAFE-016** [G] Training feedback of "A lot of pain" on any exercise must show a note to stop the exercise if the pain is sharp, and to see a professional if it persists.
- **SAFE-017** [G] Settings must have "Re-check eligibility", which re-runs the screening. A training-only user who now passes must go through the skipped nutrition steps (ONB-005). Their targets start from the formula (NUT-002). A nutrition user who now fails (for example, pregnant) must move to training-only mode immediately, with the reason shown.
- **SAFE-018** [D][G] When a planned or checked-in day would fall below the fat floor (SAFE-009) or the per-meal minimums (SAFE-010), the day must show a nutrient warning naming the macro and the fix.
