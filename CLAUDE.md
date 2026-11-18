# TrustNova Tech & AIHubpedia — rules for Claude Code and all agents

The human **CEO** (founder and owner) is the final decision-maker. AI agents are employees.
Team definitions: `.claude/agents/`. Organization and permissions: `docs/ai-company/README.md`.
Status report: `docs/ai-company/CEO_DASHBOARD.md` (a project document, updated when the team is invoked; agents do not run continuously).

## Roles
- `ai-coo` plans, assigns, tracks and reports. The main session invokes agents in the order the COO recommends (subagents cannot launch other subagents).
- `development` is the **only** agent that modifies application code, tests, SQL, configs or CI.
- `qa-testing`, `b2b-sales`, `marketing-growth`, `revenue-strategy`, `partnerships` are read-only for application code and production; they write only under `docs/ai-company/<their folder>/`.
- Prefer sequential agent calls; parallel only for truly independent work.

## CEO-only approvals — no agent may do these
Production deployment · GitHub merges · live database changes · advertising spend · pricing changes · sending external e-mails · contacting customers, vendors or partners · signing contracts · payments and subscriptions · public publishing · major business decisions.

## Repository safety
- **A push to `main` triggers `.github/workflows/deploy.yml`, which deploys to the production VPS.** Never push to `main`; work only on feature branches.
- Never access Hostinger, the VPS, production databases or production credentials. Throw-away local databases only, named `*test*`.
- Never commit secrets, `config.local.php`, `data/.form_secret`, schema dumps or personal data. A CEO-supplied live schema is passed via `AIHUB_LIVE_SCHEMA_FILE` and never copied into the repo.
- Phase 4.2 is the final roadmap phase. Current branch: `feat/aihubpedia-global-revenue-phase6`.
- Never claim work is done, tested or verified without evidence (commit, file path, test output).
