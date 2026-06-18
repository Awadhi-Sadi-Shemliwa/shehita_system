# SHEHITA Enterprise Management System

A PHP + MySQL business management system (no framework). `homepage.php` is the
application shell; it loads each feature from `modules/<page>.php` based on the
`?page=` query parameter. Access is governed by role-based permissions defined in
`config.php`.

> For a full breakdown of every module and the database schema, see **[MODULES.md](MODULES.md)**.

---

## Running the app locally (Docker)

The project ships with a Docker setup (`Dockerfile` + `docker-compose.yml`) that runs
PHP + Apache and MySQL for you. You do **not** need PHP or MySQL installed on your machine.

### Every time you want to run it

**1. Start Docker Desktop.**
Open it from the Start menu and wait until it says **"Engine running"** (~30–60s).
You only need to do this once per Windows session.

**2. Start the app** from a terminal in the project folder:
```powershell
cd D:\coding\ShehitaSystem
docker compose up -d
```
The first run builds/downloads images (slow). After that it starts in a few seconds.

**3. Open the app** in your browser:

- URL: **http://localhost:8080**
- Login: **admin@paplontech.com** / **admin123**

**4. Stop it when you're done** (keeps all your data):
```powershell
docker compose stop
```
Next time, just run `docker compose up -d` again.

---

## Editing the code

Your `.php` files are mounted live into the container:

- Edit any `.php` file → **just refresh the browser**. No restart needed.
- **Only** if you change `Dockerfile` or `docker-compose.yml`, rebuild:
  ```powershell
  docker compose up -d --build
  ```

---

## Command reference

| What you want | Command |
|---|---|
| Start | `docker compose up -d` |
| Stop (keep data) | `docker compose stop` |
| See if it's running | `docker compose ps` |
| Watch error logs | `docker compose logs -f web` |
| **Reset to an empty DB** | `docker compose down -v` then `docker compose up -d` |

`docker compose down -v` wipes the database back to just the default admin account —
useful for testing the automatic first-run setup from a clean slate.

---

## Troubleshooting

- **`docker compose up` errors with `...dockerDesktopLinuxEngine` / "cannot find the file"**
  → Docker Desktop isn't running yet. Start it (step 1) and try again.

- **Port 8080 already in use**
  → Edit `docker-compose.yml` and change the `web` port mapping, e.g. `"8081:80"`,
  then `docker compose up -d`. Open http://localhost:8081 instead.

- **Database connection / credentials**
  → For local Docker, the DB host and credentials come from environment variables in
  `docker-compose.yml` (`DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`). `config.php`
  reads these and falls back to the hosted production values when they aren't set, so
  the same code works in both environments. MySQL is also exposed on host port **3307**
  if you want to inspect it with a database client.

---

## How first-run setup works

On first load, `config.php` automatically creates the database, **all tables** (in the
correct foreign-key dependency order), and a default admin account. No manual SQL import
is required — just start the containers and log in.
