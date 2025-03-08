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
$width = 550;
$height = 200;
$padding = 25;
$graphPadding = 10;
$textPadding = 15;
$topBoxHeight = 50;
$bottomBoxHeight = 80;

// Calculate graph dimensions - moved here to avoid recalculating
$graphWidth = $width - (2 * $graphPadding);
$graphHeight = $height - $topBoxHeight - $bottomBoxHeight - 10;
$graphTop = $topBoxHeight + 5;
$graphBottom = $height - $bottomBoxHeight - 5;

// Text position calculations - moved here so they're defined once
$labelX = $padding + 10;
$valueX = $labelX + 220;
$diffX = $valueX + 100;
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
    
    // Try to get tomorrow's price data
    $tomorrowData = fetchPriceData(date('Y/m-d', strtotime('+1 day')), $area);
    if (is_array($tomorrowData) && !isset($tomorrowData['error'])) {
        $priceData = array_merge($priceData, $tomorrowData);
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
    list($prices, $hourPrices, $currentPrice, $lowestPrice, $lowestPriceHour) = processPriceData($priceData, $now, $currentHour);
    
    // Find cheapest 3-hour period
    list($cheapest3hStart, $cheapest3hPrice) = findCheapest3HourPeriod($hourPrices, $now);
    
    // Calculate time differences and price differences
    list($hoursUntilLowest, $minutesRemaining, $priceDiffLowest) = calculateTimeDifference($lowestPriceHour, $now, $currentPrice, $lowestPrice);
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
        drawPriceGraph($image, $hourPrices, $currentHour, $valueRange, $graphPadding, $graphWidth, $graphHeight, 
                        $graphBottom, $graphTop, $lowThreshold, $highThreshold, $colors);
    }
    
    // Format text elements
    $textElements = formatTextElements($currentHour, $currentPrice, 
                                      $hoursUntilLowest, $minutesRemaining, $priceDiffLowest,
                                      $hoursUntilCheapest3h, $minutesUntilCheapest3h, $priceDiff3h,
                                      $colors);
    
    // Draw text elements based on font availability
    drawTextElements($image, $textElements, $fonts, $width, $height, $textPadding, 
                     $bottomBoxHeight, $padding, $labelX, $valueX, $diffX, $y1, $y2, $colors);
    
    // Draw decorative corner elements
    drawCornerElements($image, $padding, $width, $height, $colors['circuitColor']);
    
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
    $hourPrices = [];
    $currentPrice = null;
    $lowestPrice = PHP_FLOAT_MAX;
    $lowestPriceHour = null;
    
    // Extract price values and find current/lowest prices
    foreach ($priceData as $price) {
        $startTime = new DateTime($price['time_start']);
        $hour = (int)$startTime->format('G');
        $day = (int)$startTime->format('j');
        $today = (int)$now->format('j');
        
        // Create unique hour keys for multi-day data (hour+day*100)
        $hourKey = $hour + ($day * 100);
        
        $prices[] = $price['total_price'];
        $hourPrices[$hourKey] = [
            'price' => $price['total_price'],
            'time' => $startTime
        ];
        
        // Determine if this is the current hour
        $isSameDay = $day === $today;
        $isSameHour = $hour === $currentHour;
        if ($isSameDay && $isSameHour) {
            $currentPrice = $price['total_price'];
        }
        
        // Only consider future hours for lowest price
        if ($startTime > $now && $price['total_price'] < $lowestPrice) {
            $lowestPrice = $price['total_price'];
            $lowestPriceHour = $startTime;
        }
    }
    
    // If no future hours are available, use the minimum of all prices
    if ($lowestPrice === PHP_FLOAT_MAX && !empty($prices)) {
        $lowestPrice = min($prices);
        foreach ($hourPrices as $hourKey => $hourData) {
            if ($hourData['price'] == $lowestPrice) {
                $lowestPriceHour = $hourData['time'];
                break;
            }
        }
    }
    
    // Make sure we have a complete 24-hour set for today for display
    if (!empty($prices)) {
        $avgPrice = array_sum($prices) / count($prices);
        $today = (int)$now->format('j');
        
        for ($h = 0; $h < 24; $h++) {
            $hourKey = $h + ($today * 100);
            if (!isset($hourPrices[$hourKey])) {
                $hourPrices[$hourKey] = [
                    'price' => $avgPrice,
                    'time' => (new DateTime())->setTime($h, 0)
                ];
            }
        }
    }
    
    return [$prices, $hourPrices, $currentPrice, $lowestPrice, $lowestPriceHour];
}

/**
 * Find the cheapest consecutive 3-hour period
 */
function findCheapest3HourPeriod($hourPrices, $now) {
    $cheapest3hStart = null;
    $cheapest3hPrice = PHP_FLOAT_MAX;
    
    // We need at least 3 hours of data
    if (count($hourPrices) >= 3) {
        // Get all hourKeys in order
        $hourKeys = array_keys($hourPrices);
        sort($hourKeys);
        
        // Look for cheapest consecutive 3-hour period in future
        for ($i = 0; $i < count($hourKeys) - 2; $i++) {
            $key1 = $hourKeys[$i];
            $key2 = $hourKeys[$i + 1];
            $key3 = $hourKeys[$i + 2];
            
            // Check if these are consecutive hours (could be across days)
            $time1 = $hourPrices[$key1]['time'];
            $time2 = $hourPrices[$key2]['time'];
            $time3 = $hourPrices[$key3]['time'];
            
            $diff1 = ($time2->getTimestamp() - $time1->getTimestamp()) / 3600;
            $diff2 = ($time3->getTimestamp() - $time2->getTimestamp()) / 3600;
            
            // Only consider if they're consecutive hours (1 hour apart)
            if ($diff1 == 1 && $diff2 == 1) {
                $periodAvg = ($hourPrices[$key1]['price'] + $hourPrices[$key2]['price'] + $hourPrices[$key3]['price']) / 3;
                
                // Only consider future periods
                if ($time1 > $now && $periodAvg < $cheapest3hPrice) {
                    $cheapest3hPrice = $periodAvg;
                    $cheapest3hStart = $time1;
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
function drawPriceGraph($image, $hourPrices, $currentHour, $valueRange, $padding, $graphWidth, $graphHeight, 
                      $graphBottom, $graphTop, $lowThreshold, $highThreshold, $colors) {
    // Get current date/time information
    $now = new DateTime();
    $today = (int)$now->format('j');
    $tomorrow = (int)$now->format('j') + 1;
    
    // Organize hours into past, present, and future
    $pastHours = [];
    $futureHours = [];
    $currentHourData = null;
    
    // Sort all hours by time
    $allHours = [];
    foreach ($hourPrices as $hourKey => $hourData) {
        $day = floor($hourKey / 100);
        $hour = $hourKey % 100;
        $timestamp = $hourData['time']->getTimestamp();
        
        $allHours[$timestamp] = [
            'day' => $day,
            'hour' => $hour,
            'hourKey' => $hourKey,
            'price' => $hourData['price'],
            'time' => $hourData['time']
        ];
    }
    
    // Sort by timestamp
    ksort($allHours);
    
    // Identify current hour, past hours, and future hours
    foreach ($allHours as $timestamp => $hourData) {
        $time = $hourData['time'];
        
        // The exact current hour starts at the current hour and extends to the next hour
        $hourStart = clone $hourData['time']; // Time at the start of this hour
        $hourEnd = clone $hourData['time'];
        $hourEnd->modify('+1 hour'); // Time at the end of this hour
        
        // Check if current time falls within this hour's range
        if ($now >= $hourStart && $now < $hourEnd) {
            // This is the current hour
            $currentHourData = $hourData;
        } elseif ($now > $hourEnd) {
            // This is a past hour
            $pastHours[] = $hourData;
        } else {
            // This is a future hour
            $futureHours[] = $hourData;
        }
    }
    
    // Make sure we have the current hour data
    if (empty($currentHourData) && !empty($pastHours)) {
        // If current hour not found, use the most recent past hour as current
        $currentHourData = array_pop($pastHours);
        if (!empty($pastHours)) {
            array_push($pastHours, $currentHourData); // Put it back for proper order
        }
    }
    
    // Always show all future hours
    $futureHoursToShow = count($futureHours);
    
    // Always show at least 2 hours of past data if available (plus current hour)
    $minPastHoursToShow = min(count($pastHours), 2);
    
    // Calculate total hours to show
    $totalHoursToShow = $minPastHoursToShow + (empty($currentHourData) ? 0 : 1) + $futureHoursToShow;
    
    // Select which hours to show
    $hoursToShow = [];
    
    // Add past hours (most recent first)
    if ($minPastHoursToShow > 0) {
        $pastHoursSlice = array_slice($pastHours, -$minPastHoursToShow);
        foreach ($pastHoursSlice as $hourData) {
            $hoursToShow[] = $hourData;
        }
    }
    
    // Add current hour
    if (!empty($currentHourData)) {
        $hoursToShow[] = $currentHourData;
    }
    
    // Add ALL future hours
    foreach ($futureHours as $hourData) {
        $hoursToShow[] = $hourData;
    }
    
    // Calculate block width based on total number of hours
    $totalHoursShown = count($hoursToShow);
    $blockWidth = $graphWidth / $totalHoursShown;
    
    // First pass: Draw glows for the lines
    for ($i = 0; $i < $totalHoursShown; $i++) {
        $hourData = $hoursToShow[$i];
        $price = $hourData['price'];
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
    for ($i = 1; $i < $totalHoursShown; $i++) {
        if ($hoursToShow[$i]['day'] != $hoursToShow[$i-1]['day']) {
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
    
    // Find the index of the current hour for highlighting
    $currentHourIndex = -1;
    
    // If we have current hour data, find its position in the display array
    if (!empty($currentHourData)) {
        foreach ($hoursToShow as $i => $hourData) {
            if ($hourData['time']->getTimestamp() === $currentHourData['time']->getTimestamp()) {
                $currentHourIndex = $i;
                break;
            }
        }
    }
    
    // Draw horizontal price lines for each hour
    for ($i = 0; $i < $totalHoursShown; $i++) {
        $hourData = $hoursToShow[$i];
        $price = $hourData['price'];
        $hour = $hourData['hour'];
        
        // Determine if this is the current hour (either exact match or best approximation)
        $isCurrentHour = ($i === $currentHourIndex) || 
                         ($currentHourIndex === -1 && $i === array_search($currentHourData, $hoursToShow));
        
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
        
        // Show hour labels for every other hour at the bottom of the graph
        if ($hour % 2 == 0) {
            // Position the label at the center of this hour's block
            $hourLabel = sprintf("%02d", $hour);
            $labelX = $x1 + ($blockWidth / 2) - (imagefontwidth(1) * strlen($hourLabel) / 2);
            imagestring($image, 1, $labelX, $graphBottom + 2, $hourLabel, $colors['textColor']);
            
            // Add small price label inside the bar - only for even hours to avoid cluttering
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
        
        // Mark current hour with a striped pattern for better visibility
        if ($isCurrentHour) {
            // Draw a semi-transparent filled rectangle for the current hour
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
        
        // Draw horizontal line for this hour's price - make it thicker
        for ($thick = 0; $thick < 3; $thick++) {
            imageline($image, $x1, $y + $thick, $x2, $y + $thick, $lineColor);
        }
        
        // Connect to the previous hour with a vertical line if needed
        if ($i > 0) {
            $prevPrice = $hoursToShow[$i-1]['price'];
            $prevY = $graphBottom - (($prevPrice / $valueRange) * $graphHeight);
            
            // Use the same color as the current hour's line
            for ($thick = 0; $thick < 2; $thick++) {
                imageline($image, $x1, $prevY + $thick, $x1, $y + $thick, $lineColor);
            }
        }
    }
}

/**
 * Format text elements for display
 */
function formatTextElements($currentHour, $currentPrice, $hoursUntilLowest, $minutesRemaining, $priceDiffLowest,
                         $hoursUntilCheapest3h, $minutesUntilCheapest3h, $priceDiff3h, $colors) {
    // Current hour and current price
    $nextHour = ($currentHour + 1) % 24;
    $currentTimeText = sprintf("%02d-%02d", $currentHour, $nextHour);
    
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
                       $bottomBoxHeight, $padding, $labelX, $valueX, $diffX, $y1, $y2, $colors) {
    // Use TrueType fonts if available
    if (is_string($fonts['bold']) && is_string($fonts['regular']) && function_exists('imagettftext')) {
        // ===== TITLE =====
        // Title with glow effect
        for ($i = 0; $i < 360; $i += 45) {
            $xOffset = round(1.5 * sin(deg2rad($i)));
            $yOffset = round(1.5 * cos(deg2rad($i)));
            imagettftext($image, 18, 0, $textPadding + $xOffset, 30 + $yOffset, $colors['glowColor'], $fonts['bold'], 'ELPRIS');
        }
        imagettftext($image, 18, 0, $textPadding, 30, $colors['accentColor'], $fonts['bold'], 'ELPRIS');
        
        // ===== MAIN TIME AND PRICE DISPLAY =====
        // Calculate sizes for precise positioning
        $timeBox = imagettfbbox(32, 0, $fonts['bold'], $text['currentTime']);
        $timeWidth = $timeBox[2] - $timeBox[0];
        
        $priceBox = imagettfbbox(32, 0, $fonts['bold'], $text['priceValue']);
        $priceWidth = $priceBox[2] - $priceBox[0];
        
        $unitBox = imagettfbbox(16, 0, $fonts['regular'], $text['priceUnit']);
        $unitWidth = $unitBox[2] - $unitBox[0];
        
        // Position calculations - more spacing between elements
        // Make sure time is right of ELPRIS
        $elprisBox = imagettfbbox(18, 0, $fonts['bold'], 'ELPRIS');
        $elprisWidth = $elprisBox[2] - $elprisBox[0];
        $elprisRight = $textPadding + $elprisWidth + 20; // ELPRIS right edge + margin
        
        // Time positioned well to the right of ELPRIS
        $timeX = $elprisRight + 20; // Reduced from 30px to 20px from ELPRIS right edge
        
        // Price positioned more to the center-right
        $priceX = $width / 2 + 30; // Increased from 10 to 30 to add more space between time and price
        
        // Unit positioned further right from the price
        $unitX = $priceX + $priceWidth + 15; // Increased spacing from 5 to 15
        
        // Draw time with glow effect (accent color)
        imagettftext($image, 32, 0, $timeX + 1, 45 + 1, $colors['shadowColor'], $fonts['bold'], $text['currentTime']);
        imagettftext($image, 32, 0, $timeX, 45, $colors['accentColor'], $fonts['bold'], $text['currentTime']);
        
        // Draw price with glow effect (white color)
        imagettftext($image, 32, 0, $priceX + 1, 45 + 1, $colors['shadowColor'], $fonts['bold'], $text['priceValue']);
        imagettftext($image, 32, 0, $priceX, 45, $colors['textColor'], $fonts['bold'], $text['priceValue']);
        
        // Draw unit
        imagettftext($image, 16, 0, $unitX + 1, 45 + 1, $colors['shadowColor'], $fonts['regular'], $text['priceUnit']);
        imagettftext($image, 16, 0, $unitX, 45, imagecolorallocatealpha($image, 200, 200, 200, 0), $fonts['regular'], $text['priceUnit']);
        
        // ===== BOTTOM SECTION =====
        // Draw a subtle horizontal divider
        imageline($image, $padding, $height - $bottomBoxHeight, $width - $padding, $height - $bottomBoxHeight, $colors['dividerColor']);
        
        // Calculate widths for precise positioning
        $lowestLabelBox = imagettfbbox(18, 0, $fonts['regular'], $text['lowestPriceText1']);
        $lowestLabelWidth = $lowestLabelBox[2] - $lowestLabelBox[0];
        
        $cheapest3hLabelBox = imagettfbbox(18, 0, $fonts['regular'], $text['cheapest3hText1']);
        $cheapest3hLabelWidth = $cheapest3hLabelBox[2] - $cheapest3hLabelBox[0];
        
        // Make sure both labels align at the same point
        $labelMaxWidth = max($lowestLabelWidth, $cheapest3hLabelWidth);
        
        // Value column starts closer to the label since we have shorter labels now
        $valueColX = $labelX + $labelMaxWidth + 15; // Reduced from 20 to 15
        
        // Fixed position for percentage column - right aligned
        $diffColX = $width - $padding - 50; // 50px from right edge for percentages
        
        // Draw lowest price row
        imagettftext($image, 18, 0, $labelX + 1, $y1 + 1, $colors['shadowColor'], $fonts['regular'], $text['lowestPriceText1']);
        imagettftext($image, 18, 0, $labelX, $y1, $colors['textColor'], $fonts['regular'], $text['lowestPriceText1']);
        
        imagettftext($image, 18, 0, $valueColX + 1, $y1 + 1, $colors['shadowColor'], $fonts['bold'], $text['lowestPriceText2']);
        imagettftext($image, 18, 0, $valueColX, $y1, $colors['textColor'], $fonts['bold'], $text['lowestPriceText2']);
        
        // Right align percentage
        $diffLowestBox = imagettfbbox(18, 0, $fonts['bold'], $text['diffTextLowest']);
        $diffLowestWidth = $diffLowestBox[2] - $diffLowestBox[0];
        $diffLowestX = $diffColX - $diffLowestWidth;
        
        imagettftext($image, 18, 0, $diffLowestX + 1, $y1 + 1, $colors['shadowColor'], $fonts['bold'], $text['diffTextLowest']);
        imagettftext($image, 18, 0, $diffLowestX, $y1, $text['diffColorLowest'], $fonts['bold'], $text['diffTextLowest']);
        
        // Draw cheapest 3h row
        imagettftext($image, 18, 0, $labelX + 1, $y2 + 1, $colors['shadowColor'], $fonts['regular'], $text['cheapest3hText1']);
        imagettftext($image, 18, 0, $labelX, $y2, $colors['textColor'], $fonts['regular'], $text['cheapest3hText1']);
        
        imagettftext($image, 18, 0, $valueColX + 1, $y2 + 1, $colors['shadowColor'], $fonts['bold'], $text['cheapest3hText2']);
        imagettftext($image, 18, 0, $valueColX, $y2, $colors['textColor'], $fonts['bold'], $text['cheapest3hText2']);
        
        // Right align percentage
        $diff3hBox = imagettfbbox(18, 0, $fonts['bold'], $text['diffText3h']);
        $diff3hWidth = $diff3hBox[2] - $diff3hBox[0];
        $diff3hX = $diffColX - $diff3hWidth;
        
        imagettftext($image, 18, 0, $diff3hX + 1, $y2 + 1, $colors['shadowColor'], $fonts['bold'], $text['diffText3h']);
        imagettftext($image, 18, 0, $diff3hX, $y2, $text['diffColor3h'], $fonts['bold'], $text['diffText3h']);
        
    } else {
        // Fallback implementation for non-TTF fonts
        // (keeping this simple as we're primarily focused on TTF fonts)
        imagestring($image, 5, $textPadding, 15, 'ELPRIS', $colors['accentColor']);
        
        // Calculate positioned based on requested layout
        // Make sure time is right of ELPRIS
        $elprisWidth = imagefontwidth(5) * strlen('ELPRIS');
        $elprisRight = $textPadding + $elprisWidth + 20;
        
        $timeX = $elprisRight + 20; // Reduced from 30px to 20px from ELPRIS right edge
        $priceX = $width / 2 + 30; // Increased from 10 to 30 to add more space between time and price
        $unitX = $priceX + (strlen($text['priceValue']) * imagefontwidth(5)) + 15;
        
        imagestring($image, 5, $timeX, 20, $text['currentTime'], $colors['accentColor']);
        imagestring($image, 5, $priceX, 20, $text['priceValue'], $colors['textColor']);
        imagestring($image, 3, $unitX, 25, $text['priceUnit'], imagecolorallocate($image, 200, 200, 200));
        
        // Draw a subtle divider
        imageline($image, $padding, $height - $bottomBoxHeight, $width - $padding, $height - $bottomBoxHeight, $colors['dividerColor']);
        
        // Draw bottom section text
        imagestring($image, 4, $labelX, $y1, $text['lowestPriceText1'], $colors['textColor']);
        imagestring($image, 4, $labelX, $y2, $text['cheapest3hText1'], $colors['textColor']);
        
        $valueColX = $labelX + 120; // Reduced from 180 since labels are shorter
        imagestring($image, 4, $valueColX, $y1, $text['lowestPriceText2'], $colors['textColor']);
        imagestring($image, 4, $valueColX, $y2, $text['cheapest3hText2'], $colors['textColor']);
        
        // Calculate positions for right-aligned percentages
        $diffColX = $width - $padding - 50;
        $diffLowestWidth = imagefontwidth(4) * strlen($text['diffTextLowest']);
        $diff3hWidth = imagefontwidth(4) * strlen($text['diffText3h']);
        
        imagestring($image, 4, $diffColX - $diffLowestWidth, $y1, $text['diffTextLowest'], $text['diffColorLowest']);
        imagestring($image, 4, $diffColX - $diff3hWidth, $y2, $text['diffText3h'], $text['diffColor3h']);
    }
}

/**
 * Draw decorative elements in the four corners
 */
function drawCornerElements($image, $padding, $width, $height, $circuitColor) {
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
        imageline($image, $x, $y, $x + ($xDirection * 40), $y, $circuitColor);
        imageline($image, $x, $y, $x, $y + ($yDirection * 40), $circuitColor);
        imagefilledellipse($image, $x, $y, 6, 6, $circuitColor);
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