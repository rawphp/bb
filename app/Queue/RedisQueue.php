<?php

namespace App\Queue;

use Illuminate\Queue\RedisQueue as BaseRedisQueue;

final class RedisQueue extends BaseRedisQueue
{
    use StampsUnwalkedTime;
}
