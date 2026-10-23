<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'store.php';

$dataDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$adminFile = $dataDirectory . DIRECTORY_SEPARATOR . 'admin.json';
if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0755, true) && !is_dir($dataDirectory)) {
    http_response_code(500);
    exit('Folder data tidak dapat dibuat.');
}

session_name('lootlane_admin');
session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
session_start();

function admin_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_credentials(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Data akun admin tidak dapat dibaca.');
    }

    $credentials = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($credentials) || !isset($credentials['username'], $credentials['password_hash'])) {
        throw new RuntimeException('Data akun admin tidak valid.');
    }

    return $credentials;
}

function admin_csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_admin_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(admin_csrf_token(), $token)) {
        throw new RuntimeException('Sesi formulir kedaluwarsa. Muat ulang halaman dan coba lagi.');
    }
}

function admin_field(string $name, int $maxLength): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value)) {
        throw new RuntimeException('Data formulir tidak valid.');
    }

    $value = trim($value);
    if ($value === '' || mb_strlen($value) > $maxLength) {
        throw new RuntimeException('Lengkapi semua kolom wajib dan pastikan panjang teks sesuai batas.');
    }

    return $value;
}

function admin_optional_field(string $name, int $maxLength): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value)) {
        throw new RuntimeException('Data formulir pembayaran tidak valid.');
    }

    $value = trim($value);
    if (mb_strlen($value) > $maxLength) {
        throw new RuntimeException('Salah satu kolom pembayaran melebihi batas panjang.');
    }

    return $value;
}

function save_product_upload(?array $file): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($file['tmp_name'], $file['size'])) {
        throw new RuntimeException('Upload gambar gagal. Pastikan file tidak melebihi batas server.');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > 4 * 1024 * 1024) {
        throw new RuntimeException('Ukuran gambar maksimal 4 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
        throw new RuntimeException('Format gambar harus JPG, PNG, atau WebP.');
    }

    $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Folder upload gambar tidak dapat dibuat.');
    }

    $filename = bin2hex(random_bytes(12)) . '.' . $extensions[$mimeType];
    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Gambar gagal disimpan. Periksa izin folder uploads.');
    }

    return 'uploads/' . $filename;
}

function redirect_admin(string $status): void
{
    header('Location: admin.php?status=' . rawurlencode($status), true, 303);
    exit;
}

$errorMessage = '';
$formValues = [];
$paymentFormValues = [];
$uploadedImage = null;
$credentials = admin_credentials($adminFile);
$isLoggedIn = isset($_SESSION['admin_user']) && is_string($_SESSION['admin_user']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_admin_csrf();
        $action = $_POST['action'] ?? '';
        if (!is_string($action)) {
            throw new RuntimeException('Aksi tidak valid.');
        }

        if ($action === 'setup') {
            if ($credentials !== null) {
                throw new RuntimeException('Admin sudah dibuat. Silakan masuk.');
            }

            $username = admin_field('username', 40);
            $password = $_POST['password'] ?? '';
            $confirmation = $_POST['password_confirmation'] ?? '';
            if (!is_string($password) || strlen($password) < 12 || strlen($password) > 200 || $password !== $confirmation) {
                throw new RuntimeException('Kata sandi harus minimal 12 karakter dan kedua isian harus sama.');
            }
            $saved = json_encode([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (file_put_contents($adminFile, $saved . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Akun admin gagal disimpan. Periksa izin folder data.');
            }
            session_regenerate_id(true);
            $_SESSION['admin_user'] = $username;
            redirect_admin('setup');
        }

        if ($action === 'login') {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            if ($credentials === null || !is_string($username) || !is_string($password)
                || !hash_equals((string) $credentials['username'], $username)
                || !password_verify($password, (string) $credentials['password_hash'])) {
                throw new RuntimeException('Username atau kata sandi tidak cocok.');
            }
            session_regenerate_id(true);
            $_SESSION['admin_user'] = $username;
            redirect_admin('login');
        }

        if (!$isLoggedIn) {
            throw new RuntimeException('Silakan masuk sebagai admin terlebih dahulu.');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            redirect_admin('logout');
        }

        if ($action === 'save_payments') {
            $paymentFormValues = $_POST;
            $oldSettings = load_payment_settings();
            $uploadedImage = save_product_upload(isset($_FILES['qris_image']) && is_array($_FILES['qris_image']) ? $_FILES['qris_image'] : null);
            $removeQris = ($_POST['remove_qris'] ?? '') === '1';
            $settings = [
                'dana_number' => admin_optional_field('dana_number', 40),
                'dana_name' => admin_optional_field('dana_name', 80),
                'qris_image' => $uploadedImage ?? ($removeQris ? '' : $oldSettings['qris_image']),
                'qris_name' => admin_optional_field('qris_name', 80),
                'bank_name' => admin_optional_field('bank_name', 60),
                'bank_account' => admin_optional_field('bank_account', 40),
                'bank_holder' => admin_optional_field('bank_holder', 80),
                'ewallet_provider' => admin_optional_field('ewallet_provider', 40),
                'ewallet_number' => admin_optional_field('ewallet_number', 40),
                'ewallet_name' => admin_optional_field('ewallet_name', 80),
            ];
            if ($settings['dana_number'] !== '' && !preg_match('/^[0-9+(). -]+$/', $settings['dana_number'])) {
                throw new RuntimeException('Nomor DANA hanya boleh berisi angka dan simbol nomor telepon.');
            }
            if ($settings['bank_account'] !== '' && !preg_match('/^[a-zA-Z0-9 -]+$/', $settings['bank_account'])) {
                throw new RuntimeException('Nomor rekening hanya boleh berisi huruf, angka, spasi, dan tanda hubung.');
            }
            if ($settings['ewallet_number'] !== '' && !preg_match('/^[0-9+(). -]+$/', $settings['ewallet_number'])) {
                throw new RuntimeException('Nomor e-wallet hanya boleh berisi angka dan simbol nomor telepon.');
            }
            try {
                save_payment_settings($settings);
            } catch (Throwable $saveError) {
                remove_product_image($uploadedImage);
                throw $saveError;
            }
            if ($uploadedImage !== null) {
                remove_product_image($oldSettings['qris_image']);
            } elseif ($removeQris) {
                remove_product_image($oldSettings['qris_image']);
            }
            redirect_admin('payments_saved');
        }

        $products = load_products();
        if ($action === 'delete_product') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $remaining = array_values(array_filter($products, static fn (array $product): bool => $product['id'] !== $id));
            if ($id === false || count($remaining) === count($products)) {
                throw new RuntimeException('Produk yang ingin dihapus tidak ditemukan.');
            }
            $deleted = array_values(array_filter($products, static fn (array $product): bool => $product['id'] === $id))[0];
            save_products($remaining);
            remove_product_image($deleted['image'] ?? null);
            redirect_admin('deleted');
        }

        if ($action !== 'save_product') {
            throw new RuntimeException('Aksi tidak dikenal.');
        }

        $formValues = $_POST;
        $idInput = $_POST['id'] ?? '';
        $id = $idInput === '' ? null : filter_var($idInput, FILTER_VALIDATE_INT);
        if ($idInput !== '' && ($id === false || $id < 1)) {
            throw new RuntimeException('ID produk tidak valid.');
        }

        $existingIndex = null;
        $existingProduct = null;
        if ($id !== null) {
            foreach ($products as $index => $product) {
                if ($product['id'] === $id) {
                    $existingIndex = $index;
                    $existingProduct = $product;
                    break;
                }
            }
            if ($existingProduct === null) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }
        }

        $game = admin_field('game', 60);
        $short = admin_field('short', 24);
        $emoji = admin_field('emoji', 20);
        $name = admin_field('name', 120);
        $seller = admin_field('seller', 60);
        $priceInput = $_POST['price'] ?? '';
        if (!is_string($priceInput) || !preg_match('/^[0-9]{1,12}$/', $priceInput) || (int) $priceInput < 1) {
            throw new RuntimeException('Masukkan harga berupa angka lebih dari nol.');
        }
        $rating = $_POST['rating'] ?? '';
        if (!is_string($rating) || !preg_match('/^(?:[0-4](?:\.[0-9])?|5(?:\.0)?)$/', $rating)) {
            throw new RuntimeException('Rating harus berupa angka dari 0 sampai 5.');
        }
        $popularity = filter_var($_POST['popularity'] ?? null, FILTER_VALIDATE_INT);
        if ($popularity === false || $popularity < 0 || $popularity > 100) {
            throw new RuntimeException('Popularitas harus di antara 0 dan 100.');
        }
        $statsInput = $_POST['stats'] ?? '';
        if (!is_string($statsInput)) {
            throw new RuntimeException('Detail akun tidak valid.');
        }
        $stats = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $statsInput) ?: []), static fn (string $stat): bool => $stat !== ''));
        $hasLongStat = false;
        foreach ($stats as $stat) {
            if (mb_strlen($stat) > 32) {
                $hasLongStat = true;
                break;
            }
        }
        if (count($stats) > 6 || $hasLongStat) {
            throw new RuntimeException('Isi maksimal 6 detail, masing-masing maksimal 32 karakter.');
        }

        $uploadedImage = save_product_upload(isset($_FILES['image']) && is_array($_FILES['image']) ? $_FILES['image'] : null);
        $palette = [
            ['#493456', '#171827', 'rgba(255,112,169,.45)'],
            ['#36566a', '#171d2a', 'rgba(111,220,255,.4)'],
            ['#63333b', '#211821', 'rgba(255,94,107,.45)'],
            ['#67462b', '#211b1a', 'rgba(255,177,67,.45)'],
            ['#514c32', '#1d1d1a', 'rgba(227,201,93,.4)'],
            ['#434066', '#171827', 'rgba(164,144,255,.45)'],
        ];
        $colors = $existingProduct
            ? [$existingProduct['artA'], $existingProduct['artB'], $existingProduct['glow']]
            : $palette[count($products) % count($palette)];
        $product = array_merge($existingProduct ?? [], [
            'id' => $id ?? (empty($products) ? 1 : max(array_column($products, 'id')) + 1),
            'game' => $game,
            'short' => $short,
            'emoji' => $emoji,
            'name' => $name,
            'price' => (int) $priceInput,
            'stats' => $stats,
            'seller' => $seller,
            'rating' => $rating,
            'artA' => $colors[0],
            'artB' => $colors[1],
            'glow' => $colors[2],
            'popularity' => $popularity,
            'image' => $uploadedImage ?? ($existingProduct['image'] ?? ''),
        ]);
        if ($existingIndex === null) {
            $products[] = $product;
        } else {
            $products[$existingIndex] = $product;
        }
        try {
            save_products($products);
        } catch (Throwable $saveError) {
            remove_product_image($uploadedImage);
            throw $saveError;
        }
        if ($uploadedImage !== null && $existingProduct !== null) {
            remove_product_image($existingProduct['image'] ?? null);
        }
        redirect_admin('saved');
    } catch (Throwable $error) {
        if ($uploadedImage !== null) {
            remove_product_image($uploadedImage);
        }
        error_log('Lootlane admin: ' . $error->getMessage());
        $errorMessage = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Terjadi kesalahan saat memproses permintaan. Silakan periksa log server.';
        if ($error instanceof JsonException) {
            $errorMessage = 'File data admin/katalog rusak atau formatnya tidak valid.';
        }
        $credentials = admin_credentials($adminFile);
        $isLoggedIn = isset($_SESSION['admin_user']) && is_string($_SESSION['admin_user']);
    }
}

$statusMessages = [
    'setup' => 'Akun admin berhasil dibuat.',
    'login' => 'Berhasil masuk.',
    'logout' => 'Berhasil keluar.',
    'saved' => 'Produk berhasil disimpan.',
    'deleted' => 'Produk berhasil dihapus.',
    'payments_saved' => 'Pengaturan DANA, QRIS, dan metode pembayaran lainnya berhasil disimpan.',
];
$status = $_GET['status'] ?? '';
$noticeMessage = is_string($status) ? ($statusMessages[$status] ?? '') : '';
$products = $isLoggedIn ? load_products() : [];
$paymentSettings = $isLoggedIn ? load_payment_settings() : [];
$paymentForm = array_merge($paymentSettings, $paymentFormValues);
$editingId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editingProduct = null;
if ($editingId) {
    foreach ($products as $product) {
        if ($product['id'] === $editingId) {
            $editingProduct = $product;
            break;
        }
    }
}
$isCreating = isset($_GET['new']) || ($formValues !== [] && ($_POST['id'] ?? '') === '');
$formProduct = $editingProduct ?? [];
if ($formValues !== []) {
    $formProduct = array_merge($formProduct, $formValues);
}
$statsValue = isset($formProduct['stats']) && is_array($formProduct['stats'])
    ? implode("\n", $formProduct['stats'])
    : (string) ($formProduct['stats'] ?? '');
$currentImage = (string) ($formProduct['image'] ?? ($editingProduct['image'] ?? ''));
$csrfToken = admin_csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Admin Lootlane</title>
  <style>
    :root{color-scheme:dark;--bg:#10111a;--panel:#191a25;--line:rgba(255,255,255,.1);--text:#f5f5fa;--muted:#989bad;--lime:#c8fb63;--red:#ff829b}
    *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 "Segoe UI",Arial,sans-serif}.wrap{width:min(1120px,calc(100% - 32px));margin:36px auto 70px}
    a{color:var(--lime);text-decoration:none}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:25px}.brand{font-size:21px;font-weight:800;letter-spacing:-1px}.brand span{color:var(--lime)}.top-actions{display:flex;align-items:center;gap:15px;color:var(--muted);font-size:12px}
    h1,h2{margin:0 0 8px;letter-spacing:-.04em}h1{font-size:30px}h2{font-size:19px}.muted{color:var(--muted);font-size:12px}.panel{padding:22px;border:1px solid var(--line);border-radius:16px;background:var(--panel)}.login{width:min(440px,100%);margin:11vh auto}.grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:18px;align-items:start}.product-list{display:grid;gap:10px}.product-row{display:grid;grid-template-columns:62px 1fr auto;align-items:center;gap:13px;padding:12px;border:1px solid var(--line);border-radius:12px;background:#14151e}.thumb{width:62px;height:62px;display:grid;place-items:center;overflow:hidden;border-radius:10px;background:#28293a;font-size:26px}.thumb img{width:100%;height:100%;object-fit:cover}.product-row strong{display:block;font-size:13px}.product-row small{color:var(--muted);font-size:11px}.actions{display:flex;gap:7px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 13px;border:1px solid var(--line);border-radius:9px;background:#222431;color:var(--text);cursor:pointer;font:inherit;font-size:12px;font-weight:700}.button:hover{border-color:var(--lime)}.button.primary{background:var(--lime);border-color:var(--lime);color:#17190f}.button.danger{color:#ff9caf}.button.small{min-height:31px;padding:0 9px;font-size:11px}
    .form{display:grid;gap:12px}.field label{display:block;margin-bottom:5px;color:#d3d4df;font-size:11px;font-weight:700}.field input,.field textarea{width:100%;min-height:40px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;outline:0;background:#10111a;color:var(--text);font:inherit;font-size:12px}.field textarea{min-height:76px;resize:vertical}.field input:focus,.field textarea:focus{border-color:var(--lime)}.hint{display:block;margin-top:5px;color:var(--muted);font-size:10px}.alert{margin-bottom:14px;padding:11px 13px;border:1px solid rgba(200,251,99,.25);border-radius:9px;background:rgba(200,251,99,.08);color:var(--lime);font-size:12px}.alert.error{border-color:rgba(255,130,155,.3);background:rgba(255,130,155,.08);color:var(--red)}.upload-preview{width:100%;max-height:150px;object-fit:cover;border-radius:10px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:15px}.secure-note{margin-top:14px;padding:10px;border-radius:8px;background:#12131c;color:var(--muted);font-size:10px;line-height:1.6}
    @media(max-width:800px){.grid{grid-template-columns:1fr}.top{align-items:flex-start}.wrap{margin-top:23px}.form-panel{order:-1}}@media(max-width:520px){.product-row{grid-template-columns:48px 1fr}.thumb{width:48px;height:48px}.actions{grid-column:2}.top-actions{flex-direction:column;align-items:flex-end;gap:7px}h1{font-size:25px}.panel{padding:16px}}
  </style>
</head>
<body>
<?php if ($credentials === null && !$isLoggedIn): ?>
  <main class="wrap login">
    <div class="top"><a class="brand" href="index.html">lootlane<span>.</span></a><a href="index.html">← Lihat toko</a></div>
    <section class="panel">
      <h1>Buat akun admin</h1>
      <p class="muted">Pengaturan awal hanya muncul sekali. Buat username admin dan kata sandi yang kuat.</p>
      <?php if ($errorMessage !== ''): ?><div class="alert error"><?= admin_escape($errorMessage) ?></div><?php endif; ?>
      <form class="form" method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrfToken) ?>">
        <input type="hidden" name="action" value="setup">
        <div class="field"><label for="username">Username admin</label><input id="username" name="username" required maxlength="40" autocomplete="username" value="<?= admin_escape($formValues['username'] ?? '') ?>"></div>
        <div class="field"><label for="password">Kata sandi</label><input id="password" type="password" name="password" required minlength="12" autocomplete="new-password"><span class="hint">Minimal 12 karakter.</span></div>
        <div class="field"><label for="password_confirmation">Ulangi kata sandi</label><input id="password_confirmation" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></div>
        <button class="button primary" type="submit">Buat admin pertama</button>
      </form>
      <div class="secure-note">Kata sandi disimpan sebagai hash, bukan teks biasa. Jangan bagikan halaman admin kepada orang lain.</div>
    </section>
  </main>
<?php elseif (!$isLoggedIn): ?>
  <main class="wrap login">
    <div class="top"><a class="brand" href="index.html">lootlane<span>.</span></a><a href="index.html">← Lihat toko</a></div>
    <section class="panel">
      <h1>Masuk admin</h1><p class="muted">Kelola katalog dan gambar produk Lootlane.</p>
      <?php if ($errorMessage !== ''): ?><div class="alert error"><?= admin_escape($errorMessage) ?></div><?php endif; ?>
      <form class="form" method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrfToken) ?>">
        <input type="hidden" name="action" value="login">
        <div class="field"><label for="username">Username</label><input id="username" name="username" required autocomplete="username"></div>
        <div class="field"><label for="password">Kata sandi</label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
        <button class="button primary" type="submit">Masuk</button>
      </form>
    </section>
  </main>
<?php else: ?>
  <main class="wrap">
    <div class="top">
      <div><a class="brand" href="index.html">lootlane<span>.</span></a><h1 style="margin-top:18px">Panel admin</h1><span class="muted">Ubah katalog toko tanpa mengedit kode.</span></div>
      <div class="top-actions"><span>Masuk sebagai <?= admin_escape($_SESSION['admin_user']) ?></span><a href="index.html">Lihat toko ↗</a><form method="post"><input type="hidden" name="csrf_token" value="<?= admin_escape($csrfToken) ?>"><input type="hidden" name="action" value="logout"><button class="button small" type="submit">Keluar</button></form></div>
    </div>
    <?php if ($errorMessage !== ''): ?><div class="alert error"><?= admin_escape($errorMessage) ?></div><?php elseif ($noticeMessage !== ''): ?><div class="alert"><?= admin_escape($noticeMessage) ?></div><?php endif; ?>
    <div class="grid">
      <section class="panel">
        <div class="section-title"><div><h2>Daftar produk</h2><span class="muted"><?= count($products) ?> akun dalam katalog</span></div><a class="button primary" href="admin.php?new=1">+ Tambah produk</a></div>
        <div class="product-list">
          <?php foreach ($products as $product): ?>
            <article class="product-row">
              <div class="thumb"><?php if (!empty($product['image'])): ?><img src="<?= admin_escape($product['image']) ?>" alt=""><?php else: ?><?= admin_escape($product['emoji']) ?><?php endif; ?></div>
              <div><strong><?= admin_escape($product['name']) ?></strong><small><?= admin_escape($product['game']) ?> · Rp<?= number_format((int) $product['price'], 0, ',', '.') ?> · <?= admin_escape($product['seller']) ?></small></div>
              <div class="actions"><a class="button small" href="admin.php?edit=<?= (int) $product['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Hapus produk ini dari katalog?')"><input type="hidden" name="csrf_token" value="<?= admin_escape($csrfToken) ?>"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="<?= (int) $product['id'] ?>"><button class="button small danger" type="submit">Hapus</button></form></div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
      <?php if ($editingProduct !== null || $isCreating): ?>
        <section class="panel form-panel">
          <div class="section-title"><div><h2><?= $editingProduct ? 'Edit produk' : 'Tambah produk' ?></h2><span class="muted">Field bertanda * wajib diisi.</span></div><?php if ($editingProduct): ?><a class="button small" href="admin.php">Batal</a><?php endif; ?></div>
          <form class="form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="id" value="<?= admin_escape($formProduct['id'] ?? '') ?>">
            <div class="field"><label for="game">Nama game *</label><input id="game" name="game" required maxlength="60" value="<?= admin_escape($formProduct['game'] ?? '') ?>" placeholder="Contoh: Mobile Legends"></div>
            <div class="field"><label for="short">Label singkat *</label><input id="short" name="short" required maxlength="24" value="<?= admin_escape($formProduct['short'] ?? '') ?>" placeholder="Contoh: MLBB"></div>
            <div class="field"><label for="emoji">Emoji kategori *</label><input id="emoji" name="emoji" required maxlength="20" value="<?= admin_escape($formProduct['emoji'] ?? '🎮') ?>" placeholder="🎮"></div>
            <div class="field"><label for="name">Judul/deskripsi akun *</label><input id="name" name="name" required maxlength="120" value="<?= admin_escape($formProduct['name'] ?? '') ?>"></div>
            <div class="field"><label for="price">Harga (Rupiah) *</label><input id="price" name="price" type="number" min="1" max="999999999999" step="1" required value="<?= admin_escape($formProduct['price'] ?? '') ?>"></div>
            <div class="field"><label for="stats">Detail akun</label><textarea id="stats" name="stats" maxlength="200" placeholder="Pisahkan dengan koma atau baris baru"><?= admin_escape($statsValue) ?></textarea><span class="hint">Maksimal 6 detail.</span></div>
            <div class="field"><label for="seller">Nama seller *</label><input id="seller" name="seller" required maxlength="60" value="<?= admin_escape($formProduct['seller'] ?? '') ?>"></div>
            <div class="field"><label for="rating">Rating (0–5) *</label><input id="rating" name="rating" required inputmode="decimal" maxlength="3" value="<?= admin_escape($formProduct['rating'] ?? '5.0') ?>"></div>
            <div class="field"><label for="popularity">Urutan populer (0–100) *</label><input id="popularity" name="popularity" type="number" min="0" max="100" step="1" required value="<?= admin_escape($formProduct['popularity'] ?? '50') ?>"></div>
            <div class="field"><label for="image">Gambar produk</label><input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp"><span class="hint">JPG, PNG, WebP; maksimal 4 MB. Kosongkan untuk mempertahankan gambar saat ini.</span></div>
            <?php if ($currentImage !== ''): ?><img class="upload-preview" src="<?= admin_escape($currentImage) ?>" alt="Gambar produk saat ini"><?php endif; ?>
            <button class="button primary" type="submit">Simpan produk</button>
          </form>
        </section>
      <?php else: ?>
        <aside class="panel form-panel"><h2>Kelola katalog</h2><p class="muted">Pilih <strong>Edit</strong> pada produk untuk mengganti judul, harga, detail, seller, rating, atau gambar. Perubahan langsung tampil di halaman utama.</p><div class="secure-note">Gambar yang diunggah hanya menerima JPG, PNG, atau WebP dan disimpan di folder uploads. Data katalog dan kata sandi admin tidak dapat dibuka langsung dari browser.</div></aside>
      <?php endif; ?>
    </div>
    <section class="panel" style="margin-top:18px">
      <div class="section-title"><div><h2>Pengaturan pembayaran</h2><span class="muted">Rincian ini ditampilkan kepada pembeli sebagai instruksi transfer preview.</span></div></div>
      <form class="form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrfToken) ?>">
        <input type="hidden" name="action" value="save_payments">
        <div class="grid">
          <section>
            <h2>DANA</h2>
            <div class="field"><label for="dana_number">Nomor DANA</label><input id="dana_number" name="dana_number" maxlength="40" value="<?= admin_escape($paymentForm['dana_number'] ?? '') ?>" placeholder="Contoh: 0812xxxxxxx"></div>
            <div class="field"><label for="dana_name">Nama pemilik DANA</label><input id="dana_name" name="dana_name" maxlength="80" value="<?= admin_escape($paymentForm['dana_name'] ?? '') ?>" placeholder="Nama sesuai akun"></div>
          </section>
          <section>
            <h2>QRIS</h2>
            <div class="field"><label for="qris_name">Nama merchant (opsional)</label><input id="qris_name" name="qris_name" maxlength="80" value="<?= admin_escape($paymentForm['qris_name'] ?? '') ?>" placeholder="Nama merchant"></div>
            <div class="field"><label for="qris_image">Gambar kode QRIS</label><input id="qris_image" name="qris_image" type="file" accept="image/jpeg,image/png,image/webp"><span class="hint">JPG, PNG, WebP; maksimal 4 MB. Upload gambar QRIS statis yang valid.</span></div>
            <?php if (($paymentForm['qris_image'] ?? '') !== ''): ?>
              <img class="upload-preview" src="<?= admin_escape($paymentForm['qris_image']) ?>" alt="Kode QRIS saat ini">
              <label class="muted"><input type="checkbox" name="remove_qris" value="1"> Hapus gambar QRIS saat ini</label>
            <?php endif; ?>
          </section>
          <section>
            <h2>Transfer bank</h2>
            <div class="field"><label for="bank_name">Nama bank</label><input id="bank_name" name="bank_name" maxlength="60" value="<?= admin_escape($paymentForm['bank_name'] ?? '') ?>" placeholder="Contoh: BCA"></div>
            <div class="field"><label for="bank_account">Nomor rekening</label><input id="bank_account" name="bank_account" maxlength="40" value="<?= admin_escape($paymentForm['bank_account'] ?? '') ?>" placeholder="Nomor rekening"></div>
            <div class="field"><label for="bank_holder">Nama pemilik rekening</label><input id="bank_holder" name="bank_holder" maxlength="80" value="<?= admin_escape($paymentForm['bank_holder'] ?? '') ?>" placeholder="Nama sesuai rekening"></div>
          </section>
          <section>
            <h2>E-wallet lainnya</h2>
            <div class="field"><label for="ewallet_provider">Nama layanan</label><input id="ewallet_provider" name="ewallet_provider" maxlength="40" value="<?= admin_escape($paymentForm['ewallet_provider'] ?? '') ?>" placeholder="Contoh: GoPay atau OVO"></div>
            <div class="field"><label for="ewallet_number">Nomor e-wallet</label><input id="ewallet_number" name="ewallet_number" maxlength="40" value="<?= admin_escape($paymentForm['ewallet_number'] ?? '') ?>" placeholder="Nomor akun"></div>
            <div class="field"><label for="ewallet_name">Nama pemilik e-wallet</label><input id="ewallet_name" name="ewallet_name" maxlength="80" value="<?= admin_escape($paymentForm['ewallet_name'] ?? '') ?>" placeholder="Nama sesuai akun"></div>
          </section>
        </div>
        <div class="secure-note">Hanya metode yang datanya lengkap akan muncul di checkout. Nomor rekening dan e-wallet akan dapat dilihat publik oleh pembeli. Pembayaran manual belum diverifikasi otomatis.</div>
        <button class="button primary" type="submit">Simpan pengaturan pembayaran</button>
      </form>
    </section>
  </main>
<?php endif; ?>
</body>
</html>
