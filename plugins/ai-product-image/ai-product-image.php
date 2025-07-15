<?php
/**
 * Plugin Name: AI Product Image
 * Description: Автоматизация генерации и подмены изображений товаров WooCommerce с помощью AI и Python-скриптов.
 * Version: 0.1.0
 * Author: Vasiliy / AI
 * Text Domain: ai-product-image
 * Domain Path: /languages
 */

// Защита от прямого доступа
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Определение констант
if ( ! defined( 'AI_PRODUCT_IMAGE_PATH' ) ) {
    define( 'AI_PRODUCT_IMAGE_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'AI_PRODUCT_IMAGE_URL' ) ) {
    define( 'AI_PRODUCT_IMAGE_URL', plugin_dir_url( __FILE__ ) );
}

require_once AI_PRODUCT_IMAGE_PATH . 'includes/class-plugin.php';

// Автозагрузка классов
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'AI_Product_Image_' ) === 0 ) {
        $file = AI_PRODUCT_IMAGE_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
} );

// Инициализация плагина
add_action( 'plugins_loaded', function() {
    if ( class_exists( 'AI_Product_Image_Plugin' ) ) {
        AI_Product_Image_Plugin::get_instance();
    }
} );

add_filter('upload_mimes', function($mimes){
    $mimes['ttf'] = 'font/ttf';
    $mimes['otf'] = 'font/otf';
    $mimes['woff'] = 'font/woff';
    $mimes['woff2'] = 'font/woff2';
    return $mimes;
});

add_filter('wp_check_filetype_and_ext', function($data, $file, $filename, $mimes) {
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if (in_array($ext, ['ttf', 'otf', 'woff', 'woff2'])) {
        $data['ext'] = $ext;
        $data['type'] = 'font/' . $ext;
        $data['proper_filename'] = $filename;
    }
    return $data;
}, 10, 4);

add_action('wp_ajax_ai_image_view_log', 'ai_image_view_log_callback');
function ai_image_view_log_callback() {
    $task_id = sanitize_text_field($_GET['task_id'] ?? '');
    $log_file = WP_CONTENT_DIR . '/uploads/ai_image/logs/processor.log';
    header('Content-Type: text/plain; charset=utf-8');
    if (file_exists($log_file)) {
        $lines = file($log_file);
        $filtered = array_filter($lines, function($line) use ($task_id) {
            return strpos($line, $task_id) !== false;
        });
        $last = array_slice($filtered, -100);
        echo esc_html(implode('', $last));
    } else {
        echo 'Лог-файл не найден.';
    }
    wp_die();
}

add_action('wp_ajax_ai_toggle_repeat_product', function() {
    if (!is_user_logged_in() || !current_user_can('edit_products')) wp_send_json_error('Недостаточно прав');
    $product_id = intval($_POST['product_id'] ?? 0);
    $repeat = intval($_POST['repeat'] ?? 0);
    if (!$product_id) wp_send_json_error('Нет ID товара');
    update_post_meta($product_id, '_ai_repeat_product', $repeat ? 1 : 0);
    wp_send_json_success(['repeat' => $repeat ? 1 : 0]);
});

// Вывод кнопки + / - для повторной обработки в карточке товара (single и loop)
add_action('woocommerce_after_shop_loop_item_title', function() {
    if (!is_user_logged_in() || !current_user_can('edit_products')) return;
    global $product;
    if (!$product) return;
    $pid = $product->get_id();
    $sku = $product->get_sku();
    $repeat = get_post_meta($pid, '_ai_repeat_product', true) ? 1 : 0;
    $btn_text = $repeat ? '-' : '+';
    $btn_title = $repeat ? 'Удалить товар из списка повторной обработки' : 'Добавить товар в список повторной обработки';
    echo '<button class="ai-repeat-btn" data-product-id="' . esc_attr($pid) . '" data-sku="' . esc_attr($sku) . '" type="button" style="position:relative;z-index:100;margin:6px 0 0 0;min-width:32px;min-height:32px;font-size:20px;line-height:1.1;" title="' . esc_attr($btn_title) . '">' . esc_html($btn_text) . '</button>';
}, 25);
add_action('woocommerce_single_product_summary', function() {
    if (!is_user_logged_in() || !current_user_can('edit_products')) return;
    global $product;
    if (!$product) return;
    $pid = $product->get_id();
    $sku = $product->get_sku();
    $repeat = get_post_meta($pid, '_ai_repeat_product', true) ? 1 : 0;
    $btn_text = $repeat ? '-' : '+';
    $btn_title = $repeat ? 'Удалить товар из списка повторной обработки' : 'Добавить товар в список повторной обработки';
    echo '<button class="ai-repeat-btn" data-product-id="' . esc_attr($pid) . '" data-sku="' . esc_attr($sku) . '" type="button" style="margin:6px 0 0 0;min-width:32px;min-height:32px;font-size:20px;line-height:1.1;" title="' . esc_attr($btn_title) . '">' . esc_html($btn_text) . '</button>';
}, 35);
// JS для работы с кнопкой повторной обработки
add_action('wp_footer', function() {
    if (!is_product() && !is_shop() && !is_product_category()) return;
    if (!is_user_logged_in() || !current_user_can('edit_products')) return;
    $nonce = wp_create_nonce('ai_repeat_nonce');
    ?>
    <script>
    if (typeof window.ajaxurl === 'undefined' || !window.ajaxurl) {
      window.ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
    }
    </script>
    <script>
    jQuery(function($){
      function updateBtn(btn, repeat) {
        if(repeat) {
          btn.text('-').attr('title','Удалить товар из списка повторной обработки');
        } else {
          btn.text('+').attr('title','Добавить товар в список повторной обработки');
        }
      }
      $('.ai-repeat-btn').on('click', function(){
        var btn = $(this);
        var productId = btn.data('product-id');
        var repeat = btn.text() === '+' ? 1 : 0;
        $.post(ajaxurl, {
          action: 'ai_toggle_repeat_product',
          product_id: productId,
          repeat: repeat,
          _ajax_nonce: '<?php echo esc_js($nonce); ?>'
        }, function(resp){
          if(resp.success){
            updateBtn(btn, resp.data.repeat);
          }
        });
      });
    });
    </script>
    <?php
}); 