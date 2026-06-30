# Security Documentation

## Overview

IARTNET implements multiple layers of security scanning and best practices to ensure code quality and vulnerability detection.

## Security Tools

### 1. Trivy Security Scanner

Trivy scans for vulnerabilities in:
- **Filesystem**: Code and configuration files
- **Repository**: Git repository for secrets and vulnerabilities
- **Docker**: Dockerfile and container images

#### Running Trivy Locally (Pre-commit)

**Windows (PowerShell):**
```powershell
.\scripts\ps1\pre-commit-security.ps1
```

**Linux/WSL/macOS (Bash):**
```bash
chmod +x scripts/bash/pre-commit-security.sh
./scripts/bash/pre-commit-security.sh
```

#### Automatic Pre-commit Hook

A Git pre-commit hook automatically runs Trivy before each commit:

**Setup:**
```powershell
# Windows
.\scripts\ps1\setup-pre-commit.ps1

# Linux/WSL
chmod +x scripts/bash/pre-commit-security.sh
cp .git/hooks/pre-commit .git/hooks/pre-commit.bak  # Backup existing
# The hook is already in place
```

**Bypass (not recommended):**
```bash
git commit --no-verify
```

### 2. GitHub Actions Security Workflow

The `.github/workflows/security.yml` workflow runs:
- **Trivy filesystem scan** on every push/PR
- **Trivy repository scan** for secrets
- **Trivy Docker scan** for container vulnerabilities
- **Dependency checks** (Composer, npm audit)
- **Weekly scheduled scans** (Mondays at 00:00 UTC)

Results are uploaded to GitHub Security tab as SARIF files.

### 3. Super-Linter

Super-Linter ensures code quality and security best practices:
- PHP (PSR12 standard)
- JavaScript/TypeScript (ESLint)
- YAML/JSON validation
- Dockerfile linting (Hadolint)
- PowerShell/Bash linting
- Markdown validation

Runs automatically on every push/PR via `.github/workflows/linter.yml`.

### 4. Dependabot

Automated dependency updates:
- **GitHub Actions**: Weekly updates
- **npm**: Weekly updates for frontend
- **Composer**: Weekly updates for backend
- **Docker**: Weekly updates for base images

Configuration: `.github/dependabot.yml`

## Security Best Practices

### Secrets Management

✅ **DO:**
- Use GitHub Secrets for sensitive values
- Use environment variables (`.env` files)
- Reference secrets in workflows: `${{ secrets.SECRET_NAME }}`

❌ **DON'T:**
- Commit secrets to the repository
- Hardcode passwords or API keys
- Share `.env` files

### Code Security

✅ **DO:**
- Run pre-commit security scans
- Review Dependabot PRs promptly
- Keep dependencies up to date
- Use parameterized queries (prevent SQL injection)
- Validate and sanitize all inputs
- Encode all outputs (prevent XSS)

❌ **DON'T:**
- Skip security scans (`--no-verify`)
- Ignore security warnings
- Use deprecated dependencies
- Trust user input without validation

### Branch Protection

Configure on GitHub (Settings → Branches):
- Require pull request reviews
- Require status checks to pass (Standard-Validation, lint, security)
- Require branches to be up to date
- Do not allow force pushes
- Do not allow deletions

## Reporting Vulnerabilities

See [SECURITY.md](../../SECURITY.md) for vulnerability reporting procedures.

## Security Checklist

Before committing:
- [ ] Run pre-commit security scan
- [ ] No secrets in code
- [ ] Dependencies up to date
- [ ] No hardcoded credentials
- [ ] Input validation in place
- [ ] Output encoding in place

Before merging PR:
- [ ] All security checks passed
- [ ] No CRITICAL/HIGH vulnerabilities
- [ ] Code reviewed by team
- [ ] Dependabot updates reviewed

## Troubleshooting

### Trivy not found
```powershell
# Windows - Install via winget
winget install aquasecurity.trivy

# Or via Chocolatey
choco install trivy -y

# Or via Scoop
scoop install trivy
```

### Pre-commit hook not running
```bash
# Make sure hook is executable
chmod +x .git/hooks/pre-commit
chmod +x scripts/bash/pre-commit-security.sh

# Verify hook exists
ls -la .git/hooks/pre-commit
```

### Security scan too slow
- Trivy caches results automatically
- First run is slower, subsequent runs are faster
- Consider running only on changed files (advanced)

## Resources

- [Trivy Documentation](https://aquasecurity.github.io/trivy/)
- [GitHub Security Best Practices](https://docs.github.com/en/code-security)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [Laravel Security](https://laravel.com/docs/security)
