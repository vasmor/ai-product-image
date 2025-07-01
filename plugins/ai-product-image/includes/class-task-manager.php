<?php
/**
 * Класс для управления заданиями AI Product Image
 *
 * @package AI_Product_Image
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AI_Product_Image_Task_Manager
 */
class AI_Product_Image_Task_Manager {
    /**
     * Путь к папке с заданиями
     * @var string
     */
    private $tasks_dir;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->tasks_dir = trailingslashit( $upload_dir['basedir'] ) . 'ai_image/tasks/';
    }

    /**
     * Получить список всех задач
     * @return array
     */
    public function get_tasks() {
        $tasks = [];
        if ( ! is_dir( $this->tasks_dir ) ) {
            return $tasks;
        }
        foreach ( glob( $this->tasks_dir . '*.json' ) as $file ) {
            $json = file_get_contents( $file );
            $data = json_decode( $json, true );
            if ( $data ) {
                $tasks[] = $data;
            }
        }
        return $tasks;
    }

    /**
     * Создать новое задание
     * @param array $task_data
     * @return bool
     */
    public function create_task( $task_data ) {
        if ( ! $this->validate_task_data( $task_data ) ) {
            return false;
        }
        $task_id = $task_data['task_id'] ?? uniqid( 'task_', true );
        // Если есть путь к оригинальному изображению — делаем резервную копию
        if ( !empty($task_data['original_image_path']) ) {
            $ext = pathinfo($task_data['original_image_path'], PATHINFO_EXTENSION);
            $new_name = $task_id . '.' . $ext;
            $this->backup_original_image($task_data['original_image_path'], $new_name);
        }
        $file = $this->tasks_dir . $task_id . '.json';
        $json = json_encode( $task_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        return file_put_contents( $file, $json ) !== false;
    }

    /**
     * Получить результат задачи по task_id
     * @param string $task_id
     * @return array|null
     */
    public function get_result( $task_id ) {
        $upload_dir = wp_upload_dir();
        $results_dir = trailingslashit( $upload_dir['basedir'] ) . 'ai_image/results/';
        $file = $results_dir . $task_id . '.json';
        if ( file_exists( $file ) ) {
            $json = file_get_contents( $file );
            $data = json_decode( $json, true );
            return $data ?: null;
        }
        return null;
    }

    /**
     * Скопировать оригинальное изображение товара в папку originals
     * @param string $image_path Абсолютный путь к файлу
     * @param string $new_name Новое имя файла
     * @return string|null Путь к скопированному файлу или null
     */
    public function backup_original_image( $image_path, $new_name ) {
        $upload_dir = wp_upload_dir();
        $originals_dir = trailingslashit( $upload_dir['basedir'] ) . 'ai_image/originals/';
        if ( ! is_dir( $originals_dir ) ) {
            wp_mkdir_p( $originals_dir );
        }
        $dest = $originals_dir . $new_name;
        if ( file_exists( $image_path ) && copy( $image_path, $dest ) ) {
            return $dest;
        }
        return null;
    }

    /**
     * Базовая валидация данных задачи
     * @param array $task_data
     * @return bool
     */
    public function validate_task_data( $task_data ) {
        if ( empty( $task_data['task_id'] ) || empty( $task_data['product_data'] ) ) {
            return false;
        }
        // Можно добавить дополнительные проверки (например, на обязательные поля)
        return true;
    }

    /**
     * Создать задачу на обработку для товара
     * @param int $product_id
     * @param array $settings (опционально)
     * @return bool
     */
    public function create_task_for_product( $product_id, $settings = [] ) {
        $product = wc_get_product($product_id);
        if (!$product) return false;
        $brand = $product->get_attribute('pa_brend');
        $model = $product->get_attribute('pa_model');
        $width = $product->get_attribute('pa_shirina-profilja-v-mm');
        $height = $product->get_attribute('pa_vysota-profilja-v-procentah');
        $diameter = $product->get_attribute('pa_diametr-v-djujmah');
        $load_idx = $product->get_attribute('pa_indeks-nagruzki');
        $speed_idx = $product->get_attribute('pa_indeks-skorosti');
        $season = $product->get_attribute('pa_sezonnost');
        $image_id = $product->get_image_id();
        $image_url = wp_get_attachment_url($image_id);
        $image_path = get_attached_file($image_id);
        // Выбор фона и иконки по сезону
        $season_lc = mb_strtolower($season);
        if ($season_lc === 'летняя') {
            $background = get_option('ai_image_background_summer');
            $icon = get_option('ai_image_icon_summer');
        } elseif ($season_lc === 'зимняя') {
            $background = get_option('ai_image_background_winter');
            $icon = get_option('ai_image_icon_winter');
        } elseif ($season_lc === 'всесезонная') {
            $background = get_option('ai_image_background_allseason');
            $icon = get_option('ai_image_icon_any');
        } else {
            $background = get_option('ai_image_background_summer');
            $icon = get_option('ai_image_icon_any');
        }
        $output_filename = 'processed/product_' . $product_id . '_ai.png';
        $task_id = date('Ymd_His') . '_' . $product_id;
        $debug_logging = get_option('ai_image_debug_logging', 0);
        $task = [
            'task_id' => $task_id,
            'type' => 'tyre',
            'original_image' => 'originals/' . basename($image_path),
            'template' => 'templates/' . basename(get_attached_file($background)),
            'icon' => 'logos/' . basename(get_attached_file($icon)),
            'product_data' => [
                'brand' => $brand,
                'model' => $model,
                'width' => $width,
                'height' => $height,
                'diameter' => 'R' . $diameter,
                'load_index' => $load_idx,
                'speed_index' => $speed_idx,
                'season' => $season,
            ],
            'output_filename' => $output_filename,
            'created_at' => current_time('mysql'),
            'params' => array_merge([
                'font_bold' => self::resolve_font_path(get_option('ai_image_font_bold')),
                'font_semibold' => self::resolve_font_path(get_option('ai_image_font_semibold')),
                'font_regular' => self::resolve_font_path(get_option('ai_image_font_regular')),
                'color_white' => get_option('ai_image_color_white'),
                'color_black' => get_option('ai_image_color_black'),
                'color_cyan' => get_option('ai_image_color_cyan'),
                'color_light_bg' => get_option('ai_image_color_light_bg'),
                'color_load_idx_bg' => get_option('ai_image_color_load_idx_bg'),
                'color_speed_idx_bg' => get_option('ai_image_color_speed_idx_bg'),
                'width' => get_option('ai_image_width', 620),
                'height' => get_option('ai_image_height', 826),
                'logo_removal_method' => get_option('ai_image_logo_removal_method', 'opencv'),
                'debug_logging' => $debug_logging ? true : false,
            ], $settings)
        ];
        // Копируем оригинал в originals/
        $this->backup_original_image($image_path, basename($image_path));
        // Сохраняем задачу в tasks/
        $file = $this->tasks_dir . $task_id . '.json';
        $json = json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return file_put_contents($file, $json) !== false;
    }

    /**
     * Обработать результаты: для успешных задач записать _ai_image_processed
     * @return int Количество обновлённых товаров
     */
    public function process_results() {
        $count = 0;
        $upload_dir = wp_upload_dir();
        $results_dir = trailingslashit( $upload_dir['basedir'] ) . 'ai_image/results/';
        foreach (glob($results_dir . '*.json') as $file) {
            $json = file_get_contents($file);
            $data = json_decode($json, true);
            if (!$data || $data['status'] !== 'success') continue;
            // task_id вида 20240610_12345_6789, где 12345 — ID товара
            if (preg_match('/_(\d+)$/', $data['task_id'], $m)) {
                $product_id = intval($m[1]);
                if ($product_id && get_post_type($product_id) === 'product') {
                    update_post_meta($product_id, '_ai_image_processed', $data['task_id']);
                    $count++;
                }
            }
        }
        return $count;
    }

    public static function resolve_font_path($val) {
        if (is_numeric($val)) {
            $url = wp_get_attachment_url($val);
            $upload_dir = wp_upload_dir();
            if ($url && strpos($url, $upload_dir['baseurl']) === 0) {
                // Преобразуем URL в относительный путь от uploads
                $rel = 'uploads' . str_replace($upload_dir['baseurl'], '', $url);
                return $rel;
            }
            return $url ?: '';
        }
        return $val ?: '';
    }
} 