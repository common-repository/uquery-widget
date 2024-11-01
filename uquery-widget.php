<?php /*

**************************************************************************

Plugin Name:  uQuery Widget
Plugin URI:   http://www.uquery.com/goodies/widgets
Description:  Easily embed an iPhone or iPod Touch application details into your posts.
Version:      1.0.0
Author:       RADSense Software
Author URI:   http://www.radsense.com/

**************************************************************************

Copyright (C) 2006-2009 RADSense Software

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

**************************************************************************

*/
class UqueryWidget {
	var $version = '1.0.0';
	var $settings = array();
	var $defaultsettings = array();
	var $standardcss;
	var $cssalignments;
	var $wpheadrun = FALSE;
	var $adminwarned = FALSE;

	// Class initialization
	function UqueryWidget() {
		global $wp_db_version, $wpmu_version;

		// This version of uQuery Widget requires WordPress 2.6+
		if ( !function_exists('plugins_url') ) {
			load_plugin_textdomain( 'uquery-widget', '/wp-content/plugins/uquery-widget/localization' ); // Old format
			if ( isset( $_GET['activate'] ) ) {
				wp_redirect( 'plugins.php?deactivate=true' );
				exit();
			} else {
				// Replicate deactivate_plugins()
				$current = get_option('active_plugins');
				$plugins = array( 'uquery-widget/uquery-widget.php', 'uquery-widget.php' );
				foreach ( $plugins as $plugin ) {
					if( !in_array( $plugin, $current ) ) continue;
					array_splice( $current, array_search( $plugin, $current ), 1 );
				}
				update_option('active_plugins', $current);

				add_action( 'admin_notices', array(&$this, 'WPVersionTooOld') );

				return;
			}
		}

		// Redirect the old settings page to the new one for any old links
		if ( is_admin() && isset($_GET['page']) && 'uquery-widget.php' == $_GET['page'] ) {
			wp_redirect( admin_url( 'options-general.php?page=uquery-widget' ) );
			exit();
		}

		// For debugging (this is limited to localhost installs since it's not nonced)
		if ( !empty($_GET['resetalloptions']) && 'localhost' == $_SERVER['HTTP_HOST'] && is_admin() && 'uquery-widget' == $_GET['page'] ) {
			update_option( 'widget_options', array() );
			wp_redirect( admin_url( 'options-general.php?page=uquery-widget&defaults=true' ) );
			exit();
		}

		// Load up the localization file if we're using WordPress in a different language
		// Place it in this plugin's "localization" folder and name it "uquery-widget-[value in wp-config].mo"
		load_plugin_textdomain( 'uquery-widget', FALSE, '/uquery-widget/localization' );

		// Create default settings array
		$this->defaultsettings = apply_filters( 'widget_defaultsettings', array(
			'uquery' => array(
				'button'          => 1,
				'width'           => 425,
				'height'          => 344,			
			),
			
			'alignment'           => 'center',
			'tinymceline'         => 1,
			'customcss'           => '',
			'customfeedtext'      => '',
		) );

		// Setup the settings by using the default as a base and then adding in any changed values
		$usersettings = (array) get_option('widget_options');
		$this->settings = $this->defaultsettings;
		if ( $usersettings !== $this->defaultsettings ) {
			foreach ( (array) $usersettings as $key1 => $value1 ) {
				if ( is_array($value1) ) {
					foreach ( $value1 as $key2 => $value2 ) {
						$this->settings[$key1][$key2] = $value2;
					}
				} else {
					$this->settings[$key1] = $value1;
				}
			}
		}

		// Register general hooks
		add_action( 'admin_menu', array(&$this, 'RegisterSettingsPage') );
		add_filter( 'plugin_action_links', array(&$this, 'AddPluginActionLink'), 10, 2 );
		add_action( 'admin_post_widgetsettings', array(&$this, 'POSTHandler') );
		add_action( 'wp_head', array(&$this, 'Head') );
		add_action( 'admin_head', array(&$this, 'Head') );
		add_filter( 'widget_text', 'do_shortcode', 11 );

		if ( $wp_db_version < 8989 && 'update.php' == basename( $_SERVER['PHP_SELF'] ) && 'upgrade-plugin' == $_GET['action'] && FALSE !== strstr( $_GET['plugin'], 'uquery-widget' ) )


		// Register editor button hooks
		add_filter( 'tiny_mce_version', array(&$this, 'tiny_mce_version') );
		add_filter( 'mce_external_plugins', array(&$this, 'mce_external_plugins') );
		add_action( 'edit_form_advanced', array(&$this, 'AddQuicktagsAndFunctions') );
		add_action( 'edit_page_form', array(&$this, 'AddQuicktagsAndFunctions') );
		if ( 1 == $this->settings['tinymceline'] )
			add_filter( 'mce_buttons', array(&$this, 'mce_buttons') );
		else
			add_filter( 'mce_buttons_' . $this->settings['tinymceline'], array(&$this, 'mce_buttons') );

		// Register shortcodes
		add_shortcode( 'uquery', array(&$this, 'shortcode_uquery') );
	
		// Register scripts and styles
		if ( is_admin() ) {
			// Settings page only
			if ( isset($_GET['page']) && 'uquery-widget' == $_GET['page'] ) {
				add_action( 'admin_head', array(&$this, 'StyleTweaks' ) );
				wp_enqueue_script( array('jquery'), '1.2' );
				wp_enqueue_style( array(), '1.2', 'screen' );
			}

			// Editor pages only
			if ( in_array( basename($_SERVER['PHP_SELF']), apply_filters( 'widget_editor_pages', array('post-new.php', 'page-new.php', 'post.php', 'page.php') ) ) ) {
				add_action( 'admin_head', array(&$this, 'EditorCSS') );
				add_action( 'admin_footer', array(&$this, 'OutputjQueryDialogDiv') );

				// If old version of jQuery UI, then replace it to fix a bug with the UI core
				if ( $wp_db_version < 8601 ) {
					wp_deregister_script( 'jquery-ui-core' );
					wp_enqueue_script( 'jquery-ui-core', plugins_url('/uquery-widget/resources/jquery-ui/ui.core.js'), array('jquery'), '1.5.2' );
				}

				wp_enqueue_script( 'jquery-ui-draggable', plugins_url('/uquery-widget/resources/jquery-ui/ui.draggable.js'), array('jquery-ui-core'), '1.5.2' );
				wp_enqueue_script( 'jquery-ui-resizable', plugins_url('/uquery-widget/resources/jquery-ui/ui.resizable.js'), array('jquery-ui-core'), '1.5.2' );
				wp_enqueue_script( 'jquery-ui-dialog', plugins_url('/uquery-widget/resources/jquery-ui/ui.dialog.js'), array('jquery-ui-core'), '1.5.2' );
				wp_enqueue_style( 'widget-jquery-ui', plugins_url('/uquery-widget/resources/jquery-ui/widget-jquery-ui.css'), array(), $this->version, 'screen' );
			}
		}
		

		// Set up the CSS
		$this->cssalignments = array(
			'left' => 'margin: 10px auto 10px 0;',
			'center' => 'margin: 10px auto;',
			'right' => 'margin: 10px 0 10px auto;',
			'floatleft' => 'float: left;\n	margin: 10px 10px 10px 0;',
			'floatright' => 'float: right;\n	margin: 10px 0 10px 10px;',
		);
		$this->standardcss = '
		.widgetbox {
			display: block;
			max-width: 100%;
			visibility: visible !important;
			/* alignment CSS placeholder */
		}
		.widgetbox img {
			max-width: 100%;
			height: 100%;
		}
		.widgetbox object {
			max-width: 100%;
		}';

	}


	// This function gets called when the minimum WordPress version isn't met
	function WPVersionTooOld() {
		echo '<div class="error"><p>' . sprintf( __( 'This version of <strong>uQuery Widget</strong> requires WordPress 2.6 or newer. Please <a href="%1$s">upgrade</a>', 'uquery-widget' ), 'http://codex.wordpress.org/Upgrading_WordPress') . "</p></div>\n";
	}


	// Register the settings page that allows plugin configuration
	function RegisterSettingsPage() {
		add_options_page( __("uQuery Widget Configuration", 'uquery-widget'), __('uQuery Widget', 'uquery-widget'), 'manage_options', 'uquery-widget', array(&$this, 'SettingsPage') );
	}


	// Add a link to the settings page to the plugins list
	function AddPluginActionLink( $links, $file ) {
		static $this_plugin;
		
		if( empty($this_plugin) ) $this_plugin = plugin_basename(__FILE__);

		if ( $file == $this_plugin ) {
			$settings_link = '<a href="' . admin_url( 'options-general.php?page=uquery-widget' ) . '">' . __('Settings', 'uquery-widget') . '</a>';
			array_unshift( $links, $settings_link );
		}

		return $links;
	}


	// Break the browser cache of TinyMCE
	function tiny_mce_version( $version ) {
		return $version . '-vvq' . $this->version . 'line' . $this->settings['tinymceline'];
	}


	// Load the custom TinyMCE plugin
	function mce_external_plugins( $plugins ) {
		$plugins['uquerywidget'] = plugins_url('/uquery-widget/resources/tinymce3/editor_plugin.js');
		return $plugins;
	}


	// Add the custom TinyMCE buttons
	function mce_buttons( $buttons ) {
		array_push( $buttons, 'widgetUQuery' );
		return $buttons;
	}


	// Hide TinyMCE buttons the user doesn't want to see + some misc editor CSS
	function EditorCSS() {
		echo "<style type='text/css'>\n	#widget-precacher { display: none; }\n";

		// Attempt to match the dialog box to the admin colors
		$color = ( 'classic' == get_user_option('admin_color', $user_id) ) ? '#CFEBF7' : '#EAF3FA';
		$color = apply_filters( 'widget_titlebarcolor', $color ); // Use this hook for custom admin colors
		echo "	.ui-dialog-titlebar { background: $color; }\n";

		$buttons2hide = array();
		if ( 1 != $this->settings['uquery']['button'] )     $buttons2hide[] = 'widgetUQuery';
		
		echo '	.mce_widget' . implode( ', .mce_widget', $buttons2hide ) . " { display: none !important; }\n";

		echo "</style>\n";
	}


	// Add the old style buttons to the non-TinyMCE editor views and output all of the JS for the button function + dialog box
	function AddQuicktagsAndFunctions() {
		$types = array(
			'uquery'     => array(
				__('uQuery', 'uquery-widget'),
				__('Embed an app from uQuery', 'uquery-widget'),
				__('Please enter the URL at which the app can be viewed.', 'uquery-widget'),
				'http://www.uquery.com/apps/284882215',
			),	
		);

		$buttonhtml = $datajs = '';
		foreach ( $types as $type => $strings ) 
		{
			// HTML for quicktag button
			if ( 1 == $this->settings[$type]['button'] )
				$buttonshtml .= '<input type="button" class="ed_button" onclick="WidgetButtonClick(\'' . $type . '\')" title="' . $strings[1] . '" value="' . $strings[0] . '" />';

			// Create the data array
			$datajs .= "	WidgetData['$type'] = {\n";
			$datajs .= '		title: "' . $this->js_escape( ucwords( $strings[1] ) ) . '",' . "\n";
			$datajs .= '		instructions: "' . $this->js_escape( $strings[2] ) . '",' . "\n";
			$datajs .= '		example: "' . js_escape( $strings[3] ) . '"';
			if ( !empty($this->settings[$type]['width']) && !empty($this->settings[$type]['height']) ) {
				$datajs .= ",\n		width: " . $this->settings[$type]['width'] . ",\n";
				$datajs .= '		height: ' . $this->settings[$type]['height'];
			}
			$datajs .= "\n	};\n";
		}
		?>
				<script type="text/javascript">
				// <![CDATA[

					var WidgetData = {};
				<?php echo $datajs; ?>

				// Set default heights (IE sucks)
				if ( jQuery.browser.msie ) 
				{
					var WidgetDialogDefaultHeight = 289;
					var WidgetDialogDefaultExtraHeight = 125;
				} 
				else
				{
					var WidgetDialogDefaultHeight = 258;
					var WidgetDialogDefaultExtraHeight = 108;
				}
	
				// This function is run when a button is clicked. It creates a dialog box for the user to input the data.
				function WidgetButtonClick( tag ) {

					// Close any existing copies of the dialog
					WidgetDialogClose();

					// Calculate the height/maxHeight
					WidgetDialogHeight = WidgetDialogDefaultHeight;
					WidgetDialogMaxHeight = WidgetDialogDefaultHeight + WidgetDialogDefaultExtraHeight;
					

					// Open the dialog while setting the width, height, title, buttons, etc. of it
					var buttons = { "<?php echo js_escape('Okay', 'uquery-widget'); ?>": WidgetButtonOkay, "<?php echo js_escape('Cancel', 'uquery-widget'); ?>": WidgetDialogClose };
					var title = '<img src="<?php echo plugins_url('/uquery-widget/buttons/'); ?>' + tag + '.png" alt="' + tag + '" width="20" height="20" /> ' + WidgetData[tag]["title"];
					jQuery("#widget-dialog").dialog({ autoOpen: false, width: 750, minWidth: 750, height: WidgetDialogHeight, minHeight: WidgetDialogHeight, maxHeight: WidgetDialogMaxHeight, title: title, buttons: buttons, resize: WidgetDialogResizing });

					// Reset the dialog box incase it's been used before
					jQuery("#widget-dialog-slide-header").removeClass("selected");
					jQuery("#widget-dialog-input").val("");
					jQuery("#widget-dialog-tag").val(tag);

					// Set the instructions
					jQuery("#widget-dialog-message").html("<p>" + WidgetData[tag]["instructions"] + "</p><p><strong><?php echo js_escape( __('Example:', 'uquery-widget') ); ?></strong></p><p><code>" + WidgetData[tag]["example"] + "</code></p>");

					// Style the jQuery-generated buttons by adding CSS classes and add second CSS class to the "Okay" button
					jQuery(".ui-dialog button").addClass("button").each(function(){
						if ( "<?php echo js_escape('Okay', 'uquery-widget'); ?>" == jQuery(this).html() ) jQuery(this).addClass("button-highlighted");
					});

					// Hide the Dimensions box if we can't add dimensions
					if ( WidgetData[tag]["width"] ) {
						jQuery(".widget-dialog-slide").removeClass("hidden");
						jQuery("#widget-dialog-width").val(WidgetData[tag]["width"]);
						jQuery("#widget-dialog-height").val(WidgetData[tag]["height"]);
					} else {
						jQuery(".widget-dialog-slide").addClass("hidden");
						jQuery(".widget-dialog-dim").val("");
					}

					// Do some hackery on any links in the message -- jQuery(this).click() works weird with the dialogs, so we can't use it
					jQuery("#widget-dialog-message a").each(function(){
						jQuery(this).attr("onclick", 'window.open( "' + jQuery(this).attr("href") + '", "_blank" );return false;' );
					});

					// Show the dialog now that it's done being manipulated
					jQuery("#widget-dialog").dialog("open");

					// Focus the input field
					jQuery("#widget-dialog-input").focus();
				}

				// Close + reset
				function WidgetDialogClose() {
					jQuery(".ui-dialog").height(WidgetDialogDefaultHeight);
					jQuery("#widget-dialog").dialog("close");
				}

				// Callback function for the "Okay" button
				function WidgetButtonOkay() {

					var tag = jQuery("#widget-dialog-tag").val();
					var text = jQuery("#widget-dialog-input").val();
					var width = jQuery("#widget-dialog-width").val();
					var height = jQuery("#widget-dialog-height").val();

					if ( !tag || !text ) return WidgetDialogClose();

				 
					if ( width && height && ( width != WidgetData[tag]["width"] || height != WidgetData[tag]["height"] ) )
						var text = "[" + tag + ' width="' + width + '" height="' + height + '"]' + text + "[/" + tag + "]";
					else
						var text = "[" + tag + "]" + text + "[/" + tag + "]";
				

					if ( typeof tinyMCE != 'undefined' && ( ed = tinyMCE.activeEditor ) && !ed.isHidden() ) {
						ed.focus();
						if (tinymce.isIE)
							ed.selection.moveToBookmark(tinymce.EditorManager.activeEditor.windowManager.bookmark);

						ed.execCommand('mceInsertContent', false, text);
					} else
						edInsertContent(edCanvas, text);

					WidgetDialogClose();
				}

				// This function is called while the dialog box is being resized.
				function WidgetDialogResizing( test ) {
					if ( jQuery(".ui-dialog").height() > WidgetDialogHeight ) {
						jQuery("#widget-dialog-slide-header").addClass("selected");
					} else {
						jQuery("#widget-dialog-slide-header").removeClass("selected");
					}
				}

				// On page load...
				jQuery(document).ready(function()
				{
					// Add the buttons to the HTML view
					jQuery("#ed_toolbar").append('<?php echo $this->js_escape( $buttonshtml ); ?>');

					// Make the "Dimensions" bar adjust the dialog box height
					jQuery("#widget-dialog-slide-header").click(function(){
						if ( jQuery(this).hasClass("selected") ) {
							jQuery(this).removeClass("selected");
							jQuery(this).parents(".ui-dialog").animate({ height: WidgetDialogHeight });
						} else {
							jQuery(this).addClass("selected");
							jQuery(this).parents(".ui-dialog").animate({ height: WidgetDialogMaxHeight });
						}
					});

					// If the Enter key is pressed inside an input in the dialog, do the "Okay" button event
					jQuery("#widget-dialog :input").keyup(function(event){
						if ( 13 == event.keyCode ) // 13 == Enter
							WidgetButtonOkay();
					});

					// Make help links open in a new window to avoid loosing the post contents
					jQuery("#widget-dialog-slide a").each(function(){
						jQuery(this).click(function(){
							window.open( jQuery(this).attr("href"), "_blank" );
							return false;
						});
					});
				});
			// ]]>
			</script>			
			<?php
	}


	// Output the <div> used to display the dialog box
	function OutputjQueryDialogDiv() 
	{ 
		?>
<div class="hidden">
	<div id="widget-dialog">
		<div class="widget-dialog-content">
			<div id="widget-dialog-message"></div>
			<p><input type="text" id="widget-dialog-input" style="width:98%" /></p>
			<input type="hidden" id="widget-dialog-tag" />
		</div>
	</div>
</div>
<div id="widget-precacher">
	<img src="<?php echo plugins_url('/uquery-widget/resources/jquery-ui/images/333333_7x7_arrow_right.gif'); ?>" alt="" />
	<img src="<?php echo plugins_url('/uquery-widget/resources/jquery-ui/images/333333_7x7_arrow_down.gif'); ?>" alt="" />
</div>
<?php
	}

	// Handle the submits from the settings page
	function POSTHandler() {
		global $wpmu_version;

		// Capability check
		if ( !current_user_can('manage_options') )
			wp_die( __('Cheatin&#8217; uh?') );

		// Form nonce check
		check_admin_referer('uquery-widget');

		$usersettings = (array) get_option('widget_options');
		$defaults = FALSE;

		switch ( $_POST['widget-tab'] ) {
			case 'general':
				$fields = array( 'button', 'width', 'height', 'aspectratio', 'searchbox' );

				// Check for the defaults button, clear out all values on the page if pressed (which makes the defaults be used)
				if ( !empty($_POST['widget-defaults']) ) {
					foreach ( $this->defaultsettings as $type => $settings ) {
						if ( !is_array($this->defaultsettings[$type]) ) continue;
						foreach ( $fields as $setting ) {
							if ( isset($usersettings[$type][$setting]) )
								unset( $usersettings[$type][$setting] );
						}
					}

					$defaults = TRUE;
					break;
				}

				// Copy in the results of the form
				foreach ( $this->defaultsettings as $type => $settings ) {
					if ( !is_array($this->defaultsettings[$type]) ) continue;
					foreach ( $fields as $setting ) {
						if ( isset($_POST['widget'][$type][$setting]) )
							$usersettings[$type][$setting] = (int) $_POST['widget'][$type][$setting];
						else
							$usersettings[$type][$setting] = 0;

						// Width and height are required, clear if 0
						if ( 0 === $usersettings[$type][$setting] && in_array( $setting, array( 'width', 'height' ) ) )
							unset( $usersettings[$type][$setting] );
					}
				}

				break;
		}

		update_option( 'widget_options', $usersettings );

		// Redirect back to the place we came from
		$url = admin_url( 'options-general.php?page=uquery-widget&tab=' . urlencode($_POST['widget-tab']) );
		if ( TRUE == $defaults )
			$redirectto = add_query_arg( 'defaults', 'true', $url );
		else
			$redirectto = add_query_arg( 'updated', 'true', $url );

		wp_redirect( $redirectto );
	}


	// Some style tweaks for the settings page
	function StyleTweaks() { 
		?>
			<style type="text/css">
				.widefat td { vertical-align: middle; }
				.widgetwide { width: 98%; }
				.widgetnarrow { width: 65px; }
			
				#widget-help .widget-help-title {
					font-weight: bold;
					color: #2583ad;
				}
			</style>
	<?php
	}


	// Output the settings page
	function SettingsPage() {
		global $wpmu_version;

		$tab = ( !empty($_GET['tab']) ) ? $_GET['tab'] : 'general';

		if ( !empty($_GET['defaults']) ) : ?>
<div id="message" class="updated fade"><p><strong><?php _e('Settings for this tab reset to defaults.', 'uquery-widget'); ?></strong></p></div>
<?php endif; ?>

<div class="wrap">

	<ul class="subsubsub">
<?php
		$tabs = array(
			'help'        => __('Help', 'uquery-widget'),
		);
		$tabhtml = array();

		// If someone wants to remove a tab (for example on a WPMU intall)
		$tabs = apply_filters( 'widget_tabs', $tabs );

		$class = ( 'general' == $tab ) ? ' class="current"' : '';
		$tabhtml[] = '		<li><a href="' . admin_url( 'options-general.php?page=uquery-widget' ) . '"' . $class . '>' . __('General', 'uquery-widget') . '</a>';

		foreach ( $tabs as $stub => $title ) {
			$class = ( $stub == $tab ) ? ' class="current"' : '';
			$tabhtml[] = '		<li><a href="' . admin_url( 'options-general.php?page=uquery-widget&amp;tab=' . $stub ) . '"' . $class . ">$title</a>";
		}

		echo implode( " |</li>\n", $tabhtml ) . '</li>';
?>

	</ul>

	<form id="widgetsettingsform" method="post" action="admin-post.php" style="clear:both">

	<?php wp_nonce_field('uquery-widget'); ?>

	<input type="hidden" name="action" value="widgetsettings" />

	<script type="text/javascript">
	// <![CDATA[
		jQuery(document).ready(function() {
			// Show items that need to be hidden if Javascript is disabled
			// This is needed for pre-WordPress 2.7
			jQuery(".hide-if-no-js").removeClass("hide-if-no-js");

			// Confirm pressing of the "reset tab to defaults" button
			jQuery("#widget-defaults").click(function(){
				var areyousure = confirm("<?php echo js_escape( __("Are you sure you want to reset this tab's settings to the defaults?", 'uquery-widget') ); ?>");
				if ( true != areyousure ) return false;
			});
		});
	// ]]>
	</script>

<?php

	// Figure out which tab to output
	switch ( $tab ) {

		case 'help': ?>
	<script type="text/javascript">
	// <![CDATA[
		jQuery(document).ready(function(){
			jQuery("#widget-help").find("div").hide();
			jQuery(".widget-help-title").css('cursor', 's-resize').click(function(){
				jQuery(this).parent("li").children("div").slideToggle();
			});
			jQuery("#widget-showall").css('cursor', 'pointer').click(function(){
				jQuery("#widget-help").children("li").children("div").slideDown();
			});

			// Look for HTML anchor in URL and expand if found
			var anchor = self.document.location.hash.substring(1);
			if ( anchor ) {
				jQuery("#"+anchor).children("div").show();
				location.href = "#"+anchor; // Rescroll
			}

			jQuery(".expandolink").click(function(){
				var id = jQuery(this).attr("href").replace(/#/, "");
				jQuery("#"+id).children("div").show();
				location.href = "#"+anchor; // Rescroll
			});
		});
	// ]]>
	</script>

	<p id="widget-showall" class="hide-if-no-js"><?php _e('Click on a question to see the answer or click this text to expand all answers.', 'uquery-widget'); ?></p>

	<ul id="widget-help">
		<li id="widget-help-one">
			<p class="widget-help-title"><?php _e('Where do I get the application ID from to embed a uQuery widget?', 'uquery-widget'); ?></p>
			<div>
				<p><?php _e('You can find it in an iTunes link or while browsing on uQuery.com, check the URL, the number there is your application id.. ', 'uquery-widget'); ?></p>
				
			</div>
		</li>
		<li id="widget-help-two">
			<p class="widget-help-title"><?php _e('Where do I get the code from to embed an app?', 'uquery-widget'); ?></p>
			<div>
				<p><?php _e('When browsing to an application details on uquery.com, click on the "Get Embed Widget Code" link.', 'uquery-widget'); ?></p>				
			</div>
		</li>
		
	</ul>

<?php
			break; // End help

		default;
?>
	<!--p><?php _e('Click the above links to switch between tabs.', 'uquery-widget'); ?></p-->
	<br/>
	<input type="hidden" name="widget-tab" value="general" />

	<script type="text/javascript">
	// <![CDATA[
		jQuery(document).ready(function() {
			// Handle keeping the dimensions in the correct ratio
			jQuery(".widget-width").change(function(){
				if ( true != jQuery(this).parents("tr").find(".widget-aspectratio").attr("checked") ) return;
				var width = jQuery(this).val();
				var widthdefault = jQuery(this).parents("tr").find(".widget-width-default").val();
				if ( '' == width || 0 == width ) {
					width = widthdefault;
					jQuery(this).val(widthdefault);
				}
				jQuery(this).parents("tr").find(".widget-height").val( Math.round( width * ( jQuery(this).parents("tr").find(".widget-height-default").val() / widthdefault ) ) );
			});
			jQuery(".widget-height").change(function(){
				if ( true != jQuery(this).parents("tr").find(".widget-aspectratio").attr("checked") ) return;
				var height = jQuery(this).val();
				var heightdefault = jQuery(this).parents("tr").find(".widget-height-default").val();
				if ( '' == height || 0 == height ) {
					height = heightdefault;
					jQuery(this).val(heightdefault);
				}
				jQuery(this).parents("tr").find(".widget-width").val( Math.round( height * ( jQuery(this).parents("tr").find(".widget-width-default").val() / heightdefault ) ) );
			});

	// ]]>
	</script>
		
	
		<div>
			<input name="widget[uquery][searchbox]" type="checkbox" value="1"<?php checked($this->settings['uquery']['searchbox'], 1); ?> /> Include uQuery search box in the widget
		</div>
		
	<!--table class="widefat" style="text-align:center">
		<thead>
			<tr>
				<th scope="col" style="text-align:left"><?php _e('Media Type', 'uquery-widget'); ?></th>
				<th scope="col" style="text-align:center"><?php _e('Show Editor Button?', 'uquery-widget'); ?></th>
				<th scope="col" style="text-align:center"><?php _e('Default Width', 'uquery-widget'); ?></th>
				<th scope="col" style="text-align:center"><?php _e('Default Height', 'uquery-widget'); ?></th>
				<th scope="col" style="text-align:center"><?php _e('Keep Aspect Ratio?', 'uquery-widget'); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr class="alternate">
				<td style="text-align:left"><a href="http://www.uquery.com/"><?php _e('uQuery', 'uquery-widget'); ?></a></td>
				<td><input name="widget[uquery][button]" type="checkbox" value="1"<?php checked($this->settings['uquery']['button'], 1); ?> /></td>
				<td>
					<input name="widget[uquery][width]" class="widget-width" type="text" size="5" value="<?php echo $this->settings['uquery']['width']; ?>" />
					<input type="hidden" class="widget-width-default" value="<?php echo $this->defaultsettings['uquery']['width']; ?>" />
				</td>
				<td>
					<input name="widget[uquery][height]" class="widget-height" type="text" size="5" value="<?php echo $this->settings['uquery']['height']; ?>" />
					<input type="hidden" class="widget-height-default" value="<?php echo $this->defaultsettings['uquery']['height']; ?>" />
				</td>
				<td><input name="widget[uquery][aspectratio]" class="widget-aspectratio" type="checkbox" value="1"<?php checked($this->settings['uquery']['aspectratio'], 1); ?> /></td>
			</tr>
		
		</tbody>
	</table-->
<?php
			// End General tab
	}
?>

<?php if ( 'help' != $tab ) : ?>
	<p class="submit">
		<input type="submit" name="widget-submit" value="<?php _e('Save Changes', 'uquery-widget'); ?>" />
	</p>
<?php endif; ?>

	</form>
</div>

<?php
		/*
		echo '<pre>';
		print_r( get_option('widget_options') );
		echo '</pre>';
		*/
	}


	// Output the head stuff
	function Head() {
		$this->wpheadrun = TRUE;

		echo "<!-- uQuery Widget v" . $this->version . " | http://www.uquery.com/wordpress-plugins/uquery-widget/ -->\n<style type=\"text/css\">\n";

		$aligncss = str_replace( '\n', ' ', $this->cssalignments[$this->settings['alignment']] );
		$standardcss = $this->StringShrink( $this->standardcss );
		echo strip_tags( str_replace( '/* alignment CSS placeholder */', $aligncss, $standardcss ) );

		// WPMU can't use this to avoid them messing with the theme
		if ( empty($wpmu_version) ) echo ' ' . strip_tags( $this->StringShrink( $this->settings['customcss'] ) );

		echo "\n</style>\n";

		?>

<?php
	}


	// Replaces tabs, new lines, etc. to decrease the characters
	function StringShrink( $string ) {
		if ( empty($string) ) return $string;
		return preg_replace( "/\r?\n/", ' ', str_replace( "\t", '', $string ) );
	}


	// Conditionally output debug error text
	function error( $error ) {
		global $post;

		// If the user can't edit this post, then just silently fail
		if ( empty($post->ID) || ( 'post' == $post->post_type && !current_user_can( 'edit_post', $post->ID ) ) || ( 'page' == $post->post_type && !current_user_can( 'edit_page', $post->ID ) ) )
			return '';

		// But if this user is an admin, then display some helpful text
		return '<em>[' . sprintf( __('<strong>ERROR:</strong> %s', 'uquery-widget'), $error ) . ']</em>';
	}


	// Return a link to the post for use in the feed
	function postlink() {
		global $post;

		if ( empty($post->ID) ) return ''; // This should never happen (I hope)

		$text = ( !empty($this->settings['customfeedtext']) ) ? $this->settings['customfeedtext'] : '<em>' . __( 'Click here to view the embedded uQuery application.', 'uquery-widget' ) . '</em>';

		return apply_filters( 'widget_feedoutput', '<a href="' . get_permalink( $post->ID ) . '">' . $text . '</a>' );
	}


	// No-name attribute fixing
	function attributefix( $atts = array() ) {
		// Quoted value
		if ( 0 !== preg_match( '#=("|\')(.*?)\1#', $atts[0], $match ) )
			$atts[0] = $match[2];

		// Unquoted value
		elseif ( '=' == substr( $atts[0], 0, 1 ) )
			$atts[0] = substr( $atts[0], 1 );

		return $atts;
	}

	// Reverse the parts we care about (and probably some we don't) of wptexturize() which gets applied before shortcodes
	function wpuntexturize( $text ) {
		$find = array( '&#8211;', '&#8212;', '&#215;', '&#8230;', '&#8220;', '&#8217;s', '&#8221;', '&#038;' );
		$replace = array( '--', '---', 'x', '...', '``', '\'s', '\'\'', '&' );
		return str_replace( $find, $replace, $text );
	}


	// Handle uQuery shortcodes
	function shortcode_uquery( $atts, $content = '' ) {
		$content = $this->wpuntexturize( $content );

		// Handle WordPress.com shortcode format
		if ( isset($atts[0]) ) {
			$atts = $this->attributefix( $atts );
			$content = $atts[0];
			unset($atts[0]);
		}

		if ( empty($content) ) return $this->error( sprintf( __('No URL or application ID was passed to the %s BBCode', 'uquery-widget'), __('uQuery') ) );

		if ( is_feed() ) return $this->postlink();

		// Set any missing $atts items to the defaults
		$atts = shortcode_atts(array(
			'width'    => $this->settings['uquery']['width'],
			'height'   => $this->settings['uquery']['height'],
		), $atts);

		// Allow other plugins to modify these values (for example based on conditionals)
		$atts = apply_filters( 'widget_shortcodeatts', $atts, 'uquery' );

		// If a URL was passed
		if ( 'http://' == substr( $content, 0, 7 ) ) {		
		
				preg_match( '#http://(www.uquery|uquery|[A-Za-z]{2}.uquery)\.com/(apps)/([\w-]+)(.*?)#i', $content, $matches );

				if ( empty($matches) || empty($matches[3]) ) return $this->error( sprintf( __('Unable to parse URL, check for correct %s format', 'uquery-widget'), __('uQuery') ) );

				$embedpath = 'apps/' . $matches[3];
				$fallbacklink = 'http://www.uquery.com/apps/' . $matches[3];
				$fallbackcontent = '<img src="http://s3.amazonaws.com/uquery.images/appstore/icons/' . $matches[3] . '.jpg" alt="' . __('uQuery Preview Image', 'uquery-widget') . '" />';
			
		}
		// If a URL wasn't passed, assume a app ID was passed instead
		else {
			$embedpath = 'apps/' . $content;
			$fallbacklink = 'http://www.uquery.com/apps/' . $content;
			$fallbackcontent = '<img src="http://s3.amazonaws.com/uquery.images/appstore/icons/' . $content . '.jpg" alt="' . __('uQuery Preview Image', 'uquery-widget') . '" />';
		}


		$objectid = uniqid('widget');

/*		return '<span class="widgetbox widgetuquery" style="width:' . $atts['width'] . 'px;height:' . $atts['height'] . 'px;"><span id="' . $objectid . '"><a href="' . $fallbacklink . '">' . $fallbackcontent . '</a>HELLOOOOOOOOOOOOO</span></span>';
		*/
		return '<div class="uquery_widget"><div class="uquery_container"><script src="http://www.uquery.com/javascripts/widget.js?ref=wp&search=' .$this->settings['uquery']['searchbox']. '" type="text/javascript"></script><script src="' . $fallbacklink . '.js" type="text/javascript"></script><div class="uquery_footer">Information provided by <a href="http://www.uquery.com/">uQuery</a></div></div></div>';
	}


	// WordPress' js_escape() won't allow <, >, or " -- instead it converts it to an HTML entity. This is a "fixed" function that's used when needed.
	function js_escape($text) {
		$safe_text = addslashes($text);
		$safe_text = preg_replace('/&#(x)?0*(?(1)27|39);?/i', "'", stripslashes($safe_text));
		$safe_text = preg_replace("/\r?\n/", "\\n", addslashes($safe_text));
		$safe_text = str_replace('\\\n', '\n', $safe_text);
		return apply_filters('js_escape', $safe_text, $text);
	}


	// This function always return FALSE (who woulda guessed?)
	function ReturnFalse() { return FALSE; }
}

// Start this plugin once all other plugins are fully loaded
add_action( 'plugins_loaded', 'UqueryWidget' ); function UqueryWidget() { global $UqueryWidget; $UqueryWidget = new UqueryWidget(); }

?>