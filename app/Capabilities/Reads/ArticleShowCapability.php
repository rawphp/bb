<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\ArticleShowInput;
use App\Capabilities\Reads\Data\ArticleShowOutput;
use App\Capabilities\Reads\Learn\LearnLibrary;
use App\Capabilities\Support\HostDefinition;
use App\Domain\Learn\LearnTopic;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;

final class ArticleShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('article_show')
            ->description('Show one Learn article by its slug from learn_show, as paragraphs, headings and bullets with bold and italic text. Use it to answer a question about the method from the article and to link to it. It changes nothing.')
            ->cli('article', 'show')
            ->input(ArticleShowInput::class)
            ->output(ArticleShowOutput::class)
            ->run(function (ArticleShowInput $input): ArticleShowOutput {
                // The seam answers an unknown slug with 404, as it does a missing row.
                $topic = LearnTopic::tryFrom($input->slug) ?? throw new ModelNotFoundException("No article \"{$input->slug}\".");

                return LearnLibrary::bundled()->show($topic);
            });
    }
}
