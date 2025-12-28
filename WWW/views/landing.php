<?php
/**
 * Public Landing Page View
 */
require_once __DIR__ . '/../components/loader.php';
component('header', ['title' => 'S3 Object Storage']);
?>

<div class="hero text-center py-5">
    <div class="container">
        <i class="bi bi-cloud-arrow-up-fill" style="font-size: 5rem; color: #38ef7d;"></i>
        <h1 class="mt-3">S3 Object Storage</h1>
        <p class="opacity-75">Self-hosted, S3-compatible storage for your files</p>
        <a href="/admin/login.php" class="btn btn-lg btn-primary px-5 mt-3">
            <i class="bi bi-box-arrow-in-right"></i> Sign In
        </a>
    </div>
</div>

<div class="container pb-5">
    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <div class="card h-100 text-center p-4">
                <i class="bi bi-shield-check" style="font-size: 2.5rem; color: #38ef7d;"></i>
                <h5 class="mt-3">S3 Compatible</h5>
                <p class="opacity-75 mb-0">Works with boto3, rclone, Cyberduck</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 text-center p-4">
                <i class="bi bi-people" style="font-size: 2.5rem; color: #38ef7d;"></i>
                <h5 class="mt-3">Multi-User</h5>
                <p class="opacity-75 mb-0">Separate buckets and permissions</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 text-center p-4">
                <i class="bi bi-hdd-stack" style="font-size: 2.5rem; color: #38ef7d;"></i>
                <h5 class="mt-3">Large Files</h5>
                <p class="opacity-75 mb-0">Up to 5GB with multipart uploads</p>
            </div>
        </div>
    </div>
    
    <div class="card p-4">
        <h5 class="mb-3"><i class="bi bi-code-slash"></i> API Endpoints</h5>
        <div class="row">
            <div class="col-md-6">
                <code>PUT /{bucket}</code> Create bucket<br>
                <code>GET /{bucket}</code> List objects<br>
                <code>PUT /{bucket}/{key}</code> Upload
            </div>
            <div class="col-md-6">
                <code>GET /{bucket}/{key}</code> Download<br>
                <code>DELETE /{bucket}/{key}</code> Delete<br>
                <code>HEAD /{bucket}/{key}</code> Info
            </div>
        </div>
    </div>
</div>

<?php component('footer'); ?>
