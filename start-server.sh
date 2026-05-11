#!/bin/bash
# Auto-restart Symfony PHP server when it crashes
while true; do
    echo "[$(date)] Starting PHP server on port 8000..."
    php -S 0.0.0.0:8000 -t /home/user/365d/public/ >> /var/log/symfony-server.log 2>&1
    echo "[$(date)] Server stopped, restarting in 3 seconds..."
    sleep 3
done
