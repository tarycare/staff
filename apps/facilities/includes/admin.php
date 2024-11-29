<?php
class WPRK_Create_Admin_Page
{
    public function __construct()
    {
        // Correct the reference to class method for the action hook
        add_action('init', [$this, 'allow_subscriber_access_plugins']);
        // Hook into 'init' to check user access and determine menu
        add_action('init', [$this, 'check_user_access']);
    }

    public function allow_subscriber_access_plugins()
    {
        // Get the 'subscriber' role
        $role = get_role('subscriber');

        if ($role) {
            // Add a custom capability for managing staff to the subscriber role
            $role->add_cap('staff');
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
        // Set a minimal capability
        $capability = 'read';
        $slug = 'staff';

        // Get the current user's locale
        $locale = determine_locale();

        // Set the menu title based on the user's language
        $menu_title = ($locale === 'ar' || $locale === 'ar_AR') ? 'الموظفين' : 'Staff';

        // Add main menu page
        add_menu_page(
            $menu_title,        // Page title
            $menu_title,        // Menu title
            $capability,        // Capability
            $slug,              // Menu slug
            [$this, 'menu_page_template'],  // Callback function
            'dashicons-admin-users',        // Icon
            3                   // Position
        );

        // Add a submenu page for Admin
        add_submenu_page(
            $slug,      // Parent slug
            'Admin',    // Page title
            'Admin',    // Menu title
            $capability, // Capability
            'admin',    // Menu slug
            [$this, 'menu_page_template']  // Callback function
        );
    }

    public function create_member_menu()
    {
        // Set a minimal capability, or use a custom capability check
        $capability = 'read';

        // Get the current user's locale
        $locale = determine_locale();
        $slug = 'staff';

        // Set the menu title based on locale
        $menu_title = ($locale === 'ar' || $locale === 'ar_AR') ? 'الأعضاء' : 'Members';

        // Add menu page for members
        add_menu_page(
            $menu_title,                // Page title
            $menu_title,                // Menu title
            $capability,                // Capability
            $slug,                      // Menu slug
            [$this, 'menu_page_template'],  // Callback function
            'dashicons-admin-users',    // Icon
            3                           // Position
        );
    }

    public function menu_page_template()
    {
        echo '<div class="wrap">
            <div id="wp-react-app"></div>
        </div>';
    }
}

new WPRK_Create_Admin_Page();
