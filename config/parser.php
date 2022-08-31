<?php

return [
    'url' => env('GRAPHQL_URL', 'https://graph.cars.com/graphql/api'),
    'key' => env('X_API_KEY', 'r6ZjNiaaP2Zr4KqyTIX7sf62HopyhtB4'),
    'origin_url' => env('ORIGIN_URL', 'https://auto.com/cars'),
    'year_min' => 2000,
    'year_max' => 2003,
    'price_min' => 0,
    'price_max' => 500000000,
    'page_size' => 150,
    'page' => 1,
    'threads' => 10,
    'threshold' => 10000,
    'proxy_enabled' => false,
];
