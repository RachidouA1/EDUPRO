#!/bin/bash
# =============================================================================
#  EDUPRO — Sauvegarde automatique MySQL (Docker) → Google Drive
#  Cron : 0 2 * * * /opt/EDUPRO/scripts/backup_db.sh >> /var/log/edupro_backup.log 2>&1
# =============================================================================

ENV_FILE="/opt/EDUPRO/scripts/.db_credentials"
if [ ! -f "$ENV_FILE" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERREUR : $ENV_FILE introuvable"
    exit 1
fi
source "$ENV_FILE"

DB_CONTAINER="${DB_CONTAINER:-edupro_db}"
DB_NAME="${DB_NAME:-edupro}"
DB_USER="${DB_USER:-edupro_backup}"
DB_PASS="${DB_PASS:-}"
BACKUP_DIR="${BACKUP_DIR:-/tmp/edupro_backups}"
GDRIVE_REMOTE="${GDRIVE_REMOTE:-gdrive}"
GDRIVE_FOLDER="${GDRIVE_FOLDER:-EDUPRO_Backups}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"

DATE=$(date '+%Y-%m-%d')
FILENAME="edupro_${DATE}.sql.gz"
FILEPATH="$BACKUP_DIR/$FILENAME"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

echo "========================================================"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Début sauvegarde EDUPRO"
echo "  Conteneur : $DB_CONTAINER"
echo "  Base      : $DB_NAME"
echo "  Fichier   : $FILENAME"
echo "  Dest.     : $GDRIVE_REMOTE:$GDRIVE_FOLDER/"

# Vérifier que le conteneur tourne
if ! docker inspect -f '{{.State.Running}}' "$DB_CONTAINER" 2>/dev/null | grep -q true; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERREUR : Conteneur $DB_CONTAINER non actif"
    exit 1
fi

# Dump depuis le conteneur MySQL
docker exec "$DB_CONTAINER" mysqldump     -u "$DB_USER"     -p"$DB_PASS"     --single-transaction     --routines     --triggers     --add-drop-table     --default-character-set=utf8mb4     "$DB_NAME" 2>/tmp/dump_err.log | gzip -9 > "$FILEPATH"

DUMP_STATUS=${PIPESTATUS[0]}

if [ "$DUMP_STATUS" -ne 0 ] || [ ! -s "$FILEPATH" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERREUR dump (code $DUMP_STATUS)"
    cat /tmp/dump_err.log
    rm -f "$FILEPATH"
    exit 1
fi

if ! gzip -t "$FILEPATH" 2>/dev/null; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERREUR : fichier gzip corrompu"
    rm -f "$FILEPATH"
    exit 1
fi

SIZE=$(du -sh "$FILEPATH" | cut -f1)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Dump OK — Taille : $SIZE"

# Upload Google Drive
rclone copy "$FILEPATH" "$GDRIVE_REMOTE:$GDRIVE_FOLDER/"     --log-level INFO     --retries 3     --retries-sleep 10s

if [ $? -ne 0 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERREUR : Upload Google Drive échoué"
    rm -f "$FILEPATH"
    exit 1
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Upload OK → $GDRIVE_REMOTE:$GDRIVE_FOLDER/$FILENAME"

# Nettoyage local
rm -f "$FILEPATH"

# Rotation : supprimer les fichiers > RETENTION_DAYS jours
rclone delete "$GDRIVE_REMOTE:$GDRIVE_FOLDER/"     --min-age "${RETENTION_DAYS}d"     --include "edupro_*.sql.gz"     --log-level INFO

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Sauvegarde terminée avec succès"
echo "========================================================"
exit 0
