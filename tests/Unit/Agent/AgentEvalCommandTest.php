<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;

// agent:eval (AGT-050) runs tests/Agent through Pest and reads its JUnit log. Pest is faked here: it writes the log a
// run of three requests would, one of them failed.

const EVAL_RESULT = 'tests/Agent/results/2031-01-02.json';

beforeEach(function () {
    Carbon::setTestNow('2031-01-02 10:00:00');
    config(['capabilities-ai.llm.anthropic.api_key' => 'live-key', 'capabilities-ai.llm.anthropic.model' => 'claude-opus-5-5']);
    Process::fake(function (PendingProcess $process) {
        $command = (array) $process->command;
        file_put_contents($command[array_search('--log-junit', $command, true) + 1], <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="Agent" tests="3" failures="1" errors="0">
                <testsuite name="Tests\Agent\TrainingTest" tests="3" failures="1" errors="0">
                  <testcase name="AGT-050 &quot;move tomorrow's workout to Saturday&quot;" file="tests/Agent/TrainingTest.php"/>
                  <testcase name="AGT-050 &quot;swap bench for dumbbell press&quot;" file="tests/Agent/TrainingTest.php">
                    <failure type="PHPUnit\Framework\ExpectationFailedException">The Coach should call exercise_swap with {"scope":"this_session"}, and the app accept it.</failure>
                  </testcase>
                  <testcase name="AGT-050 &quot;undo that&quot;" file="tests/Agent/TrainingTest.php"/>
                </testsuite>
              </testsuite>
            </testsuites>
            XML);

        return Process::result(output: 'Tests: 1 failed, 2 passed', exitCode: 1);
    });
});

afterEach(function () {
    Carbon::setTestNow();
    @unlink(base_path(EVAL_RESULT));
});

it('AGT-050 agent:eval --live runs the eval set on the real model and records its pass rate under the date', function () {
    $this->artisan('agent:eval', ['--live' => true])
        ->expectsOutputToContain('2 of 3 requests passed (66.7%)')
        ->assertExitCode(1);

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (array) $process->command;

        return in_array('tests/Agent', $command, true)
            && ($process->environment['AGENT_EVAL_LIVE'] ?? null) === '1'
            && ($process->environment['ANTHROPIC_API_KEY'] ?? null) === 'live-key';
    });
    expect(json_decode((string) file_get_contents(base_path(EVAL_RESULT)), true))->toBe([
        'date' => '2031-01-02',
        'model' => 'claude-opus-5-5',
        'requests' => 3,
        'passed' => 2,
        'pass_rate' => 0.667,
        'cases' => [
            ['name' => 'AGT-050 "move tomorrow\'s workout to Saturday"', 'passed' => true],
            ['name' => 'AGT-050 "swap bench for dumbbell press"', 'passed' => false, 'failure' => 'The Coach should call exercise_swap with {"scope":"this_session"}, and the app accept it.'],
            ['name' => 'AGT-050 "undo that"', 'passed' => true],
        ],
    ]);
});

it('AGT-050 agent:eval with no --live runs the eval set on the fake driver and records nothing', function () {
    $this->artisan('agent:eval')
        ->expectsOutputToContain('2 of 3 requests passed (66.7%)')
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
