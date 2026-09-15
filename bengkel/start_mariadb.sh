#!/bin/sh
# Wrapper start MariaDB untuk preview.
# Datadir diletakkan di /app (persisten lintas resume pod); /run adalah tmpfs
# sehingga direktori socket dibuat ulang tiap boot.
DATADIR=/app/.mariadb_data
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld 2>/dev/null
# Inisialisasi datadir bila belum ada
if [ ! -d "$DATADIR/mysql" ]; then
  mkdir -p "$DATADIR"
  chown -R mysql:mysql "$DATADIR"
  mariadb-install-db --user=mysql --datadir="$DATADIR" >/dev/null 2>&1
fi
chown -R mysql:mysql "$DATADIR" 2>/dev/null
exec /usr/sbin/mariadbd --user=mysql --datadir="$DATADIR" --socket=/run/mysqld/mysqld.sock --bind-address=127.0.0.1 --port=3306
