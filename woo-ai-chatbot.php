<?php
/**
 * Plugin Name: Woo AI ChatBot
 * Plugin URI: https://github.com/fredericowu/woo-ai-chatbot
 * Description: Woo AI ChatBot which will allow user to find products through natural language.
 * Version: 1.0
 * Author: TekFlox
 * Author URI: https://www.tekflox.com/
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: woo-ai-chatbot
 */

// Load the text domain
function aiflowx_chat_load_textdomain() {
    load_plugin_textdomain('aiflowx-chat', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'aiflowx_chat_load_textdomain');

// Enqueue our CSS and JS files
function aiflowx_chat_enqueue_scripts() {
    wp_enqueue_style('aiflowx-chat-style', plugin_dir_url(__FILE__) . 'css/aiflowx-chat.css');
    wp_enqueue_script('aiflowx-chat-script', plugin_dir_url(__FILE__) . 'js/aiflowx-chat.js', array('jquery'), '1.0', true);
    
    wp_localize_script('aiflowx-chat-script', 'aiflowxChat', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('aiflowx_chat_nonce'),
        'pollInterval' => 30000, // Poll every 30 seconds
        'lastSync' => get_option('aiflowx_chat_last_sync'), // Add UTC timestamp
        'hasProfile' => !empty(get_option('aiflowx_chat_profile_uuid')),
        'strings' => array(
            'dayAgo' => __('%d day ago', 'aiflowx-chat'),
            'daysAgo' => __('%d days ago', 'aiflowx-chat'),
            'hourAgo' => __('%d hour ago', 'aiflowx-chat'),
            'hoursAgo' => __('%d hours ago', 'aiflowx-chat'),
            'minuteAgo' => __('%d minute ago', 'aiflowx-chat'),
            'minutesAgo' => __('%d minutes ago', 'aiflowx-chat'),
            'lastSynchronized' => __('Last synchronized: %s (%s)', 'aiflowx-chat'),
        )
    ));
}
add_action( 'wp_enqueue_scripts', 'aiflowx_chat_enqueue_scripts' );

// Output the chat window markup in the footer
function aiflowx_chat_markup() {
    if (empty(get_option('aiflowx_chat_profile_uuid'))) {
        return; // Don't output chat markup if no profile UUID is set
    }
    ?>
    <div id="aiflowx-chat">
        <!-- Floating Chat Icon -->
        <div id="aiflowx-chat-icon">
            <img src="<?php echo plugin_dir_url( __FILE__ ) . 'images/chat-icon.svg'; ?>" alt="Chat Icon">
        </div>
        <!-- Chat Window -->
        <div id="aiflowx-chat-window" style="display: none;">
            <div id="aiflowx-chat-header">
                <?php esc_html_e('Ajuda', 'aiflowx-chat'); ?>
                <span id="aiflowx-chat-close">&times;</span>
            </div>
            <div id="aiflowx-chat-messages"></div>
            <div id="aiflowx-chat-input">
                <input type="text" id="aiflowx-chat-message-input" placeholder="<?php esc_attr_e('Type your message...', 'aiflowx-chat'); ?>">
                <button id="aiflowx-chat-send-btn"><?php esc_html_e('Send', 'aiflowx-chat'); ?></button>
            </div>
        </div>
    </div>
    <?php
}
add_action( 'wp_footer', 'aiflowx_chat_markup' );

function aiflowx_chat_custom_styles() {
    // Get colors from theme or use defaults
    $primary_color = get_theme_mod('primary_color', '#0073aa');
    $button_text_color = get_theme_mod('button_text_color', '#ffffff');
    
    $custom_css = "
        #aiflowx-chat-input button {
            background-color: {$primary_color} !important;
            color: {$button_text_color} !important;
        }
        #aiflowx-chat-input button:hover {
            background-color: " . adjust_brightness($primary_color, -20) . " !important;
        }
    ";
    wp_add_inline_style('aiflowx-chat-style', $custom_css);
}

// Helper function to darken/lighten colors for hover state
function adjust_brightness($hex, $steps) {
    $hex = str_replace('#', '', $hex);
    
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $r = max(0, min(255, $r + $steps));
    $g = max(0, min(255, $g + $steps));
    $b = max(0, min(255, $b + $steps));

    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

add_action('wp_enqueue_scripts', 'aiflowx_chat_custom_styles', 20);

// Add Settings Page
function aiflowx_chat_add_admin_menu() {
    add_options_page(
        'Woo AI ChatBot Settings',
        'Woo AI ChatBot',
        'manage_options',
        'aiflowx-chat-settings',
        'aiflowx_chat_settings_page'
    );
}
add_action('admin_menu', 'aiflowx_chat_add_admin_menu');

// Register Settings
function aiflowx_chat_register_settings() {
    register_setting('aiflowx_chat_settings', 'aiflowx_chat_api_host');
    register_setting('aiflowx_chat_settings', 'aiflowx_chat_profile_uuid');
    register_setting('aiflowx_chat_settings', 'aiflowx_chat_last_sync'); // Add this line
}
add_action('admin_init', 'aiflowx_chat_register_settings');

// Settings Page Content
function aiflowx_chat_settings_page() {
    if (isset($_GET['settings-updated'])) {
        // When settings are saved, call wordpress-active endpoint
        $uuid = get_option('aiflowx_chat_profile_uuid');
        if (!empty($uuid)) {
            aiflowx_chat_update_activation_status($uuid, true);
        }
    }
    $current_plan = aiflowx_chat_get_current_plan();
    if (isset($_POST['aiflowx_chat_sync'])) {
        $sync_result = aiflowx_chat_sync_content();
        if ($sync_result['success']) {
            echo '<div class="notice notice-success"><p>Content synchronized successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error synchronizing content: ' . esc_html($sync_result['error']) . '</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <!-- Settings Form -->
        <form action="options.php" method="post">
            <?php
            settings_fields('aiflowx_chat_settings');
            do_settings_sections('aiflowx_chat_settings');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="aiflowx_chat_api_host">API Host</label>
                    </th>
                    <td>
                        <input type="text" id="aiflowx_chat_api_host" name="aiflowx_chat_api_host" 
                               value="<?php echo esc_attr(get_option('aiflowx_chat_api_host', 'https://api.tekflox.com')); ?>" 
                               class="regular-text">
                        <p class="description">Default: https://api.tekflox.com</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="aiflowx_chat_profile_uuid">Profile UUID</label>
                    </th>
                    <td>
                        <input type="text" id="aiflowx_chat_profile_uuid" name="aiflowx_chat_profile_uuid" 
                               value="<?php echo esc_attr(get_option('aiflowx_chat_profile_uuid')); ?>" 
                               class="regular-text">
                        <?php 
                        $profile_uuid = get_option('aiflowx_chat_profile_uuid');
                        if ($profile_uuid) {
                            echo '<button type="button" id="aiflowx_unlink_profile" class="button button-secondary">Unlink Profile</button>';
                        } else {
                            echo '<button type="button" id="aiflowx_create_profile" class="button button-primary">Create Profile For Free</button>';
                        }
                        ?>
                        <p class="description">Your unique profile identifier</p>
                        <style>
                            #aiflowx_unlink_profile { margin-left: 10px; }
                            #aiflowx_create_profile { margin-left: 10px; }
                        </style>
                        <script>
                        jQuery(document).ready(function($) {
                            // Original UUID value for change detection
                            var originalUUID = $('#aiflowx_chat_profile_uuid').val();
                            
                            // Handle UUID field changes
                            $('#aiflowx_chat_profile_uuid').on('change', function() {
                                if (originalUUID && this.value !== originalUUID) {
                                    if (!confirm('Are you sure you want to change the Profile UUID? This may affect your chat functionality.')) {
                                        this.value = originalUUID;
                                    }
                                }
                            });

                            // Handle Unlink Profile button
                            $('#aiflowx_unlink_profile').on('click', function() {
                                if (confirm('Are you sure you want to unlink this profile? This will remove your current profile association.')) {
                                    $('#aiflowx_chat_profile_uuid').val('');
                                    // Use WordPress's built-in delete_option function
                                    $.post(ajaxurl, {
                                        action: 'aiflowx_delete_profile_uuid',
                                        nonce: '<?php echo wp_create_nonce("aiflowx_delete_profile_uuid_nonce"); ?>'
                                    }, function() {
                                        $('form').submit();
                                    });
                                }
                            });

                            // Handle Create Profile button
                            $('#aiflowx_create_profile').on('click', function() {
                                var button = $(this);
                                button.prop('disabled', true);
                                button.text('Creating...');
                                
                                // Call the activation function via AJAX
                                $.post(ajaxurl, {
                                    action: 'aiflowx_create_profile',
                                    nonce: '<?php echo wp_create_nonce("aiflowx_create_profile_nonce"); ?>'
                                }, function(response) {
                                    if (response.success) {
                                        location.reload();
                                    } else {
                                        alert('Failed to create profile. Please try again.');
                                        button.prop('disabled', false);
                                        button.text('Create Profile For Free');
                                    }
                                });
                            });
                        });
                        </script>
                    </td>
                </tr>
                <?php if ($profile_uuid): ?>
                <tr>
                    <th scope="row">
                        <label>Current Plan</label>
                    </th>
                    <td>
                        <input type="text" value="<?php echo esc_attr($current_plan); ?>" 
                               class="regular-text" readonly>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            <?php submit_button(); ?>
        </form>

        <?php if ($profile_uuid): ?>
        <!-- Sync Button Form -->
        <h2>Content Synchronization</h2>
        <?php 
        $last_sync = get_option('aiflowx_chat_last_sync');
        if ($last_sync) {
            echo '<p id="last-sync-time"></p>';
        }
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('aiflowx_chat_sync_action', 'aiflowx_chat_sync_nonce'); ?>
            <p class="submit">
                <input type="submit" name="aiflowx_chat_sync" class="button button-primary" value="Sync Products & Posts">
            </p>
        </form>
        <?php endif; ?>

        <!-- Add JavaScript to handle timezone conversion -->
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            function updateLastSyncTime() {
                if (!aiflowxChat.lastSync) return;
                
                try {
                    var date = new Date(aiflowxChat.lastSync + 'Z'); // Parse as UTC
                    if (isNaN(date.getTime())) {
                        console.error('Invalid date:', aiflowxChat.lastSync);
                        return;
                    }

                    var now = new Date();
                    var diff = Math.floor((now - date) / 1000); // difference in seconds
                    
                    var timeAgo;
                    if (diff < 3600) {
                        var minutes = Math.floor(diff / 60);
                        timeAgo = minutes === 1 
                            ? aiflowxChat.strings.minuteAgo.replace('%d', minutes)
                            : aiflowxChat.strings.minutesAgo.replace('%d', minutes);
                    } else if (diff < 86400) {
                        var hours = Math.floor(diff / 3600);
                        timeAgo = hours === 1
                            ? aiflowxChat.strings.hourAgo.replace('%d', hours)
                            : aiflowxChat.strings.hoursAgo.replace('%d', hours);
                    } else {
                        var days = Math.floor(diff / 86400);
                        timeAgo = days === 1
                            ? aiflowxChat.strings.dayAgo.replace('%d', days)
                            : aiflowxChat.strings.daysAgo.replace('%d', days);
                    }

                    // Format date using local timezone
                    var formattedDate = date.toLocaleString(undefined, {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: true
                    });
                    
                    var message = aiflowxChat.strings.lastSynchronized
                        .replace('%s', formattedDate)
                        .replace('%s', timeAgo);
                        
                    $('#last-sync-time').text(message);
                } catch (e) {
                    console.error('Error updating sync time:', e);
                }
            }
            
            updateLastSyncTime();
            setInterval(updateLastSyncTime, 60000); // Update every minute
        });
        </script>
    </div>
    <?php
}

function aiflowx_chat_sync_content() {
    if (!isset($_POST['aiflowx_chat_sync_nonce']) || 
        !wp_verify_nonce($_POST['aiflowx_chat_sync_nonce'], 'aiflowx_chat_sync_action')) {
        return ['success' => false, 'error' => 'Invalid nonce'];
    }

    aiflowx_chat_sync_content_internal();

}
function aiflowx_chat_sync_content_internal() {
    $data = [];

    // Get WooCommerce products if WooCommerce is active
    if (class_exists('WooCommerce')) {
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
        ]);

        foreach ($products as $product) {
            // Skip products that are out of stock or password protected
            if ($product->get_stock_status() === 'outofstock' || post_password_required($product->get_id())) {
                continue;
            }

            // Get all product attributes
            $attributes = [];
            if ($product->get_attributes()) {
                foreach ($product->get_attributes() as $attribute) {
                    if ($attribute->is_taxonomy()) {
                        $attribute_values = wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names']);
                        if (!empty($attribute_values)) {
                            $attributes[$attribute->get_name()] = implode(', ', $attribute_values);
                        }
                    } else {
                        $options = $attribute->get_options();
                        if (!empty($options)) {
                            $attributes[$attribute->get_name()] = $options;
                        }
                    }
                }
            }

            // Get all product categories with full hierarchy
            $category_ids = $product->get_category_ids();
            $categories_hierarchy = array();
            foreach ($category_ids as $cat_id) {
                $category_tree = [];
                $current_cat = get_term($cat_id, 'product_cat');
                while ($current_cat) {
                    array_unshift($category_tree, $current_cat->name);
                    $current_cat = ($current_cat->parent) ? get_term($current_cat->parent, 'product_cat') : null;
                }
                $categories_hierarchy[] = implode(' > ', $category_tree);
            }

            // Get product images
            $images = [];
            $image_id = $product->get_image_id();
            if ($image_id) {
                $image_url = wp_get_attachment_url($image_id);
                if ($image_url) {
                    $images[] = $image_url;
                }
            }
            
            // Get gallery images
            $gallery_image_ids = $product->get_gallery_image_ids();
            foreach ($gallery_image_ids as $gallery_image_id) {
                $gallery_image_url = wp_get_attachment_url($gallery_image_id);
                if ($gallery_image_url) {
                    $images[] = $gallery_image_url;
                }
            }

            $current_price = $product->get_sale_price() ?: $product->get_price();
            $original_price = $product->get_regular_price();
            $discount_pct = null;
            
            // Only calculate and include original price and discount if there's actually a difference
            if ($original_price > $current_price) {
                $discount_pct = round((($original_price - $current_price) / $original_price) * 100);
            } else {
                $original_price = null;  // Don't include if same as current price
            }

            // Prepare content object with only non-empty values
            $content_obj = array_filter([
                "type" => "product",
                "id" => $product->get_id(),
                "url" => get_permalink($product->get_id()),
                "product_name" => $product->get_name(),
                "short_description" => wp_strip_all_tags($product->get_short_description()),
                "full_description" => wp_strip_all_tags($product->get_description()),
                "sku" => $product->get_sku(),
                "current_price" => $current_price,
                "original_price" => $original_price,
                "discount_pct" => $discount_pct,
                "categories" => $categories_hierarchy,
                "tags" => strip_tags(wc_get_product_tag_list($product->get_id())),
                "attributes" => !empty($attributes) ? $attributes : null,
                "images" => !empty($images) ? $images : null,
            ], function($value) {
                return $value !== null && $value !== '' && $value !== [] && $value !== 0;
            });

            $data[] = [
                'content' => json_encode($content_obj, JSON_UNESCAPED_UNICODE),
                'metadata' => [
                    'content_type' => 'product'
                ]
            ];
        }
    }

    // Get published posts that are not password protected and not private
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'publish',
        'numberposts' => -1,
        'has_password' => false,
        'post__not_in' => get_private_posts_ids()
    ]);

    // Format posts as JSON with only non-empty values
    foreach ($posts as $post) {
        $content_obj = array_filter([
            "type" => "post",
            "id" => $post->ID,
            "url" => get_permalink($post->ID),
            "title" => $post->post_title,
            "content" => wp_strip_all_tags($post->post_content),
            "categories" => strip_tags(get_the_category_list(', ', '', $post->ID)),
            "tags" => strip_tags(get_the_tag_list('', ', ', '', $post->ID)),
            "date" => $post->post_date
        ], function($value) {
            return $value !== null && $value !== '';
        });

        $data[] = [
            'content' => json_encode($content_obj, JSON_UNESCAPED_UNICODE),
            'metadata' => [
                'content_type' => 'post'
            ]
        ];
    }

    // Send data to API
    $api_host = get_option('aiflowx_chat_api_host', 'https://api.tekflox.com');
    $profile_uuid = get_option('aiflowx_chat_profile_uuid');
    
    if (!$profile_uuid) {
        return ['success' => false, 'error' => 'Profile UUID not configured'];
    }

    $response = wp_remote_post($api_host . '/api/retriever/sync/', [
        'headers' => [
            'Content-Type' => 'application/json',
            'account-profile' => $profile_uuid
        ],
        'body' => json_encode(['data' => $data]),
        'timeout' => 60
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code === 200) {
        update_option('aiflowx_chat_last_sync', gmdate('Y-m-d H:i:s')); // Use gmdate to store in UTC
        return ['success' => true];
    }

    return ['success' => false, 'error' => 'API returned status code: ' . $response_code];    
}

// Helper function to get private post IDs
function get_private_posts_ids() {
    global $wpdb;
    return $wpdb->get_col(
        "SELECT ID FROM $wpdb->posts 
        WHERE post_type = 'post' 
        AND (
            post_status = 'private'
            OR EXISTS (
                SELECT 1 FROM $wpdb->postmeta 
                WHERE post_id = ID 
                AND meta_key = '_visibility' 
                AND meta_value = 'private'
            )
        )"
    );
}

// Function to get current plan
function aiflowx_chat_get_current_plan() {
    $uuid = get_option('aiflowx_chat_profile_uuid');
    if (empty($uuid)) {
        return 'Free';
    }

    $api_host = get_option('aiflowx_chat_api_host', 'https://api.tekflox.com');
    $api_endpoint = rtrim($api_host, '/') . "/api/account/profile/{$uuid}/plan/";
    
    $response = wp_remote_get($api_endpoint, array(
        'headers' => array(
            'Content-Type' => 'application/json'
        ),
        'timeout' => 20
    ));

    if (is_wp_error($response)) {
        return 'Free';
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return isset($body['name']) ? $body['name'] : 'Free';
}

// Handle the AJAX request
function format_message_content($content) {
    return preg_replace_callback(
        '/\b(?:http|https):\/\/[^\s<>\[\]]+/i',
        function($matches) {
            $url = $matches[0];
            $current_domain = $_SERVER['HTTP_HOST'];
            $url_parts = parse_url($url);
            $url_host = isset($url_parts['host']) ? preg_replace('/:\d+$/', '', $url_parts['host']) : '';
            $current_domain = preg_replace('/:\d+$/', '', $current_domain);
            $is_internal = $url_host === $current_domain;

            $target = $is_internal ? '' : ' target="_blank"';
            $image_url = getOgImageFromUrl($url);

            $param_split = (strpos($url, '?') !== false) ? "&" : "?";
            $url_show_chat = $url . $param_split. "show_chat=1";
            
            if ($image_url) {
                return sprintf(
                    '<a href="%s"%s>%s</a><a href="%s"%s class="url-preview-link"><div class="url-preview-image"><img src="%s" alt="URL Preview" class="chat-preview-image"></div></a>',
                    esc_url($url_show_chat),
                    $target,
                    esc_url($url),
                    esc_url($url_show_chat),
                    $target,
                    esc_url($image_url)
                );
            }
            return sprintf('<a href="%s"%s>%s</a>', esc_url($url_show_chat), $target, esc_url($url));
        },
        $content
    );
}

function aiflowx_chat_process_message() {
    check_ajax_referer('aiflowx_chat_nonce', 'nonce');
    
    $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';
    $visitor_id = isset($_POST['visitor_id']) ? sanitize_text_field($_POST['visitor_id']) : 'visitor';
    $last_message_id = isset($_POST['last_message_id']) ? intval($_POST['last_message_id']) : 0;
    
    // Get configured values and construct the endpoint
    $api_host = get_option('aiflowx_chat_api_host', 'https://api.tekflox.com');
    $api_endpoint = rtrim($api_host, '/') . '/api/bot/chat/';
    $profile_uuid = get_option('aiflowx_chat_profile_uuid', '');
    $include_sent = isset($_POST['include_sent']) ? $_POST['include_sent'] === "true" : false;
    
    // Prepare the request
    $args = array(
        'body' => json_encode(array(
            'from_contact' => $visitor_id,
            'message' => $message,
            'nowait' => isset($_POST['nowait']) ? "1" : "0",
            'last_message_id' => $last_message_id,
            'include_sent' => $include_sent
        )),
        'headers' => array(
            'Content-Type' => 'application/json',
            'account-profile' => $profile_uuid
        ),
        'method' => 'POST',
        'timeout' => 20
    );

    // Make the API request
    $response = wp_remote_post($api_endpoint, $args);

    if (is_wp_error($response)) {
        error_log("Chat API Error: " . $response->get_error_message());
        wp_send_json(array('status' => 'error'), 503);
        return;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    // Handle no content response
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code === 204) {
        wp_send_json(array('status' => 'no_content'), 204);
        return;
    }

    if ($body['status'] === 'success' && !empty($body['messages'])) {
        foreach ($body['messages'] as &$msg) {
            $msg['content'] = format_message_content($msg['content']);
        }


    }
    wp_send_json($body);
}

/**
 * Fetches the HTML from a given URL and extracts the og:image meta tag with caching.
 *
 * @param string $url The URL to fetch.
 * @return string|null The URL of the image from the og:image tag, or null if not found.
 */
function getOgImageFromUrl($url) {
    try {
        // Generate a cache key based on the URL
        $cache_key = 'aiflowx_og_image_' . md5($url);
        
        // Check if cached value exists
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached === 'null' ? null : $cached;
        }

        // Modify headers to mimic a browser request
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 5, // Reduced timeout
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5'
            ]
        ]);
        
        $html = curl_exec($ch);
        
        // If any error occurs, just return null
        if (curl_errno($ch)) {
            curl_close($ch);
            set_transient($cache_key, 'null', HOUR_IN_SECONDS);
            return null;
        }
        
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($status_code !== 200 || !$html) {
            set_transient($cache_key, 'null', HOUR_IN_SECONDS);
            return null;
        }
        
        // Attempt to parse HTML and find og:image
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        libxml_clear_errors();
        
        $metas = $doc->getElementsByTagName('meta');
        foreach ($metas as $meta) {
            if ($meta->getAttribute('property') === 'og:image') {
                $image_url = $meta->getAttribute('content');
                set_transient($cache_key, $image_url, DAY_IN_SECONDS);
                return $image_url;
            }
        }
        
        set_transient($cache_key, 'null', HOUR_IN_SECONDS);
        return null;
        
    } catch (Exception $e) {
        // If anything goes wrong, just return null
        error_log("Error fetching og:image: " . $e->getMessage());
        return null;
    }
}

add_action('wp_ajax_aiflowx_chat_message', 'aiflowx_chat_process_message');
add_action('wp_ajax_nopriv_aiflowx_chat_message', 'aiflowx_chat_process_message');

// Handle plugin activation via UI click
function aiflowx_chat_handle_plugin_activation($plugin) {
    if ($plugin === plugin_basename(__FILE__)) {
        $uuid = get_option('aiflowx_chat_profile_uuid');
        if (!empty($uuid)) {
            aiflowx_chat_update_activation_status($uuid, true);
        } else {
            // If no UUID exists, run the full activation process
            aiflowx_chat_activate();
        }
    }
}
add_action('activated_plugin', 'aiflowx_chat_handle_plugin_activation');

// Register profile on activation
function aiflowx_chat_activate() {
    // Check if we already have a profile UUID
    $existing_uuid = get_option('aiflowx_chat_profile_uuid');
    if (!empty($existing_uuid)) {
        // Even if UUID exists, ensure we call the activation status update
        aiflowx_chat_update_activation_status($existing_uuid, true);
        return;
    }

    // Delete last sync time when activating
    delete_option('aiflowx_chat_last_sync');

    $api_host = get_option('aiflowx_chat_api_host', 'https://api.tekflox.com');
    $api_endpoint = rtrim($api_host, '/') . '/api/account/register/';
    
    $site_url = get_site_url();
    $admin_email = get_option('admin_email');
    
    // Register profile
    $response = wp_remote_post($api_endpoint, array(
        'body' => json_encode(array(
            'name' => get_bloginfo('name'),
            'email' => $admin_email,
            'website' => $site_url
        )),
        'headers' => array(
            'Content-Type' => 'application/json'
        ),
        'method' => 'POST',
        'timeout' => 20
    ));

    if (!is_wp_error($response)) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['uuid'])) {
            $uuid = $body['uuid'];
            update_option('aiflowx_chat_profile_uuid', $uuid);
            // Set wordpress_activated to true
            aiflowx_chat_update_activation_status($uuid, true);
        }
    }
}

register_activation_hook(__FILE__, 'aiflowx_chat_activate');

// Update WordPress activation status
function aiflowx_chat_update_activation_status($uuid, $activated) {
    $api_host = get_option('aiflowx_chat_api_host', 'https://api.tekflox.com');
    $api_endpoint = rtrim($api_host, '/') . "/api/account/wordpress-active/{$uuid}/";
    
    $response = wp_remote_post($api_endpoint, array(
        'body' => json_encode(array(
            'activated' => $activated
        )),
        'headers' => array('Content-Type' => 'application/json'),
        'method' => 'POST',
        'timeout' => 20
    ));

    return !is_wp_error($response);
}

// Add deactivation hook
function aiflowx_chat_deactivate() {
    $uuid = get_option('aiflowx_chat_profile_uuid');
    if (!empty($uuid)) {
        aiflowx_chat_update_activation_status($uuid, false);
    }
}

register_deactivation_hook(__FILE__, 'aiflowx_chat_deactivate');

// Add Admin Scripts
function aiflowx_chat_admin_enqueue_scripts($hook) {
    // Only load on our plugin's settings page
    if ($hook != 'settings_page_aiflowx-chat-settings') {
        return;
    }

    // Add AJAX URL and nonce to our script
    wp_localize_script('jquery', 'aiflowxChat', array(
        'lastSync' => get_option('aiflowx_chat_last_sync'),
        'strings' => array(
            'dayAgo' => __('%d day ago', 'aiflowx-chat'),
            'daysAgo' => __('%d days ago', 'aiflowx-chat'),
            'hourAgo' => __('%d hour ago', 'aiflowx-chat'),
            'hoursAgo' => __('%d hours ago', 'aiflowx-chat'),
            'minuteAgo' => __('%d minute ago', 'aiflowx-chat'),
            'minutesAgo' => __('%d minutes ago', 'aiflowx-chat'),
            'lastSynchronized' => __('Last synchronized: %s (%s)', 'aiflowx-chat'),
        )
    ));
}
add_action('admin_enqueue_scripts', 'aiflowx_chat_admin_enqueue_scripts');

// Add AJAX handler for profile creation
function aiflowx_chat_create_profile() {
    check_ajax_referer('aiflowx_create_profile_nonce', 'nonce');
    
    aiflowx_chat_activate();
    wp_send_json_success();
}
add_action('wp_ajax_aiflowx_create_profile', 'aiflowx_chat_create_profile');

// Add AJAX handler for profile UUID deletion
function aiflowx_delete_profile_uuid() {
    check_ajax_referer('aiflowx_delete_profile_uuid_nonce', 'nonce');
    delete_option('aiflowx_chat_profile_uuid');
    wp_send_json_success();
}
add_action('wp_ajax_aiflowx_delete_profile_uuid', 'aiflowx_delete_profile_uuid');

// Schedule the cron event on plugin activation
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('aiflowx_daily_sync')) {
        wp_schedule_event(time(), 'daily', 'aiflowx_daily_sync');
    }
});

// Remove the cron job on plugin deactivation
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('aiflowx_daily_sync');
});

// Hook the sync function to the cron event
add_action('aiflowx_daily_sync', function() {
    $last_sync = get_option('aiflowx_chat_last_sync');
    $profile_uuid = get_option('aiflowx_chat_profile_uuid');
    
    if (!$profile_uuid) {
        error_log('AIFlow X Chat: No profile UUID configured for sync');
        return;
    }

    // Only sync if last sync was more than 20 hours ago
    if ($last_sync) {
        $last_sync_time = strtotime($last_sync);
        $hours_since_sync = (time() - $last_sync_time) / 3600;
        if ($hours_since_sync < 20) {
            return;
        }
    }

    error_log('AIFlow X Chat: Starting daily content sync');
    $result = aiflowx_chat_sync_content_internal();
    
    if ($result['success']) {
        error_log('AIFlow X Chat: Daily sync completed successfully');
    } else {
        error_log('AIFlow X Chat: Daily sync failed: ' . $result['error']);
    }
});
?>