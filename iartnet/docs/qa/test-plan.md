# IARTNET Test Plan

## Overview
This document outlines the minimal smoke tests and quality gates for the IARTNET platform.

## Test Levels

### 1. Build & Lint
**Purpose**: Ensure code compiles and follows style guidelines

#### Backend (Laravel)
```bash
cd apps/api
composer install
./vendor/bin/pint --test  # Laravel Pint linting
./vendor/bin/phpstan analyze  # Static analysis (if configured)
```

#### Frontend (Nuxt)
```bash
cd apps/web
npm ci
npm run typecheck  # TypeScript validation
npm run lint       # ESLint validation
```

### 2. Unit Tests
**Purpose**: Verify individual components work correctly

#### Backend
```bash
cd apps/api
php artisan test --parallel
```

#### Frontend
```bash
cd apps/web
npm test  # Vitest
```

### 3. Integration Tests
**Purpose**: Verify components work together

#### Database Integration
- Test database migrations up/down
- Test seeders
- Test tenant isolation (multi-tenant queries)

#### API Integration
- Test API endpoints with test database
- Test authentication/authorization
- Test file upload (ETL)

### 4. Health Checks
**Purpose**: Verify services are operational

#### Infrastructure
```bash
# PostgreSQL
docker exec iartnet-db pg_isready -U iartnet

# Redis
docker exec iartnet-redis redis-cli ping
```

#### Application
- Health endpoint: `GET /api/health` (if implemented)
- Database connectivity check
- Redis connectivity check

### 5. Accessibility Tests
**Purpose**: Verify WCAG 2.1 AA compliance

#### Automated
```bash
cd apps/web
npm run test:a11y  # If configured with axe-core
```

#### Manual
- Keyboard navigation (Tab, Enter, Escape)
- Screen reader compatibility
- Color contrast validation
- Focus indicators

### 6. Performance Tests
**Purpose**: Verify performance targets

#### Core Web Vitals
- LCP (Largest Contentful Paint) < 2.5s
- FID (First Input Delay) < 100ms
- CLS (Cumulative Layout Shift) < 0.1

#### Database
- Query performance (avoid N+1)
- Index usage validation

## CI/CD Integration

All tests run automatically on:
- Pull requests to `main` or `develop`
- Pushes to `develop`

See `.github/workflows/ci.yml` for the Standard-Validation job.

## Test Data

- Use factories for unit tests
- Use seeders for integration tests
- Never use production data in tests

## Coverage Targets

- **Minimum**: 60% code coverage
- **Target**: 80% code coverage
- **Critical paths**: 100% coverage (auth, tenant isolation, data import)

## Reporting

Test results are reported in:
- GitHub Actions workflow summary
- Local: `coverage/` directory (if coverage enabled)
- CI: Artifacts uploaded on failure

## Running Tests Locally

### Full Test Suite
```bash
# Backend
cd apps/api && php artisan test

# Frontend
cd apps/web && npm test
```

### Specific Test
```bash
# Backend
php artisan test --filter TestClassName

# Frontend
npm test -- TestFileName
```

## Known Issues
- List any known test failures or skipped tests here
- Update as issues are resolved
