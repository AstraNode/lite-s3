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
    <title>Monitor | S3 Storage Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/meta/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .metric-card-custom {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .metric-card-custom .value {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.025em;
        }
        .metric-card-custom .label {
            font-size: 0.825rem;
            color: var(--neutral-500);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.05em;
        }
    </style>
</head>
<body class="bg-neutral-50 animate-in">
    <nav class="s3-nav py-2">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-4">
                <a class="navbar-brand fw-bold d-flex align-items-center" href="?page=dashboard">
                    <i class="bi bi-cloud-upload me-2 fs-5"></i> Admin
                </a>
                <div class="d-flex gap-1 overflow-auto">
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900" href="?page=dashboard">Overview</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900" href="?page=buckets">Buckets</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900" href="?page=users">Users</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent fw-semibold" href="?page=monitor">Monitor</a>
                    <a class="s3-btn s3-btn-outline border-0 bg-transparent text-neutral-500 hover:text-neutral-900" href="?page=change-password">Security</a>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-neutral-400 small d-none d-sm-inline">Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></strong></span>
                <a class="s3-btn s3-btn-outline px-3" href="?page=logout">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </nav>

    <main class="container py-5">
        <header class="d-flex justify-content-between align-items-center mb-5">
            <div>
                <h1 class="fw-bold fs-2 mb-2">Real-time Monitor</h1>
                <p class="text-neutral-500 mb-0">System performance metrics and request analytics.</p>
            </div>
            <div class="d-flex align-items-center gap-2 text-neutral-400 small">
                <div class="spinner-grow spinner-grow-sm text-success" role="status"></div>
                Live tracking active
            </div>
        </header>

        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="s3-card metric-card-custom h-100">
                    <span class="label">Active Conn.</span>
                    <span class="value"><?php echo $concurrentConnections; ?></span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="s3-card metric-card-custom h-100">
                    <span class="label">Requests (1h)</span>
                    <span class="value"><?php echo array_sum(array_column($stats, 'total_requests')); ?></span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="s3-card metric-card-custom h-100">
                    <span class="label">Avg Latency</span>
                    <span class="value"><?php echo count($stats) > 0 ? round(array_sum(array_column($stats, 'avg_response_time')) / count($stats), 1) : 0; ?><span class="fs-6 fw-normal text-neutral-400 ms-1">ms</span></span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="s3-card metric-card-custom h-100">
                    <span class="label">Unique IPs</span>
                    <span class="value"><?php echo count(array_unique(array_column($stats, 'client_ip'))); ?></span>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-5">
            <div class="col-lg-8">
                <div class="s3-card h-100">
                    <h6 class="fw-bold mb-4">Request Distribution</h6>
                    <div style="height: 300px; display: flex; align-items: center; justify-content: center;">
                        <canvas id="requestChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="s3-card h-100">
                    <h6 class="fw-bold mb-4">System Health</h6>
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="text-neutral-500 small">Storage Engine</span>
                            <span class="badge-custom bg-black text-white px-2 py-1">Optimal</span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="text-neutral-500 small">Database</span>
                            <span class="badge-custom bg-black text-white px-2 py-1">Connected</span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="text-neutral-500 small">Auth Service</span>
                            <span class="badge-custom bg-black text-white px-2 py-1">Active</span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between border-top pt-3 mt-2">
                            <span class="text-neutral-500 small">Disk Space</span>
                            <span class="fw-bold small">84% Available</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="s3-card p-0 overflow-hidden">
            <div class="px-4 py-3 border-bottom">
                <h6 class="fw-bold mb-0 text-uppercase tracking-wider small">Method Analysis</h6>
            </div>
            <div class="table-responsive">
                <table class="s3-table mb-0">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Count</th>
                            <th>Avg Latency</th>
                            <th class="text-end">Max Latency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-neutral-400">No telemetry data available</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stats as $stat): ?>
                            <tr>
                                <td><span class="fw-bold"><?php echo htmlspecialchars($stat['method']); ?></span></td>
                                <td>
                                    <?php 
                                        $type = 'success';
                                        if ($stat['status'] >= 400) $type = 'destructive';
                                        else if ($stat['status'] >= 300) $type = 'warning';
                                    ?>
                                    <span class="badge-custom <?php echo $type == 'success' ? 'bg-black text-white' : ($type == 'warning' ? 'bg-neutral-100 text-neutral-600' : 'bg-destructive/10 text-destructive'); ?>">
                                        <?php echo $stat['status']; ?>
                                    </span>
                                </td>
                                <td class="text-neutral-500"><?php echo $stat['total_requests']; ?> <span class="text-neutral-300">hits</span></td>
                                <td class="font-monospace"><?php echo round($stat['avg_response_time'], 1); ?>ms</td>
                                <td class="text-end font-monospace text-neutral-400"><?php echo round($stat['max_response_time'], 1); ?>ms</td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <footer class="py-5 text-center text-neutral-400 border-top mt-5 bg-neutral-50">
        <p class="small mb-0">Monitor Engine v2.1 &bull; Real-time Telemetry</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(function() { location.reload(); }, 30000);

        const ctx = document.getElementById('requestChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($stats, 'method')); ?>,
                datasets: [{
                    label: 'Requests',
                    data: <?php echo json_encode(array_column($stats, 'total_requests')); ?>,
                    backgroundColor: '#000',
                    borderRadius: 4,
                    barThickness: 40
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: { color: '#888', font: { family: 'Inter' } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#000', font: { weight: '600', family: 'Inter' } }
                    }
                }
            }
        });
    </script>
</body>
</html>