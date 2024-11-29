<?php
class material_Create_Admin_Page
{
    public function __construct()
    {
        add_action('init', [$this, 'allow_subscriber_access_plugins']);
        add_action('init', [$this, 'check_user_access']);
    }

    public function allow_subscriber_access_plugins()
    {
        $role = get_role('subscriber');
        if ($role) {
            $role->add_cap('materials');
        }
    }

    public function check_user_access()
    {
        $is_admin = current_user_can('administrator');
        $user_meta = get_user_meta(get_current_user_id());
        $staff_access = '';
        if (is_array($user_meta) && isset($user_meta['staff_access'][0])) {
            $staff_access = $user_meta['staff_access'][0];
        }

        // Ensure the menu is added only once, even if both conditions apply
        if ($is_admin) {
            add_action('admin_menu', [$this, 'create_admin_menu']);
        } elseif ($staff_access === 'admin') {
            add_action('admin_menu', [$this, 'create_admin_menu']);
        } elseif ($staff_access === 'member') {
            add_action('admin_menu', [$this, 'create_member_menu']);
        }
    }

    public function create_admin_menu()
    {
        global $submenu; // Access the global submenu array
        $capability = 'read';
        $slug = 'materials';
        $locale = determine_locale();
        $menu_title = ($locale === 'ar' || $locale === 'ar_AR') ? 'الأقسام' : 'Materials';

        // Add the main menu page
        add_menu_page(
            $menu_title,
            $menu_title,
            $capability,
            $slug,
            [$this, 'menu_page_template'],
            'dashicons-building',
            3
        );

        // Check if the 'Admin' submenu already exists to avoid duplication
        if (!isset($submenu[$slug]) || !array_search('Admin2', array_column($submenu[$slug], 0))) {
            // Add a submenu page for Admin
            add_submenu_page(
                $slug,
                'admin2',
                'admin2',
                $capability,
                'admin',
                [$this, 'menu_page_template']
            );
        }
    }

    public function create_member_menu()
    {
        $capability = 'read';
        $locale = determine_locale();
        $slug = 'materials';
        $menu_title = ($locale === 'ar' || $locale === 'ar_AR') ? 'الأعضاء' : 'Members';

        add_menu_page(
            $menu_title,
            $menu_title,
            $capability,
            $slug,
            [$this, 'menu_page_template'],
            'dashicons-admin-users',
            3
        );
    }

    public function menu_page_template()
    {
        echo '<div class="wrap">

    <div id="deparment-react-app"></div>
</div>';
    }
}

new material_Create_Admin_Page();
