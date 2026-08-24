# SHEHITA EMS — local development helper (Windows / PowerShell)
#
# Docker Compose is not installed on every machine (Docker Desktop can ship
# without the plugin), so this script drives plain `docker` commands instead.
# It does the same job as docker-compose.yml for local work.
#
# USAGE — open PowerShell in this folder and run one of:
#
#   .\dev.ps1 start     Start the app       -> http://localhost:8080
#   .\dev.ps1 stop      Stop it (keeps all your data)
#   .\dev.ps1 restart   Stop then start
#   .\dev.ps1 status    Is it running?
#   .\dev.ps1 logs      Live logs — this is where emails appear
#   .\dev.ps1 mail      Show only the emails the system "sent"
#   .\dev.ps1 test      Run the end-to-end smoke test
#   .\dev.ps1 rebuild   Rebuild after changing the Dockerfile
#   .\dev.ps1 reset     DELETE ALL LOCAL DATA and start fresh
#
# Your data lives in a Docker volume called shehita-dbdata and survives
# stop/start/restart/rebuild. Only `reset` destroys it.

param(
    [Parameter(Position = 0)]
    [ValidateSet('start', 'stop', 'restart', 'status', 'logs', 'mail', 'test', 'rebuild', 'reset', 'help')]
    [string]$Command = 'help'
)

$ErrorActionPreference = 'Stop'

$Image     = 'shehita-ems:dev'
$WebName   = 'shehita-web'
$DbName    = 'shehita-db'
$Network   = 'shehita-net'
$Volume    = 'shehita-dbdata'
$Port      = 8080
$ProjectDir = $PSScriptRoot

function Assert-Docker {
    try { docker version --format '{{.Server.Version}}' | Out-Null }
    catch {
        Write-Host ""
        Write-Host "Docker is not running." -ForegroundColor Red
        Write-Host "Open Docker Desktop from the Start menu, wait until it says"
        Write-Host "'Engine running' (about 30-60 seconds), then run this again."
        Write-Host ""
        exit 1
    }
}

function Test-ContainerExists($name) {
    $found = docker ps -a --filter "name=^/$name$" --format '{{.Names}}'
    return $found -eq $name
}

function Test-ContainerRunning($name) {
    $found = docker ps --filter "name=^/$name$" --format '{{.Names}}'
    return $found -eq $name
}

function Start-Stack {
    Assert-Docker

    # Network and volume are created once and reused.
    if (-not (docker network ls --filter "name=^$Network$" --format '{{.Name}}')) {
        docker network create $Network | Out-Null
        Write-Host "Created network $Network"
    }
    if (-not (docker volume ls --filter "name=^$Volume$" --format '{{.Name}}')) {
        docker volume create $Volume | Out-Null
        Write-Host "Created data volume $Volume"
    }

    # Build the web image if it is missing.
    if (-not (docker images -q $Image)) {
        Write-Host "Building the application image (first run, this takes a few minutes)..."
        docker build -t $Image $ProjectDir
    }

    # --- database -----------------------------------------------------------
    if (Test-ContainerRunning $DbName) {
        Write-Host "Database already running."
    }
    elseif (Test-ContainerExists $DbName) {
        docker start $DbName | Out-Null
        Write-Host "Database started."
    }
    else {
        docker run -d --name $DbName --network $Network `
            -e MYSQL_ROOT_PASSWORD=rootpass `
            -v "${Volume}:/var/lib/mysql" `
            --restart unless-stopped `
            mysql:8.0 | Out-Null
        Write-Host "Database created."
    }

    Write-Host "Waiting for the database to accept connections..." -NoNewline
    for ($i = 0; $i -lt 60; $i++) {
        $ok = docker exec $DbName mysqladmin ping -h localhost -prootpass 2>$null
        if ($LASTEXITCODE -eq 0) { break }
        Write-Host "." -NoNewline
        Start-Sleep -Seconds 2
    }
    Write-Host " ready."

    # --- web ----------------------------------------------------------------
    # Always recreated, so environment changes take effect and the container
    # picks up the current code. All state is in the database volume.
    if (Test-ContainerExists $WebName) { docker rm -f $WebName | Out-Null }

    docker run -d --name $WebName --network $Network -p "${Port}:80" `
        -v "${ProjectDir}:/var/www/html" `
        -e APP_ENV=development `
        -e "APP_BASE_URL=http://localhost:$Port" `
        -e APP_TIMEZONE=Africa/Dar_es_Salaam `
        -e DB_HOST=$DbName -e DB_USER=root -e DB_PASSWORD=rootpass `
        -e CONTROL_DB_NAME=shehita_control `
        -e TRIAL_DAYS=14 `
        -e PLATFORM_ADMIN_EMAIL=admin@shehita.co.tz `
        -e PLATFORM_ADMIN_PASSWORD=changeme123 `
        -e BILLING_ADMIN_EMAILS=admin@shehita.co.tz `
        -e MAIL_DRIVER=log `
        -e MAIL_LOG_FILE=/tmp/mail.log `
        -e CRON_KEY=local-dev-cron-key `
        --restart unless-stopped `
        $Image | Out-Null

    Start-Sleep -Seconds 3

    Write-Host ""
    Write-Host "  SHEHITA EMS is running." -ForegroundColor Green
    Write-Host ""
    Write-Host "  Create a company     http://localhost:$Port/signup.php"
    Write-Host "  SHEHITA admin        http://localhost:$Port/admin/"
    Write-Host "                       admin@shehita.co.tz / changeme123"
    Write-Host ""
    Write-Host "  Emails are not sent locally - see them with:  .\dev.ps1 mail"
    Write-Host ""
}

function Stop-Stack {
    Assert-Docker
    foreach ($c in @($WebName, $DbName)) {
        if (Test-ContainerRunning $c) { docker stop $c | Out-Null; Write-Host "Stopped $c" }
    }
    Write-Host "Stopped. Your data is kept - run '.\dev.ps1 start' to bring it back."
}

function Show-Status {
    Assert-Docker
    docker ps -a --filter "name=^/$WebName$" --filter "name=^/$DbName$" `
        --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
    Write-Host ""
    if (Test-ContainerRunning $WebName) {
        Write-Host "Open http://localhost:$Port/admin/" -ForegroundColor Green
    } else {
        Write-Host "Not running. Start it with: .\dev.ps1 start" -ForegroundColor Yellow
    }
}

switch ($Command) {
    'start'   { Start-Stack }
    'stop'    { Stop-Stack }
    'restart' { Stop-Stack; Start-Stack }
    'status'  { Show-Status }

    'logs' {
        Assert-Docker
        Write-Host "Live logs. Press Ctrl+C to stop watching (the app keeps running)."
        docker logs -f --tail 60 $WebName
    }

    'mail' {
        Assert-Docker
        Write-Host "Emails the system would have sent (newest last):"
        Write-Host ""
        docker exec $WebName sh -c "cat /tmp/mail.log 2>/dev/null || echo '(no emails yet)'"
    }

    'test' {
        Assert-Docker
        docker exec $WebName php /var/www/html/tests/smoke.php
    }

    'rebuild' {
        Assert-Docker
        Write-Host "Rebuilding the application image..."
        docker build -t $Image $ProjectDir
        Start-Stack
    }

    'reset' {
        Assert-Docker
        Write-Host ""
        Write-Host "This DELETES every local company, user and invoice." -ForegroundColor Red
        $answer = Read-Host "Type 'delete' to confirm"
        if ($answer -ne 'delete') { Write-Host "Cancelled - nothing was deleted."; break }

        foreach ($c in @($WebName, $DbName)) {
            if (Test-ContainerExists $c) { docker rm -f $c | Out-Null }
        }
        docker volume rm $Volume 2>$null | Out-Null
        if (Test-Path "$ProjectDir\uploads\tenants") {
            Remove-Item "$ProjectDir\uploads\tenants\*" -Recurse -Force -ErrorAction SilentlyContinue
        }
        Write-Host "Everything deleted. Starting fresh..."
        Start-Stack
    }

    default {
        Write-Host ""
        Write-Host "SHEHITA EMS - local development"
        Write-Host ""
        Write-Host "  .\dev.ps1 start     Start the app  -> http://localhost:$Port"
        Write-Host "  .\dev.ps1 stop      Stop it (keeps your data)"
        Write-Host "  .\dev.ps1 restart   Stop then start"
        Write-Host "  .\dev.ps1 status    Is it running?"
        Write-Host "  .\dev.ps1 logs      Live logs"
        Write-Host "  .\dev.ps1 mail      Show the emails the system 'sent'"
        Write-Host "  .\dev.ps1 test      Run the smoke test"
        Write-Host "  .\dev.ps1 rebuild   Rebuild after editing the Dockerfile"
        Write-Host "  .\dev.ps1 reset     DELETE ALL LOCAL DATA and start over"
        Write-Host ""
    }
}
