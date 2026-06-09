# GoHéritage

Kirby 5 CMS — 3D heritage-visualization platform (interactive map + 3D model viewer for heritage sites). Bitnami LAMP on AWS Lightsail ($5/mo, 512 MB RAM).

## Agent skills

### Issue tracker

GitHub Issues on `nondidjos/goheritage` via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Canonical labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context — `CONTEXT.md` + `docs/adr/` at repo root. See `docs/agents/domain.md`.

## Server
- IP: `3.66.136.66` (no domain yet)
- SSH: `ssh -i lightsail-key.pem bitnami@3.66.136.66`
- Web root: `/opt/bitnami/apache/htdocs/`
- Env vars: `.env` (LIGHTSAIL_HOST, LIGHTSAIL_KEY)

## Stack
- PHP 8 / Kirby 5 (5.4.x per composer.lock) / Apache 2.4 + PHP-FPM
- Vue.js panel components (Kirby's built-in panel system)
- Node v20 on server for file processing
- Sharp/libvips for texture compression — memory-tuned pipeline (UV dilation at 2048px blur canvas, ~270 MB peak). An earlier ImageMagick approach was abandoned; HANDOFF.md still references it but the live code is Sharp.

## Plugins (`site/plugins/`)
| Plugin | Purpose |
|--------|---------|
| `model-converter` | Panel fields: file upload/overwrite, OBJ→GLB, PNG compression |
| `hotspot-detector` | 3D viewer hotspot JSON upload + viewer |
| `invite-system` | User invitations |
| `plan-viewer` | Plan/blueprint viewer |
| `project-ux` | UX helpers |
| `totp-2fa` | 2FA |

### model-converter layout
```
index.php           — API routes + server-side logic
index.js            — Vue components (upload-overwrite, page-files-list, accordion-trigger)
index.css           — panel styles
compress-texture.js — Node/Sharp texture compression (UV-dilation + vibrance)
```

## Hard constraints
- **maxSize capped at 8192, scale-down only** — `compress-texture.js` never upscales; UV-dilation runs on a 2048px blur canvas to keep RAM bounded
- **Sharp peak ~270 MB (stage 2)** on a 512 MB server — the 1 GB swapfile is what keeps it from OOM-killing
- **PHP-FPM timeout**: `set_time_limit(300)` + `timeout=300` in FPM conf
- **Swap**: 1 GB swapfile in `/etc/fstab` — verify with `free -m` after reboots; without it Sharp OOM-kills mid-run

## Deploy plugin files
Use SSH heredoc only — scp corrupts CRLF files:
```bash
ssh -i lightsail-key.pem bitnami@3.66.136.66 'sudo tee /opt/bitnami/apache/htdocs/site/plugins/model-converter/index.js > /dev/null' << 'HEREDOC'
<file content>
HEREDOC
```
Always verify after deploy: `node --check /path/to/index.js && echo ok`

## Remaining work
- Domain + SSL (`sudo /opt/bitnami/bncert-tool`)
- OBJ→GLB end-to-end test on live server
- Hotspot UX verification in 3D viewer

## Kirby patterns
- Plugins registered via `Kirby::plugin('vendor/name', [...])`
- Panel fields: `panel.plugin('vendor/name', { fields: { 'field-name': { template, props, methods } } })`
- API routes in `'routes'` key of plugin array
- File type registration: `F::$types['document'][] = 'ext'`
- Auth: `$kirby->auth()->user()` — return 401 if null

## Useful server commands
```bash
free -m                                          # check memory + swap
sudo /opt/bitnami/ctlscript.sh restart php-fpm  # fix 503 after OOM
sudo /opt/bitnami/ctlscript.sh restart apache
tail -f /opt/bitnami/apache/logs/error_log
```
