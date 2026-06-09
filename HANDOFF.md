# GoHéritage — Server & Plugin Handoff

## Server

| | |
|---|---|
| **Provider** | AWS Lightsail |
| **Plan** | $5/mo — 512 MB RAM, 1 vCPU, 20 GB SSD |
| **IP** | `3.66.136.66` (no domain yet — accessed by IP) |
| **Stack** | Bitnami LAMP (Apache 2.4, PHP 8, PHP-FPM) |
| **Node** | `/usr/bin/node` — v20.20.2 |
| **ImageMagick** | `convert` — version 6 |
| **Web root** | `/opt/bitnami/apache/htdocs/` |
| **SSH key** | `./lightsail-key.pem` (repo root) |

SSH in:
```bash
ssh -i lightsail-key.pem bitnami@3.66.136.66
```

Env vars are in `.env` at repo root (`LIGHTSAIL_HOST`, `LIGHTSAIL_KEY`).

---

## What was built

The plugin at `site/plugins/model-converter/` adds three Kirby panel field types:

### `upload-overwrite`
File upload field that overwrites same-named files instead of versioning them. Used for OBJ, PNG textures, and hotspot JSON files. Has:
- Drag-and-drop + file picker
- Delete / delete-all with confirmation dialogs
- OBJ → GLB conversion button (calls `convert-obj` API route → runs gltf-transform via Node)
- PNG texture compression with preset selector (calls `compress-file` API route → runs `compress-texture.js` via Node/ImageMagick)
- **Progress bar**: real XHR fill during upload, time-weighted fill (0→90% over 65 s) during compression

### `page-files-list`
Read-only list of all files on the current page with delete buttons.

### `accordion-trigger`
Click-to-expand section header in the panel blueprint.

---

## Plugin files

```
site/plugins/model-converter/
├── index.php           # PHP: API routes + server-side logic
├── index.js            # JS: all three Vue panel components
├── index.css           # CSS: panel field styles + progress bar
└── compress-texture.js # Node: ImageMagick texture compression script
```

### `compress-texture.js` — key design decisions

> **⚠ Superseded (2026-06):** the section below describes the original ImageMagick approach. The live code is now a **pure-Sharp/libvips** pipeline — see the file header in `compress-texture.js`. The ImageMagick notes are kept only for historical context. Current design: UV dilation on a 2048px blur canvas (~60 MB), composite + encode at full res (~270 MB peak), selective vibrance, JPEG 4:4:4 or WebP output. No ImageMagick binary or `MAGICK_*` env vars are used anymore.

- ~~Uses **ImageMagick `convert`** (not Sharp/libvips). Sharp retains ~230 MB of native memory after decoding an 8192×8192 PNG, reliably OOM-killing the server.~~
- **Resize happens before UV-dilation blur.** Blurring a 4096×4096 image (after resize) takes ~65 s and fits in RAM. Blurring at full 8192×8192 takes >10 minutes via disk cache.
- ~~**maxSize is capped at 4096** regardless of requested size.~~ **Now capped at 8192** (`MAX_SUPPORTED = 8192` in `compress-texture.js`; the PHP route clamps to the same). The Sharp pipeline's bounded-memory design (UV dilation on a 2048px blur canvas) is what made the higher cap viable on 512 MB.
- ~~Environment variables `MAGICK_MEMORY_LIMIT=256MiB`, `MAGICK_MAP_LIMIT=1GiB`, `MAGICK_DISK_LIMIT=4GiB` are passed via `execFileSync` because `policy.xml` is not read by the installed ImageMagick binary.~~

### PHP-FPM timeouts

The compress route calls `set_time_limit(300)` (5 minutes). Apache proxy timeout for the FPM socket is also 300 s (`timeout=300` in `conf/bitnami/php-fpm.conf`). A normal compression run takes ~65 s, well within limits.

---

## Swap — critical

The $5 instance has **no persistent swap file by default**. After every reboot it comes up with 0 MB swap, causing ImageMagick to be OOM-killed mid-run.

A 1 GB swap file has been created and added to `/etc/fstab`. If it ever disappears again:

```bash
sudo fallocate -l 1G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
grep -q swapfile /etc/fstab || echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

Verify: `free -m` should show ~1023 MB swap.

---

## Deploying JS/CSS plugin files

**Do not use `scp` or pipe (`ssh host < file`) for these files.** The Linux mount path truncates CRLF files silently and `scp` appends null bytes. The only reliable method is SSH heredoc:

```bash
ssh -i lightsail-key.pem bitnami@3.66.136.66 'sudo tee /opt/bitnami/apache/htdocs/site/plugins/model-converter/index.js > /dev/null' << 'HEREDOC'
<full file content here>
HEREDOC
```

After deploying, always verify:
```bash
ssh -i lightsail-key.pem bitnami@3.66.136.66 \
  "node --check /opt/bitnami/apache/htdocs/site/plugins/model-converter/index.js && echo ok"
```

---

## DNS / SSL — not done yet

The site is currently served over IP only (`https://3.66.136.66`). When a domain is pointed at the instance:

```bash
sudo /opt/bitnami/bncert-tool
```

Follow the prompts — it handles Let's Encrypt cert issuance and Apache vhost config automatically.

---

## Remaining work

- **Domain + SSL** (bncert-tool, see above)
- **OBJ → GLB conversion** — the Node script exists and runs, but has not been tested end-to-end through the panel on the live server
- **Hotspot JSON upload** — upload works; the viewer reads the JSON, but hotspot UX in the 3D viewer has not been fully verified
- **Upgrade consideration** — the $10 Lightsail plan (1 GB RAM, ~650 MB available) would give the Sharp pipeline more headroom (currently ~270 MB peak vs 512 MB total, swap-assisted)

---

## Useful server commands

```bash
# Check memory + swap
free -m

# Check what's running
ps aux | grep -E 'node|convert|php' | grep -v grep

# Restart PHP-FPM (fixes 503 after OOM kill)
sudo /opt/bitnami/ctlscript.sh restart php-fpm

# Restart Apache
sudo /opt/bitnami/ctlscript.sh restart apache

# Tail Apache error log
tail -f /opt/bitnami/apache/logs/error_log

# Test compression manually (max supported size: 8192)
/usr/bin/node --max-old-space-size=256 \
  /opt/bitnami/apache/htdocs/site/plugins/model-converter/compress-texture.js \
  /path/to/texture.png /tmp/out.jpg --size=8192 --quality=85
```
