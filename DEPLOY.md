# 🚀 Deploy Otomatis — GitHub → InfinityFree

Website di-deploy otomatis setiap kali Anda `git push` ke branch `main`:

```
Windows + Laragon (lokal)
        │  git add / git commit / git push
        ▼
      GITHUB
        │  GitHub Actions (composer install + FTP upload)
        ▼
   INFINITYFREE  (ftpupload.net → htdocs/nadhira-napoleon.infinityfreeapp.com/)
        ▼
   WEBSITE ONLINE
```

## ⚙️ Setup sekali saja

### 1. Buat repo di GitHub
1. Buka [github.com/new](https://github.com/new)
2. Nama repo: misal `nadhira-napoleon`
3. Pilih **Private** (disarankan)
4. Jangan centang apa pun (README/.gitignore/license) — biarkan kosong
5. Klik **Create repository**

### 2. Cari kredensial FTP InfinityFree
1. Login ke [InfinityFree client area](https://www.infinityfree.net/)
2. **Accounts** → klik domain `nadhira-napoleon.infinityfreeapp.com`
3. Salin **FTP username** & **FTP password** dari bagian *FTP Details*

### 3. Tambahkan secrets di GitHub
1. Buka repo → **Settings** → **Secrets and variables** → **Actions**
2. Klik **New repository secret**, tambahkan 2 secret:
   - `FTP_USERNAME` → username FTP Anda
   - `FTP_PASSWORD` → password FTP Anda

### 4. Hubungkan & push pertama kali
Jalankan di terminal dari folder proyek:

```bash
git remote add origin https://github.com/NAMA_USER/nadhira-napoleon.git
git push -u origin main
```

> Ganti `NAMA_USER` dengan username GitHub Anda.

### 5. Pantau deploy
- Buka repo → tab **Actions** → workflow **🚀 Deploy ke InfinityFree**
- Deploy pertama ≈ 2–5 menit (700+ file via FTP)
- Website otomatis ter-update setiap push berikutnya ke `main`

## 🔁 Deploy manual
Tanpa push: buka tab **Actions** → **🚀 Deploy ke InfinityFree** → **Run workflow**.

## 🚫 File yang TIDAK ikut di-upload
Dikelola manual langsung di server (jangan diubah lewat repo):

| File/folder | Alasan |
|---|---|
| `config/database.php` | Berisi kredensial DB **produksi** InfinityFree (lokal tetap `root`) |
| `uploads/backups/` | Backup otomatis yang dibuat aplikasi di server |
| `uploads/payments/` | Bukti transfer pelanggan |
| `uploads/story/`, `uploads/products/`, `uploads/hero/`, `uploads/branches/` | Gambar yang di-upload via panel admin |

Semua data runtime di server **tidak akan dihapus** saat deploy (dikecualikan dari proses sinkronisasi FTP).

## 🔒 Catatan keamanan
- Ganti `INSTALL_KEY` di `config/database.php` (versi server) dengan nilai acak — jangan pakai nilai bawaan yang ada di repo ini.
- Kredensial lain (Midtrans, SMTP, Cloudinary, OTP) disimpan di tabel `settings` database, bukan di kode — aman.
