=== Aliyun OSS Upload ===
Contributors: karrychow
Tags: aliyun, oss, upload, media, cloud storage
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Upload files to Aliyun OSS (Object Storage Service) as WordPress media library storage, with enhanced OSS protocol wrapper and full native image editing functions.

== Description ==

This plugin allows you to upload files directly to Aliyun OSS (Alibaba Cloud Object Storage Service) instead of storing them locally on your server. It seamlessly integrates with the WordPress Media Library.

**Features**

* Remote image auto upload support
* Large file multipart upload support
* Backup mode for easier switching
* Original protocol wrapper supports all native upload functions
* Support for changing default image editor class
* Auto identify and generate WEBP format

**External Service Disclosure**

This plugin connects to the Alibaba Cloud Object Storage Service (Aliyun OSS) to store and retrieve your media files.
*   **Service Name:** Alibaba Cloud OSS
*   **Service URL:** https://www.alibabacloud.com/product/oss
*   **Data Transmitted:** Media files (images, documents, etc.) uploaded to your WordPress Media Library are transmitted to and stored in your configured Aliyun OSS bucket.
*   **Terms of Service:** https://www.alibabacloud.com/help/en/legal/latest/alibaba-cloud-international-website-terms-of-use
*   **Privacy Policy:** https://www.alibabacloud.com/help/en/legal/latest/alibaba-cloud-international-website-privacy-policy

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Settings -> Aliyun OSS Upload to configure your OSS credentials and bucket information.

== Screenshots ==

1. Settings Page

== Changelog ==

= 1.0.0 =
* Initial release.
* Updated SDK to v2.0.4.
