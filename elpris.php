<?php
header('Content-Type: text/html; charset=utf-8');
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

        <div id="error-container"></div>

        <div class="price-info">
            <div class="price-card">
                <h3>Aktuellt Pris</h3>
                <div class="value" id="current-price">--</div>
                <div class="unit">öre/kWh</div>
                <div class="appliance-costs" id="appliance-costs">
                    <h4>Kostnad att köra:</h4>
                    <!-- Will be populated by JavaScript -->
                </div>
            </div>
            
            <div class="price-card">
                <h3>Tid till Lägsta Pris</h3>
                <div class="value" id="time-until-lowest">--</div>
                <div class="price-details">
                    <span class="future-price" id="lowest-price">-- öre/kWh</span>
                    <span class="percentage" id="lowest-price-diff">--%</span>
                </div>
            </div>

            <div class="price-card">
                <h3>Tid till Billigaste 3h Period</h3>
                <div class="value" id="time-until-3h">--</div>
                <div class="price-details">
                    <span class="future-price" id="cheapest-3h-price">-- öre/kWh</span>
                    <span class="percentage" id="cheapest-3h-diff">--%</span>
                </div>
            </div>

            <div class="price-card">
                <h3>Prisuppdelning</h3>
                <div class="price-breakdown" id="price-breakdown">
                    <!-- Will be populated by JavaScript -->
                </div>
            </div>
        </div>

        <canvas id="priceChart"></canvas>
        <div id="chartTooltip" style="display: none;"></div>
    </div>

    <script>
        // Constants for appliance consumption
        const APPLIANCE_COSTS = {
            'Diskmaskin': {
                consumption: 1.2,
                unit: 'cykel'
            },
            'Tvättmaskin': {
                consumption: 2.0,
                unit: 'cykel'
            },
            'Dusch': {
                consumption: 5,
                unit: '10 min'
            }
        };

        let chart = null;
        let priceConstants = null;

        // Background plugin for Chart.js
        const backgroundPlugin = {
            id: 'customCanvasBackgroundColor',
            beforeDraw: (chart, args, options) => {
                const {ctx, chartArea, scales} = chart;
                const xScale = scales.x;
                
                ctx.save();
                
                // Get current hour and date
                const now = new Date();
                const currentHour = now.getHours();
                const today = now.toISOString().split('T')[0];
                
                // Find cheapest 3h period
                let cheapest3hStart = null;
                let cheapest3hAvg = Infinity;
                const prices = chart.data.fullData;
                
                for (let i = 0; i < prices.length - 2; i++) {
                    const startTime = new Date(prices[i].time_start);
                    if (startTime >= now || startTime.toISOString().split('T')[0] > today) {
                        const avg = (prices[i].total_price + 
                                   prices[i + 1].total_price + 
                                   prices[i + 2].total_price) / 3;
                        if (avg < cheapest3hAvg) {
                            cheapest3hAvg = avg;
                            cheapest3hStart = startTime;
                        }
                    }
                }
                
                // Draw background for each data point
                chart.data.labels.forEach((label, index) => {
                    const priceData = chart.data.fullData[index];
                    if (!priceData) return;
                    
                    const priceDate = new Date(priceData.time_start);
                    const priceHour = priceDate.getHours();
                    const priceDay = priceDate.toISOString().split('T')[0];
                    let fillStyle = null;
                    
                    // Highlight hovered hour (check first to take precedence)
                    if (options.highlightedHour === priceData.time_start) {
                        fillStyle = 'rgba(255, 255, 255, 0.1)';
                    }
                    // Highlight current hour
                    else if (priceDay === today && priceHour === currentHour) {
                        fillStyle = 'rgba(0, 255, 157, 0.1)';
                    }
                    // Highlight cheapest 3h period
                    else if (cheapest3hStart && 
                        priceDate >= cheapest3hStart && 
                        priceDate < new Date(cheapest3hStart.getTime() + 3 * 60 * 60 * 1000)) {
                        fillStyle = 'rgba(75, 192, 192, 0.1)';
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
                    
                    const priceDate = new Date(priceData.time_start);
                    return priceDate.toISOString().split('T')[0] === today && 
                           priceDate.getHours() === currentHour;
                });

                // Draw current time line
                if (currentHourIndex !== -1) {
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
            return date.toLocaleString('sv-SE', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                weekday: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Function to handle permission error
        function showPermissionError(error) {
            const errorContainer = document.getElementById('error-container');
            errorContainer.innerHTML = `
                <div style="background-color: #ff5252; color: white; padding: 20px; margin: 20px; border-radius: 8px;">
                    <h3 style="margin-top: 0;">⚠️ Cache Directory Permission Issue</h3>
                    <p><strong>Error:</strong> ${error.message} (${error.cache_dir})</p>
                    <p><strong>To fix this, run these commands on your Linux server:</strong></p>
                    <pre style="background: rgba(0,0,0,0.1); padding: 15px; border-radius: 4px; overflow-x: auto;">
# Create the cache directory
mkdir -p ${error.cache_dir}

# Set ownership to web server user (usually www-data)
sudo chown www-data:www-data ${error.cache_dir}

# Set proper directory permissions
sudo chmod 755 ${error.cache_dir}</pre>
                    <p><strong>Note:</strong> If your web server runs as a different user, replace www-data with that username.</p>
                    <p>You can find your web server user by running: <code>ps aux | grep apache</code> or <code>ps aux | grep nginx</code></p>
                </div>`;
        }

        // Function to update the UI with new price data
        function updateUI(data) {
            const currentHour = new Date().getHours();
            const today = new Date().toISOString().split('T')[0];
            
            // Find current price
            let currentPrice = null;
            let lowestPrice = Infinity;
            let highestPrice = -Infinity;
            let lowestPriceHour = null;
            let cheapest3hAvg = Infinity;
            let cheapest3hStart = null;
            
            // Process prices
            data.prices.forEach(price => {
                const priceHour = new Date(price.time_start).getHours();
                const priceDay = price.time_start.split('T')[0];
                const totalPrice = price.total_price;
                
                if (priceDay === today && priceHour === currentHour) {
                    currentPrice = totalPrice;
                }
                
                if (priceDay >= today && (priceDay > today || priceHour >= currentHour)) {
                    if (totalPrice < lowestPrice) {
                        lowestPrice = totalPrice;
                        lowestPriceHour = new Date(price.time_start);
                    }
                    if (totalPrice > highestPrice) {
                        highestPrice = totalPrice;
                    }
                }
            });
            
            // Find cheapest 3h period
            for (let i = 0; i < data.prices.length - 2; i++) {
                const startTime = new Date(data.prices[i].time_start);
                if (startTime >= new Date() || startTime.toISOString().split('T')[0] > today) {
                    const avg = (data.prices[i].total_price + 
                               data.prices[i + 1].total_price + 
                               data.prices[i + 2].total_price) / 3;
                    if (avg < cheapest3hAvg) {
                        cheapest3hAvg = avg;
                        cheapest3hStart = startTime;
                    }
                }
            }
            
            // Update current price and appliance costs
            document.getElementById('current-price').textContent = currentPrice ? 
                currentPrice.toFixed(1) : '--';
            
            if (currentPrice) {
                const applianceCostsHtml = Object.entries(APPLIANCE_COSTS).map(([name, data]) => `
                    <div class="appliance-item">
                        <span class="appliance-icon">${name}</span>
                        <span class="appliance-cost">
                            ${((currentPrice / 100) * data.consumption).toFixed(1)} kr/${data.unit}
                        </span>
                    </div>
                `).join('');
                document.getElementById('appliance-costs').innerHTML = `
                    <h4>Kostnad att köra:</h4>
                    ${applianceCostsHtml}
                `;
            }
            
            // Update time until lowest price
            if (lowestPriceHour) {
                const minutesUntilLowest = Math.floor((lowestPriceHour - new Date()) / 1000 / 60);
                const hoursUntilLowest = Math.floor(minutesUntilLowest / 60);
                const minutesRemaining = minutesUntilLowest % 60;
                document.getElementById('time-until-lowest').textContent = 
                    `${hoursUntilLowest}h ${minutesRemaining}m`;
                document.getElementById('lowest-price').textContent = 
                    `${lowestPrice.toFixed(1)} öre/kWh`;
                
                const priceDiffLowest = ((lowestPrice - currentPrice) / currentPrice * 100).toFixed(1);
                const lowestDiffEl = document.getElementById('lowest-price-diff');
                lowestDiffEl.textContent = `${priceDiffLowest >= 0 ? '+' : ''}${priceDiffLowest}%`;
                lowestDiffEl.className = `percentage ${priceDiffLowest <= 0 ? 'decrease' : 'increase'}`;
            }
            
            // Update time until cheapest 3h period
            if (cheapest3hStart) {
                const minutesUntil3h = Math.floor((cheapest3hStart - new Date()) / 1000 / 60);
                const hoursUntil3h = Math.floor(minutesUntil3h / 60);
                const minutesRemaining3h = minutesUntil3h % 60;
                document.getElementById('time-until-3h').textContent = 
                    `${hoursUntil3h}h ${minutesRemaining3h}m`;
                document.getElementById('cheapest-3h-price').textContent = 
                    `${cheapest3hAvg.toFixed(1)} öre/kWh`;
                
                const priceDiff3h = ((cheapest3hAvg - currentPrice) / currentPrice * 100).toFixed(1);
                const diff3hEl = document.getElementById('cheapest-3h-diff');
                diff3hEl.textContent = `${priceDiff3h >= 0 ? '+' : ''}${priceDiff3h}%`;
                diff3hEl.className = `percentage ${priceDiff3h <= 0 ? 'decrease' : 'increase'}`;
            }
            
            // Update price breakdown
            if (currentPrice && priceConstants) {
                const spotPrice = currentPrice - (
                    priceConstants.additional_fee + 
                    priceConstants.energy_tax + 
                    priceConstants.transfer_charge
                );
                document.getElementById('price-breakdown').innerHTML = `
                    <div class="breakdown-item">
                        <span>Spotpris (inkl. moms):</span>
                        <span>${spotPrice.toFixed(1)} öre/kWh</span>
                    </div>
                    <div class="breakdown-item">
                        <span>Påslag:</span>
                        <span>${priceConstants.additional_fee} öre/kWh</span>
                    </div>
                    <div class="breakdown-item">
                        <span>Energiskatt:</span>
                        <span>${priceConstants.energy_tax} öre/kWh</span>
                    </div>
                    <div class="breakdown-item">
                        <span>Överföringsavgift:</span>
                        <span>${priceConstants.transfer_charge} öre/kWh</span>
                    </div>
                    <div class="breakdown-item total">
                        <span>Totalt:</span>
                        <span>${currentPrice.toFixed(1)} öre/kWh</span>
                    </div>
                `;
            }
            
            // Update chart
            updateChart(data.prices);
        }

        // Function to update the chart
        function updateChart(prices) {
            const labels = prices.map(price => {
                const date = new Date(price.time_start);
                return date.toLocaleString('sv-SE', { weekday: 'short', hour: '2-digit', minute: '2-digit' });
            });
            
            const values = prices.map(price => price.total_price);
            
            // Calculate min/max for color scale
            const minPrice = Math.min(...values);
            const maxPrice = Math.max(...values);
            
            if (chart) {
                chart.destroy();
            }
            
            const ctx = document.getElementById('priceChart').getContext('2d');
            chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Total Price',
                        data: values,
                        borderWidth: 2,
                        stepped: true,
                        tension: 0,
                        pointRadius: 0,
                        borderJoinStyle: 'miter',
                        segment: {
                            borderColor: ctx => {
                                const price = values[ctx.p0DataIndex];
                                const percentage = (price - minPrice) / (maxPrice - minPrice);
                                const hue = (1 - percentage) * 120;
                                return `hsla(${hue}, 80%, 40%, 1)`;
                            }
                        }
                    }],
                    fullData: prices
                },
                options: {
                    responsive: true,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        tooltip: {
                            enabled: false
                        },
                        legend: {
                            display: false
                        },
                        customCanvasBackgroundColor: {
                            color: '#2d2d2d'
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
                            const currentPrice = parseFloat(document.getElementById('current-price').textContent);
                            const priceDiff = ((price - currentPrice) / currentPrice * 100).toFixed(1);
                            const isDecrease = price <= currentPrice;
                            
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
                            
                            // Set the highlighted hour
                            chart.options.plugins.customCanvasBackgroundColor.highlightedHour = priceData.time_start;
                            chart.update('none');
                        } else {
                            tooltipEl.style.display = 'none';
                            // Clear the highlighted hour
                            chart.options.plugins.customCanvasBackgroundColor.highlightedHour = null;
                            chart.update('none');
                        }
                    }
                },
                plugins: [backgroundPlugin]
            });
        }

        // Function to fetch data and update UI
        async function fetchDataAndUpdate(days = 1) {
            try {
                const response = await fetch(`api.php?days=${days}&area=SE3`);
                const data = await response.json();
                
                if (data.error) {
                    if (data.type === 'permission_error') {
                        showPermissionError(data);
                    } else {
                        console.error('API Error:', data.message);
                    }
                    return;
                }
                
                priceConstants = data.constants;
                updateUI(data);
            } catch (error) {
                console.error('Failed to fetch data:', error);
            }
        }

        // Initialize
        fetchDataAndUpdate(1);

        // Add event listeners to time range buttons
        document.querySelectorAll('.time-button').forEach(button => {
            button.addEventListener('click', () => {
                document.querySelectorAll('.time-button').forEach(btn => 
                    btn.classList.remove('active'));
                button.classList.add('active');
                fetchDataAndUpdate(parseInt(button.dataset.days));
            });
        });

        // Update data every 5 minutes
        setInterval(() => fetchDataAndUpdate(
            parseInt(document.querySelector('.time-button.active').dataset.days)
        ), 5 * 60 * 1000);
    </script>
</body>
</html>
