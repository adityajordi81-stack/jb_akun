<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'store.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Metode tidak diizinkan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    echo json_encode(load_products(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Lootlane API gagal memuat katalog: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Katalog tidak dapat dimuat. Periksa konfigurasi server.'], JSON_UNESCAPED_UNICODE);
}
