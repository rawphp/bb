<?php

use App\Capabilities\Support\HostDefinition;
use App\Domain\Model\MuscleGroup;
use App\Enums\PhaseFit;
use App\Enums\Split;
use App\Models\Template;
use App\Models\TemplateSlot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\ScreenFixture;
use Tests\Support\TrainSeed;

// The HTTP seam of templates_show and its screen fixtures. The rules are unit tests (TemplatesPayloadTest).

afterEach(fn () => Carbon::setTestNow());

function libraryWorld(): User
{
    Carbon::setTestNow('2026-10-07 10:00:00');
    $user = TrainSeed::user();
    TrainSeed::phase($user, 'muscleGain', '2026-08-31');

    $full = Template::factory()->days(2)->create(['name' => 'Full Body 2-Day Essentials', 'phase_fit' => [PhaseFit::Gain], 'day_labels' => ['Full Body A', 'Full Body B'], 'priorities' => ['chest' => 'grow', 'hamstrings' => 'maintain']]);
    TemplateSlot::factory()->for($full)->create(['day' => 1, 'position' => 1, 'muscle_group' => 'chest', 'exercise_id' => TrainSeed::exercise('Barbell Bench Press', MuscleGroup::Chest)->id, 'sets' => 4]);
    TemplateSlot::factory()->for($full)->create(['day' => 2, 'position' => 1, 'muscle_group' => 'quads', 'exercise_id' => TrainSeed::exercise('Leg Press', MuscleGroup::Quads)->id, 'sets' => 3]);
    Template::factory()->days(3)->create(['name' => 'Arm Specialisation 3-Day', 'split' => Split::Specialisation, 'phase_fit' => [PhaseFit::Gain]]);
    Template::factory()->days(4)->create(['name' => 'Cut Upper Lower', 'split' => Split::UpperLower, 'phase_fit' => [PhaseFit::Cut]]);

    return $user;
}

it('MESO-010 refuses templates_show when signed out', function () {
    $this->postJson('/capabilities/templates_show', [])->assertUnauthorized();
});

it('MESO-012 answers 404 for a template that does not exist', function () {
    $this->actingAs(libraryWorld())->postJson('/capabilities/templates_show', ['template_id' => '999999'])->assertNotFound();
});

it('MESO-011 answers 422 naming a filter outside its options', function () {
    $this->actingAs(libraryWorld())
        ->postJson('/capabilities/templates_show', ['days' => '7'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'days');
});

it('CAP-005 templates_show is a read on every surface with a CLI verb', function () {
    $definition = app(CapabilityRegistry::class)->get('templates_show');

    expect($definition->readOnly)->toBeTrue()
        ->and($definition->surfaces)->toBe(HostDefinition::SURFACES)
        ->and([$definition->cliDomain, $definition->cliVerb])->toBe(['template', 'list']);
});

it('MESO-010 MESO-011 MESO-012 MESO-015 shows the library on the phase in force, with a template\'s detail', function () {
    $user = libraryWorld();
    $id = (string) Template::query()->where('name', 'Full Body 2-Day Essentials')->value('id');

    $data = $this->actingAs($user)->postJson('/capabilities/templates_show', ['template_id' => $id])->assertOk()->json('data');

    assertMatchesScreenFixture('templates_show', $data);
    expect(array_column($data['rows'], 'name'))->toBe(['Full Body 2-Day Essentials', 'Arm Specialisation 3-Day'])
        ->and($data['filters'][3]['label'])->toBe('Phase: Gain')
        ->and($data['detail']['days'][0]['slots'])->toBe([['exercise_name' => 'Barbell Bench Press', 'muscle_title' => 'Chest', 'sets_text' => '4 sets']])
        ->and(array_column($data['detail']['priorities'], 'muscle'))->toBe(['chest', 'hamstrings']);
});

it('MESO-011 says so when no template matches', function () {
    $data = $this->actingAs(libraryWorld())->postJson('/capabilities/templates_show', ['days' => '6', 'phase' => 'cut'])->assertOk()->json('data');

    assertMatchesScreenFixture('templates_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('templates_show.empty'), $data))->toBe([]);
    expect($data['rows'])->toBe([])
        ->and($data['empty_text'])->toBe('No templates match these filters.')
        ->and($data['detail'])->toBeNull();
});
