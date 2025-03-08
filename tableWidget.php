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
        $today = (int)$now->format('j');
        $timestamp = $startTime->getTimestamp();
        
        // Determine if this is the current hour
        $isSameDay = $day === $today;
        $isSameHour = $hour === $currentHour;
        
        if ($isSameDay && $isSameHour) {
            $currentPrice = [
                'price' => $price['total_price'],
                'time' => $startTime,
                'hour' => $hour,
                'day' => $day
            ];
        } 
        // Collect future hours
        elseif ($timestamp > $now->getTimestamp()) {
            $futureHours[] = [
                'price' => $price['total_price'],
                'time' => $startTime,
                'hour' => $hour,
                'day' => $day,
                'hoursAway' => floor(($timestamp - $now->getTimestamp()) / 3600)
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
            'price' => $closestPrice['total_price'],
            'time' => $startTime,
            'hour' => (int)$startTime->format('G'),
            'day' => (int)$startTime->format('j')
        ];
    }
    
    // Select the next 12 hours
    $next12Hours = array_slice($futureHours, 0, 12);
    $next12HourKeys = array_map(function($item) {
        return $item['hour'] . '-' . $item['day'];
    }, $next12Hours);
    
    // Find the 3 cheapest hours in the future
    usort($futureHours, function($a, $b) {
        return $a['price'] - $b['price'];
    });
    
    $cheapest3Hours = array_slice($futureHours, 0, 3);
    
    // Filter out the cheapest hours that are already in the next 12 hours
    $additionalCheapHours = array_filter($cheapest3Hours, function($item) use ($next12HourKeys) {
        $key = $item['hour'] . '-' . $item['day'];
        return !in_array($key, $next12HourKeys);
    });
    
    // Load fonts
    $fonts = loadRobotoFonts();
    
    // Draw the table header
    drawTableHeader($image, $width, $padding, $headerHeight, $colors, $fonts, $currentPrice, $scaleFactor);
    
    // Calculate how many rows we need to display
    $totalRows = count($next12Hours) + (!empty($additionalCheapHours) ? count($additionalCheapHours) + 1 : 0); // +1 for separator
    
    // Calculate available height for rows
    $availableHeight = $height - $headerHeight - round(40 * $scaleFactor); // 40px for padding
    
    // Calculate row height to fit all rows
    $rowHeight = min(round(45 * $scaleFactor), floor($availableHeight / $totalRows));
    
    // Starting Y position for the table
    $tableStartY = $headerHeight + round(20 * $scaleFactor);
    $colWidths = calculateColumnWidths($width, $padding, $scaleFactor);
    
    // Draw the 12 hour rows
    drawHourRows($image, $next12Hours, $currentPrice, $tableStartY, $rowHeight, $colWidths, $colors, $fonts, $scaleFactor);
    
    // Draw separator
    if (!empty($additionalCheapHours)) {
        $separatorY = $tableStartY + (count($next12Hours) * $rowHeight);
        drawSeparator($image, $width, $padding, $separatorY, $colors, $fonts, $scaleFactor);
        
        // Draw the cheapest hours (if not already in the 12 hours)
        drawHourRows($image, $additionalCheapHours, $currentPrice, $separatorY + $rowHeight, 
                     $rowHeight, $colWidths, $colors, $fonts, $scaleFactor, true);
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
    
    return [
        'time' => round($totalWidth * 0.25),
        'price' => round($totalWidth * 0.25),
        'diff' => round($totalWidth * 0.25),
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
    $title = "ELPRIS TABELL";
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
function drawHourRows($image, $hours, $currentPrice, $startY, $rowHeight, $colWidths, $colors, $fonts, $scaleFactor, $isHighlighted = false) {
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
            $dayIndicator = "+" . ($hour['day'] - $currentPrice['day']) . "d";
        }
        
        // Format price
        $priceText = round($hour['price']);
        
        // Calculate price difference
        $priceDiff = $currentPrice['price'] != 0 ? 
            round(($hour['price'] - $currentPrice['price']) / $currentPrice['price'] * 100) : 0;
        
        $diffText = $priceDiff >= 0 ? "+" . $priceDiff . "%" : $priceDiff . "%";
        $diffColor = $priceDiff > 0 ? $colors['highPriceColor'] : $colors['lowPriceColor'];
        
        // Format hours away
        $hoursAwayText = $hour['hoursAway'] . "h";
        
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
                        
            // Draw price
            imagettftext($image, $priceFontSize, 0, $colX['price'], $rowY + $rowHeight / 2 + $priceFontSize/2, 
                        $colors['textColor'], $fonts['bold'], $priceText);
                        
            // Draw difference
            imagettftext($image, $diffFontSize, 0, $colX['diff'], $rowY + $rowHeight / 2 + $diffFontSize/2, 
                        $diffColor, $fonts['bold'], $diffText);
                        
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
            
            imagestring($image, 5, $colX['price'], $rowY + ($rowHeight/2) - 7, $priceText, $colors['textColor']);
            imagestring($image, 4, $colX['diff'], $rowY + ($rowHeight/2) - 7, $diffText, $diffColor);
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
        'diff' => $x3,
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