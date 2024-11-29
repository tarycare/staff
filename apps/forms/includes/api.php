<?php

class WP_React_Form_Rest_Route
{
    public function __construct()
    {
        add_action('init', [$this, 'register_form_post_type']); // Register custom post type
        add_action('rest_api_init', [$this, 'create_rest_routes']); // Create REST routes

        if (defined('WP_ENV') && WP_ENV === 'development') {
            add_action('rest_api_init', [$this, 'add_cors_headers']); // Add CORS headers in dev
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']); // Enqueue scripts
    }

    // Function to register the Form custom post type
    public function register_form_post_type()
    {
        $labels = array(
            'name'               => 'Forms',
            'singular_name'      => 'Form',
            'menu_name'          => 'Forms',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Form',
            'edit_item'          => 'Edit Form',
            'new_item'           => 'New Form',
            'view_item'          => 'View Form',
            'all_items'          => 'All Forms',
            'search_items'       => 'Search Forms',
            'not_found'          => 'No forms found',
            'not_found_in_trash' => 'No forms found in trash'
        );

        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'has_archive'         => true,
            'rewrite'             => array('slug' => 'forms'),
            'supports'            => array('title', 'editor', 'thumbnail'),
            'show_in_rest'        => true, // Enable REST API support
            'rest_base'           => 'forms',
        );

        register_post_type('form', $args);
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
            'my-form-script',
            get_template_directory_uri() . '/dist/form.js',
            array('jquery'),
            null,
            true
        );

        wp_localize_script('my-form-script', 'myApiSettings', array(
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest')
        ));
    }

    // Create REST routes for CRUD operations on Form
    public function create_rest_routes()
    {
        register_rest_route('form/v1', '/add', [
            'methods' => 'POST',
            'callback' => [$this, 'add_form'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);

        register_rest_route('form/v1', '/all', [
            'methods' => 'GET',
            'callback' => [$this, 'get_all_forms'],
            'permission_callback' => '__return_true'
        ]);

        // Add a dynamic route to for sletect all
        register_rest_route('form/v1', '/select', [
            'methods' => 'GET',
            'callback' => [$this, 'get_select_forms'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('form/v1', '/get/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_form_by_id'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('form/v1', '/get', [
            'methods' => 'GET',
            'callback' => [$this, 'get_form_by_title'],
            'permission_callback' => '__return_true'
        ]);


        register_rest_route('form/v1', '/update/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_form'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);

        register_rest_route('form/v1', '/delete/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_form'],
            'permission_callback' => function () {
                return current_user_can('delete_posts');
            }
        ]);
    }


    // Function to get a form by title
    public function get_form_by_title($request)
    {
        $title = sanitize_text_field($request->get_param('title'));

        if (empty($title)) {
            return new WP_Error('missing_title', 'Title is required', array('status' => 400));
        }

        $args = [
            'post_type' => 'form',
            'post_status' => 'publish',
            'title' => $title,
            'numberposts' => 1
        ];

        $forms = get_posts($args);

        if (empty($forms)) {
            return new WP_Error('form_not_found', 'Form not found', array('status' => 404));
        }

        $form = $forms[0];

        $post_data = [
            'id' => $form->ID,
            'title' => $form->post_title,
            'content' => $form->post_content,
        ];

        // Append custom fields to the post data
        $post_meta = get_post_meta($form->ID);

        foreach ($post_meta as $key => $value) {
            $meta_value = $value[0];

            // Unserialize recursively to handle double serialization
            while (is_serialized($meta_value)) {
                $meta_value = maybe_unserialize($meta_value);
            }

            $post_data[$key] = $meta_value;
        }

        return rest_ensure_response($post_data);
    }

    // Function to add a form
    public function add_form($request)
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
            'post_type'    => 'form'
        ]);

        // insert post meta
        foreach ($parameters as $key => $value) {
            $meta_key = sanitize_key($key);
            $meta_value = maybe_serialize($value); // Handle arrays or complex values

            update_post_meta($post_id, $meta_key, $meta_value);
        }





        if (is_wp_error($post_id)) {
            return new WP_Error('form_creation_failed', $post_id->get_error_message(), array('status' => 500));
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Form created successfully',
            'post_id' => $post_id,
            'data'    => $parameters
        ]);
    }

    // Function to get all forms
    public function get_all_forms()
    {
        $args = [
            'post_type'   => 'form',
            'post_status' => 'publish',
            'numberposts' => -1
        ];

        $forms = get_posts($args);
        $data = [];

        foreach ($forms as $form) {
            $data[] = [
                'id'      => $form->ID,
                'title'   => $form->post_title,
                'content' => $form->post_content,
                'is_app_form' => get_post_meta($form->ID, 'is_app_form', true) === '0' ? false : true,
            ];
        }

        return rest_ensure_response($data);
    }

    public function get_select_forms()
    {
        $args = [
            'post_type'   => 'form',
            'post_status' => 'publish',
            'numberposts' => -1
        ];

        $forms = get_posts($args);
        $data = [];

        foreach ($forms as $form) {
            $data[] = [
                'value' => (string) $form->ID,
                'label_en' => $form->post_title,
                'label_ar' => $form->post_title,

            ];
        }

        return rest_ensure_response($data);
    }


    // Function to get a form by ID

    public function get_form_by_id($request)
    {
        $id = (int) $request['id'];
        $form = get_post($id);

        if (!$form || $form->post_type !== 'form') {
            return new WP_Error('form_not_found', 'Form not found', array('status' => 404));
        }

        $post_data = [
            'id'      => $form->ID,
            'title'   => $form->post_title,
            'content' => $form->post_content,
        ];

        // Append custom fields to the post data
        $post_meta = get_post_meta($id);

        foreach ($post_meta as $key => $value) {
            $meta_value = $value[0];

            // Unserialize recursively to handle double serialization
            while (is_serialized($meta_value)) {
                $meta_value = maybe_unserialize($meta_value);
            }

            $post_data[$key] = $meta_value;
        }

        return rest_ensure_response($post_data);
    }


    // Function to update a form with meta keys
    public function update_form($request)
    {
        $id = (int) $request['id'];
        $form = get_post($id);

        if (!$form || $form->post_type !== 'form') {
            return new WP_Error('form_not_found', 'Form not found', array('status' => 404));
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
            $meta_value = maybe_serialize($value); // Handle arrays or complex values

            update_post_meta($id, $meta_key, $meta_value);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Form updated successfully',
            'post_id' => $id,
            'updated_data' => $parameters
        ]);
    }

    // Function to delete a form and all related meta data
    public function delete_form($request)
    {
        $id = (int) $request['id'];
        $form = get_post($id);

        if (!$form || $form->post_type !== 'form') {
            return new WP_Error('form_not_found', 'Form not found', array('status' => 404));
        }

        // Delete all meta keys related to the form
        $meta_keys = get_post_meta($id);
        foreach ($meta_keys as $key => $value) {
            delete_post_meta($id, $key);
        }

        // Delete the post
        wp_delete_post($id, true);

        return rest_ensure_response([
            'success' => true,
            'message' => 'Form and related meta deleted successfully'
        ]);
    }
}




new WP_React_Form_Rest_Route();
