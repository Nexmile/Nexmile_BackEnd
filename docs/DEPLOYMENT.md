# Deployment — Instance + name.com domain mapping

Replace `nexmile.in` with your actual name.com domain and `3.0.119.102` with your
instance's public IPv4 address throughout.

## 1. Subdomain plan

Decide this **before** creating DNS records — the backend and the portals are separate
apps and should not share an origin.

| Subdomain | Serves | Lives on |
|---|---|---|
| `api.nexmile.in` | Laravel API (this repo) | Your instance |
| `merchant.nexmile.in` | Merchant portal (React) | Same instance or Vercel/Netlify |
| `admin.nexmile.in` | Admin portal (React) | Same instance or Vercel/Netlify |
| `nexmile.in` | Marketing site | Anywhere |

The Flutter customer and rider apps also call `api.nexmile.in` — they need no subdomain.

## 2. Create the instance

Any Ubuntu 24.04 LTS instance works (AWS EC2 / DigitalOcean / Hetzner / Contabo).
Minimum viable for the pilot: **2 vCPU, 4 GB RAM, 40 GB SSD**.

Open these ports in the provider's firewall / security group:

| Port | Purpose |
|---|---|
| 22 | SSH — restrict to your IP |
| 80 | HTTP — needed for Let's Encrypt |
| 443 | HTTPS |

Do **not** expose 3306 (MySQL) or 6379 (Redis) publicly.

## 3. Point the domain at the instance (name.com)

In name.com: **My Domains → nexmile.in → Manage DNS → Add Record**.

| Type | Host | Answer | TTL |
|---|---|---|---|
| A | `api` | `3.0.119.102` | 300 |
| A | `merchant` | `3.0.119.102` | 300 |
| A | `admin` | `3.0.119.102` | 300 |
| A | `@` | `3.0.119.102` | 300 |

Use a low TTL (300s) while setting up, then raise it to 3600 once stable.

Verify propagation before requesting certificates — **this must return your IP**:

```bash
nslookup api.nexmile.in
dig +short api.nexmile.in
```

If it returns nothing, wait — Certbot will fail until DNS resolves.

## 4. Server packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server git unzip \
  php8.5-fpm php8.5-mysql php8.5-mbstring php8.5-xml php8.5-curl \
  php8.5-zip php8.5-bcmath php8.5-redis php8.5-intl

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

## 5. Database

```bash
sudo mysql_secure_installation
sudo mysql
```

```sql
CREATE DATABASE nexmile CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nexmile'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON nexmile.* TO 'nexmile'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Never use the MySQL `root` account for the app.

## 6. Deploy the code

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/EnomApps/Nexmile_BackEnd.git nexmile
sudo chown -R $USER:www-data /var/www/nexmile
cd /var/www/nexmile

composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env` for production:

```env
APP_NAME=Nexmile
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.nexmile.in

CORS_ALLOWED_ORIGINS=https://merchant.nexmile.in,https://admin.nexmile.in

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=nexmile
DB_USERNAME=nexmile
DB_PASSWORD=a-long-random-password

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
```

`APP_DEBUG=false` is not optional — `true` in production leaks your `.env`
(including DB and Razorpay keys) on every error page.

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

## 7. Nginx

`/etc/nginx/sites-available/api.nexmile.in`:

```nginx
server {
    listen 80;
    server_name api.nexmile.in;

    # Laravel is served from public/, never the project root.
    root /var/www/nexmile/public;
    index index.php;

    charset utf-8;
    client_max_body_size 12M;   # KYC document uploads

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/api.nexmile.in /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Pointing `root` at `/var/www/nexmile` instead of `/var/www/nexmile/public` would
expose `.env` over HTTP. Always include `/public`.

## 8. HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api.nexmile.in -d merchant.nexmile.in -d admin.nexmile.in
sudo systemctl status certbot.timer   # auto-renewal
```

The Flutter apps will refuse plain HTTP on both Android and iOS, so TLS is required,
not optional.

## 9. Queue worker

Needed once OTP SMS, notifications and settlements land.

`/etc/systemd/system/nexmile-worker.service`:

```ini
[Unit]
Description=Nexmile queue worker
After=network.target

[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/nexmile/artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now nexmile-worker
```

## 10. Verify

```bash
curl -i https://api.nexmile.in/up                      # health check -> 200
curl -s https://api.nexmile.in/api/v1/merchant/me \
     -H "Accept: application/json"                     # -> 401 Unauthenticated
```

A **401** here is the correct result — it proves routing, PHP-FPM and Sanctum are all wired up.

Confirm `.env` is not reachable — this must return **404**:

```bash
curl -i https://api.nexmile.in/.env
```

## 11. Redeploying

**Everything under `/var/www/nexmile` is owned by `www-data`, and every
command below runs as `www-data`.** One owner, no exceptions — see the trap
after this block for what the alternative cost.

```bash
cd /var/www/nexmile

# What we are on before, so the pull can be seen to have worked.
sudo -u www-data git log --oneline -1

sudo -u www-data git pull origin master

# What we are on after. If these two are the same, the pull did nothing and
# everything below is about to run against the old code.
sudo -u www-data git log --oneline -1

# COMPOSER_HOME because www-data's home is not writable; without it composer
# fails on its own cache directory. --no-scripts then an explicit
# package:discover, so a failure in one is not buried in the other's output.
sudo -u www-data COMPOSER_HOME=/tmp/composer \
  composer install --no-dev --optimize-autoloader --no-scripts
sudo -u www-data php artisan package:discover

sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache

sudo systemctl restart php8.5-fpm nexmile-worker
```

`route:cache` is not optional when routes changed. A new endpoint is a 404
until it runs, and a 404 reads exactly like a backend that never shipped the
feature — which is how an app developer ends up reporting a bug against a
route that is sitting in the repository.

### Check the commit moved. Twice now it did not.

**On 24 November a `git pull` was run as `ubuntu`, and it changed the owner of
`.git`.** Every deploy afterwards failed, and the failure looked like this:

```
fatal: detected dubious ownership in repository at '/var/www/nexmile'
error: cannot open '.git/FETCH_HEAD': Permission denied
```

Git refuses to work on a repository owned by somebody else, and `www-data`
could no longer write the files `ubuntu` had created. But the pull failing did
not stop the rest of the script: `config:cache` reported success, PHP-FPM
reloaded, and the deploy looked finished. The server sat **four commits
behind for two days** and the first symptom was an artisan command "not
defined" — followed by a route the customer app had been built against
returning a 404, and a second application reading fields the API was not yet
sending.

The two fixes, in order:

```bash
# Tell git the repository is trusted, system-wide. NOT --global: that writes
# to the running user's home, and www-data's home is not writable, so the fix
# git itself suggests silently does nothing here.
sudo git config --system --add safe.directory /var/www/nexmile

# And put the ownership back where the rest of this document assumes it is.
sudo chown -R www-data:www-data /var/www/nexmile
```

Then confirm, because this is cheap and the alternative is two days:

```bash
ls -ld /var/www/nexmile /var/www/nexmile/.git /var/www/nexmile/storage
```

All three must say `www-data www-data`.

**Never run `git` in this directory as `ubuntu`.** A single pull as the wrong
user is all it takes, it succeeds at the time, and it breaks every deploy
after it.

### Why every line names its user

A command run as the wrong user does not stop the deploy. It prints a
permission error in the middle of a wall of output and carries on, and the
next sign of it is a feature that does nothing because the config it needs was
never re-cached — or, as above, a repository that stopped updating entirely.

That is why nothing here relies on whoever happens to be logged in.

If a `.env` change seems to be ignored, the cached config is why:

```bash
sudo -u www-data php artisan config:clear
```

Cached config never re-reads `.env`. With a cache in place, editing `.env` and
restarting changes nothing at all.

## Images

Uploads are resized and re-encoded to WebP on the way in, at the size they
are actually displayed. Before this, whatever a merchant uploaded is what
every customer downloaded — a four-megabyte phone photograph sent to draw a
four-hundred-pixel card, which on a Tier-2 connection is most of a minute
before the first screen settles.

**The server needs the GD extension:**

```bash
php -m | grep -i '^gd$' || sudo apt install -y php8.5-gd && sudo systemctl restart php8.5-fpm
```

Without it nothing breaks — the optimiser catches the failure and stores the
original, which is the right behaviour for a merchant adding a photo of
tonight's special. But it also means a missing extension is **silent**, and
the only sign is a warning in the log and images that stay large. Check for
it rather than assuming.

**Once, after deploying**, to shrink what was stored before the pipeline
existed:

```bash
cd /var/www/nexmile
sudo -u www-data php artisan nexmile:optimise-images --dry-run   # report only
sudo -u www-data php artisan nexmile:optimise-images             # rewrite
```

The dry run prints how many bytes it would save. **The rewrite is in place
and the originals are gone** — deliberately, since they are photographs of
shops and dishes rather than assets anybody will re-crop, but it is why the
dry run exists.

KYC documents are **not** touched. They are identity papers read by a person
deciding whether somebody may work, legibility is the whole point of them,
and no customer downloads them.

## Timezone

`APP_TIMEZONE=Asia/Kolkata`. **Not UTC**, and this matters more than it looks.

Almost everything the platform decides is a local-clock question: whether a
kitchen is inside its opening hours, which day an order counts toward, when a
Food Rescue window closes, what "today" means on a dashboard.

Running in UTC put all of those five and a half hours out. A merchant open
09:00–22:00 was told they were outside their hours until half past two in the
afternoon, because 09:00 IST is 03:30 UTC — so a lunchtime kitchen was
invisible to customers for the whole of lunch.

India has no daylight saving, so there is no ambiguity to reason about.
Storing UTC and converting on display is right for a multi-region product and
is needless indirection for one country.

### Changing it on a running server

MySQL `DATETIME` columns carry no timezone: Laravel writes whatever the app
clock says. Switching the app timezone therefore reinterprets **existing** rows
— a row written at 07:52 UTC now reads as 07:52 IST, five and a half hours
later than it happened.

At launch scale that is a handful of test orders and does not matter. Do it
before real trading, not after.

```bash
echo 'APP_TIMEZONE=Asia/Kolkata' >> .env
php artisan config:clear && php artisan config:cache
sudo systemctl reload php8.5-fpm
```

Confirm:

```bash
php artisan tinker --execute="echo config('app.timezone'), ' ', now()->format('D H:i'), PHP_EOL;"
```

It should agree with a clock on the wall in Chennai.
