# ADR-0001: Monorepo Structure

## Status
Accepted

## Context
IARTNET requires strict decoupling between:
- Data processing layer (ETL, Master DB management)
- Administrative backoffice (Filament)
- Public presentation layer (Nuxt portal)

The project must support:
- Shared TypeScript/PHP contracts
- Independent deployment of services
- Unified CI/CD pipeline
- Cross-platform development (Windows + Linux)

## Decision
Adopt a **monorepo structure** with the following layout:

```text
iartnet/
├── apps/
│   ├── api/          # Laravel 12 + Filament 3 backend
│   ├── etl/          # ETL modules for data ingestion
│   └── web/          # Nuxt 4 public portal
├── packages/
│   └── shared/       # Shared TypeScript/PHP contracts
├── infra/
│   ├── docker/       # Dockerfiles and docker-compose
│   └── scripts/      # Infrastructure automation
├── scripts/
│   ├── ps1/          # PowerShell scripts (Windows-first)
│   └── bash/         # Bash scripts (Linux/WSL)
└── docs/
    ├── adr/          # Architecture Decision Records
    ├── runbooks/     # Operational procedures
    ├── qa/           # Test plans and reports
    ├── traceability/ # Requirements traceability
    └── requirements/ # Requirements documentation
```

## Rationale
1. **Decoupling**: Clear separation of concerns while maintaining shared contracts
2. **Tooling**: Single repository simplifies dependency management and CI/CD
3. **Cross-platform**: Separate script directories for Windows (PowerShell) and Linux (Bash)
4. **Documentation**: Centralized docs with clear structure for ADRs, runbooks, and traceability
5. **Scalability**: Easy to add new apps or packages without restructuring

## Consequences
### Positive
- Single source of truth for dependencies and tooling
- Easier code sharing via `packages/shared`
- Unified CI/CD pipeline
- Better visibility of cross-cutting concerns

### Negative
- Larger repository size
- Requires discipline to maintain boundaries
- More complex local development setup (mitigated by scripts)

## Alternatives Considered
1. **Multi-repo**: Rejected due to complexity in dependency management and CI/CD
2. **Nx/Turborepo**: Considered but rejected for now to keep tooling minimal; may adopt later if needed

## References
- [Monorepo Best Practices](https://monorepo.tools/)
- Project requirements: strict decoupling between layers
