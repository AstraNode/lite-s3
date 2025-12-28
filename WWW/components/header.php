<?php
/**
 * Page Header Component
 * Usage: <?php component('header', ['title' => 'Page Title']); ?>
 */
$title = $title ?? 'S3 Storage';
$navbar = $navbar ?? true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary: #0d6efd; --accent: #38ef7d; }
        body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); min-height: 100vh; color: #fff; }
        .navbar { background: rgba(0,0,0,0.3) !important; backdrop-filter: blur(10px); }
        .card { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.1); color: #fff; }
        .list-group-item { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.1); color: #fff; }
        code { background: rgba(0,0,0,0.3); padding: 2px 8px; border-radius: 4px; color: var(--accent); }
        a { color: var(--accent); }
        .table { color: #fff; }
        .stat-card { border: none; }
        .stat-card.blue { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-card.green { background: linear-gradient(135deg, #11998e, #38ef7d); }
        .stat-card.orange { background: linear-gradient(135deg, #f093fb, #f5576c); }
    </style>
</head>
<body>
<?php if ($navbar): ?>
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand" href="/"><i class="bi bi-cloud-fill"></i> S3 Storage</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="/admin/login.php"><i class="bi bi-box-arrow-in-right"></i> Login</a>
        </div>
    </div>
</nav>
<?php endif; ?>
