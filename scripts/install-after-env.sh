#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
API_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
cd "${API_ROOT}"

if [ ! -f .env ]; then
  echo 'ERROR: .env does not exist. Copy .env.production.example to .env and edit DB_PASSWORD first.'
  exit 1
fi

echo '[1/8] Installing Composer dependencies...'
composer install --no-dev --optimize-autoloader --no-interaction

echo '[2/8] Permissions...'
chmod -R u+rwX storage bootstrap/cache

echo '[3/8] Clearing cached configuration...'
php artisan optimize:clear

echo '[4/8] Generating APP_KEY if missing...'
if ! grep -Eq '^APP_KEY=base64:.+' .env; then
  php artisan key:generate --force
fi

echo '[5/8] Database migrations...'
php artisan migrate --force

echo '[6/8] Core seed data...'
php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder --force
php artisan db:seed --class=Database\\Seeders\\CommunicationTemplateSeeder --force
php artisan db:seed --class=Database\\Seeders\\QualificationFrameworkSeeder --force

echo '[7/8] Production caches...'
php artisan config:cache
php artisan route:cache
php artisan event:cache || true

echo '[8/8] Readiness checks...'
php artisan nurselink:integration-check --deep || true
php artisan nurselink:ops-readiness || true

echo
cat <<'TXT'
NurseLink API installation phase complete.
Next:
  1. Create the Super Administrator:
     php artisan nurselink:make-super-admin YOUR_EMAIL
  2. Install the scheduler cron and supervised queue worker from deployment/.
  3. Open https://api.amsertech.com/api/health/live
  4. Run deployment/scripts/post-deploy-verify.sh.
TXT
