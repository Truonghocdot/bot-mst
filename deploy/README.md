# Production Deploy Guide

Guide nay mac dinh cho Ubuntu server, source code nam tai:

```bash
/var/www/html/bot-mst
```

## 1. Cai dependency cho backend

```bash
cd /var/www/html/bot-mst/core
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

## 2. Cai dependency cho worker

```bash
cd /var/www/html/bot-mst/worker
npm ci
npx playwright install chromium
```

Neu server thieu library he thong cho Playwright:

```bash
cd /var/www/html/bot-mst/worker
npx playwright install --with-deps chromium
```

## 3. Tao file env

```bash
cd /var/www/html/bot-mst/core
cp .env.example .env

cd /var/www/html/bot-mst/worker
cp .env.example .env
```

## 4. Cau hinh `core/.env`

Can dien toi thieu:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

TELEGRAM_BOT_TOKEN=...
TELEGRAM_WEBHOOK_SECRET=...
WORKER_API_TOKEN=...
ADMIN_PANEL_PASSWORD=...

DB_CONNECTION=sqlite
SESSION_DRIVER=database
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Neu dung SQLite:

```bash
cd /var/www/html/bot-mst/core
touch database/database.sqlite
sudo -u www-data php artisan key:generate --force
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
```

## 5. Cau hinh `worker/.env`

Can dien toi thieu:

```env
PLAYWRIGHT_HEADLESS=true
CORE_API_BASE_URL=https://your-domain.com
CORE_API_ENDPOINT=/api/ingestions/masothue
CORE_API_TOKEN=...

PROXY_ENABLED=true
PROXY_SERVER=http://your-proxy-host:port
PROXY_FALLBACK_SERVER=http://your-proxy-fallback-host:port
PROXY_USERNAME=...
PROXY_PASSWORD=...

CAPTCHA_BYPASS_ENABLED=false
CAPSOLVER_API_KEY=
```

## 6. Gan quyen thu muc Laravel

```bash
/var/www/html/bot-mst/deploy/fix-permissions.sh /var/www/html/bot-mst
```

Cho worker ghi duoc `.playwright` va cache:

```bash
ls -ld /var/www/html/bot-mst/core/storage /var/www/html/bot-mst/core/storage/logs
ls -l /var/www/html/bot-mst/core/storage/logs
```

## 7. Nginx

Copy file mau:

```bash
cp /var/www/html/bot-mst/deploy/nginx/bot-mst.conf /etc/nginx/sites-available/bot-mst.conf
ln -s /etc/nginx/sites-available/bot-mst.conf /etc/nginx/sites-enabled/bot-mst.conf
nginx -t
systemctl reload nginx
```

Nho sua:
- `server_name`
- duong dan `root`
- phien ban `php8.3-fpm.sock` neu server khac socket

## 8. Supervisor

Copy file queue:

```bash
cp /var/www/html/bot-mst/deploy/supervisor/bot-mst-queue.conf /etc/supervisor/conf.d/bot-mst-queue.conf
```

Copy file worker:

```bash
cp /var/www/html/bot-mst/deploy/supervisor/bot-mst-worker.conf /etc/supervisor/conf.d/bot-mst-worker.conf
```

Neu chua dung `nginx` va muon chay tam web app bang built-in server o cong `8001`:

```bash
cp /var/www/html/bot-mst/deploy/supervisor/bot-mst-web-8001.conf /etc/supervisor/conf.d/bot-mst-web-8001.conf
```

Nap lai supervisor:

```bash
supervisorctl reread
supervisorctl update
supervisorctl status
```

## 9. Dang ky webhook Telegram

```bash
curl -X POST "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook" \
  -d "url=https://your-domain.com/api/telegram/webhook" \
  -d "secret_token=<TELEGRAM_WEBHOOK_SECRET>"
```

## 10. Kiem tra nhanh

Kiem tra route:

```bash
cd /var/www/html/bot-mst/core
sudo -u www-data php artisan route:list
```

Kiem tra queue:

```bash
redis-cli --scan | sort
```

Kiem tra worker:

```bash
cd /var/www/html/bot-mst/worker
npm run crawl:once
```

Kiem tra supervisor:

```bash
supervisorctl status
tail -f /var/log/supervisor/bot-mst-queue.err.log
tail -f /var/log/supervisor/bot-mst-worker.err.log
```

## 11. URL quan tri

Sau khi nginx chay:

```text
https://your-domain.com/admin
```

Neu chay tam bang `artisan serve`:

```text
http://127.0.0.1:8001/admin
```

Dang nhap bang:
- `ADMIN_PANEL_PASSWORD` trong `core/.env`

## 12. Ghi chu quan trong

- Neu trong repo dang co token Telegram that, nen rotate token ngay sau khi deploy.
- Worker chi hoat dong on dinh khi proxy, user-agent, session storage state nhat quan.
- Tren production, uu tien chay moi lenh `php artisan ...` bang `sudo -u www-data` de tranh tao file trong `storage/` va `bootstrap/cache/` voi owner sai.
- Neu gap loi `Permission denied` khi ghi `storage/logs/*.log`, chay lai:

```bash
/var/www/html/bot-mst/deploy/fix-permissions.sh /var/www/html/bot-mst
supervisorctl restart bot-mst-web
supervisorctl restart bot-mst-queue
supervisorctl restart bot-mst-worker
```

- De deploy lai sau khi pull code moi:

```bash
/var/www/html/bot-mst/deploy/fix-permissions.sh /var/www/html/bot-mst

cd /var/www/html/bot-mst/core
composer install --no-dev --optimize-autoloader
npm ci && npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

cd /var/www/html/bot-mst/worker
npm ci

/var/www/html/bot-mst/deploy/fix-permissions.sh /var/www/html/bot-mst
supervisorctl restart bot-mst-queue
supervisorctl restart bot-mst-worker
```
