# IARTNET Requirements

## Overview
This document summarizes key requirements and constraints for the IARTNET platform.

## Core Constraints

### Database Architecture
- **Master Database**: PostgreSQL 16 LTS serves as the single source of truth
- **Mirror Databases**: Multiple mirror schemas support import/validation/promotion workflows
  - Import mirror: Temporary staging for raw data ingestion
  - Validation mirror: Data quality checks and normalization
  - Promotion workflow: Controlled promotion from mirror to master

### Data Ingestion
- **File-based imports only**: No external API dependencies for data ingestion
- **Supported formats**: XML, JSON, CSV
- **ETL Pipeline**: Specialized extraction, transformation, and load modules in `apps/etl`

### Metadata Standards
- **Dublin Core** compliance for basic metadata
- **EDM (Europeana Data Model)** compliance for interoperability
- Master Database must maintain semantic consistency across all ingested data

### Scalability
- Platform must support:
  - Multiple tenant institutions (AFAM)
  - Large-scale cultural heritage datasets
  - Concurrent import/validation operations
  - High-performance querying for public portal

### Accessibility & Performance
- **WCAG 2.1 AA** compliance for public-facing portal
- Performance targets: Core Web Vitals (LCP, FID, CLS)
- Responsive design for mobile and desktop

### Open Source Delivery
- All code must be suitable for open-source publication
- No proprietary dependencies that prevent redistribution
- Clear licensing (see LICENSE file)
- Documentation must enable community contribution

### Security & Privacy
- No secrets committed to version control
- Environment-based configuration
- Multi-tenant data isolation (no cross-tenant access)
- Input validation and output sanitization (OWASP guidelines)

## Technical Stack Requirements

- **Backend**: Laravel 12 + Filament 3
- **Frontend**: Nuxt 4 (SSR)
- **Database**: PostgreSQL 16 LTS
- **Queue**: Redis-based job processing
- **Containerization**: Docker with docker-compose

## Traceability
See [traceability-matrix.md](../traceability/traceability-matrix.md) for requirements → implementation mapping.
