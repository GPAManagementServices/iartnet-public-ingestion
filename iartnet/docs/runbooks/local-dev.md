# Local Development Runbook

## Prerequisites
- Docker Desktop (Windows) or Docker Engine (Linux)
- WSL2 (if on Windows, for Bash scripts)
- Git

## Quick Start

### Windows (PowerShell)
```powershell
# Initialize environment (first time only)
.\scripts\ps1\dev-init.ps1

# Start services
.\scripts\ps1\dev-up.ps1

# Stop services
.\scripts\ps1\dev-down.ps1
```

### Linux / WSL / macOS (Bash)
```bash
# Make scripts executable (first time only)
chmod +x scripts/bash/*.sh

# Initialize environment (first time only)
./scripts/bash/dev-init.sh

# Start services
./scripts/bash/dev-up.sh

# Stop services
./scripts/bash/dev-down.sh
```

## Detailed Setup

### 1. Environment Configuration
1. Copy `.env.example` to `.env`:
   ```bash
   cp .env.example .env
   ```
2. Edit `.env` and set secure passwords (especially `POSTGRES_PASSWORD`)

### 2. Start Infrastructure
Run the appropriate dev-up script for your platform. This will:
- Start PostgreSQL 16 on port 5432
- Start Redis on port 6379
- Wait for PostgreSQL to be ready

### 3. Backend Setup (Laravel API)
```bash
cd apps/api

# Install dependencies
composer install

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate

# (Optional) Seed database
php artisan db:seed
```

### 4. Frontend Setup (Nuxt Web)
```bash
cd apps/web

# Install dependencies
npm install

# Start development server
npm run dev
```

## Services

| Service | Port | Description |
|---------|------|-------------|
| PostgreSQL | 5432 | Master database |
| Redis | 6379 | Cache and queue backend |

## Troubleshooting

### Docker services won't start
- Check Docker is running: `docker ps`
- Check ports are not in use: `netstat -an | findstr "5432"`
- View logs: `docker compose -f infra/docker/docker-compose.yml logs`

### Database connection errors
- Verify PostgreSQL is ready: `docker exec iartnet-db pg_isready -U iartnet`
- Check `.env` has correct credentials
- Ensure `POSTGRES_HOST=postgres` (container name) in Laravel `.env`

### Permission errors (Linux/WSL)
- Ensure Docker daemon is accessible: `docker ps` should work without sudo
- Check script permissions: `chmod +x scripts/bash/*.sh`

## Cleanup
To remove all containers and volumes:
```bash
docker compose -f infra/docker/docker-compose.yml down -v
```

**Warning**: This will delete all database data!

## Next Steps
- See [test-plan.md](../qa/test-plan.md) for running tests
- See [requirements/README.md](../requirements/README.md) for project constraints
