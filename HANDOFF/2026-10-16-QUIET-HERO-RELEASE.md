# Quiet hero release — 16 October 2026

Owner approved the exact v3 motion-candidate visuals, the separate quieter master, Mute/Unmute beside Replay, and publication. Sol 6.1 implemented the two-file controller/test change; root integrated the media, inspected the diff and reran checks. This release concerns homepage presentation only.

## Published artifacts

- Public media: https://doughboss.com.au/wp-content/uploads/doughboss-commercial-v3-motion-subtle-audio.mp4
- Size: 10,410,982 bytes. Public content matches local SHA-256 `41F306A81AE4709A876E5F341A836E6DAB344DB858855B2CDBC9B0F8320D4414`.
- Uploaded through cPanel without overwriting old media. Both viewport sources use this approved portrait cut; desktop square framing contains the full image.
- Updated active WPCode snippet **598 once** after staged clipboard comparison. Persisted editor source matches the candidate after PHP-tag/EOL normalization. Anonymous homepage HTTP 200 carries the exact candidate controller, new sound control and media URL.
- No core plugin, theme, schema, Square account or payment activation changed. Franchising-pause snippet 601 remains intact. Live ordering-paused notice remains visible; independent current payment-setting readback was not established by this presentation test.

## Verification

- Original and quieter file video packet SHA-256 are identical: `2ff0c9b8e0ab464fd60fc4917d710906e561549967f8dfaaaabcf3eb8cdd1c1a`. 1080x1920, 30fps, 300 frames; AAC container duration 10.005 seconds. Full FFmpeg decode exited 0.
- Audio: -36.08 LUFS / -16.91 dBTP versus original -23.45 LUFS / -2.71 dBTP. Light denoising, filtering, compression and edge fades.
- `node tests/frontend-upgrade.test.js`: **33 passed, 0 failed**, exit 0. PHP 8.2 and 7.4 syntax checks passed. Earlier combined-suite evidence in the consolidation handoff remains unchanged outside this narrow delta and was not rerun as a new full gate.
- Fresh read-only Astra gate: **ship, scoped implementation assurance 9.0/10**, no blocking findings. Reviewed snippet SHA-256 `5A9E9C05F68404D762F930334D467A0557CF226DA7722602C3185C6727B05F0E`; test SHA-256 `72618505D8CA83FB024D0D1B0D1660D2D454DFB1E424206A76F75153C639E67C`. This is not a whole-site, subjective film-quality, core or Square readiness score.
- Signed-in Chromium desktop: muted autoplay, ended at 10.005 seconds, loop false, final frame with steam. Unmute then Replay produced unmuted active playback; Mute restored silence. Phone 390x844 reached natural completion with steam. At 320px every visible button was at least 44px high, no horizontal overflow, and wrapping controls remained above the film.
- Browser checks establish media state, not a human listening assessment or iOS/Safari proof. Existing fallback posters remain unchanged. Source is 10.4 MB; mobile transfer cost is higher than the earlier compressed cut.
- Live reduced-motion check paused the film, displayed the poster and stopped steam. Temporary device and motion emulation were cleared after verification; the live homepage tab remains available to the owner.

## Rollback and evidence

Restore `C:/Codex/Output/doughboss-251-handoff-20261016/hero-before-audio-snippet598.php` to snippet 598, omitting its opening PHP tag. Its normalized source matched the pre-update saved snippet: `E19F0A1D85ABCAAC68D51ABFF833D393075B7D1CEAB8F0B996AB1D8C93702482`. Old media remains available.

Screenshots in the same evidence directory: `live-quiet-hero-desktop.png` and `live-quiet-hero-phone.png`.

Remaining work is explicitly separate: core 2.51 operational gates, per-seller Square integration, approved promotional content/signup links and announcement-bar implementation, and local Qwen connector reconnect. New/important announcements are included in the existing promotions-bar plan. This hero publication does not close those requirements.
