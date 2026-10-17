#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
if [[ $# -ne 3 ]]; then
  echo "Pemakaian: BACKUP_PASSPHRASE_FILE=/path/rahasia ./bin/restore.sh database.sql.gz.gpg files.tar.gz.gpg manifest.sha256" >&2
  exit 2
fi
ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
cd "$ROOT"
DB_BACKUP="$(realpath -- "$1")"
FILES_BACKUP="$(realpath -- "$2")"
MANIFEST="$(realpath -- "$3")"
if ! [[ -v BACKUP_PASSPHRASE_FILE ]] || [[ -z "$BACKUP_PASSPHRASE_FILE" ]]; then
  echo "ERROR: Set BACKUP_PASSPHRASE_FILE ke file rahasia di luar repository." >&2; exit 1
fi
for command_name in docker gpg gzip tar realpath sha256sum awk; do
  command -v "$command_name" >/dev/null 2>&1 || { printf 'ERROR: perintah wajib tidak ditemukan: %s\n' "$command_name" >&2; exit 1; }
done
[[ -f .env ]] || { echo "ERROR: .env tidak ditemukan." >&2; exit 1; }
[[ -f "$DB_BACKUP" && -s "$DB_BACKUP" && -f "$FILES_BACKUP" && -s "$FILES_BACKUP" && -f "$MANIFEST" && -s "$MANIFEST" ]] || {
  echo "ERROR: database backup, file backup, dan manifest checksum harus ada dan tidak kosong." >&2; exit 1;
}
[[ -f "$BACKUP_PASSPHRASE_FILE" && -r "$BACKUP_PASSPHRASE_FILE" && -s "$BACKUP_PASSPHRASE_FILE" ]] || {
  echo "ERROR: file passphrase backup tidak dapat dibaca atau kosong." >&2; exit 1;
}
PASS_FILE="$(realpath -- "$BACKUP_PASSPHRASE_FILE")"
DB_NAME="$(basename -- "$DB_BACKUP")"
FILES_NAME="$(basename -- "$FILES_BACKUP")"
[[ "$DB_BACKUP" != "$FILES_BACKUP" && "$DB_NAME" != "$FILES_NAME" ]] || { echo "ERROR: pasangan backup tidak valid." >&2; exit 1; }
if ! awk -v db="$DB_NAME" -v files="$FILES_NAME" '
  BEGIN { dbok=0; filesok=0; count=0 }
  NF==2 && $1 ~ /^[a-fA-F0-9]{64}$/ {
    name=$2; sub(/^\*/, "", name)
    if (name==db) dbok++
    else if (name==files) filesok++
    else exit 1
    count++
    next
  }
  { exit 1 }
  END { if (count!=2 || dbok!=1 || filesok!=1) exit 1 }
' "$MANIFEST"; then
  echo "ERROR: manifest harus berisi checksum SHA-256 yang valid untuk tepat dua file backup ini." >&2; exit 1
fi
manifest_dir="$(dirname -- "$MANIFEST")"
(
  cd -- "$manifest_dir"
  sha256sum --check --strict "$MANIFEST"
) || { echo "ERROR: checksum backup tidak cocok; pemulihan dibatalkan." >&2; exit 1; }
echo "PERINGATAN: pemulihan akan mengganti isi tabel database dan folder unggahan dengan isi backup."
echo "Pastikan APP_KEY pada .env sama persis dengan kunci saat backup dibuat."
read -r -p "Ketik RESTORE untuk melanjutkan: " confirmation
[[ "$confirmation" == "RESTORE" ]] || { echo "Pemulihan dibatalkan."; exit 1; }
tmp_list="$(mktemp)"
web_stopped=0
cleanup() {
  rm -f -- "$tmp_list"
  if [[ "$web_stopped" -eq 1 ]]; then docker compose start web >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT INT TERM
echo "Memverifikasi dump database..."
gpg --batch --pinentry-mode loopback --passphrase-file "$PASS_FILE" --decrypt "$DB_BACKUP" | gzip -t -
echo "Memeriksa jalur arsip sebelum ekstraksi..."
gpg --batch --pinentry-mode loopback --passphrase-file "$PASS_FILE" --decrypt "$FILES_BACKUP" | tar -tzf - > "$tmp_list"
while IFS= read -r entry; do
  [[ -n "$entry" ]] || continue
  case "$entry" in storage/uploads|storage/uploads/*|assets/uploads|assets/uploads/*) ;; *) printf 'ERROR: jalur arsip tidak diizinkan: %s\n' "$entry" >&2; exit 1 ;; esac
  [[ "$entry" != /* && ! "$entry" =~ (^|/)\.\.(/|$) ]] || { printf 'ERROR: jalur arsip berbahaya: %s\n' "$entry" >&2; exit 1; }
done < "$tmp_list"
docker compose stop web
web_stopped=1
echo "Memulihkan database..."
gpg --batch --pinentry-mode loopback --passphrase-file "$PASS_FILE" --decrypt "$DB_BACKUP" | gzip -dc | docker compose exec -T db sh -ec 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
echo "Memulihkan folder unggahan dari arsip yang sudah diperiksa..."
gpg --batch --pinentry-mode loopback --passphrase-file "$PASS_FILE" --decrypt "$FILES_BACKUP" | docker compose run --rm --no-deps -T web sh -ec 'find /var/www/html/storage/uploads -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; find /var/www/html/assets/uploads -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; tar --no-same-owner --no-same-permissions -xzf - -C /var/www/html'
docker compose start web
web_stopped=0
echo "Pemulihan selesai. Periksa docker compose ps/logs, login, dokumen privat, serta halaman publik sebelum membuka layanan ke warga."
