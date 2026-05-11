#!/usr/bin/env bash
#
# Push goheritage to AWS Lightsail.
#
# Required env vars (or .env at project root):
#   LIGHTSAIL_HOST   bitnami@<static-ip-or-domain>
#   LIGHTSAIL_KEY    /path/to/lightsail-key.pem
#
# Optional:
#   LIGHTSAIL_REMOTE_DIR    default /opt/bitnami/apache/htdocs
#   SKIP_BUILD              if "1", don't run "npm run build" before sync
#
# Run from the project root:    ./deploy/deploy.sh
#
set -euo pipefail

# Resolve project root (this script lives in <root>/deploy)
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# Load .env if present (so secrets stay out of shell history)
if [[ -f .env ]]; then
    set -a; . .env; set +a
fi

: "${LIGHTSAIL_HOST:?Set LIGHTSAIL_HOST=bitnami@<ip-or-domain>}"
: "${LIGHTSAIL_KEY:?Set LIGHTSAIL_KEY=/path/to/lightsail-key.pem}"
REMOTE_DIR="${LIGHTSAIL_REMOTE_DIR:-/opt/bitnami/apache/htdocs}"
SSH_OPTS=(-i "$LIGHTSAIL_KEY" -o StrictHostKeyChecking=accept-new)

# ── 1. Build production CSS ─────────────────────────────────────────────────
if [[ "${SKIP_BUILD:-0}" != "1" ]]; then
    echo "==> Building production CSS bundle"
    npm run build
fi

# ── 2. Make sure vendor/ + kirby/ are installed locally so we can ship them ─
if [[ ! -d kirby ]] || [[ ! -d vendor ]]; then
    echo "==> Installing PHP dependencies (kirby + vendor)"
    composer install --no-dev --optimize-autoloader
fi

# ── 3. Rsync the project up ────────────────────────────────────────────────
echo "==> Syncing -> $LIGHTSAIL_HOST:$REMOTE_DIR"
rsync -avz --delete --human-readable \
    -e "ssh ${SSH_OPTS[*]}" \
    --exclude-from=deploy/.deployignore \
    ./ "$LIGHTSAIL_HOST:$REMOTE_DIR/"

# ── 4. Install runtime Node deps on the server ──────────────────────────────
# obj2gltf, @gltf-transform/cli and sharp-cli are exec()'d by the
# model-converter / hotspot-detector plugins. They live in package.json
# "dependencies" so --omit=dev keeps them.
echo "==> Installing Node runtime deps on server"
ssh "${SSH_OPTS[@]}" "$LIGHTSAIL_HOST" "
    set -e
    cd '$REMOTE_DIR'
    if [[ -f package.json ]]; then
        npm install --omit=dev --no-audit --no-fund --silent
    fi
"

# ── 5. Fix ownership + writable Kirby dirs ──────────────────────────────────
echo "==> Fixing ownership and permissions"
ssh "${SSH_OPTS[@]}" "$LIGHTSAIL_HOST" "
    set -e
    cd '$REMOTE_DIR'
    sudo chown -R bitnami:daemon .
    # Kirby needs to write inside these
    for d in content media site/accounts site/sessions site/cache site/logs; do
        if [[ -d \"\$d\" ]]; then
            sudo find \"\$d\" -type d -exec chmod 2775 {} \;
            sudo find \"\$d\" -type f -exec chmod 0664 {} \;
        fi
    done
    # PHP / static files: read-only group
    sudo find . -type f \\( -name '*.php' -o -name '*.html' -o -name '*.js' -o -name '*.css' \\) \
        -not -path './node_modules/*' \
        -exec chmod 0644 {} \;
"

echo
echo "==> Deploy complete."
echo "    Visit: https://${LIGHTSAIL_HOST#*@}/"
echo "    Panel: https://${LIGHTSAIL_HOST#*@}/panel"
