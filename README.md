# DoughBoss plugin — download

**`doughboss-2.12.0.zip`** is the installable WordPress plugin.

## Install / update
1. WordPress admin → **Plugins → Add New → Upload Plugin**
2. Choose `doughboss-2.12.0.zip` → **Install Now** (if updating, deactivate the old DoughBoss first, or just upload and "Replace current with uploaded").
3. **Activate.**

> It is a WordPress plugin — you upload it in wp-admin, you don't double-click it to "open" an app.

## After install — match the live menu to the boards
Run once (WP-CLI):
```
wp doughboss seed-menu          # creates/updates Manoush, Pizza, Pies, Wraps, Desserts, Drinks
wp doughboss seed-menu --dry-run   # preview, writes nothing
```
