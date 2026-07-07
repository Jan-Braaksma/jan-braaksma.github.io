/**
 * Fetch and display statistics
 * @param {string} location - Location filter value
 * @param {string} klas - Class/group filter value
 * @param {Object} dateParams - Object containing either {week, year} or {startdate, enddate}
 */
function getStatistics(location, klas, dateParams) {
    let url;
    
    if (dateParams.startdate && dateParams.enddate) {
        // Date range mode (default)
        url = `/api/statistics/statistics.php?location=${encodeURIComponent(location)}&classroom=${encodeURIComponent(klas)}&start_date=${encodeURIComponent(dateParams.startdate)}&end_date=${encodeURIComponent(dateParams.enddate)}`;
    } else {
        // Week mode
        url = `/api/statistics/statistics.php?location=${encodeURIComponent(location)}&classroom=${encodeURIComponent(klas)}&week=${encodeURIComponent(dateParams.week)}&year=${encodeURIComponent(dateParams.year)}`;
    }

    fetch(url)
        .then(response => response.text())
        .then(html => {
            document.getElementById('statistics_result').innerHTML = html;
            document.getElementById('statisticsModal').style.display = 'block';
            
            // Create pie chart after content is loaded
            const titleInfo = {
                location: location,
                klas: klas,
                isWeekMode: !!dateParams.week,
                dateRange: dateParams.week ? 
                    `Week ${dateParams.week}, ${dateParams.year}` : 
                    `${dateParams.startdate} - ${dateParams.enddate}`
            };
            createPieChart(titleInfo);
        })
        .catch(error => {
            console.error('Statistics fetch error:', error);
            alert('Er is een fout opgetreden bij het ophalen van de statistieken.');
        });
}

/**
 * Create a CSS/SVG pie chart with title
 * @param {Object} titleInfo - Information for chart title (location, klas, dateRange)
 */
function createPieChart(titleInfo = null) {
    const pieContainer = document.querySelector('.pie-chart');
    if (!pieContainer) return;
    
    // Get data from data attributes
    const aanwezig = parseFloat(pieContainer.dataset.aanwezig) || 0;
    const telaat = parseFloat(pieContainer.dataset.telaat) || 0;
    const geoorloofd = parseFloat(pieContainer.dataset.geoorloofd) || 0;
    const ongeoorloofd = parseFloat(pieContainer.dataset.ongeoorloofd) || 0;
    
    // Calculate angles for each segment
    const total = aanwezig + telaat + geoorloofd + ongeoorloofd;
    if (total === 0) return;
    
    // Check if this is a profile chart (has profile-chart-container parent)
    const isProfileChart = pieContainer.closest('.profile-chart-container') !== null;
    
    // Create SVG
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    if (!isProfileChart) {
        svg.setAttribute('width', '200');
        svg.setAttribute('height', '200');
    }
    svg.setAttribute('viewBox', '0 0 200 200');
    
    // Create HTML title element (outside SVG) - only show location/class and date
    const titleElement = document.createElement('div');
    titleElement.className = 'chart-title';
    
    const locationText = titleInfo && titleInfo.location === 'all' ? 'Alle locaties' : (titleInfo ? titleInfo.location : '');
    const klasText = titleInfo && titleInfo.klas === 'all' ? 'Alle klassen' : (titleInfo ? titleInfo.klas : '');
    
    let dateText = '';
    if (titleInfo && titleInfo.isWeekMode && titleInfo.dateRange) {
        dateText = titleInfo.dateRange;
    } else if (titleInfo && titleInfo.dateRange) {
        dateText = titleInfo.dateRange;
    }
    
    if (titleInfo) {
        titleElement.innerHTML = `<div class="chart-title-sub">${locationText}${klasText ? ' - ' + klasText : ''}</div>
                                 <div class="chart-title-date">${dateText}</div>`;
    }

    const data = [
        { label: 'Aanwezig', value: aanwezig, color: '#22c55e', gradientEnd: '#16a34a' },
        { label: 'Te laat', value: telaat, color: '#f59e0b', gradientEnd: '#d97706' },
        { label: 'Geoorloofd', value: geoorloofd, color: '#c061cb', gradientEnd: '#a855f7' },
        { label: 'Ongeoorloofd', value: ongeoorloofd, color: '#ef4444', gradientEnd: '#dc2626' }
    ];
    
    // Filter out zero values
    const filteredData = data.filter(d => d.value > 0);
    
    // Create defs section for gradients
    const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
    
    // Create gradients for each data item
    filteredData.forEach((item, index) => {
        const gradient = document.createElementNS('http://www.w3.org/2000/svg', 'radialGradient');
        gradient.setAttribute('id', `gradient-${index}`);
        gradient.setAttribute('cx', '30%');
        gradient.setAttribute('cy', '30%');
        gradient.setAttribute('r', '70%');
        
        const stop1 = document.createElementNS('http://www.w3.org/2000/svg', 'stop');
        stop1.setAttribute('offset', '0%');
        stop1.setAttribute('style', `stop-color:${item.color};stop-opacity:1`);
        
        const stop2 = document.createElementNS('http://www.w3.org/2000/svg', 'stop');
        stop2.setAttribute('offset', '100%');
        stop2.setAttribute('style', `stop-color:${item.gradientEnd};stop-opacity:1`);
        
        gradient.appendChild(stop1);
        gradient.appendChild(stop2);
        defs.appendChild(gradient);
    });
    
    svg.appendChild(defs);
    
    const centerX = 100;
    const centerY = 100;
    const radius = 80;
    
    let startAngle = -90; // Default of 0 is to the right instead of at the top.
    
    // Create pie slices
    filteredData.forEach((item, index) => {
        const angle = (item.value / total) * 360;
        
        // Special case: if angle is 360 (100%), draw a full circle instead of an arc
        if (angle >= 359.99) {
            const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            circle.setAttribute('cx', centerX);
            circle.setAttribute('cy', centerY);
            circle.setAttribute('r', radius);
            circle.setAttribute('fill', `url(#gradient-${index})`);
            circle.setAttribute('stroke', '#fff');
            circle.setAttribute('stroke-width', '2.5');
            circle.setAttribute('class', 'pie-slice');
            svg.appendChild(circle);
            return;
        }
        
        const endAngle = startAngle + angle;
        
        // Convert to radians
        const startRad = (startAngle * Math.PI) / 180;
        const endRad = (endAngle * Math.PI) / 180;
        
        // Calculate arc coordinates
        const x1 = centerX + radius * Math.cos(startRad);
        const y1 = centerY + radius * Math.sin(startRad);
        const x2 = centerX + radius * Math.cos(endRad);
        const y2 = centerY + radius * Math.sin(endRad);
        
        const largeArc = angle > 180 ? 1 : 0;
        
        // Create path
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        const pathData = `M ${centerX} ${centerY} L ${x1} ${y1} A ${radius} ${radius} 0 ${largeArc} 1 ${x2} ${y2} Z`;
        path.setAttribute('d', pathData);
        path.setAttribute('fill', `url(#gradient-${index})`);
        path.setAttribute('stroke', '#fff');
        path.setAttribute('stroke-width', '2.5');
        path.setAttribute('class', 'pie-slice');
        
        svg.appendChild(path);
        
        startAngle = endAngle;
    });
    
    // Add white separator lines between segments (only if there are multiple slices)
    if (filteredData.length > 1) {
        let separatorAngle = -90; // Default of 0 is to the right instead of at the top.
        filteredData.forEach((item, index) => {
            const angle = (item.value / total) * 360;
            separatorAngle += angle;
            
            const rad = (separatorAngle * Math.PI) / 180;
            // Reduce radius slightly to prevent separator lines from exceeding the outer circle
            const lineRadius = radius - 0.5;
            const x = centerX + lineRadius * Math.cos(rad);
            const y = centerY + lineRadius * Math.sin(rad);
            
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', centerX);
            line.setAttribute('y1', centerY);
            line.setAttribute('x2', x);
            line.setAttribute('y2', y);
            line.setAttribute('stroke', '#fff');
            line.setAttribute('stroke-width', '2.5');
            svg.appendChild(line);
        });
    }
    
    // Add white circle around the entire pie chart
    const outerCircle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    outerCircle.setAttribute('cx', centerX);
    outerCircle.setAttribute('cy', centerY);
    outerCircle.setAttribute('r', radius);
    outerCircle.setAttribute('fill', 'none');
    outerCircle.setAttribute('stroke', '#fff');
    outerCircle.setAttribute('stroke-width', '1');
    svg.appendChild(outerCircle);
    
    // Create legend
    const legend = document.createElement('div');
    legend.className = 'pie-legend';
    
    filteredData.forEach(item => {
        const legendItem = document.createElement('div');
        legendItem.className = 'pie-legend-item';
        legendItem.innerHTML = `
            <span class="pie-legend-color" style="background-color: ${item.color};"></span>
            <span class="pie-legend-label">${item.label}: ${item.value}%</span>
        `;
        legend.appendChild(legendItem);
    });
    
    // Clear container and add title, chart, and legend
    pieContainer.innerHTML = '';
    
    if (isProfileChart) {
        // For profile charts, create wrapper for side-by-side layout
        const chartWrapper = document.createElement('div');
        chartWrapper.className = 'profile-chart-wrapper';
        chartWrapper.appendChild(svg);
        
        pieContainer.appendChild(titleElement);
        pieContainer.appendChild(chartWrapper);
        pieContainer.appendChild(legend);
    } else {
        // For modal charts, keep original vertical layout
        pieContainer.appendChild(titleElement);
        pieContainer.appendChild(svg);
        pieContainer.appendChild(legend);
    }
}

/**
 * Export statistics to Excel
 * @param {string} location - Location filter value
 * @param {string} klas - Class/group filter value
 * @param {Object} dateParams - Object containing either {week, year} or {startdate, enddate}
 */
function exportToExcel(location, klas, dateParams) {
    let url;
    
    if (dateParams.startdate && dateParams.enddate) {
        // Date range mode
        url = `/api/statistics/export-excel.php?location=${encodeURIComponent(location)}&classroom=${encodeURIComponent(klas)}&start_date=${encodeURIComponent(dateParams.startdate)}&end_date=${encodeURIComponent(dateParams.enddate)}`;
    } else {
        // Week mode
        url = `/api/statistics/export-excel.php?location=${encodeURIComponent(location)}&classroom=${encodeURIComponent(klas)}&week=${encodeURIComponent(dateParams.week)}&year=${encodeURIComponent(dateParams.year)}`;
    }
    
    // Create a temporary link to trigger download
    const link = document.createElement('a');
    link.href = url;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

/**
 * Close statistics modal
 */
function closeStatisticsModal() {
    const modal = document.getElementById('statisticsModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Modal close logic
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('statisticsModal');
    const closeBtn = document.getElementById('closeStatisticsModal');
    
    if (closeBtn) {
        closeBtn.onclick = closeStatisticsModal;
    }
    
    window.onclick = function(event) {
        if (event.target === modal) {
            closeStatisticsModal();
        }
    };
});

// Make functions globally available
window.getStatistics = getStatistics;
window.createPieChart = createPieChart;
window.exportToExcel = exportToExcel;
window.closeStatisticsModal = closeStatisticsModal;
