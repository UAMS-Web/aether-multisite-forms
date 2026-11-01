# Packagist and Marketplace submission checklist

The package is on Packagist; the Statamic Marketplace listing waits for v1.0.0 (#187). This is the list of real-world steps, with the done ones marked so the rest are known before they are needed. The add-on is free with no editions and MIT licensed (decision log, #17).

Each step is marked **Person** when it needs someone with an account, or **Repo** when it is a change here.

## Before anything is submitted

1. **Repo.** The README describes only what exists, and the features it lists are real. #163 rewrote *Status* and *What it does* to describe what ships, and #183 added what the Marketplace guidelines ask for (below); check it again before submitting to the Marketplace.
2. **Repo.** `composer.json` is complete for a public package: `name`, `description`, `keywords`, `type` of `statamic-addon`, `license`, `authors`, `support`, `autoload`, `extra.statamic` (`name`, `description`) and `extra.laravel.providers`. A test (`tests/Unit/SubmissionReadinessTest.php`) pins these.
3. **Repo.** The built Control Panel assets are committed (`resources/dist`) and the `Built assets` job is green, because a Composer install does not run npm.
4. **Person.** The operator decides to submit. Done for Packagist on 2026-10-14, recorded on #185. For the Marketplace, the decision is to submit once v1.0.0 is released (decision log, 2026-10-14; #187).

## Packagist (done)

5. **Person.** Done 2026-10-14: the package is [`uams-web/aether-multisite-forms`](https://packagist.org/packages/uams-web/aether-multisite-forms).
6. **Person.** Done: the repository's Packagist webhook sends push events, so a pushed tag appears on Packagist without a manual update.

## First release (done)

7. **Person.** Done: `v0.1.0` was tagged on 2026-10-12, and releases follow the `cutting-releases` rule and the `writing-release-notes` skill. The Marketplace lists versions from tags.

## Statamic Marketplace

None of these steps starts before v1.0.0 is released (#180); #187 tracks them. Statamic's [submission guidelines](https://statamic.com/marketplace/submission-guidelines) apply to free products too. Before step 8, run the `marketplace-review` skill (`.claude/skills/marketplace-review/`) against the release being submitted, and fix what it finds.

8. **Person.** Create a statamic.com seller account (`https://statamic.com/creator/begin`). Decide whether it is personal or institutional before creating it.
9. **Person.** Bank details: the guide ties these to charging for an add-on, so a free listing is expected not to need them. Unverified.
10. **Person.** In the seller dashboard, create a product linked to the Packagist package, with a price of zero, a description, and screenshots of the Sites field and the override rows (the guidelines' Rule 10 accepts a usage example instead, which the README's quick start gives). Products start as drafts and can be previewed before publishing.
11. **Person.** Publish the product only when the operator says so. Once published, the add-on appears on the Marketplace and in the Control Panel's Addons area.

## Unverified

- Whether a free listing needs bank details or a seller account type beyond the default.
- Whether the price, or the edition list, can change after the first public tag. Adding `["free", "pro"]` later with `free` first leaves installs on `free` (measured in `docs/DEVELOPMENT.md`); the Marketplace side was not measured because no product exists.
- Whether the Marketplace requires anything about the license beyond a declared one.

## Source

Statamic's guide, Building an Addon (Marketplace Submission and Editions), and the marketplace submission guidelines (revision 2026-09-14). Re-read the steps above against the live seller dashboard before step 8.
