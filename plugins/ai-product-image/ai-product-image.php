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