# Well, Actually...

### Turn your WordPress archive into a swipeable knowledge game.

Your blog already contains years of hard-won lessons, surprising facts, unpopular opinions, and “it depends” answers. **Well, Actually...** turns those posts into a fast, playful quiz that helps readers discover your best work.

Readers see a bold statement and choose:

- **Agree**
- **Disagree**
- **Not sure**

Then the plugin judges the answer, keeps score, and reveals the article that explains the truth.

No page builder. No separate quiz platform. Just your existing WordPress posts, finally doing something fun at parties.

## What readers get

- A polished, full-screen card game at a URL you choose, such as `/swipe`
- Touch and mouse swiping, keyboard controls, and regular answer buttons
- True, False, and Debatable questions
- Server-side judging—the answer is not quietly hiding in the page source
- Explanations and links back to the original posts
- A running score and end-of-game results
- The ability to replay only the questions they missed
- Shareable score text
- Saved progress for anonymous and logged-in readers
- Cross-device progress for logged-in WordPress users

Anonymous progress stays in that browser. When someone logs in, their local progress is merged with their WordPress account.

## What publishers get

### Turn old posts into a new experience

Every card begins with an ordinary published WordPress post. Give it a short Swipe Statement and mark the answer as True, False, or Debatable.

For example:

> Temp tables are always faster than CTEs.

The post itself becomes the explanation when the reader gets it wrong—or whenever the answer is Debatable and the nuance is the whole point.

### Set up hundreds of posts without losing your mind

The bulk Swipe Setup screen is built for real archives, not just five-post demo sites.

You can:

- Filter by setup status, category, and post author
- Sort by publication date, modification date, comments, or Jetpack’s 30-day views
- Review posts 20 at a time
- Enter statements and verdicts directly in the grid
- Mark individual posts **Skip for Now** or **Never**
- Apply Skip or Never to the entire visible page, then uncheck the exceptions
- Exclude entire category branches from setup and AI drafting
- Keep AI suggestions separate until a human reviews and saves them

The regular Posts screen also gets swipe-status filters and columns, so the feature feels like part of WordPress instead of a second CMS hiding inside it.

### See what fools people

Well, Actually... records aggregate Agree, Disagree, and Not Sure responses.

The Reports screen shows:

- Total swipes
- Percentage right
- Percentage wrong
- The questions that fool the most readers
- Sortable performance columns
- Quick editing for statements and answers

Once a card has enough responses to be meaningful, it can also tell players how many readers got it wrong—without revealing the answer.

## Optional AI drafting

Writing hundreds of short, provocative statements can be the slowest part. The optional **Draft with AI** workflow can suggest a statement and verdict from an existing post.

It is deliberately human-in-the-loop:

1. You choose how many posts to draft.
2. The plugin sends those posts to your selected AI provider.
3. Suggestions return to a review queue.
4. Nothing enters the live game until someone reviews and saves it.

You can customize the drafting instructions to match your subject matter and voice, choose the provider and model, control parallel requests, and inspect recent drafting errors.

AI is entirely optional. The public game and every manual setup feature work without an AI provider.

## Who is this for?

Well, Actually... is especially useful for:

- Bloggers with a large evergreen archive
- Educators and training teams
- Technical experts whose best answer is often “it depends”
- Newsletters and membership communities
- Consultants who want readers to discover older articles
- Editorial teams looking for a playful way to reuse existing content
- Anyone with strong opinions and citations ready to go

It works particularly well when your archive contains myths, misconceptions, rules of thumb, surprising facts, or advice that changes depending on context.

## Plays nicely with WordPress

### Themes and caching

The game uses its own focused, full-screen template, so it does not depend on your theme’s page layout or require a page builder.

The public page can be cached. Deck selection, judging, scoring, and progress use REST endpoints with non-cacheable responses, avoiding the usual “every visitor received the same quiz state” problem.

### WordPress users

Anonymous visitors store progress locally in their browsers. Logged-in visitors also save progress to their WordPress accounts, allowing it to follow them between devices.

### WordPress AI providers

Draft with AI uses the AI client included with WordPress 7.0. It works with provider plugins registered through that system, including Nano-GPT and other compatible connectors.

You select and configure the provider. Well, Actually... does not bundle an AI vendor or require one.

### Jetpack Stats

When Jetpack Stats is available, Swipe Setup displays its **Views: 30 days** figures and can sort matching posts by popularity. Without Jetpack, the rest of the setup screen and plugin continue to work normally.

## Privacy and external services

Playing the game does not contact an external service.

Aggregate swipe totals are stored by your WordPress site. Anonymous progress remains in the reader’s browser; logged-in progress is stored with the reader’s WordPress account.

AI drafting contacts an external service only when an authorized editor explicitly clicks **Draft with AI**.

For each selected post, the plugin sends:

- The post title
- Its manual excerpt, when available; otherwise
- Up to the first 3,000 plain-text characters of its content

HTML and code blocks are removed. Reader activity, user accounts, site credentials, and swipe history are not sent to the AI provider.

Your chosen provider’s terms and privacy policy govern that optional transfer.

## Requirements

- WordPress 7.0 or newer
- PHP 7.4 or newer
- Published WordPress posts to turn into cards

Optional features require:

- A WordPress AI-client-compatible provider plugin and API key for Draft with AI
- Jetpack Stats for the 30-day view column and popularity sorting

## Installation

1. Upload the plugin to `wp-content/plugins/wellactually`, or install its ZIP through **Plugins → Add New**.
2. Activate **Well, Actually...**
3. Open **Settings → Well, Actually...**
4. Choose the game URL slug—the default is `/swipe`.
5. Open **Posts → Well, Actually...** to begin setting up your archive.
6. Send readers to `yoursite.com/swipe` and prepare to learn which of your “obvious” facts were not obvious at all.

## Development

Well, Actually... uses plain PHP, JavaScript, and CSS with no frontend build step.

See [TESTING.md](TESTING.md) for the testing checklist. A WP-CLI seeding script is included for archive-scale testing:

```bash
wp eval-file wp-content/plugins/wellactually/bin/seed.php
```

Run the automated checks with Composer:

```bash
composer lint
composer test
```

## Building a release

```bash
bin/build-release.sh
```

The release script creates `dist/wellactually-<version>.zip` containing only the files required by a live WordPress installation.

## License

GPL-2.0-or-later
