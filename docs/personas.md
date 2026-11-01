# Personas

Status: draft 1, 2026-10-11. There's no user research for this app yet. These personas come from the spec in [spec/](spec/), its [G] best-guess decisions, and the RP review notes in `~/EA/research/rp-apps`. Treat them as hypotheses until they're tested with real users.

## Who we build for

### 1. The hand-syncer (primary)

An intermediate lifter, 1 to 4 years in, aged 25 to 40. They already pay for RP Diet Coach and RP Hypertrophy, or have tried both, and they know MEV, RIR and diet breaks from Dr. Mike's videos.

**Job.** Run a diet phase and the training blocks inside it as one plan, week after week, without copying data between two apps.

**Pains.** They pick the phase twice and type workout times into the diet app by hand. They forget to eat at maintenance during a deload. They pay about $55 a month for two subscriptions.

**Gains.** A deload that turns into a diet break by itself (PLAN-022). Meals that move when the workout moves (SCH-050). A review that asks about diet fatigue before it cuts volume (REV-041).

**Insight.** They will check every number against RP. Each [G] decision, like units stored in kg or history kept per exercise, will win them over or earn a "this is wrong" review. Matching RP's numbers matters more to them than the price.

**Gap.** The spec has no import from RP. They'll start with an empty exercise history, and calories come from the formula for the first 3 weeks.

### 2. The guided beginner

Under a year of consistent lifting. They want to lose fat and build muscle at the same time, and they want to be told what to eat and what to lift.

**Job.** Follow a plan that's right without having to understand why.

**Pains.** They can't judge the pump and soreness questions yet, which is a known RP complaint. They face 13 setup steps before the app shows them anything. $34.99 a month is a lot for a habit they might drop.

**Gains.** A preselected template plus "pick for me" (MESO-013). Help text that steers them to 2 to 4 training days (ONB-011). A clear answer on recomp: pick Fat loss (ONB-003).

**Insight.** Bad programming won't lose them. A 5-meal plan with weighed food that doesn't fit their life will, around week 2. For this persona, making the plan easy to stick to matters more than getting the maths perfect.

**Gap.** If they rarely feel sore, the feedback answers can push volume too high. Logging food is built around meal prep.

### 3. The advanced physique athlete

More than 4 years of lifting. They often have a deadline, like a show, a photo shoot or a wedding. They run mini-cuts between gaining phases and specialise muscles.

**Job.** Reach a weight or leanness target by a date without losing muscle.

**Pains.** Apps that block their choices. Weight jumps too big for small lifts. Calorie recommendations they disagree with.

**Gains.** An event countdown and an end-date check (PH-042, PH-043). Mini-cuts with the 2:1 gain-to-cut ratio warning (PH-038). Choosing their own calories (ONB-017). Micro plates and custom load steps (EX-012).

**Insight.** They'll ignore the app's recommendation often. Warning instead of blocking is the right call. The weekly review also has to keep working after they've gone their own way, without nagging.

**Gap.** Long-term strength charts and offline logging are out of scope, and RP users ask for both.

### 4. The busy-schedule lifter

A shift worker, a parent, someone who travels a lot, or someone with a home gym. Training times change from week to week.

**Job.** Keep the plan on track when the week falls apart.

**Pains.** The most common complaint in RP Diet reviews is "any deviation breaks the app." They also deal with night shifts, eating windows that run past midnight, missed sessions and travel weeks.

**Gains.** Meals re-planned with an Undo toast (SCH-050 to SCH-056). Eating windows that can cross midnight (SCH-045). Away weeks that don't skew the fatigue or calorie maths (SCH-060, SCH-061). A next-morning prompt after a missed day (SCH-057).

**Insight.** They judge the app on bad days, not good ones. The skip, move and away flows need as much testing as the normal path.

**Gap.** No offline logging. The app never asks about equipment, so home-gym users have to pick suitable exercises themselves (EX-011).

## Users created by the spec's own rules

### 5. The training-only user

Someone who fails health screening (SAFE-001): diabetes, PCOS, thyroid disease, pregnancy, a history of eating disorders, over 65, or a BMI under 17, among others.

**Job.** Train with progression and track weight, with no nutrition targets.

**Pains.** Feeling turned away. Paying full price for half the app. Not knowing they can come back later.

**Gains.** Every training feature, plus weigh-ins and trend weight (SAFE-003, SAFE-004). A "Re-check eligibility" option (SAFE-017).

**Insight.** For some people this is temporary: a pregnant user can come back after the birth. That makes re-check a reason to stay, not just a compliance step. But someone with an eating-disorder history must never be nudged back toward diet setup.

**Open decision.** Does training-only cost the same?

### 6. The GLP-1 user

Someone on semaglutide, tirzepatide or retatrutide, with very little appetite (SAFE-013).

**Job.** Lose fat on the drug and keep their muscle.

**Pains.** They can't eat enough protein. Most apps treat eating under target as a win. They can lose weight faster than is safe.

**Gains.** Protein shown first on every meal. A warning when intake stays under 80% of target for 2 weeks. A loss-rate cap of 1% of bodyweight per week.

**Insight.** Their problem is the opposite of everyone else's. Eating too little can look like good adherence. Make sure the green day dots (SCH-021) and the review wording never reward undereating.

## Lifecycle states

These aren't new people, but the app behaves differently for each one, so each needs designing:

- **Trial user.** 7 days, starting after onboarding, with the plan preview showing behind the paywall (SUB-003, SUB-004).
- **Pre-start user.** The plan starts tomorrow. They can plan meals and look at workouts ahead of time (ONB-031).
- **Post-cut maintainer.** Coming off fat loss into resensitization training and a slow calorie increase (PH-031 to PH-044). Churn may peak here, because the goal has just been reached.
- **Lapsed subscriber.** Can read everything; every write returns 402 (SUB-010).
- **Offer-code redeemer outside the app.** A known gap in the [README](../README.md). They stay `none` until a later notification can be matched to their account.

## People who use the system but aren't customers

- **App Store reviewer.** Makes sandbox purchases against production. The API already covers this by not blocking `none`. They also have to get through screening and 13 setup steps, so a demo account in the review notes is cheap insurance against a rejection.
- **The operator.** There's no admin panel, and refunds and offers live in App Store Connect. Two questions are still open: who curates the "verified" food list (FOOD-002), and who handles support when someone can't delete their own account.

## Not users in v1

Coaches and their clients (the portal is out of scope), Android and web users, anyone under 18, and anyone wanting a medical, keto or very low-fat diet. The 15% fat floor rules those diets out (SAFE-009).
