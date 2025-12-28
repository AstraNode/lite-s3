<?php
/**
 * Stat Card Component
 * Usage: <?php component('stat-card', ['value' => 10, 'label' => 'Users', 'icon' => 'bi-people', 'color' => 'blue']); ?>
 */
$value = $value ?? 0;
$label = $label ?? 'Label';
$icon = $icon ?? 'bi-box';
$color = $color ?? 'blue';
?>
<div class="card stat-card <?= $color ?> h-100">
    <div class="card-body text-center py-4">
        <i class="bi <?= $icon ?>" style="font-size: 2rem;"></i>
        <h2 class="mt-2 mb-0"><?= htmlspecialchars($value) ?></h2>
        <p class="mb-0 opacity-75"><?= htmlspecialchars($label) ?></p>
    </div>
</div>
