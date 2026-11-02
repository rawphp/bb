<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rawphp\CapabilitiesAi\Models\TableNames;

it('ACC-005 drops ownerless Coach conversations, and all under them, before keying user_id to users', function () {
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
        $chat('GONE', (string) ($user->id + 1000));
        $chat('NOTANID', 'abc');
        $chat('EMPTY', '');
        $chat('SPACED', ' '.$user->id);
        $chat('ZEROS', '000'.$user->id);

        $migration->up();

        $left = [$kept, $serverSide];
        expect(DB::table(TableNames::conversations())->orderBy('id')->pluck('id')->all())->toBe($left)
            ->and(DB::table(TableNames::messages())->orderBy('conversation_id')->pluck('conversation_id')->all())->toBe($left)
            ->and(DB::table(TableNames::turns())->orderBy('conversation_id')->pluck('conversation_id')->all())->toBe($left)
            ->and(DB::table(TableNames::proposals())->orderBy('conversation_id')->pluck('conversation_id')->all())->toBe($left)
            ->and(DB::table(TableNames::conversations())->where('id', $kept)->value('user_id'))->toBe($user->id)
            ->and(collect(Schema::getForeignKeys(TableNames::conversations()))->firstWhere('columns', ['user_id']))
            ->toMatchArray(['foreign_table' => 'users', 'on_delete' => 'cascade']);
    } finally {
        $this->warpRecycleDatabase();
    }
});
