# Release

Current stable target: **v1.10.1**.

## Checklist

1. `make release-check` (Cursor trailer check, open PRs, composer-sync, cs-fix, cs-check, rector-dry, phpstan, validate-translations, test-coverage, demos when available).
2. Update [CHANGELOG.md](CHANGELOG.md) with user-facing notes (Keep a Changelog + SemVer).
3. Update [UPGRADING.md](UPGRADING.md) when there are migration steps or requirement changes.
4. Commit the release on the default branch (`main`).
5. Before pushing, run `make check-no-cursor-coauthor` (REQ-GIT-001).
6. Tag the commit `vX.Y.Z`.
7. Push the branch and the tag to `git@github.com:nowo-tech/SiteBackupBundle.git` — `.github/workflows/release.yml` creates the GitHub Release from the tag + changelog entry.
8. Confirm [Packagist](https://packagist.org/packages/nowo-tech/site-backup-bundle) picks up the tag (submit the GitHub repo once if the package is new).


## Example: v1.10.1

```bash
make release-check
git add docs/CHANGELOG.md docs/UPGRADING.md docs/RELEASE.md
git commit -m "Release v1.10.1: SECURITY firewall docs and REQ-GIT-001 history hygiene."
git tag -a v1.10.1 -m "Release v1.10.1"
git push origin main
git push origin v1.10.1
```

## Example: v1.9.0

```bash
# 1. Run pre-release checks
make release-check

# 2. Commit release docs and feature
git add -A
git commit -m "chore(release): prepare 1.9.0"

# 3. Create and push tag (triggers GitHub Release via Actions)
git tag -a v1.9.0 -m "Release v1.9.0 - REQ-UI-002 panel access_roles / access_checker"
git push origin main
git push origin v1.9.0
```

## Example: v1.8.1

```bash
git tag -a v1.8.1 -m "Release v1.8.1 - demo showcases layouts, locale, detectors, custom tabs"
git push origin main
git push origin v1.8.1
```

## Versioning

Follow semantic versioning. Breaking changes to config keys, route names, or public interfaces require a major bump and an [UPGRADING.md](UPGRADING.md) section.
