#!/usr/bin/env bash
# Каталог данных PostgreSQL из ночной копии и архива WAL (README, раздел «Резервные копии
# и восстановление»).
#
#   docker/postgres/restore.sh <копия> <каталог данных> ["<время>"]
#
# <копия> — каталог ночной копии с base.tar.gz и SHA256SUMS. <каталог данных> — новый или пустой
# каталог, в него ляжет PGDATA. Без времени база восстанавливается до последнего сегмента
# архива WAL, со временем — до этого момента, например "2026-10-23 10:15:00+03".
#
# Сервер скрипт не запускает. Каталог данных монтируется в контейнер postgres как PGDATA,
# архив WAL — в /wal-archive: при первом запуске сервер дочитывает архив и открывает базу.
set -euo pipefail

copy="${1:?копия: каталог с base.tar.gz и SHA256SUMS}"
pgdata="${2:?каталог данных}"
target="${3:-}"

(cd "$copy" && sha256sum -c --quiet SHA256SUMS 2>/dev/null || shasum -a 256 -c --quiet SHA256SUMS)

mkdir -p "$pgdata"
[[ -z "$(ls -A "$pgdata")" ]] || { echo "restore: $pgdata не пуст" >&2; exit 1; }
tar -xzf "$copy/base.tar.gz" -C "$pgdata"

# Базовая копия поднимается и без архива: свой WAL у неё внутри. Архив нужен, чтобы дойти
# до минуты после неё.
touch "$pgdata/recovery.signal"
{
    echo "restore_command = 'gzip -dc /wal-archive/%f.gz > %p'"
    echo "recovery_target_action = 'promote'"
    [[ -z "$target" ]] || echo "recovery_target_time = '$target'"
} >> "$pgdata/postgresql.auto.conf"

echo "PGDATA готов: $pgdata${target:+, до $target}"
