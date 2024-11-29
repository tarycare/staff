<?php

/**
 * Plugin Name: Tary Platform
 * Description: A plugin to manage all the Tary's Platform.
 * Version: 1.0.4
 * Author: Husam Nasrallah
 * Text-Domain: platform
 * GitHub Plugin URI: tarycare/platform
 * GitHub Plugin URI: https://github.com/tarycare/platform
 */

// Define sub-plugins
$sub_plugins = [
    'staff' => [
        'label' => 'Staff Plugin',
        'dev_path' => 'dev-apps/staff/plugin.php',
        'prod_path' => 'apps/staff/plugin.php',
        'option_name' => 'tary_plugins_staff'
    ],
    'department' => [
        'label' => 'Department Plugin',
        'dev_path' => 'dev-apps/department/plugin.php',
        'prod_path' => 'apps/department/plugin.php',
        'option_name' => 'tary_plugins_department'
    ],
    'facilities' => [
        'label' => 'Facilities Plugin',
        'dev_path' => 'dev-apps/facilities/plugin.php',
        'prod_path' => 'apps/facilities/plugin.php',
        'option_name' => 'tary_plugins_facilities'
    ],
    'equipment' => [
        'label' => 'Equipment Plugin',
        'dev_path' => 'dev-apps/equipment/plugin.php',
        'prod_path' => 'apps/equipment/plugin.php',
        'option_name' => 'tary_plugins_equipment'
    ],
    'material' => [
        'label' => 'Material Plugin',
        'dev_path' => 'dev-apps/material/plugin.php',
        'prod_path' => 'apps/material/plugin.php',
        'option_name' => 'tary_plugins_material'
    ],
    'forms' => [
        'label' => 'Forms Plugin',
        'dev_path' => 'dev-apps/forms/plugin.php',
        'prod_path' => 'apps/forms/plugin.php',
        'option_name' => 'tary_plugins_forms'
    ],
    'documents' => [
        'label' => 'Documents Plugin',
        'dev_path' => 'dev-apps/documents/plugin.php',
        'prod_path' => 'apps/documents/plugin.php',
        'option_name' => 'tary_plugins_documents'
    ],
    'submissions' => [
        'label' => 'Submittions Plugin',
        'dev_path' => 'dev-apps/submissions/plugin.php',
        'prod_path' => 'apps/submissions/plugin.php',
        'option_name' => 'tary_plugins_submittions'
    ],
];

// Add menu to manage sub-plugins
add_action('admin_menu', 'tary_plugins_menu');

function tary_plugins_menu()
{
    // Get the current user's locale
    $locale = determine_locale();
    $menu_title = ($locale === 'ar' || $locale === 'ar_AR') ? 'منصة طاري' : 'Tary platform';

    add_menu_page(
        $menu_title,
        $menu_title,
        'manage_options',
        'tary_plugins',
        'tary_plugins_page',
        'dashicons-admin-generic',
        3
    );
}

// Display the settings page
function tary_plugins_page()
{
?>
    <div class="wrap">
        <h1>Tary Clinic Plugins</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('tary_plugins_options');
            do_settings_sections('tary_plugins');
            submit_button();
            ?>
        </form>
    </div>
<?php
}

// Register settings and sections dynamically
add_action('admin_init', 'tary_plugins_settings');
function tary_plugins_settings()
{
    global $sub_plugins;

    foreach ($sub_plugins as $plugin_key => $plugin) {
        register_setting('tary_plugins_options', $plugin['option_name']);
        add_settings_section(
            'tary_plugins_section',
            'Sub Plugins',
            null,
            'tary_plugins'
        );
        add_settings_field(
            $plugin['option_name'],
            $plugin['label'],
            function () use ($plugin) {
                $option = get_option($plugin['option_name'], 0);
                echo '<input type="checkbox" name="' . $plugin['option_name'] . '" ' . checked(1, $option, false) . ' value="1" />';
            },
            'tary_plugins',
            'tary_plugins_section'
        );
    }
}

// Set default sub-plugin activation state on main plugin activation
register_activation_hook(__FILE__, 'tary_plugins_activate');
function tary_plugins_activate()
{
    global $sub_plugins;

    foreach ($sub_plugins as $plugin_key => $plugin) {
        if (get_option($plugin['option_name']) === false) {
            update_option($plugin['option_name'], 1); // Set to active by default
        }
    }
}

// Load sub-plugins after all plugins are initialized
add_action('plugins_loaded', 'tary_plugins_load_subplugins');
function tary_plugins_load_subplugins()
{
    global $sub_plugins;

    foreach ($sub_plugins as $plugin) {
        if (get_option($plugin['option_name'], 1)) { // Default to active
            // Get the correct plugin path (production or development)
            $plugin_path = defined('WP_ENV') && WP_ENV === 'development'
                ? plugin_dir_path(__FILE__) . $plugin['dev_path']
                : plugin_dir_path(__FILE__) . $plugin['prod_path'];

            // Check if the file exists before requiring it
            if (file_exists($plugin_path)) {
                require_once $plugin_path;
            } else {
                error_log("Plugin file not found: " . $plugin_path);
            }
        }
    }
}

// Handle plugin activation and deactivation dynamically
foreach ($sub_plugins as $plugin_key => $plugin) {
    add_action('update_option_' . $plugin['option_name'], function ($old_value, $new_value) use ($plugin) {
        $plugin_path = defined('WP_ENV') && WP_ENV === 'development'
            ? $plugin['dev_path']
            : $plugin['prod_path'];

        $plugin_basename = plugin_basename(plugin_dir_path(__FILE__) . $plugin_path);

        // Activate or deactivate based on the option value
        if ($new_value == 1 && !is_plugin_active($plugin_basename)) {
            activate_plugin($plugin_basename);
        } elseif ($new_value == 0 && is_plugin_active($plugin_basename)) {
            deactivate_plugins($plugin_basename);
        }
    }, 10, 2);
}


// Remove custom post types "Departments", "Facilities", "Forms", default post types "Posts", "Pages", "Documents", "Comments", "Media", and "Appearance"
function remove_custom_post_type_menus()
{
    // Default post types
    remove_menu_page('edit.php'); // Removes "Posts"
    remove_menu_page('edit.php?post_type=page'); // Removes "Pages"
    // remove_menu_page('edit.php?post_type=document'); // Removes "Documents"
    remove_menu_page('upload.php'); // Removes "Media"
    remove_menu_page('edit-comments.php'); // Removes "Comments"
    remove_menu_page('themes.php'); // Removes "Appearance"
    // remvoe dashboard
    remove_menu_page('index.php'); // Removes "Dashboard"

    // Custom post types
    remove_menu_page('edit.php?post_type=department'); // Removes "Departments"
    remove_menu_page('edit.php?post_type=facility');   // Removes "Facilities"
    remove_menu_page('edit.php?post_type=form');       // Removes "Forms"
    remove_menu_page('edit.php?post_type=submission');       // Removes "Forms"
    remove_menu_page('edit.php?post_type=equipment');       // Removes "Forms"
    remove_menu_page('edit.php?post_type=material');       // Removes "Forms"
    // remove_menu_page('edit.php?post_type=document');       // Removes "Documents"
    remove_menu_page('edit.php?post_type=comment');       // Removes "Comments"

}
add_action('admin_menu', 'remove_custom_post_type_menus');

// Remove meta boxes related to Posts, Pages, Documents, Departments, Facilities, Forms, and Comments
function remove_custom_meta_boxes()
{
    // Default post types
    remove_meta_box('categorydiv', 'post', 'side'); // Remove categories for "Posts"
    remove_meta_box('tagsdiv-post_tag', 'post', 'side'); // Remove tags for "Posts"
    remove_meta_box('postimagediv', 'post', 'side'); // Remove featured image for "Posts"
    remove_meta_box('commentsdiv', 'post', 'normal'); // Remove comments for "Posts"
    remove_meta_box('commentstatusdiv', 'post', 'normal'); // Remove comment status for "Posts"

    // Custom post types
    remove_meta_box('departmentdiv', 'department', 'side'); // Remove "Departments" meta boxes
    remove_meta_box('facilitydiv', 'facility', 'side');     // Remove "Facilities" meta boxes
    remove_meta_box('formdiv', 'form', 'side');             // Remove "Forms" meta boxes
    // remove_meta_box('documentdiv', 'document', 'side');     // Remove "Documents" meta boxes
    remove_meta_box('submissiondiv', 'submission', 'side');     // Remove "Documents" meta boxes
    remove_meta_box('equipmentdiv', 'equipment', 'side');     // Remove "Documents" meta boxes
    remove_meta_box('materialdiv', 'material', 'side');     // Remove "Documents" meta boxes
}
add_action('admin_menu', 'remove_custom_meta_boxes');

// Remove "New" items from the admin bar for Posts, Pages, Documents, Departments, Facilities, Forms, Media, and Comments
function remove_custom_post_type_admin_bar_links($wp_admin_bar)
{
    // Default post types
    $wp_admin_bar->remove_node('new-post');     // Removes "New Post"
    $wp_admin_bar->remove_node('new-page');     // Removes "New Page"
    // $wp_admin_bar->remove_node('new-document'); // Removes "New Document"
    $wp_admin_bar->remove_node('new-media');    // Removes "New Media"

    // Custom post types
    $wp_admin_bar->remove_node('new-department'); // Removes "New Department"
    $wp_admin_bar->remove_node('new-facility');   // Removes "New Facility"
    $wp_admin_bar->remove_node('new-form');       // Removes "New Form"
    $wp_admin_bar->remove_node('new-submission');       // Removes "New Form"
    $wp_admin_bar->remove_node('new-equipment');       // Removes "New Form"
    $wp_admin_bar->remove_node('new-material');       // Removes "New Form"


}
add_action('admin_bar_menu', 'remove_custom_post_type_admin_bar_links', 999);

// Redirect access to post type pages for Posts, Pages, Documents, Departments, Facilities, Forms, Media, Comments, and Appearance to the admin dashboard
function redirect_custom_post_type_pages()
{
    global $pagenow;

    if ($pagenow == 'edit.php' && isset($_GET['post_type'])) {
        $post_type = $_GET['post_type'];

        // Redirect for default and custom post types
        if (in_array($post_type, ['post', 'page', 'department', 'facility', 'form', 'media', 'comment', 'appearance', 'submission', 'equipment', 'material'])) {
            wp_redirect(admin_url());
            exit;
        }
    }
}
add_action('admin_init', 'redirect_custom_post_type_pages');


// Disable the top admin bar
add_filter('show_admin_bar', '__return_false');

// Hide the admin bar with CSS (for all users)
function hide_admin_bar_css()
{
    echo '<style>
        #wpadminbar { display: none !important; }
        html { margin-top: 0 !important; }
    </style>';
}
// add_action('admin_head', 'hide_admin_bar_css');

// Add a logout link in the sidebar
function add_logout_link_to_sidebar()
{
    add_menu_page(
        'Logout',                   // Page title
        'Logout',                   // Menu title
        'read',                     // Capability
        wp_logout_url(),            // URL to logout
        '',                         // No callback function, direct URL link
        'dashicons-external',       // Dashicon for logout
    );
}
add_action('admin_menu', 'add_logout_link_to_sidebar');



// Add custom CSS and HTML for the logo at the top of the admin sidebar
function add_logo_to_admin_sidebar()
{
    echo '
    <style>
        #adminmenuwrap::before {
            content: "";
            display: block;

            height: 40px; /* Adjust based on logo size */
            width: 60%;
            background: url("' . plugins_url('/apps/assets/tarylogo.svg', __FILE__) . '") no-repeat center;
            background-size: contain;
            margin-bottom: 20px;
               margin:10px


        }
            #adminmenu, #adminmenu .wp-submenu, #adminmenuback, #adminmenuwrap {
    width: 175px;
    background-color: #f7f7f7;
    color: #444;
    border-right: 1px solid #f1f1f1;

}




#adminmenu .wp-has-current-submenu .wp-submenu .wp-submenu-head, #adminmenu .wp-menu-arrow, #adminmenu .wp-menu-arrow div, #adminmenu li.current a.menu-top, #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu {
    background: #7b39ed;
    color: #fff;
}
    #adminmenu a {
    display: block;
    line-height: 1.3;
    padding: 2px 5px;
    color: #000;
}

#adminmenu .wp-has-current-submenu .wp-submenu .wp-submenu-head, #adminmenu .wp-menu-arrow, #adminmenu .wp-menu-arrow div, #adminmenu li.current a.menu-top, #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu {
    background: #7b39ed;
    color: #fff;
}
#adminmenu .current div.wp-menu-image:before, #adminmenu .wp-has-current-submenu div.wp-menu-image:before, #adminmenu a.current:hover div.wp-menu-image:before, #adminmenu a.wp-has-current-submenu:hover div.wp-menu-image:before, #adminmenu li.wp-has-current-submenu a:focus div.wp-menu-image:before, #adminmenu li.wp-has-current-submenu.opensub div.wp-menu-image:before, #adminmenu li.wp-has-current-submenu:hover div.wp-menu-image:before {
    color: #fff;
}
    #adminmenu .wp-submenu a {
    color: #000;

    font-size: 13px;
    line-height: 1.4;
    margin: 0;
    padding: 5px 0;
}
    #adminmenu .opensub .wp-submenu li.current a, #adminmenu .wp-submenu li.current, #adminmenu .wp-submenu li.current a, #adminmenu .wp-submenu li.current a:focus, #adminmenu .wp-submenu li.current a:hover, #adminmenu a.wp-has-current-submenu:focus+.wp-submenu li.current a {
    color: #000;
}
#adminmenu div.wp-menu-image:before {
    color: #7b39ed;
}

#adminmenu li.menu-top:hover, #adminmenu li.opensub>a.menu-top, #adminmenu li>a.menu-top:focus {
    position: relative;
    background-color: #f1f1f1;
    color: #000;

}
    #adminmenu li.menu-top:hover, #adminmenu li.opensub>a.menu-top, #adminmenu li>a.menu-top:focus {
    position: relative;
    color: #000;
}

#adminmenu .wp-submenu a:focus, #adminmenu .wp-submenu a:hover, #adminmenu a:hover, #adminmenu li.menu-top>a:focus {
    color: #000;
}
#adminmenu li a:focus div.wp-menu-image:before, #adminmenu li.opensub div.wp-menu-image:before, #adminmenu li:hover div.wp-menu-image:before {
    color: #000;
}

.wrap {
    margin: 10px 20px 0 2px;
    padding: 20px;
}

    </style>';
}
add_action('admin_head', 'add_logo_to_admin_sidebar');


// remove footer 
// Remove the default WordPress admin footer text
function remove_admin_footer_text()
{
    return '';
}
add_filter('admin_footer_text', 'remove_admin_footer_text');

// Remove the WordPress version from the footer
function remove_admin_footer_version()
{
    return '';
}
add_filter('update_footer', 'remove_admin_footer_version', 999);



// open ai api
add_action('rest_api_init', function () {
    register_rest_route('openai/v1', '/fetch', array(
        'methods' => 'POST', // Change to POST
        'callback' => 'fetch_openai_data',
        'permission_callback' => '__return_true', // Allow all users to access this endpoint
    ));
});

function fetch_openai_data(WP_REST_Request $request)
{
    $url = 'https://api.openai.com/v1/chat/completions';
    $OPENAI_API_KEY = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';

    // Extract parameters from the request
    $model = $request->get_param('model');
    $messages = $request->get_param('messages');
    $temperature = $request->get_param('temperature');

    $args = array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $OPENAI_API_KEY,
        ),
        'body' => json_encode(array(
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
        )),
        'timeout' => 60,
    );

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        error_log('OpenAI API request failed: ' . $response->get_error_message());
        return new WP_Error('openai_error', 'Failed to fetch data from OpenAI', array('status' => 500));
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        error_log('OpenAI API returned status code: ' . $status_code);
        return new WP_Error('openai_error', 'OpenAI API returned an error', array('status' => $status_code));
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON decode error: ' . json_last_error_msg());
        return new WP_Error('json_error', 'Failed to parse JSON response', array('status' => 500));
    }

    return rest_ensure_response($data);
}
