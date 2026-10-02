=== DoughBoss Growth ===
Contributors: doughboss
Tags: consent, attribution, landing pages, waitlist
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Companion to the DoughBoss plugin. Every feature ships switched off; installing it changes nothing on the public site.

== Description ==

DoughBoss Growth is a small companion plugin for the DoughBoss plugin. It needs DoughBoss 2.41.0 or later and does nothing (apart from an admin notice) when DoughBoss is missing or older.

It never writes to DoughBoss tables, options, post types, roles or capabilities. It keeps its own records in its own `doughboss_growth_*` tables and options, and it reads DoughBoss data through public APIs only.

Features (all off by default, each enabled separately under DoughBoss, Growth):

* Consent banner and tag loader.
* First-party attribution capture.
* Server-side conversions.
* Landing pages and search metadata.
* Lead form and party-pack sizer.
* Coming-soon section and VIP waitlist.
* Timesheet reconciliation.

That is eleven switches in all. Each one is off until you turn it on, and some need a prerequisite first (for example Tag Manager needs the consent banner, and the waitlist needs the sender legal name and the privacy-policy URL).

Safety switches:

* Turn a feature off in the Growth settings screen; the public site returns to its previous output immediately.
* Define `DOUGHBOSS_GROWTH_DISABLE` as true in wp-config.php to stop the whole companion without deactivating it.
* Deactivating the companion moves any landing page it created back to draft so no raw shortcode text is ever shown.
* Deleting the plugin removes nothing unless `DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA` is defined as true in wp-config.php.

Secrets (API secrets, tokens) are read from environment variables or wp-config.php constants only and are never stored in the database, printed or logged.

== Installation ==

1. Upload the zip under Plugins, Add New, Upload Plugin, then activate.
2. Open DoughBoss, Growth. Nothing is enabled until you enable it there.

== Changelog ==

= 0.1.0 =
* First release candidate. Eleven features, every one switched off after install, each enabled separately under DoughBoss, Growth: consent banner and Tag Manager loader, first-party attribution, server-side conversions (GA4 and Meta, plus an offline Google Ads export), landing pages and search metadata, corporate lead form, party-pack sizer, neutral coming-soon section, VIP waitlist (double opt-in, opt-out, privacy exporter and eraser) and a read-only timesheet reconciliation report.
* Foundations: settings and flags with dependency checks, core gate (DoughBoss 2.41.0 or later), module registry, fail-closed rate limiter, shared outbox with retries, HTTP wrapper with log redaction, claims ledger with a public-copy lint, health endpoint, safe deactivation (landing pages go back to draft) and an uninstall that deletes nothing unless `DOUGHBOSS_GROWTH_UNINSTALL_DELETE_DATA` is set.
* Nothing is sent to Google, Meta, Square or anyone else until the owner supplies the account ids and secrets and switches the matching feature on.
