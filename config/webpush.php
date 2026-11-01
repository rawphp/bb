<?php

use App\Models\PushSubscription;

// laravel-notification-channels/webpush. With either VAPID key empty the app
// offers no notifications (push_show says so) and the sender job sends nothing.
return [

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    // Our model: a subscription belongs to a user through user_id, so deleting
    // the user deletes it (ACC-005).
    'model' => PushSubscription::class,

    'table_name' => 'push_subscriptions',

    // Null: the app's default connection.
    'database_connection' => null,

    'client_options' => [],

    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
