<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Rawphp\Capabilities\Registry\CapabilityRegistry;

// The HTTP seam of learn_show and article_show and their screen fixtures.
// The rules are unit tests (LearnTopicTest, LearnArticleTest, LearnLibraryTest).
// Articles belong to no user, so an unknown slug stands in for another user's ID.

beforeEach(fn () => Carbon::setTestNow('2026-10-07 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('LRN-001 refuses learn_show and article_show when signed out', function () {
    $this->postJson('/capabilities/learn_show')->assertUnauthorized();
    $this->postJson('/capabilities/article_show', ['slug' => 'rir'])->assertUnauthorized();
});

it('LRN-001 shows the Learn index', function () {
    $data = $this->actingAs(User::factory()->create())->postJson('/capabilities/learn_show')->assertOk()->json('data');

    assertMatchesScreenFixture('learn_show', $data);
    expect(array_column($data['sections'], 'title'))->toBe(['Using the app', 'Diet', 'Training', 'Training and diet together']);
});

it('LRN-001 shows an article by its slug', function () {
    $data = $this->actingAs(User::factory()->create())->postJson('/capabilities/article_show', ['slug' => 'rir'])->assertOk()->json('data');

    assertMatchesScreenFixture('article_show', $data);
    expect($data['title'])->toBe('Reps in reserve (RIR)');
});

it('LRN-001 answers 404 for an article that does not exist', function () {
    $this->actingAs(User::factory()->create())->postJson('/capabilities/article_show', ['slug' => 'noSuchArticle'])->assertNotFound();
});

it('LRN-001 answers 422 naming an empty slug', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/article_show', ['slug' => ''])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'slug');
});

it('CAP-003 CAP-005 learn_show and article_show are reads on every surface, with a CLI verb and a two or three sentence description', function () {
    $registry = app(CapabilityRegistry::class);

    foreach (['learn_show' => ['learn', 'show'], 'article_show' => ['article', 'show']] as $name => $cli) {
        $definition = $registry->get($name);

        expect($definition->readOnly)->toBeTrue()
            ->and($definition->surfaces)->toBe(HostDefinition::SURFACES)
            ->and([$definition->cliDomain, $definition->cliVerb])->toBe($cli)
            ->and(preg_split('/(?<=[.!?])\s+/', $definition->description))->toHaveCount(3);
    }
});
