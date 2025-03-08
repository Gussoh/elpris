<?php
// Price components (all in öre/kWh)
const ADDITIONAL_FEE = 13.6; // elcertifikat, urspringsgarantier, omkostnader elköp, avgift för balansansvar.
const ENERGY_TAX = 54.88;
const TRANSFER_CHARGE = 7.5;
const VAT_MULTIPLIER = 1.25;

// Convert öre to SEK and add all components
const PRICE_CONSTANT = (ADDITIONAL_FEE + ENERGY_TAX + TRANSFER_CHARGE) / 100;  // Convert öre to SEK

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