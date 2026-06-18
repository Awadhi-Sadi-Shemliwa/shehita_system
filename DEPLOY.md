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

`.env` must define:

| Variable                 | What it is                                      |
|--------------------------|-------------------------------------------------|
| `MYSQL_ROOT_PASSWORD`    | DB password (also used by the app to connect)   |
| `DB_NAME`                | Database name, e.g. `shehita_business`          |
| `ADMIN_DEFAULT_EMAIL`    | First admin login email                         |
| `ADMIN_DEFAULT_PASSWORD` | First admin login password (change after login) |

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

Visit **https://system.shehita.co.tz** and log in with your
`ADMIN_DEFAULT_*` credentials. **Change the admin password immediately.**

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

## Day-to-day commands (on the VPS)

| Task                     | Command                                                       |
|--------------------------|---------------------------------------------------------------|
| View status              | `docker compose -f docker-compose.prod.yml ps`                |
| View logs                | `docker compose -f docker-compose.prod.yml logs -f web`       |
| Restart                  | `docker compose -f docker-compose.prod.yml restart`           |
| Stop (keep data)         | `docker compose -f docker-compose.prod.yml stop`              |
| Manual redeploy          | `git pull && docker compose -f docker-compose.prod.yml up -d --build` |
| Find generated admin pw  | `docker compose -f docker-compose.prod.yml logs web \| grep "generated password"` |

The MySQL data lives in the named Docker volume `dbdata` and persists across
restarts and rebuilds.
