<?php

class WP_React_Facility_Rest_Route
{
    public function __construct()
    {
        add_action('init', [$this, 'register_facility_post_type']); // Register custom post type
        add_action('rest_api_init', [$this, 'create_rest_routes']); // Create REST routes

        if (defined('WP_ENV') && WP_ENV === 'development') {
            add_action('rest_api_init', [$this, 'add_cors_headers']); // Add CORS headers in dev
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']); // Enqueue scripts
    }

    // Function to register the Facility custom post type
    public function register_facility_post_type()
    {
        $labels = array(
            'name'               => 'Facilities',
            'singular_name'      => 'Facility',
            'menu_name'          => 'Facilities',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Facility',
            'edit_item'          => 'Edit Facility',
            'new_item'           => 'New Facility',
            'view_item'          => 'View Facility',
            'all_items'          => 'All Facilities',
            'search_items'       => 'Search Facilities',
            'not_found'          => 'No facilities found',
            'not_found_in_trash' => 'No facilities found in trash'
        );

        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'has_archive'         => true,
            'rewrite'             => array('slug' => 'facilities'),
            'supports'            => array('title', 'editor', 'thumbnail'),
            'show_in_rest'        => true, // Enable REST API support
            'rest_base'           => 'facilities',
        );

        register_post_type('facility', $args);
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
            'my-facility-script',
            get_template_directory_uri() . '/dist/facility.js',
            array('jquery'),
            null,
            true
        );

        wp_localize_script('my-facility-script', 'myApiSettings', array(
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest')
        ));
    }

    // Create REST routes for CRUD operations on Facility
    public function create_rest_routes()
    {
        register_rest_route('facility/v1', '/add', [
            'methods' => 'POST',
            'callback' => [$this, 'add_facility'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);

        register_rest_route('facility/v1', '/all', [
            'methods' => 'GET',
            'callback' => [$this, 'get_all_facilities'],
            'permission_callback' => '__return_true'
        ]);

        // Add a dynamic route to for sletect all
        register_rest_route('facility/v1', '/select', [
            'methods' => 'GET',
            'callback' => [$this, 'get_select_facilities'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('facility/v1', '/get/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_facility_by_id'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('facility/v1', '/update/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'update_facility'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);

        register_rest_route('facility/v1', '/delete/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_facility'],
            'permission_callback' => function () {
                return current_user_can('delete_posts');
            }
        ]);
    }

    // Function to add a facility
    public function add_facility($request)
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
            'post_type'    => 'facility'
        ]);

        // insert post meta
        foreach ($parameters as $key => $value) {
            $meta_key = sanitize_key($key);
            $meta_value = is_array($value) ? $value : maybe_serialize($value);

            update_post_meta($post_id, $meta_key, $meta_value);
        }


        if (is_wp_error($post_id)) {
            return new WP_Error('facility_creation_failed', $post_id->get_error_message(), array('status' => 500));
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Facility created successfully',
            'post_id' => $post_id,
            'data'    => $parameters
        ]);
    }

    // Function to get all facilities
    public function get_all_facilities()
    {
        $args = [
            'post_type'   => 'facility',
            'post_status' => 'publish',
            'numberposts' => -1
        ];

        $facilities = get_posts($args);
        $data = [];

        foreach ($facilities as $facility) {
            // Get post meta data
            $meta_data = get_post_meta($facility->ID);
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
                    // convert id to number
                    'id'      => (string) $facility->ID,
                    'title'   => $facility->post_title,
                    'content' => $facility->post_content,
                ],
                $flattened_meta
            );
        }


        return rest_ensure_response($data);
    }

    public function get_select_facilities()
    {
        $args = [
            'post_type'   => 'facility',
            'post_status' => 'publish',
            'numberposts' => -1
        ];

        $facilities = get_posts($args);
        $data = [];

        foreach ($facilities as $facility) {
            $data[] = [
                'value' => (string) $facility->ID,
                'label_en' => $facility->post_title,
                'label_ar' => $facility->post_title,

            ];
        }

        return rest_ensure_response($data);
    }


    // Function to get a facility by ID
    public function get_facility_by_id($request)
    {
        $id = (int) $request['id'];
        $facility = get_post($id);

        if (!$facility || $facility->post_type !== 'facility') {
            return new WP_Error('facility_not_found', 'Facility not found', array('status' => 404));
        }

        $post_data = [
            'id'      => $facility->ID,
            'title'   => $facility->post_title,
            'content' => $facility->post_content,
        ];
        // Append custom fields to the post data
        $post_meta = get_post_meta($id);
        foreach ($post_meta as $key => $value) {
            // Only unserialize if the data is serialized
            $post_data[$key] = maybe_unserialize($value[0]);
        }

        return rest_ensure_response($post_data);
    }

    // Function to update a facility with meta keys
    public function update_facility($request)
    {
        $id = (int) $request['id'];
        $facility = get_post($id);

        if (!$facility || $facility->post_type !== 'facility') {
            return new WP_Error('facility_not_found', 'Facility not found', array('status' => 404));
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
            $meta_value = is_array($value) ? $value : maybe_serialize($value);

            update_post_meta($id, $meta_key, $meta_value);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Facility updated successfully',
            'post_id' => $id,
            'updated_data' => $parameters
        ]);
    }

    // Function to delete a facility and all related meta data
    public function delete_facility($request)
    {
        $id = (int) $request['id'];
        $facility = get_post($id);

        if (!$facility || $facility->post_type !== 'facility') {
            return new WP_Error('facility_not_found', 'Facility not found', array('status' => 404));
        }

        // Delete all meta keys related to the facility
        $meta_keys = get_post_meta($id);
        foreach ($meta_keys as $key => $value) {
            delete_post_meta($id, $key);
        }

        // Delete the post
        wp_delete_post($id, true);

        return rest_ensure_response([
            'success' => true,
            'message' => 'Facility and related meta deleted successfully'
        ]);
    }
}




new WP_React_Facility_Rest_Route();
