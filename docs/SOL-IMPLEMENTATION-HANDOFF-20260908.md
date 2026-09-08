# DoughBoss 2.42.0 implementation handoff

Read `REVIEW-20260908.md` first. This document is the concise continuation contract for the completed local batch.

**Follow-on checkpoint:** `4909823` is now frozen on `codex/full-system-review-20260908`, pushed in draft PR #66 with successful Actions run 34216140907. The current checkout is on `codex/storefront-completion-20260908`, implementing plugin 2.43.0 / theme 1.6.0; read `STOREFRONT-COMPLETION-20260908.md` before continuing. Do not mistake this historical 2.42.0 artifact section for the current working tree.

## Authoritative state

- Checkout: `C:\Codex\.codex\worktrees\5bdb\DOUGHBOSSV2-review-20260908`
- Branch: `codex/full-system-review-20260908`
- Baseline: `76deb569ceb1665d766f4c87659d40b7cc4f54b5`
- Plugin/theme versions: 2.42.0 / 1.5.0
- Canonical plugin ZIP: `dist/doughboss-2.42.0.zip`
- ZIP SHA-256: `C5236B472D4510EFBA92DE3778BB7CFB55BDBCA4112CF751F06E10C1168CCCD1`
- Paired theme ZIP: `dist/doughboss-final-1.5.0.zip`
- Theme ZIP SHA-256: `7DD351E309BDBCF67A8F3A9F06B9FED779F910E3DC8A08A3C33031774AAD46DE`

The local implementation is complete for this batch. Astra accepted the repaired payment architecture and the final independent Sol reviewer returned `ship` for the exact local seam. Final gates: 121/121 unit assertions, 6/6 JavaScript helper assertions, 91/91 WordPress/MySQL assertions, one provider POST in the observed two-process mocked race, exact 137-file plugin archive validation and exact 25-file theme archive validation.

## Safety boundary

- Leave production ordering, online payments and POSPal settings unchanged.
- Do not create a real order, payment, voucher claim, customer message or provider mutation.
- Preserve quarantine, rollback content and inactive duplicate plugins.
- Never retrieve, print, commit or send stored credentials.
- Treat provider sandbox, GitHub Actions, push/PR, deployment and Drive upload as separate remote actions.
- Do not retry the historical Drive folder write until read-only metadata confirms an accessible intended folder.

## Remaining gates

1. Resolve real POSPal mappings or a staffed fallback for custom pizza builder names.
2. Run one approved Square sandbox acceptance session using synthetic customer data, including success, decline, unknown/pending, signed webhook replay and refund/reconciliation behavior.
3. Baseline delivery completed: one branch push, draft PR #66 and one automatic CI cycle passed. Keep later storefront work consolidated in its own locally verified batch.
4. Publish the paired plugin/theme release only after CI and rollback checks, keeping online payments off.
5. Verify served versions/config/menu/location read-only, then upload a credential-safe full-folder backup and production ZIP to the confirmed shared Drive folder.
6. Complete the SamOS read-only site/location adapter in its own repository and task; do not duplicate its business topology here.

## Completion language

Report local, committed, pushed, CI-validated, published, operationally accepted and remotely backed-up states separately. Do not call Square, POS/KDS, SamOS or production publication complete from local tests alone.
