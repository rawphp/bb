<?php

namespace App\Capabilities\Support;

/**
 * The tone of a status pill or bar (UI-004). The server picks it, so the
 * client never compares numbers to choose a colour (CAP-018).
 */
enum Tone: string
{
    case Good = 'good';
    case Neutral = 'neutral';
    case Warning = 'warning';
    case Info = 'info';
}
