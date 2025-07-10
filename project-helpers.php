<?php
// Add a filter on product for set product image
add_action( 'restrict_manage_posts', 'filter_products_by_not_update_for_import' );
function filter_products_by_not_update_for_import() {
    global $typenow;
    $selected = isset( $_GET['klb_do_not_delete'] ) ? $_GET['klb_do_not_delete'] : '';
	$selected_stock = isset( $_GET['stock_qty'] ) ? $_GET['stock_qty'] : '';
	$selected_discount = isset($_GET['discount']) ? $_GET['discount'] : '';
	$selected_descr = isset($_GET['descr']) ? $_GET['descr'] : '';
    $selected_ai_status = isset($_GET['ai_image_status']) ? $_GET['ai_image_status'] : '';
    if ( 'product' === $typenow ) {
        ?>
        <select name="klb_do_not_delete" id="klb_do_not_delete">
            <option value="">Автоимпорт</option>
            <option value="update" <?php selected( 'update', $selected ); ?>>Да</option>
            <option value="notupdate" <?php selected( 'notupdate', $selected ); ?>>Нет</option>
        </select>
		<select name="stock_qty" id="stock_qty">
            <option value="">Остаток</option>
            <option value="zero" <?php selected( 'zero', $selected_stock ); ?>>0 шт.</option>
            <option value="notzero" <?php selected( 'notzero', $selected_stock ); ?>>Не 0</option>
        </select>
		<select name="discount" id="discount">
			<option value="">Все</option>
			<option value="with_discount" <?php selected( 'with_discount', $selected_discount ); ?>>Со скидкой</option>
			<option value="without_discount" <?php selected( 'without_discount', $selected_discount ); ?>>Без скидки</option>
        </select>
		<select name="descr" id="descr">
            <option value="">Описания</option>
            <option value="yes" <?php selected( 'yes', $selected_descr ); ?>>С описанием</option>
            <option value="no" <?php selected( 'no', $selected_descr ); ?>>Без описания</option>
        </select>
        <select name="ai_image_status" id="ai_image_status">
            <option value="">Статус AI-обработки</option>
            <option value="queued" <?php selected( 'queued', $selected_ai_status ); ?>>queued</option>
            <option value="task_created" <?php selected( 'task_created', $selected_ai_status ); ?>>task_created</option>
            <option value="processing" <?php selected( 'processing', $selected_ai_status ); ?>>processing</option>
            <option value="processed" <?php selected( 'processed', $selected_ai_status ); ?>>processed</option>
            <option value="applied" <?php selected( 'applied', $selected_ai_status ); ?>>applied</option>
            <option value="error" <?php selected( 'error', $selected_ai_status ); ?>>error</option>
            <option value="pending" <?php selected( 'pending', $selected_ai_status ); ?>>pending</option>
            <option value="stuck" <?php selected( 'stuck', $selected_ai_status ); ?>>stuck</option>
        </select>
        <?php
    }
}

add_filter( 'parse_query', 'filter_products_query_by_klb_do_not_delete' );
function filter_products_query_by_klb_do_not_delete( $query ) {
    global $pagenow, $typenow;

    if ( 'edit.php' === $pagenow && 'product' === $typenow && (
        ( isset( $_GET['descr'] ) && $_GET['descr'] != '' ) ||
        ( isset( $_GET['discount'] ) && $_GET['discount'] != '' ) ||
        ( isset( $_GET['klb_do_not_delete'] ) && $_GET['klb_do_not_delete'] != '' ) ||
        ( isset( $_GET['stock_qty'] ) && $_GET['stock_qty'] != '' ) ||
        ( isset( $_GET['ai_image_status'] ) && $_GET['ai_image_status'] != '' )
    ) ) {
        $meta_query = [];
        // Фильтр по статусу AI-обработки
        if (isset($_GET['ai_image_status']) && $_GET['ai_image_status'] !== '') {
            $meta_query[] = [
                'key' => '_ai_image_status',
                'value' => sanitize_text_field($_GET['ai_image_status'])
            ];
        }
        // Фильтр по описанию
        $descr = $_GET['descr'];
        if ( 'yes' === $descr ) {
            $meta_query[] = [
                'key'    => '_product_ai_generated',
                'compare'=> 'EXISTS'
            ];
        } elseif ( 'no' === $descr ) {
            $meta_query[] = [
                'key'    => '_product_ai_generated',
                'compare'=> 'NOT EXISTS'
            ];
        }
        // Фильтр по скидке
        if (isset($_GET['discount'])) {
            switch ($_GET['discount']) {
                case 'with_discount':
                    $meta_query[] = [
                        'key'     => '_sale_price',
                        'value'   => 0,
                        'compare' => '>',
                        'type'    => 'NUMERIC'
                    ];
                    break;
                case 'without_discount':
                    $meta_query[] = [
                        'relation' => 'OR',
                        [
                            'key' => '_sale_price',
                            'compare' => 'NOT EXISTS'
                        ],
                        [
                            'key'     => '_sale_price',
                            'value'   => '',
                            'compare' => '='
                        ],
                        [
                            'key'     => '_sale_price',
                            'value'   => 0,
                            'compare' => '=',
                            'type'    => 'NUMERIC'
                        ]
                    ];
                    break;
            }
        }
        // Фильтр по автоимпорту
        $upd = $_GET['klb_do_not_delete'];
        if ( 'update' === $upd ) {
            $meta_query[] = [
                'key' => 'klb_do_not_delete',
                'value' => '1',
                'compare' => '!='
            ];
        } elseif ( 'notupdate' === $upd ) {
            $meta_query[] = [
                'key' => 'klb_do_not_delete',
                'value' => '1',
            ];
        }
        // Фильтр по остатку
        $stock = $_GET['stock_qty'];
        if ( 'zero' === $stock ) {
            $meta_query[] = [
                'key' => '_stock',
                'value' => '0',
            ];
        } elseif ( 'notzero' === $stock ) {
            $meta_query[] = [
                'key' => '_stock',
                'value' => '0',
                'compare' => '!='
            ];
        }
        if (!empty($meta_query)) {
            if (count($meta_query) > 1) {
                $meta_query = array_merge(['relation' => 'AND'], $meta_query);
            }
            $query->set('meta_query', $meta_query);
        }
    }
}

add_action('pmxi_gallery_image', function($post_id, $att_id, $filepath, $is_keep_existing_images = '') {
    $sku = get_post_meta($post_id, '_sku', true);
    $existing_ids = [];

    // Получаем ID всех изображений, включая новое
    $gallery = get_post_meta($post_id, '_product_image_gallery', true);
    if ($gallery) {
        $existing_ids = explode(',', $gallery);
    }
    $main = get_post_thumbnail_id($post_id);
    if ($main) {
        array_unshift($existing_ids, $main);
    }

    // Если не было изображений до импорта — оставляем новое
    if (count($existing_ids) <= 1) {
        return;
    }

    // Проверяем, есть ли в старых файлах SKU
    $has_sku = false;
    foreach ($existing_ids as $id) {
        if ($id == $att_id) continue; // skip только что добавленный
        $name = basename(get_attached_file($id));
        if ($sku && stripos($name, $sku) !== false) {
            $has_sku = true;
            break;
        }
    }

    // Если найдено старое с SKU — удаляем вновь добавленное
    if ($has_sku) {
        wp_delete_attachment($att_id, true);
    }

}, 10, 4);

// не забыть добавить в настройки импорта запрет на обновление поля _ai_image_status