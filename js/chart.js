// ==================== CHART.JS INITIALIZATION ====================

// Wait for DOM to load
document.addEventListener('DOMContentLoaded', function() {
    initializeCharts();
});

function initializeCharts() {
    // Initialize Application Trends Chart
    initializeApplicationTrendsChart();
    
    // Initialize User Distribution Chart
    initializeUserDistributionChart();
}

// ==================== APPLICATION TRENDS CHART ====================
function initializeApplicationTrendsChart() {
    const ctx = document.getElementById('applicationChart');
    
    if (!ctx) return; // Chart element not found
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            datasets: [{
                label: 'Applications',
                data: [25, 35, 28, 45, 38, 42, 30],
                backgroundColor: [
                    '#ff7a00',
                    '#ff8f1a',
                    '#ffa433',
                    '#ffb84d',
                    '#ff7a00',
                    '#ff6300',
                    '#ff7a00'
                ],
                borderRadius: 5,
                hoverBackgroundColor: '#ffb84d',
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            indexAxis: undefined,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: '#ff7a00',
                    borderWidth: 1
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 50,
                    ticks: {
                        color: '#666666',
                        font: {
                            size: 11
                        }
                    },
                    grid: {
                        color: '#1f1f1f',
                        drawBorder: false
                    }
                },
                x: {
                    ticks: {
                        color: '#666666',
                        font: {
                            size: 11
                        }
                    },
                    grid: {
                        display: false,
                        drawBorder: false
                    }
                }
            }
        }
    });
}

// ==================== USER DISTRIBUTION CHART ====================
function initializeUserDistributionChart() {
    const ctx = document.getElementById('userDistributionChart');
    
    if (!ctx) return; // Chart element not found
    
    const chart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Admin Users', 'Regular Users'],
            datasets: [{
                data: [25, 75],
                backgroundColor: [
                    '#ff9500',  // Orange for Admin
                    '#ff3c3c'   // Red for Regular Users
                ],
                borderColor: '#141414',
                borderWidth: 2,
                hoverBorderColor: '#ffffff',
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 10,
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: '#ff7a00',
                    borderWidth: 1,
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.parsed + '%';
                        }
                    }
                }
            }
        },
        plugins: [{
            id: 'centerText',
            beforeDatasetsDraw(chart) {
                const {ctx, chartArea: {left, top, width, height}} = chart;
                
                // Draw center circle for doughnut
                ctx.save();
                
                // Draw total users text
                const centerX = left + width / 2;
                const centerY = top + height / 2;
                
                // White circle background
                ctx.fillStyle = '#141414';
                ctx.beginPath();
                ctx.arc(centerX, centerY, 60, 0, 2 * Math.PI);
                ctx.fill();
                
                // Total number
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 24px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('2,345', centerX, centerY - 10);
                
                // Label
                ctx.fillStyle = '#888888';
                ctx.font = '11px sans-serif';
                ctx.fillText('TOTAL USERS', centerX, centerY + 20);
                
                ctx.restore();
            }
        }]
    });
}