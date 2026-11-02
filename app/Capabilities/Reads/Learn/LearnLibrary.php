<?php

namespace App\Capabilities\Reads\Learn;

use App\Capabilities\Reads\Data\ArticleShowOutput;
use App\Capabilities\Reads\Data\LearnShowOutput;
use App\Capabilities\Reads\Data\Settings\ArticleBlock;
use App\Capabilities\Reads\Data\Settings\ArticleSpan;
use App\Capabilities\Reads\Data\Settings\LearnArticleRow;
use App\Capabilities\Reads\Data\Settings\LearnSection as LearnSectionData;
use App\Domain\Learn\LearnArticle;
use App\Domain\Learn\LearnSection;
use App\Domain\Learn\LearnTopic;
use RuntimeException;

/** The Learn articles bundled in resources/learn, one Markdown file per {@see LearnTopic}, and the payloads built from them. */
final readonly class LearnLibrary
{
    public function __construct(private string $directory) {}

    public static function bundled(): self
    {
        return new self(resource_path('learn'));
    }

    public function source(LearnTopic $topic): string
    {
        $path = "{$this->directory}/{$topic->value}.md";
        $source = is_file($path) ? file_get_contents($path) : false;

        return $source === false ? throw new RuntimeException("No article for {$topic->value}.") : $source;
    }

    public function article(LearnTopic $topic): LearnArticle
    {
        return LearnArticle::parse($this->source($topic));
    }

    /** @return list<string> every article file, by name without `.md` */
    public function slugsOnDisk(): array
    {
        return array_map(fn (string $path): string => basename($path, '.md'), glob("{$this->directory}/*.md") ?: []);
    }

    /** learn_show: each section in order, each article with its title and summary (LRN-001, LRN-002). */
    public function index(): LearnShowOutput
    {
        return new LearnShowOutput(array_map(fn (LearnSection $section): LearnSectionData => new LearnSectionData(
            title: $section->title(),
            articles: array_map(fn (LearnTopic $topic): LearnArticleRow => new LearnArticleRow(
                slug: $topic->value,
                title: $topic->title(),
                summary: $this->article($topic)->summary(),
            ), $section->topics()),
        ), LearnSection::cases()));
    }

    /** article_show: one article in blocks of styled spans (LRN-001). */
    public function show(LearnTopic $topic): ArticleShowOutput
    {
        return new ArticleShowOutput(
            slug: $topic->value,
            title: $topic->title(),
            blocks: array_map(fn (array $block): ArticleBlock => new ArticleBlock(
                kind: $block['kind'],
                spans: array_map(fn (array $span): ArticleSpan => new ArticleSpan($span['text'], $span['bold'], $span['italic']), $block['spans']),
            ), $this->article($topic)->blocks),
        );
    }
}
