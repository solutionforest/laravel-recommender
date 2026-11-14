# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.10.x | ✅ |
| < 0.10 | ❌ |

## Reporting a vulnerability

Please do **not** open a public issue. Report it privately through [GitHub security advisories](https://github.com/solutionforest/laravel-recommender/security/advisories/new). We will confirm receipt, investigate and publish a fix and advisory as soon as possible.

## Automated checks

On every pull request, on `main` and weekly ([security workflow](.github/workflows/security.yml)):

- **`composer audit`** of the dependencies an app installs with the package, for each supported Laravel version. Laravel 10 is end of life and has known advisories, so it is reported but does not fail the build.
- **Psalm taint analysis** of `src/`: request and other user input flowing into SQL, HTML, shell commands or file paths. Any `Tainted*` finding fails the build.

On the repository: Dependabot alerts and security updates, Dependabot updates for the CI actions, and secret scanning with push protection.
