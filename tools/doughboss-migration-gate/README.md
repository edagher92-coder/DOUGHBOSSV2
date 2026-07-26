# DoughBoss Migration Gate

Temporary WordPress protection used only during the controlled live migration.

- Logged-out visitors receive a cache-safe `503 Service Unavailable` page.
- Unauthenticated `/wp-json/doughboss/v1/*` requests receive a JSON `503`.
- Logged-in staff and administrators can test the real WordPress experience.
- The gate stores no settings and is removed by deactivating the plugin.

Do not leave this plugin active after the final public go-live approval.
