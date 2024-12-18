<?php
/**
 * Plugin Name: WooCommerce Review Importer
 * Description: A custom built plugin to import reviews from a CSV file into WooCommerce. For any issues please contact Mackenzie at MJWeb Ltd.
 * Version: 1.0
 * Author: Mackenzie Williams at MJWeb Ltd.
 * Text Domain: wc-review-importer
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Add admin menu item
add_action('admin_menu', 'wc_review_importer_menu');
function wc_review_importer_menu() {
    add_menu_page('Review Importer', 'Review Importer', 'manage_options', 'wc-review-importer', 'wc_review_importer_page');
}

// Admin page content
function wc_review_importer_page() {
    ?>
    <div class="wrap">
        <h1>WooCommerce Review Importer</h1>
        <p>Upload a CSV file containing reviews to import into WooCommerce.</p>
        <!-- <p><a href="<?php echo plugins_url('example-reviews.csv', __FILE__); ?>" class="button button-secondary">Download Example CSV</a></p> -->
        <form enctype="multipart/form-data" method="post" action="">
            <label for="csv_file">Select CSV File:</label>
            <input type="file" name="csv_file" id="csv_file" required>
            <input type="submit" name="import_reviews" class="button button-primary" value="Import Reviews">
        </form>
    </div>
    <?php
    if (isset($_POST['import_reviews'])) {
        wc_review_importer_handle_file_upload();
    }
}

// Handle file upload
function wc_review_importer_handle_file_upload() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if (isset($_FILES['csv_file'])) {
        $upload_errors = array(
            UPLOAD_ERR_OK => 'There is no error, the file uploaded with success.',
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        );

        $error_code = $_FILES['csv_file']['error'];
        if ($error_code !== UPLOAD_ERR_OK) {
            $error_message = isset($upload_errors[$error_code]) ? $upload_errors[$error_code] : 'Unknown upload error';
            echo '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
            return;
        }

        $csv_file = $_FILES['csv_file']['tmp_name'];
        wc_review_importer_import_reviews_from_csv($csv_file);
    }
}

// Import reviews from CSV
function wc_review_importer_import_reviews_from_csv($csv_file) {
    if (($handle = fopen($csv_file, 'r')) !== FALSE) {
        $header = fgetcsv($handle, 1000, ',');
        $line_number = 2; // Since we are skipping the header line
        while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
            if (count($header) !== count($data)) {
                echo '<div class="notice notice-error"><p>Error on line ' . $line_number . ': Mismatch in number of fields. Please ensure each row has the same number of fields as the header.</p></div>';
                return;
            }
            $review_data = array_combine($header, $data);

            if (empty($review_data['product_SKU'])) {
                echo '<div class="notice notice-error"><p>Error on line ' . $line_number . ': Missing product SKU.</p></div>';
                return;
            }

            wc_review_importer_insert_review($review_data);
            $line_number++;
        }
        fclose($handle);
    }
}

// Insert review into WooCommerce
function wc_review_importer_insert_review($review_data) {
    // Convert comment_approved and verified to appropriate values
    $comment_approved = in_array(strtolower($review_data['comment_approved']), ['1', 'yes']) ? 1 : 0;
    $verified = in_array(strtolower($review_data['verified']), ['1', 'yes']) ? 1 : 0;

    // Get product ID from SKU
    $product_id = wc_get_product_id_by_sku($review_data['product_SKU']);
    if (!$product_id) {
        error_log("Product with SKU " . $review_data['product_SKU'] . " not found.");
        echo '<div class="notice notice-error"><p>Product with SKU ' . esc_html($review_data['product_SKU']) . ' not found.</p></div>';
        return;
    }

    $commentdata = array(
        'comment_ID' => intval($review_data['comment_ID']),
        'comment_post_ID' => $product_id,
        'comment_author' => sanitize_text_field($review_data['comment_author']),
        'comment_author_email' => sanitize_email($review_data['comment_author_email']),
        'comment_author_url' => esc_url($review_data['comment_author_url']),
        'comment_author_IP' => sanitize_text_field($review_data['comment_author_IP']),
        'comment_date' => date('Y-m-d H:i:s', strtotime($review_data['comment_date'])),
        'comment_date_gmt' => gmdate('Y-m-d H:i:s', strtotime($review_data['comment_date_gmt'])),
        'comment_content' => sanitize_textarea_field($review_data['comment_content']),
        'comment_approved' => $comment_approved,
        'comment_parent' => intval($review_data['comment_parent']),
        'user_id' => intval($review_data['user_id']),
        'comment_type' => 'review',
    );

    // Insert comment
    $comment_id = wp_insert_comment($commentdata);

    if ($comment_id && ! is_wp_error($comment_id)) {
        update_comment_meta($comment_id, 'rating', intval($review_data['rating']));
        update_comment_meta($comment_id, 'verified', $verified);
        if (!empty($review_data['title'])) {
            update_comment_meta($comment_id, 'title', sanitize_text_field($review_data['title']));
        }
    } else {
        error_log("Failed to insert comment: " . print_r($review_data, true));
    }
}

// // Helper function to get product ID by SKU
// function wc_get_product_id_by_sku($sku) {
//     global $wpdb;
//     $product_id = $wpdb->get_var($wpdb->prepare("
//         SELECT post_id FROM {$wpdb->postmeta}
//         WHERE meta_key='_sku' AND meta_value='%s'
//     ", $sku));
//     return $product_id;
// }
?>
