<?php
// Start output buffering to catch any errors or unintended output
ob_start();

require_once 'config.php';
require_once 'api.php';

// Set proper content type for PNG image - must come AFTER api.php include 
// to override the application/json header set in api.php
header('Content-Type: image/png');

// Turn off error reporting to prevent JSON errors from being included in output
error_reporting(0);
ini_set('display_errors', 0);

// Widget configuration
// Get width and height from query parameters or use defaults
// Usage examples:
// widget.php               - Default size (550x200)
// widget.php?width=600     - Custom width (600x200) 
// widget.php?height=300    - Custom height (550x300)
// widget.php?width=600&height=300 - Custom dimensions (600x300)
$width = isset($_GET['width']) ? intval($_GET['width']) : 550;
$height = isset($_GET['height']) ? intval($_GET['height']) : 200;

// Set minimum dimensions to avoid rendering issues
$width = max(400, min(1200, $width));  // Min: 400px, Max: 1200px
$height = max(200, min(800, $height)); // Min: 200px, Max: 800px

// Calculate scaling factor based on width (reference width is 550px)
$scaleFactor = $width / 550;

$padding = round(25 * $scaleFactor);
$graphPadding = round(10 * $scaleFactor);
$textPadding = round(15 * $scaleFactor);
$topBoxHeight = 50; // Keep fixed height for top section
$bottomBoxHeight = 80; // Keep fixed height for bottom section

// Calculate graph dimensions - graph height expands with total height
$graphWidth = $width - (2 * $graphPadding);
$graphHeight = $height - $topBoxHeight - $bottomBoxHeight - 10;
$graphTop = $topBoxHeight + 5;
$graphBottom = $height - $bottomBoxHeight - 5;

// Text position calculations - moved here so they're defined once
$labelX = $padding + round(10 * $scaleFactor);
$valueX = $labelX + round(220 * $scaleFactor);
$diffX = $valueX + round(100 * $scaleFactor);
$y1 = $height - $bottomBoxHeight + 30;
$y2 = $height - $bottomBoxHeight + 60;

try {
    // Fetch today's price data and tomorrow's data
    $area = DEFAULT_AREA;
    $priceData = [];
    
    // Get today's price data
    $todayData = fetchPriceData(date('Y/m-d'), $area);
    if (is_array($todayData) && !isset($todayData['error'])) {
        $priceData = $todayData;
    }
    
    // Only try to get tomorrow's price data if it's after 13:45
    $current_hour = (int)date('G');
    $current_minute = (int)date('i');
    $current_time_in_minutes = $current_hour * 60 + $current_minute;
    $release_time_in_minutes = 13 * 60 + 45; // 13:45 in minutes
    
    if ($current_time_in_minutes >= $release_time_in_minutes) {
        // Try to get tomorrow's price data
        $tomorrowData = fetchPriceData(date('Y/m-d', strtotime('+1 day')), $area);
        if (is_array($tomorrowData) && !isset($tomorrowData['error'])) {
            $priceData = array_merge($priceData, $tomorrowData);
        }
    }
    
    // Check if we got a valid array of price data
    if (empty($priceData)) {
        throw new Exception("Failed to fetch price data: No valid data available");
    }
    
    // Create the base image with alpha channel
    $image = imagecreatetruecolor($width, $height);
    if (!$image) {
        throw new Exception("Failed to create image. GD library may not be installed.");
    }
    
    // Enable alpha blending and save alpha channel
    imagealphablending($image, false);
    imagesavealpha($image, true);
    
    // Define colors - centralized color definitions
    $colors = [
        'transparent' => imagecolorallocatealpha($image, 0, 0, 0, 80),
        'subtleBackground' => imagecolorallocatealpha($image, 0, 0, 0, 102),
        'textColor' => imagecolorallocate($image, 255, 255, 255),
        'accentColor' => imagecolorallocate($image, 0, 230, 150),
        'gridColor' => imagecolorallocatealpha($image, 80, 80, 80, 80),
        'highPriceColor' => imagecolorallocate($image, 240, 80, 80),
        'midPriceColor' => imagecolorallocate($image, 240, 180, 40),
        'lowPriceColor' => imagecolorallocate($image, 80, 230, 120),
        'shadowColor' => imagecolorallocatealpha($image, 0, 0, 0, 60),
        'glowColor' => imagecolorallocatealpha($image, 0, 220, 140, 80),
        'patternColor' => imagecolorallocatealpha($image, 255, 255, 255, 115),
        'circuitColor' => imagecolorallocatealpha($image, 0, 210, 140, 100),
        'energyColor' => imagecolorallocatealpha($image, 0, 255, 200, 90),
        'dividerColor' => imagecolorallocatealpha($image, 255, 255, 255, 110)
    ];
    
    // Fill the background with transparency
    imagefill($image, 0, 0, $colors['transparent']);
    
    // Enable alpha blending for drawing
    imagealphablending($image, true);
    
    // Draw the simple black background with 20% opacity
    imagefilledrectangle($image, 0, 0, $width, $height, $colors['subtleBackground']);
    
    // Draw some subtle circuit board patterns in the background
    drawCircuitPatterns($image, $padding, $width, $height, $colors['circuitColor']);
    
    // Process the price data
    $now = new DateTime();
    $currentHour = (int)$now->format('G');

    // Sort the price data by time to ensure chronological order
    usort($priceData, function($a, $b) {
        return strtotime($a['time_start']) - strtotime($b['time_start']);
    });

    // Extract and process price data
    list($prices, $periodPrices, $currentPrice, $lowestPrice, $lowestPricePeriod) = processPriceData($priceData, $now, $currentHour);

    // Determine the current 15-minute period window for the heading
    $currentMinute = (int)$now->format('i');
    $quarterMinute = floor($currentMinute / 15) * 15;
    $currentPeriodStart = (clone $now)->setTime((int)$now->format('G'), $quarterMinute, 0);
    $currentPeriodEnd = (clone $currentPeriodStart);
    $currentPeriodEnd->modify('+15 minutes');

    // Find cheapest 3-hour period
    list($cheapest3hStart, $cheapest3hPrice) = findCheapest3HourPeriod($periodPrices, $now);
    
    // Calculate time differences and price differences
    list($hoursUntilLowest, $minutesRemaining, $priceDiffLowest) = calculateTimeDifference($lowestPricePeriod, $now, $currentPrice, $lowestPrice);
    list($hoursUntilCheapest3h, $minutesUntilCheapest3h, $priceDiff3h) = calculateTimeDifference($cheapest3hStart, $now, $currentPrice, $cheapest3hPrice);
    
    // Load fonts
    $fonts = loadFonts();
    
    // Draw the graph if we have price data
    if (!empty($prices)) {
        $valueRange = max($prices);
        if ($valueRange <= 0) $valueRange = 1;
        
        // Determine price thresholds for coloring
        sort($prices);
        $priceCount = count($prices);
        $lowThreshold = $prices[floor($priceCount * 0.25)];
        $highThreshold = $prices[floor($priceCount * 0.75)];
        
        // Draw grid and graph elements
        drawGrid($image, $graphPadding, $width, $graphBottom, $graphHeight, $graphTop, $colors['gridColor']);
        drawBaseLineEffect($image, $graphPadding, $width, $graphBottom);
        drawPriceGraph($image, $periodPrices, $currentHour, $valueRange, $graphPadding, $graphWidth, $graphHeight,
                        $graphBottom, $graphTop, $lowThreshold, $highThreshold, $colors);
    }
    
    // Format text elements
    $textElements = formatTextElements($currentPeriodStart, $currentPeriodEnd, $currentPrice, 
                                      $hoursUntilLowest, $minutesRemaining, $priceDiffLowest,
                                      $hoursUntilCheapest3h, $minutesUntilCheapest3h, $priceDiff3h,
                                      $colors);
    
    // Draw text elements based on font availability
    drawTextElements($image, $textElements, $fonts, $width, $height, $textPadding, 
                     $bottomBoxHeight, $padding, $labelX, $valueX, $diffX, $y1, $y2, $colors, $scaleFactor);
    
    // Draw decorative corner elements
    drawCornerElements($image, $padding, $width, $height, $colors['circuitColor'], $scaleFactor);
    
    // Clear any output buffered content before sending image
    ob_end_clean();
    
    // Output image to browser
    imagepng($image);
    
    // Clean up resources
    imagedestroy($image);
    
} catch (Exception $e) {
    // If something goes wrong, return an error image
    outputErrorImage($e);
}

/**
 * Draw circuit patterns in the background
 */
function drawCircuitPatterns($image, $padding, $width, $height, $circuitColor) {
    for ($i = 0; $i < 15; $i++) {
        // Random starting points around the edges
        $side = rand(0, 3);
        
        if ($side == 0) { // Top
            $x1 = rand($padding, $width - $padding);
            $y1 = $padding;
        } elseif ($side == 1) { // Right
            $x1 = $width - $padding;
            $y1 = rand($padding, $height - $padding);
        } elseif ($side == 2) { // Bottom
            $x1 = rand($padding, $width - $padding);
            $y1 = $height - $padding;
        } else { // Left
            $x1 = $padding;
            $y1 = rand($padding, $height - $padding);
        }
        
        // Create a circuit-like path with 90-degree angles
        $length = rand(15, 60);
        $segments = rand(1, 4);
        $x2 = $x1;
        $y2 = $y1;
        
        for ($j = 0; $j < $segments; $j++) {
            $direction = rand(0, 1);
            if ($direction == 0) { // Horizontal
                $x2 += rand(-1, 1) * $length;
                imageline($image, $x1, $y1, $x2, $y1, $circuitColor);
                $x1 = $x2;
            } else { // Vertical
                $y2 += rand(-1, 1) * $length;
                imageline($image, $x1, $y1, $x1, $y2, $circuitColor);
                $y1 = $y2;
            }
            
            // Add a circuit node/junction occasionally
            if (rand(0, 3) == 0) {
                imagefilledellipse($image, $x1, $y1, 4, 4, $circuitColor);
            }
        }
    }
}

/**
 * Process price data and extract key information
 */
function processPriceData($priceData, $now, $currentHour) {
    $prices = [];
    $periodPrices = [];
    $currentPrice = null;
    $lowestPrice = PHP_FLOAT_MAX;
    $lowestPricePeriod = null;

    // Extract price values and find current/lowest prices
    foreach ($priceData as $price) {
        $startTime = new DateTime($price['time_start']);
        $hour = (int)$startTime->format('G');
        $minute = (int)$startTime->format('i');
        $day = (int)$startTime->format('j');
        $today = (int)$now->format('j');

        // Create unique period keys for multi-day data (day*10000 + hour*100 + minute)
        $periodKey = $day * 10000 + $hour * 100 + $minute;

        $prices[] = $price['total_price'];
        $periodPrices[$periodKey] = [
            'price' => $price['total_price'],
            'time' => $startTime,
            'day' => $day,
            'hour' => $hour,
            'minute' => $minute
        ];

        // Determine if this is the current 15-minute period
        $periodStart = clone $startTime;
        $periodEnd = clone $startTime;
        $periodEnd->modify('+15 minutes');
        if ($now >= $periodStart && $now < $periodEnd) {
            $currentPrice = $price['total_price'];
        }

        // Only consider future periods for lowest price
        if ($startTime > $now && $price['total_price'] < $lowestPrice) {
            $lowestPrice = $price['total_price'];
            $lowestPricePeriod = $startTime;
        }
    }

    // If no future periods are available, use the minimum of all prices
    if ($lowestPrice === PHP_FLOAT_MAX && !empty($prices)) {
        $lowestPrice = min($prices);
        foreach ($periodPrices as $periodKey => $periodData) {
            if ($periodData['price'] == $lowestPrice) {
                $lowestPricePeriod = $periodData['time'];
                break;
            }
        }
    }

    return [$prices, $periodPrices, $currentPrice, $lowestPrice, $lowestPricePeriod];
}

/**
 * Find the cheapest consecutive 3-hour period (12 consecutive 15-minute periods)
 */
function findCheapest3HourPeriod($periodPrices, $now) {
    $cheapest3hStart = null;
    $cheapest3hPrice = PHP_FLOAT_MAX;

    // We need at least 12 periods (3 hours) of data
    if (count($periodPrices) >= 12) {
        // Get all periodKeys in order
        $periodKeys = array_keys($periodPrices);
        sort($periodKeys);

        // Look for cheapest consecutive 12-period (3-hour) period in future
        for ($i = 0; $i < count($periodKeys) - 11; $i++) {
            $periodStartTime = $periodPrices[$periodKeys[$i]]['time'];
            $totalPrice = 0;
            $isConsecutive = true;

            // Check if the next 11 periods are consecutive (15 minutes apart)
            for ($j = 0; $j < 12; $j++) {
                $currentKey = $periodKeys[$i + $j];
                $currentTime = $periodPrices[$currentKey]['time'];

                // Expected time for this position in the 3-hour window
                $expectedTime = clone $periodStartTime;
                $expectedTime->modify('+' . ($j * 15) . ' minutes');

                // Check if this period is at the expected time
                if ($currentTime->getTimestamp() != $expectedTime->getTimestamp()) {
                    $isConsecutive = false;
                    break;
                }

                $totalPrice += $periodPrices[$currentKey]['price'];
            }

            // Only consider if all 12 periods are consecutive
            if ($isConsecutive) {
                $periodAvg = $totalPrice / 12;

                // Only consider future periods
                if ($periodStartTime > $now && $periodAvg < $cheapest3hPrice) {
                    $cheapest3hPrice = $periodAvg;
                    $cheapest3hStart = $periodStartTime;
                }
            }
        }
    }

    return [$cheapest3hStart, $cheapest3hPrice];
}

/**
 * Calculate time difference and price difference
 */
function calculateTimeDifference($targetTime, $now, $currentPrice, $targetPrice) {
    $hours = 0;
    $minutes = 0;
    $priceDiff = 0;
    
    if ($targetTime && $currentPrice) {
        $minutesUntil = max(0, round(($targetTime->getTimestamp() - $now->getTimestamp()) / 60));
        $hours = floor($minutesUntil / 60);
        $minutes = $minutesUntil % 60;
        $priceDiff = $currentPrice != 0 ? round(($targetPrice - $currentPrice) / $currentPrice * 100, 1) : 0;
    }
    
    return [$hours, $minutes, $priceDiff];
}

/**
 * Load fonts and return them
 */
function loadFonts() {
    // Use tech-styled fonts for better aesthetics
    // Options include: Orbitron, Quantico, Share Tech
    // Replace "Orbitron" with whichever font you've downloaded
    $fontRegular = './fonts/Orbitron-Regular.ttf';
    $fontBold = './fonts/Orbitron-Bold.ttf';
    
    // Use default fonts if custom fonts are not available
    if (!file_exists($fontRegular)) {
        $fontRegular = './fonts/Roboto-Regular.ttf'; // Fall back to Roboto first
        if (!file_exists($fontRegular)) {
            $fontRegular = 5; // Use built-in font as last resort
        }
    }
    if (!file_exists($fontBold)) {
        $fontBold = './fonts/Roboto-Bold.ttf'; // Fall back to Roboto first
        if (!file_exists($fontBold)) {
            $fontBold = 5; // Use built-in font as last resort
        }
    }
    
    return ['regular' => $fontRegular, 'bold' => $fontBold];
}

/**
 * Draw the grid lines
 */
function drawGrid($image, $padding, $width, $graphBottom, $graphHeight, $graphTop, $gridColor) {
    // Draw subtle horizontal grid lines
    $gridSteps = 4; // Number of horizontal grid lines
    for ($i = 0; $i <= $gridSteps; $i++) {
        $y = $graphBottom - ($i * ($graphHeight / $gridSteps));
        // Draw dashed gridlines for a cooler look
        for ($x = $padding; $x < $width - $padding; $x += 10) {
            imageline($image, $x, $y, $x + 5, $y, $gridColor);
        }
    }
}

/**
 * Draw the pulsing effect along the base line
 */
function drawBaseLineEffect($image, $padding, $width, $graphBottom) {
    // Draw the base line (zero line)
    imageline($image, $padding, $graphBottom, $width - $padding, $graphBottom, imagecolorallocatealpha($image, 80, 80, 80, 80));
    
    // Draw subtle pulsing effect along the base line
    for ($x = $padding; $x < $width - $padding; $x += 2) {
        $waveHeight = 2 + sin($x / 20) * 2;
        $waveColor = imagecolorallocatealpha($image, 0, 230, 160, 110);
        imageline($image, $x, $graphBottom - $waveHeight, $x, $graphBottom + $waveHeight, $waveColor);
    }
}

/**
 * Draw the price graph
 */
function drawPriceGraph($image, $periodPrices, $currentHour, $valueRange, $padding, $graphWidth, $graphHeight,
                      $graphBottom, $graphTop, $lowThreshold, $highThreshold, $colors) {
    // Get current date/time information
    $now = new DateTime();
    $today = (int)$now->format('j');
    $tomorrow = (int)$now->format('j') + 1;

    // Organize periods into past, present, and future
    $pastPeriods = [];
    $futurePeriods = [];
    $currentPeriodData = null;

    // Sort all periods by time
    $allPeriods = [];
    foreach ($periodPrices as $periodKey => $periodData) {
        $timestamp = $periodData['time']->getTimestamp();

        $allPeriods[$timestamp] = [
            'day' => $periodData['day'],
            'hour' => $periodData['hour'],
            'minute' => $periodData['minute'],
            'periodKey' => $periodKey,
            'price' => $periodData['price'],
            'time' => $periodData['time']
        ];
    }

    // Sort by timestamp
    ksort($allPeriods);

    // Identify current period, past periods, and future periods
    foreach ($allPeriods as $timestamp => $periodData) {
        $time = $periodData['time'];

        // Check if this period contains the current time
        $periodStart = clone $periodData['time'];
        $periodEnd = clone $periodData['time'];
        $periodEnd->modify('+15 minutes');

        // Check if current time falls within this period's range
        if ($now >= $periodStart && $now < $periodEnd) {
            // This is the current period
            $currentPeriodData = $periodData;
        } elseif ($now > $periodEnd) {
            // This is a past period
            $pastPeriods[] = $periodData;
        } else {
            // This is a future period
            $futurePeriods[] = $periodData;
        }
    }
    
    // Make sure we have the current period data
    if (empty($currentPeriodData) && !empty($pastPeriods)) {
        // If current period not found, use the most recent past period as current
        $currentPeriodData = array_pop($pastPeriods);
        if (!empty($pastPeriods)) {
            array_push($pastPeriods, $currentPeriodData); // Put it back for proper order
        }
    }

    // Always show all future periods (but limit to reasonable number for display)
    $futurePeriodsToShow = min(count($futurePeriods), 48); // Max 12 hours (48 periods) of future data

    // Always show at least 8 periods (2 hours) of past data if available (plus current period)
    $minPastPeriodsToShow = min(count($pastPeriods), 8);

    // Calculate total periods to show
    $totalPeriodsToShow = $minPastPeriodsToShow + (empty($currentPeriodData) ? 0 : 1) + $futurePeriodsToShow;

    // Select which periods to show
    $periodsToShow = [];

    // Add past periods (most recent first)
    if ($minPastPeriodsToShow > 0) {
        $pastPeriodsSlice = array_slice($pastPeriods, -$minPastPeriodsToShow);
        foreach ($pastPeriodsSlice as $periodData) {
            $periodsToShow[] = $periodData;
        }
    }

    // Add current period
    if (!empty($currentPeriodData)) {
        $periodsToShow[] = $currentPeriodData;
    }

    // Add future periods (limited)
    $futurePeriodsSlice = array_slice($futurePeriods, 0, $futurePeriodsToShow);
    foreach ($futurePeriodsSlice as $periodData) {
        $periodsToShow[] = $periodData;
    }

    // Calculate block width based on total number of periods
    $totalPeriodsShown = count($periodsToShow);
    $blockWidth = $graphWidth / $totalPeriodsShown;
    
    // First pass: Draw glows for the lines
    for ($i = 0; $i < $totalPeriodsShown; $i++) {
        $periodData = $periodsToShow[$i];
        $price = $periodData['price'];
        $x1 = $padding + ($i * $blockWidth);
        $x2 = $x1 + $blockWidth;

        // Height proportional to price value
        $blockHeight = ($price / $valueRange) * $graphHeight;
        $y = $graphBottom - $blockHeight;

        // Determine color based on price threshold for glow effect
        if ($price >= $highThreshold) {
            $glowColor = imagecolorallocatealpha($image, 240, 80, 80, 100); // Red glow
        } elseif ($price <= $lowThreshold) {
            $glowColor = imagecolorallocatealpha($image, 80, 230, 120, 100); // Green glow
        } else {
            $glowColor = imagecolorallocatealpha($image, 240, 180, 40, 100); // Yellow/orange glow
        }

        // Draw a wider line for the glow effect
        for ($thick = 0; $thick < 7; $thick++) {
            imageline($image, $x1, $y + $thick - 3, $x2, $y + $thick - 3, $glowColor);
        }
    }
    
    // Draw day dividers if showing multiple days
    $dayChangeIndexes = [];
    for ($i = 1; $i < $totalPeriodsShown; $i++) {
        if ($periodsToShow[$i]['day'] != $periodsToShow[$i-1]['day']) {
            $dayChangeIndexes[] = $i;
        }
    }

    // Draw day dividers at midnight
    foreach ($dayChangeIndexes as $index) {
        $x = $padding + ($index * $blockWidth);
        // Draw a more noticeable divider for day change
        imageline($image, $x, $graphTop, $x, $graphBottom, $colors['accentColor']);
        // Add "D+1" label next to the divider
        $dayLabel = "D+1";
        if (is_string(loadFonts()['regular'])) {
            imagettftext($image, 10, 0, $x + 3, $graphTop + 12, $colors['accentColor'], loadFonts()['regular'], $dayLabel);
        } else {
            imagestring($image, 2, $x + 3, $graphTop + 2, $dayLabel, $colors['accentColor']);
        }
    }
    
    // Find the index of the current period for highlighting
    $currentPeriodIndex = -1;

    // If we have current period data, find its position in the display array
    if (!empty($currentPeriodData)) {
        foreach ($periodsToShow as $i => $periodData) {
            if ($periodData['time']->getTimestamp() === $currentPeriodData['time']->getTimestamp()) {
                $currentPeriodIndex = $i;
                break;
            }
        }
    }

    // Draw horizontal price lines for each period
    for ($i = 0; $i < $totalPeriodsShown; $i++) {
        $periodData = $periodsToShow[$i];
        $price = $periodData['price'];
        $hour = $periodData['hour'];
        $minute = $periodData['minute'];

        // Determine if this is the current period
        $isCurrentPeriod = ($i === $currentPeriodIndex);

        $x1 = $padding + ($i * $blockWidth);
        $x2 = $x1 + $blockWidth;

        // Height proportional to price value
        $blockHeight = ($price / $valueRange) * $graphHeight;
        $y = $graphBottom - $blockHeight;

        // Determine color based on price threshold
        if ($price >= $highThreshold) {
            $lineColor = $colors['highPriceColor'];
        } elseif ($price <= $lowThreshold) {
            $lineColor = $colors['lowPriceColor'];
        } else {
            $lineColor = $colors['midPriceColor'];
        }

        // Show time labels for every 4th period (every hour) at the bottom of the graph
        if ($i % 4 == 0) {
            // Position the label at the center of this period's block
            $timeLabel = sprintf("%02d:%02d", $hour, $minute);
            $labelX = $x1 + ($blockWidth / 2) - (imagefontwidth(1) * strlen($timeLabel) / 2);
            imagestring($image, 1, $labelX, $graphBottom + 2, $timeLabel, $colors['textColor']);

            // Add small price label inside the bar - only for hourly intervals to avoid cluttering
            $priceLabel = round($price);
            $labelWidth = imagefontwidth(1) * strlen($priceLabel);
            $labelHeight = imagefontheight(1);

            // Calculate position for price label - center horizontally, and position below the top line
            $labelX = $x1 + ($blockWidth / 2) - ($labelWidth / 2);

            // Only show price if the bar is tall enough
            if ($blockHeight > $labelHeight + 4) {
                $labelY = $y + 4; // Position it a few pixels below the top line
                // Draw shadow then text for better readability
                imagestring($image, 1, $labelX + 1, $labelY + 1, $priceLabel, $colors['shadowColor']);
                imagestring($image, 1, $labelX, $labelY, $priceLabel, $colors['textColor']);
            }
        }
        
        // Mark current period with a striped pattern for better visibility
        if ($isCurrentPeriod) {
            // Draw a semi-transparent filled rectangle for the current period
            imagefilledrectangle($image, $x1 + 1, $graphTop, $x2 - 1, $graphBottom,
                            imagecolorallocatealpha($image, 255, 255, 255, 115));

            // Add a diagonal stripe pattern for emphasis
            for ($j = 0; $j < $graphBottom - $graphTop; $j += 8) {
                // Draw diagonal lines for a striped pattern
                imageline($image, $x1, $graphTop + $j, $x1 + $j, $graphTop, $colors['patternColor']);
                imageline($image, $x2 - $j, $graphBottom, $x2, $graphBottom - $j, $colors['patternColor']);
            }

            // Draw accent color border
            imagerectangle($image, $x1, $graphTop, $x2, $graphBottom, $colors['accentColor']);
        }

        // Draw horizontal line for this period's price - make it thicker
        for ($thick = 0; $thick < 3; $thick++) {
            imageline($image, $x1, $y + $thick, $x2, $y + $thick, $lineColor);
        }

        // Connect to the previous period with a vertical line if needed
        if ($i > 0) {
            $prevPrice = $periodsToShow[$i-1]['price'];
            $prevY = $graphBottom - (($prevPrice / $valueRange) * $graphHeight);

            // Use the same color as the current period's line
            for ($thick = 0; $thick < 2; $thick++) {
                imageline($image, $x1, $prevY + $thick, $x1, $y + $thick, $lineColor);
            }
        }
    }
}

/**
 * Format text elements for display
 */
function formatTextElements($currentPeriodStart, $currentPeriodEnd, $currentPrice, $hoursUntilLowest, $minutesRemaining, $priceDiffLowest,
                         $hoursUntilCheapest3h, $minutesUntilCheapest3h, $priceDiff3h, $colors) {
    // Current 15-minute window and current price
    $currentTimeText = $currentPeriodStart->format('H:i') . '-' . $currentPeriodEnd->format('H:i');
    
    // Round the current price to whole number (no decimals)
    $priceValue = $currentPrice ? round($currentPrice) : '--';
    $priceUnit = "öre/kWh";
    
    // Format text for lowest price and cheapest 3h period with price differences
    // Round percentages to whole numbers (no decimals)
    $diffColorLowest = $priceDiffLowest <= 0 ? $colors['lowPriceColor'] : $colors['highPriceColor'];
    $diffTextLowest = $priceDiffLowest <= 0 ? round($priceDiffLowest) . "%" : "+" . round($priceDiffLowest) . "%";
    
    $diffColor3h = $priceDiff3h <= 0 ? $colors['lowPriceColor'] : $colors['highPriceColor'];
    $diffText3h = $priceDiff3h <= 0 ? round($priceDiff3h) . "%" : "+" . round($priceDiff3h) . "%";
    
    // Format text for bottom section - shorter labels without "Tid till"
    $lowestPriceText1 = "Lägsta pris";
    $lowestPriceText2 = sprintf("%dh %dm", $hoursUntilLowest, $minutesRemaining);
    
    $cheapest3hText1 = "Billigaste 3h";
    $cheapest3hText2 = sprintf("%dh %dm", $hoursUntilCheapest3h, $minutesUntilCheapest3h);
    
    return [
        'currentTime' => $currentTimeText,
        'priceValue' => $priceValue,
        'priceUnit' => $priceUnit,
        'lowestPriceText1' => $lowestPriceText1,
        'lowestPriceText2' => $lowestPriceText2,
        'cheapest3hText1' => $cheapest3hText1,
        'cheapest3hText2' => $cheapest3hText2,
        'diffTextLowest' => $diffTextLowest,
        'diffText3h' => $diffText3h,
        'diffColorLowest' => $diffColorLowest,
        'diffColor3h' => $diffColor3h
    ];
}

/**
 * Draw text elements based on font availability
 */
function drawTextElements($image, $text, $fonts, $width, $height, $textPadding, 
                     $bottomBoxHeight, $padding, $labelX, $valueX, $diffX, $y1, $y2, $colors, $scaleFactor = 1) {
    // Scale font sizes based on width
    $titleFontSize = round(18 * $scaleFactor);
    // Reduce the main time font size slightly to fit the longer label (e.g., 11:45-12:00)
    $mainFontSize = round(26 * $scaleFactor);
    $unitFontSize = round(16 * $scaleFactor);
    $labelFontSize = round(18 * $scaleFactor);
    
    // Scale glow and shadow effects
    $glowOffset = max(1, round(1.5 * $scaleFactor));
    $shadowOffset = max(1, round(1 * $scaleFactor));
    
    // Use TrueType fonts if available
    if (is_string($fonts['bold']) && is_string($fonts['regular']) && function_exists('imagettftext')) {
        // ===== TITLE =====
        // Title with glow effect
        for ($i = 0; $i < 360; $i += 45) {
            $xOffset = round($glowOffset * sin(deg2rad($i)));
            $yOffset = round($glowOffset * cos(deg2rad($i)));
            imagettftext($image, $titleFontSize, 0, $textPadding + $xOffset, 30 + $yOffset, $colors['glowColor'], $fonts['bold'], 'ELPRIS');
        }
        imagettftext($image, $titleFontSize, 0, $textPadding, 30, $colors['accentColor'], $fonts['bold'], 'ELPRIS');
        
        // ===== MAIN TIME AND PRICE DISPLAY =====
        // Calculate sizes for precise positioning
        $timeParts = explode('-', $text['currentTime']);
        $timeTop = $timeParts[0] ?? '';
        $timeBottom = $timeParts[1] ?? '';
        $stackedTimeFontSize = max(12, round(16 * $scaleFactor)); // roughly half size
        $timeTopBox = imagettfbbox($stackedTimeFontSize, 0, $fonts['bold'], $timeTop);
        $timeBottomBox = imagettfbbox($stackedTimeFontSize, 0, $fonts['bold'], $timeBottom);
        $timeWidth = max($timeTopBox[2] - $timeTopBox[0], $timeBottomBox[2] - $timeBottomBox[0]);
        
        $priceBox = imagettfbbox($mainFontSize, 0, $fonts['bold'], $text['priceValue']);
        $priceWidth = $priceBox[2] - $priceBox[0];
        
        $unitBox = imagettfbbox($unitFontSize, 0, $fonts['regular'], $text['priceUnit']);
        $unitWidth = $unitBox[2] - $unitBox[0];
        
        // Position calculations - more spacing between elements
        // Make sure time is right of ELPRIS
        $elprisBox = imagettfbbox(18, 0, $fonts['bold'], 'ELPRIS');
        $elprisWidth = $elprisBox[2] - $elprisBox[0];
        $elprisRight = $textPadding + $elprisWidth + 20; // ELPRIS right edge + margin
        
        // Time positioned well to the right of ELPRIS
        $timeX = $elprisRight + 20; // Reduced from 30px to 20px from ELPRIS right edge
        
        // Price positioned more to the center-right (leave more room for stacked time)
        $priceX = $width / 2 + 40;
        
        // Unit positioned further right from the price
        $unitX = $priceX + $priceWidth + 15; // Increased spacing from 5 to 15
        
        // Draw stacked time (top: start, bottom: end)
        imagettftext($image, $stackedTimeFontSize, 0, $timeX + 1, 30 + 1, $colors['shadowColor'], $fonts['bold'], $timeTop);
        imagettftext($image, $stackedTimeFontSize, 0, $timeX, 30, $colors['accentColor'], $fonts['bold'], $timeTop);
        imagettftext($image, $stackedTimeFontSize, 0, $timeX + 1, 48 + 1, $colors['shadowColor'], $fonts['bold'], $timeBottom);
        imagettftext($image, $stackedTimeFontSize, 0, $timeX, 48, $colors['accentColor'], $fonts['bold'], $timeBottom);
        
        // Draw price with glow effect (white color)
        imagettftext($image, $mainFontSize, 0, $priceX + 1, 45 + 1, $colors['shadowColor'], $fonts['bold'], $text['priceValue']);
        imagettftext($image, $mainFontSize, 0, $priceX, 45, $colors['textColor'], $fonts['bold'], $text['priceValue']);
        
        // Draw unit
        imagettftext($image, $unitFontSize, 0, $unitX + 1, 45 + 1, $colors['shadowColor'], $fonts['regular'], $text['priceUnit']);
        imagettftext($image, $unitFontSize, 0, $unitX, 45, imagecolorallocatealpha($image, 200, 200, 200, 0), $fonts['regular'], $text['priceUnit']);
        
        // ===== BOTTOM SECTION =====
        // Draw a subtle horizontal divider
        imageline($image, $padding, $height - $bottomBoxHeight, $width - $padding, $height - $bottomBoxHeight, $colors['dividerColor']);
        
        // Calculate widths for precise positioning
        $lowestLabelBox = imagettfbbox($labelFontSize, 0, $fonts['regular'], $text['lowestPriceText1']);
        $lowestLabelWidth = $lowestLabelBox[2] - $lowestLabelBox[0];
        
        $cheapest3hLabelBox = imagettfbbox($labelFontSize, 0, $fonts['regular'], $text['cheapest3hText1']);
        $cheapest3hLabelWidth = $cheapest3hLabelBox[2] - $cheapest3hLabelBox[0];
        
        // Make sure both labels align at the same point
        $labelMaxWidth = max($lowestLabelWidth, $cheapest3hLabelWidth);
        
        // Value column starts closer to the label since we have shorter labels now
        $valueColX = $labelX + $labelMaxWidth + round(15 * $scaleFactor); // Scale the spacing
        
        // Fixed position for percentage column - right aligned
        $diffColX = $width - $padding - round(50 * $scaleFactor); // Scale the spacing
        
        // Draw lowest price row
        imagettftext($image, $labelFontSize, 0, $labelX + $shadowOffset, $y1 + $shadowOffset, $colors['shadowColor'], $fonts['regular'], $text['lowestPriceText1']);
        imagettftext($image, $labelFontSize, 0, $labelX, $y1, $colors['textColor'], $fonts['regular'], $text['lowestPriceText1']);
        
        imagettftext($image, $labelFontSize, 0, $valueColX + $shadowOffset, $y1 + $shadowOffset, $colors['shadowColor'], $fonts['bold'], $text['lowestPriceText2']);
        imagettftext($image, $labelFontSize, 0, $valueColX, $y1, $colors['textColor'], $fonts['bold'], $text['lowestPriceText2']);
        
        // Right align percentage
        $diffLowestBox = imagettfbbox($labelFontSize, 0, $fonts['bold'], $text['diffTextLowest']);
        $diffLowestWidth = $diffLowestBox[2] - $diffLowestBox[0];
        $diffLowestX = $diffColX - $diffLowestWidth;
        
        imagettftext($image, $labelFontSize, 0, $diffLowestX + $shadowOffset, $y1 + $shadowOffset, $colors['shadowColor'], $fonts['bold'], $text['diffTextLowest']);
        imagettftext($image, $labelFontSize, 0, $diffLowestX, $y1, $text['diffColorLowest'], $fonts['bold'], $text['diffTextLowest']);
        
        // Draw cheapest 3h row
        imagettftext($image, $labelFontSize, 0, $labelX + $shadowOffset, $y2 + $shadowOffset, $colors['shadowColor'], $fonts['regular'], $text['cheapest3hText1']);
        imagettftext($image, $labelFontSize, 0, $labelX, $y2, $colors['textColor'], $fonts['regular'], $text['cheapest3hText1']);
        
        imagettftext($image, $labelFontSize, 0, $valueColX + $shadowOffset, $y2 + $shadowOffset, $colors['shadowColor'], $fonts['bold'], $text['cheapest3hText2']);
        imagettftext($image, $labelFontSize, 0, $valueColX, $y2, $colors['textColor'], $fonts['bold'], $text['cheapest3hText2']);
        
        // Right align percentage
        $diff3hBox = imagettfbbox($labelFontSize, 0, $fonts['bold'], $text['diffText3h']);
        $diff3hWidth = $diff3hBox[2] - $diff3hBox[0];
        $diff3hX = $diffColX - $diff3hWidth;
        
        imagettftext($image, $labelFontSize, 0, $diff3hX + $shadowOffset, $y2 + $shadowOffset, $colors['shadowColor'], $fonts['bold'], $text['diffText3h']);
        imagettftext($image, $labelFontSize, 0, $diff3hX, $y2, $text['diffColor3h'], $fonts['bold'], $text['diffText3h']);
        
    } else {
        // Fallback implementation for non-TTF fonts
        // Scale font sizes based on width
        $titleFont = min(5, max(2, round(5 * $scaleFactor))); 
        $mainFont = min(5, max(2, round(4 * $scaleFactor))); // Slight reduction for longer time label
        $unitFont = min(4, max(1, round(3 * $scaleFactor)));
        $labelFont = min(5, max(2, round(4 * $scaleFactor)));
        
        imagestring($image, $titleFont, $textPadding, 15, 'ELPRIS', $colors['accentColor']);
        
        // Calculate positioned based on requested layout
        // Make sure time is right of ELPRIS
        $elprisWidth = imagefontwidth($titleFont) * strlen('ELPRIS');
        $elprisRight = $textPadding + $elprisWidth + round(20 * $scaleFactor);
        
        $timeX = $elprisRight + round(20 * $scaleFactor); // Reduced spacing
        $priceX = $width / 2 + round(20 * $scaleFactor); // Slightly closer since time text is longer
        $unitX = $priceX + (strlen($text['priceValue']) * imagefontwidth($mainFont)) + round(15 * $scaleFactor);
        
        // Draw stacked time using bitmap fonts
        $timeParts = explode('-', $text['currentTime']);
        $timeTop = $timeParts[0] ?? '';
        $timeBottom = $timeParts[1] ?? '';
        $stackedMainFont = max(1, $mainFont - 1); // smaller for stack
        imagestring($image, $stackedMainFont, $timeX, 10, $timeTop, $colors['accentColor']);
        imagestring($image, $stackedMainFont, $timeX, 28, $timeBottom, $colors['accentColor']);
        imagestring($image, $mainFont, $priceX, 20, $text['priceValue'], $colors['textColor']);
        imagestring($image, $unitFont, $unitX, 25, $text['priceUnit'], imagecolorallocate($image, 200, 200, 200));
        
        // Draw a subtle divider
        imageline($image, $padding, $height - $bottomBoxHeight, $width - $padding, $height - $bottomBoxHeight, $colors['dividerColor']);
        
        // Draw bottom section text
        imagestring($image, $labelFont, $labelX, $y1, $text['lowestPriceText1'], $colors['textColor']);
        imagestring($image, $labelFont, $labelX, $y2, $text['cheapest3hText1'], $colors['textColor']);
        
        $valueColX = $labelX + round(120 * $scaleFactor); // Scale spacing
        imagestring($image, $labelFont, $valueColX, $y1, $text['lowestPriceText2'], $colors['textColor']);
        imagestring($image, $labelFont, $valueColX, $y2, $text['cheapest3hText2'], $colors['textColor']);
        
        // Calculate positions for right-aligned percentages
        $diffColX = $width - $padding - round(50 * $scaleFactor);
        $diffLowestWidth = imagefontwidth($labelFont) * strlen($text['diffTextLowest']);
        $diff3hWidth = imagefontwidth($labelFont) * strlen($text['diffText3h']);
        
        imagestring($image, $labelFont, $diffColX - $diffLowestWidth, $y1, $text['diffTextLowest'], $text['diffColorLowest']);
        imagestring($image, $labelFont, $diffColX - $diff3hWidth, $y2, $text['diffText3h'], $text['diffColor3h']);
    }
}

/**
 * Draw decorative elements in the four corners
 */
function drawCornerElements($image, $padding, $width, $height, $circuitColor, $scaleFactor = 1) {
    // Scale corner elements
    $cornerSize = round(40 * $scaleFactor);
    $dotSize = max(3, round(6 * $scaleFactor));
    
    // Corner positions
    $corners = [
        ['x' => $padding, 'y' => $padding], // Top left
        ['x' => $width - $padding, 'y' => $padding], // Top right
        ['x' => $padding, 'y' => $height - $padding], // Bottom left
        ['x' => $width - $padding, 'y' => $height - $padding] // Bottom right
    ];
    
    foreach ($corners as $corner) {
        $x = $corner['x'];
        $y = $corner['y'];
        $xDirection = ($x == $padding) ? 1 : -1; // Left-to-right or right-to-left
        $yDirection = ($y == $padding) ? 1 : -1; // Top-to-bottom or bottom-to-top
        
        // Draw horizontal and vertical lines
        imageline($image, $x, $y, $x + ($xDirection * $cornerSize), $y, $circuitColor);
        imageline($image, $x, $y, $x, $y + ($yDirection * $cornerSize), $circuitColor);
        imagefilledellipse($image, $x, $y, $dotSize, $dotSize, $circuitColor);
    }
}

/**
 * Output an error image in case of exception
 */
function outputErrorImage($e) {
    ob_end_clean();
    header('Content-Type: image/png');
    
    $errorImage = imagecreatetruecolor(600, 300);
    $bgColor = imagecolorallocate($errorImage, 26, 26, 26);
    $textColor = imagecolorallocate($errorImage, 255, 82, 82);
    $whiteColor = imagecolorallocate($errorImage, 255, 255, 255);
    
    imagefill($errorImage, 0, 0, $bgColor);
    imagestring($errorImage, 5, 20, 20, "Error generating widget", $textColor);
    imagestring($errorImage, 4, 20, 50, $e->getMessage(), $whiteColor);
    imagestring($errorImage, 3, 20, 80, "Check server logs for details", $whiteColor);
    
    imagepng($errorImage);
    imagedestroy($errorImage);
    
    // Log the error
    error_log("Widget error: " . $e->getMessage());
} 