=== WellActually ===
Contributors: brento
Tags: quiz, engagement, gamification, blog
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Swipe mode for your blog. Show readers a bold statement, let them swipe agree/disagree/not sure, and reveal the truth (and the post behind it) when they're wrong.

== Description ==

WellActually turns your archive of blog posts into a Tinder-style knowledge game. Mark any post with a one-line "Swipe Statement" and a True/False/Debatable verdict, and visitors at your chosen URL (default `/swipe`) will see it full screen. They swipe right to agree, left to disagree, or up if they're not sure. Get it wrong — or aren't sure — and they'll see the post that explains why.

Progress is tracked locally for anonymous visitors and synced to their account when logged in, so their score follows them.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/well-actually` or install via the Plugins screen.
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

= 1.0.0 =
* Initial public release: full swipe mode experience, meta box, aggregate stats, anonymous + logged-in progress tracking, replay mode.
