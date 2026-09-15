#!/bin/sh
# Daemon sinkronisasi katalog hargasukucadang.online setiap 24 jam.
# Dijalankan oleh supervisor `hsc_sync_daemon`.
# - Delay 60 detik saat start supaya MariaDB + PHP siap.
# - Sesudah tiap iterasi, tidur 24 jam (86400 detik).
# - Kegagalan tidak menghentikan daemon (di-log dan lanjut).
sleep 60
while true; do
  /usr/bin/php /app/bengkel/scripts/sync_hsc_cli.php || echo "$(date -Is) sync FAILED"
  sleep 86400
done
