# استقرار روی Ubuntu 24.04 + Nginx

یک سرور، یک دامنه. جایگزین `kariva.example` کنید.

1. **بسته‌ها**: `apt install nginx php8.3-fpm php8.3-{cli,pgsql,redis,mbstring,xml,curl,zip,gd,intl,bcmath} postgresql postgresql-contrib redis-server supervisor clamav-daemon certbot python3-certbot-nginx git rsync ufw fail2ban composer nodejs npm`
2. **دیتابیس**: `createuser kariva -P && createdb -O kariva kariva`؛ سپس `psql kariva -c 'create extension pg_trgm;'` (به‌عنوان superuser).
3. **کد**: `git clone … /var/www/kariva`؛ `mkdir -p /var/www/kariva/web /var/log/kariva`؛ مالکیت `storage` و `bootstrap/cache` برای `www-data`.
4. **محیط**: `cp deploy/.env.production.example backend/.env`، پر کردن، `php artisan key:generate`.
5. **Nginx**: `deploy/nginx/kariva.conf` → `/etc/nginx/sites-available/kariva`، فعال‌سازی، `certbot --nginx -d kariva.example`، `nginx -t && systemctl reload nginx`.
6. **Supervisor**: `deploy/supervisor/kariva-worker.conf` → `/etc/supervisor/conf.d/`؛ `supervisorctl reread && update`.
7. **Cron**: `deploy/cron/kariva` → `/etc/cron.d/kariva`.
8. **فایروال**: `ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw enable`؛ Postgres و Redis فقط روی localhost؛ `fail2ban` برای ssh؛ ورود ssh فقط با کلید.
9. **اولین استقرار**: `deploy/deploy.sh` (preflight را اجرا می‌کند؛ اگر چیزی ناامن بود متوقف می‌شود).
10. **ادمین**: `php artisan kariva:make-admin +93…`
11. **مانیتورینگ**: UptimeRobot روی `https://kariva.example/api/v1/health` (هر ۵ دقیقه).
12. **بکاپ**: بعد از استقرار، `php artisan kariva:backup` را یک‌بار دستی بزنید و با `deploy/restore.sh` در دیتابیس آزمایشی بازیابی کنید.

## بازگشت
`git reset --hard <commit قبلی>` + `deploy.sh`. اگر migration مشکل داشت: از بکاپ پیش از migration (`storage/app/backups`) بازیابی کنید.
