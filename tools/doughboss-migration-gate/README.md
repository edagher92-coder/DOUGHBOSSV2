# DoughBoss Migration Gate

Temporary WordPress protection used only during the controlled live migration.

- Logged-out visitors receive a cache-safe `503 Service Unavailable` page.
- Unauthenticated `/wp-json/doughboss/v1/*` requests receive a JSON `503`, except exact payment-provider callbacks that continue to their own signature/HMAC verification handlers.
- Logged-in staff and administrators can test the real WordPress experience.
- The exact hidden `/staff-clock/` landing remains available so the shared kiosk can show sign-in and post-action confirmation; all attendance data and mutations still require the DoughBoss plugin's account, capability, nonce and schema checks.
- The gate stores no settings and is removed by deactivating the plugin.

Do not leave this plugin active after the final public go-live approval.
