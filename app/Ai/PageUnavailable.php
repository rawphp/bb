<?php

namespace App\Ai;

use RuntimeException;

/** A recipe URL was refused or could not be fetched (RCP-003). */
final class PageUnavailable extends RuntimeException {}
