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