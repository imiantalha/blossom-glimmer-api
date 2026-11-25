<?php

return [
    'cash_on_delivery' => (bool) env('STOREFRONT_COD_ENABLED', true),
    'seed_demo_catalog' => (bool) env('STOREFRONT_SEED_DEMO_CATALOG', env('APP_ENV', 'production') !== 'production'),
];
