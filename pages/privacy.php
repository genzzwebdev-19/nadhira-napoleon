<?php
// ============================================
// KEBIJAKAN PRIVASI
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';

$page_title = 'Kebijakan Privasi';
$meta_description = 'Kebijakan privasi Nadhira Napoleon Pekanbaru — bagaimana kami mengumpulkan, menggunakan, dan melindungi data Anda.';
include '../includes/header.php';
?>

<section style="padding-top: calc(var(--navbar-total-height, 120px) + 8px); padding-bottom: var(--space-3xl);">
    <div class="container" style="max-width: 860px;">
        <!-- Breadcrumb -->
        <nav style="display: flex; align-items: center; gap: var(--space-sm); font-size: var(--text-sm); color: var(--text-muted); margin-bottom: var(--space-lg);">
            <a href="<?= SITE_URL ?>" style="color: var(--soft-gold);">Beranda</a>
            <i class="fas fa-chevron-right" style="font-size: 10px;"></i>
            <span>Kebijakan Privasi</span>
        </nav>

        <!-- Judul -->
        <div style="text-align: center; margin-bottom: var(--space-2xl);">
            <span style="display: inline-block; padding: 6px 14px; background: var(--soft-gold); color: #fff; border-radius: 999px; font-size: var(--text-xs); font-weight: 600; letter-spacing: 1px; margin-bottom: var(--space-lg);">PRIVASI</span>
            <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-sm);">
                Kebijakan <span class="gold-text">Privasi</span>
            </h1>
            <p style="color: var(--text-muted);">Terakhir diperbarui: <?= formatDate('2026-08-06') ?></p>
        </div>

        <!-- Kartu konten -->
        <div style="background: var(--warm-white); border: 1px solid var(--soft-grey); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-sm);">
            <div style="display: flex; flex-direction: column; gap: var(--space-xl);">

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-shield-halved" style="color: var(--soft-gold);"></i> 1. Pendahuluan
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Kami (Nadhira Napoleon Pekanbaru) menghargai privasi Anda. Kebijakan privasi ini menjelaskan bagaimana kami mengumpulkan, menggunakan, menyimpan, dan melindungi data pribadi Anda saat mengunjungi website atau melakukan transaksi. Dengan menggunakan layanan kami, Anda menyetujui praktik yang dijelaskan di bawah ini.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-database" style="color: var(--soft-gold);"></i> 2. Data yang Kami Kumpulkan
                    </h2>
                    <ul style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8; padding-left: 1.2rem; display: flex; flex-direction: column; gap: var(--space-xs);">
                        <li><strong>Data akun:</strong> nama lengkap, username, email, nomor telepon, dan kata sandi (tersimpan terenkripsi).</li>
                        <li><strong>Data transaksi:</strong> alamat pengiriman, riwayat pesanan, metode pembayaran, dan total belanja.</li>
                        <li><strong>Data teknis:</strong> alamat IP, jenis perangkat/browser, dan halaman yang dikunjungi (untuk keamanan & perbaikan layanan).</li>
                    </ul>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-wand-magic-sparkles" style="color: var(--soft-gold);"></i> 3. Cara Kami Menggunakan Data
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Data Anda digunakan untuk: memproses pesanan & pembayaran, mengirim notifikasi status pesanan, menghitung poin & level membership, memberikan layanan pelanggan, mengirim informasi promo (hanya dengan persetujuan Anda), serta menjaga keamanan dan mencegah penipuan. Kami tidak menjual data pribadi Anda kepada pihak mana pun.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-cookie-bite" style="color: var(--soft-gold);"></i> 4. Cookie &amp; "Ingat Saya"
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Website menggunakan <strong>cookie sesi</strong> agar Anda tetap login selama menjelajah, dan cookie <strong>"Ingat Saya"</strong> (opsional, aktif secara default) agar Anda tidak perlu login ulang pada kunjungan berikutnya. Anda dapat menghapus centang "Ingat Saya" di halaman login atau menghapus cookie melalui pengaturan browser kapan saja.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-handshake" style="color: var(--soft-gold);"></i> 5. Berbagi Data dengan Pihak Ketiga
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Data transaksi diproses oleh <strong>Midtrans</strong> sebagai penyedia gateway pembayaran sesuai kebijakan privasi mereka — kami tidak menyimpan nomor kartu atau data pembayaran sensitif Anda. Data pengiriman dibagikan kepada jasa kurir hanya sebatas kebutuhan pengiriman pesanan.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-lock" style="color: var(--soft-gold);"></i> 6. Keamanan Data
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Kami menerapkan langkah keamanan yang wajar: kata sandi di-hash, sesi dilindungi token, cookie dibuat <em>HttpOnly</em>, dan akses data dibatasi hanya untuk staf yang berwenang. Meski demikian, tidak ada metode transmisi data di internet yang 100% aman.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-user-check" style="color: var(--soft-gold);"></i> 7. Hak Anda
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Anda berhak mengakses, memperbaiki, atau memperbarui data pribadi Anda melalui halaman <a href="<?= SITE_URL ?>/auth/profile.php" style="color: var(--soft-gold);">profil</a>, serta meminta penghapusan akun dengan menghubungi kami. Permintaan penghapusan akan kami proses sesuai ketentuan yang berlaku.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-pen-to-square" style="color: var(--soft-gold);"></i> 8. Perubahan Kebijakan Privasi
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Kebijakan ini dapat diperbarui sewaktu-waktu; tanggal "terakhir diperbarui" di bagian atas halaman akan kami sesuaikan. Perubahan berlaku sejak diunggah.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-envelope" style="color: var(--soft-gold);"></i> 9. Hubungi Kami
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Untuk pertanyaan seputar privasi data Anda, silakan hubungi kami melalui halaman <a href="<?= SITE_URL ?>#contact" style="color: var(--soft-gold);">kontak</a>.
                    </p>
                </div>

            </div>
        </div>

        <!-- CTA kembali -->
        <div style="text-align: center; margin-top: var(--space-2xl);">
            <a href="<?= SITE_URL ?>/auth/register.php" class="btn btn-primary btn-lg">
                <i class="fas fa-user-plus"></i>
                Daftar Sekarang
            </a>
        </div>
    </div>
</section>

<?php include '../includes/footer.php'; ?>
