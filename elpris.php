<?php
header('Content-Type: text/html; charset=utf-8');

// PHP Configuration
set_time_limit(20);  // 20 seconds
ini_set('memory_limit', '256M');

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
    
    // Display error message if there are permission issues (only once)
    if ($cache_error && !$cache_error_shown) {
        echo '<div style="background-color: #ff5252; color: white; padding: 20px; margin: 20px; border-radius: 8px; font-family: sans-serif;">
            <h3 style="margin-top: 0;">⚠️ Cache Directory Permission Issue</h3>
            <p><strong>Error:</strong> ' . htmlspecialchars($cache_error) . ' (' . htmlspecialchars($cache_dir) . ')</p>
            <p><strong>To fix this, run these commands on your Linux server:</strong></p>
            <pre style="background: rgba(0,0,0,0.1); padding: 15px; border-radius: 4px; overflow-x: auto;">
# Create the cache directory
mkdir -p ' . htmlspecialchars($cache_dir) . '

# Set ownership to web server user (usually www-data)
sudo chown www-data:www-data ' . htmlspecialchars($cache_dir) . '

# Set proper directory permissions
sudo chmod 755 ' . htmlspecialchars($cache_dir) . '</pre>
            <p><strong>Note:</strong> If your web server runs as a different user, replace www-data with that username.</p>
            <p>You can find your web server user by running: <code>ps aux | grep apache</code> or <code>ps aux | grep nginx</code></p>
        </div>';
        $cache_error_shown = true;
    }

    // Format the filename: month-day_area.json
    $filename = date('m-d', strtotime($date)) . "_{$area}.json";
    $cache_path = "$cache_dir/$filename";

    // Check if we have a cached version
    if (file_exists($cache_path)) {
        $cached_data = file_get_contents($cache_path);
        return json_decode($cached_data, true);
    }

    // Format date for API URL (YYYY/mm-dd)
    $api_date = date('Y', strtotime($date)) . '/' . date('m-d', strtotime($date));
    $json_url = "https://www.elprisetjustnu.se/api/v1/prices/{$api_date}_{$area}.json";

    $context = stream_context_create(['http' => ['ignore_errors' => true]]);
    $response = file_get_contents($json_url, false, $context);
    
    // Check if the request was successful
    if (strpos($http_response_header[0], '404') !== false) {
        return null;
    }

    // Save successful response to cache
    $data = json_decode($response, true);
    if ($data !== null) {
        file_put_contents($cache_path, $response);
    }
    
    return $data;
}

// Get dates
$today = date('Y/m-d');
$tomorrow = date('Y/m-d', strtotime('+1 day'));
$area = 'SE3';

// Fetch historical data (30 days back)
$all_prices = [];
for ($i = 30; $i >= -1; $i--) {  // -1 to include tomorrow
    $date = date('Y/m-d', strtotime("-$i days"));
    $day_prices = fetchPriceData($date, $area);
    if ($day_prices !== null) {
        $all_prices = array_merge($all_prices, $day_prices);
    }
}

// If no data is available
if (empty($all_prices)) {
    throw new Exception("No price data available");
}

// Process data for the chart
$labels = [];
$values = [];
foreach ($all_prices as $price) {
    // Convert time to more readable format (e.g., "Mon 15:00")
    $timestamp = strtotime($price['time_start']);
    $hour = date('D H:i', $timestamp);
    $labels[] = $hour;
    $values[] = round(calculateTotalPrice($price['SEK_per_kWh']), 1);
}

// Calculate current price and other metrics using the full dataset
$current_hour = (int)date('H');
$current_price = null;
$lowest_price = PHP_FLOAT_MAX;
$highest_price = 0;
$lowest_price_hour = null;

foreach ($all_prices as $price) {
    $price_hour = (int)date('H', strtotime($price['time_start']));
    $price_day = date('Y-m-d', strtotime($price['time_start']));
    $today = date('Y-m-d');
    
    // Get current price
    if ($price_hour == $current_hour && $price_day == $today) {
        $current_price = calculateTotalPrice($price['SEK_per_kWh']);
    }
    
    // Calculate min/max for today and tomorrow only
    if ($price_day >= $today && ($price_day > $today || $price_hour >= $current_hour)) {
        $total_price = calculateTotalPrice($price['SEK_per_kWh']);
        if ($total_price < $lowest_price) {
            $lowest_price = $total_price;
            $lowest_price_hour = $price_hour;
        }
        if ($total_price > $highest_price) {
            $highest_price = $total_price;
        }
    }
}

// Calculate hours until lowest price
$hours_until_lowest = 0;
$current_price = null;
$lowest_price = PHP_FLOAT_MAX;
$highest_price = 0;
$lowest_price_hour = null;

foreach ($all_prices as $index => $price) {
    $price_hour = (int)date('H', strtotime($price['time_start']));
    $price_day = date('Y-m-d', strtotime($price['time_start']));
    $today = date('Y-m-d');
    
    // Only look at prices from current hour onwards and today/tomorrow
    if ($price_day >= $today && ($price_day > $today || $price_hour >= $current_hour)) {
        $total_price = calculateTotalPrice($price['SEK_per_kWh']);
        if ($total_price < $lowest_price) {
            $lowest_price = $total_price;
            $lowest_price_hour = $price_hour;
        }
        if ($total_price > $highest_price) {
            $highest_price = $total_price;
        }
    }
    
    // Get current price
    if ($price_hour == $current_hour && $price_day == $today) {
        $current_price = calculateTotalPrice($price['SEK_per_kWh']);
    }
}

// Calculate minutes until lowest price
$minutes_until_lowest = 0;
if ($current_price <= $lowest_price) {
    $minutes_until_lowest = 0;
} else {
    $lowest_timestamp = strtotime(date('Y-m-d') . ' ' . $lowest_price_hour . ':00:00');
    if ($lowest_timestamp < time()) {
        $lowest_timestamp += 86400; // Add 24 hours if the time is tomorrow
    }
    $minutes_until_lowest = floor(($lowest_timestamp - time()) / 60);
}

// Format the time until lowest price
$hours_until_lowest = floor($minutes_until_lowest / 60);
$minutes_until_lowest_mod = $minutes_until_lowest % 60;
$time_until_lowest_text = $hours_until_lowest . "h " . $minutes_until_lowest_mod . "m";

// Modify the findCheapestConsecutiveHours function to return more information
function findCheapestConsecutiveHours($all_prices, $consecutive_hours = 3) {
    $current_hour = (int)date('H');
    $today = date('Y-m-d');
    $cheapest_start = null;
    $cheapest_average = PHP_FLOAT_MAX;
    $cheapest_hours = [];
    
    // Convert prices array to sequential array with only future prices
    $future_prices = [];
    foreach ($all_prices as $price) {
        $price_hour = (int)date('H', strtotime($price['time_start']));
        $price_day = date('Y-m-d', strtotime($price['time_start']));
        
        if ($price_day >= $today && ($price_day > $today || $price_hour >= $current_hour)) {
            $future_prices[] = [
                'hour' => $price_hour,
                'price' => calculateTotalPrice($price['SEK_per_kWh']),
                'time_start' => $price['time_start'],
                'label' => date('D H:i', strtotime($price['time_start']))
            ];
        }
    }
    
    // Find cheapest consecutive period
    for ($i = 0; $i <= count($future_prices) - $consecutive_hours; $i++) {
        $sum = 0;
        for ($j = 0; $j < $consecutive_hours; $j++) {
            $sum += $future_prices[$i + $j]['price'];
        }
        $average = $sum / $consecutive_hours;
        
        if ($average < $cheapest_average) {
            $cheapest_average = $average;
            $cheapest_start = $future_prices[$i]['time_start'];
            $cheapest_hours = array_map(function($j) use ($future_prices, $i) {
                return $future_prices[$i + $j]['label'];
            }, range(0, $consecutive_hours - 1));
        }
    }
    
    // Calculate hours and minutes until cheapest period
    $minutes_until = 0;
    if ($cheapest_start) {
        $start_timestamp = strtotime($cheapest_start);
        $current_timestamp = time();
        $minutes_until = floor(($start_timestamp - $current_timestamp) / 60);
    }
    
    return [
        'minutes_until' => $minutes_until,
        'cheapest_hours' => $cheapest_hours
    ];
}

// Get cheapest period information
$cheapest_period = findCheapestConsecutiveHours($all_prices, 3);
$minutes_until_cheapest_period = $cheapest_period['minutes_until'];
$cheapest_hours = $cheapest_period['cheapest_hours'];

// Format the time until cheapest period
$hours_until = floor($minutes_until_cheapest_period / 60);
$minutes_until = $minutes_until_cheapest_period % 60;
$time_until_text = $hours_until . "h " . $minutes_until . "m";

// Add these calculations right before the price-info div
$lowest_hour = null;
$lowest_price = PHP_FLOAT_MAX;
foreach ($all_prices as $price) {
    $price_hour = (int)date('H', strtotime($price['time_start']));
    $price_day = date('Y-m-d', strtotime($price['time_start']));
    $today = date('Y-m-d');
    
    if ($price_day >= $today && ($price_day > $today || $price_hour >= $current_hour)) {
        $total_price = calculateTotalPrice($price['SEK_per_kWh']);
        if ($total_price < $lowest_price) {
            $lowest_price = $total_price;
            $lowest_hour = $price;
        }
    }
}

// Calculate percentage difference
$price_diff_lowest = $current_price ? round((($lowest_price - $current_price) / $current_price) * 100, 1) : 0;

// Calculate average price for cheapest 3h period
$cheapest_3h_avg = 0;
$future_prices = [];

// Filter for future prices only
foreach ($all_prices as $price) {
    $price_hour = (int)date('H', strtotime($price['time_start']));
    $price_day = date('Y-m-d', strtotime($price['time_start']));
    $today = date('Y-m-d');
    
    if ($price_day >= $today && ($price_day > $today || $price_hour >= $current_hour)) {
        $future_prices[] = $price;
    }
}

// Find cheapest consecutive 3 hours
$min_avg = PHP_FLOAT_MAX;
for ($i = 0; $i <= count($future_prices) - 3; $i++) {
    $sum = 0;
    for ($j = 0; $j < 3; $j++) {
        $sum += calculateTotalPrice($future_prices[$i + $j]['SEK_per_kWh']);
    }
    $avg = $sum / 3;
    if ($avg < $min_avg) {
        $min_avg = $avg;
        $cheapest_3h_avg = $avg;
    }
}

$price_diff_3h = $current_price ? round((($cheapest_3h_avg - $current_price) / $current_price) * 100, 1) : 0;

// Average consumption in kWh for common appliances
$appliance_costs = [
    'Dishwasher' => [
        'consumption' => 1.2, // kWh per cycle
        'unit' => 'cycle'
    ],
    'Laundry' => [
        'consumption' => 2.0, // kWh per cycle
        'unit' => 'cycle'
    ],
    'Shower' => [
        'consumption' => 5, // kWh per 10 min
        'unit' => '10 min'
    ]
];

// Calculate cost for each appliance without using reference
$calculated_costs = [];
foreach ($appliance_costs as $appliance => $data) {
    $calculated_costs[$appliance] = [
        'consumption' => $data['consumption'],
        'unit' => $data['unit'],
        'cost' => number_format(($current_price / 100) * $data['consumption'], 1)
    ];
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Elpriser</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <style>
        html, body {
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        
        body {
            background-color: #1a1a1a;
            color: #ffffff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
            transition: background 1s ease;
            min-height: 100vh;
            background-attachment: fixed; /* This prevents the gradient from scrolling */
        }

        .container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            background-color: #2d2d2d;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
            box-sizing: border-box;
        }

        h1 {
            text-align: center;
            color: #00ff9d;
            font-size: 2em;
            margin-bottom: 30px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .price-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin: 30px 0;
            padding: 20px;
            background-color: #363636;
            border-radius: 10px;
            box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.3);
            box-sizing: border-box;
        }

        .price-card {
            padding: 15px;
            text-align: center;
            background-color: #2d2d2d;
            border-radius: 8px;
            border: 1px solid #4a4a4a;
            transition: transform 0.2s;
        }

        .price-card:hover {
            transform: translateY(-5px);
        }

        .price-card h3 {
            color: #00ff9d;
            margin: 0 0 10px 0;
            font-size: 1.2em;
        }

        .price-card .value {
            font-size: 1.8em;
            font-weight: bold;
            color: #ffffff;
        }

        .price-card .unit {
            color: #888;
            font-size: 0.9em;
        }

        #priceChart {
            background-color: #2d2d2d;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            height: 400px;  /* Default height for desktop */
        }

        .price-breakdown {
            text-align: left;
            font-size: 0.9em;
            margin-top: 10px;
        }
        
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
            padding: 2px 0;
        }
        
        .breakdown-item.total {
            border-top: 1px solid #4a4a4a;
            margin-top: 10px;
            padding-top: 10px;
            font-weight: bold;
        }

        .time-range-selector {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .time-button {
            flex: 1;
            min-width: 80px;
            max-width: 150px;
            min-height: 44px;  /* Better touch target */
            padding: 8px 16px;
            background-color: #2d2d2d;
            color: #fff;
            border: 1px solid #4a4a4a;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .time-button:hover {
            background-color: #3d3d3d;
        }

        .time-button.active {
            background-color: #00ff9d;
            color: #1a1a1a;
            border-color: #00ff9d;
        }

        .price-card .price-details {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .price-card .future-price {
            font-size: 1.2em;
            color: #00ff9d;
            font-weight: bold;
        }

        .price-card .percentage {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.9em;
            font-weight: bold;
            margin-left: 5px;
        }

        .price-card .percentage.decrease {
            background: rgba(0, 255, 157, 0.15);
            color: #00ff9d;
        }

        .price-card .percentage.increase {
            background: rgba(255, 82, 82, 0.15);
            color: #ff5252;
        }

        .appliance-costs {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            text-align: left;
        }

        .appliance-costs h4 {
            margin: 0 0 10px 0;
            color: #00ff9d;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .appliance-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 8px 0;
            font-size: 0.9em;
            padding: 4px 0;
        }

        .appliance-icon {
            color: #888;
        }

        .appliance-cost {
            color: #00ff9d;
            font-weight: bold;
        }

        /* Add media query for mobile */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .container {
                padding: 10px;
                border-radius: 10px;
            }

            h1 {
                font-size: 1.3em;
                margin-bottom: 15px;
            }

            .price-info {
                grid-template-columns: 1fr;  /* Force single column */
                padding: 15px;
                margin: 15px 0;
                gap: 15px;
            }

            .price-card {
                padding: 12px;
                font-size: 0.9em;
            }

            .price-card .value {
                font-size: 1.4em;
            }

            .price-card h3 {
                font-size: 1.1em;
            }

            .time-range-selector {
                gap: 5px;
                margin-bottom: 15px;
            }

            .time-button {
                min-width: auto;  /* Remove min-width */
                flex: 1;
                font-size: 0.9em;
                padding: 8px;
            }

            .appliance-costs {
                margin-top: 12px;
                padding-top: 12px;
            }

            .appliance-item {
                font-size: 0.85em;
            }

            .breakdown-item {
                font-size: 0.85em;
            }

            #priceChart {
                height: 250px;  /* Shorter on mobile */
                padding: 10px;
                margin-top: 10px;
            }
        }

        /* Add small phone specific adjustments */
        @media (max-width: 380px) {
            body {
                padding: 5px;
            }

            .container {
                padding: 8px;
                border-radius: 8px;
            }

            .price-info {
                padding: 10px;
                margin: 10px 0;
                gap: 10px;
            }

            .price-card .value {
                font-size: 1.2em;
            }

            .time-button {
                padding: 6px;
                font-size: 0.8em;
            }

            .price-card {
                padding: 10px;
            }
        }

        /* Add desktop-only optimization */
        @media (min-width: 1200px) {
            .price-info {
                grid-template-columns: repeat(4, 1fr);  /* Force 4 columns on desktop */
            }
        }

        #chartTooltip {
            min-height: 60px;
            padding: 12px 15px;
            background-color: #2d2d2d;
            border-radius: 8px;
            border: 1px solid #4a4a4a;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-top: 10px;
            line-height: 1.5;
        }

        .tooltip-time {
            color: #00ff9d;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .tooltip-price {
            color: #fff;
            font-size: 1.1em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tooltip-comparison {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.9em;
            font-weight: bold;
        }

        .tooltip-comparison.decrease {
            background: rgba(0, 255, 157, 0.15);
            color: #00ff9d;
        }

        .tooltip-comparison.increase {
            background: rgba(255, 82, 82, 0.15);
            color: #ff5252;
        }
    </style>
    <link rel="icon" type="image/x-icon" href="price.png">
</head>
<body>
    <div class="container">
        <h1>Elpriser</h1>
        
        <div class="time-range-selector">
            <button class="time-button active" data-days="1">Idag</button>
            <button class="time-button" data-days="7">Vecka</button>
            <button class="time-button" data-days="30">Månad</button>
        </div>

        <div class="price-info">
            <div class="price-card">
                <h3>Aktuellt Pris</h3>
                <div class="value"><?= number_format($current_price, 1) ?></div>
                <div class="unit">öre/kWh</div>
                <div class="appliance-costs">
                    <h4>Kostnad att köra:</h4>
                    <?php foreach ($calculated_costs as $appliance => $data): ?>
                        <div class="appliance-item">
                            <span class="appliance-icon">
                                <?= strtr($appliance, [
                                    'Dishwasher' => 'Diskmaskin',
                                    'Laundry' => 'Tvättmaskin',
                                    'Shower' => 'Dusch',
                                    'Oven' => 'Ugn',
                                    'EV Charging' => 'Elbilsladdning'
                                ]) ?>
                            </span>
                            <span class="appliance-cost">
                                <?= $data['cost'] ?> kr/<?= strtr($data['unit'], [
                                    'cycle' => 'cykel',
                                    'hour' => 'timme',
                                    '10 min' => '10 min'
                                ]) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="price-card">
                <h3>Tid till Lägsta Pris</h3>
                <div class="value"><?= $time_until_lowest_text ?></div>
                <div class="price-details">
                    <span class="future-price"><?= number_format($lowest_price, 1) ?> öre/kWh</span>
                    <span class="percentage <?= $price_diff_lowest <= 0 ? 'decrease' : 'increase' ?>">
                        <?= ($price_diff_lowest >= 0 ? '+' : '') . $price_diff_lowest ?>%
                    </span>
                </div>
            </div>

            <div class="price-card">
                <h3>Tid till Billigaste 3h Period</h3>
                <div class="value"><?= $time_until_text ?></div>
                <div class="price-details">
                    <span class="future-price"><?= number_format($cheapest_3h_avg, 1) ?> öre/kWh</span>
                    <span class="percentage <?= $price_diff_3h <= 0 ? 'decrease' : 'increase' ?>">
                        <?= ($price_diff_3h >= 0 ? '+' : '') . $price_diff_3h ?>%
                    </span>
                </div>
            </div>

            <div class="price-card">
                <h3>Prisuppdelning</h3>
                <div class="price-breakdown">
                    <div class="breakdown-item">
                        <span>Spotpris (inkl. moms):</span>
                        <span><?= number_format(($current_price - (ADDITIONAL_FEE + ENERGY_TAX + TRANSFER_CHARGE)), 1) ?> öre/kWh</span>
                    </div>
                    <div class="breakdown-item">
                        <span>Påslag:</span>
                        <span><?= ADDITIONAL_FEE ?> öre/kWh</span>
                    </div>
                    <div class="breakdown-item">
                        <span>Energiskatt:</span>
                        <span><?= ENERGY_TAX ?> öre/kWh</span>
                    </div>
                    <div class="breakdown-item">
                        <span>Överföringsavgift:</span>
                        <span><?= TRANSFER_CHARGE ?> öre/kWh</span>
                    </div>
                    <div class="breakdown-item total">
                        <span>Totalt:</span>
                        <span><?= number_format($current_price, 1) ?> öre/kWh</span>
                    </div>
                </div>
            </div>
        </div>

        <canvas id="priceChart"></canvas>
        <div id="chartTooltip" style="display: none; min-height: 60px; margin-top: 10px; padding: 10px; background-color: #2d2d2d; border-radius: 8px; border: 1px solid #4a4a4a;"></div>
    </div>

    <script>
        const ctx = document.getElementById('priceChart').getContext('2d');
        
        // Get just the current hour with full date information
        const currentDateTime = '<?= date('Y-m-d D H:00') ?>';
        
        // Get the full dates array and prices
        const allPrices = <?= json_encode($all_prices) ?>;
        const allValues = <?= json_encode($values) ?>;
        
        // Get cheapest hours from PHP
        const cheapestHours = <?= json_encode($cheapest_period['cheapest_hours']) ?>;

        const backgroundPlugin = {
            id: 'customCanvasBackgroundColor',
            beforeDraw: (chart, args, options) => {
                const {ctx, chartArea, scales} = chart;
                const xScale = scales.x;
                
                ctx.save();
                
                // Draw background for each data point
                chart.data.labels.forEach((label, index) => {
                    const priceData = chart.data.fullData[index];
                    if (!priceData) return;
                    
                    const fullDateLabel = formatDateTime(priceData.time_start);
                    let fillStyle = null;
                    
                    if (fullDateLabel === currentDateTime) {
                        fillStyle = 'rgba(0, 255, 157, 0.1)';
                    } else if (cheapestHours.includes(label)) {
                        fillStyle = 'rgba(75, 192, 192, 0.1)';
                    } else if (options.highlightedHour === priceData.time_start) {
                        fillStyle = 'rgba(255, 255, 255, 0.1)';
                    }
                    
                    if (fillStyle) {
                        ctx.fillStyle = fillStyle;
                        const x = xScale.getPixelForValue(index);
                        const width = xScale.getPixelForValue(index + 1) - x;
                        ctx.fillRect(x, chartArea.top, width, chartArea.bottom - chartArea.top);
                    }
                });
                
                // Draw current time line
                const currentHourIndex = chart.data.labels.findIndex((label, index) => {
                    const priceData = chart.data.fullData[index];
                    if (!priceData) return false;
                    
                    const fullDateLabel = formatDateTime(priceData.time_start);
                    return fullDateLabel === currentDateTime;
                });

                // Draw current time line
                if (currentHourIndex !== -1) {
                    const now = new Date();
                    const minutes = now.getMinutes();
                    const percentage = minutes / 60;
                    const x1 = xScale.getPixelForValue(currentHourIndex);
                    const x2 = xScale.getPixelForValue(currentHourIndex + 1);
                    const currentX = x1 + (x2 - x1) * percentage;

                    ctx.beginPath();
                    ctx.strokeStyle = 'rgba(255, 255, 255, 0.5)';
                    ctx.lineWidth = 2;
                    ctx.setLineDash([5, 5]);
                    ctx.moveTo(currentX, chartArea.top);
                    ctx.lineTo(currentX, chartArea.bottom);
                    ctx.stroke();
                    ctx.setLineDash([]);
                }
                
                ctx.restore();
            }
        };

        // Function to format date consistently
        function formatDateTime(dateStr) {
            const date = new Date(dateStr);
            return `${date.getFullYear()}-${
                String(date.getMonth() + 1).padStart(2, '0')}-${
                String(date.getDate()).padStart(2, '0')} ${
                date.toLocaleString('en-US', { weekday: 'short' })} ${
                String(date.getHours()).padStart(2, '0')}:00`;
        }

        // Function to filter data for specific number of days
        function filterDataForDays(days) {
            const now = new Date();
            const cutoffDate = new Date(now - days * 24 * 60 * 60 * 1000);
            
            const filteredLabels = [];
            const filteredPrices = [];
            const filteredFullData = [];
            
            allPrices.forEach((price, index) => {
                const priceDate = new Date(price.time_start);
                if (priceDate >= cutoffDate) {
                    filteredLabels.push(<?= json_encode($labels) ?>[index]);
                    filteredPrices.push(allValues[index]);
                    filteredFullData.push(price);
                }
            });

            // Add an extra hour to the last price point
            if (filteredLabels.length > 0) {
                const lastLabel = filteredLabels[filteredLabels.length - 1];
                const nextHour = new Date(filteredFullData[filteredFullData.length - 1].time_start);
                nextHour.setHours(nextHour.getHours() + 1);
                
                filteredLabels.push(nextHour.toLocaleString('en-US', { weekday: 'short' }) + ' ' + 
                    String(nextHour.getHours()).padStart(2, '0') + ':00');
                filteredPrices.push(filteredPrices[filteredPrices.length - 1]);
                filteredFullData.push({...filteredFullData[filteredFullData.length - 1], 
                    time_start: nextHour.toISOString()});
            }

            // Calculate min/max for the filtered data
            const minPrice = Math.min(...filteredPrices);
            const maxPrice = Math.max(...filteredPrices);

            return { 
                labels: filteredLabels, 
                prices: filteredPrices,
                fullData: filteredFullData,
                minPrice: minPrice,
                maxPrice: maxPrice
            };
        }

        // Function to get color based on price
        function getColorForPrice(price, minPrice, maxPrice) {
            const percentage = (price - minPrice) / (maxPrice - minPrice);
            const hue = (1 - percentage) * 120;
            return `hsla(${hue}, 80%, 40%, 1)`;
        }

        // Get min and max prices
        const minPrice = Math.min(...allValues);
        const maxPrice = Math.max(...allValues);

        // Create the chart with initial (today) data
        const initialData = filterDataForDays(1);
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: initialData.labels,
                datasets: [{
                    label: 'Total Price',
                    data: initialData.prices,
                    borderWidth: 2,
                    stepped: true,
                    tension: 0,
                    pointRadius: 0,
                    borderJoinStyle: 'miter',
                    segment: {
                        borderColor: ctx => getColorForPrice(
                            initialData.prices[ctx.p0DataIndex], 
                            initialData.minPrice, 
                            initialData.maxPrice
                        )
                    }
                }],
                fullData: initialData.fullData
            },
            options: {
                responsive: true,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    tooltip: {
                        enabled: false  // Disable the built-in tooltip
                    },
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#444'
                        },
                        ticks: {
                            color: '#fff'
                        }
                    },
                    x: {
                        grid: {
                            color: '#444'
                        },
                        ticks: {
                            color: '#fff'
                        }
                    }
                },
                onHover: (event, elements) => {
                    const tooltipEl = document.getElementById('chartTooltip');
                    
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const priceData = chart.data.fullData[index];
                        const price = elements[0].element.$context.parsed.y;
                        const currentPrice = <?= $current_price ?>;
                        const priceDiff = ((price - currentPrice) / currentPrice * 100).toFixed(1);
                        const isDecrease = price <= currentPrice;
                        
                        // Update tooltip content with HTML structure
                        const hourTime = new Date(priceData.time_start);
                        const now = new Date();
                        const diffMs = hourTime - now;
                        
                        let tooltipContent = `
                            <div class="tooltip-time">
                                ${hourTime.toLocaleString('sv-SE', { 
                                    weekday: 'short', 
                                    hour: '2-digit', 
                                    minute: '2-digit' 
                                })}
                            </div>
                            <div class="tooltip-price">
                                Pris: ${price.toFixed(1)} öre/kWh
                                <span class="tooltip-comparison ${isDecrease ? 'decrease' : 'increase'}">
                                    ${isDecrease ? '' : '+'}${priceDiff}%
                                </span>
                            </div>`;

                        if (diffMs > 0) {
                            const hours = Math.floor(diffMs / (1000 * 60 * 60));
                            const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
                            const timeUntil = hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
                            tooltipContent += `<div>Tid tills: ${timeUntil}</div>`;
                        }

                        tooltipEl.innerHTML = tooltipContent;
                        tooltipEl.style.display = 'block';
                        
                        // Add highlight to the plugin
                        chart.options.plugins.customCanvasBackgroundColor = {
                            highlightedHour: priceData.time_start
                        };
                        
                        chart.update('none');
                    } else {
                        tooltipEl.style.display = 'none';
                        chart.options.plugins.customCanvasBackgroundColor = {};
                        chart.update('none');
                    }
                }
            },
            plugins: [backgroundPlugin]
        });

        // Update button click handlers
        document.querySelectorAll('.time-button').forEach(button => {
            button.addEventListener('click', () => {
                // Update active button
                document.querySelectorAll('.time-button').forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');

                // Get number of days to show
                const daysToShow = parseInt(button.dataset.days);
                const filteredData = filterDataForDays(daysToShow);

                // Update chart with filtered data
                chart.data.labels = filteredData.labels;
                chart.data.datasets[0].data = filteredData.prices;
                chart.data.fullData = filteredData.fullData;
                
                // Update the color scale based on filtered data
                chart.data.datasets[0].segment.borderColor = ctx => getColorForPrice(
                    filteredData.prices[ctx.p0DataIndex],
                    filteredData.minPrice,
                    filteredData.maxPrice
                );
                
                chart.update();
            });
        });

        // Update chart every minute to move the time line
        setInterval(() => {
            chart.update();
        }, 60000);
    </script>
</body>
</html>
