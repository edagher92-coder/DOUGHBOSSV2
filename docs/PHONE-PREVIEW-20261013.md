# Read-only phone preview snapshot

This publication updates only the separate GitHub Pages visual demo. It is not a WordPress release, Square integration deployment, or approval to accept orders or payments.

The five-page static export was built from the canonical development checkout at base commit d35264f6606ef3759ab8a7656a7d4c53f7311ee4 plus its ongoing uncommitted visual work. The manifest records the exact source file hashes; the base commit alone does not reproduce that working tree.

Before copying, scripts/validate-visual-preview.js passed against the canonical source and export. The snapshot validator pins manifest SHA256 a65648ea334d0f44e4c993f24499a85f403ae529af2dcdf41ae3f541e3fb0a6d and verifies every published output hash, page/resource allowlist, local assets, restrictive CSP, synthetic shop fixtures, and no-network/no-payment boundaries. It does not claim that this older publication branch contains the canonical source.

The existing source, archive, and original preview checks remain unchanged. The additional snapshot gate selects phone-preview as the only Pages artifact.

Pages: index.html, order.html, paused.html, partners.html, wholesale.html. Data is illustrative. Express and private records are excluded. No staff/customer data or credentials are included.
