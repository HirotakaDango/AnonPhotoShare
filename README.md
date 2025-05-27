# AnonPhotoShare

![Screenshot from 2025-05-27 14-06-48](https://github.com/user-attachments/assets/5da2488c-4a8e-439e-aa47-982254e3e65b)

AnonPhotoShare is a **single‑file PHP application** that lets you and your friends share images anonymously without needing a database or any third‑party services.  
Just drop it onto any PHP‑enabled web host ⟶ open the page ⟶ start uploading.

---

## ✨ Features

* **Drag‑and‑drop uploads** with instant thumbnail preview  
* **Automatic thumbnail generation** (default **750 × 750 px**) powered by the GD extension  
* **File‑type & size validation** – accepts *JPG, PNG, GIF, WebP* up to 10 MB  
* **Ephemeral storage** – files older than **3 days** are pruned on every request  
* **Download or view originals** in a single click  
* **Dark‑mode Bootstrap 5 UI** packaged right inside `index.php` – no external assets  
* Zero configuration, zero dependencies, 100 % self‑contained

---

## 📂 Project layout

```
.
├── index.php             # all application logic & UI
├── uploads/              # originals (created automatically, git‑ignored)
├── thumbnails/           # generated thumbs (created automatically, git‑ignored)
└── image_metadata.json   # tiny JSON DB with per‑file metadata
```

> **Note:** `uploads/`, `thumbnails/`, and `image_metadata.json` are created on first run if they don’t exist.

---

## 🚀 Quick start

1. **Clone or copy** the repository (it is literally one file):

   ```bash
   git clone https://github.com/HirotakaDango/AnonPhotoShare.git
   cd AnonPhotoShare
   ```

2. **Ensure PHP 8.0+** (with the **GD** extension enabled) is available.

3. **Launch** with the built‑in PHP server:

   ```bash
   php -S localhost:8000
   ```

4. Open <http://localhost:8000> in your browser and upload away!

---

## 🛠️ Configuration

Open `index.php` and tweak the constants near the top:

| Constant | Purpose | Default |
|----------|---------|---------|
| `THUMBNAIL_WIDTH` / `THUMBNAIL_HEIGHT` | Size of generated thumbnails | `750` |
| `MAX_FILE_SIZE` | Max upload size in bytes | `10 * 1024 * 1024` (10 MB) |
| `ALLOWED_MIME_TYPES` / `ALLOWED_EXTENSIONS` | Whitelist of file types | JPEG / PNG / GIF / WebP |
| `FILE_RETENTION_DAYS` | How long to keep files before pruning | `3` |

*Changes take effect immediately; no rebuild or restart required.*

---

## 🧹 House‑keeping

A lightweight `cleanup_old_files()` routine runs **on every request** and removes any images (and their thumbnails) older than the retention period.  
If you prefer a scheduled cleanup, you can comment out that call and run the function via `cron` instead:

```bash
# Every day at 02:00
0 2 * * * php /var/www/html/index.php cleanup
```

---


* Don’t forget to make `uploads/`, `thumbnails/`, and `image_metadata.json` **writable** by the web server user:  
  `chown -R www-data:www-data uploads thumbnails image_metadata.json`

---

## 🔒 Security notes

* All uploads are validated against their **MIME type** *and* **file extension**.
* Files are saved with a **random 12‑character ID** to avoid name collisions and guessing.
* Consider putting the app behind basic auth or a VPN if you do not want the world to upload files.
