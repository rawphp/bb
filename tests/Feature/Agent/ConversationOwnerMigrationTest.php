<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rawphp\CapabilitiesAi\Models\TableNames;

it('ACC-005 drops ownerless Coach conversations, with their messages, before keying user_id to users', function () {
    $migration = require database_path('migrations/2026_11_02_151100_cascade_capabilities_ai_conversations_user_id.php');
    $user = User::factory()->create();
    $conversation = fn (string $ulid, ?int $owner) => DB::table(TableNames::conversations())->insertGetId([
        'ulid' => $ulid, 'user_id' => $owner, 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $message = fn (string $ulid, int $conversationId) => DB::table(TableNames::messages())->insert([
        'ulid' => $ulid, 'conversation_id' => $conversationId, 'role' => 'user', 'content' => 'Hi', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $kept = $conversation('01JKEPT0000000000000000001', $user->id);
    $serverSide = $conversation('01JSERVER00000000000000001', null);
    $message('01JMESSAGE0000000000000001', $kept);
    // A row from before the key: its owner was deleted while nothing cascaded.
    Schema::withoutForeignKeyConstraints(function () use ($conversation, $message, $user) {
        $message('01JMESSAGE0000000000000002', $conversation('01JGONE0000000000000000001', $user->id + 1000));
    });

    $migration->deleteOwnerless();

    expect(DB::table(TableNames::conversations())->orderBy('id')->pluck('id')->all())->toBe([$kept, $serverSide])
        ->and(DB::table(TableNames::messages())->pluck('conversation_id')->all())->toBe([$kept]);
});
