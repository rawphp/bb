<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Rawphp\CapabilitiesAi\Models\TableNames;

it('ACC-005 keys user_id to users: another spelling of a user\'s id is that user, an owner that is no user is cleared, and nothing is deleted', function () {
    $migration = require database_path('migrations/2026_11_02_151100_cascade_capabilities_ai_conversations_user_id.php');
    // DDL commits on MySQL, so this test rebuilds the database when it is done.
    try {
        // The column as the package created it: a string, with no key.
        $migration->down();
        expect(Schema::getColumnType(TableNames::conversations(), 'user_id'))->toBe('varchar');

        $user = User::factory()->create();
        $chat = function (string $tag, ?string $owner): int {
            $at = ['created_at' => now(), 'updated_at' => now()];
            $conversation = DB::table(TableNames::conversations())->insertGetId(['ulid' => str_pad("01JC{$tag}", 26, '0'), 'user_id' => $owner, 'status' => 'open'] + $at);
            DB::table(TableNames::messages())->insert(['ulid' => str_pad("01JM{$tag}", 26, '0'), 'conversation_id' => $conversation, 'role' => 'user', 'content' => 'Hi'] + $at);
            $turn = DB::table(TableNames::turns())->insertGetId(['ulid' => str_pad("01JT{$tag}", 26, '0'), 'conversation_id' => $conversation] + $at);
            DB::table(TableNames::proposals())->insert(['ulid' => str_pad("01JP{$tag}", 26, '0'), 'conversation_id' => $conversation, 'turn_id' => $turn] + $at);

            return $conversation;
        };

        $kept = $chat('KEPT', (string) $user->id);
        $serverSide = $chat('SERVER', null);
        $spaced = $chat('SPACED', ' '.$user->id);
        $zeros = $chat('ZEROS', '000'.$user->id);
        $gone = $chat('GONE', (string) ($user->id + 1000));
        $notAnId = $chat('NOTANID', 'abc');
        $empty = $chat('EMPTY', '');
        Log::spy();

        $migration->up();

        $all = [$kept, $serverSide, $spaced, $zeros, $gone, $notAnId, $empty];
        $owners = DB::table(TableNames::conversations())->orderBy('id')->pluck('user_id', 'id')->all();
        expect(array_keys($owners))->toBe($all)
            ->and(DB::table(TableNames::messages())->orderBy('conversation_id')->pluck('conversation_id')->all())->toBe($all)
            ->and(DB::table(TableNames::turns())->orderBy('conversation_id')->pluck('conversation_id')->all())->toBe($all)
            ->and(DB::table(TableNames::proposals())->orderBy('conversation_id')->pluck('conversation_id')->all())->toBe($all)
            ->and($owners)->toBe([
                $kept => $user->id, $serverSide => null, $spaced => $user->id, $zeros => $user->id,
                $gone => null, $notAnId => null, $empty => null,
            ])
            ->and(collect(Schema::getForeignKeys(TableNames::conversations()))->firstWhere('columns', ['user_id']))
            ->toMatchArray(['foreign_table' => 'users', 'on_delete' => 'cascade']);
        Log::shouldHaveReceived('warning')->times(3);
    } finally {
        $this->warpRecycleDatabase();
    }
});
