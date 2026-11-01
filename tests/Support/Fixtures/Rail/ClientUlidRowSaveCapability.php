<?php

namespace Tests\Support\Fixtures\Rail;

use App\Capabilities\HostCapability;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\ReadKey;
use App\Capabilities\Support\UsesClientUlid;
use App\Capabilities\Support\WriteResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

/** A create for rail tests: saves a row under the client's ULID, once. */
final class ClientUlidRowSaveCapability implements HostCapability
{
    use UsesClientUlid;

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::write('client_ulid_row_save', affects: ['client_ulid_rows_show'])
            ->description('Save a row under a client-made ULID. Used by rail tests only.')
            ->cli('client-ulid-row', 'save')
            ->input(ClientUlidRowInput::class)
            ->run(function (ClientUlidRowInput $input, CapabilityContext $context): WriteResult {
                /** @var Authenticatable $user */
                $user = $context->user();

                $this->ownedOrNew(ClientUlidRow::class, $input->id, $user)
                    ?? ClientUlidRow::create(['id' => $input->id, 'user_id' => $user->getAuthIdentifier()]);

                return new WriteResult(invalidates: [ReadKey::for('client_ulid_rows_show')]);
            });
    }
}
