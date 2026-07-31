# DoughBoss kitchen touch-screen deployment

This deployment uses one KAMRUI mini PC and two 21.5-inch 1920x1080 touch
monitors as one extended Windows workstation.

## Screen layout

| Physical screen | Browser mode | Purpose |
| --- | --- | --- |
| Left | `screen=make` | New orders, prep and oven flow. |
| Right | `screen=pass` | Ready-to-call orders, collection and pre-order review. |

The screens do not duplicate each other. When a kitchen staff member taps
**Send to pass**, the ticket leaves the MAKE screen and appears on the PASS &
PICKUP screen. When the pass staff member taps **Collected**, the order is
completed through the normal version-checked DoughBoss status transition and
leaves the active kitchen queue.

## Hardware connection

1. Connect monitor one to the mini PC HDMI output.
2. Connect monitor two with a DisplayPort-to-HDMI or USB-C-to-HDMI adapter.
   The mini PC has one HDMI, one DisplayPort and one USB-C display output, so
   two HDMI cables alone cannot connect both monitors.
3. Connect each monitor's USB touch lead to the mini PC. HDMI carries the
   image only; the USB lead enables touch.
4. Prefer wired Ethernet for the mini PC. Use Wi-Fi only as the fallback.
5. In **Windows Settings > System > Display**, select **Extend these
   displays**, arrange the monitors left-to-right, and set each to 1920x1080.
6. In **Control Panel > Tablet PC Settings**, use **Setup** to associate touch
   input with the correct physical monitor.

## First sign-in and daily launch

1. Sign in to Chrome with the low-privilege DoughBoss Kitchen account.
2. If the owner has enabled the optional Board access key, use the normal
   approved board bookmark; do not save the key in a shared document.
3. Run `scripts/start-kitchen-screens.ps1`.
4. The left monitor opens MAKE; the right opens PASS & PICKUP in kiosk mode.
5. Tap **Enable sound alerts** once on the MAKE screen at the start of the
   shift. Browser audio requires a real staff gesture before it can play.

## Touch and safety rules

- All operating controls are at least 48px, with 58px controls in MAKE and
  PASS mode. No critical action requires hover or a mouse.
- The kitchen board cannot alter prices, take payments or issue refunds.
- A live request carries a version check. A duplicate/late tap cannot silently
  overwrite a later order state.
- If the board becomes offline, it visibly locks actions rather than accepting
  changes it cannot confirm.
