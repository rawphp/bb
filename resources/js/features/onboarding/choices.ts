import type { OnboardingAnswerInput } from '@/contracts/onboarding_answer';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import type { Choice } from '@/design/ChoiceList.vue';

/** Any answers from the steps; a key left out keeps the saved answer. */
export type Answers = NonNullable<OnboardingAnswerInput['answers']>;
export type Move = NonNullable<OnboardingAnswerInput['move']>;
/** One option as `onboarding_show` sends it. */
export type ShowChoice = OnboardingShowOutput['goal']['options'][number];

/** The server's options as ChoiceList rows: its titles, its reasons, its locks, and its Recommended badge as the row's tag. */
export function toChoices<T extends string>(options: ShowChoice[]): Choice<T>[] {
    return options.map((option) => ({
        id: option.value as T,
        title: option.title,
        detail: option.detail || undefined,
        isEnabled: option.is_enabled,
        isRecommended: option.badges.some((badge) => badge.text === 'Recommended'),
    }));
}

/** The option the server marks selected, if any. */
export function picked<T extends string>(options: ShowChoice[]): T | null {
    return (options.find((option) => option.is_selected)?.value as T | undefined) ?? null;
}
