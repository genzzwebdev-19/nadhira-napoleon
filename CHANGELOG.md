# 📋 Changelog — Nadhira Napoleon Pekanbaru

> Catatan setiap perubahan & pengembangan pada website  
> **Project:** Premium Oleh-Oleh Khas Riau

---

## [1.23.0] — 2026-08-17

### 📦 Stok Otomatis Sinkron dengan Pesanan

#### 🙋 Aksi Pesanan oleh User (Batalkan & Konfirmasi Terima)
Pelanggan kini bisa mengelola pesanannya sendiri tanpa harus menghubungi admin via WhatsApp.

- **Batalkan Pesanan**: tombol **Batalkan** muncul di `auth/profile.php` (kartu "Pesanan Saya") & `pages/tracking.php` untuk pesanan berstatus `pending` dan belum lunas — pembatalan pesanan yang sudah dibayar tetap lewat admin (perlu refund). Saat dibatalkan, semua yang ter-reserve dikembalikan otomatis: stok produk (`restoreOrderStock`), jumlah terjual, poin & total belanja (`reverseOrderRewards`), poin yang ditukar jadi diskon (`refundPointsForOrder`), langganan membership, dan kuota kode promo (`decrementPromoUsage`)
- **Konfirmasi Terima**: tombol **Konfirmasi Terima** muncul untuk pesanan berstatus `shipped` — pelanggan menandai pesanan sudah diterima (status → `delivered`) tanpa menunggu admin
- **Helper baru** di `config/database.php`: `cancelOrderByUser($orderId, $userId)` & `confirmReceivedByUser($orderId, $userId)` — keduanya memvalidasi kepemilikan (order milik user yang login) & status yang diizinkan, dan dicatat ke `activity_logs`
- **Endpoint baru** `ajax/order-action.php` (POST `action=cancel_order|confirm_received` + `order_id` + CSRF) — aksi hanya bisa dilakukan oleh pemilik pesanan, dengan konfirmasi di frontend

### 📦 Stok Otomatis Sinkron dengan Pesanan

#### ⏰ Auto-Expire Pesanan Pending (Tidak Dibayar)
Pesanan yang dibuat tapi tidak dibayar dalam batas waktu tertentu otomatis dibatalkan — stok & kuota tidak terkunci selamanya.

- **Batas waktu default 24 jam** (sama dengan masa berlaku token Midtrans), bisa diubah di **Admin → Pengaturan → Midtrans → Auto-Expire Pesanan Belum Dibayar (jam)** — kolom `settings.order_expiry_hours`, self-healing default `24`
- **Helper baru** di `config/database.php`: `getOrderExpiryHours()`, `runOrderExpiryIfDue()` (throttle 1x/jam via `settings.order_expiry_last_run`), `expirePendingOrders()`, `expireOrder($orderId)`
- **Pemicu (poor man's cron)**: `index.php` (halaman depan, paling sering dikunjungi) & `admin/layout.php` (sama seperti penjadwal backup otomatis) — keduanya memanggil `runOrderExpiryIfDue()`
- **Runner khusus** `auto-expire.php` untuk cron sungguhan (CLI `php auto-expire.php [--force]` atau HTTP `?key=KUNCI&force=1`) — mengikuti pola `auto-backup.php`; kunci `auto_expire_key` **dibuat otomatis** (`autoExpireKey()`) dan panduan cron-nya ditampilkan di **Admin → Backup & Restore** (kartu "Auto-Expire Pesanan Belum Dibayar")
- **Saat kedaluwarsa**, semua yang ter-reserve dikembalikan: stok produk (`restoreOrderStock`), jumlah terjual, poin & total belanja (hanya jika pernah diberikan), poin tukar diskon, kuota kode promo, dan langganan membership; status → `cancelled`, dicatat ke `activity_logs`
- **Aman**: hanya pesanan `order_status = 'pending'` dengan `payment_status IN ('pending','failed')` & `payment_method != 'cod'` yang ikut di-expire — pesanan lunas/proses/kirim/selesai tidak pernah tersentuh
- **Jaring pengaman**: bila webhook Midtrans `expire` tidak sampai ke server (URL notifikasi belum diisi / server mati), auto-expire ini yang membersihkan pesanan menggantung

### 📦 Stok Otomatis Sinkron dengan Pesanan

Sebelumnya stok produk hanya bisa diubah manual oleh admin — pesanan yang masuk **tidak pernah mengurangi stok** sehingga label "Tersedia/Sisa X/Habis" dan alert stok rendah di dashboard tidak akurat. Sekarang stok berkurang otomatis saat pesanan dibuat dan dikembalikan saat pesanan dibatalkan / pembayaran gagal.

- **Helper baru** di `config/database.php`:
  - `deductOrderStock($orderId)` — kurangi stok global `products.stock` untuk setiap item pesanan; jika pesanan memakai cabang (`orders.branch_id`), stok cabang `branch_products.stock` ikut dikurangi
  - `restoreOrderStock($orderId)` — kebalikannya, hanya berjalan jika stok sudah pernah dikurangi
  - Kolom baru `orders.stock_deducted` (flag idempoten, mengikuti pola `sold_counted`/`points_awarded`) — dibuat otomatis di DB lama via `ensureStockDeductedColumn()`; `schema.sql` diperbarui untuk instal fresh
- **Saat pesanan dibuat** (`pages/checkout.php`, di dalam transaksi): stok di-reserve segera — mencegah kejual melebihi stok (overselling)
- **Saat pesanan dibatalkan**: stok dikembalikan otomatis di `admin/orders.php`, `admin/order-detail.php`, `admin/shipping.php`
- **Saat pembayaran gagal/expired/refund** (webhook & polling Midtrans di `config/midtrans.php`): stok dikembalikan; saat pembayaran lunas, `deductOrderStock()` juga dipanggil (idempoten) untuk menutup pesanan lama yang dibuat sebelum fitur ini
- **Verifikasi manual** (`admin/payments.php`, `admin/order-detail.php`): verifikasi → pastikan stok ter-reserve; tolak/gagal → stok dikembalikan

---

## [1.22.0] — 2026-08-15

### 🎯 Tiga Fitur Prioritas Tinggi Tuntas

#### 🔢 Batas Pemakaian Kode Promo
Kode promo tidak bisa lagi dipakai tanpa batas — admin bisa menetapkan kuota pemakaian maksimal.

- **Kolom baru** `promotions.max_uses` (NULL = tanpa batas) & `promotions.used_count` (jumlah pesanan yang memakai kode) — dibuat otomatis di DB lama via `ensurePromoColumns()`, `schema.sql` diperbarui untuk instal fresh
- **Validasi** `validatePromoCode()`: kode ditolak dengan pesan *"Kode promo sudah mencapai batas pemakaian"* saat `used_count >= max_uses` — berlaku di keranjang & checkout (server-side, aman dari manipulasi)
- **Pencatatan otomatis**: `incrementPromoUsage()` dipanggil saat pesanan berhasil dibuat (dalam transaksi); `decrementPromoUsage()` saat pesanan dibatalkan di `admin/orders.php` & `admin/order-detail.php` — kuota selalu akurat
- **Admin → Promo**: field **Batas Pemakaian** (kosongkan = tanpa batas) di form tambah & edit; kolom **Pemakaian** di tabel menampilkan `dipakai / batas` (badge merah **Habis** saat kuota tercapai)

#### 💎 Harga Khusus Member (Diskon per Level)
Member kini mendapat diskon otomatis sesuai level — Silver/Gold/Platinum/Diamond.

- **Pengaturan baru** di **Admin → Pengaturan → Harga Khusus Member**: persentase diskon per level (default Silver 0%, Gold 5%, Platinum 10%, Diamond 15%, bisa diubah 0–100%)
- **Helper** `config/database.php`: `getMemberDiscountRate()` (level efektif dari akun), `getMemberDiscountForSubtotal()` (diskon rupiah dari subtotal), `getMemberLevelLabel()`
- **Keranjang & Checkout**: baris **Diskon Member (Gold 5%)** di ringkasan + total berkurang otomatis; preview total live di JS checkout ikut menghitung; diskon digabung ke `orders.discount` (tidak melebihi subtotal)
- **Halaman detail produk**: badge **💎 Harga member Gold: Rp X (diskon Y% otomatis di keranjang)** untuk member yang login
- Diskon member **berfungsi bersamaan** dengan kode promo & tukar poin

#### ⭐ Ulasan Produk dari Pengguna
Pengunjung kini bisa menulis ulasan (rating + komentar) langsung di halaman produk — dengan validasi pembeli & moderasi admin.

- **Form tulis ulasan** di tab Ulasan halaman detail produk (`pages/product-detail.php`): rating bintang interaktif (1–5) + komentar
- **Validasi pembeli terverifikasi**: hanya akun yang sudah punya pesanan berstatus **Selesai (delivered)** berisi produk ini yang bisa mengulas (`userCanReviewProduct()`); satu ulasan per produk per akun (`userAlreadyReviewedProduct()`); belum login → diarahkan login; tamu/pembeli belum selesai → pesan penjelasan
- **Moderasi admin**: ulasan baru masuk **pending** (tidak tampil) hingga disetujui; halaman baru **Admin → Ulasan Produk** (`admin/reviews.php`) dengan filter (Semua/Menunggu/Tampil), badge pembeli terverifikasi ✅, aksi **Setujui / Tolak / Hapus**
- **Rating otomatis**: `recalcProductRating()` menghitung ulang rata-rata rating produk (dari ulasan aktif & terverifikasi) saat ulasan disetujui/dihapus
- **Permission & menu self-healing**: `ensureReviewsSchema()` membuat permission `reviews` (view/create/edit/delete) + menu sidebar untuk role super-admin, admin-penjualan-online, admin-marketing, admin-produk

---

## [1.21.0] — 2026-08-15

### 📧 Notifikasi Email (PHPMailer + SMTP Gmail)
Notifikasi yang tadinya hanya muncul di dalam website kini bisa **terkirim ke email customer** — konfirmasi pesanan, nomor resi, reset password, dan broadcast newsletter.

- **Helper baru `config/mail.php`** (PHPMailer v7 via composer): `mailIsConfigured()`, `getMailer()`, `sendMail()` (selalu mengembalikan hasil, tidak pernah menggagalkan alur utama), template HTML premium bertema emas `mailTemplate()` + `mailButton()`, `htmlToText()` untuk klien email lama
- **Pengiriman spesifik**: `sendOrderConfirmationEmail()` (ringkasan item + link bayar Midtrans), `sendTrackingNumberEmail()` (notifikasi nomor resi), `sendPasswordResetEmail()` (token 30 menit, sekali pakai), `sendNewsletterBroadcastEmail()` (semua subscriber aktif)
- **Admin → Pengaturan**: kartu **Email Notifikasi (SMTP)** — host, port, enkripsi (TLS/SSL), username, password (App Password), email & nama pengirim, toggle aktif/nonaktif, plus tombol **Kirim Email Uji** (konfigurasi tersimpan dulu lalu langsung dicoba kirim; panduan Gmail App Password ada di kartunya)
- **Lupa password**: halaman baru `auth/forgot-password.php` (anti enumerasi akun — pesan sukses netral) + `auth/reset-password.php` (token divalidasi hash SHA-256, cek kedaluwarsa & sekali pakai, minimal 6 karakter); tabel `password_resets` dibuat otomatis (self-healing); link "Lupa password?" di halaman login kini berfungsi + banner sukses setelah reset
- **Konfirmasi pesanan**: `pages/checkout.php` mengirim email berisi ringkasan pesanan + tombol Bayar Sekarang setelah order dibuat (gagal kirim tidak menggagalkan pesanan)
- **Nomor resi**: `admin/orders.php` & `admin/order-detail.php` mengirim email ke customer saat resi diisi/diubah
- **Newsletter**: `admin/marketing.php` mendapat form **Kirim Newsletter** (subjek + isi) → dikirim ke semua subscriber aktif, dengan ringkasan terkirim/gagal
- **Catatan hosting**: InfinityFree memblokir `mail()` — pengiriman lewat SMTP eksternal. Gmail gratis (App Password) cukup untuk email transaksional (±500/hari); broadcast besar sebaiknya pakai Brevo/MailerSend

---

## [1.20.0] — 2026-08-15

### 🎟️ Kode Promo Fungsional di Keranjang & Checkout
Input "Punya kode promo?" di keranjang yang sebelumnya mati kini **benar-benar berfungsi** — pelanggan bisa memakai kode diskon yang dibuat admin.

- **Admin → Promo (`admin/promo.php`)**: field **Kode Promo** (huruf besar otomatis, wajib & unik; saat edit boleh dikosongkan = pertahankan kode lama); kode ditampilkan di tabel; validasi duplikat case-insensitive
- **Database (self-healing)**: kolom `promotions.code` (unique) + `orders.promo_code` (catatan kode yang dipakai) — dibuat otomatis di DB lama via `ensurePromoColumns()`; `schema.sql` diperbarui untuk instal fresh
- **Helper baru `config/database.php`**: `getPromoByCode()`, `validatePromoCode()` (cek aktif, periode berlaku, min_purchase; diskon persen/nominal dibatasi maksimal = subtotal), `getSessionPromoCode()` / `setSessionPromoCode()` / `clearSessionPromoCode()`
- **Keranjang (`pages/cart.php` + `ajax/promo.php`)**: input kode + tombol **Gunakan** (Enter juga bisa) → validasi via AJAX → diskon muncul di ringkasan; kode aktif ditampilkan (badge hemat + tombol **Hapus**); kode tidak berlaku otomatis dibersihkan
- **Checkout (`pages/checkout.php`)**: kode dari keranjang divalidasi **ulang server-side** terhadap subtotal saat pesanan dibuat (aman dari manipulasi); diskon promo dijumlahkan ke `discount` order + kode tersimpan di `promo_code`; ringkasan menampilkan baris **Diskon Promo (KODE)** + kotak kode aktif dengan link hapus (`?remove_promo=1`); preview total live di JS ikut menghitung; kode dibersihkan setelah order dibuat
- **Invoice, PDF, Tracking, Detail Pesanan admin**: menampilkan label "Diskon (kode promo)" + baris info **Kode promo** saat order memakai kode
- **UI/UX mobile diperbaiki** (`assets/css/style.css` v4.1→v4.2): komponen promo baru `.promo-form`, `.promo-box`, `.promo-box-remove`, `.promo-chip` — di HP (≤480px) input & tombol promo disusun vertikal **full-width** dengan target sentuh ≥46px, kotak promo aktif membungkus rapi tanpa overflow horizontal, tombol **Hapus** jadi pill yang nyaman disentuh; di checkout ringkasan yang terlipat kini menampilkan chip hijau **"Promo aktif"** di header agar diskon terlihat tanpa perlu dibuka; diverifikasi via headless Chrome di viewport 375px (tanpa overflow, layout column/wrap benar) & desktop

---

## [1.19.0] — 2026-08-06

### 🏠 Alamat Pengiriman Tersimpan — Checkout Lebih Cepat
Pengguna bisa menyimpan **beberapa alamat pengiriman** di profil; alamat default **otomatis terisi** di halaman checkout.

- **Tabel baru `shipping_addresses`** (`ensureShippingAddressSchema()` di `config/database.php`, self-healing) + helper: `getUserShippingAddresses()`, `getDefaultShippingAddress()`, `saveShippingAddress()` (alamat pertama otomatis jadi default, **dedupe otomatis** — alamat identik di-update bukan ditambah baris baru, verifikasi kepemilikan saat update), `deleteShippingAddress()` (selalu menyisakan satu default), dan `getIndonesiaProvinces()` (satu sumber daftar provinsi, dipakai checkout & profil)
- **Profil (`auth/profile.php`)**: seksi **Alamat Pengiriman** — daftar kartu alamat (label, penerima, telepon, alamat lengkap, badge DEFAULT) dengan aksi **Jadikan Default / Edit / Hapus** (dengan konfirmasi), plus form tambah/edit alamat (label, penerima, telepon, alamat, kota, provinsi, kode pos, jadikan default)
- **Checkout (`pages/checkout.php`)**:
  - Alamat **default otomatis prefill** form pengiriman (nama, telepon, alamat, kota, provinsi, kode pos)
  - **Pemilih "Alamat Tersimpan"** — pilih alamat lain → form terisi otomatis via JS (`fillSavedAddress`, data JSON di-escape anti-XSS)
  - **Centang "💾 Simpan alamat ini ke akun saya"** (default aktif) → alamat tersimpan & jadi default saat order dibuat, checkout berikutnya langsung terisi

### 🔐 Wajib Login untuk Memesan
Pengguna **harus punya akun & login terlebih dahulu** sebelum bisa checkout (memesan).

- **`pages/checkout.php`**: guard wajib login di bagian atas (meliputi GET & POST) — guest yang membuka checkout langsung diarahkan ke halaman login dengan `?redirect=/pages/checkout.php`; POST checkout oleh guest **ditolak & tidak membuat order**
- **`auth/login.php`**: dukungan parameter `?redirect=` — hanya path internal halaman (`/pages/...`) yang diizinkan; redirect eksternal, protokol-relative, backslash, dan encoding `%` **ditolak** (anti open-redirect); setelah login customer **kembali otomatis ke halaman checkout**; banner info *"Silakan masuk ke akun Anda untuk melanjutkan pemesanan"*; link "Daftar Sekarang" ikut membawa redirect
- **`auth/register.php`**: parameter `redirect` dipertahankan (hidden field + link "Masuk") sehingga pengguna yang baru mendaftar bisa langsung lanjut login → checkout
- **Auto-login setelah registrasi** — tidak perlu login 2 langkah lagi: setelah akun dibuat, sistem langsung membuat sesi (`createUserSession` + regenerasi ID sesi anti session-fixation), **keranjang tamu otomatis dipindah ke akun baru**, lalu user langsung diarahkan ke halaman asal (`/pages/checkout.php` bila daftar dari checkout) atau homepage
- **Pesan "Silakan Login" yang jelas di keranjang** (`pages/cart.php`): saat guest mengklik **Lanjut ke Pembayaran**, muncul **modal** berisi penjelasan + tombol **Masuk** dan **Daftar Gratis** (keduanya membawa `?redirect=/pages/checkout.php` sehingga langsung kembali ke checkout; keranjang tamu dipindah otomatis ke akun) + hint kecil di bawah tombol; user yang sudah login tetap melihat tombol biasa
- **Merge keranjang tamu → akun** (`mergeGuestCartToUser()` di `config/database.php`): item keranjang yang ditambahkan saat belum login **tidak hilang** — dipindahkan ke keranjang akun saat login (kuantitas produk yang sama dijumlahkan, bukan baris duplikat; memakai ID sesi lama sebelum `session_regenerate_id`; dibungkus transaksi agar aman dari kegagalan tengah jalan)
- Tamu tetap bisa **menjelajah & menambah ke keranjang** — baru wajib login saat memesan

### 👥 Role Baru — Admin Penjualan Online
Role ke-16 ditambahkan untuk mengelola operasional penjualan online toko.

#### 🔐 Hak Akses (sesuai pilihan: operasional penjualan online penuh)
- **Kelola pesanan online**: view, buat, ubah, approve, export (`orders`)
- **Lihat pembayaran** (`payments:view`), **kelola pengiriman & resi** (`shipping: view/create/edit`)
- **Lihat invoice, pelanggan, produk, promo** (`invoices/customers/products/promo: view`)
- **Pesan masuk** (`messages:view`) & **laporan penjualan** (`reports: view/export`)
- Hak dasar semua role: dashboard, profil, notifikasi, changelog
- **Tidak** dapat mengubah produk/harga, mengelola role/user, atau mengakses pengaturan & backup

#### 📋 Implementasi
- `database/rbac-seeder.php`: role + permission mapping + widget dashboard (`stats_orders`, `stats_revenue`, `recent_orders`, `pending_payments`) untuk instalasi fresh
- Database aktif: role + 22 permission + widget dipasang **tanpa mereset mapping role lain**
- Akun login dibuat otomatis via user-seeder: `admin-penjualan-online` / `admin-penjualan-online@nadhiranapoleon.com` (password acak ditampilkan sekali saat eksekusi)
- `role.md`: daftar role diperbarui (Admin Penjualan Online = nomor 7, penomoran disesuaikan)

---

### 🔔 Notifikasi Suara Transaksi Baru (untuk Admin Penjualan Online)
Setiap ada transaksi/pesanan baru masuk, role **Admin Penjualan Online** mendapat peringatan **suara + toast** di panel admin agar bisa langsung memproses & memverifikasi.

#### 🛒 Notifikasi HANYA Saat Pembayaran LUNAS
- **Notifikasi saat order dibuat dihapus** — admin tidak lagi dibunyikan untuk pesanan yang baru masuk tapi belum dibayar (mencegah suara palsu untuk order yang tidak kunjung dibayar)
- Admin hanya diberi tahu **setelah pembayaran benar-benar LUNAS** — dipicu dari **semua jalur pembayaran lunas**: webhook Midtrans & polling status (`midtransApplyTransactionStatus()`) **+ verifikasi manual** di `admin/payments.php` & `admin/order-detail.php` (konfirmasi transfer lama) via helper bersama `notifyPaymentPaid()` (`config/rbac.php`)
- Helper `notifyPaymentPaid()` dipakai semua jalur agar pesan & target selalu konsisten (label metode pembayaran opsional, link ke detail pesanan)

#### 🔊 Peringatan Suara di Panel Admin — `admin/layout.php`
- **Polling setiap 10 detik** ke endpoint baru `ajax/notifications.php` (mengembalikan jumlah belum dibaca + notifikasi terbaru) agar peringatan lebih cepat
- Ada notifikasi baru → **bunyi "ding-dong" lembut** (Web Audio API, tanpa file eksternal) + **toast** (judul, pesan, tombol "Lihat sekarang") + badge lonceng ter-update
- **Role penerima suara bisa diatur** di Admin → Pengaturan → Lainnya → "Role Penerima Notifikasi Suara Transaksi" (setting `sound_notify_role`, default `admin-penjualan-online`) — dipakai juga oleh `notifyRole()` di checkout agar target selalu sinkron
- Role lain tetap mendapat badge & toast tanpa suara; audio dibuka kunci saat interaksi pertama (kebijakan autoplay browser); konten toast di-escape dari XSS; `notifyRole()` hanya mengirim ke user aktif

#### 🖥 Notifikasi Desktop (izin browser)
- **Tombol izin notifikasi desktop** di header panel admin (di samping lonceng): klik → browser meminta izin → status tampil (hijau aktif / merah diblokir / abu-abu belum diizinkan)
- Saat izin diberikan, transaksi baru muncul sebagai **notifikasi desktop asli browser** (ikon logo toko, klik notifikasi → langsung buka halaman Pesanan)
- **Hanya untuk role penerima suara** (`sound_notify_role`, default Admin Penjualan Online): tombol hanya dirender untuk role tersebut + pengaman ganda di JS (`DESKTOP_ENABLED`) — admin lain tetap mendapat badge lonceng & toast tanpa popup desktop
- Jika diblokir, muncul panduan untuk mengizinkan lewat pengaturan situs
- **Tombol "Tes Notifikasi"** (📢) di header panel admin — di samping tombol desktop, khusus role penerima suara: sekali klik langsung memutar **bunyi ding-dong** + menampilkan **notifikasi desktop uji** (jika izin sudah diberikan); jika belum diizinkan muncul panduan, jika diblokir muncul instruksi mengizinkan lewat pengaturan situs — tanpa perlu menunggu transaksi masuk
- **Perbaikan bug**: fungsi `toggleDesktopNotif()` kini diekspos ke global (`window.toggleDesktopNotif`) sehingga atribut `onclick` inline tombol desktop benar-benar berfungsi di browser (sebelumnya tersembunyi di dalam IIFE → error `ReferenceError` saat diklik)
- **Tombol "Tes Suara"** di halaman Pengaturan (Lainnya → Role Penerima Notifikasi Suara): pratinjau bunyi ding-dong sebelum menyimpan (memakai ulang `window.nnPlayChime` dari layout)

#### ✅ Notifikasi Saat Pembayaran LUNAS (Settlement) — `config/midtrans.php`
- **Pemicu tunggal notifikasi suara + toast + desktop**: saat pembayaran berhasil **LUNAS** (Midtrans settlement / capture diterima)
- Dipasang di **titik tunggal** `midtransApplyTransactionStatus()` (dipakai webhook & polling status) sehingga otomatis sinkron dari sumber mana pun
- Guard idempoten (`payment_status` sudah `paid` → skip) memastikan notifikasi **tidak ganda** walau webhook dikirim berkali-kali
- Isi: *"✅ Pembayaran LUNAS #INV-xxx — Pembayaran sebesar Rp ... (QRIS/VA/dll) telah lunas — invoice otomatis terverifikasi"*, link langsung ke **detail pesanan** (`admin/order-detail.php?id=...`)
- Target: role dari setting `sound_notify_role` (default Admin Penjualan Online) + Super Admin

---

### 🔑 "Ingat Saya" — Aktif Secara Default
Preferensi *ingat saya* kini **diaktifkan secara default** sehingga pengguna tidak perlu login ulang di setiap kunjungan.

- **Checkbox "Ingat saya" dicentang otomatis** di halaman login (`auth/login.php`) — cukup opt-out (hapus centang) bila memakai perangkat bersama; teks penjelasan kecil ditambahkan di bawahnya
- **Preferensi pengguna disimpan** (`config/rbac.php`): cookie `nn_remember_pref` (1 tahun) mengingat pilihan — jika pengguna menghapus centang, form login di kunjungan berikutnya tampil **tanpa centang**; jika tidak, tetap aktif
- **Cookie login 7 hari** (`nn_remember`, `setRememberCookie()`): saat dicentang, token sesi disimpan di cookie; `restoreRememberedLogin()` (`config/database.php`) **otomatis memulihkan sesi** setelah browser ditutup — kembali ke website langsung sudah login (aman: token divalidasi di tabel `user_sessions`, akun nonaktif ditolak)
- **Daftar otomatis ikut diingat** (`auth/register.php`): user baru langsung mendapat cookie remember + preferensi 1 — tidak perlu login ulang sejak kunjungan pertama
- Jika checkbox dihapus, cookie login lama **dibersihkan** (`clearRememberCookie()`); logout tetap menghapus cookie

---

### 📄 Halaman Syarat & Ketentuan + Kebijakan Privasi
Link legal di form daftar yang sebelumnya kosong (`href="#"`) kini menuju halaman lengkap.

- **Halaman baru `pages/terms.php`** — Syarat & Ketentuan (12 pasal): penerimaan ketentuan, akun & pendaftaran, pemesanan & pembayaran (Midtrans), harga & ketersediaan, pengiriman (ongkir gratis), pengembalian & retur, poin & membership, kekayaan intelektual, batasan tanggung jawab, perubahan ketentuan, hukum yang berlaku, dan kontak — desain mengikuti gaya situs (breadcrumb, badge LEGAL, kartu konten)
- **Halaman baru `pages/privacy.php`** — Kebijakan Privasi (9 pasal): data yang dikumpulkan, penggunaan data, cookie & "Ingat Saya", pihak ketiga (Midtrans & kurir), keamanan data, hak pengguna, perubahan kebijakan, dan kontak
- **`auth/register.php`**: link "Syarat & Ketentuan" dan "Kebijakan Privasi" di samping checkbox persetujuan kini menuju halaman tersebut dan dibuka di **tab baru** (`target="_blank" rel="noopener"`) agar data form tidak hilang
- **`includes/footer.php`**: kedua halaman ditautkan di kolom Menu footer

---

### 💬 Verifikasi OTP via WhatsApp (Fonnte) untuk Pendaftaran
Pendaftaran akun kini 2 langkah: isi form → **kode OTP dikirim ke WhatsApp** → verifikasi → akun dibuat & langsung login. Penyedia: **Fonnte** (gateway WA Indonesia, token API saja tanpa persetujuan template).

- **File baru `config/otp.php`** (mengikuti pola `config/midtrans.php`):
  - Tabel `otp_verifications` (identifier, phone, code_hash, purpose, expires_at, attempts, verified) dibuat otomatis (self-healing) + kunci settings default
  - Helper: `otpIsEnabled()`, `normalizePhone()` (08xx/8xx → 62xx), `maskPhone()`, `generateOtpCode()` (6 digit), `storeOtp()`/`verifyOtpCode()` (hash HMAC-SHA256, **kadaluwarsa 5 menit** & **maks 5 percobaan**, perbandingan waktu konsisten dengan `NOW()` MySQL agar kebal selisih jam PHP/DB), `sendOtpWhatsApp()` (cURL ke `api.fonnte.com/send`, header `Authorization: token`)
- **`auth/register.php` — alur 2 langkah**: submit form → sistem simpan data sementara di sesi + kirim OTP → halaman **Verifikasi WhatsApp** (input 6 digit) → kode benar → akun dibuat + **auto-login** (keranjang tamu ikut pindah, "Ingat Saya" otomatis) → redirect ke halaman asal; tombol **Kirim Ulang** (kode baru) & **Ubah Data/Nomor** (batal); nomor WA wajib diisi saat OTP aktif; **fallback**: jika OTP dinonaktifkan, registrasi langsung seperti sebelumnya
- **Mode Uji (default aktif)**: tanpa token pun sistem berfungsi — kode OTP **tampil di layar** (kotak kuning) alih-alih dikirim; di Pengaturan tinggal matikan mode uji + isi token Fonnte untuk kirim sungguhan
- **`admin/settings.php` — kartu "WhatsApp OTP"**: aktif/nonaktif, **Mode Uji**, **Token API Fonnte**, masa berlaku kode (1–30 menit) + panduan aktivasi (daftar fonnte.com → hubungkan nomor WA toko → salin token)
- Validasi keamanan: kode disimpan **hash** (bukan teks), kadaluwarsa otomatis, percobaan dibatasi (maks 5, counter atomik anti-balapan), nomor divalidasi **HP Indonesia** (`628` + 9–13 digit), **rate limit 3 kirim/nomor/jam** (anti SMS-bombing), baris OTP lama dibersihkan otomatis, pesan menyertakan peringatan "jangan berikan kode ke siapa pun"

---

## [1.18.0] — 2026-08-06

### 💳 Pembayaran Midtrans Snap — Ganti Konfirmasi Manual
Metode pembayaran diubah dari konfirmasi transfer manual menjadi **Midtrans Snap** (Virtual Account, QRIS, E-Wallet, Kartu Kredit). Begitu pembayaran sukses, pesanan **otomatis LUNAS** — tidak perlu verifikasi manual lagi.

#### ⚙️ Konfigurasi — `config/midtrans.php` (file baru)
- Setting kunci **Server Key / Client Key** + toggle **Sandbox / Production** di Admin → Pengaturan
- Helper baru: `midtransCreateSnapToken()`, `midtransVerifySignature()` (SHA512), `midtransGetTransactionStatus()`, `midtransApplyTransactionStatus()`, `midtransPaymentLabel()`, `ensureMidtransSchema()`
- Perbaikan bug host API `app.` → `api.` (sebelumnya status pembayaran tidak pernah tersinkron)

#### 🔔 Webhook Otomatis — `midtrans-notification.php`
- Endpoint notifikasi Midtrans: verifikasi signature SHA512(order_id+status_code+gross_amount+ServerKey)
- **Settlement → pesanan langsung LUNAS**, poin otomatis diberikan (lihat bagian poin)
- Body invalid ditolak (400), signature salah ditolak (403), pesanan tidak ditemukan ditolak (404)

#### 🛒 Alur Checkout — `pages/checkout.php`
- Checkout membuat **Snap token** → redirect/popup Snap Midtrans (VA, QRIS, GoPay, dll.)
- `ajax/midtrans-status.php`: polling status pembayaran dari halaman invoice
- Invoice (`pages/invoice.php`) menampilkan status **LUNAS real-time**; tombol "Bayar Sekarang" hilang setelah lunas
- Sinkronisasi status: webhook + polling + status dari Snap (`?pay=success|pending|error`)
- **Setelah pembayaran selesai, pengguna langsung diarahkan ke homepage** (bukan invoice) dengan toast pemberitahuan hasil (sukses / menunggu / gagal) — `pages/payment.php` redirect ke `/?pay=...` + handler toast baru di `main.js` (URL dibersihkan otomatis setelah ditampilkan)

---

### 🚚 Ongkir Gratis
- **Biaya pengiriman di-set Rp 0 (GRATIS)** untuk semua pesanan reguler (`getSetting('shipping_cost', 0)`)
- Keranjang berisi hanya paket membership tetap ongkir Rp 0 (perilaku sebelumnya dipertahankan)
- Ringkasan checkout & invoice otomatis konsisten — tidak ada biaya kirim yang ditagih

---

### ⭐ Poin Hanya Bertambah Saat Pembayaran LUNAS
Poin membership tidak lagi langsung diberikan saat order dibuat — poin baru ditambahkan **ketika pembayaran sudah lunas/terverifikasi**.

#### 🎯 Kapan Poin Diberikan
- **Pembayaran Midtrans settlement** (webhook) → `awardOrderRewards()` langsung dijalankan
- **Verifikasi manual** di `admin/payments.php` & `admin/order-detail.php` → poin diberikan saat admin menyetujui pembayaran
- Pesanan masih **pending / belum verifikasi** → poin belum ditambahkan (menunggu status lunas)

#### 🗄 Teknis
- Kolom `sold_counted` pada tabel `orders` sebagai pengaman agar poin & statistik **tidak dobel** saat status di-update berkali-kali
- Pembatalan pesanan tetap membalik reward (poin ditarik kembali) seperti sebelumnya

---

### 🏠 Homepage Terhubung Penuh ke Dashboard Admin
Semua bagian homepage kini hidup dari database — dikelola langsung dari dashboard admin (sebelumnya sebagian besar teks mati/hardcoded).

#### ❓ FAQ — dari Tabel `faq`
- Homepage membaca FAQ dari tabel `faq` (yang dikelola di **Admin → FAQ**), bukan teks hardcoded
- Hanya FAQ aktif yang tampil, diurutkan `sort_order`; fallback konten lama jika tabel kosong

#### 📊 Hero Stats — Dinamis dari Database
- **Produk Terjual**: SUM total_sold produk · **Pelanggan Puas**: email unik dari pesanan **LUNAS** · **Cabang**: jumlah cabang aktif · **Rating**: rata-rata review terverifikasi · **Hari Buka**: setting baru
- Angka tampil dengan animasi counter; fallback nilai default jika belum ada data

#### ✨ Why Us (4 Fitur) — Bisa Diubah dari Pengaturan
- Ikon, judul, dan deskripsi 4 fitur Why Us kini diatur di **Admin → Pengaturan → Why Us**
- Validasi format ikon `fa-*` saat simpan (input tidak valid memblokir penyimpanan dengan pesan jelas)

#### 📅 Hari Buka — Setting Baru
- `hero_open_days` (1–7) diatur di **Admin → Pengaturan → Lainnya**, dibaca homepage sebagai statistik "Hari Buka"

---

### 📱 Penyempurnaan Tampilan Mobile
- **Perbaikan:** tombol WhatsApp melayang tidak lagi **menutupi menu navigasi bawah** di HP (naik ke `calc(70px + 16px)` + ukuran lebih ramah layar kecil)
- **Navbar ramping di layar kecil** (≤480px): logo dipangkas, jarak ikon dirapatkan, badge membership menampilkan ikon saja
- Cache busting CSS `v=1.3 → v=1.4` agar perangkat yang pernah membuka website memuat gaya baru
- Diverifikasi di viewport mobile 375px: homepage, produk, detail produk, keranjang, membership, checkout — **tanpa overflow horizontal**, 0 error JS

---

## [1.17.0] — 2026-08-04

### 🛒 Section Membership di Katalog Produk — `pages/products.php`
Section membership kini juga tampil di halaman katalog produk, tepat di atas grid produk — pengunjung langsung melihat penawaran membership sambil berbelanja.

#### 🔁 Refactor — Partial Bersama `includes/membership-section.php`
- Seluruh section membership (banner promo + countdown, kartu member/CTA guest, kartu paket Gold/Platinum/Diamond, CTA) diekstrak dari `index.php` menjadi **partial reusable**
- Homepage & halaman produk memakai **kode yang sama** (tidak ada duplikasi ke-3/ke-4) — satu sumber kebenaran
- Variabel di-prefix `$ms_` agar aman dari tabrakan dengan variabel halaman induk (`$conn`, `$page`, `$products`, dll.)

#### 🎯 Pemasangan di `pages/products.php`
- Partial dipasang di atas grid produk (setelah breadcrumb & shop-hero)
- Fitur lengkap langsung aktif di sana: banner promo membership, kartu member untuk yang login, paket langganan, tombol Langganan (wajib login), dan harga diskon tahunan saat promo

#### ⏱ Timer Countdown Pindah ke `assets/js/main.js`
- Fungsi `startPromoTimers` (sebelumnya inline di index.php) dipindah ke main.js agar berjalan di **semua halaman** yang punya `.promo-card-timer`
- Cache busting main.js `v=1.4 → v=1.5`

---

## [1.16.0] — 2026-08-04

### ⏳ Promo Membership — Countdown & Diskon Paket Tahunan
Section membership homepage kini punya banner promo dengan countdown, lengkap dengan **diskon nyata** untuk paket tahunan.

#### 🎯 Banner Promo di Homepage — `index.php`
- Banner emas di atas section membership: badge **"Diskon -X% Paket Tahunan"**, judul & deskripsi, **countdown real-time** (Hari/Jam/Menit/Detik, reuse `startPromoTimers`) + tombol **Klaim Promo** (smooth-scroll ke kartu paket)
- **Harga diskon tampil di kartu paket**: harga tahunan dicoret + harga promo, badge merah "Promo -X%" menggantikan "Hemat 2 bulan" selama promo

#### 💰 Diskon Nyata di Checkout — `pages/checkout.php`
- Paket **tahunan** otomatis dapat diskon saat promo aktif — dikurangkan dari total pesanan (kolom `discount`, tampil di invoice)
- Ringkasan checkout menampilkan baris **"Diskon Promo Membership"**; preview total live di JS ikut menghitung diskon
- Batas aman: total diskon (poin + promo) tidak melebihi subtotal

#### ⚙️ Admin — `admin/settings.php`
- Kartu **Promo Membership**: toggle aktif, judul, deskripsi, **% diskon** (1–90), tanggal berakhir (datetime-local)
- Default: aktif +20% paket tahunan, berakhir +7 hari bergulir (bisa langsung dinonaktifkan)
- Dikonfirmasi: diskon konsisten juga tampil di halaman membership (`pages/membership.php`)

#### 🔧 Teknis — `config/database.php`
- Helper baru: `getMembershipPromo()`, `membershipPromoPrice()`, `membershipPromoDiscount()`, `membershipPromoCartDiscount()`

---

## [1.15.0] — 2026-08-04

### 👤 Kartu Member Saya di Section Membership Homepage
Section membership di homepage kini personal untuk member yang login.

#### 💳 Untuk Member yang Login — `index.php`
- **Kartu member** tampil di atas kartu paket langganan: sapaan nama, level member + badge, poin, total belanja
- **Progress bar ke level berikutnya** + sisa belanja yang dibutuhkan
- Info langganan berbayar aktif (level, periode, tanggal berakhir) jika ada
- CTA: "Belanja & Kumpulkan Poin" + "Lihat Detail Membership"
- Pesan khusus jika sudah di level tertinggi (Diamond 🏆)

#### 🎁 Untuk Pengunjung (Guest)
- Strip CTA "Daftar gratis & kumpulkan poin" dengan tombol **Daftar Gratis** / **Login** — memanfaatkan momen sebelum melihat paket

#### 🎨 Teknis
- Memakai ulang komponen kartu member yang sudah ada (`.member-card`, `.member-level-icon`, `.member-stat-*`, `.level-progress-*`)
- CSS kecil `.home-member-card`: border emas + shadow agar menonjol di section gelap
- Data level/poin/progress dihitung ulang per-request (konsisten dengan halaman membership)

---

## [1.14.0] — 2026-08-04

### 🏠 Section Beli Membership di Homepage (di Atas Produk)
Homepage kini punya etalase membership premium tepat di atas daftar produk terlaris.

#### 🎯 Section Baru — `index.php` (id="membership")
- Posisi: di atas section **Produk Terlaris** (Best Seller), setelah Why Us — pengunjung langsung lihat penawaran membership sebelum katalog
- **Latar gelap mewah** (gradient cokelat gelap + aksen emas) agar menonjol di antara section terang
- **3 kartu paket** (Gold/Platinum/Diamond) dengan pilihan Bulanan & Tahunan (badge "Hemat 2 bulan"), harga, info poin ×multiplier & syarat belanja
- Tombol **Langganan** → keranjang → checkout (wajib login, sama seperti di halaman membership)
- CTA "Pelajari Program Membership" ke `pages/membership.php`
- Section otomatis tersembunyi jika belum ada paket aktif

#### 🔧 Refactor — fungsi `buyMembership` dipindah ke `assets/js/main.js`
- Satu sumber fungsi `buyMembership()` (sebelumnya inline di membership.php, kini dipakai homepage & halaman membership)
- `includes/header.php`: global JS `NN_LOGGED_IN` untuk cek status login
- Cache busting main.js `v=1.3 → v=1.4`

---

## [1.13.0] — 2026-08-04

### 📜 Riwayat Poin per Member — Tabel `point_history`
Setiap perolehan & pemakaian poin kini tercatat lengkap per member — transparan, bisa dilacak dari mana poin datang & ke mana pergi.

#### 🗄 Database
- Tabel baru `point_history` (user, order, jumlah poin ±, tipe, deskripsi, saldo setelah transaksi) — dibuat otomatis (self-healing) + masuk `modules.sql`/`init.php` untuk instal fresh

#### ✍️ Pencatatan Otomatis — `config/database.php`
- Helper baru `logPointHistory()` & `getPointHistory()`
- **Poin Masuk** (`earned`): poin belanja dari pesanan — dicatat saat order dibuat (dengan no. pesanan)
- **Tukar Diskon** (`spent`): poin dipakai jadi voucher diskon di checkout
- **Refund** (`refunded`): poin tukar dikembalikan saat pesanan dibatalkan
- **Ditarik** (`reversed`): poin belanja ditarik kembali saat pesanan dibatalkan
- **Penyesuaian** (`adjusted`): penyesuaian poin oleh admin
- Setiap entri menyimpan saldo poin setelah transaksi (`balance_after`)

#### 👤 Member — `auth/profile.php`
- Section **Riwayat Poin** di halaman profil: 12 transaksi terbaru dengan ikon, deskripsi (no. pesanan), waktu, tipe, saldo, dan jumlah ± berwarna (hijau masuk / merah keluar)

#### 🛠 Admin — `admin/membership.php`
- Kartu **Riwayat Poin Member (30 terakhir)**: member, deskripsi, poin ±, saldo, tipe, waktu
- **Filter per member**: ikon riwayat di samping poin tiap member → tampilkan riwayat lengkap member tersebut
- Penyesuaian poin oleh admin otomatis tercatat di riwayat

---

## [1.12.0] — 2026-08-04

### 🎟 Tukar Poin Membership Jadi Diskon di Checkout
Poin tidak hanya dikumpulkan — sekarang bisa **ditukar langsung jadi voucher diskon** saat checkout.

#### ⚙️ Aturan Tukar
- Kurs: **1 poin = Rp 100** (100 poin = diskon Rp 10.000)
- Maksimal: **30% dari subtotal** per pesanan (sesuai permintaan)
- Hanya member yang login bisa menukar; poin dicek real-time (tidak boleh melebihi saldo)

#### 🛒 Checkout — `pages/checkout.php`
- Kotak **"Tukar Poin Jadi Diskon"** di ringkasan pesanan: saldo poin, maksimal poin yang bisa dipakai, dan estimasi diskon
- **Preview live** — total pembayaran & diskon terupdate real-time saat mengetik jumlah poin
- Diskon poin disimpan di kolom `discount` order → otomatis tampil di invoice & tracking
- Poin dipotong **dalam satu transaksi** bersamaan dengan pembuatan order (aman dari gagal-tengah-jalan)

#### 🗄 Database — tabel baru `point_redeems`
- Mencatat penukaran poin per pesanan (user, order, poin, nilai, status) — dibuat otomatis (self-healing) + masuk `modules.sql` untuk instal fresh
- **Refund otomatis**: pesanan dibatalkan → poin yang ditukar dikembalikan ke saldo member (`admin/orders.php` & `admin/order-detail.php`)

#### ℹ️ Info
- Halaman membership: step "Kumpulkan Poin" kini menampilkan kurs tukar (1 poin = Rp 100, maks 30%)

---

## [1.11.0] — 2026-08-04

### 💳 Membership Berbayar — Beli & Langganan Level Premium
Membership tidak hanya didapat dari total belanja, tapi **bisa juga dibeli** sebagai langganan bulanan/tahunan, mengalir lewat sistem order + konfirmasi bayar yang sudah ada.

#### 📦 Paket Langganan — tabel baru `membership_plans` & `membership_purchases`
- 6 paket default (bisa diubah dari admin): Gold/Platinum/Diamond × Bulanan/Tahunan
  - Gold: Rp 99rb/bln, Rp 990rb/thn · Platinum: Rp 199rb/bln, Rp 1.990rb/thn · Diamond: Rp 399rb/bln, Rp 3.990rb/thn (tahunan = hemat 2 bulan)
- Tabel dibuat otomatis (self-healing via `ensureMembershipSchema()`), tanpa perlu migrasi manual
- `membership_purchases` mencatat riwayat langganan (user, paket, harga, masa aktif, status)

#### 🛒 Alur Pembelian
- **Halaman Membership** (`pages/membership.php`): section baru "Beli Membership" — kartu paket per level, tombol Langganan → masuk keranjang → checkout seperti biasa (wajib login)
- Produk paket dibuat otomatis & **disembunyikan dari katalog** (index, daftar produk, terkait, detail diarahkan ke halaman membership)
- Keranjang berisi **hanya** paket membership: **ongkir Rp 0**; pembelian paket wajib login (guard di `ajax/cart.php` & `checkout.php`)

#### ⚡ Aktivasi Otomatis
- Saat pembayaran diverifikasi (`admin/payments.php` & `admin/order-detail.php`): langganan **langsung aktif** — level user naik, masa aktif 30/365 hari
- Perpanjangan otomatis: berlangganan ulang level yang sama menambah masa aktif dari tanggal berakhir
- **Level efektif = maksimum(level langganan, level total belanja)** — level beli tidak tertimpa oleh perhitungan belanja
- Kadaluarsa otomatis: langganan yang habis masa aktif otomatis jadi `expired` & level disinkronkan (`syncUserMembership()`)
- Order dibatalkan → langganan dibatalkan & level disinkronkan ulang (`admin/orders.php`)

#### 🛠 Admin — `admin/membership.php`
- Section **Paket Membership Dijual**: CRUD paket (level, periode, harga, durasi, aktif) + toggle — harga sinkron ke produk otomatis
- Section **Riwayat Langganan**: 30 langganan terbaru + jumlah langganan aktif

#### 👤 Status di Frontend
- Badge "Langganan X aktif s.d. tanggal" di kartu member (`pages/membership.php`) & ringkasan profil (`auth/profile.php`)

---

## [1.10.0] — 2026-08-04

### 👑 Sistem Membership & Poin Lengkap (Sisi Member)
Sesuai prioritas tinggi `rancangan.md` — bagian admin sudah ada di 1.9.0, kini sisi member & otomatisasi selesai:

#### 🏠 Halaman Membership — `pages/membership.php`
- **Halaman baru** yang bisa diakses semua orang: info program + 4 kartu level (Silver/Gold/Platinum/Diamond) dengan syarat belanja, multiplier poin, dan benefit dari database (`membership_benefits` aktif)
- **Kartu member** untuk yang login: level, poin, total belanja, progress bar ke level berikutnya + sisa belanja
- **CTA daftar/login** untuk pengunjung, CTA "Belanja untuk Naik Level" untuk member
- Bagian "Cara Kerja" 3 langkah: Belanja → Kumpulkan Poin → Naik Level

#### ⚙️ Otomatisasi Reward — `config/database.php`
- Helper baru: `getMembershipLevels()`, `getMembershipLevelForSpent()`, `getMembershipNextLevel()`, `calculateOrderPoints()`, `awardOrderRewards()`, `estimateOrderPoints()`, `reverseOrderRewards()`
- Aturan level sesuai artikel: Silver (0), Gold (Rp 500rb), Platinum (Rp 2jt), Diamond (Rp 5jt)
- **Poin otomatis**: 1 poin per Rp 10.000 belanja × multiplier level (1x/2x/3x/5x), dihitung dari subtotal produk (ongkir tidak menghasilkan poin)
- **Upgrade level otomatis**: level member diperbarui dari total belanja kumulatif setiap ada transaksi

#### 🛒 Integrasi Checkout — `pages/checkout.php`
- Setelah order dibuat, poin + total belanja + level langsung diperbarui dalam satu transaksi
- Ringkasan checkout menampilkan estimasi "Anda akan mendapat X poin dari pesanan ini"
- Invoice (`pages/invoice.php`) menampilkan banner hijau "Anda mendapat X poin" setelah checkout

#### 🛡 Pembatalan Order — `admin/orders.php`
- Saat pesanan dibatalkan, reward dibalik otomatis: poin dikurangi, total belanja dikembalikan, level disinkronkan ulang

#### 🔔 Badge & Navigasi
- **Badge level member** di navbar (icon crown + nama level, warna sesuai level)
- Menu **Membership Saya** di dropdown user + chip level
- Footer: link **Membership** + link Tracking
- Profil: link "Membership & Poin" (sebelumnya link mati), chip level di sidebar, **kartu ringkasan membership** (level, poin, progress bar, CTA) di halaman profil

#### 🛠 Admin — `admin/membership.php`
- Kolom baru **Ganti Level** per member (dropdown + simpan, dengan konfirmasi)
- Tetap dengan CSRF + permission check per aksi

---

## [1.9.0] — 2026-08-04

### 👤 Akun Role — User untuk Setiap Jabatan
- **14 akun baru** dibuat otomatis via `database/user-seeder.php` — satu akun untuk setiap role kecuali Super Admin
- Username = slug role (contoh: `owner`, `finance`, `admin-marketing`)
- Email = `{slug}@nadhiranapoleon.com`
- Password = 8 karakter acak, ditampilkan **sekali saat eksekusi**
- Aman dijalankan ulang — akun yang sudah ada dilewati (idempotent)

Daftar akun yang dibuat:

| Role | Username | Login Sebagai |
|------|----------|--------------|
| Owner | `owner` | Dashboard eksekutif (read-only) |
| General Manager | `general-manager` | Approval promo & monitoring |
| Admin Produk | `admin-produk` | CRUD produk & kategori |
| Admin Gudang | `admin-gudang` | Inventory & stock |
| Admin Pesanan | `admin-pesanan` | Order & transaksi |
| Admin Cabang | `admin-cabang` | Data cabang spesifik |
| Admin Marketing | `admin-marketing` | Promo & campaign |
| Admin Customer Service | `admin-customer-service` | Ticket & komplain |
| Admin Membership | `admin-membership` | Member & poin |
| Admin Content | `admin-content` | Artikel & gallery |
| Finance | `finance` | Verifikasi bayar & invoice |
| Admin Pengiriman | `admin-pengiriman` | Tracking & kurir |
| Affiliate Manager | `affiliate-manager` | Afiliasi & reseller |
| Developer / IT Support | `developer-it-support` | Backup & settings |

- Setiap akun hanya punya permission sesuai role-nya (tidak bisa akses di luar kewenangan)
- Login di: `http://localhost/nad/auth/login.php`

### 📦 9 Modul Operasional Baru
Semua modul yang tadinya belum ada halaman admin kini lengkap, sesuai roadmap `role.md`:

#### 🏷 Stock / Gudang — `admin/stock.php`
- Statistik: produk aktif, total unit, stok menipis (≤5), stok habis
- **Catat pergerakan stock**: masuk (`in`), keluar (`out`), opname — otomatis update stok produk
- Validasi: stock keluar tidak boleh melebihi stok tersedia
- Riwayat pergerakan 50 terakhir (produk, tipe, qty, sebelum → sesudah, cabang, petugas)
- Filter stok menipis / habis + pencarian produk

#### 💰 Pembayaran — `admin/payments.php`
- Daftar konfirmasi pembayaran dari customer (bank, jumlah, bukti transfer)
- **Verifikasi / tolak** pembayaran → otomatis set `payment_status` pesanan (paid/failed)
- Alasan penolakan opsional + riwayat verifikator & waktu
- Filter status + statistik menunggu/terverifikasi/ditolak

#### 🚚 Pengiriman — `admin/shipping.php`
- Daftar pesanan yang perlu dikirim (processing/shipped)
- Update status pengiriman + **nomor resi (tracking)** per pesanan
- Kelola daftar kurir (JNE, J&T, SiCepat, dll) disimpan di settings

#### 👑 Membership — `admin/membership.php`
- Statistik member per level (silver/gold/platinum/diamond) + total poin
- CRUD **benefit per level** + toggle aktif/nonaktif
- **Atur poin member** (+/-) langsung dari daftar
- Filter member per level

#### 📢 Marketing — `admin/marketing.php`
- CRUD **kampanye** (judul, channel, anggaran, periode, status draft/aktif/selesai/batal)
- Statistik anggaran aktif, kampanye draft, subscriber newsletter
- Kelola subscriber newsletter (daftar + hapus)

#### 🤝 Affiliate & Reseller — `admin/affiliate.php`
- CRUD afiliasi/reseller dengan **kode referral unik** & komisi (%)
- **Sesuaikan saldo komisi** (+/-) via dialog, pencairan tercatat di audit log
- Statistik afiliasi aktif, total komisi belum dicairkan, rata-rata komisi

#### 📊 Laporan — `admin/reports.php`
- Ringkasan pendapatan, total pesanan, rata-rata per order
- **Grafik batang pendapatan 6 bulan terakhir** (CSS murni)
- Pesanan per status + pendapatan per metode pembayaran
- **Produk terlaris top 10** + pesanan terbaru
- **Export CSV** data pesanan & produk

#### 🎧 Ticket Support — `admin/support.php`
- Daftar ticket dengan prioritas (rendah/sedang/tinggi) & status (baru/diproses/selesai/ditutup)
- Buat ticket manual, buka detail, **ubah status/prioritas, tugaskan ke staff**
- Balasan admin dilampirkan ke pesan ticket
- Statistik antrian prioritas tinggi

#### 🧾 Invoice — `admin/invoices.php`
- Daftar semua invoice pesanan dengan status pembayaran & jumlah item
- Tombol **Lihat Invoice** (halaman premium siap cetak) & **Download PDF**
- Statistik total invoice, nilai lunas, menunggu bayar, belum konfirmasi
- Export CSV daftar invoice

### 🗄 Database
- Tabel baru di `database/modules.sql`: `stock_movements`, `marketing_campaigns`, `affiliates`, `support_tickets`
- `database/init.php` kini memproses & memverifikasi tabel modul baru
- 9 menu sidebar baru + modul permission (`stock`, `payments`, `shipping`, `membership`, `marketing`, `affiliate`, `reports`, `support`, `invoices`) — hanya Super Admin yang dapat, sesuai prinsip RBAC

### 🛡 Keamanan
- Semua aksi tulis memakai `verifyCsrf()` + `requirePermission()` per aksi
- Output di-escape dengan `htmlspecialchars`
- SQL value di-escape dengan `real_escape_string` (mengikuti konvensi proyek)

---

## [1.8.0] — 2026-08-04

### ✨ Role Baru — Permission & Widget Default Otomatis
- Saat membuat role baru di `admin/roles.php`, permission dasar otomatis diberikan: `dashboard:view`, `profile:view/edit`, `notifications:view/delete`, `changelog:view`
- Widget default otomatis terpasang: **Ringkasan Dashboard**, **Profil Saya**, **Notifikasi Terbaru**
- 3 widget baru ditambahkan ke database (total 15 widget) dengan render khusus di dashboard
- Role baru langsung punya dashboard fungsional tanpa perlu konfigurasi manual

### ✨ Menu Management — Kelola Sidebar Dinamis
- **Halaman baru:** `admin/menus.php` — kelola seluruh menu sidebar langsung dari dashboard
- CRUD lengkap: tambah, edit, hapus menu dengan nama, slug, URL, icon, module permission, section, dan urutan
- **Toggle aktif/nonaktif** — menu disembunyikan tanpa perlu dihapus
- **Urutan naik/turun** dengan tombol panah di setiap baris
- **Submenu** — menu bisa dijadikan child dari menu lain (parent_id), dirender bertingkat di sidebar
- **Module permission** — menu hanya tampil untuk role yang punya permission `module:view` (otomatis tersembunyi jika tidak punya akses)
- Section baru bisa dibuat langsung dari form (datalist)

### ✨ Widget Management — Dashboard per Role
- **Halaman baru:** `admin/widgets.php` — kelola widget dashboard
- CRUD widget: judul, slug, icon, ukuran (small/medium/large/full), deskripsi, urutan, status aktif
- **Widget per Role:** matriks checkbox untuk mengatur widget mana yang tampil di dashboard setiap role
- Tombol "Pilih Semua" / "Kosongkan" per role, Super Admin terkunci (selalu semua widget)
- **Fallback render:** widget baru tanpa kode render khusus tetap tampil sebagai kartu informasi (tidak kosong)

### 🔐 RBAC
- Modul permission baru: `menus` & `widgets` (view/create/edit/delete) — hanya Super Admin yang mendapatkannya
- Menu sidebar "Menu Management" & "Widget Dashboard" muncul otomatis di section Akses & Keamanan

---

## [1.4.0] — 2026-07-26

### 🐛 Bugfix — Video Gallery Modal
- **Perbaikan:** Semua video (utama & samping) kini diputar di modal, bukan redirect ke YouTube/Instagram
- Root cause: Browser cache — file `main.js?v=1.1` masih versi lama
- Solusi: Cache busting dengan `v=1.2` + ganti dari inline `onclick` ke `data-video-url` (lebih aman dari masalah encoding)
- Regex diperbaiki dengan `const`/`let` konsisten, tanpa debug

---

## [1.3.0] — 2026-07-26

### ✨ Video Utama — Set Main Video
- **Tombol "Jadikan Video Utama"** (`setmain`) di admin video gallery — satu klik untuk menandai video mana yang tampil besar di galeri
- Badge "🜲 Utama" dengan icon crown pada video utama di tabel admin
- Badge "👑 Video Utama" dengan animasi pulse pada video utama di landing page
- Tombol crown hanya muncul untuk video yang belum jadi utama (sort_order > 0)
- Menggeser urutan video lain secara otomatis saat menetapkan video utama baru

---

## [1.3.0] — 2026-07-26

### ✨ Video Modal — Navigasi Prev/Next
- **Fitur baru:** Tombol navigasi prev/next pada video modal untuk gonta-ganti video tanpa tutup modal
- Tombol prev (←) dan next (→) dengan efek hover gold gradient dan scale
- Navigasi melingkar (dari video terakhir ke pertama, dan sebaliknya)
- Indikator posisi video di footer (contoh: "2/5 — Youtube")
- Tombol navigasi otomatis hilang jika hanya ada 1 video

### 🛠 Perbaikan
- **Fix:** Template literal string `addNavButtons()` diperbaiki dari single quotes ke backtick

## [1.4.0] — 2026-07-26

### ✨ Testimonial Slider / Carousel
- **Fitur baru:** Testimonial berubah dari grid statis menjadi slider interaktif
- Auto-slide setiap 5 detik dengan animasi smooth
- Navigasi dot indicators dengan efek active gold gradient memanjang
- Tombol prev/next dengan efek hover gold
- **Pause on hover** — slider berhenti saat mouse di atasnya
- **Swipe touch** — dukung geser di mobile
- **Keyboard support** — panah kiri/kanan saat slider di viewport
- Query testimonial ditingkatkan dari LIMIT 3 ke LIMIT 10

### ✨ Play/Pause Toggle
- **Fitur baru:** Tombol play/pause untuk kontrol autoplay slider testimonial
- Ikon pause (❚❚) saat aktif, ikon play (▶) saat dijeda
- Tombol berubah warna gold saat dijeda
- Toggle state dihormati saat hover (tidak override pause manual)
- Efek hover scale + glow gold yang konsisten dengan desain

## [1.5.0] — 2026-07-26

### ✨ Wishlist — Count Badge di Navbar
- **Fitur baru:** Badge jumlah wishlist muncul di navbar (icon hati) untuk user yang sudah login
- Badge otomatis update saat user menambah/menghapus wishlist (via AJAX)
- Jumlah wishlist terisi otomatis saat halaman dimuat
- Ikon hati juga ditambahkan di bottom navigation mobile
- Fungsi helper `getWishlistCount()` ditambahkan di database.php
- CSS `.wishlist-count` dengan style yang sama seperti cart badge

### 🛠 Perbaikan
- **Fix:** CSS cart badge diberi class `badge-count` agar konsisten

## [1.6.0] — 2026-07-26

### ✨ Admin — Sort Order Testimonial
- **Fitur baru:** Admin panel testimonial kini bisa atur peringkat (sort order)
- Tambah kolom `sort_order` INT di tabel testimonials
- Tombol panah ▲/▼ untuk naik/turunkan peringkat satu per satu
- Sort order otomatis terisi (max+1) saat tambah testimoni baru
- Landing page testimonial slider urut berdasarkan `sort_order ASC`
- Tampilan nomor urut (#) di tabel admin
- Tombol pertama/terakhir dinonaktifkan secara visual (opacity + pointer-events)



### ✨ Halaman Changelog di Admin Panel
- **Halaman baru:** `admin/changelog.php` — melihat catatan perubahan langsung dari dashboard admin
- Parser markdown sederhana untuk menampilkan CHANGELOG.md dengan format yang rapi
- Navigasi sidebar baru di menu "Lainnya"
- Tombol untuk melihat file asli `CHANGELOG.md`

---

## [1.7.0] — 2026-07-26

### ✨ Mobile Responsive — Semua Halaman Mobile-Friendly
- **Responsive komprehensif:** 3 breakpoint (1024px, 768px, 480px) mencakup seluruh halaman
- **Grid collapse:** Semua grid (grid-2/3/4, features, contact, footer, video, checkout) menyesuaikan ke 1-2 kolom di mobile
- **Touch-friendly:** Produk card actions selalu terlihat di HP (tidak perlu hover), efek hover dimatikan untuk touch device
- **Typography:** Ukuran font mengecil secara proporsional di setiap breakpoint
- **Spacing:** Padding section dan container mengecil di mobile
- **Cart mobile:** Layout cart item berubah jadi 2 kolom + full-width untuk quantity & subtotal
- **Tab & breadcrumb:** Bisa scroll horizontal di HP
- **Body padding:** Bottom nav tidak menutupi konten (padding-bottom: 70px)
- **Footer:** Grid jadi 2 kolom (tablet) → 1 kolom (HP), konten di-tengahkan
- **Promo timer:** Lebih kecil di mobile dengan wrap
- **Category pills:** Ukuran teks & padding mengecil

### ✨ Product Detail — Foto Besar & Lightbox Zoom
- **Layout lebih lebar:** Grid gallery berubah dari `1fr 1fr` ke `3fr 2fr` — foto produk lebih dominan
- **Aspect ratio 1:1** dengan `object-fit: cover` — foto mengisi penuh tanpa ruang kosong
- **Ikon zoom** (🔍) muncul saat hover di foto utama — sinyal bisa diklik
- **Lightbox fullscreen:** Klik foto untuk lihat ukuran penuh dengan backdrop blur, animasi zoom-in, tombol close fixed di pojok
- **Support ESC** untuk tutup lightbox
- **Thumbnail interaktif:** Efek scale saat hover, border gold saat aktif, klik untuk ganti foto utama dengan animasi smooth
- **Responsive mobile:** Grid jadi 1 kolom di HP, gallery tidak sticky

### ✨ Our Story — Ganti Foto dari Admin
- **Fitur baru:** Foto Our Story bisa diganti langsung dari admin Settings (tidak perlu edit kode)
- Upload file gambar (JPG, PNG, WebP, GIF) atau tempel URL gambar di admin/settings.php
- Gambar disimpan ke `uploads/story/` dengan nama unik
- Preview gambar langsung terlihat saat memilih file
- Fallback ke Unsplash default jika belum ada foto

### ✨ Promo Dinamis dari Database
- **Integrasi penuh:** Promo di homepage sekarang mengambil data dari tabel `promotions` (yang bisa diatur via admin/promo.php)
- Tiga kartu promo hardcoded (Diskon 20%, Free Ongkir, Bundle Hemat) diganti dengan query dinamis
- Hanya promo aktif (`is_active=1`) dan belum kadaluarsa (`end_date >= NOW()`) yang ditampilkan
- Setiap kartu menampilkan: badge diskon, judul, deskripsi, minimum pembelian (jika ada), dan timer countdown
- Timer countdown multi-promo — semua timer berjalan dengan end date masing-masing
- Fallback empty state jika belum ada promo
- Admin cukup tambah/edit promo di menu Promo, langsung muncul di homepage

---

## [1.1.0] — 2026-07-26

### ✨ Video Gallery — Lightbox/Modal
- **Fitur baru:** Video YouTube & Instagram kini diputar langsung di website via modal/lightbox (tidak perlu buka tab baru)
- Modal dengan backdrop blur, animasi scale-in spring, tombol close, dan klik luar untuk tutup
- Dukungan escape key (tekan Esc untuk tutup)
- YouTube: embed via `youtube.com/embed/VIDEO_ID?autoplay=1`
- Instagram: embed via `instagram.com/p/XXX/embed` atau `/reel/XXX/embed`
- Fallback: buka di tab baru untuk platform lain yang tidak didukung

### ✨ Video Gallery — Dukungan Instagram
- **Auto-fetch thumbnail Instagram** via oEmbed API publik saat admin menambahkan video
- Badge platform (YouTube merah / Instagram ungu-oranye gradient) pada setiap thumbnail
- Tombol play dengan gaya Instagram (gradient ungu-oranye) untuk video Instagram
- Background gradient Instagram sebagai fallback bila thumbnail tidak tersedia
- Cek ketersediaan ekstensi `curl` sebelum memanggil API

### 🛠 Perbaikan & Peningkatan
- `admin/videos.php`: Hapus `CURLOPT_SSL_VERIFYPEER => false` untuk keamanan
- `admin/videos.php`: Guard `function_exists('curl_init')` sebelum panggil cURL
- `index.php`: Escape `ENT_QUOTES` pada URL video untuk keamanan JavaScript

---

## [1.0.0] — Rilis Awal

Fitur lengkap website Nadhira Napoleon Pekanbaru:

### 🏠 Landing Page
- Hero section dengan video background, overlay, stat counter animasi
- Our Story section dengan layout grid
- Why Us / Features (4 kartu)
- Best Seller Produk dari database
- Promo Hari Ini dengan timer countdown
- Paket Oleh-Oleh
- Video Gallery (YouTube + Instagram)
- Cabang Kami dari database
- Testimonial pelanggan
- Artikel terbaru
- FAQ accordion
- Contact form + Newsletter

### 🛒 E-Commerce
- Katalog produk lengkap dengan filter kategori
- Detail produk dengan gallery gambar, tabs info (deskripsi, komposisi, penyimpanan, ulasan)
- Keranjang belanja (cart) dengan AJAX
- Checkout & payment confirmation
- Wishlist dengan AJAX
- Tracking pesanan
- Invoice PDF download

### 👤 Auth & Profil
- Register/Login user
- Profil pengguna
- Logout

### 📦 Admin Panel
- Manajemen produk (CRUD + upload gambar)
- Manajemen kategori
- Manajemen video gallery
- Manajemen cabang (branch)
- Manajemen testimonial
- Manajemen artikel
- Manajemen FAQ
- Manajemen promo
- Manajemen pesanan (orders)
- Manajemen pelanggan (customers)
- Manajemen pesan (messages)
- Pengaturan website (settings)

### 🔧 Teknis
- Database MySQL dengan schema lengkap
- Helper function untuk koneksi database
- AJAX untuk cart & wishlist
- Custom AOS animation (Intersection Observer)
- Smooth scrolling, parallax hero
- Toast notification
- Floating WhatsApp button
- Bottom navigation mobile
- Skeleton loading
- Breadcrumb
- Design system dengan CSS variables
- Responsive (mobile, tablet, desktop)
- Font: Playfair Display (heading), Poppins (UI), Inter (body)
