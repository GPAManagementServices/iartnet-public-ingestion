# IARTNET Traceability Matrix

## Purpose
This matrix tracks the relationship between requirements, user stories, implementation, tests, and evidence.

## Format
| Req ID | Requirement | Story/Ticket | Implementation | Tests | Evidence |
|--------|-------------|--------------|----------------|-------|----------|
| REQ-001 | Master DB architecture | STORY-001 | `apps/api/database/` | `tests/Feature/MasterDbTest.php` | ADR-0002 |
| ... | ... | ... | ... | ... | ... |

## Requirements

### REQ-001: Master Database Architecture
- **Description**: PostgreSQL 16 LTS master database with mirror schemas for import/validation/promotion
- **Status**: In Progress
- **Story**: STORY-001
- **Implementation**: Database migrations in `apps/api/database/migrations/`
- **Tests**: Feature tests for schema isolation
- **Evidence**: ADR-0002 (Database Architecture)

### REQ-002: File-based Data Ingestion
- **Description**: ETL pipeline processes XML/JSON/CSV files without external API calls
- **Status**: Planned
- **Story**: STORY-002
- **Implementation**: `apps/etl/` modules
- **Tests**: Unit tests for parsers, integration tests for import flow
- **Evidence**: ADR-0003 (ETL Architecture)

### REQ-003: WCAG 2.1 AA Compliance
- **Description**: Public portal meets accessibility standards
- **Status**: Planned
- **Story**: STORY-003
- **Implementation**: `apps/web/` components with ARIA labels, semantic HTML
- **Tests**: Automated a11y tests (axe-core), manual keyboard navigation
- **Evidence**: QA test reports

### REQ-004: Multi-tenant Isolation
- **Description**: No cross-tenant data access
- **Status**: In Progress
- **Story**: STORY-004
- **Implementation**: Tenant scoping in all queries (`apps/api/app/Models/`)
- **Tests**: Security tests for tenant isolation
- **Evidence**: Security audit report

### REQ-005: Open Source Delivery
- **Description**: Codebase suitable for public release
- **Status**: In Progress
- **Story**: STORY-005
- **Implementation**: LICENSE, documentation, dependency audit
- **Tests**: License compliance check
- **Evidence**: LICENSE file, dependency report

### REQ-006: Laravel 12 + Filament 3 Backend Initialization
- **Description**: Initialize Laravel 12 application with Filament 3 admin panel in `apps/api/`
- **Status**: In Progress
- **Story**: STORY-006
- **Implementation**: `apps/api/` Laravel installation, Filament 3 setup, base configuration
- **Tests**: Laravel default test suite, Filament panel accessibility tests
- **Evidence**: Commit 05e3202, `apps/api/composer.json`

## Notes
- Update this matrix as requirements are implemented
- Link to ADRs for architectural decisions
- Link to test reports for evidence
- Use conventional commit messages that reference Req IDs where applicable
