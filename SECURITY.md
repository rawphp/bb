# Security policy

## Supported versions

Bodybuilder has no release yet. Once it does, security fixes land on the latest release and `main` only.

## Reporting a vulnerability

Do not open a public issue.

Report privately through **GitHub Security Advisories**: open the repository's Security tab and choose **Report a vulnerability**.

Include:

- the version or commit SHA;
- what the issue is and what an attacker could do with it;
- steps to reproduce, or a minimal proof of concept;
- any mitigation you know of.

You'll get an acknowledgement when a maintainer sees the report, then an assessment and a plan for a fix. Please allow time for the fix before discussing it publicly.

## Areas we care most about

The app stores health data (weight, body measurements, food intake, screening answers) and can hold an Anthropic API key and personal access tokens. Reports about these are especially welcome:

- one user reading or changing another user's data;
- a capability running without the right authorization, from the UI, the agent, MCP or the CLI;
- token leakage, or a token doing more than its scope allows;
- prompt injection that makes the agent act beyond what the user asked;
- offline outbox replay applying a write twice, or as the wrong user.
