<?php

namespace App\Console\Commands;

use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use SimpleXMLElement;

/**
 * Runs the agent eval set (AGT-050), tests/Agent, through Pest. The gate runs it on the fake driver; --live sends
 * each request to the real model and records the pass marks in tests/Agent/results/<date>.json. A live run costs
 * Anthropic tokens and is run before a release, not in the gate. It needs the dev dependencies and the test database.
 */
final class AgentEvalCommand extends Command
{
    protected $signature = 'agent:eval {--live : Send each request to the real model and record the pass rate}';

    protected $description = 'Run the agent eval set (tests/Agent); --live runs it on the real model and records the pass rate';

    public function handle(): int
    {
        $live = (bool) $this->option('live');
        $key = config('capabilities-ai.llm.anthropic.api_key');
        if ($live && blank($key)) {
            $this->error('A live run needs ANTHROPIC_API_KEY.');

            return self::FAILURE;
        }

        $log = (string) tempnam(sys_get_temp_dir(), 'agent-eval');
        try {
            $run = Process::path(base_path())
                ->forever()
                ->env([...$this->withoutDotEnv(), ...($live ? ['AGENT_EVAL_LIVE' => '1', 'ANTHROPIC_API_KEY' => (string) $key, 'CAPABILITIES_AI_ANTHROPIC_MODEL' => (string) config('capabilities-ai.llm.anthropic.model')] : [])])
                ->run([PHP_BINARY, 'vendor/bin/pest', 'tests/Agent', '--log-junit', $log], fn (string $type, string $output) => $this->output->write($output));
            $cases = $this->cases((string) @file_get_contents($log));
        } finally {
            @unlink($log);
        }

        if ($cases === []) {
            $this->error('The eval set did not run.');
            $this->line(trim($run->output()."\n".$run->errorOutput()));

            return self::FAILURE;
        }

        $passed = count(array_filter($cases, fn (array $case): bool => $case['passed']));
        $this->info(sprintf('%d of %d requests passed (%.1f%%)', $passed, count($cases), 100 * $passed / count($cases)));

        if ($live) {
            $path = 'tests/Agent/results/'.now()->format('Y-m-d').'.json';
            file_put_contents(base_path($path), json_encode([
                'date' => now()->format('Y-m-d'),
                'model' => config('capabilities-ai.llm.anthropic.model'),
                'requests' => count($cases),
                'passed' => $passed,
                'pass_rate' => round($passed / count($cases), 3),
                'cases' => $cases,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->line("Recorded in {$path}");
        }

        return $passed === count($cases) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * This command's own .env values would reach Pest, and phpunit.xml does not override a variable that is
     * already set, so the tests would run in this app's environment. Unset them, as `php artisan test` does.
     *
     * @return array<string, false>
     */
    private function withoutDotEnv(): array
    {
        $file = $this->laravel->environmentFilePath();

        return is_file($file) ? array_fill_keys(array_keys(Dotenv::parse((string) file_get_contents($file))), false) : [];
    }

    /** @return list<array{name: string, passed: bool, failure?: string}> each request in the JUnit log, in run order */
    private function cases(string $junit): array
    {
        if (trim($junit) === '') {
            return [];
        }

        $cases = [];
        foreach ((new SimpleXMLElement($junit))->xpath('//testcase') ?: [] as $case) {
            $failure = isset($case->failure) ? $case->failure : (isset($case->error) ? $case->error : null);
            $cases[] = ['name' => preg_replace('/^it /', '', (string) $case['name']), 'passed' => $failure === null && ! isset($case->skipped)]
                + ($failure === null ? [] : ['failure' => $this->message((string) $case['name'], (string) $failure)]);
        }

        return $cases;
    }

    /** Pest's JUnit fault is the test's name, the message, then where it was thrown: keep the message. */
    private function message(string $name, string $fault): string
    {
        $text = str_starts_with($fault, $name) ? substr($fault, strlen($name)) : $fault;

        return trim((string) preg_replace('/\R\s*at \S+\.php:\d+.*$/s', '', $text));
    }
}
