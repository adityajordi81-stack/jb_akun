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
    $settings = load_payment_settings();
    echo json_encode([
        'qris' => [
            'enabled' => $settings['qris_image'] !== '',
            'image' => $settings['qris_image'],
            'name' => $settings['qris_name'],
        ],
        'dana' => [
            'enabled' => $settings['dana_number'] !== '',
            'number' => $settings['dana_number'],
            'name' => $settings['dana_name'],
        ],
        'bank' => [
            'enabled' => $settings['bank_name'] !== '' && $settings['bank_account'] !== '',
            'bank' => $settings['bank_name'],
            'number' => $settings['bank_account'],
            'name' => $settings['bank_holder'],
        ],
        'ewallet' => [
            'enabled' => $settings['ewallet_provider'] !== '' && $settings['ewallet_number'] !== '',
            'provider' => $settings['ewallet_provider'],
            'number' => $settings['ewallet_number'],
            'name' => $settings['ewallet_name'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Lootlane API gagal memuat pengaturan pembayaran: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Pengaturan pembayaran tidak dapat dimuat.'], JSON_UNESCAPED_UNICODE);
}
