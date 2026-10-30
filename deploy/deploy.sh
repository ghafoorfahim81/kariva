#!/usr/bin/env bash
# Deploy the current main branch on the server. Run as the deploy user:  /var/www/kariva/deploy/deploy.sh
set -euo pipefail

APP=/var/www/kariva
cd "$APP"

echo "==> Getting the code"
git fetch --quiet origin main
git checkout --quiet main
git reset --hard origin/main

echo "==> Backend"
cd "$APP/backend"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan kariva:preflight          # stops the deploy before anything changes if a setting is unsafe
php artisan down --retry=10 || true
# A backup before every migration: the one thing that cannot be undone is a bad migration on real data.
php artisan kariva:backup --skip-files
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan storage:link || true
php artisan up

echo "==> Browser app"
cd "$APP/kariva-v4.8"
npm ci --no-audit --no-fund
npm run build
rsync -a --delete dist/ "$APP/web/"

echo "==> Restarting workers"
sudo supervisorctl restart 'kariva-worker:*'
sudo systemctl reload php8.3-fpm

echo "==> Check"
curl -fsS https://kariva.example/api/v1/health && echo
echo "Deployed $(git rev-parse --short HEAD)"
