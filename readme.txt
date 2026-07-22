=== WellActually ===
Contributors: brento
Tags: quiz, engagement, gamification, blog
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
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

== Changelog ==

= 0.1.0 =
* Initial scaffold: settings page, plugin structure.
