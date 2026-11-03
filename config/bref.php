<?php

// Overrides for bref/laravel-bridge. Only the keys that differ from the package defaults.
return [

    // This API has no file uploads: do not register the bridge's presigned upload route.
    'uploads' => [
        'route' => null,
    ],

];
