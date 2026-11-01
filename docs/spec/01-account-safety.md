# 01. Account, subscription, platforms and safety

## Account (ACC)

- **ACC-001** [D][H] Users must be able to sign up and sign in with Apple, Google, or email and password.
- **ACC-002** [I] One account must hold both nutrition and training data. Differs from RP: RP keeps the two apps on separate logins.
- **ACC-003** [D] The account must sync across the user's devices. The More menu must show sync status, for example "Synced a few seconds ago".
- **ACC-004** [G] Users must be able to reset a forgotten password by email.
- **ACC-005** [G] Users must be able to delete their account and all data from Settings, with a typed confirmation.
- **ACC-006** [D] Users must be able to sign out from Settings.

## Subscription (SUB)

- **SUB-001** [I] One subscription must unlock every feature in the app. Differs from RP: RP sells Diet and Hypertrophy separately.
- **SUB-002** [D][H] The app must offer monthly and yearly plans, and a 6-month plan. Prices are a business decision; draft 1 suggests $34.99 per month, $179.99 per 6 months and $299.99 per year [G].
- **SUB-003** [G] New users must get a 7-day free trial, started after onboarding and before the first plan is generated.
- **SUB-004** [D][G] The paywall must appear at the end of onboarding, showing the plan preview behind it, so the user sees what they are paying for.
- **SUB-005** [D][H] Purchases must work through the App Store (StoreKit 2). Subscriptions are managed and refunded through the App Store. Google Play and website purchases are deferred.
- **SUB-006** Deferred, not in v1 (iPhone only). [D][H] Website purchases must carry a 30-day money-back guarantee, limited to one refund per customer.
- **SUB-007** [D] Users must be able to switch plans and cancel from Settings, which opens the App Store's manage-subscription sheet.
- **SUB-008** [G] Users must be able to restore store purchases from Settings.
- **SUB-009** [D] The app must support promo pricing (seasonal sale prices, referral and gym codes) without an app release.
- **SUB-010** [G] When a subscription lapses, the app must keep all data and show it read-only until the user resubscribes.
- **SUB-011** Deferred, not in v1 (iPhone only). [D][G] A website purchase must be matched to an account by the purchase email. When the signed-in email differs, the app must offer to link the purchase to the signed-in account.

## Platforms (PLT)

- **PLT-001** [D][H] The app must run on iPhone, iOS 17.6 or later. v1 is iPhone only. A small Laravel API holds accounts, sync, food search and the AI calls.
- **PLT-002** Deferred, not in v1 (iPhone only). [H] The app must also run in a web browser, installable to the home screen, with full training and planning features.
- **PLT-003** Deferred, not in v1 (iPhone only). [G] The web version must support food logging by search and custom food. Camera scanning (barcode, label, photo) is mobile only.
- **PLT-004** [D] The iPhone app must also run on Apple-silicon Macs and on Apple Vision Pro as a compatible iPhone app.
- **PLT-005** [D] The app must support light and dark mode, following the system setting with a manual override.
- **PLT-006** Deferred, not in v1 (iPhone only). [G] Layouts must scale to iPad and wide browser windows without a separate tablet design. Differs from RP: RP has no iPad layout and users ask for one.

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
