<?php
/*
Plugin Name: DO Spaces File Uploader API with Document Post Type
Description: Handle file uploads to DigitalOcean Spaces, create document post type with API support.
Version: 1.1
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require __DIR__ . '/../lib/vendor/autoload.php';

use Aws\S3\S3Client;

// Register Custom Post Type for Documents
function register_document_post_type()
{
    register_post_type('document', [
        'labels' => [
            'name' => 'Documents',
            'singular_name' => 'Document',
        ],
        'public' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'author'],
        'has_archive' => true,
        'rewrite' => ['slug' => 'documents'],
    ]);
}
add_action('init', 'register_document_post_type');

// Register Custom Taxonomies (Category and Tag) for Documents
function register_document_taxonomies()
{
    register_taxonomy('document_category', 'document', [
        'label' => 'Categories',
        'rewrite' => ['slug' => 'document-category'],
        'hierarchical' => true,
        'show_in_rest' => true,
    ]);

    register_taxonomy('document_tag', 'document', [
        'label' => 'Tags',
        'rewrite' => ['slug' => 'document-tag'],
        'hierarchical' => false,
        'show_in_rest' => true,
    ]);
}
add_action('init', 'register_document_taxonomies');

// Add custom fields (version, document date, expiry date) to REST API
function register_document_meta_fields()
{
    register_post_meta('document', 'version', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'string',
    ]);

    register_post_meta('document', 'document_date', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'string',
    ]);

    register_post_meta('document', 'expiry_date', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'string',
    ]);
}
add_action('init', 'register_document_meta_fields');

// Function to upload files to DO Spaces
function upload_file_to_do_spaces($file, $visibility)
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
    $acl = ($visibility === 'public') ? 'public-read' : 'private';

    try {
        $result = $s3->putObject([
            'Bucket' => DO_SPACES_BUCKET,
            'Key' => 'uploads/' . $file_name,
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

// Register REST API route to handle document upload and post creation
add_action('rest_api_init', function () {
    register_rest_route('do-spaces/v1', '/upload-document', [
        'methods' => 'POST',
        'callback' => 'do_spaces_upload_and_create_document',
        'permission_callback' => function () {
            return is_user_logged_in(); // Ensure the user is logged in
        },
    ]);
});

// Handle the API request to upload file and create a document post
function do_spaces_upload_and_create_document(WP_REST_Request $request)
{
    $files = $request->get_file_params();
    $visibility = $request->get_param('visibility');
    $version = $request->get_param('version');
    $document_date = $request->get_param('document_date');
    $expiry_date = $request->get_param('expiry_date');
    $category = $request->get_param('category');
    $tags = $request->get_param('tags');

    $created_by = get_current_user_id();
    $created_date = date('Y-m-d H:i:s');
    $app_name = $request->get_param('app_name');
    $item_id = $request->get_param('item_id');
    $file_type = $request->get_param('file_type');

    if (isset($files['file'])) {
        $upload_result = upload_file_to_do_spaces($files['file'], $visibility);

        if ($upload_result['success']) {
            // Create document post
            $post_id = wp_insert_post([
                'post_title' => sanitize_text_field($files['file']['name']),
                'post_type' => 'document',
                'post_status' => 'publish',
                'meta_input' => [
                    'version' => sanitize_text_field($version),
                    'document_date' => sanitize_text_field($document_date),
                    'expiry_date' => sanitize_text_field($expiry_date),
                ],
            ]);

            if (is_wp_error($post_id)) {
                return new WP_REST_Response(['message' => 'Failed to create document post'], 500);
            }

            // Assign category and tags
            if (!empty($category)) {
                // Convert category string to array if necessary
                if (!is_array($category)) {
                    $category = explode(',', $category);
                }

                // Check if the category exists, if not create it
                foreach ($category as $cat) {
                    if (term_exists($cat, 'document_category')) {
                        wp_set_post_terms($post_id, $cat, 'document_category');
                    } else {
                        $new_category = wp_insert_term($cat, 'document_category');
                        if (!is_wp_error($new_category)) {
                            wp_set_post_terms($post_id, $new_category['term_id'], 'document_category');
                        } else {
                            error_log('Failed to create category: ' . $new_category->get_error_message());
                        }
                    }
                }
            }



            if (!empty($tags)) {
                // Convert tags string to array
                $tags = explode(',', $tags);
                wp_set_post_terms($post_id, $tags, 'document_tag');
            }

            // Add custom fields
            update_post_meta($post_id, 'visibility', $visibility);
            update_post_meta($post_id, 'created_by', $created_by);
            update_post_meta($post_id, 'created_date', $created_date);
            update_post_meta($post_id, 'app_name', $app_name);
            update_post_meta($post_id, 'item_id', $item_id);
            update_post_meta($post_id, 'file_type', $file_type);
            // url
            update_post_meta($post_id, 'url', $upload_result['url']);

            return new WP_REST_Response([
                'success' => true,
                'url' => $upload_result['url'],
                'post_id' => $post_id,
                'data' => $request->get_params(),
            ], 200);
        } else {
            return new WP_REST_Response($upload_result, 500);
        }
    }

    return new WP_REST_Response(['message' => 'No file uploaded'], 400);
}


// Register REST API route to retrieve document categories
add_action('rest_api_init', function () {
    register_rest_route('do-spaces/v1', '/document-categories', [
        'methods' => 'GET',
        'callback' => 'get_document_categories',
        'permission_callback' => '__return_true', // Set permissions as needed
    ]);
});

// Function to fetch document categories
function get_document_categories(WP_REST_Request $request)
{
    $categories = get_terms([
        'taxonomy' => 'document_category',
        'hide_empty' => false,
    ]);

    if (!empty($categories) && !is_wp_error($categories)) {
        $response = [];
        foreach ($categories as $category) {
            $response[] = [
                'id' => $category->term_id,
                'name' => $category->name,
            ];
        }
        return new WP_REST_Response($response, 200);
    }

    return new WP_REST_Response(['message' => 'No categories found'], 404);
}

// Register REST API route to retrieve document tags
add_action('rest_api_init', function () {
    register_rest_route('do-spaces/v1', '/document-tags', [
        'methods' => 'GET',
        'callback' => 'get_document_tags',
        'permission_callback' => '__return_true', // Set permissions as needed
    ]);
});

// Function to fetch document tags
function get_document_tags(WP_REST_Request $request)
{
    $tags = get_terms([
        'taxonomy' => 'document_tag',
        'hide_empty' => false,
    ]);

    if (!empty($tags) && !is_wp_error($tags)) {
        $response = [];
        foreach ($tags as $tag) {
            $response[] = [
                'id' => $tag->term_id,
                'name' => $tag->name,
            ];
        }
        return new WP_REST_Response($response, 200);
    }

    return new WP_REST_Response(['message' => 'No tags found'], 404);
}




// Register REST API route to fetch documents by app_name and item_id
add_action('rest_api_init', function () {
    register_rest_route('do-spaces/v1', '/documents', [
        'methods' => 'GET',
        'callback' => 'get_documents_by_app_name_and_item_id',
        'permission_callback' => '__return_true', // Adjust as needed
    ]);
});

// Callback function to handle fetching documents by app_name and item_id (stored in meta)
// Function to fetch documents by app_name and item_id and include categories and tags
function get_documents_by_app_name_and_item_id(WP_REST_Request $request)
{
    $app_name = sanitize_text_field($request->get_param('app_name'));
    $item_id = sanitize_text_field($request->get_param('item_id'));

    if (!$app_name || !$item_id) {
        return new WP_REST_Response(['message' => 'App name and Item ID are required'], 400);
    }

    $args = [
        'post_type' => 'document',
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'app_name',
                'value' => $app_name,
                'compare' => '='
            ],
            [
                'key' => 'item_id',
                'value' => $item_id,
                'compare' => '='
            ]
        ]
    ];

    $query = new WP_Query($args);

    $documents = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            // Fetch all post meta
            $post_meta = get_post_meta($post_id);

            // Get categories
            $categories = get_the_terms($post_id, 'document_category');
            $categories_data = [];
            if (!empty($categories) && !is_wp_error($categories)) {
                foreach ($categories as $category) {
                    $categories_data[] = [
                        'id' => $category->term_id,
                        'name' => $category->name,
                    ];
                }
            }

            // Get tags
            $tags = get_the_terms($post_id, 'document_tag');
            $tags_data = [];
            if (!empty($tags) && !is_wp_error($tags)) {
                foreach ($tags as $tag) {
                    $tags_data[] = [
                        'id' => $tag->term_id,
                        'name' => $tag->name,
                    ];
                }
            }

            // Prepare document response, including categories and tags
            $documents[] = [
                'id' => $post_id,
                'title' => get_the_title(),
                'url' => get_post_meta($post_id, 'url', true),
                'version' => get_post_meta($post_id, 'version', true),
                'document_date' => get_post_meta($post_id, 'document_date', true),
                'expiry_date' => get_post_meta($post_id, 'expiry_date', true),
                'created_date' => get_post_meta($post_id, 'created_date', true),
                'app_name' => get_post_meta($post_id, 'app_name', true),
                'item_id' => get_post_meta($post_id, 'item_id', true),
                'categories' => $categories_data, // Include categories
                'tags' => $tags_data, // Include tags
                'meta' => $post_meta
            ];
        }
        wp_reset_postdata();
    }

    return new WP_REST_Response($documents, 200);
}




// Register REST API route to delete a document
add_action('rest_api_init', function () {
    register_rest_route('do-spaces/v1', '/document/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'delete_document_by_id',
        'permission_callback' => function () {
            // Only allow users with the 'delete_posts' capability (typically admins)
            return current_user_can('delete_posts');
        },
    ]);
});


// Function to delete the document and its metadata
function delete_document_by_id(WP_REST_Request $request)
{
    $post_id = (int) $request['id'];

    // Ensure the document exists
    if (get_post_type($post_id) !== 'document') {
        return new WP_REST_Response(['message' => 'Invalid document ID'], 404);
    }

    // Delete post meta
    delete_post_meta($post_id, 'version');
    delete_post_meta($post_id, 'document_date');
    delete_post_meta($post_id, 'expiry_date');
    delete_post_meta($post_id, 'app_name');
    delete_post_meta($post_id, 'item_id');

    // Trash the document
    wp_trash_post($post_id);

    return new WP_REST_Response(['message' => 'Document deleted successfully'], 200);
}


// Register REST API route to update a document
add_action('rest_api_init', function () {
    register_rest_route('do-spaces/v1', '/document/(?P<id>\d+)', [
        'methods' => 'POST',
        'callback' => 'update_document_by_id',
        'permission_callback' => function () {
            return is_user_logged_in(); // Ensure the user is logged in
        },
    ]);
});

// Function to update the document title and metadata
// Function to update the document title, metadata, categories, and tags
function update_document_by_id(WP_REST_Request $request)
{
    $post_id = (int) $request['id'];
    $title = sanitize_text_field($request->get_param('title'));
    $version = sanitize_text_field($request->get_param('version'));
    $document_date = sanitize_text_field($request->get_param('document_date'));
    $expiry_date = sanitize_text_field($request->get_param('expiry_date'));
    $category = $request->get_param('category');
    $tags = $request->get_param('tags');
    $visibility = sanitize_text_field($request->get_param('visibility'));

    // Ensure the document exists
    if (get_post_type($post_id) !== 'document') {
        return new WP_REST_Response(['message' => 'Invalid document ID'], 404);
    }

    // Update the document's title
    wp_update_post([
        'ID' => $post_id,
        'post_title' => $title,
    ]);

    // Update post meta
    update_post_meta($post_id, 'version', $version);
    update_post_meta($post_id, 'document_date', $document_date);
    update_post_meta($post_id, 'expiry_date', $expiry_date);
    update_post_meta($post_id, 'visibility', $visibility);

    // Update category
    if (!empty($category)) {
        // Convert category to array if necessary
        if (!is_array($category)) {
            $category = [$category];
        }
        wp_set_post_terms($post_id, $category, 'document_category');
    } else {
        // Remove categories if none provided
        wp_set_post_terms($post_id, [], 'document_category');
    }

    // Update tags
    if (!empty($tags)) {
        // Convert tags to array if necessary
        if (!is_array($tags)) {
            $tags = is_string($tags) ? explode(',', $tags) : [$tags];
        }
        wp_set_post_terms($post_id, $tags, 'document_tag');
    } else {
        // Remove tags if none provided
        wp_set_post_terms($post_id, [], 'document_tag');
    }

    return new WP_REST_Response(['message' => 'Document updated successfully'], 200);
}
