<?php

use App\Capabilities\Reads\Learn\LearnLibrary;
use App\Domain\Learn\LearnArticle;
use App\Domain\Learn\LearnSection;
use App\Domain\Learn\LearnTopic;
use App\Domain\Safety\SafetyWarnings;
use App\Domain\Safety\Screening;
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
            'summary' => 'RIR stands for reps in reserve, the number of clean reps you had left at the point you stopped. Your target falls as the weeks pass, which makes the work harder as a block goes on.',
        ]);
});

it('LRN-002 builds the index summaries once and rebuilds them when an article file changes', function () {
    $dir = sys_get_temp_dir().'/learn-'.bin2hex(random_bytes(6));
    File::copyDirectory(resource_path('learn'), $dir);
    $path = "{$dir}/rir.md";
    $summary = fn (): string => (new LearnLibrary($dir))->index()->toArray()['sections'][2]['articles'][1]['summary'];

    try {
        $old = $summary();
        expect($old)->toContain('RIR stands');
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
        expect($old)->toContain('RIR stands');
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

it('SAFE-014 says in Learn that medical diets are not supported and metabolic conditions need a physician', function () {
    expect(learnArticleText(LearnTopic::IsItRightForMe))->toContain('Medical diets are unsupported')
        ->toContain('metabolic condition')->toContain('physician');
});

it('SAFE-001 SAFE-002 lists in Learn every screening answer that needs medical clearance first', function () {
    $text = learnArticleText(LearnTopic::IsItRightForMe);

    expect($text)->toContain('medical clearance')
        ->toContain('younger than '.Screening::MINIMUM_AGE)->toContain('older than '.Screening::MAXIMUM_AGE)
        ->toContain('trying to conceive, which blocks only fat loss');
    foreach (['baby is on the way', 'breastfeeding', 'diabetes', 'PCOS', 'autoimmune', 'gallbladder', 'heart', 'thyroid', 'weight-loss surgery', '5 years', 'hormone therapy', 'eating disorder'] as $item) {
        expect($text)->toContain($item);
    }
});

it('SAFE-005 states the 1.0% weekly fat-loss ceiling and never a faster rate', function () {
    expect(learnArticleText(LearnTopic::ChoosingAGoal))->toContain('1.0% as the ceiling')
        ->and(learnArticleText(LearnTopic::FatLoss))->toContain('between 0.25% and 1%')
        ->and(learnArticleText(LearnTopic::MiniCut))->toContain('from 0.75% to 1%');
});

it('SAFE-015 says when the step target warning shows', function () {
    expect(learnArticleText(LearnTopic::TrainingOnACut))->toContain('above '.number_format(SafetyWarnings::MAX_STEP_TARGET_WITHOUT_WARNING));
});

it('LRN-001 keeps each health article\'s doctor note with every condition it names', function (LearnTopic $topic, array $conditions) {
    $text = strtolower(learnArticleText($topic));

    foreach (['doctor', ...$conditions] as $phrase) {
        expect($text)->toContain($phrase);
    }
})->with([
    'is it right for me' => [LearnTopic::IsItRightForMe, ['what your doctor tells you about the drug']],
    'fat loss' => [LearnTopic::FatLoss, ['unwell', 'pregnant', 'glp-1', 'disordered eating']],
    'mini-cut' => [LearnTopic::MiniCut, ['pregnancy', 'medical condition', 'weight-loss medication']],
    'maintenance' => [LearnTopic::Maintenance, ['pregnancy', 'medical condition', 'weight-loss medication']],
    'muscle gain' => [LearnTopic::MuscleGain, ['medical condition']],
    'body fat' => [LearnTopic::BodyFat, ['pregnancy', 'illness']],
    'alcohol' => [LearnTopic::Alcohol, ['medication', 'health issue']],
    'coffee' => [LearnTopic::Coffee, ['pregnant', 'heart condition', 'medication']],
    'training while sick' => [LearnTopic::TrainingWhileSick, ['whatever your doctor tells you', 'your doctor is the person to ask', 'your doctor has given you specific advice', 'sicker']],
]);

it('FOOD-081 guides on alcohol and coffee', function () {
    expect(learnArticleText(LearnTopic::Alcohol))->toContain('avoid alcohol while dieting')->toContain('1 to 2 drinks a week')
        ->and(learnArticleText(LearnTopic::Coffee))->toContain('keep drinking coffee')->toContain('caffeine is fine');
});

it('FOOD-007 says to measure cooking oil', function () {
    expect(strtolower(learnArticleText(LearnTopic::Oils)))->toContain('measure');
});
