<?php

namespace App\Capabilities\Reads\Onboarding;

use App\Capabilities\Onboarding\Data\OnboardingReviewBlock;
use App\Capabilities\Onboarding\Data\OnboardingReviewRow;
use App\Capabilities\Onboarding\Data\OnboardingReviewSection;
use App\Capabilities\Onboarding\Data\OnboardingStatus;
use App\Domain\Onboarding\OnboardingReview;
use App\Domain\Onboarding\ReviewBlock;
use App\Domain\Onboarding\ReviewRow;

/** The review of onboarding_show (ONB-018 to ONB-020). */
final class OnboardingReviewPayload
{
    public const STARTING_TITLE = 'Building your plan';

    public const STARTING_TEXT = 'Working out your targets and saving your plan.';

    public static function build(OnboardingReview $review): OnboardingReviewSection
    {
        return new OnboardingReviewSection(
            sections: array_map(fn (ReviewBlock $block) => new OnboardingReviewBlock(
                title: $block->title, can_edit: $block->canEdit, edit_step: $block->editStep,
                rows: array_map(fn (ReviewRow $row) => new OnboardingReviewRow(
                    label: $row->label, value: $row->value, badges: array_map(self::badge(...), $row->badges),
                ), $block->rows),
            ), $review->blocks()),
            note: $review->note(),
            starting_title: self::STARTING_TITLE,
            starting_text: self::STARTING_TEXT,
        );
    }

    /** "Recommended" is good news; "Selected" is information. */
    private static function badge(string $text): OnboardingStatus
    {
        return OnboardingParts::status($text, $text === 'Recommended' ? 'good' : 'info');
    }
}
