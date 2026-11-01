<?php

namespace Tests\Support\Fixtures\Rail;

use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\WriteResult;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;

/** A write for rail tests: adds to a counter and says how often it ran. */
final class TallyAddCapability implements HostCapability
{
    public static int $total = 0;

    public static int $runs = 0;

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('tally_add', affects: ['tally_show'])
            ->description('Add to the test tally. Used by rail tests only.')
            ->cli('tally', 'add')
            ->input(TallyInput::class)
            ->run(function (TallyInput $input): WriteResult {
                self::$runs++;
                self::$total += $input->amount;

                return new WriteResult(
                    invalidates: [ReadKey::for('tally_show')],
                    toast: 'Tally is '.self::$total,
                );
            });
    }
}
