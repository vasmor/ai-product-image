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
        // Контроль статуса: не создавать задачу, если статус не error и не пустой
        if ( ! class_exists('AI_Product_Image_Product_Helper') ) {
            require_once dirname(__FILE__) . '/class-product-helper.php';
        }
        $status = AI_Product_Image_Product_Helper::get_status($product_id);
        $force = !empty($settings['force']);
        if (in_array($status, ['applied', 'processed']) && !$force) {
            // Уже обработан, повторная обработка не запрошена
            return false;
        }
        $product = wc_get_product($product_id);
        if (!$product) return false;
        $sku = $product->get_sku();
        if (!$sku) {
            if (class_exists('AI_Product_Image_Logger')) {
                $logger = AI_Product_Image_Plugin::get_instance()->logger;
                $logger->log('Ошибка: у товара ' . $product_id . ' отсутствует sku, задача не создана.');
            }
            return false;
        }
        // --- Нормализация SKU ---
        $norm_sku = AI_Product_Image_Product_Helper::normalize_sku($sku);
        // Контроль дубликатов задач по sku
        $existing_task_files = glob($this->tasks_dir . '*_' . $norm_sku . '.json');
        if ($existing_task_files && count($existing_task_files) > 0) {
            if (in_array($status, ['applied', 'processed']) && $force) {
                // Повторная обработка: удаляем старые файлы задач
                foreach ($existing_task_files as $f) { unlink($f); }
            } else {
                if (class_exists('AI_Product_Image_Logger')) {
                    $logger = AI_Product_Image_Plugin::get_instance()->logger;
                    $logger->log('Дубликат: задача для sku ' . $sku . ' уже существует, новая не создаётся.');
                }
                return false;
            }
        }
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
        $output_filename = 'processed/product_' . $norm_sku . '_ai.png';
        $task_id = date('Ymd_His') . '_' . $norm_sku;
        $debug_logging = get_option('ai_image_debug_logging', 0);
        // Определяем файл шаблона по сезону
        $season_lc = mb_strtolower(trim($season));
        if ($season_lc === 'зимняя') {
            $template_file = 'background_winter.jpg';
        } elseif ($season_lc === 'летняя') {
            $template_file = 'background_summer.jpg';
        } else {
            $template_file = 'background_allseason.jpg';
        }
        // Проверка наличия шаблона
        $upload_dir = wp_upload_dir();
        $template_path = trailingslashit($upload_dir['basedir']) . 'ai_image/templates/' . $template_file;
        if (!file_exists($template_path)) {
            if (class_exists('AI_Product_Image_Logger')) {
                $logger = AI_Product_Image_Plugin::get_instance()->logger;
                $logger->log('Пропуск создания задачи: не найден шаблон для сезона "' . $season_lc . '" (файл: ' . $template_file . ') для товара ' . $product_id, 'error');
            }
            return false;
        }
        $task = [
            'task_id' => $task_id,
            'product_id' => $product_id, // <--- добавлено поле product_id
            'type' => 'tyre',
            'original_image' => 'originals/' . $norm_sku . '-' . basename($image_path),
            'template' => 'templates/' . $template_file,
            'product_data' => [
                'sku' => $sku,
                'norm_sku' => $norm_sku, // <--- обязательно добавляем norm_sku
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
                'width' => get_option('ai_image_width', 620),
                'height' => get_option('ai_image_height', 826),
                'logo_removal_method' => 'runwayml',
                'runwayml_api_key' => get_option('ai_image_runwayml_api_key', ''),
                'runwayml_prompt' => get_option('ai_image_runwayml_prompt', ''),
                'debug_logging' => $debug_logging ? true : false,
            ], $settings)
        ];
        // Копируем оригинал в originals/
        if ($sku) {
            $original_name = basename($image_path);
            $original_with_sku = $norm_sku . '-' . $original_name;
            $this->backup_original_image($image_path, $original_with_sku);
        }
        // Сохраняем задачу в tasks/
        $file = $this->tasks_dir . $task_id . '.json';
        $json = json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $result = file_put_contents($file, $json) !== false;
        if ($result) {
            AI_Product_Image_Product_Helper::set_status($product_id, 'task_created');
        }
        return $result;
    }

    /**
     * Обработать результаты: для успешных задач записать _ai_image_processed
     * @return int Количество обновлённых товаров
     */
    public function process_results() {
        $count = 0;
        $upload_dir = wp_upload_dir();
        $results_dir = trailingslashit( $upload_dir['basedir'] ) . 'ai_image/results/';
        $STUCK_ATTEMPTS = 5;
        $stuck_results = [];
        if ( ! class_exists('AI_Product_Image_Product_Helper') ) {
            require_once dirname(__FILE__) . '/class-product-helper.php';
        }
        foreach (glob($results_dir . '*.json') as $file) {
            $json = file_get_contents($file);
            $data = json_decode($json, true);
            if (!$data || empty($data['task_id'])) continue;
            $task_id = $data['task_id'];
            $task_file = $this->tasks_dir . $task_id . '.json';
            $product_id = !empty($data['product_id']) ? intval($data['product_id']) : 0;
            // --- Счётчик попыток ---
            $attempts = isset($data['attempts']) ? intval($data['attempts']) : 0;
            // Если уже зависший — пропускаем дальнейшие попытки
            if (($data['status'] ?? '') === 'stuck') {
                continue;
            }
            $attempts++;
            $data['attempts'] = $attempts;
            // Если превышен лимит попыток — считаем зависшим
            if ($attempts >= $STUCK_ATTEMPTS) {
                if ($product_id && get_post_type($product_id) === 'product') {
                    AI_Product_Image_Product_Helper::set_status($product_id, 'stuck');
                    AI_Product_Image_Product_Helper::set_error($product_id, 'Результат завис: превышено число попыток применения (' . $attempts . ')');
                }
                $data['status'] = 'stuck';
                $data['error'] = 'Результат завис: превышено число попыток применения (' . $attempts . ')';
                file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                if (class_exists('AI_Product_Image_Logger')) {
                    $logger = AI_Product_Image_Plugin::get_instance()->logger;
                    $sku = !empty($data['product_data']['sku']) ? $data['product_data']['sku'] : '';
                    $logger->log('Результат задачи завис: product_id=' . $product_id . ', sku=' . $sku . ', task_id=' . $task_id . ', attempts=' . $attempts, 'error');
                }
                $stuck_results[] = [
                    'task_id' => $task_id,
                    'product_id' => $product_id,
                    'sku' => $data['product_data']['sku'] ?? '',
                    'error' => $data['error'] ?? '',
                    'attempts' => $attempts,
                ];
                continue;
            } else {
                // Сохраняем увеличенный attempts
                file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            if ($product_id && get_post_type($product_id) === 'product') {
                if ($data['status'] === 'success') {
                    update_post_meta($product_id, '_ai_image_processed', $data['task_id']);
                    $applied = false;
                    if (!empty($data['output_image'])) {
                        $processed_path = trailingslashit($upload_dir['basedir']) . 'ai_image/' . $data['output_image'];
                        $applied = AI_Product_Image_Product_Helper::apply_processed_image_to_product($product_id, $processed_path);
                    }
                    if ($applied) {
                        AI_Product_Image_Product_Helper::set_status($product_id, 'applied');
                        AI_Product_Image_Product_Helper::set_error($product_id, '');
                        $count++;
                        if (!empty($data['gallery_image'])) {
                            $gallery_path = trailingslashit($upload_dir['basedir']) . 'ai_image/' . $data['gallery_image'];
                            if (file_exists($gallery_path)) {
                                $upload = wp_upload_bits(basename($gallery_path), null, file_get_contents($gallery_path));
                                if (!$upload['error']) {
                                    $wp_filetype = wp_check_filetype($upload['file'], null);
                                    $attachment = [
                                        'post_mime_type' => $wp_filetype['type'],
                                        'post_title'     => sanitize_file_name(basename($upload['file'])),
                                        'post_content'   => '',
                                        'post_status'    => 'inherit'
                                    ];
                                    $attach_id = wp_insert_attachment($attachment, $upload['file'], 0);
                                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                                    $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
                                    wp_update_attachment_metadata($attach_id, $attach_data);
                                    $gallery_ids = get_post_meta($product_id, '_product_image_gallery', true);
                                    $gallery_ids_arr = $gallery_ids ? explode(',', $gallery_ids) : [];
                                    if (!in_array($attach_id, $gallery_ids_arr)) {
                                        $gallery_ids_arr[] = $attach_id;
                                        update_post_meta($product_id, '_product_image_gallery', implode(',', array_filter($gallery_ids_arr)));
                                    }
                                }
                            }
                        }
                        if (file_exists($task_file)) {
                            unlink($task_file);
                        }
                        // --- Удаляем файл результата только при полном успехе ---
                        if (file_exists($file)) {
                            unlink($file);
                        }
                    } else {
                        $sku = !empty($data['product_data']['sku']) ? $data['product_data']['sku'] : '';
                        AI_Product_Image_Product_Helper::set_status($product_id, 'error');
                        if (class_exists('AI_Product_Image_Logger')) {
                            $logger = AI_Product_Image_Plugin::get_instance()->logger;
                            $logger->log('Ошибка применения processed-изображения: product_id=' . $product_id . ', sku=' . $sku . ', task_id=' . $task_id, 'error');
                        }
                        // Файл результата НЕ удаляем, чтобы cron мог повторить попытку
                    }
                    // AI_Product_Image_Product_Helper::set_error($product_id, ''); // уже сброшено при успехе
                } else {
                    AI_Product_Image_Product_Helper::set_status($product_id, 'error');
                    $err = !empty($data['error']) ? $data['error'] : 'Ошибка обработки';
                    AI_Product_Image_Product_Helper::set_error($product_id, $err);
                    $sku = !empty($data['product_data']['sku']) ? $data['product_data']['sku'] : '';
                    if (class_exists('AI_Product_Image_Logger')) {
                        $logger = AI_Product_Image_Plugin::get_instance()->logger;
                        $logger->log('Ошибка применения результата: product_id=' . $product_id . ', sku=' . $sku . ', task_id=' . $task_id . ', error=' . $err, 'error');
                    }
                    if (file_exists($task_file)) {
                        unlink($task_file);
                    }
                    // Файл результата НЕ удаляем, чтобы cron мог повторить попытку
                }
            }
        }
        // Можно вернуть массив зависших задач для вывода в dashboard
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

    /**
     * Установить статус 'processing' для товара по task_id
     * @param string $task_id
     * @return bool
     */
    public function set_processing_status_by_task_id($task_id) {
        if ( ! class_exists('AI_Product_Image_Product_Helper') ) {
            require_once dirname(__FILE__) . '/class-product-helper.php';
        }
        // Теперь product_id должен быть получен из задачи, а не из task_id
        $tasks = $this->get_tasks();
        foreach ($tasks as $task) {
            if (!empty($task['task_id']) && $task['task_id'] === $task_id && !empty($task['product_id'])) {
                $product_id = intval($task['product_id']);
                if ($product_id && get_post_type($product_id) === 'product') {
                    AI_Product_Image_Product_Helper::set_status($product_id, 'processing');
                    return true;
                }
            }
        }
        return false;
    }
} 