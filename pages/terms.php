<?php
// ============================================
// SYARAT & KETENTUAN
// Website Nadhira Napoleon Pekanbaru
// ============================================
require_once '../config/database.php';

$page_title = 'Syarat & Ketentuan';
$meta_description = 'Syarat & ketentuan penggunaan website dan pemesanan Nadhira Napoleon Pekanbaru.';
include '../includes/header.php';
?>

<section style="padding-top: calc(var(--navbar-total-height, 120px) + 8px); padding-bottom: var(--space-3xl);">
    <div class="container" style="max-width: 860px;">
        <!-- Breadcrumb -->
        <nav style="display: flex; align-items: center; gap: var(--space-sm); font-size: var(--text-sm); color: var(--text-muted); margin-bottom: var(--space-lg);">
            <a href="<?= SITE_URL ?>" style="color: var(--soft-gold);">Beranda</a>
            <i class="fas fa-chevron-right" style="font-size: 10px;"></i>
            <span>Syarat & Ketentuan</span>
        </nav>

        <!-- Judul -->
        <div style="text-align: center; margin-bottom: var(--space-2xl);">
            <span style="display: inline-block; padding: 6px 14px; background: var(--soft-gold); color: #fff; border-radius: 999px; font-size: var(--text-xs); font-weight: 600; letter-spacing: 1px; margin-bottom: var(--space-lg);">LEGAL</span>
            <h1 style="font-family: var(--font-display); font-size: var(--text-3xl); font-weight: 700; margin-bottom: var(--space-sm);">
                Syarat &amp; <span class="gold-text">Ketentuan</span>
            </h1>
            <p style="color: var(--text-muted);">Terakhir diperbarui: <?= formatDate('2026-08-06') ?></p>
        </div>

        <!-- Kartu konten -->
        <div style="background: var(--warm-white); border: 1px solid var(--soft-grey); border-radius: var(--radius-xl); padding: var(--space-2xl); box-shadow: var(--shadow-sm);">
            <div style="display: flex; flex-direction: column; gap: var(--space-xl);">

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-file-contract" style="color: var(--soft-gold);"></i> 1. Penerimaan Ketentuan
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Dengan mengakses dan menggunakan website <strong>Nadhira Napoleon Pekanbaru</strong> (selanjutnya disebut "kami"), serta membuat akun atau melakukan pemesanan, Anda dianggap telah membaca, memahami, dan menyetujui seluruh syarat & ketentuan ini. Jika Anda tidak setuju dengan sebagian atau seluruh isinya, mohon untuk tidak menggunakan layanan kami.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-user-lock" style="color: var(--soft-gold);"></i> 2. Akun &amp; Pendaftaran
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Untuk dapat melakukan pemesanan, Anda wajib memiliki akun dengan data yang benar dan lengkap. Anda bertanggung jawab penuh atas kerahasiaan kata sandi dan seluruh aktivitas yang terjadi pada akun Anda. Segera hubungi kami jika terdapat aktivitas mencurigakan pada akun Anda. Kami berhak menangguhkan atau menutup akun yang melanggar ketentuan ini.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-shopping-bag" style="color: var(--soft-gold);"></i> 3. Pemesanan &amp; Pembayaran
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Pesanan dianggap sah setelah proses checkout selesai dan pembayaran berhasil. Pembayaran diproses melalui <strong>Midtrans</strong> (Virtual Account, QRIS, E-Wallet, dan kartu kredit) dengan keamanan standar industri. Status pesanan akan diperbarui otomatis menjadi <strong>LUNAS</strong> setelah pembayaran terverifikasi. Kami berhak membatalkan pesanan yang terindikasi penipuan, data tidak valid, atau stok tidak tersedia.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-tag" style="color: var(--soft-gold);"></i> 4. Harga &amp; Ketersediaan Produk
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Seluruh harga tercantum dalam Rupiah (Rp) dan dapat berubah sewaktu-waktu tanpa pemberitahuan terlebih dahulu. Harga yang berlaku adalah harga pada saat checkout. Ketersediaan produk dapat berubah; jika produk yang Anda pesan tidak tersedia, kami akan menghubungi Anda untuk penggantian atau pengembalian dana.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-truck" style="color: var(--soft-gold);"></i> 5. Pengiriman
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Saat ini kami memberlakukan <strong>ongkir gratis</strong> untuk seluruh pesanan reguler. Waktu pengiriman bergantung pada lokasi tujuan dan jasa kurir. Risiko keterlambatan di luar kendali kami (cuaca, hari libur, alamat tidak lengkap) bukan tanggung jawab kami. Pastikan alamat pengiriman yang Anda isi benar; kesalahan alamat menjadi tanggung jawab pemesan.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-rotate-left" style="color: var(--soft-gold);"></i> 6. Pengembalian &amp; Retur
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Produk makanan/oleh-oleh bersifat mudah rusak, sehingga hanya dapat dikembalikan jika diterima dalam kondisi rusak, salah produk, atau salah jumlah — dengan melampirkan dokumentasi (foto/video) saat kemasan diterima dalam waktu maksimal 1&times;24 jam. Klaim yang lolos akan diganti produk atau dananya dikembalikan sesuai kebijakan.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-gem" style="color: var(--soft-gold);"></i> 7. Poin &amp; Program Membership
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Poin loyalty diberikan setelah pembayaran dinyatakan LUNAS, sesuai aturan program membership yang berlaku. Poin tidak dapat ditukar dengan uang tunai dan dapat hangus bila akun ditutup. Kami berhak mengubah aturan poin, level, atau program membership sewaktu-waktu.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-copyright" style="color: var(--soft-gold);"></i> 8. Kekayaan Intelektual
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Seluruh konten di website ini — termasuk teks, gambar, logo, dan desain — dilindungi hak cipta dan tidak boleh digunakan, disalin, atau didistribusikan tanpa izin tertulis dari kami.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-scale-balanced" style="color: var(--soft-gold);"></i> 9. Batasan Tanggung Jawab
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Tanggung jawab kami terbatas pada nilai pesanan yang dibayarkan. Kami tidak bertanggung jawab atas kerugian tidak langsung, insidental, atau konsekuensial akibat penggunaan website ini, termasuk gangguan layanan, keterlambatan, atau kesalahan informasi.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-pen-to-square" style="color: var(--soft-gold);"></i> 10. Perubahan Syarat &amp; Ketentuan
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Kami dapat memperbarui syarat & ketentuan ini sewaktu-waktu. Perubahan akan berlaku sejak diunggah di halaman ini. Dengan terus menggunakan website, Anda dianggap menyetujui perubahan tersebut.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-gavel" style="color: var(--soft-gold);"></i> 11. Hukum yang Berlaku
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Syarat & ketentuan ini diatur oleh hukum Republik Indonesia. Segala sengketa akan diselesaikan secara musyawarah terlebih dahulu, dan bila tidak tercapai, melalui pengadilan yang berwenang di Pekanbaru.
                    </p>
                </div>

                <div>
                    <h2 style="font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-sm);">
                        <i class="fas fa-envelope" style="color: var(--soft-gold);"></i> 12. Hubungi Kami
                    </h2>
                    <p style="color: var(--text-muted); font-size: var(--text-sm); line-height: 1.8;">
                        Jika Anda memiliki pertanyaan mengenai syarat & ketentuan ini, silakan hubungi kami melalui halaman <a href="<?= SITE_URL ?>#contact" style="color: var(--soft-gold);">kontak</a>.
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
