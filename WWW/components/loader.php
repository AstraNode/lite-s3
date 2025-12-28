<?php
/**
 * Component Loader
 * Usage: component('header', ['title' => 'Page']);
 */
function component($name, $props = []) {
    extract($props);
    include __DIR__ . '/' . $name . '.php';
}
