# Security Policy

## Supported Versions

We actively support security updates for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| Latest  | :white_check_mark: |
| < Latest | :x:                |

## Reporting a Vulnerability

We take the security of IARTNET seriously. If you believe you have found a
security vulnerability, please report it to us as described below.

### How to Report

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, please report them via one of the following methods:

1. **Email**: Send an email to `security@gpams.it` with the subject line
   `[IARTNET Security]` followed by a brief description.

2. **GitHub Security Advisory**: If you have a GitHub account, you can use the
   [GitHub Security Advisory](https://github.com/GPAManagementServices/iartnet/security/advisories/new)
   feature.

### What to Include

When reporting a vulnerability, please include:

- **Type of issue** (e.g., buffer overflow, SQL injection, cross-site
  scripting, etc.)
- **Full paths of source file(s) related to the manifestation of the issue**
- **The location of the affected source code** (tag/branch/commit or direct URL)
- **Step-by-step instructions to reproduce the issue**
- **Proof-of-concept or exploit code** (if possible)
- **Impact of the issue**, including how an attacker might exploit the issue

This information will help us triage your report more quickly.

### What to Expect

- **Acknowledgment**: We will acknowledge receipt of your report within 48
  hours.
- **Initial Assessment**: We will provide an initial assessment within 7
  business days.
- **Updates**: We will keep you informed of our progress on fixing the vulnerability.
- **Resolution**: We will notify you when the vulnerability is fixed and released.

### Disclosure Policy

- We will work with you to understand and resolve the issue quickly.
- We will credit you in our security advisories (unless you prefer to remain anonymous).
- We will not take legal action against security researchers who:
  - Act in good faith
  - Do not access more data than necessary
  - Do not destroy or modify data
  - Do not violate any laws

### Security Best Practices

To help keep IARTNET secure, please:

- **Never commit secrets** to the repository (API keys, passwords, tokens, etc.)
- **Use environment variables** for sensitive configuration
- **Keep dependencies up to date** (we use Dependabot for automated updates)
- **Review pull requests** carefully before merging
- **Report vulnerabilities** responsibly

### Security Features

IARTNET implements the following security measures:

- **Automated Security Scanning**: Trivy scans for vulnerabilities in code
  and dependencies
- **Dependency Updates**: Dependabot automatically creates PRs for security
  updates
- **Code Linting**: Super-Linter ensures code quality and security best
  practices
- **Secret Scanning**: GitHub automatically scans for accidentally committed
  secrets
- **Branch Protection**: Main branches require reviews and status checks

### Known Security Considerations

- **Database**: Uses PostgreSQL with proper connection security
- **Authentication**: Laravel's built-in authentication with Filament admin panel
- **Multi-tenant**: Strict tenant isolation to prevent cross-tenant data access
- **Input Validation**: All user inputs are validated and sanitized
- **Output Encoding**: All outputs are properly encoded to prevent XSS

### Security Updates

Security updates are released as soon as possible after a vulnerability is
confirmed and fixed. We recommend:

- Keeping your dependencies up to date
- Monitoring GitHub Security Advisories
- Reviewing Dependabot pull requests promptly

---

**Thank you for helping keep IARTNET and our users safe!**
