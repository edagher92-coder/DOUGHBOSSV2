# DoughBoss kitchen display setup

## Confirmed primary screen

The Lenovo ThinkVision T24t-20 is the primary kitchen MAKE display. It is a
23.8-inch, 1920x1080, anti-glare IPS monitor with 10-point capacitive touch.
The DoughBoss kitchen workspace is tuned for this native Full HD canvas with
large touch controls, three independently scrolling production lanes, visible
payment and allergy warnings, sound status, connection status and full-screen
operation.

Official Lenovo references:

- https://support.lenovo.com/us/en/solutions/pd500506-thinkvision-t24t-20-monitor-overview
- https://psref.lenovo.com/syspool/Sys/PDF/ThinkVision/ThinkVision_T24t_20/ThinkVision_T24t_20_Spec.PDF

## Cables

Preferred one-cable setup, when the mini PC USB-C port supports DisplayPort Alt
Mode: connect the monitor's USB-C upstream port to the PC with a full-featured
USB-C to USB-C cable. This carries video, touch/data and the monitor's USB hub.

If video is connected by HDMI or DisplayPort, add a USB data cable for touch:
connect the monitor's USB-C upstream port to a USB-A port on the PC with a
USB-C to USB-A data cable. A charge-only cable will not activate touch. Lenovo
lists USB-C to USB-C with the product and lists USB-C to USB-A in some regional
packages, so check the box contents before buying another cable.

The smaller catering touch display needs its own video connection and its own
USB touch/data connection. Connect the mini PC to Ethernet for the most stable
order feed; Wi-Fi can remain the fallback.

## Windows display setup

1. Set **Extend these displays**, not Duplicate.
2. Make the Lenovo the primary screen, landscape, 1920x1080, 100% scaling.
3. Put the smaller catering screen to the right in Windows Display Settings.
4. Search Windows for **Calibrate the screen for pen or touch input**, choose
   **Setup**, and tap the requested screen so Windows maps each touch panel to
   the correct display.
5. Disable display sleep, automatic tablet rotation and disruptive restart
   prompts during trading hours.
6. Keep Chrome updated and allow sound for doughboss.com.au.

## Protected workspaces

- Main kitchen: https://doughboss.com.au/kitchen/?screen=make
- Pass and pickup: https://doughboss.com.au/kitchen/?screen=pass
- Catering production: https://doughboss.com.au/catering-kitchen/
- Owner and manager overview: https://doughboss.com.au/management/

These are not public boards. They use WordPress authentication, DoughBoss role
capabilities, assigned-shop scope, REST nonces and the optional verified board
link key. The screens are no-index and cannot be framed by another website.
They are intentionally absent from the customer navigation. Existing devices
bookmarked to `https://doughboss.com.au/kitchen/?screen=catering` remain
compatible, but new catering devices should use the dedicated URL above.

Create a dedicated WordPress kitchen user named **DoughBoss** with the
DoughBoss Kitchen role and assign it only to the correct shop. Generate a new,
unique password in a password manager. Do not use `doughboss13` or any password
that has appeared in a message or screenshot. Use a separate manager account
for `/management/`.

## Automatic startup

After signing the kitchen account into Chrome once, run
`scripts/start-kitchen-screens.ps1`. It opens MAKE full-screen on the 1920x1080
primary monitor and CATERING full-screen on the smaller right-hand monitor. The
script stores no username, password or board key.

Before launch, confirm an online test order appears on MAKE, moves to the next
lane with one touch, sounds once, remains visible after a refresh and reaches
the customer tracker. Then repeat with a committed catering job on the smaller
screen.
