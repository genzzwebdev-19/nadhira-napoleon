# 📋 Rancangan Pengembangan Website Nadhira Napoleon

> **Project:** Nadhira Napoleon — Premium Oleh-Oleh Khas Riau
> **Tech Stack:** PHP Native, MySQL, HTML5, CSS3, JavaScript (Vanilla)
> **Status:** ✅ Sudah berjalan, perlu penyempurnaan

---

## ✅ FITUR YANG SUDAH JADI

### 🏠 Halaman Depan (Frontend)
- [x] **Landing Page** — Hero video + overlay, stat counter, story, features, produk best seller, promo timer, paket oleh-oleh, video gallery, testimonial, artikel, FAQ, contact form, newsletter, footer
- [x] **Katalog Produk** — Grid produk, search, filter kategori
- [x] **Detail Produk** — Gallery gambar, tabs (deskripsi, komposisi, penyimpanan, ulasan), qty, add-to-cart, wishlist
- [x] **Keranjang Belanja** — AJAX-based, update qty, hapus item
- [x] **Wishlist** — Simpan & hapus produk favorit
- [x] **Checkout** — Form lengkap, multi payment (transfer, COD, e-wallet)
- [x] **Invoice & PDF** — Halaman invoice + download PDF (Dompdf)
- [x] **Konfirmasi Pembayaran** — Upload bukti transfer
- [x] **Tracking Pesanan** — Cek status via invoice + email
- [x] **Auth** — Login, register, profile, logout
- [x] **Newsletter** — Subscribe email

### 🛠️ Admin Panel
- [x] **Dashboard** — Statistik produk, pesanan, pelanggan, revenue, alert stok rendah, pesan baru
- [x] **Manajemen Produk** — CRUD + gambar + harga diskon + badge (best seller/featured)
- [x] **Manajemen Kategori** — CRUD + sort order + modal edit
- [x] **Manajemen Pesanan** — CRUD + update status + tracking number
- [x] **Manajemen Cabang** — CRUD + koordinat maps + toggle aktif
- [x] **Manajemen Promo** — CRUD + diskon persen/nominal + tanggal
- [x] **Manajemen Artikel** — CRUD + publish
- [x] **Manajemen FAQ** — CRUD + kategori + sort order
- [x] **Manajemen Testimonial** — CRUD + featured
- [x] **Manajemen Video** — CRUD video galery
- [x] **Manajemen Pelanggan** — Lihat daftar pelanggan
- [x] **Pesan Masuk** — Lihat & tandai sudah dibaca
- [x] **Pengaturan** — Setting site name, kontak, sosmed, bank, dll

### 🗄️ Database
- [x] **20+ Tables** — users, products, categories, orders, cart, wishlist, branches, promotions, articles, testimonials, FAQ, contacts, newsletter, settings, dll
- [x] **Seeder** — Data awal (admin, cabang, FAQ, testimonial, kategori, membership benefits)

---

## 🔴 PRIORITAS TINGGI (Harus segera dikerjakan)

### 1. 🔧 Sistem Membership & Poin
**Mengapa penting:** Database sudah punya tabel `users` dengan field `membership`, `points`, `total_spent` dan tabel `membership_benefits`, tapi belum difungsikan.

**Yang perlu dibuat:**
- [x] Halaman profil member — menampilkan level (Silver/Gold/Platinum/Diamond), poin, progress ke level berikutnya (`pages/membership.php` + kartu di `auth/profile.php`)
- [x] Perhitungan poin otomatis — setiap transaksi menambah poin (1 poin per Rp 10.000 × multiplier level, di `pages/checkout.php`)
- [x] Upgrade level otomatis — berdasarkan total belanja (`awardOrderRewards()` di `config/database.php`)
- [x] Manfaat tiap level — ditampilkan di halaman membership (dari tabel `membership_benefits`)
- [x] Badge member di navbar — menampilkan level user (`includes/header.php`)
- [x] Harga khusus member — diskon berdasarkan level ✅ (v1.22.0: persentase per level di Admin > Pengaturan, otomatis di keranjang/checkout + badge harga member di detail produk)
- [x] Admin: edit level member, atur benefit (`admin/membership.php`)

**Status: ✅ Selesai (v1.22.0)**

### 2. 🔧 Integrasi Kode Promo di Transaksi
**Mengapa penting:** Tabel `promotions` sudah ada dan admin bisa manage, tapi aplikasi kode promo di keranjang/checkout belum terintegasi penuh.

**Yang perlu dibuat:**
- [x] Validasi kode promo di halaman cart — cek ke tabel promotions (kolom `code`, diatur di Admin → Promo)
- [x] Hitung diskon otomatis (persen/nominal) — kurangi subtotal (dibatasi maksimal = subtotal)
- [x] Cek minimal pembelian — validasi min_purchase
- [x] Cek masa berlaku — validasi start_date/end_date
- [x] Tampilkan diskon di ringkasan belanja (keranjang & checkout, + tombol hapus)
- [x] Simpan promo yang digunakan ke order (kolom `promo_code`, tampil di invoice/tracking/PDF/admin)
- [x] Batasi penggunaan — kuota pemakaian maksimal + hitung pemakaian ✅ (v1.22.0: `max_uses`/`used_count` di Admin > Promo, validasi di keranjang & checkout, kuota dikembalikan saat order dibatalkan)

**Status: ✅ Selesai (v1.22.0)**

### 3. 🔧 Sistem Ulasan Produk dari Pengguna
**Mengapa penting:** Tabel `product_reviews` sudah ada, tapi user belum bisa submit review dari frontend.

**Yang perlu dibuat:**
- [x] Form ulasan di halaman detail produk — rating bintang + komentar ✅ (v1.22.0)
- [x] Validasi — hanya pembeli terverifikasi bisa review ✅ (v1.22.0: pesanan berstatus Selesai yang berisi produk tsb; 1 ulasan/produk/akun)
- [x] Tampilkan ulasan di tab "Ulasan" produk ✅ (sudah ada, + form di atasnya)
- [x] Hitung rata-rata rating otomatis ✅ (v1.22.0: `recalcProductRating()` saat approve/hapus)
- [x] Admin: approve/tolak ulasan ✅ (v1.22.0: `admin/reviews.php` — setujui/tolak/hapus + filter status)

**Status: ✅ Selesai (v1.22.0)**

### 4. 🔧 Tabel Video Gallery
**Mengapa penting:** Admin/videos.php dan landing page mereferensi tabel `video_gallery` yang BELUM ADA di schema.sql.

**Yang perlu dibuat:**
- [x] Tambah tabel `video_gallery` ke schema.sql
- [x] Migrasi database — self-healing (tabel dibuat otomatis saat runtime, tanpa migrasi manual)
- [x] Pastikan admin videos.php berfungsi

**Status: ✅ Selesai**

---

## 🟡 PRIORITAS SEDANG

### 5. Manajemen Stok Cabang — ✅ Selesai
- [x] Admin UI untuk mengatur produk apa saja yang tersedia di tiap cabang (branch_products) — `admin/branch-stock.php`
- [x] Tampilkan ketersediaan di halaman detail produk per cabang — panel ketersediaan + stok per cabang

### 6. Konfirmasi Pembayaran oleh Admin — ✅ Selesai
- [x] Halaman admin untuk melihat & memverifikasi bukti transfer — `admin/payments.php`
- [x] Update status pembayaran (pending → verified/rejected)
- [x] Beri alasan jika ditolak
- [x] Notifikasi ke user saat status berubah (poin diberikan saat lunas, email & notifikasi in-app)
- Catatan: pembayaran baru sudah otomatis terverifikasi via Midtrans (webhook) — verifikasi manual hanya untuk konfirmasi lama

### 7. Riwayat Pesanan di Profil User — ✅ Selesai
- [x] Tabel daftar pesanan user — `auth/profile.php` → "Pesanan Saya"
- [x] Status pesanan real-time
- [x] Tombol: detail, tracking, download invoice, konfirmasi terima

### 8. Multi Alamat Pengiriman — ✅ Selesai (v1.19.0)
- [x] User bisa simpan beberapa alamat — tabel `shipping_addresses`
- [x] Pilih alamat saat checkout (otomatis terisi, pemilih alamat tersimpan)
- [x] Tabel `shipping_addresses` baru

### 9. Export Data Admin — ✅ Selesai
- [x] Export produk ke CSV/Excel — `admin/reports.php`
- [x] Export pesanan ke CSV/Excel
- [x] Export laporan pendapatan (per periode)

---

## 🟢 PRIORITAS RENDAH (Enhancement)

### 10. Notifikasi Email ✅ (v1.21.0 — PHPMailer + SMTP, konfig di Admin > Pengaturan)
- [x] Email konfirmasi pesanan (`config/mail.php` → `sendOrderConfirmationEmail()`, dipicu `pages/checkout.php`)
- [x] Email tracking number / nomor resi (`sendTrackingNumberEmail()`, dipicu `admin/orders.php` & `admin/order-detail.php`)
- [x] Email reset password (`auth/forgot-password.php` + `auth/reset-password.php` + tabel `password_resets`, token 30 menit sekali pakai)
- [x] Email newsletter broadcast (`admin/marketing.php` → `sendNewsletterBroadcastEmail()`)
- [x] Konfigurasi SMTP + tombol uji kirim di `admin/settings.php`

### 11. Tampilan & UX
- [ ] **Dark mode toggle**
- [ ] **Animasi GSAP lebih banyak** — untuk transisi halaman
- [ ] **Skeleton loading** — untuk produk dan konten
- [ ] **Infinite scroll** — untuk katalog produk
- [ ] **Quick view modal** — lihat produk cepat tanpa buka halaman detail
- [ ] **Price range filter** — filter harga di katalog

### 12. Dashboard Admin — sebagian selesai
- [x] **Grafik penjualan** — bulanan/tahunan (Chart.js) ✅
- [x] **Grafik produk terlaris** — top 10 ✅ (di `admin/reports.php`)
- [x] **Rekap pendapatan** — harian, mingguan, bulanan ✅ (dashboard + reports)
- [ ] **Export laporan PDF** — belum (baru PDF invoice per pesanan)

### 13. Keamanan — sebagian selesai
- [x] **CSRF token** — untuk semua form ✅ (semua aksi tulis admin)
- [ ] **Rate limiting** — login, register, contact form (login sudah ada lockout; register/kontak belum)
- [x] **Input sanitasi** ✅ (escape & real_escape_string)
- [x] **Log aktivitas admin** ✅ (tabel `activity_logs`)


### 14. Performa
- [ ] **Image compression** — saat upload produk
- [ ] **Lazy loading** — sudah ada sebagian, perlu disempurnakan
- [ ] **Caching** — query database
- [ ] **Minify CSS/JS** — untuk production

---

## 📊 ESTIMASI PRIORITAS

| Prioritas | Fitur | Estimasi Pengerjaan |
|---|---|---|
| 🔴 Tinggi | Membership & Poin | 2-3 hari |
| 🔴 Tinggi | Integrasi Kode Promo | 1 hari |
| 🔴 Tinggi | Sistem Ulasan Produk | 1 hari |
| 🔴 Tinggi | Tabel Video Gallery | 0.5 hari |
| 🟡 Sedang | Stok Cabang | 1 hari |
| 🟡 Sedang | Konfirmasi Pembayaran Admin | 1 hari |
| 🟡 Sedang | Riwayat Pesanan User | 1 hari |
| 🟡 Sedang | Multi Alamat | 1 hari |
| 🟡 Sedang | Export Data | 0.5 hari |
| 🟢 Rendah | Notifikasi Email | 2 hari |
| 🟢 Rendah | Tampilan & UX | 3-4 hari |
| 🟢 Rendah | Dashboard Admin | 2 hari |
| 🟢 Rendah | Keamanan | 1-2 hari |
| 🟢 Rendah | Performa | 1-2 hari |

---

> **Catatan:** Rancangan ini bisa berubah sesuai kebutuhan dan prioritas yang berkembang.
> Semua estimasi bersifat relatif dan tergantung kompleksitas saat pengerjaan.
