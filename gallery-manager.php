<?php
/*
Plugin Name: Photo Gallery
Plugin URI: https://github.com/uttam04aug/Photo-Gallery-Wordpress-Plugin
Description: Advanced folder-based gallery with infinite scroll and lightbox support. Easily create and display photo galleries on your site.
Version: 2.0.0
Author: Uttam Singh
Author URI: https://github.com/uttam04aug
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: gallery
*/
 
if (!defined('ABSPATH')) exit;
global $wpdb;

define('GP_TABLE', $wpdb->prefix.'gallery_folders');
define('GP_URL', plugin_dir_url(__FILE__));
define('GP_PATH', plugin_dir_path(__FILE__));
define('GP_VERSION', '3.0.0');

/* =====================
   DISABLE UPDATE WARNINGS
===================== */
add_filter('site_transient_update_plugins', function($value) {
    $plugin_basename = plugin_basename(__FILE__);
    
    if (isset($value->response[$plugin_basename])) {
        unset($value->response[$plugin_basename]);
    }
    
    return $value;
});

add_filter('pre_set_site_transient_update_plugins', function($transient) {
    $plugin_basename = plugin_basename(__FILE__);
    
    if (isset($transient->response[$plugin_basename])) {
        unset($transient->response[$plugin_basename]);
    }
    
    return $transient;
});

/* =====================
   ACTIVATE – TABLE
===================== */
register_activation_hook(__FILE__, 'gp_activate_plugin');
function gp_activate_plugin() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE ".GP_TABLE." (
        id INT AUTO_INCREMENT PRIMARY KEY,
        folder_name VARCHAR(255) NOT NULL,
        images LONGTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";
    
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    
    // Create uploads directory
    $upload_dir = wp_upload_dir();
    $gallery_dir = $upload_dir['basedir'].'/gallery-uploads';
    if (!file_exists($gallery_dir)) {
        wp_mkdir_p($gallery_dir);
    }
}

/* =====================
   ADMIN MENU
===================== */
add_action('admin_menu', function() {
    add_menu_page(
        'Gallery Manager',
        'Gallery',
        'manage_options',
        'gallery-manager',
        'gp_admin_main_page',
        'dashicons-format-gallery',
        30
    );
    
    add_submenu_page(
        'gallery-manager',
        'Add New Folder',
        'Add New',
        'manage_options',
        'gallery-add',
        'gp_admin_add_page'
    );
});

/* =====================
   ENQUEUE ADMIN ASSETS
===================== */
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'gallery') !== false) {
        // Admin CSS
        wp_enqueue_style('gp-admin-css', GP_URL . 'assets/css/admin.css', [], '1.0');
        
        // Admin JS
        wp_enqueue_script('gp-admin-js', GP_URL . 'assets/js/admin.js', 
            ['jquery', 'jquery-ui-sortable'], '1.0', true);
        
        // Media Uploader
        wp_enqueue_media();
        
        // Localize script
        wp_localize_script('gp-admin-js', 'gpAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gp_ajax_nonce'),
            'max_size' => 10 * 1024 * 1024,
            'max_size_msg' => 'Maximum file size is 10MB',
            'uploading' => 'Uploading...',
            'upload_complete' => 'Upload Complete!',
            'upload_failed' => 'Upload Failed!'
        ]);
    }
});

/* =====================
   ENQUEUE FRONTEND ASSETS
===================== */
add_action('wp_enqueue_scripts', 'gp_enqueue_all_assets');
function gp_enqueue_all_assets() {
    global $post;
    
    // Only load on pages with gallery shortcode
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'gallery')) {
        
        // 1. Enqueue CSS Files
        wp_enqueue_style('gp-frontend-css', GP_URL . 'assets/css/frontend.css', [], '1.0.0');
        
        // 2. Enqueue JavaScript Files
        wp_enqueue_script('jquery');
        
        // Your existing frontend JS
        wp_enqueue_script('gp-frontend-js',
            GP_URL . 'assets/js/frontend.js',
            ['jquery'], '1.0.0', true);
        
        // 3. Pass data to JavaScript
        wp_localize_script('gp-frontend-js', 'gp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gp_ajax_nonce'),
            'site_url' => get_site_url(),
            'loading_text' => 'Loading more images...',
            'no_more_text' => 'All images loaded'
        ]);
    }
}

/* =====================
   AJAX HANDLERS
===================== */

// Handle image upload
add_action('wp_ajax_gp_upload_image', 'gp_ajax_upload_image');
function gp_ajax_upload_image() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gp_ajax_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    
    // Check if file exists
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error(['message' => 'No file uploaded or upload error']);
    }
    
    $file = $_FILES['file'];
    
    // Check file size (10MB limit)
    if ($file['size'] > 10 * 1024 * 1024) {
        wp_send_json_error(['message' => 'File size exceeds 10MB limit']);
    }
    
    // Check file type
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = wp_check_filetype($file['name']);
    
    if (!in_array($file_type['type'], $allowed_types)) {
        wp_send_json_error(['message' => 'Invalid file type. Only JPG, PNG, GIF, WebP allowed']);
    }
    
    // Include necessary files
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    // Upload file
    $attachment_id = media_handle_upload('file', 0);
    
    if (is_wp_error($attachment_id)) {
        wp_send_json_error(['message' => $attachment_id->get_error_message()]);
    }
    
    // Get image URL
    $image_url = wp_get_attachment_url($attachment_id);
    
    if (!$image_url) {
        wp_send_json_error(['message' => 'Failed to get image URL']);
    }
    
    wp_send_json_success([
        'url' => $image_url,
        'id' => $attachment_id,
        'name' => basename($image_url)
    ]);
}

// Handle image removal
add_action('wp_ajax_gp_remove_image', 'gp_ajax_remove_image');
function gp_ajax_remove_image() {
    global $wpdb;
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gp_ajax_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    
    $folder_id = isset($_POST['folder_id']) ? intval($_POST['folder_id']) : 0;
    $image_url = isset($_POST['image_url']) ? sanitize_text_field($_POST['image_url']) : '';
    
    if ($folder_id > 0 && !empty($image_url)) {
        $folder = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".GP_TABLE." WHERE id=%d", $folder_id));
        
        if ($folder) {
            $images = json_decode($folder->images, true) ?: [];
            $new_images = array_values(array_filter($images, function($img) use ($image_url) {
                return $img !== $image_url;
            }));
            
            $wpdb->update(
                GP_TABLE,
                ['images' => json_encode($new_images)],
                ['id' => $folder_id]
            );
            
            wp_send_json_success(['message' => 'Image removed successfully']);
        }
    }
    
    wp_send_json_success(['message' => 'Image removed from preview']);
}

// AJAX for infinite scroll - Load more images
add_action('wp_ajax_gp_load_more_images', 'gp_ajax_load_more_images');
add_action('wp_ajax_nopriv_gp_load_more_images', 'gp_ajax_load_more_images');
function gp_ajax_load_more_images() {
    global $wpdb;
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gp_ajax_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    
    $folder_id = isset($_POST['folder_id']) ? intval($_POST['folder_id']) : 0;
    $page = isset($_POST['page']) ? intval($_POST['page']) : 2;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 12;
    
    if ($folder_id <= 0) {
        wp_send_json_error(['message' => 'Invalid folder ID']);
    }
    
    // Get folder data
    $folder = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM ".GP_TABLE." WHERE id = %d", 
        $folder_id
    ));
    
    if (!$folder) {
        wp_send_json_error(['message' => 'Folder not found']);
    }
    
    $images = json_decode($folder->images, true) ?: [];
    $total_images = count($images);
    
    // Calculate offset
    $offset = ($page - 1) * $per_page;
    
    // Get images for current page
    $current_images = array_slice($images, $offset, $per_page);
    
    if (empty($current_images)) {
        wp_send_json_success([
            'images' => [],
            'has_more' => false,
            'message' => 'No more images'
        ]);
    }
    
    $html = '';
    foreach ($current_images as $index => $img_url) {
        if (!empty($img_url)) {
            $global_index = $offset + $index;
            $html .= '<div class="gp-grid-item">';
            $html .= '<a href="' . esc_url($img_url) . '" class="gp-lightbox-link" data-title="' . esc_attr($folder->folder_name) . ' - Image ' . ($global_index + 1) . '">';
            $html .= '<img src="' . esc_url($img_url) . '" alt="' . esc_attr($folder->folder_name) . ' - Image ' . ($global_index + 1) . '" loading="lazy">';
            $html .= '</a>';
            $html .= '</div>';
        }
    }
    
    wp_send_json_success([
        'html' => $html,
        'images' => $current_images,
        'folder_name' => esc_html($folder->folder_name),
        'current_page' => $page,
        'loaded_count' => count($current_images),
        'total_loaded' => min($offset + count($current_images), $total_images),
        'has_more' => ($offset + count($current_images)) < $total_images,
        'total_images' => $total_images
    ]);
}

/* =====================
   ADMIN PAGES
===================== */

// Main admin page
function gp_admin_main_page() {
    global $wpdb;
    
    // Handle delete
    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
        if (wp_verify_nonce($_GET['_wpnonce'], 'gp_delete_folder')) {
            $wpdb->delete(GP_TABLE, ['id' => intval($_GET['id'])]);
            echo '<div class="notice notice-success is-dismissible"><p>Folder deleted successfully!</p></div>';
        }
    }
    
    // Get all folders
    $folders = $wpdb->get_results("SELECT * FROM ".GP_TABLE." ORDER BY id DESC");
    ?>
    <div class="wrap gp-admin-wrap">
        <h1 class="wp-heading-inline">Gallery Folders</h1>
        <a href="<?php echo admin_url('admin.php?page=gallery-add'); ?>" class="page-title-action">Add New Folder</a>
        
        <hr class="wp-header-end">
        
        <div class="gp-folders-list">
            <?php if (empty($folders)): ?>
                <div class="notice notice-info">
                    <p>No folders found. <a href="<?php echo admin_url('admin.php?page=gallery-add'); ?>">Create your first folder</a></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="20%">Folder Name</th>
                            <th width="40%">Images</th>
                            <th width="20%">Shortcode</th>
                            <th width="20%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($folders as $folder): 
                            $images = json_decode($folder->images, true) ?: [];
                            $image_count = count($images);
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($folder->folder_name); ?></strong></td>
                            <td>
                                <div class="gp-folder-images">
                                    <?php if (!empty($images)): 
                                        $preview_count = min(5, $image_count);
                                        for ($i = 0; $i < $preview_count; $i++):
                                    ?>
                                        <img src="<?php echo esc_url($images[$i]); ?>" 
                                             alt="Preview <?php echo $i+1; ?>"
                                             title="Image <?php echo $i+1; ?>">
                                    <?php endfor; ?>
                                        <?php if ($image_count > 5): ?>
                                        <span class="gp-image-count">+<?php echo $image_count - 5; ?> more</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="no-images">No images</span>
                                    <?php endif; ?>
                                </div>
                                <p><small>Total: <?php echo $image_count; ?> images</small></p>
                            </td>
                            <td>
                                <input type="text" class="regular-text gp-shortcode-input" 
                                       value='[gallery folder="<?php echo $folder->id; ?>"]' 
                                       readonly style="width: 100%;">
                                <button class="button button-small gp-copy-shortcode" 
                                        data-shortcode='[gallery folder="<?php echo $folder->id; ?>"]'>
                                    Copy Shortcode
                                </button>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=gallery-add&edit='.$folder->id); ?>" 
                                   class="button button-small">
                                    Edit
                                </a>
                                <a href="<?php echo wp_nonce_url(
                                    admin_url('admin.php?page=gallery-manager&action=delete&id='.$folder->id), 
                                    'gp_delete_folder'
                                ); ?>" 
                                   class="button button-small button-danger"
                                   onclick="return confirm('Are you sure? This will delete the folder and all its images.');">
                                    Delete
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Add/Edit folder page
function gp_admin_add_page() {
    global $wpdb;
    
    $folder_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
    $folder = $folder_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM ".GP_TABLE." WHERE id=%d", $folder_id)) : null;
    $images = $folder ? json_decode($folder->images, true) : [];
    $images = is_array($images) ? $images : [];
    
    // Handle form submission
    if (isset($_POST['submit_folder']) && check_admin_referer('gp_save_folder', 'gp_folder_nonce')) {
        $folder_name = isset($_POST['folder_name']) ? sanitize_text_field($_POST['folder_name']) : '';
        $existing_images = isset($_POST['existing_images']) ? array_map('esc_url_raw', (array)$_POST['existing_images']) : [];
        
        if (empty($folder_name)) {
            echo '<div class="notice notice-error"><p>Folder name is required!</p></div>';
        } else {
            if ($folder_id && $folder) {
                // Update existing folder
                $result = $wpdb->update(
                    GP_TABLE,
                    [
                        'folder_name' => $folder_name,
                        'images' => json_encode($existing_images)
                    ],
                    ['id' => $folder_id],
                    ['%s', '%s'],
                    ['%d']
                );
                
                if ($result !== false) {
                    echo '<div class="notice notice-success is-dismissible"><p>Folder updated successfully!</p></div>';
                    $folder = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".GP_TABLE." WHERE id=%d", $folder_id));
                    $images = json_decode($folder->images, true) ?: [];
                } else {
                    echo '<div class="notice notice-error"><p>Failed to update folder!</p></div>';
                }
            } else {
                // Create new folder
                $result = $wpdb->insert(
                    GP_TABLE,
                    [
                        'folder_name' => $folder_name,
                        'images' => json_encode($existing_images)
                    ],
                    ['%s', '%s']
                );
                
                if ($result) {
                    $folder_id = $wpdb->insert_id;
                    echo '<div class="notice notice-success is-dismissible"><p>Folder created successfully!</p></div>';
                    $folder = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".GP_TABLE." WHERE id=%d", $folder_id));
                    $images = json_decode($folder->images, true) ?: [];
                } else {
                    echo '<div class="notice notice-error"><p>Failed to create folder!</p></div>';
                }
            }
        }
    }
    ?>
    
    <div class="wrap gp-admin-wrap">
        <h1><?php echo $folder_id ? 'Edit Folder' : 'Add New Folder'; ?></h1>
        
        <form method="post" class="gp-folder-form" enctype="multipart/form-data">
            <?php wp_nonce_field('gp_save_folder', 'gp_folder_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="folder_name">Folder Name *</label></th>
                    <td>
                        <input type="text" id="folder_name" name="folder_name" 
                               value="<?php echo $folder ? esc_attr($folder->folder_name) : ''; ?>" 
                               class="regular-text" required style="width: 100%; max-width: 400px;">
                        <p class="description">Enter a name for this folder</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Upload Images</th>
                    <td>
                        <div class="gp-upload-area">
                            <div class="gp-drop-zone" id="gp-drop-zone">
                                <span class="dashicons dashicons-upload"></span>
                                <p><strong>Drag & drop images here</strong></p>
                                <p>or <a href="#" class="gp-browse button">Select Images</a></p>
                                <p class="description">Maximum 10MB per image. Supported: JPG, PNG, GIF, WebP</p>
                                <input type="file" id="gp-file-input" multiple accept="image/*" style="display: none;">
                            </div>
                            
                            <div class="gp-progress-area" style="display: none;">
                                <div class="gp-progress-bar">
                                    <div class="gp-progress-fill"></div>
                                </div>
                                <div class="gp-progress-text">0%</div>
                            </div>
                            
                            <div class="gp-upload-status"></div>
                        </div>
                        
                        <div class="gp-images-preview">
                            <h3>Images in this folder (<span id="gp-image-count"><?php echo count($images); ?></span>)</h3>
                            <div class="gp-images-grid" id="gp-images-grid">
                                <?php if (!empty($images)): 
                                    foreach ($images as $index => $img_url): 
                                        if (!empty($img_url)): ?>
                                        <div class="gp-image-item" data-index="<?php echo $index; ?>">
                                            <img src="<?php echo esc_url($img_url); ?>" 
                                                 alt="Image <?php echo $index+1; ?>"
                                                 onerror="this.src='<?php echo GP_URL; ?>assets/img/placeholder.jpg'">
                                            <input type="hidden" name="existing_images[]" value="<?php echo esc_url($img_url); ?>">
                                            <button type="button" class="gp-remove-image" title="Remove image"
                                                    data-folder-id="<?php echo $folder_id ?: 0; ?>"
                                                    data-image-url="<?php echo esc_url($img_url); ?>">
                                                <span class="dashicons dashicons-no"></span>
                                            </button>
                                            <span class="gp-image-number"><?php echo $index+1; ?></span>
                                        </div>
                                        <?php endif; 
                                    endforeach; 
                                endif; ?>
                            </div>
                            
                            <?php if (empty($images)): ?>
                            <div class="no-images-message">
                                <p>No images uploaded yet. Upload images using the area above.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit_folder" class="button button-primary button-large" 
                       value="<?php echo $folder_id ? 'Update Folder' : 'Create Folder'; ?>">
                <a href="<?php echo admin_url('admin.php?page=gallery-manager'); ?>" class="button button-large">Cancel</a>
            </p>
            
            <input type="hidden" name="folder_id" value="<?php echo $folder_id; ?>">
        </form>
    </div>
    
    <!-- Image Template for JavaScript -->
    <script type="text/html" id="gp-image-template">
        <div class="gp-image-item" data-index="{index}">
            <img src="{url}" alt="Image {number}">
            <input type="hidden" name="existing_images[]" value="{url}">
            <button type="button" class="gp-remove-image" title="Remove image">
                <span class="dashicons dashicons-no"></span>
            </button>
            <span class="gp-image-number">{number}</span>
        </div>
    </script>
    
    <?php
}

/* =====================
   COMPLETE SHORTCODE WITH INFINITE SCROLL
===================== */
add_shortcode('gallery', function($atts) {
    global $wpdb;
    
    $atts = shortcode_atts([
        'per_page' => 6,
        'columns' => 3,
        'folder' => 0
    ], $atts);
    
    $per_page = intval($atts['per_page']);
    $columns = min(6, max(1, intval($atts['columns'])));
    $folder_id = intval($atts['folder']);
    
    // Get current page
    $paged = max(1, get_query_var('paged') ?: (get_query_var('page') ?: 1));
    
    ob_start();
    
    // ============================================
    // INLINE CSS WITH INFINITE SCROLL STYLES
    // ============================================
    ?>
    <style>
    .gp-all-folders, .gp-single-folder {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        box-sizing: border-box;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    
    .gp-grid {
        display: grid;
        grid-template-columns: repeat(<?php echo $columns; ?>, 1fr);
        gap: 25px;
        margin: 30px 0;
        width: 100%;
    }
    
    .gp-folder-card {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        height: 320px;
        display: flex;
        flex-direction: column;
        width: 100%;
        position: relative;
        transition: all 0.3s ease;
    }
    
    .gp-folder-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.12);
    }
    
    .gp-folder-link {
        text-decoration: none;
        color: inherit;
        display: flex;
        flex-direction: column;
        height: 100%;
        width: 100%;
    }
    
    .gp-folder-image {
        height: 220px;
        overflow: hidden;
        flex-shrink: 0;
        width: 100%;
        position: relative;
    }
    
    .gp-folder-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.5s ease;
    }
    
    .gp-folder-card:hover .gp-folder-image img {
        transform: scale(1.05);
    }
    
    .gp-image-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(to bottom, transparent 50%, rgba(0,0,0,0.7) 100%);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        opacity: 0;
        transition: opacity 0.3s;
        padding-bottom: 15px;
    }
    
    .gp-folder-card:hover .gp-image-overlay {
        opacity: 1;
    }
    
    .gp-image-overlay .dashicons {
        color: white;
        font-size: 30px;
        width: 30px;
        height: 30px;
        margin-bottom: 5px;
    }
    
    .gp-count {
        color: white;
        font-size: 14px;
        font-weight: 600;
        background: rgba(0,0,0,0.6);
        padding: 4px 12px;
        border-radius: 20px;
    }
    
    .gp-folder-info {
        padding: 18px 15px;
        background: white;
        flex-grow: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        border-top: 1px solid #f0f0f0;
        min-height: 60px;
    }
    
    .gp-folder-info h3 {
        margin: 0;
        font-size: 17px;
        color: #333;
        font-weight: 600;
        text-align: center;
        width: 100%;
        line-height: 1.4;
    }
    
    .gp-grid-item {
        height: 250px;
        border-radius: 10px;
        overflow: hidden;
        width: 100%;
        box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        position: relative;
        transition: all 0.3s ease;
    }
    
    .gp-grid-item:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.12);
    }
    
    .gp-grid-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.5s ease;
    }
    
    .gp-grid-item:hover img {
        transform: scale(1.05);
    }
    
    .gp-lightbox-link {
        display: block;
        height: 100%;
        width: 100%;
        cursor: zoom-in;
        position: relative;
    }
    
    .gp-breadcrumb {
        margin: 20px 0 30px;
        padding: 12px 0px;
        border-radius: 8px;
        font-size: 15px;
        color: #495057;
    }
    
    .gp-breadcrumb a {
        color: #bf1e2e;
        text-decoration: none;
        font-weight: 600;
        margin-right: 5px;
    }
    
    .gp-breadcrumb a:hover {
        color: #005a87;
        text-decoration: underline;
    }
    
    .gp-breadcrumb .separator {
        margin: 0 8px;
        color: #6c757d;
    }
    
    .gp-main-title{font-weight: 700;
  margin-bottom: 0px;
  font-family: 'Playfair Display', serif;
  font-size: 3.5rem; padding-top:2%;}
    
     .gp-folder-title {
        font-size: 28px;
        color: #2c3e50;
        margin: 0 0 30px;
        padding-bottom: 15px;
        border-bottom: 3px solid #f0f0f0;
        position: relative;
    }
    
    .gp-folder-title:after {
        content: '';
        position: absolute;
        bottom: -3px;
        left: 0;
        width: 80px;
        height: 3px;
        background: #ffb129;
    }
    
    .gp-image-count-badge {
        background: #ffb129;
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 500;
        margin-left: 10px;
        vertical-align: middle;
    }
    
    .gp-pagination {
        text-align: center;
        margin-top: 40px;
        padding-top: 30px;
        border-top: 1px solid #e9ecef;
        clear: both;
    }
    
    .gp-pagination .page-numbers {
        display: inline-block;
        margin: 0 3px;
        padding: 8px 15px;
        background: white;
        color: #495057;
        text-decoration: none;
        border-radius: 5px;
        border: 1px solid #dee2e6;
        font-weight: 600;
        min-width: 40px;
        text-align: center;
        transition: all 0.3s;
    }
    
    .gp-pagination .page-numbers:hover {
        background: #bf1e2e;
        color: white;
        border-color: #bf1e2e;
    }
    
    .gp-pagination .current {
        background: #bf1e2e;
        color: white;
        border-color: #bf1e2e;
    }
    
    .gp-pagination .dots {
        background: transparent;
        border: none;
        min-width: auto;
    }
    
    .gp-back-button {
        display: inline-flex;
        align-items: center;
        margin: 25px 0;
        padding: 10px 20px;
        background: #bf1e2e;
        color: white;
        text-decoration: none;
        border-radius: 5px;
        transition: all 0.3s;
        font-weight: 600;
        border: none;
        cursor: pointer;
    }
    
    .gp-back-button:hover {
        background: #005a87;
        color: white;
    }
    
    .gp-back-button:before {
        content: '←';
        margin-right: 8px;
    }
    
    .gp-empty-folder, .gp-no-folders {
        text-align: center;
        padding: 50px 30px;
        background: #f8f9fa;
        border-radius: 10px;
        color: #6c757d;
        font-size: 16px;
        border: 2px dashed #dee2e6;
        margin: 20px 0;
    }
    
    .gp-error {
        background: #f8d7da;
        color: #721c24;
        padding: 15px;
        border-radius: 5px;
        border: 1px solid #f5c6cb;
        margin: 20px 0;
        text-align: center;
    }
    
    /* INFINITE SCROLL STYLES */
    .gp-infinite-loading {
        grid-column: 1 / -1;
        text-align: center;
        padding: 40px 20px;
    }
    
    .gp-loading-spinner {
        width: 40px;
        height: 40px;
        border: 3px solid #f3f3f3;
        border-top: 3px solid #0073aa;
        border-radius: 50%;
        animation: gp-spin 1s linear infinite;
        margin: 0 auto 20px;
    }
    
    .gp-load-more-btn {
        display: block;
        margin: 30px auto;
        padding: 12px 30px;
        background: #fff;
        color: #000;
        border: none;
        border-radius: 5px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        grid-column: 1 / -1;
    }
    
    .gp-load-more-btn:hover {
        background: #005a87;
        transform: translateY(-2px);
    }
    
    .gp-load-more-btn:disabled {
        background: #cccccc;
        cursor: not-allowed;
        transform: none;
    }
    
    .gp-no-more-images {
        text-align: center;
        padding: 30px;
        color: #666;
        font-size: 16px;
        background: #f8f9fa;
        border-radius: 10px;
        margin: 20px 0;
        grid-column: 1 / -1;
    }
    
    @keyframes gp-spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* Lightbox Styles */
    .gp-lightbox-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.95);
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.3s ease;
    }
    
    .gp-lightbox-content {
        position: relative;
        max-width: 90vw;
        max-height: 90vh;
        text-align: center;
    }
    
    .gp-lightbox-img {
        max-width: 100%;
        max-height: 85vh;
        border-radius: 8px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.5);
    }
    
    .gp-lightbox-close {
        position: absolute;
        top: -20px;
        right: -13px;
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        font-size: 40px;
        cursor: pointer;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        line-height: 1;
    }
    
    .gp-lightbox-close:hover {
        background: rgba(255,255,255,0.3);
    }
    
    .gp-lightbox-title {
        color: white;
        margin-top: 20px;
        font-size: 18px;
        padding: 0 20px;
    }
    
    /* Animations */
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes fadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
    }
    
    @keyframes zoomIn {
        from { 
            opacity: 0;
            transform: scale(0.9);
        }
        to { 
            opacity: 1;
            transform: scale(1);
        }
    }
    
    /* Responsive Design */
    @media (max-width: 1200px) {
        .gp-all-folders, .gp-single-folder {
            max-width: 100%;
            padding: 20px;
        }
    }
    
    @media (max-width: 992px) {
        .gp-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .gp-folder-card {
            height: 300px;
        }
        
        .gp-folder-image {
            height: 200px;
        }
        
        .gp-grid-item {
            height: 220px;
        }
    }
    
    @media (max-width: 768px) {
        .gp-grid {
            gap: 15px;
        }
        
        .gp-folder-card {
            height: 280px;
        }
        
        .gp-folder-image {
            height: 180px;
        }
        
        .gp-grid-item {
            height: 200px;
        }
        
        .gp-folder-title {
            font-size: 24px;
        }
        
        .gp-breadcrumb {
            font-size: 14px;
            padding: 10px 15px;
        }
    }
    
    @media (max-width: 576px) {
        .gp-grid {
            grid-template-columns: 1fr;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .gp-folder-card {
            height: 300px;
            max-width: 100%;
        }
        
        .gp-folder-image {
            height: 200px;
        }
        
        .gp-grid-item {
            height: 250px;
            max-width: 100%;
        }
        
        .gp-all-folders, .gp-single-folder {
            padding: 0 15px 30px;
        }
    }
    
    @media (max-width: 480px) {
        .gp-folder-card {
            height: 280px;
        }
        
        .gp-folder-image {
            height: 180px;
        }
        
        .gp-grid-item {
            height: 220px;
        }
        
        .gp-pagination .page-numbers {
            padding: 6px 12px;
            margin: 0 2px;
            min-width: 35px;
            font-size: 14px;
        }
    }
    </style>
    <?php
    // ============================================
    // END OF INLINE CSS
    // ============================================
    
    // If specific folder is requested or URL has folder parameter
    if ($folder_id > 0 || isset($_GET['folder'])) {
        $folder_id = $folder_id > 0 ? $folder_id : intval($_GET['folder']);
        
        // Single folder view
        $folder = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".GP_TABLE." WHERE id=%d", $folder_id));
        
        if (!$folder) {
            echo '<div class="gp-error">Folder not found!</div>';
        } else {
            $images = json_decode($folder->images, true) ?: [];
            $total_images = count($images);
            
            // Breadcrumb
            echo '<div class="gp-breadcrumb">';
            echo '<a href="' . remove_query_arg('folder') . '">Gallery</a>';
            echo '<span class="separator"> › </span>';
            echo '<span>' . esc_html($folder->folder_name) . '</span>';
            echo '</div>';
            
            // Title with image count
            echo '<h1 class="gp-folder-title">';
            echo esc_html($folder->folder_name);
            echo '<span class="gp-image-count-badge">' . $total_images . ' images</span>';
            echo '</h1>';
            
            // Hidden data for infinite scroll
            echo '<input type="hidden" id="gp-folder-id" value="' . $folder_id . '">';
            echo '<input type="hidden" id="gp-total-images" value="' . $total_images . '">';
            echo '<input type="hidden" id="gp-per-page" value="12">';
            echo '<input type="hidden" id="gp-current-page" value="1">';
            
            if (empty($images)) {
                echo '<div class="gp-empty-folder">No images in this folder.</div>';
            } else {
                // Show initial images (first 12)
                $initial_images = array_slice($images, 0, 12);
                $initial_count = count($initial_images);
                
                echo '<div class="gp-grid" id="gp-images-grid">';
                foreach ($initial_images as $index => $img_url) {
                    if (!empty($img_url)) {
                        echo '<div class="gp-grid-item">';
                        echo '<a href="' . esc_url($img_url) . '" 
          class="gp-lightbox-link" 
          data-title="' . esc_attr($folder->folder_name) . ' - Image ' . ($index + 1) . '">';
                        echo '<img src="' . esc_url($img_url) . '" alt="' . esc_attr($folder->folder_name) . ' - Image ' . ($index + 1) . '" loading="lazy">';
                        echo '</a>';
                        echo '</div>';
                    }
                }
                
                // Add infinite scroll loading container
                if ($total_images > $initial_count) {
                    echo '<div class="gp-infinite-loading" id="gp-infinite-loading">';
                    echo '<div class="gp-loading-spinner"></div>';
                    echo '<div class="gp-loading-text">Scroll down to load more images</div>';
                    echo '</div>';
                    
                    // Load more button
                    echo '<button class="gp-load-more-btn" id="gp-load-more-btn">';
                    echo 'Load More (' . ($total_images - $initial_count) . ' remaining)';
                    echo '</button>';
                } else {
                    echo '<div class="gp-no-more-images">All ' . $total_images . ' images loaded</div>';
                }
                
                echo '</div>';
            }
            
            // Back button
            echo '<div style="margin-top: 30px; text-align: center;">';
            echo '<a href="' . remove_query_arg('folder') . '" class="gp-back-button">Back to Gallery</a>';
            echo '</div>';
        }
        
    } else {
        // All folders view with pagination
        $total_folders = $wpdb->get_var("SELECT COUNT(*) FROM ".GP_TABLE);
        $total_pages = ceil($total_folders / $per_page);
        $offset = ($paged - 1) * $per_page;
        
        // Get folders with pagination
        $folders = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM ".GP_TABLE." ORDER BY id DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        echo '<div class="gp-all-folders">';
        echo '<h1 class="gp-main-title">Gallery</h1><hr class="hr_left">';
        
        if (empty($folders)) {
            echo '<div class="gp-no-folders">';
            echo '<p>No folders available yet.</p>';
            if (current_user_can('manage_options')) {
                echo '<p><a href="' . admin_url('admin.php?page=gallery-add') . '" class="button" style="display: inline-block; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; border-radius: 5px;">Create First Folder</a></p>';
            }
            echo '</div>';
        } else {
            echo '<div class="gp-grid">';
            foreach ($folders as $folder) {
                $images = json_decode($folder->images, true) ?: [];
                $image_count = count($images);
                $first_image = !empty($images) ? $images[0] : GP_URL . 'assets/img/placeholder.jpg';
                
                echo '<div class="gp-folder-card">';
                echo '<a href="' . add_query_arg(['folder' => $folder->id], get_permalink()) . '" class="gp-folder-link">';
                echo '<div class="gp-folder-image">';
                echo '<img src="' . esc_url($first_image) . '" 
                         alt="' . esc_attr($folder->folder_name) . '"
                         onerror="this.src=\'' . GP_URL . 'assets/img/placeholder.jpg\'">';
                if ($image_count > 0) {
                    echo '<div class="gp-image-overlay">';
                    echo '<span class="dashicons dashicons-images-alt2"></span>';
                    echo '<span class="gp-count">' . $image_count . ' image' . ($image_count > 1 ? 's' : '') . '</span>';
                    echo '</div>';
                }
                echo '</div>';
                echo '<div class="gp-folder-info">';
                echo '<h3>' . esc_html($folder->folder_name) . '</h3>';
                echo '</div>';
                echo '</a>';
                echo '</div>';
            }
            echo '</div>';
            
            // Pagination
            if ($total_pages > 1) {
                echo '<div class="gp-pagination">';
                
                $current_url = get_permalink();
                $prev_page = ($paged > 1) ? $paged - 1 : 1;
                $next_page = ($paged < $total_pages) ? $paged + 1 : $total_pages;
                
                // Previous button
                if ($paged > 1) {
                    echo '<a href="' . add_query_arg('paged', $prev_page, $current_url) . '" class="page-numbers">&laquo; Previous</a>';
                }
                
                // Page numbers
                $start_page = max(1, $paged - 2);
                $end_page = min($total_pages, $paged + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++) {
                    if ($i == $paged) {
                        echo '<span class="page-numbers current">' . $i . '</span>';
                    } else {
                        echo '<a href="' . add_query_arg('paged', $i, $current_url) . '" class="page-numbers">' . $i . '</a>';
                    }
                }
                
                // Next button
                if ($paged < $total_pages) {
                    echo '<a href="' . add_query_arg('paged', $next_page, $current_url) . '" class="page-numbers">Next &raquo;</a>';
                }
                
                echo '</div>';
            }
        }
        
        echo '</div>';
    }
    
    // ============================================
    // INFINITE SCROLL JAVASCRIPT
    // ============================================
    ?>
    <script>
    (function() {
        console.log('Gallery with Infinite Scroll Loading...');
        
        // Wait for DOM to load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initGallery);
        } else {
            setTimeout(initGallery, 500);
        }
        
        function initGallery() {
            initLightbox();
            initInfiniteScroll();
        }
        
        // Lightbox Function
        function initLightbox() {
            var lightboxLinks = document.querySelectorAll('.gp-lightbox-link');
            
            lightboxLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    var imageUrl = this.href;
                    var imageTitle = this.getAttribute('data-title') || 
                                   this.querySelector('img')?.alt || 
                                   'Gallery Image';
                    
                    openLightbox(imageUrl, imageTitle);
                });
                
                // Add cursor style
                link.style.cursor = 'zoom-in';
            });
        }
        
        // Infinite Scroll Function
        function initInfiniteScroll() {
            var folderId = document.getElementById('gp-folder-id');
            var totalImages = document.getElementById('gp-total-images');
            var imagesGrid = document.getElementById('gp-images-grid');
            var loadMoreBtn = document.getElementById('gp-load-more-btn');
            var loadingContainer = document.getElementById('gp-infinite-loading');
            
            if (!folderId || !imagesGrid) return;
            
            var config = {
                folderId: folderId.value,
                totalImages: parseInt(totalImages?.value || 0),
                perPage: 12,
                currentPage: 1,
                isLoading: false,
                hasMore: true
            };
            
            // Load more button click
            if (loadMoreBtn) {
                loadMoreBtn.addEventListener('click', function() {
                    if (!config.isLoading && config.hasMore) {
                        loadMoreImages(config);
                    }
                });
            }
            
            // Auto-scroll detection
            if (loadingContainer) {
                window.addEventListener('scroll', function() {
                    if (config.isLoading || !config.hasMore) return;
                    
                    var scrollPosition = window.innerHeight + window.scrollY;
                    var containerPosition = loadingContainer.offsetTop + loadingContainer.offsetHeight;
                    
                    // Load when 300px from bottom
                    if (scrollPosition > containerPosition - 300) {
                        loadMoreImages(config);
                    }
                });
            }
        }
        
        function loadMoreImages(config) {
            if (config.isLoading || !config.hasMore) return;
            
            config.isLoading = true;
            config.currentPage++;
            
            console.log('Loading page:', config.currentPage);
            
            // Show loading state
            var loadingContainer = document.getElementById('gp-infinite-loading');
            var loadMoreBtn = document.getElementById('gp-load-more-btn');
            
            if (loadingContainer) {
                loadingContainer.innerHTML = '<div class="gp-loading-spinner"></div><div class="gp-loading-text">Loading more images...</div>';
            }
            
            if (loadMoreBtn) {
                loadMoreBtn.disabled = true;
                loadMoreBtn.innerHTML = 'Loading...';
            }
            
            // AJAX request
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                config.isLoading = false;
                
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        
                        if (response.success) {
                            // Add new images to grid
                            var imagesGrid = document.getElementById('gp-images-grid');
                            
                            // Remove loading container temporarily
                            var loadingContainer = document.getElementById('gp-infinite-loading');
                            if (loadingContainer) {
                                loadingContainer.remove();
                            }
                            
                            // Add new images
                            imagesGrid.insertAdjacentHTML('beforeend', response.data.html);
                            
                            // Re-initialize lightbox for new images
                            initLightbox();
                            
                            // Check if more images available
                            config.hasMore = response.data.has_more;
                            
                            if (config.hasMore) {
                                // Add loading container back
                                imagesGrid.insertAdjacentHTML('beforeend', 
                                    '<div class="gp-infinite-loading" id="gp-infinite-loading">' +
                                    '<div class="gp-loading-spinner"></div>' +
                                    '<div class="gp-loading-text">Scroll down to load more images</div>' +
                                    '</div>'
                                );
                                
                                // Update load more button
                                if (loadMoreBtn) {
                                    loadMoreBtn.disabled = false;
                                    var remaining = response.data.total_images - response.data.total_loaded;
                                    if (remaining > 0) {
                                        loadMoreBtn.innerHTML = 'Load More (' + remaining + ' remaining)';
                                    } else {
                                        loadMoreBtn.style.display = 'none';
                                    }
                                }
                            } else {
                                // No more images
                                imagesGrid.insertAdjacentHTML('beforeend', 
                                    '<div class="gp-no-more-images">All ' + response.data.total_images + ' images loaded</div>'
                                );
                                if (loadMoreBtn) {
                                    loadMoreBtn.style.display = 'none';
                                }
                            }
                        } else {
                            // Error
                            if (loadMoreBtn) {
                                loadMoreBtn.disabled = false;
                                loadMoreBtn.innerHTML = 'Error - Try Again';
                            }
                        }
                    } catch (e) {
                        console.error('Error:', e);
                        if (loadMoreBtn) {
                            loadMoreBtn.disabled = false;
                            loadMoreBtn.innerHTML = 'Error - Try Again';
                        }
                    }
                }
            };
            
            xhr.onerror = function() {
                config.isLoading = false;
                if (loadMoreBtn) {
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.innerHTML = 'Network Error - Try Again';
                }
            };
            
            // Prepare data
            var data = new URLSearchParams();
            data.append('action', 'gp_load_more_images');
            data.append('folder_id', config.folderId);
            data.append('page', config.currentPage);
            data.append('per_page', config.perPage);
            data.append('nonce', '<?php echo wp_create_nonce('gp_ajax_nonce'); ?>');
            
            xhr.send(data);
        }
        
        // Lightbox functions
        function openLightbox(imageUrl, imageTitle) {
            closeLightbox();
            
            var lightboxHTML = `
                <div id="gp-lightbox-overlay" class="gp-lightbox-overlay">
                    <div class="gp-lightbox-content">
                        <img src="${imageUrl}" 
                             alt="${imageTitle}" 
                             class="gp-lightbox-img"
                             style="animation: zoomIn 0.3s ease;">
                        <button class="gp-lightbox-close" onclick="closeLightbox()" title="Close (Esc)">
                            ×
                        </button>
                        <div class="gp-lightbox-title">
                            ${imageTitle}
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', lightboxHTML);
            
            document.getElementById('gp-lightbox-overlay').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeLightbox();
                }
            });
            
            document.addEventListener('keydown', function lightboxKeyHandler(e) {
                if (e.key === 'Escape') {
                    closeLightbox();
                    document.removeEventListener('keydown', lightboxKeyHandler);
                }
            });
        }
        
        function closeLightbox() {
            var lightbox = document.getElementById('gp-lightbox-overlay');
            if (lightbox) {
                lightbox.style.animation = 'fadeOut 0.3s ease';
                setTimeout(function() {
                    if (lightbox.parentNode) {
                        lightbox.parentNode.removeChild(lightbox);
                    }
                }, 300);
            }
        }
        
        // Make functions available globally
        window.closeLightbox = closeLightbox;
        window.loadMoreImages = loadMoreImages;
        
    })();
    </script>
    <?php
    
    return ob_get_clean();
});

/* =====================
   CREATE ASSETS DIRECTORIES
===================== */
add_action('init', function() {
    $assets_dir = GP_PATH . 'assets';
    $css_dir = $assets_dir . '/css';
    $js_dir = $assets_dir . '/js';
    $img_dir = $assets_dir . '/img';
    
    // Create directories if they don't exist
    $directories = [$assets_dir, $css_dir, $js_dir, $img_dir];
    foreach ($directories as $dir) {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
    }
    
    // Create placeholder image if doesn't exist
    $placeholder = $img_dir . '/placeholder.jpg';
    if (!file_exists($placeholder) && function_exists('imagecreatetruecolor')) {
        $img = imagecreatetruecolor(400, 300);
        $bg_color = imagecolorallocate($img, 240, 240, 240);
        $text_color = imagecolorallocate($img, 180, 180, 180);
        imagefill($img, 0, 0, $bg_color);
        imagestring($img, 5, 120, 140, 'No Image', $text_color);
        imagejpeg($img, $placeholder, 80);
        imagedestroy($img);
    }
    
    // Create default CSS files
    $frontend_css = $css_dir . '/frontend.css';
    if (!file_exists($frontend_css)) {
        $css_content = '/* Gallery Frontend CSS */';
        file_put_contents($frontend_css, $css_content);
    }
    
    $admin_css = $css_dir . '/admin.css';
    if (!file_exists($admin_css)) {
        $css_content = '/* Gallery Admin CSS */';
        file_put_contents($admin_css, $css_content);
    }
    
    // Create default JS files
    $admin_js = $js_dir . '/admin.js';
    if (!file_exists($admin_js)) {
        $js_content = 'jQuery(document).ready(function($) {
    console.log("Gallery Admin JS loaded");
});';
        file_put_contents($admin_js, $js_content);
    }
    
    $frontend_js = $js_dir . '/frontend.js';
    if (!file_exists($frontend_js)) {
        $js_content = 'jQuery(document).ready(function($) {
    console.log("Gallery Frontend JS loaded");
});';
        file_put_contents($frontend_js, $js_content);
    }
});
