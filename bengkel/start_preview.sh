#!/bin/sh
# Wrapper start preview PHP bengkel.
# Bebaskan port 3000 dari proses lain (mis. frontend React yang auto-start
# saat pod resume) agar server PHP bisa mengikat port 3000.
PIDS=$(ss -ltnp 2>/dev/null | grep ':3000 ' | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u)
for p in $PIDS; do kill "$p" 2>/dev/null; done
sleep 1
# Set PHP ini values for large file uploads (restore database, import Excel)
exec /usr/bin/php -d upload_max_filesize=64M -d post_max_size=64M -d memory_limit=256M -d max_input_time=300 -d max_execution_time=300 -d max_input_vars=10000 -S 0.0.0.0:3000 -t /app/bengkel
