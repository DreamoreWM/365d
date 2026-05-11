#!/bin/bash
# Surveille symfony serve et le relance automatiquement si ça crashe
# Usage : bash watch-symfony.sh (à lancer une fois depuis le dossier du projet)

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG="$PROJECT_DIR/var/log/symfony-watch.log"

mkdir -p "$PROJECT_DIR/var/log"

# Charger le webhook Discord depuis .env.local
if [ -f "$PROJECT_DIR/.env.local" ]; then
    DISCORD_WEBHOOK_DEPLOY=$(grep "^DISCORD_WEBHOOK_DEPLOY=" "$PROJECT_DIR/.env.local" | cut -d '=' -f2-)
fi

notify_discord() {
    local message="$1"
    if [ -n "$DISCORD_WEBHOOK_DEPLOY" ]; then
        curl -s -X POST "$DISCORD_WEBHOOK_DEPLOY" \
            -H "Content-Type: application/json" \
            -d "{\"content\": \"$message\"}" > /dev/null
    fi
}

echo "[$(date)] Watchdog démarré" | tee -a "$LOG"
notify_discord ":white_check_mark: Watchdog Symfony démarré"

while true; do
    if ! lsof -i :8000 -sTCP:LISTEN > /dev/null 2>&1; then
        echo "[$(date)] Symfony arrêté, relance en cours..." | tee -a "$LOG"
        notify_discord ":warning: Symfony a crashé, redémarrage en cours..."
        cd "$PROJECT_DIR"
        symfony serve --no-tls >> "$LOG" 2>&1 &
        sleep 5
        if lsof -i :8000 -sTCP:LISTEN > /dev/null 2>&1; then
            echo "[$(date)] Symfony relancé avec succès" | tee -a "$LOG"
            notify_discord ":white_check_mark: Symfony redémarré avec succès sur le port 8000"
        else
            echo "[$(date)] Échec du redémarrage" | tee -a "$LOG"
            notify_discord ":x: Échec du redémarrage de Symfony — intervention manuelle requise"
        fi
    fi
    sleep 10
done
