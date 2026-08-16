<?php
// ============================================
// INSTALLER DATABASE — NADHIRA NAPOLEON
// ============================================
// 🔒 DIPROTEKSI: file ini HANYA bisa dijalankan dari TERMINAL (CLI).
//    Mengakses dari browser akan ditolak (403) — mencegah siapa pun
//    mereset / menghapus seluruh database secara tidak sengaja.
//
//    Cara pakai (terminal):
//      cd C:\laragon\www\nad
//      php run_install.php
//
//    ⚠️ Setelah instalasi berhasil, HAPUS file ini dari server
//       (terutama saat sudah di-hosting)!
// ============================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>403 — Akses Ditolak</title></head>';
    echo '<body style="font-family:Segoe UI,Arial,sans-serif;background:#FEF2F2;margin:0;padding:48px 20px;text-align:center">';
    echo '<div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #FECACA;border-radius:16px;padding:40px">';
    echo '<div style="font-size:48px">🔒</div>';
    echo '<h1 style="color:#DC2626;margin:12px 0 8px">403 — Akses Ditolak</h1>';
    echo '<p style="color:#6B7280;line-height:1.6">Installer database <b>tidak boleh</b> diakses dari browser.<br>';
    echo 'Jalankan melalui terminal (Command Prompt / Git Bash):</p>';
    echo '<pre style="background:#111827;color:#D1D5DB;padding:14px 18px;border-radius:10px;display:inline-block;text-align:left">php ' . htmlspecialchars(basename(__FILE__)) . '</pre>';
    echo '<p style="color:#9CA3AF;font-size:13px;margin-top:24px">Jangan biarkan file ini berada di server produksi.</p>';
    echo '</div></body></html>';
    exit;
}

// Jalankan installer (CLI only)
$_GET['confirm'] = 'yes';
$_GET['run'] = '1';
require_once __DIR__ . '/database/init.php';
