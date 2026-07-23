## What this changes

<!-- One or two sentences. What behaviour is different afterwards? -->

## Why

<!-- The bug, or the reason. Link an issue if there is one. -->

## How it was verified

<!-- Replace with what you actually did. "Tests pass" alone isn't enough for
     anything touching drafting, the queue, or the admin filters. -->

- [ ] `composer test` passes
- [ ] Exercised in a browser against a realistic amount of content
- [ ] For concurrency changes: tested with more than one request in flight
- [ ] For anything touching stored status: checked the affected admin filters
      still show the right posts after a save

## Risk

<!-- What would break if this is wrong, and how would you notice? -->

<!-- CI runs lint + the PHPUnit matrix (PHP 7.4/WP 6.5 and PHP 8.2/WP latest);
     these must pass to merge. GitHub Copilot reviews the diff automatically —
     a second set of eyes, not a gate. Human review still decides. -->
