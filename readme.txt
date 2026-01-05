=== Aliyun OSS Upload ===

Contributors: Karry, xiaomac
Donate link: https://github.com/karrychow/wp-aliyun-oss-upload
Tags: aliyun, oss, upload, media, storage
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 4.9.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload WordPress media files to Aliyun OSS with native image editing support and automatic remote image handling.

== Description ==

Use Aliyun OSS as WordPress media library attachment storage, supporting original enhanced OSS protocol wrapper and full native image editing and derivative functions.

### Features ###

* New support for remote image auto upload!
* New support for large file auto multipart upload
* New support for backup mode for easier switching
* Original protocol supports all native upload functions
* Support changing default image editor class
* Support auto identify and generate WEBP format
* With English settings explanation and demo

Note: Banner images and icons are from the official OSS website.

### More Info ###

[https://github.com/karrychow/wp-aliyun-oss-upload](https://github.com/karrychow/wp-aliyun-oss-upload)

== Installation ==

1. Upload the plugin folder to the "/wp-content/plugins/" directory of your WordPress site
2. Activate the plugin through the 'Plugins' menu in WordPress
3. See Settings -> Aliyun OSS Upload

== Frequently Asked Questions ==

None

== Screenshots ==

1. Settings

== Changelog ==

= 4.9.0 =
* Update to support WordPress 6.7
* Update to support Aliyun OSS SDK 2.7.2
* Update to support PHP 8.2

= 4.8.9 =
* Fixed an issue where some themes would cause severe errors

= 4.8.8 =
* Fixed core compatibility issues with pseudo-protocol wrappers

= 4.8.7 =
* Save remote images compatible with Gutenberg editor

= 4.8.6 =
* Support disabling thumbnails for high-definition resolutions

= 4.8.5 =
* Optimized directory upload and other functions to support repeated execution
* Fixed exceptions caused by unencoded image service parameters
* Fixed the problem that physical thumbnail mode only has large images

= 4.8.4 =
* Fixed the issue of double styles in grid mode

= 4.8.3 =
* Fixed the logic of remote upload and connection correction

= 4.8.2 =
* Enhanced compatibility of connection correction

= 4.8.1 =
* Fixed link correction compatible with published articles

= 4.8 =
* Fixed WP5.3 large image compression issues

= 4.7 =
* Added support for automatic renaming for remote uploads
* Added automatic redirection of attachments when OSS is disabled

= 4.6 =
* Added automatic renaming during upload
* Added black and white lists for remote images
* Enhanced compatibility for remote image uploads

= 4.5 =
* Predetermine before loading classes
* Cancel code obfuscation

= 4.4 =
* Simplified class file structure
* Restored code obfuscation

= 4.3.9 =
* Fixed an issue where uploading a local directory would lose the directory of the upload path

= 4.3.8 =
* Exclude image acceleration effects for crawlers
* Exclude compression styles for non-images
* Optimize remote image auto-save compatibility
* Optimize default full-image style
* Optimize thumbnail deletion function
* Fix error in exporting personal data

= 4.3.7 =
* Cancel code obfuscation

= 4.3.6 =
* Optimize remote preservation compatibility
* Do not enable auto compression under mobile

= 4.3.5 =
* Fix logic for precisely determining object existence

= 4.3.4 =
* Added remote image auto-save function
* Restored physical thumbnails for better theme compatibility
* Fixed and enhanced thumbnail regeneration tool
* Other small adjustments to interface and widgets

= 4.3.3 =
* Fix issue where full-size images do not carry parameters
* Fix issue where non-images carry parameters
* Other small adjustments to interface and translation

= 4.3.2 =
* Default style changed to separator and full-size style
* Support custom lazy load default address
* Support custom thumbnail quality parameters
* Optimize non-image upload logic

= 4.3.1 =
* Corrected animation style under default style setting

= 4.3 =
* Added Lazyload to the front end to increase loading speed
* Support setting special styles for GIF animation format
* Lower thumbnail quality in the background to increase loading speed

= 4.2.9 =
* Optimized an underlying file interface

= 4.2.8 =
* Fixed thumbnail deletion bug
* Fixed full-size image style bug

= 4.2.7 =
* Fixed a bug caused by local testing

= 4.2.6 =
* Added automatic browser recognition to generate WebP
* Added tool to regenerate thumbnail information
* Fixed traversal bugs in individual logic and tools

= 4.2.5 =
* Fixed issue where small images would be automatically deleted

= 4.2.4 =
* Fixed issue where non-images would be backed up locally

= 4.2.3 =
* Fixed issue where local would be inexplicably backed up
* Added a promotional link, thank you for your support

= 4.2.2 =
* Multisite support: sub-sites can be automatically inherited
* Fixed issue where default library does not support streams
* Simplified and adjusted some setting options

= 4.2.1 =
* Fixed directory traversal interface potentially returning null values
* Clean thumbnail function supports local and OSS
* Added small function to support synchronization of missing attachments

= 4.2 =
* Changed logic to default global takeover
* Fixed core issues to support indexing
* Automatic compatibility with stream and file uploads
* Simplified settings interface for positioning
* Comes with two powerful small functions

= 4.1.2 =
* Compatible with XMLRPC attachment upload

= 4.1.1 =
* Fixed OSS overwriting with same name issue

= 4.1 =
* Support adding new upload file types
* Compatible with domain protocol mismatch issues
* Other small optimizations

= 4.0.4 =
* Added error feedback and optimized code

= 4.0.3 =
* Continued to fix issues and optimize code

= 4.0.2 =
* Fixed issue of HTTP errors during upload

= 4.0.1 =
* Fixed issue where changing the system default upload path would cause upload errors

= 4.0 =
* Updated architecture to support automatic multipart for large files
* New backup mode supports redundancy and switching
* Additional logo reset function compatible with old attachments

= 3.6 =
* Physical thumbnails support original image suffixes
* Added cleanup flags when uninstalling the plugin
* Code optimization and translation correction

= 3.5 =
* Added support for custom style separators
* Fixed correction of modified attachment connection retrieval errors

= 3.4 =
* Added support for custom featured image sizes
* Added support for attachment connection replacement correction
* Fixed directory logic for deleting attachments

= 3.3 =
* Continued optimization for compatibility

= 3.2 =
* Continued optimization of compatibility logic
* Upload with default function

= 3.1 =
* Continued optimization of compatibility logic
* Delete thumbnails when modifying

= 3.0 =
* Optimize compatibility: automatically judge and mark file storage
* Original image suffix: responsive tag compatible style suffix
* Quick entry: Media library adds plugin setting entry

= 2.9 =
* Cancel hooks with compatibility issues for individual themes

= 2.8 =
* Fixed errors in uploading plugins and themes
* Added a hook for thumbnail themes

= 2.7 =
* Optimize logic
* Fix BUGs

= 2.6 =
* Optimize loading logic to be more concise and compatible

= 2.5 =
* Fixed: Time issue potentially caused by timezone definition in library files

= 2.4 =
* Optimization: Cancel loading mode settings

= 2.3 =
* Fixed: Media library supports grid browsing
* Fixed: Support browsing non-image attachments

= 2.2 =
* Optimization: Compatibility issues caused by changing thumbnail settings
* Fixed: An EMPTY error in old version of PHP

= 2.1 =
* Fixed: Perfectly compatible with uploaded media files

= 2.0 =
* Optimization: Perfect compatibility with non-global loading mode
* Added: Support full-size image default style suffix
* Added: Automatically delete thumbnails when deleting attachments

= 1.9 =
* Optimize loading logic and compatibility
* Fixed an error in the library file

= 1.8 =
* Added compatibility mode (greedy mode by default)

= 1.7 =
* Change loading logic to maximize compatibility with other plugins
* Plugin only takes effect on media library management and display
* Fixed issue of thumbnail failure without year-month subdirectory

= 1.6 =
* Fixed case of uploading plugins and themes

= 1.5 =
* Support image service
* Adjust background settings

= 1.4 =
* Added test function
* Endpoint default non-empty
* Default three-level domain name

= 1.3 =
* Support modifying the default image editor class
* Support prohibiting the generation of multi-size thumbnails
* Added translation and setting description

= 1.2 =
* Compatible with three-level domain names and regional settings

= 1.1 =
* Stand alone as a new one

= 1.0 =
* First release in Open Lazy
