=== WellActually ===
Contributors: brento
Tags: quiz, engagement, gamification, blog
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.2.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Swipe mode for your blog. Show readers a bold statement, let them swipe agree/disagree/not sure, and reveal the truth (and the post behind it) when they're wrong.

== Description ==

WellActually turns your archive of blog posts into a Tinder-style knowledge game. Mark any post with a one-line "Swipe Statement" and a True/False/Debatable verdict, and visitors at your chosen URL (default `/swipe`) will see it full screen. They swipe right to agree, left to disagree, or up if they're not sure. Get it wrong — or aren't sure — and they'll see the post that explains why.

Progress is tracked locally for anonymous visitors and synced to their account when logged in, so their score follows them.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wellactually` or install via the Plugins screen.
2. Activate the plugin.
3. Visit Settings → WellActually to set the swipe page slug (default `swipe`).
4. Edit any post and fill in the "Swipe Statement" and verdict fields to add it to the deck.
5. Send visitors to `yoursite.com/swipe`.

== Frequently Asked Questions ==

= Which posts show up in swipe mode? =

Any published post with both a Swipe Statement and a verdict (True, False, or Debatable) set.

= Does this work with page caching? =

Yes — the swipe page itself can be cached, but all game state (deck contents, scoring) comes from REST endpoints marked non-cacheable.

= What counts as "correct" on a Debatable statement? =

Any answer (agree, disagree, or not sure) counts as correct, but the post is always shown afterward since the nuance is the point.

= Does my score follow me between devices? =

Only when you're logged in. Anonymous progress is stored in your browser's local storage; logging in merges it with your account's saved progress.

= Can I see which statements fool the most readers? =

Yes — the Posts list has a "Swipe stats" column showing the agree percentage and total swipe count for each post in the deck.

== Screenshots ==

1. Full-screen swipe card with keyboard/swipe hints.
2. Reveal overlay after a wrong or unsure answer.
3. End-of-deck score screen with replay option.
4. The Swipe Statement meta box on the post edit screen.

== Changelog ==

= 1.2.1 =
* Fix: a failed swipe request (network hiccup, expired nonce, rate limit) no longer records a wrong answer and skips the card — progress is left untouched and the same card stays up to retry.
* Fix: on a slow connection, running out of the current 20-card batch no longer shows the final score screen early; the app waits for the next batch (or a confirmed empty result) before deciding.
* Fix: merging progress across devices no longer resurrects a card as "wrong" when it was corrected on another device or the server.
* Fix: the swipe page now actually outputs a noindex robots meta tag (the filter was registered but never rendered).
* Fix: the reveal card's keyboard focus trap no longer strands keyboard/screen-reader users on the Continue button — Tab and Shift+Tab now cycle through the post links too.
* Fix: long reveal cards can now be scrolled with one-finger touch on small screens instead of getting stuck.

= 1.2.0 =
* AI drafting: send needs-setup posts to a configured AI provider (via WordPress 7's AI client) to draft a swipe statement and verdict for your review. Pick the provider and model in Settings → WellActually; works with any registered provider, including Nano-GPT.
* On Swipe Setup, a "Draft with AI" panel queues a batch (default 10, respects the category filter) and drafts them in the background with live progress. Drafts appear under a new "Has AI suggestions" filter, pre-filled for review — nothing goes live until you Save it.
* New per-post "Skip for now" checkbox: set a post aside (out of the deck and the review lists) without deleting its statement, verdict, or AI suggestion. Distinct from the permanent "Never" exclude. A "Skipped" filter lists them.

= 1.1.0 =
* New "Swipe Setup" screen (Posts → Swipe Setup) for configuring many existing posts at once: filter by status (needs setup / in deck / excluded / all), category, and date; edit the statement and verdict inline, or mark a post as never getting a swipe setup; save a whole page at once.
* Posts can now be explicitly excluded from swipe mode (e.g. personal news), separate from "not set up yet."
* Posts list gains "Needs setup" and "Excluded" filters.

= 1.0.0 =
* Initial public release: full swipe mode experience, meta box, aggregate stats, anonymous + logged-in progress tracking, replay mode.
