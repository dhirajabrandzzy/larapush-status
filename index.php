<?php
// index.php

// If the requested file exists in the same folder (e.g. CSS, JS, image), serve it directly
$requestPath = __DIR__ . parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

if ($requestPath !== __FILE__ && is_file($requestPath)) {
    return false; // Let the built-in PHP server serve the file directly
}

// Otherwise, route everything to access.php
require __DIR__ . '/access.php';
