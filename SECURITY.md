# Security policy

## Supported versions

Security fixes target the latest `1.0.x` release. If you use a beta or release
candidate, upgrade to the latest stable release when it is available.

## Report a vulnerability

Report Sift vulnerabilities through GitHub's private reporting form. Never open
a public issue for a vulnerability. [Report a vulnerability](https://github.com/fourkitchens/viewsreference_sift/security/advisories/new).

Include the affected Sift version, steps to reproduce the issue, and its
security impact. We aim to acknowledge reports within
[CONFIRM: 5 business days].

For vulnerabilities in Drupal core or Views Reference, follow Drupal.org's
confidential reporting process. [Drupal Security Team reporting instructions](https://www.drupal.org/docs/develop/issues/issue-procedures-and-etiquette/reporting-a-security-issue).

## Coordinated disclosure

We develop and review the fix in a temporary private fork attached to a draft
GitHub security advisory. We release the fix, then publish the advisory.

Packagist imports advisories from the GitHub Advisory Database. The
`composer audit` command checks Packagist for advisories by default.
