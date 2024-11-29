<?php

class WP_React_Settings_Rest_Route
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'create_rest_routes']);
        if (defined('WP_ENV') && WP_ENV === 'development') {

            add_action('rest_api_init', [$this, 'add_cors_headers']); // Add this line
        }
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']); // Enqueue scripts
    }
    // Add this new function to handle CORS headers
    public function add_cors_headers()
    {
        // Remove the default CORS headers
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');

        // Add custom CORS headers
        add_filter('rest_pre_serve_request', function ($value) {
            // Allow requests from localhost:4000 during development
            header('Access-Control-Allow-Origin: http://localhost:3000');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Authorization, X-WP-Nonce, Content-Type, Accept, Origin, X-Requested-With');

            return $value;
        });
    }

    // Enqueue and localize the script with nonce
    public function enqueue_scripts()
    {
        wp_enqueue_script(
            'my-staff-script',  // Unique handle for the script
            get_template_directory_uri() . 'dist/bundle.js',  // URL to the script
            array('jquery'),  // Optional dependencies
            null,  // No version
            true   // Load in footer
        );

        // Localize script to pass nonce and REST API root URL
        wp_localize_script('my-staff-script', 'myApiSettings', array(
            'root' => esc_url_raw(rest_url()),  // REST API base URL
            'nonce' => wp_create_nonce('wp_rest')  // Security nonce
        ));
    }

    public function create_rest_routes()
    {
        // Route for adding a user
        register_rest_route('staff/v1', '/add', [
            'methods' => 'POST',
            'callback' => [$this, 'add_staff'],
            'permission_callback' => function () {
                if (defined('WP_ENV') && WP_ENV === 'development') {
                    return '__return_true';
                } else {
                    return current_user_can('create_users') || current_user_can('edit_users');
                }
            }

        ]);

        // Route for fetching all users
        register_rest_route('staff/v1', '/all', [
            'methods' => 'GET',
            'callback' => [$this, 'get_all_users'],
            'permission_callback' => '__return_true'
        ]);

        // Route for fetching managers
        register_rest_route('staff/v1', '/managers', [
            'methods' => 'GET',
            'callback' => [$this, 'get_all_managers'],
            'permission_callback' => '__return_true'
        ]);

        // Route for fetching a specific user by ID
        register_rest_route('staff/v1', '/get/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_user_by_id'],
            'permission_callback' => '__return_true'
        ]);

        // Route for deleting a user
        register_rest_route('staff/v1', '/delete/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_staff'],
            'permission_callback' => function () {
                return current_user_can('create_users') || current_user_can('edit_users');
            }
        ]);

        // Route for updating a user
        register_rest_route('staff/v1', '/update/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'update_staff'],
            'permission_callback' => function () {
                return current_user_can('create_users') || current_user_can('edit_users');
            }
        ]);

        // Get the current site ID
        register_rest_route('staff/v1', '/site', [
            'methods' => 'GET',
            'callback' => [$this, 'get_site_id'],
            'permission_callback' => '__return_true'
        ]);
    }

    // Function to get current site ID
    public function get_site_id()
    {
        return rest_ensure_response([
            'site_id' => get_current_blog_id()
        ]);
    }

    // Function to get all users
    public function get_all_users()
    {
        $args = [
            'role__in' => ['subscriber', 'editor', 'administrator'],
            'orderby' => 'registered',
            'order' => 'DESC'
        ];

        $users = get_users($args);
        $user_data = [];

        if (!empty($users) && is_array($users)) {
            foreach ($users as $user) {
                // Check if the user has roles and if roles exist
                $user_roles = !empty($user->roles) ? implode(', ', $user->roles) : 'No role assigned';

                // Get user meta and flatten the array values
                $meta_data = get_user_meta($user->ID);
                $flattened_meta = [];

                if (!empty($meta_data) && is_array($meta_data)) {
                    foreach ($meta_data as $key => $value) {
                        if ($key === 'image') {
                            $attachment_id = (int) $value[0];
                            $image_url = wp_get_attachment_url($attachment_id);
                            // Store the image URL under the same key as the field name
                            $flattened_meta[$key] = $image_url;
                        } else {
                            // unserializing the value if it's serialized
                            $meta_value = $value[0];
                            while (is_serialized($meta_value)) {
                                $meta_value = maybe_unserialize($meta_value);
                            }
                            $flattened_meta[$key] = $meta_value;
                        }
                    }
                }


                $user_data[] = array_merge([
                    'id' => $user->ID,
                    'username' => $user->user_login,
                    'email' => $user->user_email,
                    'role' => $user_roles,
                    'registered' => $user->user_registered,
                ], $flattened_meta);
            }
        } else {
            error_log('Users array is empty or not an array.');
        }

        return rest_ensure_response($user_data);
    }



    // Function to get all managers
    public function get_all_managers($parameters)
    {
        $exclude_user_id = isset($parameters['id']) ? intval($parameters['id']) : 0;

        $args = [
            'orderby' => 'registered',
            'order' => 'DESC'
        ];

        $users = get_users($args);
        $user_data = [];

        foreach ($users as $user) {
            if ($user->ID == $exclude_user_id) {
                continue;
            }

            $first_name = get_user_meta($user->ID, 'first_name', true);
            $last_name = get_user_meta($user->ID, 'last_name', true);

            $user_data[] = [
                'value' => (string) $user->ID,
                'label_en' => $first_name . ' ' . $last_name,
                'label_ar' => $first_name . ' ' . $last_name,
            ];
        }

        return rest_ensure_response($user_data);
    }

    // Function to get a user by ID
    public function get_user_by_id($request)
    {
        $user_id = (int) $request->get_param('id');
        $user = get_userdata($user_id);

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found', array('status' => 404));
        }

        $user_data = [
            'id' => $user->ID,
            'username' => $user->user_login,
            'user_email' => $user->user_email,
            'registered' => $user->user_registered
        ];

        // Append custom fields to the user data
        $custom_fields = get_user_meta($user_id);

        if (!empty($custom_fields) && is_array($custom_fields)) {
            foreach ($custom_fields as $key => $value) {
                if ($key === 'image') {
                    $attachment_id = (int) $value[0];

                    // Check if the attachment ID is valid
                    if ($attachment_id) {
                        $image_url = wp_get_attachment_url($attachment_id);
                        if ($image_url) {
                            $user_data[$key] = $image_url;
                        } else {
                            $user_data[$key] = null; // Set as null if the image URL can't be retrieved
                        }
                    } else {
                        $user_data[$key] = null; // Set as null if no valid image is found
                    }
                } else {
                    $meta_value = $value[0];

                    // Unserialize repeatedly until it's no longer serialized
                    while (is_serialized($meta_value)) {
                        $meta_value = maybe_unserialize($meta_value);
                    }

                    // Set the unserialized value or the original if not serialized
                    $user_data[$key] = $meta_value;
                }
            }
        } else {
            error_log('Custom fields are empty or not an array for user ID: ' . $user_id);
        }

        return rest_ensure_response($user_data);
    }





    private function handle_avatar_upload($file, $user_id)
    {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        if ($file['error'] !== UPLOAD_ERR_OK) {
            error_log('File upload error: ' . $file['error']);
            return new WP_Error('upload_error', 'File upload error', array('status' => 500));
        }

        $upload = wp_handle_upload($file, ['test_form' => false]);

        if (isset($upload['error'])) {
            error_log('Upload failed: ' . $upload['error']);
            return new WP_Error('upload_error', $upload['error'], array('status' => 500));
        }

        $attachment = [
            'guid' => $upload['url'],
            'post_mime_type' => $upload['type'],
            'post_title' => basename($upload['file']),
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        if (!$attach_id) {
            return new WP_Error('attachment_error', 'Failed to create attachment.', array('status' => 500));
        }

        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        update_user_meta($user_id, 'image', $attach_id);

        return $attach_id;
    }

    // Function to add a user
    public function add_staff($request)
    {
        $parameters = $request->get_params();

        $email = sanitize_email($parameters['email']);
        if (empty($email)) {
            return new WP_Error('missing_fields', 'Missing email field', array('status' => 400));
        }

        $site_id = is_multisite() ? get_current_blog_id() : 1;
        $email_with_prefix = $site_id . '_' . $email;

        if (email_exists($email_with_prefix)) {
            return new WP_Error('user_exists', 'User already exists with this email', array('status' => 400));
        }

        $password = wp_generate_password();
        $user_id = wp_create_user($email_with_prefix, $password, $email_with_prefix);

        if (is_wp_error($user_id)) {
            return new WP_Error('user_creation_failed', $user_id->get_error_message(), array('status' => 500));
        }

        wp_update_user(['ID' => $user_id, 'role' => 'subscriber']);

        foreach ($parameters as $key => $value) {
            $meta_key = sanitize_key($key);
            $meta_value = maybe_serialize($value);
            update_user_meta($user_id, $meta_key, $meta_value);
        }

        if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $avatar_id = $this->handle_avatar_upload($_FILES['image'], $user_id);
            if (is_wp_error($avatar_id)) {
                return $avatar_id;
            }
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'User created successfully',
            'user_id' => $user_id,
            'data' => $parameters
        ], 201);
    }

    // Function to update a user
    // Function to update a user
    public function update_staff($request)
    {
        $user_id = (int) $request->get_param('id');

        $existing_user = get_userdata($user_id);

        if (!$existing_user) {
            return new WP_Error('user_not_found', 'User not found', array('status' => 404));
        }

        $parameters = $request->get_params(); // Retrieves both GET and POST parameters

        if (empty($parameters) || !is_array($parameters)) {
            return new WP_Error('invalid_request', 'No parameters provided', array('status' => 400));
        }

        // Log parameters for debugging
        error_log('Parameters: ' . print_r($parameters, true));

        $new_email = sanitize_email(isset($parameters['email']) ? $parameters['email'] : '');

        if (empty($new_email)) {
            return new WP_Error('missing_email', 'Email is required', array('status' => 400));
        }

        $site_id = is_multisite() ? get_current_blog_id() : 1;
        $new_email_with_prefix = $site_id . '_' . $new_email;

        // Check if the email exists for another user
        $existing_user_with_email = get_user_by('email', $new_email_with_prefix);
        if ($existing_user_with_email && $existing_user_with_email->ID !== $user_id) {
            return new WP_Error('user_exists', 'User already exists with this email', array('status' => 400));
        }

        wp_update_user([
            'ID' => $user_id,
            'user_email' => $new_email_with_prefix,
            'display_name' => $new_email_with_prefix,
            'user_nicename' => $new_email_with_prefix
        ]);

        // Handle image removal if the 'remove_image' action is set
        if (isset($parameters['remove_image']) && $parameters['remove_image'] === 'true') {
            $existing_image_id = get_user_meta($user_id, 'image', true);
            if (!empty($existing_image_id)) {
                // Remove the image by deleting the user meta
                delete_user_meta($user_id, 'image');
                // Optionally: You can also delete the attachment if needed
                wp_delete_attachment($existing_image_id, true); // true to force delete
                error_log('User image removed.');
            }
        } else {
            // Handle image upload if a new image file is provided
            $files = $request->get_file_params();
            error_log('Received files: ' . print_r($files, true));

            if (!empty($files['image']) && $files['image']['error'] === UPLOAD_ERR_OK) {
                $avatar_id = $this->handle_avatar_upload($files['image'], $user_id);
                if (is_wp_error($avatar_id)) {
                    return $avatar_id;
                }
                // Update user meta with the new avatar ID
                update_user_meta($user_id, 'image', $avatar_id);
            } elseif (!isset($parameters['remove_image'])) {
                // Retain the current image if no new image is uploaded and no removal is requested
                $existing_image_id = get_user_meta($user_id, 'image', true);
                if (!empty($existing_image_id)) {
                    error_log('Retaining the existing image as no new image was uploaded.');
                    update_user_meta($user_id, 'image', $existing_image_id);
                }
            } else {
                error_log('No image uploaded or image upload error occurred, skipping image update.');
            }
        }

        // Update other user meta except for the image if no file upload or remove_image action
        foreach ($parameters as $key => $value) {
            if (!in_array($key, ['user_login', 'user_email', 'id', 'registered', 'username', 'image', 'remove_image'])) {
                update_user_meta($user_id, sanitize_key($key), maybe_serialize($value));
            }
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'User updated successfully',
            'user_id' => $user_id
        ]);
    }




    // Function to delete a user
    public function delete_staff($request)
    {
        $user_id = (int) $request->get_param('id');

        if (!get_userdata($user_id)) {
            return new WP_Error('user_not_found', 'User not found', array('status' => 404));
        }

        require_once(ABSPATH . 'wp-admin/includes/user.php');
        wp_delete_user($user_id);

        $meta_keys = get_user_meta($user_id, '', '');
        if (!empty($meta_keys) && is_array($meta_keys)) {
            foreach ($meta_keys as $key => $value) {
                delete_user_meta($user_id, $key);
            }
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }
}

new WP_React_Settings_Rest_Route();
