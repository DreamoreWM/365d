#!/bin/bash
# Surveille symfony serve et le relance automatiquement si ça crashe
# Usage : bash watch-symfony.sh (à lancer une fois depuis le dossier du projet)

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG="$PROJECT_DIR/var/log/symfony-watch.log"

mkdir -p "$PROJECT_DIR/var/log"

echo "[$(date)] Watchdog démarré" | tee -a "$LOG"

while true; do
    if ! lsof -i :8000 -sTCP:LISTEN > /dev/null 2>&1; then
        echo "[$(date)] Symfony arrêté, relance en cours..." | tee -a "$LOG"
        cd "$PROJECT_DIR"
        symfony serve --no-tls >> "$LOG" 2>&1 &
        sleep 5
    fi
    sleep 10
done
