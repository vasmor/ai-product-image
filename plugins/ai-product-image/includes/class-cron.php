<?php
/**
 * Класс для интеграции с WP Cron и автоматизации обработки задач
 *
 * @package AI_Product_Image
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AI_Product_Image_Cron
 */
class AI_Product_Image_Cron {
    /**
     * Инициализация хуков
     */
    public function __construct() {
        add_action( 'ai_image_process_tasks_event', [ $this, 'process_tasks_batch' ] );
        add_filter( 'cron_schedules', [ $this, 'add_custom_cron_interval' ] );
        $this->maybe_reschedule_cron();
    }

    /**
     * Добавить пользовательский интервал для крона
     */
    public function add_custom_cron_interval( $schedules ) {
        $interval = intval( get_option('ai_image_cron_time', 15) );
        if ($interval < 1) $interval = 15;
        $schedules['ai_image_custom'] = [
            'interval' => $interval * 60,
            'display'  => 'AI Image Custom (' . $interval . ' мин)'
        ];
        return $schedules;
    }

    /**
     * Перепланировать или удалить cron по настройкам
     */
    public function maybe_reschedule_cron() {
        $enabled = get_option('ai_image_cron_enabled', 0);
        $interval = 'ai_image_custom';
        $hook = 'ai_image_process_tasks_event';
        $next = wp_next_scheduled($hook);
        if (!$enabled) {
            if ($next) wp_clear_scheduled_hook($hook);
            return;
        }
        // Если не запланировано или изменился интервал — пересоздать
        if ($next) wp_clear_scheduled_hook($hook);
        wp_schedule_event(time() + 60, $interval, $hook);
    }

    /**
     * Обработка batch задач (и результатов)
     */
    public function process_tasks_batch() {
        // Здесь будет логика запуска обработки batch задач (например, запуск Python-скрипта)
        // Для теста можно просто логировать вызов
        error_log( '[AI Product Image] Запущена обработка batch задач через cron: ' . date('Y-m-d H:i:s') );
        // Обновить мета-поля обработанных товаров
        require_once AI_PRODUCT_IMAGE_PATH . 'includes/class-task-manager.php';
        $tm = new AI_Product_Image_Task_Manager();
        $updated = $tm->process_results();
        if ($updated > 0) {
            error_log('[AI Product Image] Обновлено товаров после AI-обработки (cron): ' . $updated);
        }
    }
} 