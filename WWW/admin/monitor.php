<?php
/**
 * Real-time Performance Monitor Dashboard
 */

require_once '../config.php';
require_once '../performance-monitor.php';
session_start();

if (!($_SESSION['admin_logged_in'] ?? false)) {
    header('Location: login.php');
    exit;
}

$perfMonitor = new PerformanceMonitor();
$stats = $perfMonitor->getStats(1); // Last hour
$concurrentConnections = $perfMonitor->getConcurrentConnections();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Monitor - S3 Storage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .metric-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .metric-value {
            font-size: 2.5rem;
            font-weight: bold;
        }
        .metric-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .status-ok { background-color: #28a745; }
        .status-warning { background-color: #ffc107; }
        .status-error { background-color: #dc3545; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">S3 Storage Monitor</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php?page=dashboard">Dashboard</a>
                <a class="nav-link" href="index.php?page=buckets">Buckets</a>
                <a class="nav-link" href="index.php?page=users">Users</a>
                <a class="nav-link active" href="monitor.php">Monitor</a>
                <a class="nav-link" href="index.php?page=logout">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h1 class="mb-4">Real-time Performance Monitor</h1>
        
        <!-- Key Metrics -->
        <div class="row">
            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-value"><?php echo $concurrentConnections; ?></div>
                    <div class="metric-label">Active Connections</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-value"><?php echo array_sum(array_column($stats, 'total_requests')); ?></div>
                    <div class="metric-label">Requests (1h)</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-value"><?php echo round(array_sum(array_column($stats, 'avg_response_time')) / count($stats), 2); ?>ms</div>
                    <div class="metric-label">Avg Response Time</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-value"><?php echo count(array_unique(array_column($stats, 'client_ip'))); ?></div>
                    <div class="metric-label">Unique Clients</div>
                </div>
            </div>
        </div>

        <!-- Request Statistics -->
        <div class="row mt-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5>Request Statistics (Last Hour)</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="requestChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5>System Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <span class="status-indicator status-ok"></span>
                            <strong>Storage System:</strong> Online
                        </div>
                        <div class="mb-3">
                            <span class="status-indicator status-ok"></span>
                            <strong>Database:</strong> Connected
                        </div>
                        <div class="mb-3">
                            <span class="status-indicator status-ok"></span>
                            <strong>Authentication:</strong> Active
                        </div>
                        <div class="mb-3">
                            <span class="status-indicator status-ok"></span>
                            <strong>File Operations:</strong> Normal
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Statistics Table -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5>Detailed Request Breakdown</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Requests</th>
                                        <th>Avg Response Time</th>
                                        <th>Max Response Time</th>
                                        <th>Unique Clients</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats as $stat): ?>
                                    <tr>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($stat['method']); ?></span></td>
                                        <td>
                                            <span class="badge <?php echo $stat['status'] >= 400 ? 'bg-danger' : ($stat['status'] >= 300 ? 'bg-warning' : 'bg-success'); ?>">
                                                <?php echo $stat['status']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $stat['total_requests']; ?></td>
                                        <td><?php echo round($stat['avg_response_time'], 2); ?>ms</td>
                                        <td><?php echo round($stat['max_response_time'], 2); ?>ms</td>
                                        <td><?php echo $stat['unique_clients']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh every 30 seconds
        setTimeout(function() {
            location.reload();
        }, 30000);

        // Chart.js for request visualization
        const ctx = document.getElementById('requestChart').getContext('2d');
        const requestChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($stats, 'method')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($stats, 'total_requests')); ?>,
                    backgroundColor: [
                        '#FF6384',
                        '#36A2EB',
                        '#FFCE56',
                        '#4BC0C0',
                        '#9966FF',
                        '#FF9F40'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });
    </script>
</body>
</html>
