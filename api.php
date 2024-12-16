<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Constants for price components (all in öre/kWh)
const ADDITIONAL_FEE = 8.63;
const ENERGY_TAX = 54.875;
const TRANSFER_CHARGE = 25.0;
const VAT_MULTIPLIER = 1.25;

// Function to calculate total price from base price
function calculateTotalPrice($base_price_sek) {
    // Convert base price to öre and add VAT
    $price_with_vat = $base_price_sek * 100 * VAT_MULTIPLIER;
    
    // Add fixed components
    return $price_with_vat + ADDITIONAL_FEE + ENERGY_TAX + TRANSFER_CHARGE;
}

// Function to safely fetch JSON data
function fetchPriceData($date, $area) {
    static $cache_error_shown = false;
    
    // Convert date format from Y/m-d to Y-m-d for proper parsing
    $date = str_replace('/', '-', $date);
    
    // Create directory structure if it doesn't exist
    $year = date('Y', strtotime($date));
    $cache_dir = "oldPrices/$year";
    
    // Check if directory exists and is writable
    $cache_error = '';
    if (!file_exists($cache_dir)) {
        if (!@mkdir($cache_dir, 0777, true)) {
            $cache_error = "Cache directory could not be created";
        }
    } elseif (!is_writable($cache_dir)) {
        $cache_error = "Cache directory is not writable";
    }
    
    if ($cache_error && !$cache_error_shown) {
        $error_response = [
            'error' => true,
            'message' => $cache_error,
            'cache_dir' => $cache_dir,
            'type' => 'permission_error'
        ];
        $cache_error_shown = true;
        return $error_response;
    }

    // Format the filename: month-day_area.json
    $filename = date('m-d', strtotime($date)) . "_{$area}.json";
    $cache_path = "$cache_dir/$filename";

    // Check if we have a cached version
    if (file_exists($cache_path)) {
        $cached_data = file_get_contents($cache_path);
        $data = json_decode($cached_data, true);
        if ($data !== null) {
            // Add calculated total prices
            foreach ($data as &$price) {
                $price['total_price'] = calculateTotalPrice($price['SEK_per_kWh']);
            }
            return $data;
        }
    }

    // Format date for API URL (YYYY/mm-dd)
    $api_date = date('Y', strtotime($date)) . '/' . date('m-d', strtotime($date));
    $json_url = "https://www.elprisetjustnu.se/api/v1/prices/{$api_date}_{$area}.json";

    $context = stream_context_create(['http' => ['ignore_errors' => true]]);
    $response = file_get_contents($json_url, false, $context);
    
    // Check if the request was successful
    if (strpos($http_response_header[0], '404') !== false) {
        return ['error' => true, 'message' => 'Data not available for this date'];
    }

    // Save successful response to cache
    $data = json_decode($response, true);
    if ($data !== null) {
        file_put_contents($cache_path, $response);
        // Add calculated total prices
        foreach ($data as &$price) {
            $price['total_price'] = calculateTotalPrice($price['SEK_per_kWh']);
        }
    }
    
    return $data;
}

// Get parameters
$days = isset($_GET['days']) ? (int)$_GET['days'] : 1;
$area = isset($_GET['area']) ? $_GET['area'] : 'SE3';

// Validate input
if ($days < 1 || $days > 31) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Days parameter must be between 1 and 31']);
    exit;
}

if (!in_array($area, ['SE1', 'SE2', 'SE3', 'SE4'])) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Invalid area parameter']);
    exit;
}

// Fetch data for requested days
$all_prices = [];
for ($i = $days - 1; $i >= -1; $i--) {  // -1 to include tomorrow
    $date = date('Y/m-d', strtotime("-$i days"));
    $day_prices = fetchPriceData($date, $area);
    
    // Check for permission error
    if (isset($day_prices['error']) && isset($day_prices['type']) && $day_prices['type'] === 'permission_error') {
        echo json_encode($day_prices);
        exit;
    }
    
    if ($day_prices !== null && !isset($day_prices['error'])) {
        $all_prices = array_merge($all_prices, $day_prices);
    }
}

echo json_encode([
    'error' => false,
    'prices' => $all_prices,
    'constants' => [
        'additional_fee' => ADDITIONAL_FEE,
        'energy_tax' => ENERGY_TAX,
        'transfer_charge' => TRANSFER_CHARGE,
        'vat_multiplier' => VAT_MULTIPLIER
    ]
]); 