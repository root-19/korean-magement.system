#!/usr/bin/env bash
#
# In-place cutover, run ON THE SERVER.
#
#   bash database/schema/cutover.sh
#
# Runs from the modern-web root (the folder holding `artisan`).
#
# It stops at the first failure rather than carrying on, and it refuses to touch
# anything until it has a backup it has actually checked the size of.
#
# NOTHING IS DELETED. The five legacy tables are renamed, never dropped; every
# other legacy table is left exactly as it is.

set -euo pipefail

DB_NAME='u532211211_jsut10academy'
DB_USER='u532211211_academy10min'

# Read the password once, without echoing it, and hand it to mysql through the
# environment. This is why the commands below never prompt -- the prompt is what
# swallowed the rest of a pasted block last time.
read -rsp "MySQL password for ${DB_USER}: " MYSQL_PWD
echo
export MYSQL_PWD

echo
echo "=== 0/6  Checking .env ========================================"

if [ ! -f artisan ]; then
    echo "    STOPPING: no ./artisan here. cd to the modern-web root first."
    exit 1
fi

# After the cutover both connections point at one database, and the importer has
# to be told the legacy tables were renamed. Getting this wrong mid-run leaves
# the site down, so it is checked before anything is touched.
need_env() {
    if ! grep -qE "^$1=$2\s*$" .env; then
        echo "    STOPPING: .env needs   $1=$2"
        echo "    Set it, run  php artisan config:clear , then run this again."
        exit 1
    fi
}

need_env LEGACY_TABLE_PREFIX legacy_
need_env LEGACY_DB_DATABASE "$DB_NAME"
need_env DB_DATABASE "$DB_NAME"
echo "    .env is set for an in-place cutover"

php artisan config:clear >/dev/null 2>&1 || true

STAMP=$(date +%Y-%m-%d-%H%M)
BACKUP="backup-before-cutover-${STAMP}.sql"

echo
echo "=== 1/6  Backup ==============================================="
mysqldump -u "$DB_USER" "$DB_NAME" > "$BACKUP"

SIZE=$(stat -c%s "$BACKUP" 2>/dev/null || stat -f%z "$BACKUP")
echo "    ${BACKUP}  ($((SIZE / 1024 / 1024)) MB)"

if [ "$SIZE" -lt 5000000 ]; then
    echo
    echo "    STOPPING: that backup is under 5 MB, so it is not a full dump."
    echo "    Nothing has been changed. Check the credentials and try again."
    exit 1
fi

echo
echo "=== 2/6  Row counts before ===================================="
mysql -u "$DB_USER" "$DB_NAME" -e "
    SELECT 'users' t, COUNT(*) n FROM users
    UNION ALL SELECT 'teacher_presence', COUNT(*) FROM teacher_presence
    UNION ALL SELECT 'feedback', COUNT(*) FROM feedback
    UNION ALL SELECT 'teacher_schedules', COUNT(*) FROM teacher_schedules;"

echo
echo "=== 3/6  Renaming legacy tables ==============================="
echo "    The legacy site stops working from here."
mysql -u "$DB_USER" "$DB_NAME" < database/schema/production_cutover.sql
echo "    done"

echo
echo "=== 4/6  Creating the modern schema ==========================="
php artisan migrate --force

echo
echo "=== 5/6  Importing the legacy data ============================"
php artisan legacy:import

echo
echo "=== 6/6  Verifying payroll ===================================="
echo "    The new engine must reproduce legacy pay to the peso."
php artisan legacy:verify-earnings

echo
echo "=== Done ======================================================"
echo "Backup kept at: ${BACKUP}"
echo
echo "If verify-earnings reported anything other than 0.00, do not"
echo "announce the new site yet -- send me the output first."
