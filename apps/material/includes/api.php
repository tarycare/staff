<?php

class WP_React_material_Rest_Route
{
    public function __construct()
    {
        add_action('init', [$this, 'register_material_post_type']); // Register custom post type
        add_action('rest_api_init', [$this, 'create_rest_routes']); // Create REST routes

        if (defined('WP_ENV') && WP_ENV === 'development') {
            add_action('rest_api_init', [$this, 'add_cors_headers']); // Add CORS headers in dev
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']); // Enqueue scripts
    }

    // Function to register the Material custom post type
    public function register_material_post_type()
    {
        $labels = array(
            'name'               => 'Materials',
            'singular_name'      => 'Material',
            'menu_name'          => 'Materials',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Material',
            'edit_item'          => 'Edit Material',
            'new_item'           => 'New Material',
            'view_item'          => 'View Material',
            'all_items'          => 'All Materials',
            'search_items'       => 'Search Materials',
            'not_found'          => 'No materials found',
            'not_found_in_trash' => 'No materials found in trash'
        );

        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'has_archive'         => true,
            'rewrite'             => array('slug' => 'materials'),
            'supports'            => array('title', 'editor', 'thumbnail'),
            'show_in_rest'        => true, // Enable REST API support
            'rest_base'           => 'materials',
        );

        register_post_type('material', $args);
    }

    // Add CORS headers in development mode
    public function add_cors_headers()
    {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', function ($value) {
            header('Access-Control-Allow-Origin: http://localhost:4000');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Authorization, X-WP-Nonce, Content-Type, Accept, Origin, X-Requested-With');
            return $value;
        });
    }

    // Enqueue scripts
    public function enqueue_scripts()
    {
        wp_enqueue_script(
            'my-material-script',
            get_template_directory_uri() . '/dist/material.js',
            array('jquery'),
            null,
            true
        );

        wp_localize_script('my-material-script', 'myApiSettings', array(
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest')
        ));
    }

    // Create REST routes for CRUD operations on Material
    public function create_rest_routes()
    {
        register_rest_route('material/v1', '/add', [
            'methods' => 'POST',
            'callback' => [$this, 'add_material'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);

        register_rest_route('material/v1', '/all', [
            'methods' => 'GET',
            'callback' => [$this, 'get_all_materials'],
            'permission_callback' => '__return_true'
        ]);

        // Add a dynamic route to for sletect all
        register_rest_route('material/v1', '/select', [
            'methods' => 'GET',
            'callback' => [$this, 'get_select_materials'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('material/v1', '/get/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_material_by_id'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('material/v1', '/update/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'update_material'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);

        register_rest_route('material/v1', '/delete/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_material'],
            'permission_callback' => function () {
                return current_user_can('delete_posts');
            }
        ]);
    }

    // Function to add a material
    public function add_material($request)
    {
        $parameters = $request->get_params();

        $title = sanitize_text_field($parameters['title']);
        $content = sanitize_textarea_field($parameters['content']);

        if (empty($title)) {
            return new WP_Error('missing_fields', 'Missing title', array('status' => 400));
        }

        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'material'
        ]);

        // insert post meta
        foreach ($parameters as $key => $value) {
            $meta_key = sanitize_key($key);
            $meta_value = is_array($value) ? $value : maybe_serialize($value);

            update_post_meta($post_id, $meta_key, $meta_value);
        }


        if (is_wp_error($post_id)) {
            return new WP_Error('material_creation_failed', $post_id->get_error_message(), array('status' => 500));
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Material created successfully',
            'post_id' => $post_id,
            'data'    => $parameters
        ]);
    }

    // Function to get all materials
    public function get_all_materials()
    {
        $args = [
            'post_type'   => 'material',
            'post_status' => 'publish',
            'numberposts' => -1
        ];

        $materials = get_posts($args);
        $data = [];

        foreach ($materials as $material) {
            // Get post meta data
            $meta_data = get_post_meta($material->ID);
            $flattened_meta = [];

            if (!empty($meta_data) && is_array($meta_data)) {
                foreach ($meta_data as $key => $value) {
                    $flattened_meta[$key] = is_array($value) && isset($value[0]) ? $value[0] : $value;
                    // Unserialize if the value is serialized
                    $flattened_meta[$key] = maybe_unserialize($flattened_meta[$key]);
                }
            }

            // Merge the flattened meta data with the main data array
            $data[] = array_merge(
                [
                    'id'      => $material->ID,
                    'title'   => $material->post_title,
                    'content' => $material->post_content,
                ],
                $flattened_meta
            );
        }

        return rest_ensure_response($data);
    }

    public function get_select_materials()
    {
        $args = [
            'post_type'   => 'material',
            'post_status' => 'publish',
            'numberposts' => -1
        ];

        $materials = get_posts($args);
        $data = [];

        foreach ($materials as $material) {
            $data[] = [
                'value' => (string) $material->ID,
                'label_en' => $material->post_title,
                'label_ar' => $material->post_title,

            ];
        }

        return rest_ensure_response($data);
    }


    // Function to get a material by ID
    public function get_material_by_id($request)
    {
        $id = (int) $request['id'];
        $material = get_post($id);

        if (!$material || $material->post_type !== 'material') {
            return new WP_Error('material_not_found', 'Material not found', array('status' => 404));
        }

        $post_data = [
            'id'      => $material->ID,
            'title'   => $material->post_title,
            'content' => $material->post_content,
        ];
        // Append custom fields to the post data
        $post_meta = get_post_meta($id);
        foreach ($post_meta as $key => $value) {
            // Only unserialize if the data is serialized
            $post_data[$key] = maybe_unserialize($value[0]);
        }

        return rest_ensure_response($post_data);
    }

    // Function to update a material with meta keys
    public function update_material($request)
    {
        $id = (int) $request['id'];
        $material = get_post($id);

        if (!$material || $material->post_type !== 'material') {
            return new WP_Error('material_not_found', 'Material not found', array('status' => 404));
        }

        $parameters = $request->get_params();
        $title = sanitize_text_field($parameters['title']);
        $content = sanitize_textarea_field($parameters['content']);

        // Update post title and content
        if (!empty($title)) {
            wp_update_post([
                'ID'         => $id,
                'post_title' => $title,
                'post_content' => $content
            ]);
        }

        // Update meta fields (replace old ones with new ones)
        foreach ($parameters as $key => $value) {
            $meta_key = sanitize_key($key);
            // dont serialize if the value is an array
            $meta_value = is_array($value) ? $value : maybe_serialize($value);

            update_post_meta($id, $meta_key, $meta_value);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Material updated successfully',
            'post_id' => $id,
            'updated_data' => $parameters
        ]);
    }

    // Function to delete a material and all related meta data
    public function delete_material($request)
    {
        $id = (int) $request['id'];
        $material = get_post($id);

        if (!$material || $material->post_type !== 'material') {
            return new WP_Error('material_not_found', 'Material not found', array('status' => 404));
        }

        // Delete all meta keys related to the material
        $meta_keys = get_post_meta($id);
        foreach ($meta_keys as $key => $value) {
            delete_post_meta($id, $key);
        }

        // Delete the post
        wp_delete_post($id, true);

        return rest_ensure_response([
            'success' => true,
            'message' => 'Material and related meta deleted successfully'
        ]);
    }
}




new WP_React_material_Rest_Route();
