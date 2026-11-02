<?php

namespace Tests\Support\Fixtures\Rail;

use App\Capabilities\Support\WriteOutput;
use App\Capabilities\Support\WriteResult;

/** A write that also returns a link and an optional note, to test WriteOutput. */
final class LinkWriteOutput extends WriteOutput
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $note,
        public readonly WriteResult $result,
    ) {}
}
