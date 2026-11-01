<?php

use App\Domain\Model\JointPain;
use App\Domain\Model\Pump;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;

/**
 * @param  list<JointPain|Soreness|Pump|VolumeFeedback>  $cases
 * @return list<string>
 */
function feedbackLabels(array $cases): array
{
    return array_map(fn (JointPain|Soreness|Pump|VolumeFeedback $case) => $case->label(), $cases);
}

it('FB-002 lists the joint pain options in order', function () {
    expect(feedbackLabels(JointPain::cases()))->toBe(['None', 'Low pain', 'Moderate pain', 'A lot of pain']);
});

it('FB-003 lists the soreness options in order', function () {
    expect(feedbackLabels(Soreness::cases()))
        ->toBe(['Never got sore', 'Healed a while ago', 'Healed just on time', "I'm still sore!"]);
});

it('FB-004 lists the pump options in order', function () {
    expect(feedbackLabels(Pump::cases()))->toBe(['Low pump', 'Moderate pump', 'Amazing pump']);
});

it('FB-005 lists the volume options in order', function () {
    expect(feedbackLabels(VolumeFeedback::cases()))->toBe(['Not enough', 'Just right', 'Pushed my limits', 'Too much']);
});
