<?php

// Router for `php -S` in RecipePageTransferTest: a page of the size asked for, with or without a Content-Length.
$bytes = (int) ($_GET['bytes'] ?? 0);
if (! isset($_GET['chunked'])) {
    header('Content-Length: '.$bytes);
}
for ($sent = 0; $sent < $bytes && ! connection_aborted(); $sent += 65536) {
    echo str_repeat('a', min(65536, $bytes - $sent));
    flush();
}
