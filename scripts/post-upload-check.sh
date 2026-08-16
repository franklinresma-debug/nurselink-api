#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
API_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
cd "${API_ROOT}"
printf 'NurseLink API production preflight\n'
printf 'Path: %s\n' "$PWD"
printf 'PHP: '; php -v | head -1
printf 'Composer: '; composer --version | head -1
missing_extensions=0
for ext in bcmath ctype curl dom fileinfo intl mbstring openssl pdo_mysql sodium tokenizer xml zip; do
  if ! php -r "exit(extension_loaded('${ext}') ? 0 : 1);"; then
    echo "MISSING PHP EXTENSION: ${ext}"
    missing_extensions=$((missing_extensions + 1))
  fi
done

if (( missing_extensions > 0 )); then
  echo "ERROR: ${missing_extensions} required PHP extension(s) are missing."
  exit 1
fi

missing_ocr_tools=0
for tool in tesseract pdftotext pdftoppm; do
  if ! command -v "${tool}" >/dev/null 2>&1; then
    echo "MISSING OCR TOOL: ${tool}"
    missing_ocr_tools=$((missing_ocr_tools + 1))
  fi
done

if (( missing_ocr_tools > 0 )); then
  echo "ERROR: ${missing_ocr_tools} required OCR tool(s) are missing."
  exit 1
fi

scanner_driver="$(sed -n 's/^NURSELINK_MALWARE_SCANNER=//p' .env 2>/dev/null | tail -1 | tr -d '\r\"')"
clamav_socket="$(sed -n 's/^CLAMAV_SOCKET=//p' .env 2>/dev/null | tail -1 | tr -d '\r\"')"
if [[ "${scanner_driver}" == "clamav" && -n "${clamav_socket}" && ! -S "${clamav_socket}" ]]; then
  echo "ERROR: Configured ClamAV socket is unavailable: ${clamav_socket}"
  exit 1
fi
[ -f composer.json ] || { echo 'ERROR: composer.json missing'; exit 1; }
[ -f public/index.php ] || { echo 'ERROR: public/index.php missing'; exit 1; }
[ -f .env ] || echo 'NOTE: .env not created yet. Copy .env.production.example to .env and enter the DB password.'
echo 'Preflight complete.'
