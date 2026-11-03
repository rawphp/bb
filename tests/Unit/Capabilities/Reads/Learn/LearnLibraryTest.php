<?php

use App\Capabilities\Reads\Learn\LearnLibrary;
use App\Domain\Learn\LearnArticle;
use App\Domain\Learn\LearnSection;
use App\Domain\Learn\LearnTopic;
use Illuminate\Support\Facades\File;

// The bundled articles in resources/learn and the payloads built from them.
// The articles are the content under test, so these read the real files.

function learnArticleText(LearnTopic $topic): string
{
    $article = LearnLibrary::bundled()->article($topic);

    return implode("\n", array_map(fn (array $block) => implode('', array_column($block['spans'], 'text')), $article->blocks));
}

it('LRN-001 bundles exactly one article per topic', function () {
    expect(LearnLibrary::bundled()->slugsOnDisk())
        ->toEqualCanonicalizing(array_map(fn (LearnTopic $topic) => $topic->value, LearnTopic::cases()));
});

it('LRN-001 parses every article, each opening with a summary and no empty block', function () {
    foreach (LearnTopic::cases() as $topic) {
        $article = LearnLibrary::bundled()->article($topic);

        expect($article->blocks)->not->toBe([], $topic->value)
            ->and($article->summary())->not->toBe('', "{$topic->value} needs a summary paragraph first");
        foreach ($article->blocks as $block) {
            expect(trim(implode('', array_column($block['spans'], 'text'))))->not->toBe('', $topic->value);
        }
    }
});

it('LRN-001 keeps every article short and free of the source program\'s names', function () {
    foreach (LearnTopic::cases() as $topic) {
        $source = LearnLibrary::bundled()->source($topic);

        expect(count(preg_split('/\s+/', trim($source)) ?: []))->toBeLessThan(400, "{$topic->value} is too long");
        foreach (['RP ', "RP's", 'Renaissance', 'Dr. Mike', 'Israetel'] as $banned) {
            expect($source)->not->toContain($banned);
        }
    }
});

it('LRN-001 LRN-002 builds the Learn index: each section in order, each article with its title and summary', function () {
    $sections = LearnLibrary::bundled()->index()->toArray()['sections'];

    expect(array_column($sections, 'title'))->toBe(array_map(fn (LearnSection $section) => $section->title(), LearnSection::cases()))
        ->and(array_column($sections[3]['articles'], 'slug'))->toBe(['trainingOnACut', 'deloadsAtMaintenance', 'maintenanceAndResensitization', 'carbsAroundWorkouts', 'liftsStallInADiet'])
        ->and($sections[2]['articles'][1])->toBe([
            'slug' => 'rir',
            'title' => 'Reps in reserve (RIR)',
            'summary' => 'RIR stands for reps in reserve, meaning how many good reps you had left when a set ended. The target falls over the block, so every week asks slightly more effort than the one before.',
        ]);
});

it('LRN-002 builds the index summaries once and rebuilds them when an article file changes', function () {
    $dir = sys_get_temp_dir().'/learn-'.bin2hex(random_bytes(6));
    File::copyDirectory(resource_path('learn'), $dir);
    $path = "{$dir}/rir.md";
    $summary = fn (): string => (new LearnLibrary($dir))->index()->toArray()['sections'][2]['articles'][1]['summary'];

    try {
        $old = $summary();
        $mtime = filemtime($path);
        file_put_contents($path, str_replace('RIR stands', 'RIR STANDS', file_get_contents($path)));
        touch($path, $mtime);
        clearstatcache();

        expect($summary())->toBe($old);

        touch($path, $mtime + 60);
        clearstatcache();

        expect($summary())->toBe(str_replace('RIR stands', 'RIR STANDS', $old));
    } finally {
        File::deleteDirectory($dir);
    }
});

it('LRN-002 rebuilds the index summaries when the article parser changes', function () {
    $dir = sys_get_temp_dir().'/learn-'.bin2hex(random_bytes(6));
    File::copyDirectory(resource_path('learn'), $dir);
    $path = "{$dir}/rir.md";
    $parser = (new ReflectionClass(LearnArticle::class))->getFileName();
    $parserMtime = filemtime($parser);
    $summary = fn (): string => (new LearnLibrary($dir))->index()->toArray()['sections'][2]['articles'][1]['summary'];

    try {
        $old = $summary();
        $mtime = filemtime($path);
        file_put_contents($path, str_replace('RIR stands', 'RIR STANDS', file_get_contents($path)));
        touch($path, $mtime);
        touch($parser, $parserMtime + 60);
        clearstatcache();

        expect($summary())->toBe(str_replace('RIR stands', 'RIR STANDS', $old));
    } finally {
        touch($parser, $parserMtime);
        File::deleteDirectory($dir);
    }
});

it('LRN-001 shows an article by its slug, in blocks of styled spans', function () {
    $article = LearnLibrary::bundled()->show(LearnTopic::Rir)->toArray();

    expect($article['slug'])->toBe('rir')
        ->and($article['title'])->toBe('Reps in reserve (RIR)')
        ->and($article['blocks'][1])->toBe(['kind' => 'heading', 'spans' => [['text' => 'Reading the scale', 'bold' => false, 'italic' => false]]])
        ->and($article['blocks'][2]['spans'][0])->toBe(['text' => '3 RIR:', 'bold' => true, 'italic' => false]);
});

it('LRN-005 lists the tools you need in the tools article', function () {
    $text = strtolower(learnArticleText(LearnTopic::ToolsYouNeed));

    foreach (['gram food scale', 'bathroom scale', 'gym'] as $tool) {
        expect($text)->toContain($tool);
    }
});

it('LRN-006 tells users to hold off cheat foods for 1 to 4 weeks after a cut ends', function () {
    foreach ([LearnTopic::FatLoss, LearnTopic::MiniCut, LearnTopic::Maintenance] as $topic) {
        expect(learnArticleText($topic))->toContain('1 to 4 weeks');
    }
});

it('FOOD-081 guides on alcohol and coffee', function () {
    expect(learnArticleText(LearnTopic::Alcohol))->toContain('avoid alcohol while dieting')->toContain('1 to 2 drinks a week')
        ->and(learnArticleText(LearnTopic::Coffee))->toContain('coffee and caffeine are fine');
});

it('FOOD-007 says to measure cooking oil', function () {
    expect(strtolower(learnArticleText(LearnTopic::Oils)))->toContain('measure');
});
