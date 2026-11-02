<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\LearnShowInput;
use App\Capabilities\Reads\Data\LearnShowOutput;
use App\Capabilities\Reads\Learn\LearnLibrary;
use App\Capabilities\Support\HostDefinition;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;

final class LearnShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('learn_show')
            ->description('List the Learn articles on using the app and the methods behind it, grouped into sections, each with its slug, title and one-line summary. Use it to find the article that answers a question about the method, then open it with article_show. It changes nothing.')
            ->cli('learn', 'show')
            ->input(LearnShowInput::class)
            ->output(LearnShowOutput::class)
            ->run(fn (LearnShowInput $input): LearnShowOutput => LearnLibrary::bundled()->index());
    }
}
