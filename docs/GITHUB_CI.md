# GitHub CI notes

## Workflows

- `.github/workflows/ci.yml` — quality + tests on PRs / pushes
- Dependabot: `.github/dependabot.yml`

## REQ-GIT-001 (no Cursor co-author trailers)

CI and `make release-check` fail if commit history contains Cursor co-author trailers.

The `git-hygiene` job clones with `fetch-depth: 0` so the checker sees full history.

```bash
make check-no-cursor-coauthor
make strip-cursor-coauthor-from-history   # rewrite local history if needed
```

Prefer `git push --force-with-lease` after a rewrite. Install hooks once: `make setup-hooks`.

## Local parity

```bash
make release-check
```
