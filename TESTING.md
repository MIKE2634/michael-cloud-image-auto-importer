# Testing and Regression Checklist

## Environment

Use a disposable WordPress installation with a test database and test
Google Drive account. Use test credentials only. Record WordPress,
PHP, WooCommerce, and plugin versions before testing.

## Static review

- [ ] Inspect PHP changes for syntax errors in a PHP-enabled environment.
- [ ] Check WordPress coding conventions and escaping/sanitization.
- [ ] Check that privileged actions verify capabilities and nonces.
- [ ] Confirm API failures return understandable errors.
- [ ] Review logs for accidental credentials or sensitive data.

## Activation and lifecycle

- [ ] Activate the plugin on a clean WordPress installation.
- [ ] Deactivate it and confirm scheduled tasks are handled correctly.
- [ ] Reactivate it and verify settings and expected behavior.
- [ ] Test uninstall behavior on disposable data only.
- [ ] Confirm cleanup does not remove unrelated options or records.

## Google Drive integration

- [ ] Test valid and invalid folder URLs.
- [ ] Test authorization success, denial, expiry, and revocation.
- [ ] Test empty folders, unsupported files, and inaccessible files.
- [ ] Test API rate limits, timeouts, and transient network failures.
- [ ] Confirm secrets and tokens are not exposed to unauthorized users.

## Import behavior

- [ ] Import one valid image and verify the resulting media attachment.
- [ ] Test duplicate detection with the same image twice.
- [ ] Test a mixed batch containing valid and invalid files.
- [ ] Test large batches, interrupted requests, and retry behavior.
- [ ] Verify image dimensions, compression, and WebP behavior where enabled.
- [ ] Verify generated alt text and filenames.
- [ ] Confirm progress and errors accurately reflect completed work.

## WordPress security

- [ ] Test AJAX actions as an administrator and an unauthorized user.
- [ ] Test missing, invalid, and expired nonces.
- [ ] Test malformed input and unexpected API responses.
- [ ] Confirm output is escaped and input is validated.
- [ ] Confirm access tokens and credentials never appear in browser output.

## WooCommerce and compatibility

- [ ] Test relevant WooCommerce workflows when WooCommerce is installed.
- [ ] Test behavior when WooCommerce is absent or disabled.
- [ ] Test supported WordPress and PHP versions.
- [ ] Check browser console and WordPress/PHP logs.

## Evidence

Record the environment, exact steps, expected result, actual result,
and logs for every failure. Do not mark a test passed unless it was run.
