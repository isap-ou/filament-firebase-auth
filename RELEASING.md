# Releasing

This file gives the release rules for `isapp/filament-firebase-auth`.
It is for maintainers.

## Version rule

**The plugin major version equals the Filament major version.**

- Filament 5 uses plugin `5.x`. The first release is `v5.0.0`.
- After `v5.0.0`, releases are `v5.MINOR.PATCH`. Inside the major, we follow [SemVer v2.0.0](https://semver.org/):
  - a minor release adds features and stays backward compatible,
  - a patch release fixes bugs and stays backward compatible.
- We do not make breaking changes inside a Filament major. A breaking change waits for the next Filament major.
- Each plugin major requires exactly one Filament major. Today `composer.json` requires `filament/filament: ^5.0`.
  Never widen the constraint to two majors, for example `^5.0|^6.0`.
- Tags start with `v`, for example `v5.0.0`, `v5.1.0`, `v5.1.1`.

## Branches

- `main` develops the newest Filament major.
- `composer.json` has `extra.branch-alias` `"dev-main": "5.x-dev"`. Users can require `5.x-dev` to get `main`.

When Filament 6 ships:

1. Cut a `5.x` maintenance branch from `main`: `git switch -c 5.x main`, then push `5.x`.
2. On `5.x`, remove `extra.branch-alias`. Composer shows the branch as `5.x-dev` without an alias.
3. On `main`, set `extra.branch-alias` to `"dev-main": "6.x-dev"`.
4. On `main`, change the requirement to `filament/filament: ^6.0`. Remove the Filament 5 support.
5. On `main`, add a "To 6.0 (Filament 6)" section to `UPGRADING.md`.
6. Release `v6.0.0` from `main`. Release later `5.x` fixes from the `5.x` branch as `v5.MINOR.PATCH`.

## Pre-release checklist

Do these steps on the commit that you will tag:

- [ ] All workflows are green: `tests`, `phpstan`, `assets` and `emulator`.
- [ ] The `fix-code-style` workflow made no new commit.
- [ ] The output of `npm run build` is committed in `resources/dist`. The `assets` workflow checks this.
- [ ] In `CHANGELOG.md`, move the content of `## [Unreleased]` under a new heading `## vX.Y.Z - YYYY-MM-DD`.
      Keep an empty `## [Unreleased]` heading above it.
- [ ] For a new major version, `UPGRADING.md` has a section for this major.
- [ ] `composer.json` requires exactly one Filament major.
- [ ] `composer validate --strict` passes.

## Release

1. Create a signed tag on the release commit: `git tag -s vX.Y.Z -m "vX.Y.Z"`.
2. Push the tag: `git push origin vX.Y.Z`.
3. On GitHub, create a release from the tag. Use the tag name as the release title, for example `v5.0.0`.
   Paste the `CHANGELOG.md` section of this version as the release notes.
4. Publish the release. This starts the `update-changelog` workflow.
   - If `CHANGELOG.md` already has a heading that starts with the release title, the workflow makes no change.
   - If the heading is missing, the workflow adds the release notes under a new heading and commits `CHANGELOG.md` to `main`.
5. Packagist gets the new tag through the GitHub webhook. Check that the version shows on
   https://packagist.org/packages/isapp/filament-firebase-auth.
6. Update the plugin page in the Filament author dashboard (https://filamentphp.com/author).
   Check the supported Filament version, the description and the images.

## Packagist webhook

Packagist must know about each push and each tag.
Connect the Packagist account to GitHub, or add the Packagist webhook to the repository settings.
Packagist shows a warning on the package page when the webhook does not work.

## Recommended GitHub settings

The files in this repository cannot set these options. Set them by hand in the repository settings.

- **Required status checks on `main`.** Add a branch protection rule or a ruleset for `main`.
  Require the jobs of the `tests`, `phpstan` and `assets` workflows.
  GitHub lists each matrix job by its name, for example `P8.4 - L13.* - prefer-stable - ubuntu-latest` for `tests`,
  `P8.4 - L13.* - prefer-stable` for `phpstan`, and `assets` for `assets`. Select all of them.
  Add the `emulator` job when the emulator tests are in place.
  Required checks block a direct push of a commit that has no green checks.
  Merge through a pull request, or let the maintainers bypass the rule.
- **Signed tags.** Sign each release tag with `git tag -s`.
  Add a tag ruleset for `v*` that blocks the update and the deletion of release tags.
- **Discussions.** Turn on Discussions with the categories "Q&A" and "Ideas".
  The issue template links to these categories.
- **Private vulnerability reporting.** Turn it on, so that reporters can also use GitHub to report security issues in private.
