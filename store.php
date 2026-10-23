<?php
declare(strict_types=1);

function product_store_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'products.json';
}

function payment_settings_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'payments.json';
}

function load_payment_settings(): array
{
    $defaults = [
        'dana_number' => '',
        'dana_name' => '',
        'qris_image' => '',
        'qris_name' => '',
        'bank_name' => '',
        'bank_account' => '',
        'bank_holder' => '',
        'ewallet_provider' => '',
        'ewallet_number' => '',
        'ewallet_name' => '',
    ];
    $path = payment_settings_path();
    if (!is_file($path)) {
        return $defaults;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Data pembayaran tidak dapat dibaca.');
    }
    $settings = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($settings)) {
        throw new RuntimeException('Format data pembayaran tidak valid.');
    }

    return array_merge($defaults, array_intersect_key($settings, $defaults));
}

function save_payment_settings(array $settings): void
{
    $contents = json_encode(
        $settings,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    if (file_put_contents(payment_settings_path(), $contents, LOCK_EX) === false) {
        throw new RuntimeException('Pengaturan pembayaran gagal disimpan. Periksa izin folder data.');
    }
}

function load_products(): array
{
    $path = product_store_path();
    if (!is_file($path)) {
        throw new RuntimeException('File katalog tidak ditemukan.');
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('File katalog tidak dapat dibaca.');
    }

    $products = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($products)) {
        throw new RuntimeException('Format file katalog tidak valid.');
    }
    $expectedIndex = 0;
    foreach ($products as $index => $product) {
        if ($index !== $expectedIndex++ || !is_array($product)) {
            throw new RuntimeException('Format file katalog tidak valid.');
        }
    }

    return $products;
}

function save_products(array $products): void
{
    $contents = json_encode(
        array_values($products),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    if (file_put_contents(product_store_path(), $contents, LOCK_EX) === false) {
        throw new RuntimeException('Perubahan katalog gagal disimpan. Periksa izin folder data.');
    }
}

function remove_product_image(?string $image): void
{
    if (!$image || !preg_match('~^uploads/[a-f0-9]{24}\.(?:jpg|png|webp)$~', $image)) {
        return;
    }

    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $image);
    if (is_file($path) && !unlink($path)) {
        error_log('Lootlane: gambar produk lama gagal dihapus: ' . $path);
    }
}
