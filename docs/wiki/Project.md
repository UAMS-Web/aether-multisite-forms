Written against v0.6.1.

Where to report a problem, how releases work, and where the development notes live.

## Report a problem or ask for a feature

Open an issue in the [issue tracker](https://github.com/UAMS-Web/aether-multisite-forms/issues/new/choose), choosing **Bug** for something that behaves incorrectly. Say which release you run, your Statamic version, and what you saw: a log line or error message quoted exactly, or the page and site where it happened. [Troubleshooting](Troubleshooting.md) may already name the cause.

Support is community support through the issue tracker; there is no paid support.

## Releases

Releases are listed on the [releases page](https://github.com/UAMS-Web/aether-multisite-forms/releases), each with notes grouped into what is new, fixed, security and maintenance, and any breaking change called out first.

- The add-on is in early development: every release is a 0.x pre-release, published on [Packagist](https://packagist.org/packages/uams-web/aether-multisite-forms). See [Installation (README)](https://github.com/UAMS-Web/aether-multisite-forms#installation).
- A release is cut each business day at 10:00 US Central time when `main` has unreleased work, so each one carries at most a day's changes.
- A minor version (`0.5.0`) carries new features, and can carry a breaking change, which its notes describe with the action to take; a patch version (`0.4.2`) carries refinements only.
- These wiki pages are republished with each release.

## Development notes

- [`docs/DEVELOPMENT.md`](https://github.com/UAMS-Web/aether-multisite-forms/blob/main/docs/DEVELOPMENT.md): setting up a multisite host and running the tests against it.
- [`docs/DECISIONS.md`](https://github.com/UAMS-Web/aether-multisite-forms/blob/main/docs/DECISIONS.md): the design decisions, with why each was made and what was rejected.
- [`AGENTS.md`](https://github.com/UAMS-Web/aether-multisite-forms/blob/main/AGENTS.md): the commands, the gate, and the repository's layout.
- [`docs/wiki/`](https://github.com/UAMS-Web/aether-multisite-forms/tree/main/docs/wiki): these pages. Change them through a pull request; an edit made in the wiki is overwritten by the next publish.

## License

MIT. See [`LICENSE`](https://github.com/UAMS-Web/aether-multisite-forms/blob/main/LICENSE).
