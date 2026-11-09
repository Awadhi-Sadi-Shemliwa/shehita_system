# Deploying SHEHITA to production (system.shehita.co.tz)

This guide covers a one-time server setup, then automatic deploys on every
push to `main` via GitHub Actions.

The app runs in its own Docker containers behind Nginx, so it is fully
isolated from any other app already running on the VPS (e.g. your FastAPI
app). Nginx routes requests by hostname, so the two never collide.

---

## 0. Before you start: rotate the old database password

The old InfinityFree credentials that used to be hard-coded in `config.php`
have been removed from the code, but they were previously stored in plain text.
**Treat them as compromised** and change/rotate that InfinityFree DB password
if that database still holds anything you care about. Production now uses a
fresh, self-hosted MySQL with secrets you control (see below).

---

## 1. DNS

In your domain DNS panel add an **A record**:

| Type | Name     | Value                |
|------|----------|----------------------|
| A    | `system` | `<your Contabo VPS IP>` |

Wait a few minutes, then confirm: `ping system.shehita.co.tz` should resolve
to your VPS IP.

---

## 2. One-time server setup

SSH into the VPS, then:

```bash
# Install Docker + compose plugin if not already present
#   (Contabo Ubuntu): https://docs.docker.com/engine/install/ubuntu/

# Clone the repo
sudo mkdir -p /opt/shehita && sudo chown $USER:$USER /opt/shehita
git clone https://github.com/Awadhi-Sadi-Shemliwa/shehita_system.git /opt/shehita
cd /opt/shehita

# Create the production secrets file
cp .env.example .env
nano .env          # fill in strong values

# Generate strong random values like this:
openssl rand -base64 24
```

`.env` must define (see `.env.example` for the annotated full list):

| Variable                  | What it is                                                        |
|---------------------------|-------------------------------------------------------------------|
| `MYSQL_ROOT_PASSWORD`     | DB password. The app connects as root because creating a company needs `CREATE DATABASE`. |
| `CONTROL_DB_NAME`         | The platform's own database, e.g. `shehita_control`               |
| `APP_BASE_URL`            | `https://system.shehita.co.tz` — **used to build every emailed link**, so it must be exact |
| `TRIAL_DAYS`              | Free trial length, default 14                                     |
| `PLATFORM_ADMIN_EMAIL`    | SHEHITA's first console login (not a client login)                |
| `PLATFORM_ADMIN_PASSWORD` | …and its password. Change it after first sign-in.                 |
| `BILLING_ADMIN_EMAILS`    | Who receives the one-click "confirm this payment" emails          |
| `MAIL_DRIVER`             | `resend` (recommended), `smtp`, or `log`                          |
| `RESEND_API_KEY`          | Required when `MAIL_DRIVER=resend`                                |
| `MAIL_FROM`               | e.g. `SHEHITA EMS <no-reply@shehita.co.tz>`                       |
| `CRON_KEY`                | Protects the daily reminder endpoint                              |
| `AI_API_KEY`              | Enables the AI assistant. Leave empty to disable it.              |
| `AI_BASE_URL`             | Model endpoint, default `https://api.groq.com/openai/v1`. Any OpenAI-compatible provider works. |
| `AI_MODEL`                | Default `llama-3.3-70b-versatile`                                 |

> There is no `DB_NAME` any more. Each client company gets its own database
> (`shems_<slug>`), created when they sign up.

> **Email is not optional.** The entire payment and activation flow runs through
> it: welcome messages, trial reminders, the confirmation link SHEHITA clicks,
> and invoices. If mail is not configured, customers can still be activated from
> the admin console, but nobody will be told anything.

Then start the stack:

```bash
# Make sure host port 8080 is free first:
sudo ss -ltnp | grep 8080      # if used, change the port (see note below)

docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml ps     # both services should be "Up"/healthy
```

> **Port conflict:** if 8080 is taken by your FastAPI app, change it in BOTH
> `docker-compose.prod.yml` (`127.0.0.1:XXXX:80`) and the Nginx config
> (`proxy_pass http://127.0.0.1:XXXX;`) — the two must match.

---

## 3. Nginx + HTTPS

```bash
sudo cp deploy/nginx/system.shehita.co.tz.conf \
        /etc/nginx/sites-available/system.shehita.co.tz
sudo ln -s /etc/nginx/sites-available/system.shehita.co.tz \
           /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# Free TLS cert (Certbot adds the 443 block + HTTP->HTTPS redirect automatically)
sudo certbot --nginx -d system.shehita.co.tz
```

Visit **https://system.shehita.co.tz/admin/** and sign in with your
`PLATFORM_ADMIN_*` credentials. **Change that password immediately.**

Then, in the console, open **Settings** and fill in your payment instructions
(bank account, Lipa namba) and the SHEHITA details printed on invoices — clients
see those on their billing page, and they start out as placeholders.

`https://system.shehita.co.tz/` itself redirects to **/signup**, which is where
client companies create their own accounts.

### If a second hostname needs to keep working

ARC previously ran as a separate deployment on `arc-system.shehita.co.tz`. After
it is adopted as a company (see below), that hostname keeps working via a vhost
that rewrites it onto the tenant path:

```bash
sudo cp deploy/nginx/arc-system.shehita.co.tz.conf         /etc/nginx/sites-available/arc-system.shehita.co.tz
sudo ln -s /etc/nginx/sites-available/arc-system.shehita.co.tz /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d arc-system.shehita.co.tz
```

---

## 4. Automatic deploys (CI/CD)

The workflow at `.github/workflows/deploy.yml` SSHes into the VPS on every
push to `main`, pulls the latest code, and rebuilds the containers.

### Set up an SSH key for GitHub to use

On the VPS (or locally), create a dedicated deploy key:

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/gh_deploy -N ""
# Authorize it on the VPS:
cat ~/.ssh/gh_deploy.pub >> ~/.ssh/authorized_keys
```

### Add GitHub repository secrets

In the repo: **Settings → Secrets and variables → Actions → New repository secret**

| Secret name   | Value                                                        |
|---------------|--------------------------------------------------------------|
| `SSH_HOST`    | VPS IP or hostname                                           |
| `SSH_USER`    | SSH username (e.g. `root` or your sudo user)                 |
| `SSH_PORT`    | SSH port (usually `22`)                                      |
| `SSH_KEY`     | Contents of the **private** key `~/.ssh/gh_deploy`           |
| `DEPLOY_PATH` | `/opt/shehita`                                               |

After that, every `git push` to `main` redeploys automatically. You can also
trigger it manually from the **Actions** tab → *Deploy to Contabo* →
*Run workflow*.

> The deploy runs `git reset --hard origin/main`, so the server always matches
> the repo exactly. Your `.env` and runtime files under `uploads/` are NOT
> tracked by git, so they are never touched by a deploy.

---

## 5. Scheduled jobs

Two cron entries. Add them with `crontab -e`:

```cron
# Trial and renewal reminders. Safe to run more than once a day -- it will not
# re-send anything it has already sent.
0 7 * * * curl -fsS -H "X-Cron-Key: YOUR_CRON_KEY" http://127.0.0.1:8080/cron/subscription-notices.php >> /var/log/shehita-notices.log 2>&1

# Nightly backup of every company's database plus uploaded logos.
30 2 * * * /opt/shehita/cron/backup.sh >> /var/log/shehita-backup.log 2>&1
```

> **Backups matter more than they used to.** With one database per company,
> losing `shems_<slug>` loses that whole client. `cron/backup.sh` dumps
> `--all-databases`, verifies the dump decompresses and is not truncated before
> pruning older ones, and archives `uploads/` separately (logos are not in MySQL).

---

## 6. Bringing an existing installation in as a company

For ARC, or any other separate copy of this codebase you want to fold in.

Its database already has the right schema, so nothing is migrated — it is
**adopted**. No rows are copied and nothing in it is rewritten.

```bash
# 1. Restore that installation's database onto this MySQL server, if it is not
#    already here:
docker compose -f docker-compose.prod.yml exec -T db     mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE arc_business"
gunzip < arc_dump.sql.gz | docker compose -f docker-compose.prod.yml exec -T db     mysql -u root -p"$MYSQL_ROOT_PASSWORD" arc_business

# 2. Copy its logo in
cp /path/to/arc.png uploads/systemlogo/arc.png

# 3. See exactly what would happen, changing nothing:
docker compose -f docker-compose.prod.yml exec web php cron/import-tenant.php     --slug=arc --name="Actuarial and Risk Consulting" --db=arc_business     --email=admin@arc.co.tz --logo=uploads/systemlogo/arc.png     --primary='#7d5419' --days=3650 --dry-run

# 4. Run it for real by dropping --dry-run
```

The existing user accounts in that database keep working unchanged — no new
administrator is created. Add the rewrite vhost above so old bookmarks survive,
then stop the old container.

---

## 7. Confirming a deploy worked

```bash
docker compose -f docker-compose.prod.yml exec web php tests/smoke.php
```

Drives the real endpoints end to end and cleans up after itself. It creates and
drops two databases named `shems_smoketest_*` and sends a handful of emails to
`*.test` addresses, so it is safe alongside live customers — but read the note at
the top of the file before running it against production for the first time.

---

## Day-to-day commands (on the VPS)

| Task                     | Command                                                       |
|--------------------------|---------------------------------------------------------------|
| View status              | `docker compose -f docker-compose.prod.yml ps`                |
| View logs                | `docker compose -f docker-compose.prod.yml logs -f web`       |
| Restart                  | `docker compose -f docker-compose.prod.yml restart`           |
| Stop (keep data)         | `docker compose -f docker-compose.prod.yml stop`              |
| Manual redeploy          | `git pull && docker compose -f docker-compose.prod.yml up -d --build` |
| Find generated admin pw  | `docker compose -f docker-compose.prod.yml logs web \| grep "generated password"` |
| List client databases    | `docker compose -f docker-compose.prod.yml exec db mysql -uroot -p -e "SHOW DATABASES LIKE 'shems_%'"` |
| Send reminders by hand   | `docker compose -f docker-compose.prod.yml exec web php cron/subscription-notices.php` |
| Back up now              | `./cron/backup.sh`                                            |

The MySQL data lives in the named Docker volume `dbdata` and persists across
restarts and rebuilds — that volume now holds every client company's database,
so it is the single most important thing on the server to back up.
