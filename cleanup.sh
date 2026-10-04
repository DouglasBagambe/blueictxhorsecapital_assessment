#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    printf 'Usage: %s [--dry-run] /absolute/path [age_in_days]\n' "${0##*/}" >&2
    exit 2
}

dry_run=false
if [[ ${1:-} == '--dry-run' ]]; then
    dry_run=true
    shift
fi

[[ $# -ge 1 && $# -le 2 ]] || usage

target_argument=$1
age_days=${2:-7}

[[ $target_argument == /* ]] || { printf '[ERROR] Target must be an absolute path.\n' >&2; exit 2; }
[[ $age_days =~ ^[0-9]+$ ]] || { printf '[ERROR] Age threshold must be a whole number of days.\n' >&2; exit 2; }
[[ ! -L $target_argument ]] || { printf '[ERROR] Target directory must not be a symlink.\n' >&2; exit 2; }
[[ -d $target_argument ]] || { printf '[ERROR] Target directory does not exist.\n' >&2; exit 2; }

target=$(realpath -e -- "$target_argument")
case "$target" in
    /|/bin|/etc|/usr|/var|/home)
        printf '[ERROR] Refusing to scan critical directory: %s\n' "$target" >&2
        exit 2
        ;;
esac

printf '[INFO] Scanning directory: %s\n' "$target"

count=0
bytes_freed=0

while IFS= read -r -d '' file; do
    file_size=$(stat -c '%s' -- "$file")

    if [[ $dry_run == true ]]; then
        printf '[DRY-RUN] Would delete: %q\n' "$file"
    else
        rm -- "$file"
    fi

    ((count += 1))
    ((bytes_freed += file_size))
done < <(find -P "$target" -type f -name '*.log' -mtime +"$age_days" -print0)

megabytes=$(awk -v bytes="$bytes_freed" 'BEGIN { printf "%.2f", bytes / 1048576 }')
if [[ $dry_run == true ]]; then
    printf '[SUCCESS] Would delete %d log files. Total space that would be freed: %s MB.\n' "$count" "$megabytes"
else
    printf '[SUCCESS] Deleted %d log files. Total space freed: %s MB.\n' "$count" "$megabytes"
fi
