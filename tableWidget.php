<?php
// Start output buffering to catch any errors or unintended output
ob_start();

require_once 'config.php';
require_once 'api.php';

// Set proper content type for PNG image
header('Content-Type: image/png');

// Turn off error reporting to prevent JSON errors from being included in output
error_reporting(0);
ini_set('display_errors', 0);

// Widget configuration
// Get width and height from query parameters or use defaults
// Usage examples:
// tableWidget.php                   - Default size (500x800)
// tableWidget.php?width=600         - Custom width (600x800) 
// tableWidget.php?height=1000       - Custom height (500x1000)
// tableWidget.php?width=600&height=1000 - Custom dimensions (600x1000)
$width = isset($_GET['width']) ? intval($_GET['width']) : 500;
$height = isset($_GET['height']) ? intval($_GET['height']) : 800;

// Set minimum dimensions to avoid rendering issues
$width = max(350, min(1200, $width));  // Min: 350px, Max: 1200px
$height = max(600, min(1600, $height)); // Min: 600px, Max: 1600px

// Calculate scaling factor based on width (reference width is 500px)
$scaleFactor = $width / 500;

// Define padding and layout variables
$padding = round(20 * $scaleFactor);
$headerHeight = round(80 * $scaleFactor);

try {
    // Fetch today's price data and tomorrow's data
    $area = DEFAULT_AREA;
    $priceData = [];
    
    // Get yesterday's price data
    $yesterdayData = fetchPriceData(date('Y/m-d', strtotime('-1 day')), $area);
    if (is_array($yesterdayData) && !isset($yesterdayData['error'])) {
        $priceData = array_merge($priceData, $yesterdayData);
    }
    
    // Get today's price data
    $todayData = fetchPriceData(date('Y/m-d'), $area);
    if (is_array($todayData) && !isset($todayData['error'])) {
        $priceData = array_merge($priceData, $todayData);
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
    
    // Calculate the average price across all days
    $averagePrice = calculateAveragePrice($priceData);
    
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
        'background' => imagecolorallocatealpha($image, 30, 30, 35, 0),
        'headerBg' => imagecolorallocatealpha($image, 20, 20, 25, 0),
        'textColor' => imagecolorallocate($image, 255, 255, 255),
        'accentColor' => imagecolorallocate($image, 0, 230, 150),
        'lightText' => imagecolorallocate($image, 200, 200, 200),
        'gridColor' => imagecolorallocatealpha($image, 80, 80, 80, 40),
        'rowBgEven' => imagecolorallocatealpha($image, 40, 40, 45, 0),
        'rowBgOdd' => imagecolorallocatealpha($image, 50, 50, 55, 0),
        'highPriceColor' => imagecolorallocate($image, 240, 80, 80),
        'midPriceColor' => imagecolorallocate($image, 240, 180, 40),
        'lowPriceColor' => imagecolorallocate($image, 80, 230, 120),
        'shadowColor' => imagecolorallocatealpha($image, 0, 0, 0, 60),
        'borderColor' => imagecolorallocate($image, 100, 100, 100),
        'headerText' => imagecolorallocate($image, 220, 220, 220),
        'currentRowBg' => imagecolorallocatealpha($image, 0, 80, 60, 80)
    ];
    
    // Fill the background
    imagefill($image, 0, 0, $colors['background']);
    
    // Enable alpha blending for drawing
    imagealphablending($image, true);
    
    // Process the price data
    $now = new DateTime();
    $currentHour = (int)$now->format('G');
    
    // Sort the price data by time to ensure chronological order
    usort($priceData, function($a, $b) {
        return strtotime($a['time_start']) - strtotime($b['time_start']);
    });
    
    // Get current price data and organize future prices
    $currentPrice = null;
    $futureHours = [];
    $cheapestHours = [];
    
    // Process all price data
    foreach ($priceData as $price) {
        $startTime = new DateTime($price['time_start']);
        $hour = (int)$startTime->format('G');
        $day = (int)$startTime->format('j');
        $month = (int)$startTime->format('n');
        $year = (int)$startTime->format('Y');
        $today = (int)$now->format('j');
        $thisMonth = (int)$now->format('n');
        $thisYear = (int)$now->format('Y');
        $timestamp = $startTime->getTimestamp();
        
        // STRICT DATE FILTERING - Only include today and tomorrow
        // Only process data from current year and month
        if ($year != $thisYear || $month != $thisMonth) {
            continue;
        }
        
        // Only process today and tomorrow
        $tomorrow = $today + 1;
        $isToday = ($day == $today);
        $isTomorrow = ($day == $tomorrow);
        
        // Handle month boundary cases
        $daysInMonth = (int)$now->format('t');
        if ($today == $daysInMonth && $day == 1) {
            $isTomorrow = true; // First day of next month
        }
        
        // Skip if not today or tomorrow
        if (!$isToday && !$isTomorrow) {
            continue;
        }
        
        // Determine if this is the current hour
        $isSameDay = $day === $today;
        $isSameHour = $hour === $currentHour;
        
        if ($isSameDay && $isSameHour) {
            $currentPrice = [
                'price' => $price['total_price'],
                'time' => $startTime,
                'hour' => $hour,
                'day' => $day,
                'month' => $month,
                'year' => $year
            ];
        } 
        // Collect future hours (only for today and tomorrow)
        elseif ($timestamp > $now->getTimestamp() && $price['total_price'] > 0) {
            // Calculate hours away - using the exact hour difference, NOT forcing minimum 1
            $hoursAway = floor(($timestamp - $now->getTimestamp()) / 3600);
            
            $futureHours[] = [
                'price' => $price['total_price'],
                'time' => $startTime,
                'hour' => $hour,
                'day' => $day,
                'month' => $month,
                'year' => $year,
                'hoursAway' => $hoursAway
            ];
        }
    }
    
    // If we couldn't find the current hour price, use the closest available
    if (!$currentPrice && !empty($priceData)) {
        usort($priceData, function($a, $b) use ($now) {
            return abs(strtotime($a['time_start']) - $now->getTimestamp()) - 
                   abs(strtotime($b['time_start']) - $now->getTimestamp());
        });
        
        $closestPrice = $priceData[0];
        $startTime = new DateTime($closestPrice['time_start']);
        $currentPrice = [
            'price' => max(1, $closestPrice['total_price']), // Ensure we never have zero price
            'time' => $startTime,
            'hour' => (int)$startTime->format('G'),
            'day' => (int)$startTime->format('j'),
            'month' => (int)$startTime->format('n'),
            'year' => (int)$startTime->format('Y')
        ];
    }
    
    // Select the next 12 hours (or fewer if not enough data)
    $next12Hours = array_slice($futureHours, 0, min(12, count($futureHours)));
    $next12HourKeys = array_map(function($item) {
        return $item['hour'] . '-' . $item['day'];
    }, $next12Hours);
    
    // Check if the current hour is cheaper than all future hours
    $isCurrentHourCheapest = true;
    $currentHourPrice = $currentPrice['price'];
    
    foreach ($futureHours as $hour) {
        if ($hour['price'] < $currentHourPrice) {
            $isCurrentHourCheapest = false;
            break;
        }
    }
    
    // First, check if the current hour is among the cheapest
    // Create an array with all hours including current hour
    $allHoursForComparison = $futureHours;
    
    // Add current hour to the comparison
    $allHoursForComparison[] = [
        'price' => $currentPrice['price'],
        'time' => $currentPrice['time'],
        'hour' => $currentPrice['hour'],
        'day' => $currentPrice['day'],
        'month' => $currentPrice['month'] ?? (int)$now->format('n'),
        'year' => $currentPrice['year'] ?? (int)$now->format('Y'),
        'hoursAway' => 0,
        'isCurrent' => true
    ];
    
    // Apply strict validation to make absolutely sure we only show valid hours
    $validHours = array_filter($allHoursForComparison, function($hour) use ($now) {
        // Make sure price is positive
        if ($hour['price'] <= 0) {
            return false;
        }
        
        // Special case for current hour
        if (isset($hour['isCurrent']) && $hour['isCurrent']) {
            return true;
        }
        
        // Only include hours from today and tomorrow
        $hourDate = $hour['time'];
        $hourDay = (int)$hourDate->format('j');
        $hourMonth = (int)$hourDate->format('n');
        $hourYear = (int)$hourDate->format('Y');
        
        $today = (int)$now->format('j');
        $thisMonth = (int)$now->format('n');
        $thisYear = (int)$now->format('Y');
        
        // Must be current year and month
        if ($hourYear != $thisYear || $hourMonth != $thisMonth) {
            return false;
        }
        
        // Must be today or tomorrow
        $tomorrow = $today + 1;
        if ($hourDay == $today || $hourDay == $tomorrow) {
            return true;
        }
        
        // Handle month boundary
        $daysInMonth = (int)$now->format('t');
        if ($today == $daysInMonth && $hourDay == 1) {
            return true; // First day of next month
        }
        
        return false;
    });
    
    // Sort by price to find cheapest
    $validHoursByPrice = $validHours;
    usort($validHoursByPrice, function($a, $b) {
        return $a['price'] - $b['price'];
    });
    
    // Take the 3 cheapest hours
    $cheapestHours = array_slice($validHoursByPrice, 0, min(3, count($validHoursByPrice)));
    
    // Create a lookup array of the cheapest hours
    $cheapestHoursKeys = [];
    foreach ($cheapestHours as $hour) {
        $key = "";
        if (isset($hour['isCurrent']) && $hour['isCurrent']) {
            $key = "current";
        } else {
            $key = $hour['hour'] . '-' . $hour['day'];
        }
        $cheapestHoursKeys[$key] = true;
    }
    
    // Find those hours in the original array to preserve order by time
    $cheapestHoursByTime = [];
    
    // First check for current hour
    if (isset($cheapestHoursKeys['current'])) {
        $cheapestHoursByTime[] = [
            'price' => $currentPrice['price'],
            'time' => $currentPrice['time'],
            'hour' => $currentPrice['hour'],
            'day' => $currentPrice['day'],
            'month' => $currentPrice['month'] ?? (int)$now->format('n'),
            'year' => $currentPrice['year'] ?? (int)$now->format('Y'),
            'hoursAway' => 0,
            'isCurrent' => true
        ];
    }
    
    // Then add future hours in time order
    foreach ($validHours as $hour) {
        if (isset($hour['isCurrent']) && $hour['isCurrent']) {
            continue; // Skip current hour as we already handled it
        }
        
        $key = $hour['hour'] . '-' . $hour['day'];
        if (isset($cheapestHoursKeys[$key])) {
            $cheapestHoursByTime[] = $hour;
        }
    }
    
    // Load fonts first
    $fonts = loadRobotoFonts();
    
    // Draw the table header
    drawTableHeader($image, $width, $padding, $headerHeight, $colors, $fonts, $currentPrice, $scaleFactor);
    
    // Calculate how many rows we need to display
    $totalRows = count($next12Hours) + (count($cheapestHoursByTime) > 0 ? count($cheapestHoursByTime) + 1 : 0); // +1 for separator
    
    // Calculate available height for rows
    $availableHeight = $height - $headerHeight - round(40 * $scaleFactor); // 40px for padding
    
    // Calculate row height to fit all rows
    $rowHeight = min(round(45 * $scaleFactor), floor($availableHeight / $totalRows));
    
    // Starting Y position for the table
    $tableStartY = $headerHeight + round(20 * $scaleFactor);
    $colWidths = calculateColumnWidths($width, $padding, $scaleFactor);
    
    // Draw the 12 hour rows
    drawHourRows($image, $next12Hours, $currentPrice, $tableStartY, $rowHeight, $colWidths, $colors, $fonts, $scaleFactor, false, $averagePrice);
    
    // Now draw the cheapest hours section after we've calculated the layout variables
    if (!empty($cheapestHoursByTime)) {
        $separatorY = $tableStartY + (count($next12Hours) * $rowHeight);
        drawSeparator($image, $width, $padding, $separatorY, $colors, $fonts, $scaleFactor);
        
        // Draw the cheapest hours (in time order)
        drawHourRows($image, $cheapestHoursByTime, $currentPrice, $separatorY + $rowHeight, 
                     $rowHeight, $colWidths, $colors, $fonts, $scaleFactor, true, $averagePrice);
    }
    
    // Clear any output buffered content before sending image
    ob_end_clean();
    
    // Output the image
    imagepng($image);
    imagedestroy($image);
    
} catch (Exception $e) {
    // Handle errors - create a simple error image
    outputErrorImage($e);
}

/**
 * Load Roboto fonts
 */
function loadRobotoFonts() {
    $fontRegular = './fonts/Roboto-Regular.ttf';
    $fontBold = './fonts/Roboto-Bold.ttf';
    
    // Use default fonts if Roboto is not available
    if (!file_exists($fontRegular)) {
        $fontRegular = 5; // Use built-in font as last resort
    }
    if (!file_exists($fontBold)) {
        $fontBold = 5; // Use built-in font as last resort
    }
    
    return ['regular' => $fontRegular, 'bold' => $fontBold];
}

/**
 * Calculate column widths
 */
function calculateColumnWidths($width, $padding, $scaleFactor) {
    $totalWidth = $width - (2 * $padding);
    
    // Reserve extra space for the percentage bars
    $percentBarWidth = round(25 * $scaleFactor); // Bar width + spacing
    
    return [
        'time' => round($totalWidth * 0.25),
        'price' => round($totalWidth * 0.20),
        'diff' => round($totalWidth * 0.30), // More space for percentage + bar
        'hours' => round($totalWidth * 0.25)
    ];
}

/**
 * Draw the table header
 */
function drawTableHeader($image, $width, $padding, $headerHeight, $colors, $fonts, $currentPrice, $scaleFactor) {
    // Draw header background
    imagefilledrectangle($image, 0, 0, $width, $headerHeight, $colors['headerBg']);
    
    // Calculate font sizes
    $titleFontSize = round(22 * $scaleFactor);
    $priceFontSize = round(32 * $scaleFactor);
    $unitFontSize = round(16 * $scaleFactor);
    
    // Draw title text
    $title = "ELPRISTABELL";
    if (is_string($fonts['bold']) && function_exists('imagettftext')) {
        // Draw title
        imagettftext($image, $titleFontSize, 0, $padding, 30 * $scaleFactor, 
                     $colors['accentColor'], $fonts['bold'], $title);
        
        // Format current hour
        $hour = $currentPrice['hour'];
        $nextHour = ($hour + 1) % 24;
        $timeText = sprintf("AKTUELLT PRIS (%02d-%02d)", $hour, $nextHour);
        
        // Draw current time
        imagettftext($image, $titleFontSize, 0, $padding, 60 * $scaleFactor, 
                     $colors['headerText'], $fonts['regular'], $timeText);
        
        // Draw current price
        $priceText = round($currentPrice['price']);
        imagettftext($image, $priceFontSize, 0, $width - $padding - 150 * $scaleFactor, 50 * $scaleFactor, 
                     $colors['textColor'], $fonts['bold'], $priceText);
        
        // Draw unit
        imagettftext($image, $unitFontSize, 0, $width - $padding - 60 * $scaleFactor, 50 * $scaleFactor, 
                     $colors['lightText'], $fonts['regular'], "öre/kWh");
    } else {
        // Fallback for built-in fonts
        imagestring($image, 5, $padding, 10 * $scaleFactor, $title, $colors['accentColor']);
        
        $hour = $currentPrice['hour'];
        $nextHour = ($hour + 1) % 24;
        $timeText = sprintf("AKTUELLT PRIS (%02d-%02d)", $hour, $nextHour);
        
        imagestring($image, 4, $padding, 30 * $scaleFactor, $timeText, $colors['headerText']);
        
        $priceText = round($currentPrice['price']);
        imagestring($image, 5, $width - $padding - 100 * $scaleFactor, 20 * $scaleFactor, 
                   $priceText . " öre/kWh", $colors['textColor']);
    }
    
    // Draw header underline
    imageline($image, $padding, $headerHeight - 1, $width - $padding, $headerHeight - 1, $colors['borderColor']);
}

/**
 * Draw a separator between sections
 */
function drawSeparator($image, $width, $padding, $y, $colors, $fonts, $scaleFactor) {
    // Draw separator line
    imageline($image, $padding, $y, $width - $padding, $y, $colors['borderColor']);
    
    // Draw label for cheapest hours section
    $labelFontSize = round(16 * $scaleFactor);
    
    if (is_string($fonts['bold']) && function_exists('imagettftext')) {
        imagettftext($image, $labelFontSize, 0, $padding + 10 * $scaleFactor, $y + 30 * $scaleFactor, 
                    $colors['accentColor'], $fonts['bold'], "BILLIGASTE TIMMARNA");
    } else {
        imagestring($image, 4, $padding + 5 * $scaleFactor, $y + 10 * $scaleFactor, 
                   "BILLIGASTE TIMMARNA", $colors['accentColor']);
    }
}

/**
 * Draw hour rows
 */
function drawHourRows($image, $hours, $currentPrice, $startY, $rowHeight, $colWidths, $colors, $fonts, $scaleFactor, $isHighlighted = false, $averagePrice = null) {
    $rowCount = count($hours);
    $colX = calculateColumnX($colWidths);
    
    // Calculate font sizes
    $timeFontSize = round(18 * $scaleFactor);
    $smallFontSize = round(12 * $scaleFactor); // Smaller font for date indicators
    $priceFontSize = round(20 * $scaleFactor);
    $diffFontSize = round(18 * $scaleFactor);
    
    for ($i = 0; $i < $rowCount; $i++) {
        $hour = $hours[$i];
        $rowY = $startY + ($i * $rowHeight);
        
        // Determine row background color
        if ($isHighlighted) {
            $bgColor = $colors['lowPriceColor'];
            imagefilledrectangleWithAlpha($image, 0, $rowY, $colX['time'] - 5, $rowY + $rowHeight, $bgColor, 90);
        } else {
            $bgColor = ($i % 2 == 0) ? $colors['rowBgEven'] : $colors['rowBgOdd'];
            imagefilledrectangle($image, 0, $rowY, $colX['end'], $rowY + $rowHeight, $bgColor);
        }
        
        // Format hour and time
        $hourNum = $hour['hour'];
        $nextHour = ($hourNum + 1) % 24;
        $timeText = sprintf("%02d-%02d", $hourNum, $nextHour);
        $dayIndicator = "";
        
        // Check if this is a different day
        if ($hour['day'] != $currentPrice['day']) {
            // Only show day indicators for reasonable dates (within next month)
            if (isset($hour['month']) && isset($currentPrice['month'])) {
                if ($hour['month'] == $currentPrice['month'] || 
                    ($hour['month'] == $currentPrice['month'] + 1 && $currentPrice['day'] >= 28)) {
                    $dayIndicator = "+" . ($hour['day'] - $currentPrice['day']) . "d";
                }
            } else {
                $dayIndicator = "+" . ($hour['day'] - $currentPrice['day']) . "d";
            }
        }
        
        // Format price
        $priceText = round($hour['price']);
        
        // Calculate price difference
        if ($currentPrice['price'] > 0) {
            $priceDiff = round(($hour['price'] - $currentPrice['price']) / $currentPrice['price'] * 100);
        } else {
            // If current price is zero or very close to zero, handle differently
            $priceDiff = $hour['price'] > 0 ? 100 : 0; // If future price > 0, it's 100% more expensive
        }
        
        $diffText = $priceDiff >= 0 ? "+" . $priceDiff . "%" : $priceDiff . "%";
        $diffColor = $priceDiff > 0 ? $colors['highPriceColor'] : $colors['lowPriceColor'];
        
        // Special case for current hour in cheapest section
        if (isset($hour['isCurrent']) && $hour['isCurrent']) {
            $diffText = "AKTUELL";
            $hoursAwayText = "NU";
        } else {
            // Format hours away with a + sign
            $hoursAwayText = "+" . $hour['hoursAway'] . "h";
        }
        
        // Determine price text color based on comparison to average price
        $priceColor = $colors['textColor']; // Default
        if ($averagePrice !== null && $averagePrice > 0) {
            $priceColor = getPriceColor($hour['price'], $averagePrice, $image);
        }
        
        if (is_string($fonts['regular']) && function_exists('imagettftext')) {
            // Draw time
            imagettftext($image, $timeFontSize, 0, $colX['time'], $rowY + $rowHeight / 2 + $timeFontSize/2, 
                        $colors['textColor'], $fonts['regular'], $timeText);
            
            // Draw day indicator with smaller font if needed
            if (!empty($dayIndicator)) {
                $timeWidth = imagettfbbox($timeFontSize, 0, $fonts['regular'], $timeText);
                $timeWidth = $timeWidth[2] - $timeWidth[0];
                imagettftext($image, $smallFontSize, 0, 
                          $colX['time'] + $timeWidth + 5, 
                          $rowY + $rowHeight / 2 + $timeFontSize/2, 
                          $colors['lightText'], $fonts['regular'], $dayIndicator);
            }
                        
            // Draw price with calculated color
            imagettftext($image, $priceFontSize, 0, $colX['price'], $rowY + $rowHeight / 2 + $priceFontSize/2, 
                        $priceColor, $fonts['bold'], $priceText);
            
            // Special handling for "AKTUELL" text (no visual bar needed)
            if ($diffText === "AKTUELL") {
                // Just draw the text with accent color
                imagettftext($image, $diffFontSize, 0, $colX['diff'], $rowY + $rowHeight / 2 + $diffFontSize/2, 
                            $colors['accentColor'], $fonts['bold'], $diffText);
            } else {
                // Draw visual percentage bar to left of percentage text
                $maxBarWidth = round(20 * $scaleFactor); // Maximum bar width
                $barHeight = round(8 * $scaleFactor);  // Bar height
                
                // Calculate absolute percentage (without + or - sign)
                $absPercentage = abs($priceDiff);
                
                // Limit to 100% for bar width calculation
                $barPercentage = min(100, $absPercentage) / 100;
                $barWidth = round($barPercentage * $maxBarWidth);
                
                // Don't draw bar if percentage is very small
                if ($barWidth > 0) {
                    // Position the bar vertically centered with text
                    $barY = $rowY + $rowHeight / 2 - $barHeight / 2;
                    
                    // Draw the bar to the left of the percentage text
                    $barX = $colX['diff'] - $barWidth - round(5 * $scaleFactor);
                    
                    // Use the same color as the percentage text
                    imagefilledrectangle($image, $barX, $barY, $barX + $barWidth, $barY + $barHeight, $diffColor);
                }
                
                // Draw the percentage text
                imagettftext($image, $diffFontSize, 0, $colX['diff'], $rowY + $rowHeight / 2 + $diffFontSize/2, 
                            $diffColor, $fonts['bold'], $diffText);
            }
                        
            // Draw hours away
            imagettftext($image, $diffFontSize, 0, $colX['hours'], $rowY + $rowHeight / 2 + $diffFontSize/2, 
                        $colors['lightText'], $fonts['regular'], $hoursAwayText);
        } else {
            // Fallback for built-in fonts
            imagestring($image, 4, $colX['time'], $rowY + ($rowHeight/2) - 7, $timeText, $colors['textColor']);
            
            // Draw day indicator if needed
            if (!empty($dayIndicator)) {
                $timeWidth = imagefontwidth(4) * strlen($timeText);
                imagestring($image, 2, $colX['time'] + $timeWidth + 5, 
                           $rowY + ($rowHeight/2) - 5, $dayIndicator, $colors['lightText']);
            }
            
            // Draw price with calculated color
            imagestring($image, 5, $colX['price'], $rowY + ($rowHeight/2) - 7, $priceText, $priceColor);
            
            // Special handling for "AKTUELL" text (no visual bar needed)
            if ($diffText === "AKTUELL") {
                // Just draw the text with accent color
                imagestring($image, 4, $colX['diff'], $rowY + ($rowHeight/2) - 7, $diffText, $colors['accentColor']);
            } else {
                // Draw visual percentage bar to left of percentage text
                $maxBarWidth = round(20 * $scaleFactor); // Maximum bar width
                $barHeight = round(6 * $scaleFactor);  // Bar height slightly smaller for built-in fonts
                
                // Calculate absolute percentage (without + or - sign)
                $absPercentage = abs($priceDiff);
                
                // Limit to 100% for bar width calculation
                $barPercentage = min(100, $absPercentage) / 100;
                $barWidth = round($barPercentage * $maxBarWidth);
                
                // Don't draw bar if percentage is very small
                if ($barWidth > 0) {
                    // Position the bar vertically centered with text
                    $barY = $rowY + ($rowHeight/2) - $barHeight / 2 - 2; // Small adjustment for text alignment
                    
                    // Draw the bar to the left of the percentage text
                    $barX = $colX['diff'] - $barWidth - 5;
                    
                    // Use the same color as the percentage text
                    imagefilledrectangle($image, $barX, $barY, $barX + $barWidth, $barY + $barHeight, $diffColor);
                }
                
                // Draw the percentage text
                imagestring($image, 4, $colX['diff'], $rowY + ($rowHeight/2) - 7, $diffText, $diffColor);
            }
            
            imagestring($image, 4, $colX['hours'], $rowY + ($rowHeight/2) - 7, $hoursAwayText, $colors['lightText']);
        }
        
        // Draw row separator
        imageline($image, 0, $rowY + $rowHeight, $colX['end'], $rowY + $rowHeight, $colors['gridColor']);
    }
}

/**
 * Calculate column X positions
 */
function calculateColumnX($colWidths) {
    $x1 = 20;  // Starting X position
    $x2 = $x1 + $colWidths['time'];
    $x3 = $x2 + $colWidths['price'];
    $x4 = $x3 + $colWidths['diff'];
    $x5 = $x4 + $colWidths['hours'];
    
    return [
        'time' => $x1,
        'price' => $x2,
        'diff' => $x3 + round($colWidths['diff'] * 0.4), // Offset right to make space for bar
        'hours' => $x4,
        'end' => $x5
    ];
}

/**
 * Draw a filled rectangle with alpha
 * This function implements a workaround for imagefilledrectangle with alpha
 */
function imagefilledrectangleWithAlpha($image, $x1, $y1, $x2, $y2, $color, $alpha) {
    // Create a color with the specified alpha
    $r = ($color >> 16) & 0xFF;
    $g = ($color >> 8) & 0xFF;
    $b = $color & 0xFF;
    
    $alphaColor = imagecolorallocatealpha($image, $r, $g, $b, 127 - ($alpha * 127 / 100));
    imagefilledrectangle($image, $x1, $y1, $x2, $y2, $alphaColor);
}

/**
 * Output an error image
 */
function outputErrorImage($e) {
    // Clear any buffered output
    ob_end_clean();
    
    // Create a simple error image
    $errorImage = imagecreatetruecolor(500, 200);
    $bgColor = imagecolorallocate($errorImage, 50, 50, 50);
    $textColor = imagecolorallocate($errorImage, 255, 100, 100);
    $whiteColor = imagecolorallocate($errorImage, 255, 255, 255);
    
    imagefill($errorImage, 0, 0, $bgColor);
    imagestring($errorImage, 5, 20, 20, "Error generating price table", $textColor);
    imagestring($errorImage, 4, 20, 50, $e->getMessage(), $whiteColor);
    imagestring($errorImage, 3, 20, 80, "Check server logs for details", $whiteColor);
    
    imagepng($errorImage);
    imagedestroy($errorImage);
    
    // Log the error
    error_log("Table widget error: " . $e->getMessage());
}

/**
 * Calculate weighted average price from an array of price data
 */
function calculateAveragePrice($priceData) {
    $totalHours = 0;
    $totalPrice = 0;
    
    foreach ($priceData as $price) {
        // Only include positive price values
        if (isset($price['total_price']) && $price['total_price'] > 0) {
            $totalPrice += $price['total_price'];
            $totalHours++;
        }
    }
    
    if ($totalHours === 0) {
        return 0; // Avoid division by zero
    }
    
    return $totalPrice / $totalHours;
}

/**
 * Calculate color for price text based on comparison to average
 * Returns an RGB color array
 */
function getPriceColor($price, $averagePrice, $image) {
    // Default to white if average price is 0 (avoid division by zero)
    if ($averagePrice <= 0) {
        return imagecolorallocate($image, 255, 255, 255);
    }
    
    // Calculate the percentage of the current price compared to average
    $percentage = ($price / $averagePrice) * 100;
    
    // Stay white for prices within ±20% of average
    if ($percentage >= 80 && $percentage <= 120) {
        return imagecolorallocate($image, 255, 255, 255);
    }
    
    if ($percentage < 80) {
        // Green gradient for low prices (50% or less is full green)
        // Map 50%-80% to 0-255 for the red and blue components
        $intensity = min(255, max(0, (($percentage - 50) / 30) * 255));
        return imagecolorallocate($image, $intensity, 255, $intensity);
    } else {
        // Red gradient for high prices (200% or more is full red)
        // Map 120%-200% to 255-0 for the green and blue components
        $intensity = min(255, max(0, (1 - (($percentage - 120) / 80)) * 255));
        return imagecolorallocate($image, 255, $intensity, $intensity);
    }
} 