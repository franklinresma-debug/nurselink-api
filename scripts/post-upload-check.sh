#!/usr/bin/env bash
set -euo pipefail
cd /home/frankresma/nurselink-api
printf 'NurseLink API cPanel preflight\n'
printf 'Path: %s\n' "$PWD"
printf 'PHP: '; php -v | head -1
printf 'Composer: '; composer --version | head -1
for ext in bcmath ctype curl dom fileinfo intl mbstring openssl pdo_mysql sodium tokenizer xml zip; do
  php -m | grep -qi "^${ext}$" || echo "MISSING PHP EXTENSION: ${ext}"
done
[ -f composer.json ] || { echo 'ERROR: composer.json missing'; exit 1; }
[ -f public/index.php ] || { echo 'ERROR: public/index.php missing'; exit 1; }
[ -f .env ] || echo 'NOTE: .env not created yet. Copy .env.production.example to .env and enter the DB password.'
echo 'Preflight complete.'
