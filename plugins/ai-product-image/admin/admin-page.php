<?php
/**
 * Страница управления AI Product Image (админка)
 *
 * @package AI_Product_Image
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Выводит страницу управления задачами и настройками
 */
function ai_product_image_admin_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Недостаточно прав для доступа к этой странице.');
    }

    $task_manager = new AI_Product_Image_Task_Manager();
    // Автоматически обработать результаты при открытии очереди
    if (!isset($_GET['tab']) || $_GET['tab'] === 'tasks') {
        $updated = $task_manager->process_results();
        if ($updated > 0) {
            echo '<div class="notice notice-success"><p>Обновлено товаров после AI-обработки: ' . intval($updated) . '</p></div>';
        }
    }
    $tasks = $task_manager->get_tasks();

    if ( isset($_POST['ai_image_create_task']) && check_admin_referer('ai_image_create_task_action', 'ai_image_create_task_nonce') ) {
        $task_data = [
            'task_id' => 'manual_' . date('Ymd_His'),
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'product_data' => [
                'brand' => sanitize_text_field($_POST['brand'] ?? ''),
                'model' => sanitize_text_field($_POST['model'] ?? ''),
            ],
        ];
        $ok = $task_manager->create_task($task_data);
        if ($ok) {
            echo '<div class="notice notice-success"><p>Задача создана!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Ошибка создания задачи (проверьте данные).</p></div>';
        }
        // Обновить список задач после создания
        $tasks = $task_manager->get_tasks();
    }

    // Обработка сброса статуса
    if (isset($_POST['reset_status_product_id']) && check_admin_referer('ai_image_reset_status_action', 'ai_image_reset_status_nonce')) {
        $pid = intval($_POST['reset_status_product_id']);
        if ($pid) {
            AI_Product_Image_Product_Helper::reset_status($pid);
            echo '<div class="notice notice-success"><p>Статус товара #' . $pid . ' сброшен.</p></div>';
        }
    }

    // Обработка повторной обработки
    if (isset($_POST['repeat_process_product_id']) && check_admin_referer('ai_image_repeat_process_action', 'ai_image_repeat_process_nonce')) {
        $pid = intval($_POST['repeat_process_product_id']);
        if ($pid) {
            AI_Product_Image_Product_Helper::set_status($pid, 'queued');
            $tm = new AI_Product_Image_Task_Manager();
            $ok = $tm->create_task_for_product($pid);
            if ($ok) {
                echo '<div class="notice notice-success"><p>Задача на повторную обработку товара #' . $pid . ' создана!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Ошибка создания задачи для товара #' . $pid . '.</p></div>';
            }
        }
    }

    $tab = isset($_GET['tab']) ? $_GET['tab'] : 'tasks';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=ai-product-image&tab=tasks" class="nav-tab' . ($tab=='tasks'?' nav-tab-active':'') . '">Очередь</a>';
    echo '<a href="?page=ai-product-image&tab=single" class="nav-tab' . ($tab=='single'?' nav-tab-active':'') . '">Одиночная обработка</a>';
    echo '<a href="?page=ai-product-image&tab=mass" class="nav-tab' . ($tab=='mass'?' nav-tab-active':'') . '">Массовая обработка</a>';
    echo '<a href="?page=ai-product-image&tab=settings" class="nav-tab' . ($tab=='settings'?' nav-tab-active':'') . '">Настройки</a>';
    echo '<a href="?page=ai-product-image&tab=dashboard" class="nav-tab' . ($tab=='dashboard'?' nav-tab-active':'') . '">Мониторинг</a>';
    echo '</h2>';

    if ($tab === 'single') {
        $result_msg = '';
        if (isset($_POST['single_process'])) {
            $product_id = intval($_POST['single_product_id']);
            $force = !empty($_POST['single_force_reprocess']);
            if (get_transient('ai_image_processing_lock')) {
                $result_msg = '<div class="notice notice-error"><p>В данный момент уже идёт обработка. Повторите позже.</p></div>';
            } elseif (!$product_id || get_post_type($product_id) !== 'product') {
                $result_msg = '<div class="notice notice-error"><p>Товар не найден.</p></div>';
            } elseif (!AI_Product_Image_Product_Helper::product_in_category_tree($product_id, 14834)) {
                $result_msg = '<div class="notice notice-error"><p>Товар не относится к нужной категории.</p></div>';
            } else {
                $already_processed = get_post_meta($product_id, '_ai_image_processed', true);
                if ($already_processed && !$force) {
                    $result_msg = '<div class="notice notice-warning"><p>Товар уже обработан. Для повторной обработки отметьте чекбокс.</p></div>';
                } else {
                    set_transient('ai_image_processing_lock', 1, 60*10);
                    $tm = new AI_Product_Image_Task_Manager();
                    $ok = $tm->create_task_for_product($product_id);
                    if ($ok) {
                        // TODO: после завершения обработки записать _ai_image_processed
                        $result_msg = '<div class="notice notice-success"><p>Задача на обработку товара отправлена!</p></div>';
                    } else {
                        $result_msg = '<div class="notice notice-error"><p>Ошибка создания задачи.</p></div>';
                    }
                    delete_transient('ai_image_processing_lock');
                }
            }
        }
        ?>
        <h2>Одиночная обработка товара</h2>
        <?php echo $result_msg; ?>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label for="single_product_id">ID товара</label></th>
                    <td><input type="number" name="single_product_id" id="single_product_id" value="" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Повторная обработка</th>
                    <td><input type="checkbox" name="single_force_reprocess" value="1"> Разрешить повторную обработку</td>
                </tr>
            </table>
            <p><input type="submit" name="single_process" class="button button-primary" value="Запустить обработку"></p>
        </form>
        <div id="single_process_result"></div>
        <?php
        return;
    }
    if ($tab === 'mass') {
        $mass_msg = '';
        $selected_statuses = isset($_POST['mass_status']) && is_array($_POST['mass_status']) ? array_map('sanitize_text_field', $_POST['mass_status']) : ['queued','error'];
        $category_id = isset($_POST['mass_category_id']) ? intval($_POST['mass_category_id']) : '';
        $mass_limit = isset($_POST['mass_limit']) ? intval($_POST['mass_limit']) : 100;
        if (isset($_POST['mass_start'])) {
            if (get_transient('ai_image_processing_lock')) {
                $mass_msg = '<div class="notice notice-error"><p>В данный момент уже идёт обработка. Повторите позже.</p></div>';
            } else {
                $product_ids = AI_Product_Image_Product_Helper::get_products_by_category_tree($category_id, $mass_limit);
                if (empty($product_ids)) {
                    $mass_msg = '<div class="notice notice-warning"><p>Нет товаров для обработки по выбранному фильтру.</p></div>';
                } else {
                    set_transient('ai_image_processing_lock', 1, 60*30);
                    $tm = new AI_Product_Image_Task_Manager();
                    $created = 0;
                    foreach ($product_ids as $pid) {
                        if ($tm->create_task_for_product($pid)) {
                            $created++;
                        }
                    }
                    $mass_msg = '<div class="notice notice-success"><p>Массовая обработка запущена для ' . $created . ' товаров.</p></div>';
                    delete_transient('ai_image_processing_lock');
                }
            }
        }
        if (isset($_POST['mass_stop'])) {
            delete_transient('ai_image_processing_lock');
            $mass_msg = '<div class="notice notice-info"><p>Массовая обработка остановлена.</p></div>';
        }
        ?>
        <h2>Массовая обработка товаров</h2>
        <?php echo $mass_msg; ?>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th>ID категории товаров</th>
                    <td><input type="number" name="mass_category_id" value="<?php echo esc_attr($category_id); ?>" min="1" class="small-text"> (товар должен входить в эту категорию или её дочерние)</td>
                </tr>
                <tr>
                    <th>Лимит товаров на обработку</th>
                    <td><input type="number" name="mass_limit" value="<?php echo esc_attr($mass_limit); ?>" min="1" class="small-text"> (максимум товаров за запуск)</td>
                </tr>
                <tr>
                    <th>Статусы для массовой обработки</th>
                    <td>
                        <label><input type="checkbox" name="mass_status[]" value="queued" <?php checked(in_array('queued', $selected_statuses)); ?>> queued</label>
                        <label><input type="checkbox" name="mass_status[]" value="error" <?php checked(in_array('error', $selected_statuses)); ?>> error</label>
                        <label><input type="checkbox" name="mass_status[]" value="task_created" <?php checked(in_array('task_created', $selected_statuses)); ?>> task_created</label>
                        <label><input type="checkbox" name="mass_status[]" value="processing" <?php checked(in_array('processing', $selected_statuses)); ?>> processing</label>
                        <label><input type="checkbox" name="mass_status[]" value="processed" <?php checked(in_array('processed', $selected_statuses)); ?>> processed</label>
                        <label><input type="checkbox" name="mass_status[]" value="applied" <?php checked(in_array('applied', $selected_statuses)); ?>> applied</label>
                    </td>
                </tr>
            </table>
            <p>
                <input type="submit" name="mass_start" class="button button-primary" value="Запустить массовую обработку">
                <input type="submit" name="mass_stop" class="button button-secondary" value="Остановить">
            </p>
        </form>
        <div id="mass_process_progress">
            <p>Прогресс: <span id="mass_progress_value">0</span> / <span id="mass_progress_total">0</span></p>
        </div>
        <?php
        return;
    }

    if ( isset($_GET['tab']) && $_GET['tab'] === 'settings' ) {
        ?>
        <h2>Настройки генерации изображений</h2>
        <form method="post" action="options.php" enctype="multipart/form-data">
            <?php settings_fields('ai_image_settings'); ?>
            <table class="form-table">
                <tr><th colspan="2"><b>Фоны</b></th></tr>
                <tr><th>Летние шины</th><td><?php echo wp_get_attachment_image( get_option('ai_image_background_summer'), 'medium' ); ?><br><?php echo wp_media_input('ai_image_background_summer'); ?></td></tr>
                <tr><th>Зимние шины</th><td><?php echo wp_get_attachment_image( get_option('ai_image_background_winter'), 'medium' ); ?><br><?php echo wp_media_input('ai_image_background_winter'); ?></td></tr>
                <tr><th>Всесезонные шины</th><td><?php echo wp_get_attachment_image( get_option('ai_image_background_allseason'), 'medium' ); ?><br><?php echo wp_media_input('ai_image_background_allseason'); ?></td></tr>
                <tr><th colspan="2"><b>Логотип и шрифты</b></th></tr>
                <tr><th>Inter-Bold.ttf</th><td><?php echo wp_media_input('ai_image_font_bold'); ?></td></tr>
                <tr><th>Inter-SemiBold.ttf</th><td><?php echo wp_media_input('ai_image_font_semibold'); ?></td></tr>
                <tr><th>Inter-Regular.ttf</th><td><?php echo wp_media_input('ai_image_font_regular'); ?></td></tr>
                <tr><th colspan="2"><b>Цвета и размеры</b></th></tr>
                <tr><th>WHITE</th><td><input type="text" name="ai_image_color_white" value="<?php echo esc_attr(get_option('ai_image_color_white', '#FFFFFF')); ?>" class="regular-text"></td></tr>
                <tr><th>BLACK</th><td><input type="text" name="ai_image_color_black" value="<?php echo esc_attr(get_option('ai_image_color_black', '#222222')); ?>" class="regular-text"></td></tr>
                <tr><th>CYAN</th><td><input type="text" name="ai_image_color_cyan" value="<?php echo esc_attr(get_option('ai_image_color_cyan', '#23B2AA')); ?>" class="regular-text"></td></tr>
                <tr><th>LIGHT_BG</th><td><input type="text" name="ai_image_color_light_bg" value="<?php echo esc_attr(get_option('ai_image_color_light_bg', '#F3F6F7')); ?>" class="regular-text"></td></tr>
                <tr><th>LOAD_IDX_BG</th><td><input type="text" name="ai_image_color_load_idx_bg" value="<?php echo esc_attr(get_option('ai_image_color_load_idx_bg', '#30BBC2')); ?>" class="regular-text"></td></tr>
                <tr><th>SPEED_IDX_BG</th><td><input type="text" name="ai_image_color_speed_idx_bg" value="<?php echo esc_attr(get_option('ai_image_color_speed_idx_bg', '#357D9F')); ?>" class="regular-text"></td></tr>
                <tr><th>Ширина (px)</th><td><input type="number" name="ai_image_width" value="<?php echo esc_attr(get_option('ai_image_width', 620)); ?>" class="small-text"></td></tr>
                <tr><th>Высота (px)</th><td><input type="number" name="ai_image_height" value="<?php echo esc_attr(get_option('ai_image_height', 826)); ?>" class="small-text"></td></tr>
                <tr><th colspan="2"><b>Крон и автоматизация</b></th></tr>
                <tr>
                    <th>Включить обработку по крону</th>
                    <td><input type="checkbox" name="ai_image_cron_enabled" value="1" <?php checked(get_option('ai_image_cron_enabled', 0)); ?>></td>
                </tr>
                <tr>
                    <th>Время/интервал (минуты)</th>
                    <td><input type="number" name="ai_image_cron_time" value="<?php echo esc_attr(get_option('ai_image_cron_time', 15)); ?>" class="small-text"></td>
                </tr>
                <tr>
                    <th>ID категории для крон-обработки</th>
                    <td><input type="number" name="ai_image_cron_category_id" value="<?php echo esc_attr(get_option('ai_image_cron_category_id', '')); ?>" min="1" class="small-text"></td>
                </tr>
                <tr>
                    <th>Лимит товаров для крон-обработки</th>
                    <td><input type="number" name="ai_image_cron_limit" value="<?php echo esc_attr(get_option('ai_image_cron_limit', 100)); ?>" min="1" class="small-text"></td>
                </tr>
                <tr><th colspan="2"><b>RunwayML API</b></th></tr>
                <tr>
                    <th>API-ключ RunwayML</th>
                    <td>
                        <input type="password" name="ai_image_runwayml_api_key" value="<?php echo esc_attr(get_option('ai_image_runwayml_api_key', '')); ?>" autocomplete="off" style="width: 350px;">
                        <p class="description">Введите ваш персональный API-ключ RunwayML. Ключ хранится в базе данных WordPress.</p>
                    </td>
                </tr>
                <tr><th colspan="2"><b>Метод удаления логотипа</b></th></tr>
                <tr>
                    <th>Метод удаления логотипа</th>
                    <td>
                        <select name="ai_image_logo_removal_method">
                            <!-- <option value="opencv" <?php /* selected(get_option('ai_image_logo_removal_method', 'opencv'), 'opencv'); */ ?>>OpenCV (быстро)</option> -->
                            <!-- <option value="lama" <?php /* selected(get_option('ai_image_logo_removal_method', 'opencv'), 'lama'); */ ?>>Lama Cleaner (AI, качественно)</option> -->
                            <option value="runwayml" <?php selected(get_option('ai_image_logo_removal_method', 'runwayml'), 'runwayml'); ?>>RunwayML (облако, AI)</option>
                        </select>
                        <p class="description">Доступен только RunwayML (облачный AI). Для активации других методов раскомментируйте соответствующие строки в коде и доработайте интеграцию.</p>
                    </td>
                </tr>
                <tr>
                    <th>Включить расширенное логирование</th>
                    <td>
                        <input type="checkbox" name="ai_image_debug_logging" value="1" <?php checked(1, get_option('ai_image_debug_logging', 0)); ?> />
                        <span class="description">Включить подробные логи координат и размеров элементов (для отладки)</span>
                    </td>
                </tr>
                <tr><th colspan="2"><b>API для интеграции с Python</b></th></tr>
                <tr>
                    <th>Секретный ключ API</th>
                    <td>
                        <input type="text" name="ai_image_api_secret" value="<?php echo esc_attr(get_option('ai_image_api_secret', '')); ?>" autocomplete="off" style="width: 350px;">
                        <p class="description">Секретный ключ для REST API интеграции с Python-скриптом. Должен совпадать с wp_api_secret в config.yaml.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
        return;
    }

    if ($tab === 'dashboard') {
        // Считаем задачи по статусам
        $status_counts = [
            'queued' => 0,
            'task_created' => 0,
            'processing' => 0,
            'processed' => 0,
            'applied' => 0,
            'error' => 0,
            'pending' => 0,
        ];
        foreach ($tasks as $task) {
            $pid = isset($task['task_id']) && preg_match('/_(\d+)$/', $task['task_id'], $m) ? intval($m[1]) : 0;
            $status = $pid ? AI_Product_Image_Product_Helper::get_status($pid) : ($task['status'] ?? 'pending');
            if (!isset($status_counts[$status])) $status_counts[$status] = 0;
            $status_counts[$status]++;
        }
        $total = array_sum($status_counts);
        echo '<h2>Мониторинг и статистика задач</h2>';
        echo '<div style="display:flex;gap:30px;align-items:flex-end;">';
        foreach ($status_counts as $status => $count) {
            $color = [
                'queued' => '#888',
                'task_created' => '#0073aa',
                'processing' => '#00a0d2',
                'processed' => '#46b450',
                'applied' => '#008000',
                'error' => '#d63638',
                'pending' => '#cccccc',
            ][$status] ?? '#ccc';
            echo '<div style="text-align:center;">';
            echo '<div style="background:' . $color . ';width:40px;height:' . (max(10, $count*2)) . 'px;border-radius:6px 6px 0 0;margin-bottom:5px;"></div>';
            echo '<div style="font-weight:bold;color:' . $color . ';">' . $count . '</div>';
            echo '<div style="font-size:12px;">' . $status . '</div>';
            echo '</div>';
        }
        echo '</div>';
        // Алерты по ошибкам и зависшим задачам
        $error_tasks = $status_counts['error'] ?? 0;
        $processing_tasks = $status_counts['processing'] ?? 0;
        if ($error_tasks > 0) {
            echo '<div style="margin-top:20px;padding:10px 20px;background:#ffd2d2;color:#a00;border-radius:5px;font-weight:bold;">Внимание: есть задачи со статусом "error" (' . $error_tasks . ')</div>';
        }
        if ($processing_tasks > 0) {
            echo '<div style="margin-top:10px;padding:10px 20px;background:#e6f7ff;color:#0073aa;border-radius:5px;font-weight:bold;">В обработке: ' . $processing_tasks . ' задач(и)</div>';
        }
        echo '<div style="margin-top:30px;color:#888;">Всего задач: ' . $total . '</div>';
        return;
    }

    // Просмотр логов по task_id
    if (isset($_GET['view_log'])) {
        $task_id = sanitize_text_field($_GET['view_log']);
        $log_file = WP_CONTENT_DIR . '/uploads/ai_image/logs/processor.log';
        echo '<div class="wrap"><h2>Лог обработки для задачи ' . esc_html($task_id) . '</h2>';
        if (file_exists($log_file)) {
            $lines = file($log_file);
            $filtered = array_filter($lines, function($line) use ($task_id) {
                return strpos($line, $task_id) !== false;
            });
            $last = array_slice($filtered, -100);
            echo '<pre style="max-height:500px;overflow:auto;background:#222;color:#eee;padding:10px;">' . esc_html(implode('', $last)) . '</pre>';
        } else {
            echo '<p>Лог-файл не найден.</p>';
        }
        echo '<a href="?page=ai-product-image&tab=tasks" class="button">Назад к очереди</a></div>';
        return;
    }

    // Пагинация очереди задач
    $page = isset($_GET['task_page']) ? max(1, intval($_GET['task_page'])) : 1;
    $per_page = 20;
    $total_tasks = count($tasks);
    $total_pages = max(1, ceil($total_tasks / $per_page));
    $tasks_to_show = array_slice($tasks, ($page-1)*$per_page, $per_page);
    ?>
    <div class="wrap">
        <h1>AI Product Image — управление задачами</h1>
        <form method="post">
            <?php wp_nonce_field('ai_image_create_task_action', 'ai_image_create_task_nonce'); ?>
            <h2>Создать новую задачу (тест)</h2>
            <table class="form-table">
                <tr>
                    <th><label for="brand">Бренд</label></th>
                    <td><input type="text" name="brand" id="brand" value="" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="model">Модель</label></th>
                    <td><input type="text" name="model" id="model" value="" class="regular-text"></td>
                </tr>
            </table>
            <p><input type="submit" name="ai_image_create_task" class="button button-primary" value="Создать задачу"></p>
        </form>
        <h2>Очередь задач</h2>
        <form method="get" id="ai-image-task-filters" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="ai-product-image">
            <input type="hidden" name="tab" value="tasks">
            <label for="filter_status">Статус: </label>
            <select name="filter_status" id="filter_status">
                <option value="">Все</option>
                <option value="queued" 
        <form method="post" id="ai-image-task-bulk-actions" style="margin-bottom: 10px;">
            <input type="hidden" name="ai_image_bulk_action_nonce" value="
        <table class="widefat">
            <thead>
                <tr>
                    <th>Task ID</th>
                    <th>ID товара</th>
                    <th>Статус</th>
                    <th>Ошибка</th>
                    <th>Результат</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $tasks_to_show ) ) : ?>
                <tr><td colspan="6">Задач нет</td></tr>
            <?php else :
                foreach ( $tasks_to_show as $task ) :
                    $result = $task_manager->get_result( $task['task_id'] ?? '' );
                    $pid = isset($task['task_id']) && preg_match('/_(\d+)$/', $task['task_id'], $m) ? intval($m[1]) : 0;
                    $status = $pid ? AI_Product_Image_Product_Helper::get_status($pid) : ($task['status'] ?? '');
                    $error = $pid ? AI_Product_Image_Product_Helper::get_error($pid) : '';
            ?>
                    <tr>
                        <td><?php echo esc_html( $task['task_id'] ?? '—' ); ?></td>
                        <td><?php echo $pid ? esc_html($pid) : '—'; ?></td>
                        <td>
                            <?php
                            $status_label = $status ?: ($result['status'] ?? $task['status'] ?? 'pending');
                            $status_colors = [
                                'queued' => '#888',
                                'task_created' => '#0073aa',
                                'processing' => '#00a0d2',
                                'processed' => '#46b450',
                                'applied' => '#008000',
                                'error' => '#d63638',
                                'pending' => '#cccccc',
                            ];
                            $status_icons = [
                                'queued' => '⏳',
                                'task_created' => '📝',
                                'processing' => '🔄',
                                'processed' => '✅',
                                'applied' => '🖼️',
                                'error' => '❌',
                                'pending' => '⏸️',
                            ];
                            $color = $status_colors[$status_label] ?? '#cccccc';
                            $icon = $status_icons[$status_label] ?? '⏸️';
                            $tooltip = [
                                'queued' => 'В очереди',
                                'task_created' => 'Задача создана',
                                'processing' => 'В обработке',
                                'processed' => 'Обработано',
                                'applied' => 'Изображение применено',
                                'error' => 'Ошибка',
                                'pending' => 'Ожидание',
                            ][$status_label] ?? $status_label;
                            ?>
                            <span title="<?php echo esc_attr($tooltip); ?>" style="display:inline-block;min-width:24px;color:<?php echo esc_attr($color); ?>;font-size:18px;vertical-align:middle;">
                                <?php echo $icon; ?>
                            </span>
                            <span style="color:<?php echo esc_attr($color); ?>;font-weight:bold;vertical-align:middle;">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $error ); ?></td>
                        <td>
                            <?php if ( !empty($result['output_image']) ) : ?>
                                <button type="button" class="button ai-image-preview-btn" data-img="<?php echo esc_url( wp_upload_dir()['baseurl'] . '/ai_image/' . $result['output_image'] ); ?>">Посмотреть</button>
                                <a href="<?php echo esc_url( wp_upload_dir()['baseurl'] . '/ai_image/' . $result['output_image'] ); ?>" download class="button">Скачать</a>
                            <?php else : ?>—<?php endif; ?>
                            <?php if ($task['task_id']): ?>
                                <button type="button" class="button ai-image-log-btn" data-task="<?php echo esc_attr($task['task_id']); ?>">Логи</button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($pid): ?>
                                <?php wp_nonce_field('ai_image_reset_status_action', 'ai_image_reset_status_nonce'); ?>
                                <button type="submit" name="reset_status_product_id" value="<?php echo esc_attr($pid); ?>" class="button">Сбросить статус</button>
                                <?php wp_nonce_field('ai_image_repeat_process_action', 'ai_image_repeat_process_nonce'); ?>
                                <button type="submit" name="repeat_process_product_id" value="<?php echo esc_attr($pid); ?>" class="button button-primary">Повторить обработку</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach;
            endif; ?>
            </tbody>
        </table>
        <?php if ($total_pages > 1): ?>
        <div class="tablenav-pages" style="margin: 10px 0;">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?>
                    <span style="font-weight:bold;padding:4px 8px;background:#eee;border-radius:3px;"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?page=ai-product-image&tab=tasks&task_page=<?php echo $i; ?>" style="padding:4px 8px;"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
    <!-- Модальные окна -->
    <div id="ai-image-modal-preview" style="display:none;position:fixed;z-index:9999;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,0.7);align-items:center;justify-content:center;">
        <div style="background:#fff;padding:20px;max-width:90vw;max-height:90vh;overflow:auto;position:relative;">
            <button type="button" id="ai-image-modal-close" style="position:absolute;top:10px;right:10px;font-size:20px;">×</button>
            <img id="ai-image-modal-img" src="" alt="Результат" style="max-width:80vw;max-height:80vh;display:block;margin:auto;">
        </div>
    </div>
    <div id="ai-image-modal-log" style="display:none;position:fixed;z-index:9999;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,0.7);align-items:center;justify-content:center;">
        <div style="background:#fff;padding:20px;max-width:90vw;max-height:90vh;overflow:auto;position:relative;">
            <button type="button" id="ai-image-modal-log-close" style="position:absolute;top:10px;right:10px;font-size:20px;">×</button>
            <pre id="ai-image-modal-log-content" style="max-width:80vw;max-height:80vh;overflow:auto;background:#222;color:#eee;padding:10px;"></pre>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Preview modal
        document.querySelectorAll('.ai-image-preview-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('ai-image-modal-img').src = btn.getAttribute('data-img');
                document.getElementById('ai-image-modal-preview').style.display = 'flex';
            });
        });
        document.getElementById('ai-image-modal-close').onclick = function() {
            document.getElementById('ai-image-modal-preview').style.display = 'none';
            document.getElementById('ai-image-modal-img').src = '';
        };
        // Log modal
        document.querySelectorAll('.ai-image-log-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var taskId = btn.getAttribute('data-task');
                fetch('?page=ai-product-image&tab=tasks&view_log=' + encodeURIComponent(taskId) + '&ajax=1')
                    .then(r => r.text())
                    .then(txt => {
                        document.getElementById('ai-image-modal-log-content').textContent = txt;
                        document.getElementById('ai-image-modal-log').style.display = 'flex';
                    });
            });
        });
        document.getElementById('ai-image-modal-log-close').onclick = function() {
            document.getElementById('ai-image-modal-log').style.display = 'none';
            document.getElementById('ai-image-modal-log-content').textContent = '';
        };
    });
    </script>
    <?php
} 