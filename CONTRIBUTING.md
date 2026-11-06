# Contributing to Views Reference Sift

<!-- cspell:words ddev docroot phpcbf -->

## Set up local development

Install Docker and DDEV, then run these commands from the module checkout.
[DDEV installation](https://docs.ddev.com/en/stable/users/install/ddev-installation/)

```sh
ddev config --project-type=drupal11 --docroot=web --php-version=8.3
ddev add-on get ddev/ddev-drupal-contrib
ddev start
ddev poser
ddev symlink-project
```

## Experimental Drupal 12 testing

Use PHP 8.5 with the SQLite PDO extension and a disposable copy of this
checkout. Follow the `drupal-12 (pending upstream)` job's dependency, patch,
and test steps. Set `GITHUB_WORKSPACE` to the disposable checkout and
`RUNNER_TEMP` to a writable temporary directory. Use `EXPERIMENTAL=true`,
`DRUPAL_VERSION='^12.0@beta'`, and `VIEWSREFERENCE_VERSION='*'`. The job
widens Sift's core requirements for testing and installs Drupal core from
source to include its test bootstrap. Use `RUNNER_TEMP` in place of
`${{ runner.temp }}` for the test paths and SQLite database.
[CI workflow](.github/workflows/ci.yml)

The job applies patches for Views Reference setting discovery and a PHP 8.5
formatter warning. Drupal 12 support depends on the upstream discovery fix.
[Views Reference issue #3583101](https://www.drupal.org/project/viewsreference/issues/3583101)

## Run checks

Run the coding standards and static analysis checks from the module checkout.

```sh
ddev phpcs
ddev phpstan
```

Use `ddev phpcbf` to fix coding standards violations that can be corrected
automatically.

Install the Selenium add-on recommended by DDEV Drupal Contrib to run the
FunctionalJavascript tests.
[DDEV Selenium Standalone Chrome](https://github.com/ddev/ddev-selenium-standalone-chrome)

```sh
ddev add-on get ddev/ddev-selenium-standalone-chrome
ddev restart
ddev phpunit
```

## Open a pull request

1. Branch from `1.0.x` and target `1.0.x` in the pull request.
2. Use [Conventional Commits](https://www.conventionalcommits.org/) for commit
   messages.
3. Add user-facing changes under **Unreleased** in [CHANGELOG.md](CHANGELOG.md).
4. Fill out the [pull request template](.github/pull_request_template.md) and
   wait for the required CI checks to pass before merging.

## Release a version

1. Rename the **Unreleased** changelog section to the version and date, add a
   fresh **Unreleased** heading above it, and update the compare links.
   Open and merge the release pull request.
2. Update the local `1.0.x` branch. Replace `VERSION` with the release version
   in `git tag -a VERSION -m VERSION` and `git push origin VERSION`.
3. Publish a GitHub release using the version's changelog section as its
   notes. Mark beta and release candidate versions as pre-releases.
4. Push the same tag to the Drupal.org Git repository. Use **Add new release**
   on the project page, select the tag, and reuse the GitHub release notes.
   [Drupal.org project page](https://www.drupal.org/project/viewsreference_sift)
5. Update the `sous-content-list` dependency when it needs the new release.
