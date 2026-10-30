# REA extraction and adaptation record — 30 October 2026

This records actual REA execution, separately from the scout's ordinary source review. No target application, downloaded skill, installer or reference backend was executed. REA parsed the selected JavaScript/TypeScript as inert source. PHP backend contracts were inspected directly in the DoughBoss source; the JavaScript analysis is not a PHP or live-provider audit.

## Selected reference backend

- Reference: `trycompai/crm`, pinned at `c9473b82e3b4bfd5d0598bbdf7c1f36f3d2d68f0`.
- Exact source: `apps/api/src/crm/activity-stamp.service.ts`.
- REA parent evidence: `ev_6a53492dbd79ce20713a98dead39ec911f36fa6840dcb3d9ce6c601848baacbd`.
- Summary evidence: `ev_d58be5b720f41293aaa786f0e9a2fc252637eb133ccefe03f1aca11e7c9af31d`.
- One source parsed, 5,761 text bytes read, 810 AST nodes visited, 15 findings and zero parse failures. The application graph remains partial; 1,066 inventoried files were outside the selected analysis scope.

Direct source inspection establishes a useful pattern: companies, contacts and deals have related activity timestamps; a newer activity advances the stamp while deletion triggers recomputation from remaining activities. Batched recomputation uses a database transaction. This informs the future activity/audit design; it is not a WordPress-compatible implementation or proof of tenant authorization, notification delivery or exactly-once processing. No NestJS/Prisma source was copied into DoughBoss.

The scout separately records the Prisma schema paths for company/contact/deal/activity relationships and the pinned MIT root licence. Each source/package/asset would still need its own compatibility and licence assessment before code reuse.

## Selected existing DoughBoss frontend

- Exact sources: `public/js/doughboss-catering.js` and `public/js/doughboss-portals.js` in the isolated `crm` checkout.
- Summary evidence: `ev_b9613b9ae98ca2853ae66a15596192ceca091c7116e3348ffcb149e477d6c5d4`.
- Two sources parsed, 44,191 text bytes read, 6,084 AST nodes visited, 25 findings and zero parse failures. The retained application and semantic graphs remain partial; 258 inventoried files were not selected. Exhaustion of this summary view does not make the whole application analyzed.

Direct source inspection alongside REA confirms the current public catering JavaScript uses the existing REST base and nonce boundary, loads public catalogue data, and posts the enquiry through `/catering/enquiry`. Its payment-intent/confirmation paths are separate from merely submitting an enquiry. The existing portal script only supplies clock/full-screen presentation; it is not a CRM backend.

The first CRM slice therefore reads the canonical saved catering rows behind the existing manager boundary and adds server-rendered GET filters. It does not create another enquiry endpoint, accept browser-reported prices, invoke payment preparation, or add a second frontend runtime. This is the relevant adaptation for the current caller.

## Preserved evidence and limits

`evidence/CRM-REA-REFERENCE-BACKEND-20261030.json` and `evidence/CRM-REA-FRONTEND-20261030.json` preserve the structured REA summary Evidence, source selections, artifact digests, coverage and limitations. The artifact digests identify each analysis-time inventory, not a final release-tree hash after later documentation or build outputs.

Static imports, request candidates, local call relationships and data flow do not prove live execution, reachability, deployed routes, provider acceptance or a complete backend. Unselected dependencies and sources remain unknown. No runtime instrumentation, authenticated session capture, customer data, bot token or Telegram payload was included.

The downloaded REA source is pinned at `cce33142ede98d39c0ce289931700c8c7a0d9cdc`; that pin is reference-source provenance, not a claim about the installed MCP provider's package version. The exact provider/operation and Evidence identifiers are retained in the JSON receipts. No new REA daemon, plugin, configuration or server was installed or activated.
