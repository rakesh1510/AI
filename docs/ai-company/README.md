# TrustNova Tech & AIHubpedia — AI company team

Reusable Claude Code project subagents (`.claude/agents/`). The CEO (human founder and owner) decides; agents are employees.
Agents run **only when invoked** in a Claude Code session. They are not background processes.

## Organization

```
                         CEO  (human — final decision-maker)
                          │
                       ai-coo  (plans · assigns · tracks · reports)
                          │
   ┌──────────────┬───────┴──────┬───────────────┬──────────────────┬──────────────┐
development    qa-testing     b2b-sales   marketing-growth   revenue-strategy   partnerships
 (builds)      (verifies)    (acquires)      (grows)          (profitability)    (expands)
   ▲               │
   └── bug reports ┘
```

How work flows: CEO goal → `ai-coo` writes assignments in `BACKLOG.md` → the main session invokes each agent with that assignment → agent delivers a file or commit → `qa-testing` verifies code → `ai-coo` updates `CEO_DASHBOARD.md` → CEO approves or redirects. Subagents cannot call each other, so the main session does the hand-offs.

## Permissions

| Agent | Model | Tools | Writes to | App code | Web research | Git push |
|---|---|---|---|---|---|---|
| `ai-coo` | sonnet | Read, Glob, Grep, Write, Edit, Bash (read-only use) | `docs/ai-company/` | No | No | No |
| `development` | opus | Read, Write, Edit, Glob, Grep, Bash | whole repo | **Yes (only agent)** | No | Feature branches only |
| `qa-testing` | sonnet | Read, Glob, Grep, Bash (tests only), Write | `docs/ai-company/qa/` | No | No | No |
| `b2b-sales` | sonnet | Read, Glob, Grep, Write, WebSearch, WebFetch | `docs/ai-company/sales/` | No | Yes | No |
| `marketing-growth` | sonnet | Read, Glob, Grep, Write, WebSearch, WebFetch | `docs/ai-company/marketing/` | No | Yes | No |
| `revenue-strategy` | sonnet | Read, Glob, Grep, Write, WebSearch, WebFetch | `docs/ai-company/revenue/` | No | Yes | No |
| `partnerships` | sonnet | Read, Glob, Grep, Write, WebSearch, WebFetch | `docs/ai-company/partnerships/` | No | Yes | No |

Model choice: `opus` only where code correctness matters most (development); `sonnet` for everything else to keep cost down.

### How the limits are enforced
| Limit | Enforcement |
|---|---|
| Research agents cannot run commands or edit existing files | **Technical**: no Bash or Edit tool |
| No push to `main` (which auto-deploys to the VPS), no force-push, no `gh pr merge`, no workflow dispatch, no ssh/scp/sftp/rsync/ftp, no reading `config.local.php` or `data/.form_secret` | **Technical**: deny rules in `.claude/settings.json`, apply to every agent and the main session |
| Which folder an agent writes to; QA and COO do not change code; no external contact; no publishing; no price changes | **Instruction-level**: agent prompts and `CLAUDE.md`. Review diffs; QA and COO have Write/Bash, so these rely on the agents following instructions |
| Merges, deployment, live DB, spend, pricing, contracts, payments, contact, publishing | **CEO only**, performed by the CEO outside the agents |

## CEO-only approvals
Production deployment · GitHub merges · live database changes · advertising spend · pricing changes · sending external e-mails · contacting customers · signing contracts · payments and subscriptions · public publishing · major business decisions.

## How to use the team
In Claude Code, from this repository:
- "Use the ai-coo agent to plan <goal>" → assignments in `BACKLOG.md`.
- "Use the development agent to <task>" / "Use the qa-testing agent to verify <commit>".
- `/agents` lists them. Files in `.claude/agents/` are picked up when a session starts.

## Files
- `CEO_DASHBOARD.md`: status report (updated when the team is invoked)
- `BACKLOG.md`: company task backlog (owned by `ai-coo`)
- `qa/`, `sales/`, `marketing/`, `revenue/`, `partnerships/`: department deliverables
