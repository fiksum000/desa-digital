#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
cd "$ROOT"
if ! [[ -v BACKUP_DIR ]] || [[ -z "$BACKUP_DIR" ]]; then echo "ERROR: Set BACKUP_DIR ke direktori backup di disk terpisah." >&2; exit 1; fi
if ! [[ -v BACKUP_PASSPHRASE_FILE ]] || [[ -z "$BACKUP_PASSPHRASE_FILE" ]]; then echo "ERROR: Set BACKUP_PASSPHRASE_FILE ke file rahasia di luar repository." >&2; exit 1; fi
for command_name in docker gpg gzip tar sha256sum realpath date; do
  command -v "$command_name" >/dev/null 2>&1 || { printf 'ERROR: perintah wajib tidak ditemukan: %s\n' "$command_name" >&2; exit 1; }
done
[[ -f .env ]] || { echo "ERROR: .env tidak ditemukan; salin dari .env.example terlebih dahulu." >&2; exit 1; }
[[ -f "$BACKUP_PASSPHRASE_FILE" && -r "$BACKUP_PASSPHRASE_FILE" && -s "$BACKUP_PASSPHRASE_FILE" ]] || { echo "ERROR: file passphrase backup harus dapat dibaca dan tidak kosong." >&2; exit 1; }
PASS_FILE="$(realpath -- "$BACKUP_PASSPHRASE_FILE")"
mkdir -p -- "$BACKUP_DIR"
BACKUP_DIR="$(cd -- "$BACKUP_DIR" && pwd -P)"
case "$BACKUP_DIR/" in "$ROOT/"*) echo "ERROR: BACKUP_DIR tidak boleh berada di dalam repository. Pilih disk/path backup di luar proyek." >&2; exit 1 ;; esac
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
db_name="desa-digital-db-"$stamp".sql.gz.gpg"
files_name="desa-digital-files-"$stamp".tar.gz.gpg"
manifest_name="desa-digital-"$stamp".sha256"
db_out="$BACKUP_DIR/$db_name"; files_out="$BACKUP_DIR/$files_name"; manifest_out="$BACKUP_DIR/$manifest_name"
db_tmp="$BACKUP_DIR/."$db_name".partial.$$"; files_tmp="$BACKUP_DIR/."$files_name".partial.$$"; manifest_tmp="$BACKUP_DIR/."$manifest_name".partial.$$"
for output in "$db_out" "$files_out" "$manifest_out"; do [[ ! -e "$output" ]] || { printf 'ERROR: file backup sudah ada: %s\n' "$output" >&2; exit 1; }; done
cleanup() { rm -f -- "$db_tmp" "$files_tmp" "$manifest_tmp"; }
trap cleanup EXIT INT TERM
echo "Membuat backup database terkompresi dan terenkripsi..."
docker compose exec -T db sh -ec 'exec mariadb-dump --single-transaction --routines --triggers --events --hex-blob --no-tablespaces -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' | gzip -c | gpg --batch --yes --pinentry-mode loopback --passphrase-file "$PASS_FILE" --symmetric --cipher-algo AES256 --compress-algo none --output "$db_tmp"
echo "Membuat backup unggahan privat dan media publik terenkripsi..."
docker compose exec -T web tar --numeric-owner -czf - -C /var/www/html storage/uploads assets/uploads | gpg --batch --yes --pinentry-mode loopback --passphrase-file "$PASS_FILE" --symmetric --cipher-algo AES256 --compress-algo none --output "$files_tmp"
[[ -s "$db_tmp" && -s "$files_tmp" ]] || { echo "ERROR: berkas backup kosong." >&2; exit 1; }
mv -- "$db_tmp" "$db_out"; mv -- "$files_tmp" "$files_out"
(cd -- "$BACKUP_DIR" && sha256sum -- "$db_name" "$files_name" > "$manifest_tmp")
mv -- "$manifest_tmp" "$manifest_out"; chmod 600 -- "$db_out" "$files_out" "$manifest_out"
echo "Backup selesai:"
printf '  Database : %s\n  Berkas   : %s\n  SHA-256  : %s\n' "$db_out" "$files_out" "$manifest_out"
echo "Simpan salinan terenkripsi di lokasi terpisah. APP_KEY harus dicadangkan terpisah melalui pengelola rahasia; tidak disertakan dalam arsip."
