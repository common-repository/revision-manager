=== CF Revision Manager ===
Contributors: crowdfavorite, alexkingorg
Tags: postmeta, custom fields, revisions, revision
Requires at least: 3.1
Tested up to: 3.1
Stable tag: 1.0

Add versioning to registered post meta fields when post revisions are made. This is currently a developer library, not an end-user plugin.


== Description ==

The post-meta is duplicated and attached to the specific post-revision that is made. Registered post meta items will appear in the post-revision inspection screen. Post meta items do not show up in the post-comparison feature.


== Installation ==

## Registering Your Post Meta

The plugin includes a registration function to easily include your post-meta in the revision scheme.

	function my_registered_post_meta_item() {
		if (function_exists('cfr_register_metadata')) {
			cfr_register_metadata('my_metadata_key');
		}
	}
	add_action('init', 'my_registered_post_meta_item');
	
## Prettifying Your Post Meta

By default the post meta is run through `print_r` (if its an object or array) and then through `htmlspecialchars`. Register a callback function along with your post meta key to override the default display of your post meta in the revision screen.

It's a 2 step process. Step 1: create your function to format your output:

	function prettify_my_postmeta($postmeta) {
		// make the post meta data presentable
		return $postmeta;
	}

Step 2: add the function as a handler for your desired post meta key. It's a best practice to wrap this in an additional hook to fire at 'init' because this can be added in any plugin or theme and we want to make sure that the Revision Manager has been loaded first.

	function my_registered_post_meta_item() {
		if (function_exists('cfr_register_metadata')) {
			cfr_register_metadata('my_metadata_key', 'prettify_my_postmeta');
		}
	}
	add_action('init', 'my_registered_post_meta_item');

== Frequently Asked Questions ==

= Are there plans to make this an end-user friendly plugin? =

Yes, we will likely add some UI to this in the future so that individual site owners can choose what post meta should be versioned. However we wanted to go ahead and release this version now so that other developers could benefit from it.


== Changelog ==

= 1.0 =

- wrapping entire plugin in `class_exists` check since the primary use case at this time is to use it as a library

= 0.9 =

- various bug fixes and stability enhancements
- first internal tag
