=== Well, Actually... ===
Contributors: brento
Tags: quiz, engagement, gamification, blog
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.3.3
License: MIT
License URI: https://opensource.org/licenses/MIT

Swipe mode for your blog. Show readers a bold statement, let them swipe agree/disagree/not sure, and reveal the truth (and the post behind it) when they're wrong.

== Description ==

"Well, Actually..." turns your archive of blog posts into a Tinder-style knowledge game. Mark any post with a one-line "Swipe Statement" and a True/False/Debatable verdict, and visitors at your chosen URL (default `/swipe`) will see it full screen. They swipe right to agree, left to disagree, or up if they're not sure. Get it wrong — or aren't sure — and they'll see the post that explains why.

Progress is tracked locally for anonymous visitors and synced to their account when logged in, so their score follows them.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wellactually` or install via the Plugins screen.
2. Activate the plugin.
3. Visit Settings → "Well, Actually..." to set the swipe page slug (default `swipe`).
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

= 1.3.3 =
* Fix: the empty "No posts match this filter" screen after saving, properly this time. The 1.3.2 fix invalidated WordPress's cached query results on every status change, but that only helps if the invalidation is seen immediately — on managed hosting it isn't, which is why the screen would right itself on its own after roughly 30-60 seconds (the cache entry's own lifetime). Rather than depending on any host's invalidation timing, the "Well, Actually..." screen and the AI candidate picker now always read live from the database. Both are small, already-indexed queries, so there's no meaningful cost — and it means a save is reflected on the very next page load, every time.
* Fix: post titles and excerpts could show raw HTML entities ("There&#8217;s a Bug…" instead of "There's a Bug…") on the swipe cards, the reveal card, and the setup screen. WordPress hands back titles and excerpts already HTML-encoded, and both the game and the admin screen were then escaping them a second time. All post text is now decoded once before display, and swipe statements are shown decoded when editing too, so what you type is what gets stored.

= 1.3.2 =
* Fix: saving a page of the "Well, Actually..." screen and landing back on the same filtered view could show "No posts match this filter" even though the post count at the top was correct and nonzero. On sites with a persistent object cache (common on managed hosting), WordPress caches a query's matching post IDs and only invalidates that cache when a post itself changes — not when post meta does, which is all our status field is. Saving changed the meta but left the cached ID list stale, so the very next page load re-served the posts that were *just* handled; this plugin's own stale-status safety net then (correctly) recognized every one of them as no longer belonging and dropped them all, emptying the page. Every place that changes a post's swipe status now also invalidates that cache, so the next query is always fresh.

= 1.3.1 =
* On the "Well, Actually..." screen, add "Date modified" and "Comment count" as sort options alongside "Date published" — all three are indexed core columns, so they stay cheap even on a large archive.
* Speed up Draft with AI: send far less post text per request (30,000 characters down to 3,000), prefer a post's manual excerpt when it has one instead of its full content, and drop code samples entirely before building the prompt — none of that helps pick a one-line true/false/debatable statement, and smaller requests mean faster drafts.

= 1.3.0 =
* Rebrand: the plugin now displays on-screen as "Well, Actually..." everywhere (Plugins list, admin screens, share text). No change to the plugin's internal slug, files, or database keys.
* Rename the Posts submenu screen (formerly "Well Actually Setup") to just "Well, Actually...".
* Add category exclusion: Settings → "Well, Actually..." → Categories lists every category (with parent/child indenting and post counts) with a "Skip This Category" checkbox. Skipped categories' posts are hidden from every status view on the "Well, Actually..." screen and are never offered to AI drafting.
* Settings → "Well, Actually..." is now a tabbed page: Setup, Categories, and a new Errors tab listing AI drafting failures from the last 7 days (with the post title, error message, and a link to fix it) — previously a failed draft was silently discarded with no way to see why.
* Fix: the "AI error" chip on the "Well, Actually..." screen looked clickable (cursor changed to a question mark) but did nothing. It's now a real link to the new Errors tab.
* Fix: a denormalized status value could go stale and let an already-configured post linger in the "Needs to be set up" filter (or a similar mismatch in the other status filters). Each filtered page of results now double-checks its own rows against the post's actual verdict/AI-status/skip meta and self-corrects any mismatch — bounded to one page of results, so it stays cheap at archive scale.
* Fix: clicking "Draft with AI" without an explicit model chosen in this plugin's settings would fail outright on providers (like Nano-GPT) that reject a request with no model at all, even when the site owner had already picked a default model in that provider's own settings. It now asks the provider for its published default model first.
* Rename: "Needs setup" → "Needs to be set up", "Skipped" → "Skipped for Now", and the "Skip" column header → "Skip for Now", for clarity.
* Fix: the site name in the share-your-score text could show a raw HTML entity (e.g. "Brent&#8217;s Blog") instead of an apostrophe. WordPress's get_bloginfo('name') returns HTML-entity-encoded text even without asking for it, and the frontend was escaping it a second time.
* Fix: a literal double-quote character in the share text (from the new "Well, Actually..." branding) could break the share text box and corrupt the markup after it — the frontend's HTML-escaping helper wasn't escaping quote characters, only the ones unsafe in plain text.
* The share-score prompt now includes a link back to the quiz so whoever it's shared with can play, and appears every 20 swipes in addition to the end-of-deck screen, so players who don't finish a (large) deck still get invited to share.
* Hardened the reveal card's Continue button against a first-tap-does-nothing issue some players hit — it now also responds to the pointer-down that starts a tap/click, rather than waiting on the full click event alone.

= 1.2.4 =
* Fix: Well Actually Setup (and the Posts list filter behind it) could still take many seconds — or time out — on a large archive, even after the 1.2.3 caching fix. The "Needs setup" / "Has AI suggestions" / "In swipe deck" views were built from a meta_query spanning three separate post-meta keys with NOT EXISTS branches on each, which at real archive scale (thousands of posts, tens of thousands of postmeta rows once other plugins' data is counted) required several joins across the entire postmeta table — measured at 6.6 seconds for a single query against a 3,000-post/50,000-row test table, versus 7 milliseconds for an equivalent single-key lookup. Replaced it with one denormalized status field, recomputed automatically whenever a post's verdict, AI suggestion, or skip flag changes, cutting that same query to well under a tenth of a second. Existing sites are migrated automatically the first time any admin page loads after updating — no action needed.

= 1.2.3 =
* Rename the Posts submenu screen from "Swipe Setup" to "Well Actually Setup."
* Fix: opening that screen (and Settings → WellActually) could take tens of seconds because checking whether an AI provider is configured can involve a live round-trip to the provider (e.g. validating the key or checking account balance) — some providers do this on every check. That check is now cached for 5 minutes and cleared immediately whenever you save settings, so only the first load after a change pays the cost.

= 1.2.2 =
* Add a "Settings" quick link to this plugin's row on the Plugins list page.

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
