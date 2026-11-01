# 19. Views

Every rp-clone SwiftUI view gets a Vue counterpart with the same layout, copy and behaviour, reading from a `*_show` capability (CAP-002). rp-clone's screenshots in `~/EA/projects/rp-clone/docs/screenshots/<area>/` and its UX walks (`docs/ux-walks/`) are the visual reference for each one.

## Rules (VIEW)

- **VIEW-001** [B] Each SwiftUI `View` type in the table must become one Vue single-file component with the same name, under the folder shown. Small helpers that only lay out part of one screen (marked "inline") may live inside their parent component.
- **VIEW-002** [B] A Vue view must not port the SwiftUI view model's logic. rp-clone's `RPCore/Features/<Feature>` view models decide things on the device; in Bodybuilder that logic moves to the server behind the screen's capabilities (CAP-001). A view's script holds only display state: which sheet is open, the text in a field, scroll position.
- **VIEW-003** [B] rp-clone's `*DebugSource.swift` files and debug screens are not ported. Their job is done by server seeders and the time-walk clock (architecture.md).
- **VIEW-004** [B] Every design system component must appear in the gallery at `/dev/gallery` (local and testing only), with each state: empty, loading, error, pending (OFF-008) and disabled.
- **VIEW-005** [B] A view is done when it matches its rp-clone screenshot on a 390 × 844 viewport in light and dark mode, and passes PWA-050.

## Layout

```text
resources/js/
  design/                 shared components (13-shared-ui.md) and tokens.css
  app/                    shell: tab bar, stacks, sheets host, toast host, outbox, cache, call()
  features/<feature>/     screens and sheets, one folder per rp-clone feature
```

## Design system

| rp-clone (`ios/App/DesignSystem/`) | Vue (`resources/js/design/`) | Notes |
|---|---|---|
| Tokens+SwiftUI.swift, RPCore ColorToken, Macro, StatusTone | `tokens.css` | CSS custom properties, light and dark (PWA-040) |
| ChoiceList.swift | `ChoiceList.vue` | UI-006 |
| HelpButton.swift | `HelpButton.vue` | UI-009 |
| ItemCard.swift | `ItemCard.vue` | UI-002 |
| ProgressBar.swift | `ProgressBar.vue` | UI-003 |
| SheetScaffold.swift | `BottomSheet.vue` | UI-005, PWA-023 |
| StatusPill.swift | `StatusPill.vue` | UI-004, tone from the server (CAP-018) |
| StepFlow.swift | `StepFlow.vue` | UI-007 |
| Toast.swift | `Toast.vue` | UI-010, Undo calls `undo_apply` |
| ValueRow.swift | `ValueRow.vue` | UI-008 |
| WeekStrip.swift | `WeekStrip.vue` | UI-001 |
| Gallery/GalleryView.swift | `features/dev/GalleryView.vue` | VIEW-004 |
| (new) | `PendingMark.vue`, `OfflineNotice.vue`, `Skeleton.vue` | OFF-008, OFF-003, PWA-024 |

## Shell

| rp-clone | Vue | Notes |
|---|---|---|
| RootView.swift (RootView) | `app/AppShell.vue`, `app/TabBar.vue`, `app/TabStack.vue` | NAV-001, PWA-020 to PWA-022. Debug screens in RootView are dropped (VIEW-003) |
| RPCloneApp.swift | `app/main.ts` | Boot, service worker registration |
| (new) | `app/CoachButton.vue`, `features/coach/CoachChat.vue`, `features/coach/ChangeCard.vue` | AGT-002, AGT-013, AGT-021 |
| (new) | `features/sync/OutboxView.vue` | OFF-010, OFF-011 |
| (new) | `features/account/ActivityView.vue` | CAP-022 |

## Features

| rp-clone file (`ios/App/Features/`) | View types | Vue folder (`resources/js/features/`) | Notes |
|---|---|---|---|
| Account/SignInView.swift | SignInView | `account/` | ACC-001: email and password, or emailed code |
| Account/KeychainTokenStore.swift | | dropped | Cookie session |
| Copy/CopySheet.swift | CopySheet, CopySourceCard, CopyWeekNavigator, CopyMealRowView, CopyWorkoutBlockView, CopyOptionsSheet | `copy/` | |
| CustomFoods/CustomFoodSheet.swift | CustomFoodSheet (OptionalFocus inline) | `custom-foods/` | |
| CustomFoods/CustomFoodsView.swift | CustomFoodsView | `custom-foods/` | |
| Exercises/CustomExerciseSheet.swift | CustomExerciseSheet | `exercises/` | |
| Exercises/ExerciseLibraryView.swift | ExerciseLibraryView | `exercises/` | |
| Exercises/ExerciseView.swift | ExerciseView, ExerciseDetailContent | `exercises/` | YouTube links as in rp-clone architecture |
| Health/AppleHealthSection.swift, AppleHealthStore.swift | AppleHealthSection | `settings/HealthBridgeView.vue` | INT-001, INT-004 |
| Learn/LearnViews.swift | LearnIndexView, ArticleView | `learn/` | |
| Meal/MealView.swift | MealView | `meal/` | UI-021 |
| Meal/MealParts.swift | MealSummaryCard, MealFoodRowView, MealRecipeRowView, MealAmountSheet | `meal/` | |
| Mesocycles/BuildSteps.swift | BuilderStepView, ScratchStepView, SlotsStepView, SlotRowView, DetailsStepView | `mesocycles/build/` | |
| Mesocycles/MesocycleBuildView.swift | MesocycleBuildView (ContinueButton, LabelStylePicker inline) | `mesocycles/build/` | |
| Mesocycles/CurrentMesocycleView.swift | CurrentMesocycleView | `mesocycles/` | |
| Mesocycles/MesocyclesView.swift | MesocyclesView | `mesocycles/` | |
| Mesocycles/MesocycleView.swift | MesocycleView, WeekColumn, DayCell | `mesocycles/` | |
| Mesocycles/PriorityViews.swift | PriorityBarsView, PriorityRowView, WarningLabel | `mesocycles/` | |
| Mesocycles/ProgressionRatesView.swift | ProgressionRatesView | `mesocycles/` | |
| Mesocycles/TemplateLibraryView.swift | TemplateLibraryView, TemplateDetailView | `mesocycles/templates/` | |
| More/MoreView.swift | MoreView | `more/` | SET-020 plus Activity and sync line |
| Notifications/UserNotificationScheduler.swift | | dropped | The server schedules Web Push (NOTIF) |
| Nutrition/NutritionView.swift | NutritionView | `nutrition/` | |
| Onboarding/OnboardingView.swift | OnboardingView, GoalStep, GLP1Step | `onboarding/` | AGT-030 adds the two doors |
| Onboarding/IntroView.swift | IntroView | `onboarding/` | |
| Onboarding/AboutYouStep.swift | AboutYouStep | `onboarding/` | |
| Onboarding/BodyFatStep.swift | BodyFatStep, BucketCard | `onboarding/` | |
| Onboarding/LifestyleSteps.swift | DailyStepsStep, MealsStep, WeighInsStep, CaloriesStep | `onboarding/` | |
| Onboarding/OnboardingParts.swift | OnboardingCallout, YesNoQuestion, ContinueHint, NumberField, WeekdayPicker, TimeOfDayPicker, StepHeading, StepNote | `onboarding/parts/` | WeekdayPicker and TimeOfDayPicker are reused by Settings |
| Onboarding/RateLengthStep.swift | RateLengthStep | `onboarding/` | |
| Onboarding/ReviewStep.swift | ReviewStep, StartingPlanView | `onboarding/` | Also rendered as a chat card (AGT-032) |
| Onboarding/ScreeningStep.swift | ScreeningStep, ScreeningRowView | `onboarding/` | Also opened as a sheet from chat (AGT-031) |
| Onboarding/SetupGateView.swift | | `app/` router guard | Sends users without a plan to onboarding |
| Onboarding/TrainingSteps.swift | ExperienceStep, TrainingDaysStep, TrainingPlanStep | `onboarding/` | |
| Pantry/PantryView.swift, PantryFilterBar.swift, PantryRowView.swift | PantryView, PantryFilterBar, PantryChip, PantryRowView | `pantry/` | |
| Plan/PlanView.swift | PlanView, CurrentPhaseView (PlanDestinations, PlanActions inline) | `plan/` | |
| Plan/PlanTimelineView.swift | PlanTimelineView, DeloadWeekSheet | `plan/` | |
| Plan/AddPhaseView.swift | AddPhaseView | `plan/` | |
| Plan/PhaseDetailView.swift | PhaseDetailView, AdjustLengthSheet, ChangeGoalSheet | `plan/` | |
| Plan/PhaseTransitionView.swift | PhaseTransitionView (PhaseTransitionHost becomes a router guard) | `plan/` | |
| Plan/PrebuiltPlansView.swift | PrebuiltPlansView | `plan/` | |
| Progress/ProgressTabView.swift | ProgressTabView, ProgressScreen | `progress/` | |
| Progress/ProgressCards.swift | ThisWeekCard, ReviewCard, StepsCard, PhaseHistoryCard, MesocycleHistoryCard, ProgressPlaceholderView | `progress/` | |
| Progress/ProgressChartView.swift | ProgressChartView, ProgressSummaryView, ProgressShareCard | `progress/` | Chart drawn from server series. Waist line WT-021. Share PWA-034 |
| Progress/ExerciseHistoryViews.swift | ExerciseListView, ExerciseHistoryView, ExerciseHistoryContent | `progress/` | |
| Recipes/RecipesView.swift | RecipesView | `recipes/` | |
| Recipes/RecipeEditorView.swift | RecipeEditorView | `recipes/` | |
| Recipes/RecipeDraftView.swift | RecipeDraftView | `recipes/` | |
| Recipes/RecipeGenerateView.swift | RecipeGenerateView | `recipes/` | |
| Recipes/RecipeImportView.swift | RecipeImportView | `recipes/` | |
| Recipes/RecipeEnvironment.swift | | dropped | Environment wiring |
| Review/WeeklyReviewView.swift | WeeklyReviewView | `review/` | |
| Review/ReviewSteps.swift | ReviewWeighInsStep, ReviewMealsStep, ReviewTrainingStep, ReviewProgressStep, ReviewGoalStep, ReviewCaloriesStep, ReviewPreviewStep, ReviewMoveSheet (Line, Note inline) | `review/` | |
| SavedMeals/SavedMealsView.swift | SavedMealsView, SavedMealsStatus, SavedMealRowView, SavedMealPickerSheet | `saved-meals/` | |
| Scan/ScanView.swift | ScanView | `scan/` | PWA-031, PWA-032 |
| Scan/ScanParts.swift | ScanFoundCard, ScanNotFoundCard, ScanResultView, ScanRowView, ScanGramsField | `scan/` | |
| Scan/ScanImages.swift | | `scan/useCamera.ts` | Camera capture and image downscale only |
| Settings/SettingsView.swift | SettingsView, SettingsContent, DietFiltersView (DebugSettingsScreen becomes DeveloperSection) | `settings/` | SET-010, SET-011 |
| Settings/ProfileEditView.swift | ProfileEditView | `settings/` | |
| Settings/ScheduleEditView.swift | ScheduleEditView, StepRangeRow | `settings/` | |
| Settings/EligibilityRecheckView.swift | EligibilityRecheckView | `settings/` | |
| Settings/DeleteAccountView.swift | DeleteAccountView | `settings/` | |
| Settings/ClockBinding.swift | | `settings/DeveloperSection.vue` | Time-walk clock |
| (new) | TokensView, CoachSettingsView, NotificationSettingsView | `settings/` | CAP-021, AGT-020, NOTIF-007 |
| Shopping/ShoppingListView.swift | ShoppingListView, ShoppingStatus, ShoppingRowView | `shopping/` | |
| Subscription/PaywallView.swift | PaywallView, PaywallSheet | `billing/` | Billing on only. Opens Stripe Checkout |
| Subscription/LapsedBannerView.swift | LapsedBannerView | `billing/` | Billing on only |
| Subscription/StoreKitSubscriptionStore.swift, SubscriptionStoreEnvironment.swift, SubscriptionDebug.swift | | dropped | Stripe is server-side |
| Today/TodayView.swift | TodayView | `today/` | |
| Today/TodayCards.swift | TodayMealCard, TodayWorkoutCard, TodayOtherActivityCard, TodayWeighInRow, TodayBusyCard, TodayTotalsBar, TodayStepRow (MacroLine inline) | `today/` | TodayStepRow gains manual entry (WT-020) |
| Today/TodayCheckIn.swift | CheckInButton, ManualCheckInSheet | `today/` | |
| Today/TodaySheets.swift | TodayCalendarSheet, TodayWorkoutTimeSheet, TodayAddMealSheet, TodayOptimizeSheet, TodayMoveDaySheet, TodayMissedWeeksSheet, TodayMissedDaySheet, TodayTourOverlay | `today/sheets/` | TodayPlaceholderSheet dropped |
| Today/AddWorkoutSheet.swift | AddWorkoutSheet | `today/sheets/` | |
| Today/AdjustSheet.swift | AdjustSheet | `today/sheets/` | |
| Today/AwayWeekSheet.swift | AwayWeekSheet | `today/sheets/` | |
| Today/OffPlanViews.swift | OffPlanSheet, OffPlanRow, AmountRow | `today/sheets/` | |
| Today/RedistributeSheet.swift | RedistributeSheet, RedistributeRowView, WarningLine | `today/sheets/` | |
| Today/ScheduleSheets.swift | BusyPeriodSheet, EditScheduleSheet, ScheduleSheetMessage, WeekdayChips | `today/sheets/` | |
| Today/WeekdayChangeSheet.swift | WeekdayChangeSheet (modifier becomes a composable) | `today/sheets/` | |
| Today/NextMesocycleCard.swift | NextMesocycleCard | `today/` | |
| Today/RetargetToast.swift | | `design/Toast.vue` | Server sends the toast (CAP-010) |
| Today/TodayEnvironment.swift | | dropped | Environment wiring |
| Train/TrainView.swift | TrainView | `train/` | |
| WeighIns/WeighInsView.swift | WeighInsView, WeighInsScreen | `weigh-ins/` | Steps list added (WT-020) |
| WeighIns/WeighInEntrySheet.swift | WeighInEntrySheet | `weigh-ins/` | Optional waist (WT-021) |
| WeighIns/WeighInScheduleView.swift | WeighInScheduleView, WeighInScheduleEntrySheet | `weigh-ins/` | |
| WeighIns/TodayWeighInCard.swift | TodayWeighInCard | `weigh-ins/` | |
| Workout/WorkoutView.swift | WorkoutView | `workout/` | Wake lock PWA-030 |
| Workout/WorkoutExerciseCard.swift | WorkoutExerciseCard | `workout/` | |
| Workout/WorkoutSetRow.swift | WorkoutSetRow (FieldBox inline) | `workout/` | Rep table lookup CAP-016, pending mark OFF-009 |
| Workout/WorkoutSheets.swift | SetMenuSheet, RIRInfoSheet, WorkoutHelpSheet, LookBackSheet, LookBackDetail, ExercisePickerSheet, SwapSheet, NoteSheet, RelabelSheet, ReorderSheet, RemainingWeeksCheckbox (ActionRow, ReadMore inline) | `workout/sheets/` | |
| Workout/FeedbackSheet.swift | FeedbackSheet (FeedbackSheetHost becomes a composable) | `workout/sheets/` | UI-022 |
| Workout/ActiveWorkoutBar.swift | ActiveWorkoutBar (modifier becomes a shell slot) | `app/` | NAV-004 |

## Rules that move to the server

rp-clone keeps most rules on the device, in `ios/Sources/RPCore/Domain/` (about 9,000 lines of Swift). Each folder becomes a PHP module under `app/Domain/` with the same name, and each Swift test named after a requirement becomes a Pest unit test with the same name before the PHP is written:

DayBalance, Defaults, Energy, Macros, MealFit, MealSplit, Mesocycle, Model, Phases, Placement, Plan, Progress, Progression, Recipe, Retarget, Review, Safety, Shopping, WeekBuilder, Weight.

rp-clone's server already owns a few rules (`app/Plan`, `app/Nutrition`, `app/Training`, `app/Foods`, after its "one authority per rule" refactor). Those are ported as they are. Where a Swift rule and a PHP rule both exist for the same requirement, the PHP one wins, and the gap goes in the milestone plan.
