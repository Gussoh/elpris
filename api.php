<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once 'config.php';

// Valid price areas in Sweden
const VALID_AREAS = ['SE1', 'SE2', 'SE3', 'SE4'];

// Function to calculate total price from base price
function calculateTotalPrice($base_price_sek) {
    // Add all components before VAT
    $price_before_vat = ($base_price_sek * 100) + ADDITIONAL_FEE + TRANSFER_CHARGE + ENERGY_TAX;

    // Apply VAT to the total
    return $price_before_vat * VAT_MULTIPLIER;
}

// Function to aggregate 15-minute data to hourly averages
function aggregateToHourly($priceData) {
    $hourlyData = [];

    foreach ($priceData as $price) {
        $startTime = new DateTime($price['time_start']);
        $hour = $startTime->format('Y-m-d H:00:00'); // Group by hour

        if (!isset($hourlyData[$hour])) {
            $hourlyData[$hour] = [
                'time_start' => $hour,
                'time_end' => date('Y-m-d H:i:s', strtotime($hour . ' +1 hour')),
                'SEK_per_kWh' => 0,
                'EUR_per_kWh' => 0,
                'EXR' => $price['EXR'],
                'total_price' => 0,
                'count' => 0
            ];
        }

        // Accumulate prices for averaging
        $hourlyData[$hour]['SEK_per_kWh'] += $price['SEK_per_kWh'];
        $hourlyData[$hour]['EUR_per_kWh'] += $price['EUR_per_kWh'];
        $hourlyData[$hour]['total_price'] += calculateTotalPrice($price['SEK_per_kWh']);
        $hourlyData[$hour]['count']++;
    }

    // Calculate averages
    $result = [];
    foreach ($hourlyData as $hour => $data) {
        $result[] = [
            'time_start' => $data['time_start'],
            'time_end' => $data['time_end'],
            'SEK_per_kWh' => round($data['SEK_per_kWh'] / $data['count'], 4),
            'EUR_per_kWh' => round($data['EUR_per_kWh'] / $data['count'], 4),
            'EXR' => $data['EXR'],
            'total_price' => round($data['total_price'] / $data['count'], 2)
        ];
    }

    return $result;
}

// Function to safely fetch JSON data
function fetchPriceData($date, $area, $aggregate_to_hourly = false) {
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
            // Aggregate to hourly if requested
            if ($aggregate_to_hourly) {
                $data = aggregateToHourly($data);
            }
            return $data;
        }
    }

    // Format date for API URL (YYYY/mm-dd)
    $api_date = date('Y', strtotime($date)) . '/' . date('m-d', strtotime($date));
    $json_url = "https://www.elprisetjustnu.se/api/v1/prices/{$api_date}_{$area}.json";

    // Check if requesting tomorrow's data and if it's too early (before 13:45)
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $requested_date = date('Y-m-d', strtotime($date));
    
    if ($requested_date == $tomorrow) {
        $current_hour = (int)date('G');
        $current_minute = (int)date('i');
        $current_time_in_minutes = $current_hour * 60 + $current_minute;
        $release_time_in_minutes = 13 * 60 + 45; // 13:45 in minutes
        
        if ($current_time_in_minutes < $release_time_in_minutes) {
            return ['error' => true, 'message' => 'Tomorrow\'s prices are not available until 13:45'];
        }
    }

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
        // Aggregate to hourly if requested
        if ($aggregate_to_hourly) {
            $data = aggregateToHourly($data);
        }
    }

    return $data;
}

// Get parameters
$days = isset($_GET['days']) ? (int)$_GET['days'] : 1;
$area = isset($_GET['area']) ? $_GET['area'] : DEFAULT_AREA;

// Validate input
if ($days < 1 || $days > MAX_DAYS) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => "Days parameter must be between 1 and " . MAX_DAYS]);
    exit;
}

if (!in_array($area, VALID_AREAS)) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Invalid area parameter']);
    exit;
}

// Fetch data for requested days
$all_prices = [];
for ($i = $days - 1; $i >= -1; $i--) {  // -1 to include tomorrow
    // Skip tomorrow's prices if current time is before 13:45
    if ($i == -1) {
        $current_hour = (int)date('G');
        $current_minute = (int)date('i');
        $current_time_in_minutes = $current_hour * 60 + $current_minute;
        $release_time_in_minutes = 13 * 60 + 45; // 13:45 in minutes
        
        if ($current_time_in_minutes < $release_time_in_minutes) {
            // Skip fetching tomorrow's prices as they're not published yet
            continue;
        }
    }
    
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