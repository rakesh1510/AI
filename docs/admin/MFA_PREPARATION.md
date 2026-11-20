# Optional two-factor sign-in: preparation (not enabled)

Status: **prepared, not implemented, not enabled.** Nothing here changes how anyone signs in. Implementation needs the owner's separate approval (decision D6) and a recovery process, so that no administrator can be locked out.

## Design (for approval)
- **Method:** time-based one-time codes (TOTP, RFC 6238) from an authenticator app. No SMS, no email codes.
- **Scope:** optional per account at first; the owner decides whether it becomes mandatory for the owner role.
- **Storage:** one additive table `crm_user_mfa` (user id, encrypted secret, enabled flag, created time, last used step) and one for recovery codes (hashed, single use). No change to `crm_users`. The migration would be written and reviewed, never run without approval.
- **Secret handling:** secrets encrypted with a server key from the environment (not in Git); shown once as a QR code and text; never logged.
- **Sign-in flow:** password first (existing checks, rate limit and audit), then the code; five wrong codes lock the attempt for 15 minutes; a used code step cannot be reused.
- **Recovery:** ten single-use recovery codes shown once; an administrator can reset an account's second factor from the command line (`scripts/crm_user.php`) after identity checks; every reset is audited.
- **Account page:** the "Two-factor sign-in" panel already exists as a placeholder (status: not enabled).
- **Safeguards against lock-out:** never enforce for an account without confirmed enrolment and unused recovery codes; keep the command-line reset; test enrolment on staging with a second administrator present.

## Not decided yet
Mandatory versus optional for the owner; recovery-code count; whether to support hardware keys (WebAuthn) later; session behaviour after enrolment.
