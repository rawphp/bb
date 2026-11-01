<?php

namespace App\Queue;

use Illuminate\Queue\DatabaseQueue as BaseDatabaseQueue;

final class DatabaseQueue extends BaseDatabaseQueue
{
    use StampsUnwalkedTime;
}
