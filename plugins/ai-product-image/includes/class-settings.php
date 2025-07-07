<?php
if ( ! function_exists( 'ai_product_image_admin_page' ) ) {
    require_once AI_PRODUCT_IMAGE_PATH . 'admin/admin-page.php';
}

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
        add_action( 'update_option_ai_image_background_summer', [ $this, 'copy_template_image' ], 10, 2 );
        add_action( 'update_option_ai_image_background_winter', [ $this, 'copy_template_image' ], 10, 2 );
        add_action( 'update_option_ai_image_background_allseason', [ $this, 'copy_template_image' ], 10, 2 );
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
        register_setting( 'ai_image_settings', 'ai_image_font_bold' );
        register_setting( 'ai_image_settings', 'ai_image_font_semibold' );
        register_setting( 'ai_image_settings', 'ai_image_font_regular' );
        register_setting( 'ai_image_settings', 'ai_image_color_white' );
        register_setting( 'ai_image_settings', 'ai_image_color_black' );
        register_setting( 'ai_image_settings', 'ai_image_width' );
        register_setting( 'ai_image_settings', 'ai_image_height' );
        register_setting( 'ai_image_settings', 'ai_image_cron_enabled' );
        register_setting( 'ai_image_settings', 'ai_image_cron_time' );
        register_setting( 'ai_image_settings', 'ai_image_logo_removal_method' );
        register_setting( 'ai_image_settings', 'ai_image_debug_logging' );
        register_setting( 'ai_image_settings', 'ai_image_runwayml_api_key' );
        register_setting( 'ai_image_settings', 'ai_image_api_secret' );
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

    /**
     * Копирует выбранный шаблон в папку templates с уникальным именем по сезону
     */
    public function copy_template_image($old_value, $new_value) {
        $option_to_season = [
            'ai_image_background_summer' => 'summer',
            'ai_image_background_winter' => 'winter',
            'ai_image_background_allseason' => 'allseason',
        ];
        // Определяем, для какого сезона вызван хук
        $option = current_filter();
        $option = str_replace('update_option_', '', $option);
        $season = $option_to_season[$option] ?? '';
        if (!$season || !$new_value) return;
        $src = get_attached_file($new_value);
        if (!$src || !file_exists($src)) return;
        $upload_dir = wp_upload_dir();
        $dest_dir = trailingslashit($upload_dir['basedir']) . 'ai_image/templates/';
        if (!is_dir($dest_dir)) wp_mkdir_p($dest_dir);
        $ext = pathinfo($src, PATHINFO_EXTENSION);
        $dest = $dest_dir . 'background_' . $season . '.' . $ext;
        copy($src, $dest);
    }
} 