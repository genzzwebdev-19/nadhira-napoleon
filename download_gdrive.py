"""
Download images from Google Drive folder.
Gunakan script ini untuk mendownload semua foto produk dari Google Drive.
Dapat dijalankan via terminal atau dari admin/download-gdrive.php
"""
import gdown
import os
import shutil
import sys

# Fix Unicode output for Windows console
if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

FOLDER_URL = 'https://drive.google.com/drive/folders/1O6e_UcbkGZLdO4uTIizHwULhlzeFd0xE'
OUTPUT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'temp_gdrive')
DEST_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'uploads', 'products', 'gdrive_images')


def main():
    print("=" * 50)
    print("DOWNLOAD GAMBAR GOOGLE DRIVE")
    print("Nadhira Napoleon - Product Images")
    print("=" * 50)

    # Clean and recreate temp directory
    if os.path.exists(OUTPUT_DIR):
        shutil.rmtree(OUTPUT_DIR)
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    os.makedirs(DEST_DIR, exist_ok=True)

    # Download all files from the folder
    print("\n[INFO] Mendownload gambar dari Google Drive...")
    print("  (Ini mungkin memakan waktu beberapa menit)")
    print("  Jika gagal karena rate limit, tunggu lalu coba lagi.\n")

    try:
        files = gdown.download_folder(FOLDER_URL, output=OUTPUT_DIR, quiet=False)
    except Exception as e:
        print(f"\n[ERROR] {e}")
        print("\n[WARNING] Beberapa file mungkin sudah terdownload.")
        # Check what was downloaded so far
        files = []
        for root, dirs, filenames in os.walk(OUTPUT_DIR):
            for f in filenames:
                if f.lower().endswith(('.jpg', '.jpeg', '.png')):
                    files.append(os.path.join(root, f))

    # Count downloaded files
    downloaded = []
    if files:
        if isinstance(files, list) and len(files) > 0 and isinstance(files[0], list):
            # gdown returns [[id, path, local_path], ...]
            for file_id, file_path, local_path in files:
                if os.path.exists(local_path):
                    downloaded.append(local_path)
        else:
            # Simple file list from exception handler
            downloaded = files

    print(f"\n[RESULT] Berhasil mendownload: {len(downloaded)} file")

    # Copy to destination
    print("\n[INFO] Menyalin ke folder uploads/products/gdrive_images/...")
    copied = 0
    for file_path in downloaded:
        try:
            dest = os.path.join(DEST_DIR, os.path.basename(file_path))
            if not os.path.exists(dest):
                shutil.copy2(file_path, dest)
                copied += 1
        except Exception as e:
            print(f"  [WARNING] Gagal menyalin {file_path}: {e}")

    print(f"\n[RESULT] {copied} file baru disalin")
    print(f"[RESULT] Total gambar di folder: {len(os.listdir(DEST_DIR))} file")
    print("\n[DONE] Buka admin/import-gdrive-images.php untuk mengelola gambar.\n")


if __name__ == '__main__':
    main()
