# WellActually

A WordPress plugin that turns your blog archive into a Tinder-style knowledge game.

Mark any post with a one-line **Swipe Statement** and a **True / False / Debatable** verdict, and visitors at your chosen URL (default `/swipe`) see it full screen. Swipe right to agree, left to disagree, up if you're not sure — or use the arrow keys. Get it wrong (or aren't sure) and you'll see the post that explains why.

## Features

- Full-screen swipe card, keyboard + touch/mouse gesture controls
- Server-side judging so answers never appear in view-source or the network tab
- Reveal overlay with the post excerpt, a link to read more, and "X% of readers agreed"
- Score and seen-statement tracking via local storage (anonymous) or user account (logged in), merged automatically on login
- Replay just the ones you got wrong
- Admin columns showing each post's verdict and aggregate agree/disagree/unsure tally

## Installation

1. Upload to `wp-content/plugins/wellactually` (or install the zip via Plugins → Add New).
2. Activate the plugin.
3. Visit **Settings → WellActually** to set the swipe page slug (default `swipe`).
4. Edit any post and fill in the **Swipe Statement** and **Verdict** fields in the meta box.
5. Send visitors to `yoursite.com/swipe`.

## Development

Plain PHP + vanilla ES6/CSS, no build step. See [TESTING.md](TESTING.md) for the manual test checklist and `bin/seed.php` for a WP-CLI script that generates test posts for load testing:

```bash
wp eval-file wp-content/plugins/wellactually/bin/seed.php
```

## Building a release

```bash
bin/build-release.sh
```

Copies just the files a live site needs (main plugin file, `includes/`, `templates/`, `assets/`, `languages/`, `uninstall.php`, `readme.txt`, `LICENSE`) into a `wellactually/` folder and zips it as `dist/wellactually-<version>.zip` — version read straight from the plugin header. Dev-only files (this repo's `.git`, `TESTING.md`, `bin/seed.php`, the local test harness, etc.) are never included. Unzipping the result into `wp-content/plugins/` drops the plugin in as `wp-content/plugins/wellactually`. `build/` and `dist/` are gitignored; nothing there is source.

## License

MIT
