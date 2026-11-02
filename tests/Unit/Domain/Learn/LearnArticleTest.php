<?php

use App\Domain\Learn\LearnArticle;

// The Markdown subset an article may use, ported from rp-clone's LearnArticle.

/** @return list<string> */
function learnPlainText(LearnArticle $article): array
{
    return array_map(fn (array $block) => implode('', array_column($block['spans'], 'text')), $article->blocks);
}

it('LRN-001 parses paragraphs, headings and bullets, with the first paragraph as the summary', function () {
    $article = LearnArticle::parse(<<<'MD'
        Weigh in **every**
        morning.

        ## How

        - Use the toilet first
        - Same scale

        Then *log* it.
        MD);

    expect($article->summary())->toBe('Weigh in every morning.')
        ->and(array_column($article->blocks, 'kind'))->toBe(['paragraph', 'heading', 'bullet', 'bullet', 'paragraph'])
        ->and(learnPlainText($article))->toBe(['Weigh in every morning.', 'How', 'Use the toilet first', 'Same scale', 'Then log it.']);
});

it('LRN-001 ends a paragraph at the bullet or heading on the next line, keeping the article in order', function () {
    $article = LearnArticle::parse("Intro.\n\nPick one:\n- A\n## Next\nText");

    expect(array_column($article->blocks, 'kind'))->toBe(['paragraph', 'paragraph', 'bullet', 'heading', 'paragraph'])
        ->and(learnPlainText($article))->toBe(['Intro.', 'Pick one:', 'A', 'Next', 'Text']);
});

it('LRN-001 splits a line into bold and italic spans, keeping the spaces between them', function () {
    $article = LearnArticle::parse("Intro.\n\n- **0 RIR:** you hit your limit. *No more clean reps.*\n- ***Both*** at once");

    expect($article->blocks[1]['spans'])->toBe([
        ['text' => '0 RIR:', 'bold' => true, 'italic' => false],
        ['text' => ' you hit your limit. ', 'bold' => false, 'italic' => false],
        ['text' => 'No more clean reps.', 'bold' => false, 'italic' => true],
    ])->and($article->blocks[2]['spans'])->toBe([
        ['text' => 'Both', 'bold' => true, 'italic' => true],
        ['text' => ' at once', 'bold' => false, 'italic' => false],
    ]);
});

it('LRN-001 refuses Markdown the article view cannot show', function (string $markdown) {
    expect(fn () => LearnArticle::parse($markdown))->toThrow(InvalidArgumentException::class);
})->with([
    'a title' => "# Title\n\nText",
    'a table' => "Intro\n\n| a | b |",
    'an image' => "Intro\n\n![x](y.png)",
    'a numbered list' => "Intro\n\n1. One",
    'a star bullet' => "Intro\n\n* star",
    'a quote' => "Intro\n\n> quoted",
    'a code fence' => "Intro\n\n```\ncode\n```",
    'a wrapped bullet' => "Intro\n\n- One\n  wrapped",
    'a nested bullet' => "Intro\n\n- One\n  - nested",
    'a heading first' => "## Heading first\n\nText",
    'a bullet first' => "- Bullet first\n\nText",
    'unclosed bold' => 'Intro with **bold',
    'unclosed italics' => 'Intro with *italics',
    'inline code' => 'Run `this` first',
    'an empty article' => "\n\n",
]);

it('LRN-004 keeps videos and links out of an article: it is text only', function (string $markdown) {
    expect(fn () => LearnArticle::parse($markdown))->toThrow(InvalidArgumentException::class);
})->with([
    'a link' => 'Watch [the squat](https://example.com/squat) first.',
    'an embedded video' => "Intro\n\n![Squat technique](squat.mp4)",
]);
