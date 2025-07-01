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

    $tab = isset($_GET['tab']) ? $_GET['tab'] : 'tasks';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=ai-product-image&tab=tasks" class="nav-tab' . ($tab=='tasks'?' nav-tab-active':'') . '">Очередь</a>';
    echo '<a href="?page=ai-product-image&tab=single" class="nav-tab' . ($tab=='single'?' nav-tab-active':'') . '">Одиночная обработка</a>';
    echo '<a href="?page=ai-product-image&tab=mass" class="nav-tab' . ($tab=='mass'?' nav-tab-active':'') . '">Массовая обработка</a>';
    echo '<a href="?page=ai-product-image&tab=settings" class="nav-tab' . ($tab=='settings'?' nav-tab-active':'') . '">Настройки</a>';
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
        if (isset($_POST['mass_start'])) {
            if (get_transient('ai_image_processing_lock')) {
                $mass_msg = '<div class="notice notice-error"><p>В данный момент уже идёт обработка. Повторите позже.</p></div>';
            } else {
                $limit = max(1, intval($_POST['mass_limit']));
                $product_ids = AI_Product_Image_Product_Helper::get_products_by_category_tree(14834, $limit);
                $to_process = [];
                foreach ($product_ids as $pid) {
                    $already_processed = get_post_meta($pid, '_ai_image_processed', true);
                    if (!$already_processed) {
                        $to_process[] = $pid;
                    }
                }
                if (empty($to_process)) {
                    $mass_msg = '<div class="notice notice-warning"><p>Нет товаров для обработки по выбранному фильтру.</p></div>';
                } else {
                    set_transient('ai_image_processing_lock', 1, 60*30);
                    $tm = new AI_Product_Image_Task_Manager();
                    $created = 0;
                    foreach ($to_process as $pid) {
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
                    <th>Лимит товаров на обработку</th>
                    <td><input type="number" name="mass_limit" value="100" min="1" class="small-text"> (максимум товаров за запуск)</td>
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
                <tr><th colspan="2"><b>Иконки сезонности</b></th></tr>
                <tr><th>Лето</th><td><?php echo wp_get_attachment_image( get_option('ai_image_icon_summer'), 'thumbnail' ); ?><br><?php echo wp_media_input('ai_image_icon_summer'); ?></td></tr>
                <tr><th>Зима</th><td><?php echo wp_get_attachment_image( get_option('ai_image_icon_winter'), 'thumbnail' ); ?><br><?php echo wp_media_input('ai_image_icon_winter'); ?></td></tr>
                <tr><th>Любой сезон</th><td><?php echo wp_get_attachment_image( get_option('ai_image_icon_any'), 'thumbnail' ); ?><br><?php echo wp_media_input('ai_image_icon_any'); ?></td></tr>
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
                <tr><th colspan="2"><b>Метод удаления логотипа</b></th></tr>
                <tr>
                    <th>Метод удаления логотипа</th>
                    <td>
                        <select name="ai_image_logo_removal_method">
                            <option value="opencv" <?php selected(get_option('ai_image_logo_removal_method', 'opencv'), 'opencv'); ?>>OpenCV (быстро)</option>
                            <option value="lama" <?php selected(get_option('ai_image_logo_removal_method', 'opencv'), 'lama'); ?>>Lama Cleaner (AI, качественно)</option>
                        </select>
                        <p class="description">OpenCV — быстрый классический inpaint, Lama — AI-инпейтинг (лучше восстанавливает фон, но требует установки lama-cleaner).</p>
                    </td>
                </tr>
                <tr>
                    <th>Включить расширенное логирование</th>
                    <td>
                        <input type="checkbox" name="ai_image_debug_logging" value="1" <?php checked(1, get_option('ai_image_debug_logging', 0)); ?> />
                        <span class="description">Включить подробные логи координат и размеров элементов (для отладки)</span>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
        return;
    }
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
        <table class="widefat">
            <thead>
                <tr>
                    <th>Task ID</th>
                    <th>Статус</th>
                    <th>Сообщение</th>
                    <th>Результат</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $tasks ) ) : ?>
                <tr><td colspan="4">Задач нет</td></tr>
            <?php else :
                foreach ( $tasks as $task ) :
                    $result = $task_manager->get_result( $task['task_id'] ?? '' ); ?>
                    <tr>
                        <td><?php echo esc_html( $task['task_id'] ?? '—' ); ?></td>
                        <td><?php echo esc_html( $result['status'] ?? $task['status'] ?? 'pending' ); ?></td>
                        <td><?php echo esc_html( $result['message'] ?? '-' ); ?></td>
                        <td>
                            <?php if ( !empty($result['output_image']) ) : ?>
                                <a href="<?php echo esc_url( wp_upload_dir()['baseurl'] . '/ai_image/' . $result['output_image'] ); ?>" target="_blank">Посмотреть</a>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach;
            endif; ?>
            </tbody>
        </table>
    </div>
    <?php
} 