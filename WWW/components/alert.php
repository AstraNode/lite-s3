<?php
/**
 * Alert Component
 * Usage: <?php component('alert', ['type' => 'success', 'message' => 'Done!']); ?>
 */
$type = $type ?? 'info';
$message = $message ?? '';
$icon = ['success' => 'bi-check-circle', 'danger' => 'bi-exclamation-triangle', 'warning' => 'bi-exclamation-circle', 'info' => 'bi-info-circle'][$type] ?? 'bi-info-circle';
if ($message):
?>
<div class="alert alert-<?= $type ?> alert-dismissible fade show" role="alert">
    <i class="bi <?= $icon ?>"></i> <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
