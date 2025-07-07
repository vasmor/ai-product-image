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
     * Обработка batch задач (и результатов) + контроль зависших задач
     */
    public function process_tasks_batch() {
        error_log( '[AI Product Image] Запущена обработка batch задач через cron: ' . date('Y-m-d H:i:s') );
        require_once AI_PRODUCT_IMAGE_PATH . 'includes/class-task-manager.php';
        require_once AI_PRODUCT_IMAGE_PATH . 'includes/class-product-helper.php';
        $tm = new AI_Product_Image_Task_Manager();
        // 1. Контроль зависших задач (processing > 30 мин)
        $now = time();
        $tasks = $tm->get_tasks();
        foreach ($tasks as $task) {
            $task_id = $task['task_id'] ?? '';
            if (!$task_id) continue;
            if (preg_match('/_(\d+)$/', $task_id, $m)) {
                $pid = intval($m[1]);
                $status = AI_Product_Image_Product_Helper::get_status($pid);
                if ($status === 'processing') {
                    $created = strtotime($task['created_at'] ?? '');
                    if ($created && ($now - $created > 1800)) { // 30 минут
                        AI_Product_Image_Product_Helper::set_status($pid, 'error');
                        AI_Product_Image_Product_Helper::set_error($pid, 'Задача зависла (таймаут)');
                    }
                }
            }
        }
        // 2. Запуск задач для queued/error
        $category_id = intval(get_option('ai_image_cron_category_id', 14834));
        $limit = intval(get_option('ai_image_cron_limit', 1000));
        $product_ids = AI_Product_Image_Product_Helper::get_products_by_category_tree($category_id, $limit);
        foreach ($product_ids as $pid) {
            $status = AI_Product_Image_Product_Helper::get_status($pid);
            if (in_array($status, ['queued','error'])) {
                $tm->create_task_for_product($pid);
            }
        }
        // 3. Обновить мета-поля обработанных товаров
        $updated = $tm->process_results();
        if ($updated > 0) {
            error_log('[AI Product Image] Обновлено товаров после AI-обработки (cron): ' . $updated);
        }
    }
} 