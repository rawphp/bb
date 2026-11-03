<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;

// agent:eval (AGT-050) runs tests/Agent through Pest and reads its JUnit log. Pest is faked here: it writes the log a
// run of five requests would: two passed, one failed, one errored and one skipped.

const EVAL_RESULT = 'tests/Agent/results/2031-01-02.json';

beforeEach(function () {
    Carbon::setTestNow('2031-01-02 10:00:00');
    // The app's .env, as a developer's checkout has one.
    $this->envFile = [app()->environmentPath(), app()->environmentFile()];
    app()->useEnvironmentPath(base_path('tests/fixtures/agent-eval'))->loadEnvironmentFrom('app.env');
    config(['capabilities-ai.llm.anthropic.api_key' => 'live-key', 'capabilities-ai.llm.anthropic.model' => 'claude-opus-5-5']);
    Process::fake(function (PendingProcess $process) {
        $command = (array) $process->command;
        file_put_contents($command[array_search('--log-junit', $command, true) + 1], <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="Agent" tests="5" failures="1" errors="1" skipped="1">
                <testsuite name="Tests\Agent\TrainingTest" tests="5" failures="1" errors="1" skipped="1">
                  <testcase name="it AGT-050 &quot;move tomorrow's workout to Saturday&quot;" file="tests/Agent/TrainingTest.php"/>
                  <testcase name="it AGT-050 &quot;swap bench for dumbbell press&quot;" file="tests/Agent/TrainingTest.php">
                    <failure type="PHPUnit\Framework\ExpectationFailedException">it AGT-050 "swap bench for dumbbell press"The Coach should call exercise_swap with {"scope":"this_session"}, and the app accept it.
            Its reply: Swapped it, see you at 5:30 PM.
            at 5:30 the gym is busy.
            Failed asserting that null is not null.
            at tests/Agent/Coach.php:128</failure>
                  </testcase>
                  <testcase name="it AGT-050 &quot;undo that&quot;" file="tests/Agent/TrainingTest.php">
                    <error type="RuntimeException">it AGT-050 "undo that"RuntimeException: The Coach made no successful workout_time_set call.
            at tests/Agent/Coach.php:96</error>
                  </testcase>
                  <testcase name="it AGT-050 &quot;add a set of bench&quot;" file="tests/Agent/TrainingTest.php">
                    <skipped/>
                  </testcase>
                  <testcase name="it AGT-050 &quot;start my workout&quot;" file="tests/Agent/TrainingTest.php"/>
                </testsuite>
              </testsuite>
            </testsuites>
            XML);

        return Process::result(output: 'Tests: 1 failed, 1 errored, 1 skipped, 2 passed', exitCode: 1);
    });
});

afterEach(function () {
    Carbon::setTestNow();
    app()->useEnvironmentPath($this->envFile[0])->loadEnvironmentFrom($this->envFile[1]);
    @unlink(base_path(EVAL_RESULT));
});

it('AGT-050 agent:eval --live runs the eval set on the real model and records its pass rate under the date', function () {
    $this->artisan('agent:eval', ['--live' => true])
        ->expectsOutputToContain('2 of 5 requests passed (40.0%)')
        ->assertExitCode(1);

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (array) $process->command;

        return in_array('tests/Agent', $command, true)
            && ($process->environment['AGENT_EVAL_LIVE'] ?? null) === '1'
            && ($process->environment['ANTHROPIC_API_KEY'] ?? null) === 'live-key'
            // The app's own environment stays out, so phpunit.xml's pins hold.
            && ($process->environment['APP_ENV'] ?? null) === false
            && ($process->environment['CAPABILITIES_AI_LLM_DRIVER'] ?? null) === false;
    });
    expect(json_decode((string) file_get_contents(base_path(EVAL_RESULT)), true))->toBe([
        'date' => '2031-01-02',
        'model' => 'claude-opus-5-5',
        'requests' => 5,
        'passed' => 2,
        'pass_rate' => 0.4,
        'cases' => [
            ['name' => 'AGT-050 "move tomorrow\'s workout to Saturday"', 'passed' => true],
            ['name' => 'AGT-050 "swap bench for dumbbell press"', 'passed' => false, 'failure' => "The Coach should call exercise_swap with {\"scope\":\"this_session\"}, and the app accept it.\nIts reply: Swapped it, see you at 5:30 PM.\nat 5:30 the gym is busy.\nFailed asserting that null is not null."],
            ['name' => 'AGT-050 "undo that"', 'passed' => false, 'failure' => 'RuntimeException: The Coach made no successful workout_time_set call.'],
            ['name' => 'AGT-050 "add a set of bench"', 'passed' => false],
            ['name' => 'AGT-050 "start my workout"', 'passed' => true],
        ],
    ]);
});

it('AGT-050 agent:eval with no --live runs the eval set on the fake driver and records nothing', function () {
    $this->artisan('agent:eval')
        ->expectsOutputToContain('2 of 5 requests passed (40.0%)')
        ->assertExitCode(1);

    Process::assertRan(fn (PendingProcess $process): bool => ! isset($process->environment['AGENT_EVAL_LIVE']));
    expect(file_exists(base_path(EVAL_RESULT)))->toBeFalse();
});

it('AGT-050 agent:eval --live with no Anthropic key stops before it runs anything', function () {
    config(['capabilities-ai.llm.anthropic.api_key' => null]);

    $this->artisan('agent:eval', ['--live' => true])
        ->expectsOutputToContain('ANTHROPIC_API_KEY')
        ->assertExitCode(1);

    Process::assertNothingRan();
    expect(file_exists(base_path(EVAL_RESULT)))->toBeFalse();
});

it('AGT-050 agent:eval fails when Pest runs no request', function () {
    Process::fake(fn () => Process::result(errorOutput: 'Pest is not installed', exitCode: 127));

    $this->artisan('agent:eval', ['--live' => true])
        ->expectsOutputToContain('The eval set did not run')
        ->assertExitCode(1);

    expect(file_exists(base_path(EVAL_RESULT)))->toBeFalse();
});
