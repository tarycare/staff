<?php

/**
 * Plugin Name: Staff
 * Author: Husam Nasrallah
 * Author URI: https://github.com/tarycare
 * Version: 1.3.0
 * Description: Manage Staff Members
 * Text-Domain: staff
 * GitHub Plugin URI: tarycare/staff
 * GitHub Plugin URI: https://github.com/tarycare/staff
 */

if (!defined('ABSPATH')) : exit();
endif; // No direct access allowed.

// Configuration Variables
$config = [
    'plugin_name'          => 'Staff',                     // Plugin Name
    'plugin_slug'          => 'staff',                     // Plugin Slug
    'menu_title_en'        => 'Staff',                     // English Menu Title
    'menu_title_ar'        => 'الموظفين',                  // Arabic Menu Title
    'member_menu_title_en' => 'Members',                   // English Member Menu Title
    'member_menu_title_ar' => 'الأعضاء',                   // Arabic Member Menu Title
    'capability'           => 'read',                      // Capability Required
    'icon'                 => 'dashicons-admin-users',     // Menu Icon
    'position'             => 2,                           // Menu Position
    'app_script_handle'    => 'staff',            // Unique Script Handle
    'app_script_dev'       => 'http://localhost:3000/apps/staff/dist/staff.js',  // Dev Script URL
    'app_style_dev'        => 'http://localhost:3000/apps/staff/assets/staff.css',     // Dev Style URL
    'app_script_prod'      => 'dist/staff.js',             // Prod Script Path
    'app_style_prod'       => '../assets/style.css',       // Prod Style Path
    'dynamic_id'           => 'staff',        // Dynamic ID for React App Container
    'path_constant'        => 'PATH_STAFF',                // Dynamic Path Constant
    'url_constant'         => 'URL_STAFF',                 // Dynamic URL Constant
    'class_name'           => 'Staff_Admin_Page',          // Dynamic Class Name
];

/**
 * Dynamically Define Plugin Constants
 */
if (!defined($config['path_constant'])) {
    define($config['path_constant'], trailingslashit(plugin_dir_path(__FILE__)));
}
if (!defined($config['url_constant'])) {
    define($config['url_constant'], trailingslashit(plugin_dir_url(__FILE__)));
}

/**
 * Class for Creating Admin Pages
 */
if (!class_exists($config['class_name'])) {
    class Staff_Admin_Page
    {
        private $config;

        public function __construct($config)
        {
            $this->config = $config;

            add_action('init', [$this, 'allow_subscriber_access_plugins']);
            add_action('init', [$this, 'check_user_access']);
            add_action('admin_enqueue_scripts', [$this, 'load_scripts']);
        }

        public function allow_subscriber_access_plugins()
        {
            // Get the 'subscriber' role
            $role = get_role('subscriber');

            if ($role) {
                // Add a custom capability to the subscriber role
                $role->add_cap($this->config['plugin_slug']);
            }
        }

        public function check_user_access()
        {
            // Check if the current user has admin capabilities
            $is_admin = current_user_can('administrator');

            // Get user meta data
            $user_meta = get_user_meta(get_current_user_id());

            // Check if $user_meta is an array before accessing it
            $staff_access = '';
            if (is_array($user_meta) && isset($user_meta['staff_access'][0])) {
                $staff_access = $user_meta['staff_access'][0]; // Get the first value of the array
            }

            // Add the appropriate admin menus based on user role
            if ($is_admin || $staff_access === 'admin') {
                add_action('admin_menu', [$this, 'create_admin_menu']);
            } elseif ($staff_access === 'member') {
                add_action('admin_menu', [$this, 'create_member_menu']);
            }
        }

        public function create_admin_menu()
        {
            $capability = $this->config['capability'];
            $slug       = $this->config['plugin_slug'];

            // Get the current user's locale
            $locale = determine_locale();

            // Set the menu title based on the user's language
            $menu_title = ($locale === 'ar' || $locale === 'ar_AR') ? $this->config['menu_title_ar'] : $this->config['menu_title_en'];

            // Add main menu page
            add_menu_page(
                $menu_title,                          // Page title
                $menu_title,                          // Menu title
                $capability,                          // Capability
                $slug,                                // Menu slug
                [$this, 'menu_page_template'],        // Callback function
                $this->config['icon'],                // Icon
                $this->config['position']             // Position
            );

            // Add a submenu page for Admin
            add_submenu_page(
                $slug,                                // Parent slug
                'Admin',                              // Page title
                'Admin',                              // Menu title
                $capability,                          // Capability
                'admin',                              // Menu slug
                [$this, 'menu_page_template']         // Callback function
            );
        }

        public function create_member_menu()
        {
            $capability = $this->config['capability'];
            $slug       = $this->config['plugin_slug'];

            // Get the current user's locale
            $locale = determine_locale();

            // Set the menu title based on locale
            $menu_title = ($locale === 'ar' || $locale === 'ar_AR') ? $this->config['member_menu_title_ar'] : $this->config['member_menu_title_en'];

            // Add menu page for members
            add_menu_page(
                $menu_title,                          // Page title
                $menu_title,                          // Menu title
                $capability,                          // Capability
                $slug,                                // Menu slug
                [$this, 'menu_page_template'],        // Callback function
                $this->config['icon'],                // Icon
                $this->config['position']             // Position
            );
        }

        public function menu_page_template()
        {
            // Use the dynamic ID here
            echo '<div class="wrap">
                    <div id="' . esc_attr($this->config['dynamic_id']) . '"></div>
                  </div>';
        }

        public function load_scripts()
        {
            $is_dev = defined('WP_ENV') && WP_ENV === 'development';

            if ($is_dev) {
                // Load from Webpack Dev Server during development
                wp_enqueue_script($this->config['app_script_handle'], $this->config['app_script_dev'], ['jquery', 'wp-element'], wp_rand(), true);
                wp_enqueue_style($this->config['app_script_handle'] . '-style', $this->config['app_style_dev'], [], wp_rand());
            } else {
                // Load the production bundle from the plugin directory
                wp_enqueue_script($this->config['app_script_handle'], constant($this->config['url_constant']) . $this->config['app_script_prod'], ['jquery', 'wp-element'], wp_rand(), true);
                wp_enqueue_style($this->config['app_script_handle'] . '-style', constant($this->config['url_constant']) . $this->config['app_style_prod'], [], wp_rand());
            }

            // Localize script with handle and variable 'appLocalizer'
            wp_localize_script($this->config['app_script_handle'], 'appLocalizer', [
                'apiUrl' => home_url('/wp-json'),
                'nonce'  => wp_create_nonce('wp_rest'),
            ]);
        }
    }

    // Instantiate the class with the configuration
    new $config['class_name']($config);
}

/**
 * Include additional plugin files
 */
require_once constant($config['path_constant']) . 'includes/api.php';
