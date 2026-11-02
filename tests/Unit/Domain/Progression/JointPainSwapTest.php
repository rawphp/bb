<?php

use App\Domain\Model\JointPain;
use App\Domain\Progression\JointPainSwap;

it('EX-009 two Moderate or A lot answers running suggest a swap', function () {
    expect(JointPainSwap::isSuggested([JointPain::Moderate, JointPain::Moderate]))->toBeTrue()
        ->and(JointPainSwap::isSuggested([JointPain::ALot, JointPain::ALot]))->toBeTrue()
        ->and(JointPainSwap::isSuggested([JointPain::Moderate, JointPain::ALot]))->toBeTrue()
        ->and(JointPainSwap::isSuggested([JointPain::ALot, JointPain::Moderate]))->toBeTrue();
});

it('EX-009 one answer is not enough', function () {
    expect(JointPainSwap::isSuggested([]))->toBeFalse()
        ->and(JointPainSwap::isSuggested([JointPain::ALot]))->toBeFalse();
});

it('EX-009 a Low or None answer breaks the run', function () {
    expect(JointPainSwap::isSuggested([JointPain::Moderate, JointPain::Low]))->toBeFalse()
        ->and(JointPainSwap::isSuggested([JointPain::ALot, JointPain::None]))->toBeFalse()
        ->and(JointPainSwap::isSuggested([JointPain::Low, JointPain::Moderate]))->toBeFalse();
});

it('EX-009 only the latest two answers count, oldest first', function () {
    expect(JointPainSwap::isSuggested([JointPain::None, JointPain::Low, JointPain::Moderate, JointPain::ALot]))->toBeTrue()
        ->and(JointPainSwap::isSuggested([JointPain::ALot, JointPain::ALot, JointPain::None, JointPain::Moderate]))->toBeFalse()
        ->and(JointPainSwap::isSuggested([JointPain::Moderate, JointPain::ALot, JointPain::Low]))->toBeFalse();
});

it('EX-009 the suggestion names the exercise', function () {
    expect(JointPainSwap::suggestion('Bench Press'))->toBe('Bench Press hurt 2 sessions running. Consider swapping it.');
});
