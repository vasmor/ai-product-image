<?php
/**
 * Основной класс плагина AI Product Image
 *
 * @package AI_Product_Image
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AI_Product_Image_Plugin
 */
class AI_Product_Image_Plugin {
    /**
     * Экземпляр singleton
     *
     * @var AI_Product_Image_Plugin|null
     */
    private static $instance = null;

    /**
     * Получить экземпляр класса
     *
     * @return AI_Product_Image_Plugin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Конструктор (приватный)
     */
    private function __construct() {
        // Инициализация компонентов плагина
        require_once AI_PRODUCT_IMAGE_PATH . 'admin/admin-page.php';
        require_once AI_PRODUCT_IMAGE_PATH . 'includes/class-settings.php';
        new AI_Product_Image_Settings();
        require_once AI_PRODUCT_IMAGE_PATH . 'includes/class-task-manager.php';
        $this->task_manager = new AI_Product_Image_Task_Manager();
        require_once AI_PRODUCT_IMAGE_PATH . 'includes/class-cron.php';
        new AI_Product_Image_Cron();
        require_once AI_PRODUCT_IMAGE_PATH . 'includes/class-logger.php';
        $this->logger = new AI_Product_Image_Logger();
        require_once AI_PRODUCT_IMAGE_PATH . 'includes/class-product-helper.php';
        // add_action, add_filter и т.д.
    }
} 