# IARTNET - Integrated Art Network Platform

## Project Overview

IARTNET is a digital ecosystem developed for the **Accademia di Brera**.
It serves as a centralized hub for managing and narrating the cultural
heritage of AFAM institutions (Higher Education in Art, Music, and Dance).
The platform enables data ingestion from heterogeneous sources, normalization
into a Master Database, and advanced storytelling through interactive timelines
and maps.

## Technical Architecture

The project follows a **Monorepo** strategy to ensure strict decoupling
between the data processing layer and the public presentation layer.

* **apps/api**: Backend core powered by **Laravel 12** and **Filament 3**.
  It manages the Master DB (PostgreSQL) and the administrative backoffice.
* **apps/web**: Public-facing portal built with **Nuxt 4** (SSR), focused on
  accessibility (WCAG 2.1 AA) and performance.
* **apps/etl**: Specialized Extraction, Transformation, and Load modules for
  processing partner data (XML/JSON/CSV).
* **packages/shared**: Shared TypeScript definitions and business logic.
* **infra/docker**: Containerized environment using **PostgreSQL 16 LTS** and
  PHP 8.4.

## Key Constraints & Standards

* **Database**: PostgreSQL 16 LTS.
* **Metadata Standard**: Dublin Core / EDM compliance for the Master Database.
* **Data Privacy**: No external API dependencies for data ingestion; all
  imports are file-based as per project requirements.
* **CI/CD**: Strict validation via GitHub Actions (**Standard-Validation**
  job).

## Getting Started

### Prerequisites

* Docker Desktop (Windows) or Docker Engine (Linux)
* WSL2 (if on Windows, for Bash scripts)
* Git

### Quick Start

**Windows (PowerShell):**

```powershell
.\scripts\ps1\dev-init.ps1
```

**Linux / WSL / macOS (Bash):**

```bash
chmod +x scripts/bash/*.sh
./scripts/bash/dev-init.sh
```

This will:

1. Start Docker services (PostgreSQL, Redis)
2. Create `.env` from `.env.example` (if needed)
3. Wait for services to be ready

### Next Steps

1. **Setup the Backend**: Navigate to `apps/api`, install dependencies, and
   run migrations.
2. **Setup the Frontend**: Navigate to `apps/web` and run `npm install`.

For detailed setup instructions, see
[docs/runbooks/local-dev.md](docs/runbooks/local-dev.md).

## CI/CD & Quality Control

We employ a Top-Down agile methodology. Every Pull Request (PR) must pass the
automated Standard-Validation job.

* **Naming Convention**: Use feature/, bugfix/, or hotfix/ prefixes for
  branches.
* **Validation**: GitHub Actions will automatically check for linting, type
  safety, and accessibility compliance.
* **Merging**: Merges to main are restricted and require approval from senior
  validators once the "Standard-Validation" status is green.

## Documentation

* **[Requirements](docs/requirements/README.md)**: Key constraints and
  standards
* **[Traceability Matrix](docs/traceability/traceability-matrix.md)**:
  Requirements → Implementation mapping
* **[Architecture Decision Records](docs/adr/)**: ADR-0001 (Repo Structure)
* **[Local Development Runbook](docs/runbooks/local-dev.md)**: Detailed setup
  and troubleshooting
* **[Test Plan](docs/qa/test-plan.md)**: Testing strategy and quality gates

## Security Notice

**No secrets committed**: This repository uses `.env` files for
configuration. Never commit `.env` files or hardcode secrets. See
`.env.example` for required variables.

## License

This software is developed for the Italian Public Administration and is
released under the GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later) to ensure transparency, reuse,
and long-term sustainability.

Developed by GPA Management Services
