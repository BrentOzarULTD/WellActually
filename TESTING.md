# WellActually manual test checklist

Run through this before each release. Check items off in the PR description.

## Verification status

The full checklist below was executed end-to-end against a real WordPress
7.0 install (PHP 8.5 / MariaDB) on 2026-07-22 with `WP_DEBUG` on. REST
endpoints were exercised via scripted HTTP calls (9-case correctness matrix,
leakage checks, rejections, tally/percentage math), and the swipe UI, admin
screens, slug change, and logged-in cross-device merge were driven in a real
browser. No PHP notices or console errors were observed.

Four bugs were found and fixed during that pass:

1. The `wp_wa_stats` table was never created — the `wa_activate` listener
   wasn't registered at activation time and the `plugins_loaded` safety net
   was dead code. (Fixed: boot components in the activation hook; move the
   safety net to `init`.)
2. `swipe.js` never booted — it was printed in `<head>` and ran before
   `#wa-app` existed. (Fixed: print before `</body>`; gate on
   `DOMContentLoaded`.)
3. REST requests hit a malformed URL (`v1deck`) because the JS joined the
   route onto a base with no trailing slash. (Fixed: a `restUrl()` join
   helper.)
4. Changing the swipe slug didn't flush rewrite rules, so the new URL 404'd.
   (Fixed: a self-healing flush on `init` when stored rules lack the current
   slug's rule.)

Still worth a human pass before shipping: real iOS Safari / Android Chrome
gesture feel, screen-reader output, and behavior with a production
page-caching plugin active.

## Correctness matrix (POST /swipe)

For each verdict × answer combination, confirm `correct` and `show_post` match:

| Verdict   | Answer   | correct | show_post |
|-----------|----------|---------|-----------|
| true      | agree    | true    | false     |
| true      | disagree | false   | true      |
| true      | unsure   | false   | true      |
| false     | agree    | false   | true      |
| false     | disagree | true    | false     |
| false     | unsure   | false   | true      |
| debatable | agree    | true    | true      |
| debatable | disagree | true    | true      |
| debatable | unsure   | true    | true      |

## Editor / admin

- [ ] Swipe Statement + Verdict fields appear and save in Classic Editor.
- [ ] Same fields appear and save in Gutenberg (meta box compatibility mode).
- [ ] Posts list "Swipe" column shows the right icon/label and is sortable.
- [ ] Posts list "Swipe stats" column shows `N% agree · M swipes` (or "No swipes yet").
- [ ] Posts list filter dropdown (All / In deck / True / False / Debatable) narrows correctly.
- [ ] Settings → WellActually slug field saves and changes the live URL.
- [ ] Changing the slug 404s the old URL and serves the new one (after auto-flush).

## Anonymous play

- [ ] `/swipe` (or configured slug) renders full-screen, no theme header/footer.
- [ ] Keyboard: ArrowLeft/Up/Right answer correctly; buttons work identically.
- [ ] Touch/mouse drag: label fade-in, threshold commit, flick commit, spring-back under threshold.
- [ ] Correct true/false answers show a brief flash only (no overlay).
- [ ] Wrong / unsure / debatable answers show the full reveal overlay with the right banner text.
- [ ] "Read the full post" opens in a new tab; game state is intact on return.
- [ ] Reload mid-game: score and seen-list resume exactly; no repeats.
- [ ] Close browser, return later: same as above.
- [ ] Corrupt the `wa_progress` localStorage value by hand: app recovers cleanly.
- [ ] "Start over" (confirm-guarded) resets local state and fetches a fresh deck.
- [ ] Finishing the deck shows the score/tier screen; replay button count is correct.
- [ ] Replay round serves only wrong-list posts; correcting one removes it from the wrong list; aggregate stats don't move during replay.
- [ ] Share text + copy button work.

## Logged-in play

- [ ] Log in on device/browser A, answer a few cards; open device/browser B logged in: score and seen-list carry over.
- [ ] Play a few anonymously, then log in: histories merge without double-counting.
- [ ] `curl -X POST .../progress` without auth returns 401.
- [ ] Tampered payload (inflated counts, junk IDs) is corrected/rejected server-side, not trusted as-is.
- [ ] Anonymous session makes zero requests to `/progress` (check the Network tab).

## Accessibility

- [ ] Full run (start to finish) using only the keyboard.
- [ ] Screen reader announces the statement and the reveal verdict.
- [ ] Focus visibly moves to the reveal overlay's Continue button and returns after dismissal.
- [ ] `prefers-reduced-motion` is honored (no fly-off/slide animations).

## Compatibility & performance

- [ ] Works with a page-caching plugin active; `/swipe` HTML can be cached, but `POST /swipe` and `/progress` responses are `Cache-Control: no-store`.
- [ ] Seed ~3,000 posts (`wp eval-file bin/seed.php`) and confirm `/deck` responds quickly and `/swipe` still judges correctly at that scale.
- [ ] No PHP notices/warnings with `WP_DEBUG` on, across every screen above.
- [ ] No browser console errors, across every screen above.
- [ ] iOS Safari and Android Chrome: gestures work, no pull-to-refresh/scroll interference.
