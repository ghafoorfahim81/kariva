# فاز ۳ — اعتماد و راه‌اندازی (Trust and launch)

## ۱. تأیید هویت (verification)
- صفحه (actor) فقط توسط **مالک** درخواست تأیید می‌دهد: `POST /api/v1/verification` (با هدر `X-Actor-Id`).
- اسناد (عکس تذکره/جواز) با `POST /api/v1/media` روی `verification_request/document` بارگذاری می‌شوند: دیسک **خصوصی**، حداکثر ۴ فایل، ۶ مگابایت، هرگز URL عمومی ندارند.
- فقط درخواست `pending` سند می‌پذیرد (`verification.not_pending`)؛ درخواست دوباره هنگام pending → `verification.already_pending`؛ صفحهٔ تأییدشده → `verification.already_verified`.
- ادمین تأیید می‌کند → `actors.verified_at` پر می‌شود، اعلان می‌رسد، اسناد ۳۰ روز بعد پاک می‌شوند (`VERIFICATION_FILES_DAYS`). رد کردن بدون یادداشت ممکن نیست (`verification.note_required`).
- دیدن سند توسط ادمین در `audit_logs` ثبت می‌شود (`admin.document_viewed`).

## ۲. API ادمین (`/api/v1/admin/*`)
میدل‌ور: `auth:sanctum`, `active`, `admin`, `throttle:120,1`. ادمین ساختن: `php artisan kariva:make-admin +93700000000` (`--revoke` برای گرفتن).

| مسیر | کار |
|---|---|
| `GET overview` | شمارش‌ها (صف تأیید، گزارش‌های باز …) |
| `GET verifications`, `GET verifications/{id}`, `POST …/approve`, `POST …/reject`, `GET …/documents/{media}` | صف تأیید (قدیمی‌ترین اول) |
| `GET reports`, `POST reports/{id}/resolve`, `POST reports/{id}/dismiss` | رسیدگی به گزارش‌ها؛ `action` اختیاری: `suspend_actor`، `delete_review`، `archive_service` |
| `GET actors?q=` | جستجوی صفحه/کاربر با نام یا شماره |
| suspend / unsuspend actor و user | تعلیق؛ تعلیق کاربر توکن‌ها را می‌بندد |
| `DELETE reviews/{id}`, archive service | حذف نظر (آمار دوباره محاسبه می‌شود)، بایگانی خدمت |
| `GET audit-logs` | دفتر ثبت (فقط افزودنی) |
| `GET health` | وضعیت دقیق سیستم |

کاربر تعلیق‌شده در درخواست بعدی خارج می‌شود (`auth.account_unavailable`).

## ۳. سخت‌سازی امنیتی
- هدرها (`SecurityHeaders`): nosniff، X-Frame DENY، Referrer-Policy، Permissions-Policy، CORP، `Cache-Control: no-store, private`.
- Nginx: HSTS، CSP، محدودیت نرخ (`deploy/nginx/kariva.conf`).
- محدودیت نرخ پیش‌فرض API: ۲۴۰ در دقیقه؛ OTP: ۳ در ساعت برای هر IP.
- `TRUSTED_PROXIES` برای دیدن IP واقعی.
- `php artisan kariva:preflight --production` تنظیمات ناامن را می‌گیرد (debug روشن، کد OTP ثابت، اسکنر غیر clamav، queue همزمان، کوکی ناامن …). `deploy.sh` آن را پیش از هر تغییر اجرا می‌کند.

## ۴. مانیتورینگ
- `GET /api/v1/health` (عمومی، ۲۰۰ یا ۵۰۳) → به UptimeRobot/مشابه بدهید.
- `GET /api/v1/admin/health` جزئیات: دیتابیس، کش، صف، ضربان زمان‌بند (`kariva:heartbeat` هر دقیقه)، دیسک، آخرین بکاپ.

## ۵. بکاپ و بازیابی
- `kariva:backup` هر شب ۰۲:۳۰ (کابل): `pg_dump` فرمت custom + آرشیو آپلودها؛ پوشه با دسترسی ۰۷۰۰؛ نگه‌داری `BACKUP_KEEP_DAYS` (۱۴)؛ نسخهٔ دوم روی `BACKUP_DISK` (S3).
- پیش از هر migration در `deploy.sh` بکاپ گرفته می‌شود.
- بازیابی: `deploy/restore.sh <dump> <new_db>` در یک دیتابیس **جدید** تا پیش از جایگزینی بررسی شود. **بازیابی را ماهانه آزمایش کنید.**

## ۶. ارسال کد ورود واقعی
`OTP_DRIVER=http` با `OTP_HTTP_URL`, `OTP_HTTP_TOKEN`, `OTP_HTTP_BODY` (قالب با `{phone} {phone_local} {code} {message}`). هر ارائه‌دهندهٔ HTTP (واتساپ بیزنس یا پیامک) با تنظیم قالب کار می‌کند. خطای دروازه کد را در لاگ نمی‌گذارد.

## ۷. تصمیم‌های باز برای غفور
۱. ارائه‌دهندهٔ OTP (واتساپ یا پیامک)؟ ۲. دامنهٔ نهایی؟ ۳. دیسک بکاپ بیرونی (S3 کجا)؟

## ۸. وضعیت
بک‌اند: ۴۶۷ تست سبز. وب: پنل ادمین واقعی (`AdminPanel.vue`، فقط برای `is_admin`) و درخواست تأیید هویت با بارگذاری عکس (`VerificationPanel.vue`) به API وصل شدند؛ نشان ✔ روی کارت‌ها؛ متن اعلان‌ها و خطاها اضافه شد. آزمون مرورگر (Playwright) گردش کامل «درخواست → صف ادمین → تأیید → نشان» را سبز گذراند.

یادداشت: بررسی سلامت کش با Redis واقعی یافت و درست شد (مقدار به‌صورت رشته برمی‌گردد).
