# Production deployment protection

This change replaces the push-to-`main` auto-deploy with a manual workflow. Merging this PR does not deploy (the workflow now only runs on `workflow_dispatch`). **It changes no repository setting, secret or server.** Items below must be done by the repository owner.

## What changed in `.github/workflows/deploy.yml`
| Before | After |
|---|---|
| Deploys on every push to `main` | Manual only; needs a 40-character SHA that is on `main`, a typed `DEPLOY`, and a successful `ci.yml` run for that SHA |
| No approval | Job uses the `production` environment: pauses for required reviewers |
| `debug: true`, third-party SSH action, secrets in every run | No debug output, plain `ssh` with a pinned host key, secrets only in the approved `deploy` job, inputs passed through env vars (no script injection) |
| `git reset --hard origin/main` (silently destroys server edits) | Aborts if the server working tree is dirty; deploys the exact SHA detached |
| No backup, check or rollback | Mandatory backup script, health check, file rollback to the previous SHA |

Database changes are never applied by this workflow and are not undone by the file rollback; apply migrations manually after a backup.

## One-time setup (owner only, nothing is automatic)
1. Settings → Environments → create `production`: Required reviewers = owner, deployment branches = `main`.
2. Move `VPS_HOST`, `VPS_USER`, `VPS_PORT`, `VPS_SSH_KEY` from repository secrets to `production` environment secrets; add `VPS_HOST_KEY` (the server's public host key line, taken from the server) and the variable `PROD_HEALTH_URL`. Delete the repository-level copies.
3. Rotate the SSH key (new key pair, restricted user without sudo) and any credential ever pasted into chat.
4. Settings → Rules → ruleset on `main`: pull request required, 1 approval, `CI` status checks (both MySQL versions) required, no force-push or deletion.
5. Server admin installs `/usr/local/bin/aihub-predeploy-backup` (database dump plus files). Until it exists the workflow aborts safely.
6. The server checkout becomes detached HEAD after the first deploy; do not run manual `git pull` there.

## Verifying before first use
Read-only checks: the workflow YAML parses; run it first against a staging copy of this workflow (separate environment and host) before production.
