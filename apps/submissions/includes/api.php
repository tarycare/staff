<?php

require __DIR__ . '/../../lib/vendor/autoload.php';

use Aws\S3\S3Client;

class WP_React_Submissions_Rest_Route
{
    public function __construct()
    {
        add_action('init', [$this, 'register_submission_post_type']);
        add_action('rest_api_init', [$this, 'create_rest_routes']);

        if (defined('WP_ENV') && WP_ENV === 'development') {
            add_action('rest_api_init', [$this, 'add_cors_headers']);
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    // Function to upload files to DO Spaces
    public function upload_file_to_do_spaces($file, $visibility)
    {
        $s3 = new S3Client([
            'version' => 'latest',
            'region' => DO_SPACES_REGION,
            'endpoint' => 'https://sgp1.digitaloceanspaces.com',
            'credentials' => [
                'key' => DO_SPACES_KEY,
                'secret' => DO_SPACES_SECRET,
            ],
        ]);

        $file_path = $file['tmp_name'];
        $file_name = basename($file['name']);
        $acl = 'private';

        try {
            $site_id = get_current_blog_id();
            $result = $s3->putObject([
                'Bucket' => DO_SPACES_BUCKET,
                'Key' => 'uploads/' . $site_id . '/forms/' . $file_name,
                'SourceFile' => $file_path,
                'ACL' => $acl,
            ]);

            return [
                'success' => true,
                'url' => $result['ObjectURL'],
                'result' => $result,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    // Function to register the Submission custom post type
    public function register_submission_post_type()
    {
        $labels = [
            'name'               => 'Submissions',
            'singular_name'      => 'Submission',
            'menu_name'          => 'Submissions',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Submission',
            'edit_item'          => 'Edit Submission',
            'new_item'           => 'New Submission',
            'view_item'          => 'View Submission',
            'all_items'          => 'All Submissions',
            'search_items'       => 'Search Submissions',
            'not_found'          => 'No submissions found',
            'not_found_in_trash' => 'No submissions found in trash'
        ];

        $args = [
            'labels'              => $labels,
            'public'              => true,
            'has_archive'         => true,
            'rewrite'             => ['slug' => 'submissions'],
            'supports'            => ['title', 'editor', 'thumbnail'],
            'show_in_rest'        => true,
            'rest_base'           => 'submissions',
        ];

        register_post_type('submission', $args);
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
            'my-submission-script',
            get_template_directory_uri() . '/dist/submission.js',
            ['jquery'],
            null,
            true
        );

        wp_localize_script('my-submission-script', 'myApiSettings', [
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest')
        ]);
    }

    // Create REST routes for CRUD operations on Submission
    public function create_rest_routes()
    {
        register_rest_route('submission/v1', '/add', [
            'methods' => 'POST',
            'callback' => [$this, 'add_submission'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);

        register_rest_route('submission/v1', '/all/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_all_submissions_by_id'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('submission/v1', '/select', [
            'methods' => 'GET',
            'callback' => [$this, 'get_select_submissions'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('submission/v1', '/get/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_submission_by_id'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('submission/v1', '/update/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'update_submission'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);

        register_rest_route('submission/v1', '/delete/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_submission'],
            'permission_callback' => function () {
                return current_user_can('delete_posts');
            }
        ]);
    }

    // Function to add a submission
    public function add_submission($request)
    {
        $parameters = $request->get_params();
        $title = sanitize_text_field($parameters['title']) ?: date('Y-m-d H:i:s');
        $content = sanitize_textarea_field($parameters['content']);
        $parameters['user_id'] = get_current_user_id();

        foreach ($_FILES as $key => $file) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                $upload = $this->upload_file_to_do_spaces($file, 'private');

                if ($upload['success']) {
                    $parameters[$key] = $upload['url'];
                } else {
                    return new WP_Error('file_upload_failed', $upload['message'], ['status' => 500]);
                }
            }
        }

        if (empty($title)) {
            return new WP_Error('missing_fields', 'Missing title', ['status' => 400]);
        }

        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'submission'
        ]);

        if (is_wp_error($post_id)) {
            return new WP_Error('submission_creation_failed', $post_id->get_error_message(), ['status' => 500]);
        }

        foreach ($parameters as $key => $value) {
            $meta_key = sanitize_key($key);
            $meta_value = is_array($value) ? $value : maybe_serialize($value);
            update_post_meta($post_id, $meta_key, $meta_value);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Submission created successfully',
            'post_id' => $post_id,
            'data'    => $parameters
        ]);
    }

    // Function to get all submissions
    public function get_all_submissions_by_id($request)
    {
        $id = (string) $request['id'];

        $args = [
            'post_type'   => 'submission',
            'post_status' => 'publish',
            'meta_query'  => [
                [
                    'key'   => 'form_id',
                    'value' => $id,
                    'compare' => '='
                ]
            ],
            'numberposts' => -1
        ];

        $submissions = get_posts($args);
        $data = [];
        // foreach ($departments as $department) {
        //     // Get post meta data
        //     $meta_data = get_post_meta($department->ID);
        //     $flattened_meta = [];

        //     if (!empty($meta_data) && is_array($meta_data)) {
        //         foreach ($meta_data as $key => $value) {
        //             $flattened_meta[$key] = is_array($value) && isset($value[0]) ? $value[0] : $value;
        //             // Unserialize if the value is serialized
        //             $flattened_meta[$key] = maybe_unserialize($flattened_meta[$key]);
        //         }
        //     }

        //     // Merge the flattened meta data with the main data array
        //     $data[] = array_merge(
        //         [
        //             // convert id to number
        //             'id'      => (string) $department->ID,
        //             'title'   => $department->post_title,
        //             'content' => $department->post_content,
        //         ],
        //         $flattened_meta
        //     );
        // }
        foreach ($submissions as $submission) {
            $meta_data = get_post_meta($submission->ID);
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
                    'id'      => (string) $submission->ID,
                    'title'   => $submission->post_title,
                    'content' => $submission->post_content,
                ],
                $flattened_meta
            );
        }

        if (empty($submissions)) {
            return rest_ensure_response([], 200);
        }


        return rest_ensure_response($data);
    }

    public function get_select_submissions()
    {
        $args = [
            'post_type'   => 'submission',
            'post_status' => 'publish',
            'numberposts' => -1
        ];

        $submissions = get_posts($args);
        $data = [];

        foreach ($submissions as $submission) {
            $data[] = [
                'value' => (string) $submission->ID,
                'label_en' => $submission->post_title,
                'label_ar' => $submission->post_title,
            ];
        }

        return rest_ensure_response($data);
    }

    // Function to get a submission by ID
    public function get_submission_by_id($request)
    {
        $id = (int) $request['id'];
        $submission = get_post($id);

        if (!$submission || $submission->post_type !== 'submission') {
            return new WP_Error('submission_not_found', 'Submission not found', ['status' => 404]);
        }

        $post_data = [
            'id'      => $submission->ID,
            'title'   => $submission->post_title,
            'content' => $submission->post_content,
        ];

        $post_meta = get_post_meta($id);
        foreach ($post_meta as $key => $value) {
            $post_data[$key] = maybe_unserialize($value[0]);
        }

        return rest_ensure_response($post_data);
    }

    // Function to update a submission with meta keys
    public function update_submission($request)
    {
        $id = (int) $request['id'];
        $submission = get_post($id);

        if (!$submission || $submission->post_type !== 'submission') {
            return new WP_Error('submission_not_found', 'Submission not found', ['status' => 404]);
        }

        $parameters = $request->get_params();
        $title = sanitize_text_field($parameters['title']);
        $content = sanitize_textarea_field($parameters['content']);

        if (!empty($title)) {
            wp_update_post([
                'ID'         => $id,
                'post_title' => $title,
                'post_content' => $content
            ]);
        }

        foreach ($parameters as $key => $value) {
            if ($value === 'removed___file__meta') {
                delete_post_meta($id, $key);
                unset($parameters[$key]);
            }
        }

        foreach ($_FILES as $key => $file) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                $upload = $this->upload_file_to_do_spaces($file, 'private');

                if ($upload['success']) {
                    $parameters[$key] = $upload['url'];
                } else {
                    return new WP_Error('file_upload_failed', $upload['message'], ['status' => 500]);
                }
            }
        }

        foreach ($parameters as $key => $value) {
            $meta_key = sanitize_key($key);
            $meta_value = is_array($value) ? $value : maybe_serialize($value);
            update_post_meta($id, $meta_key, $meta_value);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Submission updated successfully',
            'post_id' => $id,
            'updated_data' => $parameters
        ]);
    }

    // Function to delete a submission and all related meta data
    public function delete_submission($request)
    {
        $id = (int) $request['id'];
        $submission = get_post($id);

        if (!$submission || $submission->post_type !== 'submission') {
            return new WP_Error('submission_not_found', 'Submission not found', ['status' => 404]);
        }

        $meta_keys = get_post_meta($id);
        foreach ($meta_keys as $key => $value) {
            delete_post_meta($id, $key);
        }

        wp_delete_post($id, true);

        return rest_ensure_response([
            'success' => true,
            'message' => 'Submission and related meta deleted successfully'
        ]);
    }
}

new WP_React_Submissions_Rest_Route();
