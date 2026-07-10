=== Exportación Selectiva ===
Contributors: arielhf
Donate link: https://arielhf.cl
Tags: export, import, migration, content, pages
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Selectively export and import pages, posts, and custom post types from the WordPress admin list tables.

== Description ==

**Exportación Selectiva** lets you export and import WordPress content directly from the admin screens you already use every day.

Author: Ariel Hernández Friz — https://arielhf.cl — hola@arielhf.cl

= Export =

1. Go to the Posts, Pages, or any compatible custom post type list.
2. Select the items you want to export.
3. In **Bulk actions**, choose **Export**.
4. Download the generated `.wpcontent` file.

= Import =

1. On the same list screen, click **Import**.
2. Upload a `.wpcontent` file.
3. Review the detected content.
4. Choose which items to import and how to resolve conflicts.

= What the package includes =

* Posts, pages, and exportable custom post types
* Metadata (`postmeta`)
* Taxonomies and terms
* Featured image and related attachments
* Media files embedded in the package

= Conflict policies =

* Skip
* Update
* Duplicate

= Compatibility =

* WordPress 6.9 or higher
* PHP 7.4 or higher
* Basic Gutenberg content

This plugin does not send data to external services. All export and import processing happens on your WordPress site.

**Development**

Source development repository: https://github.com/ahernandezfriz/exportacion-selectiva

== Installation ==

1. Upload the `exportacion-selectiva` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu.
3. Use **Export** in Bulk actions or **Import** on the content list screen.

== Frequently Asked Questions ==

= Does the file include images? =

Yes. The `.wpcontent` package includes media files detected as dependencies of the exported content.

= Can I import only some items from the package? =

Yes. The import wizard lets you select which items to import.

= Does it work with Elementor or Divi? =

Version 1.0.0 includes basic support for metadata and Gutenberg. Advanced support for page builders will be added in later versions.

= Do I need the WordPress XML exporter? =

No. This plugin uses its own `.wpcontent` format based on compressed JSON.

== Screenshots ==

1. Bulk Export action on the pages list.
2. Import button on the content list screen.
3. Import wizard with item selection.

== Changelog ==

= 1.0.0 =
* Initial release.
* Selective export from bulk actions.
* Import wizard with conflict policies.
* `.wpcontent` format version 1.0.

== Upgrade Notice ==

= 1.0.0 =
First public release of the plugin.
