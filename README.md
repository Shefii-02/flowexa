# Flowexa / WAAPI — Full-Stack Setup Guide

WhatsApp Business CRM & automation platform. This repo is a **monorepo of four independent
projects** that are deployed separately but talk to each other over HTTP/WebSocket. This README
is the single place that explains how to take a brand-new server (or a fresh laptop) and bring
every piece of the stack up from nothing, in the right order, with nothing assumed.

```
waapi/
├── backend/               Laravel 13 API — auth, CRM, billing, campaigns, queues (the "brain")
├── backend-node/           OpenWA — Node/NestJS WhatsApp gateway (sessions, sending, sockets)
├── frontend/               React 18 + Vite — the web app (agents/admin UI)
└── unichat_mobile_app/     Flutter — the mobile app (agents/admin, same API)
```

## How the four pieces fit together

```
                                   ┌─────────────────────────┐
                                   │   React (frontend)       │
                                   │   Flutter (mobile app)   │
                                   └────────────┬─────────────┘
                                   REST (JWT)    │    Socket.IO (WS)
                          ┌────────────────────┼────────────────────────┐
                          ▼                                              ▼
              ┌───────────────────────┐                    ┌───────────────────────────┐
              │  Laravel backend       │   admin API key    │  OpenWA node gateway       │
              │  (backend/)            │ ──────────────────▶│  (backend-node/)           │
              │  :8000                 │   (mint/revoke      │  :2785                     │
              │  MySQL + Redis         │    per-company key)  │  SQLite/Postgres + Redis   │
              │  Horizon + queue:work  │                      │  BullMQ + Socket.IO        │
              └───────────────────────┘                      └───────────────────────────┘
```

- **Laravel is the system of record**: users, companies, billing, CRM, campaigns. It never talks
  to WhatsApp directly.
- **The Node gateway (OpenWA) is the only thing that talks to WhatsApp.** Laravel calls it over
  HTTP using a per-company API key that Laravel mints via the gateway's **admin key**
  (`WA_CHAT_ADMIN_KEY`). See `backend/app/Console/Commands/WaChatAdminKey.php` /
  `WaChatToken.php`.
- **Sockets are not proxied through Laravel.** The React app and the Flutter app open a
  Socket.IO connection **directly to the Node gateway** (`VITE_WA_CHAT_WS_URL` /
  `ApiConstants.socketUrl`) to receive live QR codes, message events and session-status updates.
  Laravel has no broadcasting server configured (no Reverb/Pusher) — don't wire one up unless a
  new feature explicitly needs Laravel-originated real-time events.
- **The two backends never share a database.** Laravel owns MySQL; the gateway owns its own
  SQLite/Postgres. They only integrate through the admin-key → per-company-key HTTP contract
  above.

### Production domains

| Service | Domain |
|---|---|
| React frontend | `https://teamzo.io` |
| Laravel backend | `https://api.teamzo.io` |
| Node (OpenWA) gateway | `https://admin.chatwa.teamzo.io` |

Every `localhost`/`127.0.0.1` URL below is for local development only — swap in the domains
above for every production `.env` / config value.

---

## 0. Prerequisites — fresh Ubuntu 22.04/24.04 VPS packages

Run this once on a brand-new server before touching any of the four projects. Adjust package
manager commands for other distros.

```bash
sudo apt update && sudo apt upgrade -y

# Build tools (needed by native Node modules: sharp, better-sqlite3, bufferutil, etc.)
sudo apt install -y build-essential git curl unzip pkg-config python3 ca-certificates gnupg

# ── PHP 8.3 + extensions Laravel needs ───────────────────────────────────────
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.3 php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl \
  php8.3-gd php8.3-sqlite3

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ── Node.js 22 (via nvm, matches backend-node/.nvmrc) ────────────────────────
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
source ~/.bashrc
nvm install 22
nvm use 22
npm install -g pm2          # process manager for the Node gateway

# ── MySQL (Laravel) ───────────────────────────────────────────────────────────
sudo apt install -y mysql-server
sudo mysql_secure_installation

# ── Redis (Laravel queue/cache + Horizon + Node gateway queue/sockets) ───────
sudo apt install -y redis-server
sudo systemctl enable --now redis-server

# ── Chromium headless deps (whatsapp-web.js engine uses Puppeteer) ───────────
sudo apt install -y libnss3 libatk-bridge2.0-0 libx11-xcb1 libxcomposite1 \
  libxdamage1 libxrandr2 libgbm1 libasound2 libpangocairo-1.0-0 libxshmfence1

# ── Nginx (reverse proxy for all four services) + certbot (TLS) ──────────────
sudo apt install -y nginx certbot python3-certbot-nginx

# ── Process supervisor for Laravel queue workers + Horizon ───────────────────
sudo apt install -y supervisor

# ── Flutter SDK (only needed on the machine that BUILDS the mobile app,
#    not on the server) ───────────────────────────────────────────────────────
# https://docs.flutter.dev/get-started/install — any 3.24+ (Dart SDK ^3.7.0 required)
```

---

## 1. Laravel backend (`backend/`)

PHP 8.3, Laravel 13, MySQL, Redis, JWT auth (`php-open-source-saver/jwt-auth`), Horizon.

```bash
cd backend
composer install --no-dev --optimize-autoloader   # drop --no-dev locally for dev tooling

cp .env.example .env
```

Edit `.env`:

```env
APP_URL=https://api.teamzo.io
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=waapi_saas
DB_USERNAME=waapi
DB_PASSWORD=choose-a-strong-password

QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Points at the Node gateway (section 2) — different subdomain, same or different server.
WA_CHAT_API_ORIGIN=https://admin.chatwa.teamzo.io
# Either paste the gateway's admin key here once it exists (2.4 below)...
WA_CHAT_ADMIN_KEY=
# ...or, for same-server deploys, point straight at its key file instead:
# WA_CHAT_ADMIN_KEY_FILE=/var/www/waapi/backend-node/data/.api-key
```

Create the database, then:

```bash
mysql -u root -p -e "CREATE DATABASE waapi_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
  CREATE USER 'waapi'@'localhost' IDENTIFIED BY 'choose-a-strong-password'; \
  GRANT ALL PRIVILEGES ON waapi_saas.* TO 'waapi'@'localhost'; FLUSH PRIVILEGES;"

php artisan key:generate
php artisan jwt:secret                 # generates JWT_SECRET for php-open-source-saver/jwt-auth

php artisan migrate --force
php artisan db:seed                    # idempotent — safe to re-run (see DatabaseSeeder's docblock)
php artisan storage:link               # avatars / uploaded media under storage/app/public

npm install --ignore-scripts
npm run build                          # Vite build for any server-rendered assets
```

> Shortcut: `composer run setup` does install + `.env` copy + `key:generate` + `migrate --force`
> + `npm install` + `npm run build` in one go. Still run `jwt:secret`, `db:seed` and
> `storage:link` yourself — they aren't part of that script.

### 1.1 Local development

```bash
composer run dev
# runs: php artisan serve + queue:listen + pail (log tail) + vite, concurrently
```

### 1.2 Production: PHP-FPM + Nginx

Point PHP-FPM's pool and an Nginx server block at `backend/public`, standard Laravel Nginx config
(`fastcgi_pass unix:/run/php/php8.3-fpm.sock;`, `try_files $uri $uri/ /index.php?$query_string;`).
Enable TLS with `sudo certbot --nginx -d api.teamzo.io`.

### 1.3 Queue workers — Supervisor

Laravel queues (`redis`, `redis-campaigns`) must run as long-lived workers, not `queue:work` in a
terminal. Create `/etc/supervisor/conf.d/waapi-worker.conf`:

```ini
[program:waapi-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/waapi/backend/artisan queue:work redis --queue=default,campaigns,webhooks --sleep=3 --tries=3 --max-time=3600
directory=/var/www/waapi/backend
autostart=true
autorestart=true
numprocs=2
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/waapi/backend/storage/logs/worker.log
stopwaitsecs=3600
```

### 1.4 Horizon (recommended instead of 1.3)

`laravel/horizon` is already a dependency and `config/horizon.php` defines three named
supervisors (`supervisor-campaigns`, `supervisor-webhooks`, `supervisor-default`) tuned for this
app's actual queues — prefer it over raw `queue:work` in production, since it gives you a
dashboard, auto-scaling, and job metrics for free.

```bash
php artisan horizon:install   # already configured — only needed if config/horizon.php is missing
```

Run Horizon itself under Supervisor (`/etc/supervisor/conf.d/waapi-horizon.conf`):

```ini
[program:waapi-horizon]
process_name=%(program_name)s
command=php /var/www/waapi/backend/artisan horizon
directory=/var/www/waapi/backend
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/waapi/backend/storage/logs/horizon.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start waapi-horizon
```

Dashboard is at `https://api.teamzo.io/horizon` (gate it behind VPN/basic-auth/
`HorizonServiceProvider::gate()` in production — it's wide open by default).

> Run **either** 1.3 or 1.4, not both, for the same queues.

### 1.5 Scheduler (cron) — required

`routes/console.php` schedules: device-login token cleanup (5 min), `wa-cloud:run-automations`
(15 min), `wa-chat:check-health` (5 min — the per-company WhatsApp session health sweep that
fires webhooks on disconnect), `subscriptions:sweep` (daily), lead SLA checks, log pruning, etc.
None of this runs without a cron entry:

```bash
crontab -e -u www-data
# add:
* * * * * cd /var/www/waapi/backend && php artisan schedule:run >> /dev/null 2>&1
```

### 1.6 Useful artisan commands

| Command | Purpose |
|---|---|
| `php artisan wa-chat:admin-key` | Check or (`--rotate`) regenerate the gateway admin key stored in `WA_CHAT_ADMIN_KEY` |
| `php artisan wa-chat:token {company}` | Inspect/mint a company's own gateway API key |
| `php artisan wa-chat:send-test` | Send a test WhatsApp message through the gateway |
| `php artisan horizon` | Run the Horizon master supervisor in the foreground (dev) |
| `php artisan queue:work redis` | Run a single worker in the foreground (dev) |

---

## 2. Node WhatsApp gateway — OpenWA (`backend-node/`)

Node 22, NestJS, TypeORM, BullMQ, Socket.IO. This is a large, self-contained open-source project
("OpenWA") vendored into this repo — its own `README.md` and `docs/` folder are the deep
reference; this section is the condensed path to get it running and wired to Laravel.

> ⚠️ This connects to WhatsApp via reverse-engineered clients (`whatsapp-web.js` /
> `@whiskeysockets/baileys`), not Meta's official Cloud API. Use a dedicated/disposable number,
> never a primary business number — see `backend-node/README.md` → "Before you connect a number".

### Option A — Docker (recommended for a fresh server)

```bash
cd backend-node
docker compose -f docker-compose.dev.yml up -d
# API:       http://localhost:2785/api
# Dashboard: http://localhost:2785          (bundled into the API image)
# Swagger:   http://localhost:2785/api/docs
```

`docker-compose.yml` (the hardened production variant) additionally runs Postgres, Redis, MinIO
and a `docker-proxy` sidecar so the app container never touches `/var/run/docker.sock` directly —
use it instead of `docker-compose.dev.yml` once you're past local dev. In production this
container sits behind Nginx at `https://admin.chatwa.teamzo.io` (TLS terminated by Nginx, port
2785 only reachable on localhost) — see §2.2 for the WebSocket-upgrade headers Nginx needs.

### Option B — Bare metal with PM2

```bash
cd backend-node
nvm use 22
npm ci                       # locked install, includes the dashboard
cp .env.minimal .env         # single-session/SQLite quick start — see "scaling up" below
npm run build:all            # builds the NestJS API + the dashboard (Vite)
pm2 start ecosystem.config.cjs
pm2 save
pm2 startup                  # prints a one-time boot-hook command — run the command it prints
```

```bash
pm2 logs openwa-gateway      # tail logs
pm2 restart openwa-gateway   # after a rebuild
```

### 2.1 Scaling past the minimal config

`.env.minimal` disables Redis, the queue and the cache for a single-session personal bot. For a
multi-company SaaS deployment (this project's actual use case), copy `.env.example` instead and
enable:

```env
AUTO_START_SESSIONS=true        # re-attach every previously-authenticated session on restart
DATABASE_TYPE=postgres          # SQLite does not scale past one process
REDIS_ENABLED=true
QUEUE_ENABLED=true
CACHE_ENABLED=true
API_MASTER_KEY=generate-a-long-random-value
```

With `AUTO_START_SESSIONS=true`, keep `instances: 1` / `exec_mode: 'fork'` in
`ecosystem.config.cjs` as-is — see `docs/13-horizontal-scaling.md` before changing it.

### 2.2 Sockets — nothing extra to set up

Socket.IO is built into the gateway (`@nestjs/platform-socket.io`, `@socket.io/redis-adapter` when
`REDIS_ENABLED=true`). The React app and Flutter app connect to it directly
(`ws://localhost:2785` in dev, `wss://admin.chatwa.teamzo.io` in prod) for QR codes, message
events and live session status. No Laravel involvement and no separate socket server to run.

Nginx must upgrade the connection for the gateway's domain, or sockets silently fall back to
polling (or fail):

```nginx
server {
    listen 443 ssl;
    server_name admin.chatwa.teamzo.io;

    location / {
        proxy_pass http://127.0.0.1:2785;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### 2.3 Background queues — nothing extra to set up either

BullMQ (`@nestjs/bullmq`) handles the gateway's own internal job queues once `QUEUE_ENABLED=true`
and Redis is reachable; `@bull-board/nestjs` mounts a queue dashboard inside the gateway's own API
process — no separate worker process or Supervisor entry is needed for the Node side (unlike
Laravel's queue workers in §1.3/1.4).

### 2.4 Linking the gateway to Laravel

1. Start the gateway first (sections above). On first boot it writes its own admin key to
   `backend-node/data/.api-key`.
2. In `backend/.env`, either:
   - set `WA_CHAT_ADMIN_KEY_FILE=/var/www/waapi/backend-node/data/.api-key` (same-server deploys,
     no secret duplicated), or
   - copy the key's value into `WA_CHAT_ADMIN_KEY` directly (remote gateway).
3. From `backend/`, run `php artisan wa-chat:admin-key` to confirm Laravel can reach the gateway
   and see the matching key id.
4. Per-company gateway API keys are minted automatically by Laravel as companies connect a
   WhatsApp number — you don't create those by hand.

---

## 3. React frontend (`frontend/`)

React 18 + Vite + TypeScript, Redux Toolkit, React Query, Tailwind, Socket.IO client.

```bash
cd frontend
npm install
```

Create `.env` (no `.env.example` is committed — these are the vars the code reads):

```env
VITE_API_URL=https://api.teamzo.io/api/v1
VITE_WA_CHAT_API_URL=https://admin.chatwa.teamzo.io
VITE_WA_CHAT_WS_URL=wss://admin.chatwa.teamzo.io
VITE_APP_ENV=production
VITE_SESSION_TIMEOUT_MINUTES=60
VITE_SESSION_WARNING_MINUTES=5
```

For local dev point both at `localhost` (`http://127.0.0.1:8000/api/v1`,
`http://localhost:2785`). `vite.config.ts` already proxies `/api` → `http://localhost:8000` on
the dev server, so cross-origin isn't an issue locally.

```bash
npm run dev      # local dev server, http://localhost:3000
npm run build    # production build → frontend/dist
npm run preview  # smoke-test the production build locally
```

Serve `dist/` as static files behind Nginx at `teamzo.io` (standard SPA config: `try_files $uri
/index.html;`). TLS: `sudo certbot --nginx -d teamzo.io -d www.teamzo.io`.

---

## 4. Flutter mobile app (`unichat_mobile_app/`)

Flutter (Dart SDK `^3.7.0`), Riverpod 3, go_router, Dio, `socket_io_client`.

```bash
cd unichat_mobile_app
flutter pub get
```

Unlike the web app, **there is no `.env` here** — base URLs are compiled in. Before building for
a new environment, edit `lib/core/constants/api_constants.dart`:

```dart
static const laravelBase   = 'https://api.teamzo.io/api/v1';
static const nodeBase      = 'https://admin.chatwa.teamzo.io';
static const socketUrl     = 'wss://admin.chatwa.teamzo.io';
static const laravelOrigin = 'https://api.teamzo.io'; // used to resolve storage:// avatar URLs
```

```bash
flutter run                              # dev, connected device/emulator
flutter build apk --release              # Android
flutter build ios --release              # iOS (requires Xcode + signing setup on macOS)
```

---

## 5. Fresh-server checklist (do it in this order)

1. **Provision the VPS** → run section 0 in full.
2. **MySQL + Redis** running (`systemctl status mysql redis-server`).
3. **Node gateway** (§2) — bring it up first; Laravel needs its admin key to finish configuring.
4. **Laravel backend** (§1) — install, migrate, seed, `jwt:secret`, wire `WA_CHAT_ADMIN_KEY`
   (§2.4), Horizon + scheduler cron.
5. **React frontend** (§3) — point its `.env` at the live backend + gateway URLs, build, deploy.
6. **Flutter app** (§4) — only needed when you're cutting a new mobile build; edit
   `api_constants.dart` and rebuild.
7. **Nginx + TLS** for all three hostnames: `teamzo.io` (React), `api.teamzo.io` (Laravel),
   `admin.chatwa.teamzo.io` (Node gateway — remember the WebSocket-upgrade headers from §2.2).
8. Smoke test: log in on the web app → connect a WhatsApp number (QR shows up via the gateway's
   socket) → send a test message (`php artisan wa-chat:send-test`) → confirm it's queued/sent via
   Horizon's dashboard.

---

## 6. Troubleshooting

| Symptom | Likely cause |
|---|---|
| Laravel 500s on any queue-touching endpoint | Redis not running, or `QUEUE_CONNECTION`/`REDIS_HOST` wrong in `.env` |
| `wa-chat:admin-key` can't reach the gateway | `WA_CHAT_API_ORIGIN` wrong, gateway not started yet, or firewall blocking port 2785 |
| QR code / live status never updates in the web app | `VITE_WA_CHAT_WS_URL` wrong, or Nginx not configured to upgrade the connection for WebSockets (`proxy_set_header Upgrade $http_upgrade;`) |
| Scheduled jobs (SLA checks, session health sweep) never fire | No `schedule:run` cron entry (§1.5) |
| Horizon shows no workers | Supervisor isn't running `artisan horizon`, or you're running `queue:work` instead (§1.3 vs §1.4 — pick one) |
| WhatsApp session won't stay connected after a server restart | `AUTO_START_SESSIONS` is `false`, or PM2 isn't set up to boot on reboot (`pm2 startup` step skipped) |
| Avatars/media 404 on the frontend or app | `php artisan storage:link` was never run |
