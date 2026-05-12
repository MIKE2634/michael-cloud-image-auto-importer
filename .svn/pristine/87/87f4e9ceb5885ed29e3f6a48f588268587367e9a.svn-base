=== Michael Cloud Image Auto Importer ===
Contributors: mike17894
Tags: google-drive, media library, image import, cloud, photos
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import images from Google Drive directly into the WordPress Media Library with automatic alt text generation.

== Description ==

Michael Cloud Image Auto Importer lets you import images from Google Drive straight into your WordPress Media Library.  
It is ideal for bloggers, photographers, and content creators who store their media in Google Drive.

The plugin connects securely to your Google Drive account, allows you to select folders, and imports images in bulk while avoiding duplicates.

**Key Features:**
* Secure Google Drive OAuth 2.0 integration
* Bulk image import from Drive folders
* Automatic duplicate detection using MD5 file hashes
* SEO-friendly alt text generation from filenames
* Optional image compression with quality control
* Real-time import progress tracking
* Detailed import logs with statistics
* Privacy-focused design with explicit user consent
* No usage limits, subscriptions, or trialware
* WordPress.org compliant coding standards

== External Services ==

This plugin connects to Google Drive API (provided by Google LLC) to import images from Google Drive into your WordPress media library.

**Service:** Google Drive API
**Purpose:** To browse, list, and download images from your Google Drive account
**Data Transmitted:**
- OAuth 2.0 authentication tokens (for API access)
- File metadata (names, IDs, sizes, MIME types)
- MD5 hashes of files (for duplicate detection)
- File content when downloading images

**When data is transmitted:**
- When you connect your Google Drive account
- When browsing/listing folders
- When importing images

**Service Provider Information:**
- Terms of Service: https://developers.google.com/drive/terms
- Privacy Policy: https://policies.google.com/privacy

**User Consent:** This plugin requires explicit user consent before making any connections to Google services. You must enable "External Connections" in the plugin settings before connecting to Google Drive.

== Features ==

* **Secure Google Drive Integration** – Uses official Google API with OAuth 2.0
* **Bulk Image Import** – Import entire folders at once
* **Duplicate Detection** – Uses MD5 file hashing to skip existing images
* **SEO Optimization** – Auto-generates alt text from filenames
* **Image Compression** – Optional compression with adjustable quality
* **Progress Tracking** – Real-time progress bar and statistics
* **Import Logs** – Detailed logs of all imports with success/failure rates
* **Privacy First** – Requires explicit consent before connecting to Google
* **No Limits** – Import as many images as you need
* **Clean Design** – Intuitive WordPress admin interface

== How It Works ==

1. **Configure Settings** – Enter Google API credentials in plugin settings
2. **Grant Consent** – Enable external connections (required for Google Drive)
3. **Connect Drive** – Authorize access to your Google Drive account
4. **Import Images** – Paste Google Drive folder URL and start import
5. **Track Progress** – Monitor real-time progress with detailed statistics

== Privacy & Security ==

* **User Consent Required** – Explicit opt-in required for Google Drive access
* **No Tracking** – No analytics, ads, or user tracking
* **Direct Downloads** – Images download directly to your server
* **Secure Storage** – Google API credentials stored securely in WordPress database
* **No Third Parties** – No external image processing services
* **Data Control** – Option to remove all data on uninstall

== Installation ==

1. Upload the 'Michael Cloud Image Auto Importer' folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Go to **Michael Cloud Image Auto Importer → Settings**
4. Enter your Google API credentials (see Google API Setup below)
5. Enable external connections consent
6. Go to main page and connect Google Drive
7. Start importing images

== Google API Setup ==

1. **Create Google Cloud Project**
   * Visit [Google Cloud Console](https://console.cloud.google.com/)
   * Create new project or select existing
   * Name: "Michael Cloud Image Auto Importer"

2. **Enable Google Drive API**
   * Go to "APIs & Services" → "Library"
   * Search for "Google Drive API"
   * Click "Enable"

3. **Create OAuth 2.0 Credentials**
   * Go to "APIs & Services" → "Credentials"
   * Click "Create Credentials" → "OAuth client ID"
   * Application type: "Web application"
   * Name: "Cloud Auto Importer"
   * Authorized redirect URI: `[your-site]/wp-admin/admin.php?page=cloud-auto-importer`

4. **Configure Plugin**
   * Copy "Client ID" and "Client Secret"
   * Paste into plugin Settings page
   * Save settings

== Frequently Asked Questions ==

= Do I need a Google Cloud account? =
Yes. A Google Cloud account is required to create Drive API credentials. The account is free and includes generous API quotas.

= Is there a limit to how many images I can import? =
No. There are no usage or import limits in the plugin. Your only limit is Google's API quota (which is very generous for personal use).

= Can I import images from shared folders? =
Yes. Any Google Drive folder you have access to (including shared folders) can be imported.

= What image formats are supported? =
JPG, JPEG, PNG, GIF, WebP, BMP, TIFF, TIF, and SVG.

= Are images compressed during import? =
Optional compression is available for JPG, PNG, and WebP images. You can adjust quality from 50-95%.

= How are duplicate images handled? =
Duplicates are detected using MD5 file hashing. If a file with the same hash already exists in your Media Library, it will be skipped.

= Is my data secure? =
Yes. Images download directly to your server. Google API credentials are stored in your WordPress database. No data is sent to third parties beyond the required Google Drive API connections documented in the External Services section.

= What happens when I uninstall the plugin? =
You can choose to keep or delete all plugin data during uninstall. This includes settings, logs, and import metadata.

= Does this work on multisite? =
Yes. The plugin is multisite compatible.

== Screenshots ==

1. **Main Import Interface** – Connect Google Drive and start imports
2. **Import Progress** – Real-time progress bar with live statistics
3. **Settings Page** – Google API configuration and privacy settings
4. **Import Logs** – Detailed history of all imports with success rates
5. **Setup Guide** – Step-by-step setup instructions

== Changelog ==

= 1.0.7 =

* Incresaed realiablility and effeciency
* Maintained WordPress.org compliance


= 1.0.6 =

* Fixed text domain to match plugin slug (michael-cloud-image-auto-importer) to all files 
* Updated all translation strings to use correct text domain
* Improved WordPress.org compliance
* Removed all the errors 

= 1.0.5 =
* Added comprehensive external services documentation for Google Drive API
* Fixed variable prefix consistency (changed $wpfilesystem to $wp_filesystem)
* Removed direct core file inclusion (wp-admin/includes/image.php)
* Fixed text domain to match plugin slug (michael-cloud-image-auto-importer)
* Updated all translation strings to use correct text domain
* Improved WordPress.org compliance

= 1.0.4 =
* Added external services documentation for Google Drive API
* Fixed prefix compliance: Changed 'cai_' to 'mcai_' (4+ characters)
* Fixed sanitization: Changed (bool) to wp_validate_boolean() for settings
* Removed direct file inclusion: wp-admin/includes/image.php
* Fixed file writing to plugin folder
* Added WordPress.org username to Contributors: mike17894
* Updated all AJAX actions to use mcai_ prefix
* Updated CSS/JS class names and IDs
* Fixed database table name prefix
* Improved security and WordPress.org compliance
* Updated Google Drive integration class
* Enhanced error handling and logging
* Added proper text domain: michael-cloud-image-auto-importer

= 1.0.3 =
* Renaming the plugin to Michael cloud image auto importer

= 1.0.2 =
* Fixed PHPCS warnings and coding standards issues
* Added proper escaping for all output
* Improved nonce verification and security checks
* Enhanced error handling in Google Drive integration
* Minor bug fixes and performance improvements

= 1.0.1 =
* Fixed translation compatibility
* Improved alt text generation algorithm
* Added translators comments for all strings
* Enhanced image compression quality controls
* Fixed duplicate detection edge cases

= 1.0.0 =
* Initial release
* Google Drive API integration
* Bulk image importing
* MD5 duplicate detection
* Automatic alt text generation
* Optional image compression
* Import progress tracking
* Detailed import logs
* Privacy-first design

== Upgrade Notice ==

= 1.0.7 =

* Incresaed realiablility and effeciency
* Maintained WordPress.org compliance


= 1.0.6=

* Fixed all the domain issues
* Recommended update for all users


= 1.0.5 =
* Added external services documentation
* Fixed variable prefix and text domain issues
* Recommended update for all users

= 1.0.4 =
* Added external services documentation
* Fixed all WordPress.org compliance issues
* Updated text domain to match plugin slug
* Added proper prefixes to all functions
* Recommended update for all users

= 1.0.3 =
* Major update for WordPress.org compliance
* Changed all prefixes from 'cai_' to 'mcai_'
* Improved security and sanitization
* No data migration needed for new installations

== Languages ==

* English (default)
* Translations welcome – uses WordPress translation system
* Translation files: `/languages/`
* Text Domain: michael-cloud-image-auto-importer

== Credits ==

**Developed by:** Michael Otieno (WordPress.org: mike17894)  
**Google API:** Uses official Google APIs PHP Client Library  
**Coding Standards:** Built following WordPress PHP, JS, and CSS coding standards  
**Privacy:** No third-party tracking, analytics, or external services except Google Drive API as documented  
**License:** 100% GPLv2+ compatible

== Support ==

For support, please use the WordPress.org support forum:  
https://wordpress.org/support/plugin/michael-cloud-image-auto-importer/