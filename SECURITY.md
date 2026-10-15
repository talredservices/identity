# Security policy

## Reporting a vulnerability

Please report suspected vulnerabilities privately through [GitHub's vulnerability reporting form](https://github.com/talredservices/identity/security/advisories/new). Include the affected endpoint or component, reproduction steps, impact, and any suggested mitigation.

Do not disclose the issue publicly until a fix is available. Avoid including real credentials, access tokens, refresh tokens, client secrets, or personal data in a report; use redacted or disposable test values.

## Supported versions

Security fixes are applied to the latest code on `main` until the project begins publishing versioned releases. Deployments should follow `main` only after the CI checks pass and should rotate any credential suspected of exposure.
