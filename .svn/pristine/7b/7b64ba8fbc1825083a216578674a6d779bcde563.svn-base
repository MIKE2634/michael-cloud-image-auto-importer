=== Michael Cloud Image Auto Importer ===
Contributors: mike17894
Tags: woocommerce, bulk import, images compression,seo alt-text, duplicate-detection
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import images from Google Drive into the WordPress Media Library with optional Cloudflare Worker or Google API authentication.

== Description ==

Michael Cloud Image Auto Importer lets you import images from Google Drive straight into your WordPress Media Library.  
It is ideal for bloggers, photographers, and content creators who store their media in Google Drive.

The plugin connects securely to your Google Drive account - now with TWO authentication options:
1. **New:** One-click Cloudflare Worker connection (no API credentials needed!)
2. **Traditional:** Google API credentials (for advanced users)

**Key Features:**
* 🔐 **Optional Zero Configuration** – New Cloudflare Worker option - just click "Connect"!
* ☁️ **Cloudflare Worker Integration** – Secure OAuth without exposing credentials
* 🔑 **Traditional OAuth Support** – Use your own Google API credentials if preferred
* 📁 **Bulk Image Import** – Import entire Drive folders at once
* 🔍 **Smart Duplicate Detection** – MD5 file hashing to skip existing images
* 📝 **SEO-Friendly Alt Text** – Auto-generates from filenames (cat.jpg → "Cat")
* 🗜️ **Optional Compression** – JPEG/PNG/WebP compression with quality control
* 📊 **Real-Time Progress** – Live progress bar and statistics
* 📋 **Detailed Import Logs** – Success/failure rates for every import
* 🚀 **No Limits** – Import as many images as you need
* 🎨 **Clean Interface** – Intuitive WordPress admin design
* 🌍 **Multisite Compatible** – Works on WordPress Multisite networks

== External Services ==

**Option 1: Cloudflare Worker (New in 2.0.0)**
**Service:** Cloudflare Worker (Google OAuth Proxy)
**Purpose:** Securely handle Google OAuth authentication without storing credentials in WordPress
**Data Transmitted:**
- OAuth authorization codes
- Session tokens (temporary, stored in Cloudflare KV)
**Service Provider:** Cloudflare, Inc.
**Privacy Policy:** https://www.cloudflare.com/privacypolicy/

**Option 2: Direct Google API**
**Service:** Google Drive API
**Purpose:** Browse, list, and download images from your Google Drive
**Data Transmitted:**
- OAuth 2.0 authentication tokens
- File metadata (names, IDs, sizes, MIME types)
- MD5 hashes of files (for duplicate detection)
- File content when downloading images
**Service Provider:** Google LLC
**Terms:** https://developers.google.com/drive/terms
**Privacy:** https://policies.google.com/privacy

**User Consent:** By clicking "Connect Google Drive", you consent to connecting to these services.

== Features ==

* **Two Authentication Methods** – Choose Cloudflare Worker (zero-config) or traditional API credentials
* **Zero Configuration Option** – New Cloudflare Worker integration - no API credentials needed!
* **Traditional OAuth Support** – Use your own Google API credentials for full control
* **Bulk Image Import** – Import entire folders at once
* **Smart Duplicate Detection** – MD5 file hashing prevents duplicates
* **SEO Optimization** – Auto-generates alt text from filenames
* **Image Compression** – Optional compression with adjustable quality (50-95%)
* **Real-Time Progress Tracking** – Live progress bar and statistics
* **Detailed Import Logs** – Complete history of all imports
* **One-Click Disconnect** – Easily revoke access anytime
* **No Usage Limits** – Import unlimited images
* **Privacy First** – No tracking, no analytics

== How It Works ==

**Option A: Cloudflare Worker (Recommended - New!)**
1. Click "Connect Google Drive"
2. Authorize your Google account
3. Paste Drive folder URL and start import
4. Done! No API credentials needed!

**Option B: Traditional Google API**
1. Create Google Cloud Project
2. Enable Drive API and create OAuth credentials
3. Enter credentials in plugin settings
4. Connect and start importing

== Installation ==

**Quick Setup (Cloudflare Worker - New!):**
1. Upload plugin folder to `/wp-content/plugins/`
2. Activate through Plugins menu
3. Go to **Cloud Importer** page
4. Click **Connect Google Drive**
5. Authorize your Google account
6. Start importing images!

**Traditional Setup (Google API Credentials):**
1. Follow Google API Setup instructions below
2. Enter credentials in Settings page
3. Connect and import

== Google API Setup (Traditional Method) ==

1. **Create Google Cloud Project**
   * Visit https://console.cloud.google.com/
   * Create new project or select existing

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

= What's new in version 1.1.0? =
Cloudflare Worker integration! Now you can connect to Google Drive without entering any API credentials. Just click "Connect" and authorize!

= Do I need Google API credentials? =
No! With the new Cloudflare Worker option, you don't need any credentials. Just click "Connect Google Drive"!

= Can I still use my own Google API credentials? =
Yes. The traditional authentication method still works for advanced users who want full control.

= How does the Cloudflare Worker work? =
The worker acts as a secure proxy between WordPress and Google. It stores OAuth secrets in Cloudflare's secure environment, not in your WordPress database.

= Is my data secure with the worker? =
Yes. The worker uses Cloudflare's secure infrastructure. Images download directly to your server. No data passes through third parties except the required Google API connections.

= Is there a limit to how many images I can import? =
No. There are no usage or import limits.

= Can I import images from shared folders? =
Yes. Any Google Drive folder you have access to works.

= What image formats are supported? =
JPG, JPEG, PNG, GIF, WebP, BMP, TIFF, TIF, SVG, ICO.

= Are images compressed during import? =
Optional compression is available for JPG, PNG, and WebP images. Quality adjustable from 50-95%.

= How are duplicates detected? =
MD5 file hashing. Same hash = duplicate file = skipped.

= What happens when I uninstall? =
You can choose to keep or delete all plugin data (settings, logs, import metadata).

= Does this work on multisite? =
Yes. Fully multisite compatible.

= What if I want to use my own Cloudflare Worker? =
You can deploy your own worker and configure the plugin to use it. Contact support for details.

== Screenshots ==

1. **Main Interface** – Connect Google Drive and start imports
2. **Import Progress** – Real-time progress bar with live statistics
3. **Settings Page** – Simple data management options
4. **Import Logs** – Detailed history with success rates
5. **Setup Guide** – Quick start instructions

== Changelog ==

= 2.0.0 =

* **NEW:** Cloudflare Worker integration for optional zero-config authentication
* **NEW:** One-click Google Drive connection (no API credentials needed!)
* **NEW:** Added 15+ additional plugin tags for better discoverability
* **IMPROVED:** Existing credential-based authentication still fully supported
* **IMPROVED:** Better error messages for connection issues
* **IMPROVED:** Updated documentation for new workflow
* **SECURITY:** Optional external credential storage via Cloudflare
* **UX:** Simplified setup process for new users
* **COMPATIBILITY:** Fully backward compatible with version 1.0.x

= 1.0.7 =

* Increased reliability and efficiency
* Maintained WordPress.org compliance

= 1.0.6 =

* Fixed text domain to match plugin slug
* Updated all translation strings
* Improved WordPress.org compliance

= 1.0.5 =

* Added comprehensive external services documentation
* Fixed variable prefix consistency
* Improved WordPress.org compliance

= 1.0.4 =

* Added external services documentation
* Fixed prefix compliance: 'cai_' to 'mcai_'
* Fixed sanitization: wp_validate_boolean()
* Removed direct file inclusion
* Added WordPress.org username to Contributors
* Updated all AJAX actions to use mcai_ prefix
* Updated CSS/JS class names
* Fixed database table name prefix

= 1.0.3 =
* Renamed plugin to Michael Cloud Image Auto Importer

= 1.0.2 =
* Fixed PHPCS warnings and coding standards
* Added proper escaping for all output
* Improved nonce verification
* Enhanced error handling

= 1.0.1 =
* Fixed translation compatibility
* Improved alt text generation
* Enhanced compression controls
* Fixed duplicate detection

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.0 =
* **New feature!** Cloudflare Worker integration added. Now you can connect without API credentials! Traditional method still works. Recommended update for all users.

= 1.0.7 =
* Reliability improvements. Recommended update.

= 1.0.6 =
* Fixed all domain issues. Recommended update.

= 1.0.5 =
* Added external services documentation. Recommended update.

== Languages ==

* English (default)
* Translations welcome via WordPress.org
* Text Domain: michael-cloud-image-auto-importer

== Credits ==

**Developed by:** Michael Otieno (WordPress.org: mike17894)  
**Cloudflare Integration:** Cloudflare Workers for secure OAuth  
**Google API:** Official Google Drive API  
**Coding Standards:** WordPress PHP, JS, CSS standards  
**License:** 100% GPLv2+

== Support ==

WordPress.org support forum:  
https://wordpress.org/support/plugin/michael-cloud-image-auto-importer/