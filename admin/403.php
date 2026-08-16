<?php
require_once __DIR__ . '/../config/rbac.php';
$module = $_GET['module'] ?? '';
$action = $_GET['action'] ?? 'view';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Akses Ditolak - Admin <?= SITE_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .card {
            background: #fff; border-radius: 24px; padding: 48px; max-width: 480px; width: 100%;
            text-align: center; box-shadow: 0 24px 80px rgba(0,0,0,0.3);
        }
        .lock { font-size: 56px; margin-bottom: 20px; }
        .code { font-family: 'Playfair Display', serif; font-size: 72px; font-weight: 700; line-height: 1; color: #DC2626; }
        h1 { font-family: 'Playfair Display', serif; font-size: 24px; color: #1a1a2e; margin: 8px 0 12px; }
        p { color: #666; font-size: 14px; line-height: 1.7; margin-bottom: 8px; }
        .module-tag {
            display: inline-block; background: #FEF3C7; color: #92400E; font-size: 12px;
            padding: 4px 14px; border-radius: 20px; margin: 12px 0 24px; font-weight: 600;
        }
        .btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px;
            background: linear-gradient(135deg, #D4A853, #B8860B); color: #fff; text-decoration: none;
            border-radius: 50px; font-weight: 600; font-size: 14px; transition: all 0.25s ease;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(212,168,83,0.4); }
        .btn-secondary {
            display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px;
            border: 1.5px solid #e5e0db; color: #1a1a2e; text-decoration: none;
            border-radius: 50px; font-weight: 600; font-size: 14px; margin-left: 8px;
            transition: all 0.25s ease;
        }
        .btn-secondary:hover { border-color: #D4A853; color: #D4A853; }
    </style>
</head>
<body>
    <div class="card">
        <div class="lock">🔒</div>
        <div class="code">403</div>
        <h1>Akses Ditolak</h1>
        <p>Anda tidak memiliki izin untuk mengakses halaman ini.</p>
        <p>Role Anda tidak memiliki permission <strong><?= htmlspecialchars($module) ?>:<?= htmlspecialchars($action) ?></strong>.</p>
        <span class="module-tag"><?= htmlspecialchars($module) ?> / <?= htmlspecialchars($action) ?></span>
        <div>
            <a href="index.php" class="btn"><i class="fas fa-home"></i> Ke Dashboard</a>
            <a href="javascript:history.back()" class="btn-secondary">Kembali</a>
        </div>
    </div>
</body>
</html>
