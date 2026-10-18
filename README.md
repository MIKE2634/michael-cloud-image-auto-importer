# Michael Cloud Image Auto Importer

A WordPress plugin for importing images from Google Drive into the
WordPress Media Library, with WooCommerce-related workflows where supported.

## Repository layout

- `trunk/` — current WordPress.org plugin source
- `tags/` — versioned releases
- `assets/` — plugin directory assets and screenshots
- `TESTING.md` — manual verification and regression checklist

## Before contributing

1. Review `trunk/readme.txt` for the plugin's documented features.
2. Keep changes to the plugin source inside `trunk/` unless a release
   or repository asset specifically needs updating.
3. Do not edit historical releases under `tags/` unless intentionally
   preparing a release-history change.
4. Review security, error handling, compatibility, and regression risks.
5. Test changes in a disposable WordPress installation before release.

## Testing

See [TESTING.md](TESTING.md) for a practical test checklist.

PHP, WordPress, and database integration tests require an appropriate
PHP/WordPress environment. This repository-only documentation update
does not claim those tests have been executed.

## Security

Never commit Google service-account credentials, OAuth client secrets,
access tokens, production configuration, or customer data.
