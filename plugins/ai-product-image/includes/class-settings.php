<?php
/**
 * Класс для управления настройками и страницей админки
 *
 * @package AI_Product_Image
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AI_Product_Image_Settings
 */
class AI_Product_Image_Settings {
    /**
     * Инициализация хуков
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'update_option_ai_image_cron_enabled', [ $this, 'reschedule_cron' ], 10, 2 );
        add_action( 'update_option_ai_image_cron_time', [ $this, 'reschedule_cron' ], 10, 2 );
    }

    /**
     * Регистрирует страницу в меню админки
     */
    public function register_admin_page() {
        add_menu_page(
            'AI Product Image',
            'AI Product Image',
            'manage_options',
            'ai-product-image',
            'ai_product_image_admin_page',
            'dashicons-format-image',
            56
        );
    }

    public function register_settings() {
        register_setting( 'ai_image_settings', 'ai_image_background_summer' );
        register_setting( 'ai_image_settings', 'ai_image_background_winter' );
        register_setting( 'ai_image_settings', 'ai_image_background_allseason' );
        register_setting( 'ai_image_settings', 'ai_image_icon_summer' );
        register_setting( 'ai_image_settings', 'ai_image_icon_winter' );
        register_setting( 'ai_image_settings', 'ai_image_icon_any' );
        register_setting( 'ai_image_settings', 'ai_image_font_bold' );
        register_setting( 'ai_image_settings', 'ai_image_font_semibold' );
        register_setting( 'ai_image_settings', 'ai_image_font_regular' );
        register_setting( 'ai_image_settings', 'ai_image_color_white' );
        register_setting( 'ai_image_settings', 'ai_image_color_black' );
        register_setting( 'ai_image_settings', 'ai_image_color_cyan' );
        register_setting( 'ai_image_settings', 'ai_image_color_light_bg' );
        register_setting( 'ai_image_settings', 'ai_image_color_load_idx_bg' );
        register_setting( 'ai_image_settings', 'ai_image_color_speed_idx_bg' );
        register_setting( 'ai_image_settings', 'ai_image_width' );
        register_setting( 'ai_image_settings', 'ai_image_height' );
        register_setting( 'ai_image_settings', 'ai_image_cron_enabled' );
        register_setting( 'ai_image_settings', 'ai_image_cron_time' );
        register_setting( 'ai_image_settings', 'ai_image_logo_removal_method' );
        register_setting( 'ai_image_settings', 'ai_image_debug_logging' );
    }

    /**
     * Перепланировать крон при изменении настроек
     */
    public function reschedule_cron() {
        if (class_exists('AI_Product_Image_Cron')) {
            $cron = new AI_Product_Image_Cron();
            $cron->maybe_reschedule_cron();
        }
    }
} 