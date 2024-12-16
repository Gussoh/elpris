<?php
// Price components (all in öre/kWh)
const ADDITIONAL_FEE = 8.63;
const ENERGY_TAX = 54.875;
const TRANSFER_CHARGE = 25.0;
const VAT_MULTIPLIER = 1.25;

// Area configuration
const DEFAULT_AREA = 'SE3';

// Appliance consumption in kWh
const APPLIANCE_COSTS = [
    'Diskmaskin' => [
        'consumption' => 1.2,
        'unit' => 'cykel'
    ],
    'Tvättmaskin' => [
        'consumption' => 2.0,
        'unit' => 'cykel'
    ],
    'Dusch' => [
        'consumption' => 5,
        'unit' => '10 min'
    ]
];

// Time periods
const MAX_DAYS = 31;
const UPDATE_INTERVAL_MINUTES = 5; 