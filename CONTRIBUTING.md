# Contributing to Views Reference Sift

<!-- cspell:words ddev docroot packagist phpcbf -->

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

To switch the local project to Drupal 12, set `php_version: "8.5"` in
`.ddev/config.yaml` and run these commands.

```sh
ddev restart
ddev core-version '^12'
ddev poser
```

Drupal 12 tests also need Views Reference's attribute discovery fix. The
[CI workflow](.github/workflows/ci.yml) shows the patch used for those tests.

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
4. Verify the tagged version appears on Packagist once the package is
   registered.
   [Packagist package](https://packagist.org/packages/fourkitchens/viewsreference_sift)
5. Update the `sous-content-list` dependency when it needs the new release.
