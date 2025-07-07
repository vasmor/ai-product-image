<?php
/**
 * Вспомогательные функции для работы с товарами и категориями
 *
 * @package AI_Product_Image
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Product_Image_Product_Helper {
    /**
     * Получить ID всех товаров, относящихся к категории $cat_id или её потомкам
     * @param int $cat_id
     * @param int $limit
     * @return array
     */
    public static function get_products_by_category_tree( $cat_id, $limit = 0 ) {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => self::get_category_and_descendants( $cat_id ),
                    'include_children' => true,
                ],
            ],
        ];
        $query = new WP_Query( $args );
        return $query->posts;
    }

    /**
     * Получить массив ID: категория + все её потомки
     * @param int $cat_id
     * @return array
     */
    public static function get_category_and_descendants( $cat_id ) {
        $ids = [ $cat_id ];
        $children = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'parent'     => $cat_id,
            'fields'     => 'ids',
        ] );
        foreach ( $children as $child_id ) {
            $ids = array_merge( $ids, self::get_category_and_descendants( $child_id ) );
        }
        return $ids;
    }

    /**
     * Проверить, относится ли товар к категории $cat_id или её потомкам
     * @param int $product_id
     * @param int $cat_id
     * @return bool
     */
    public static function product_in_category_tree( $product_id, $cat_id ) {
        $terms = get_the_terms( $product_id, 'product_cat' );
        if ( ! $terms || is_wp_error( $terms ) ) return false;
        $target_ids = self::get_category_and_descendants( $cat_id );
        foreach ( $terms as $term ) {
            if ( in_array( $term->term_id, $target_ids ) ) return true;
            $ancestors = get_ancestors( $term->term_id, 'product_cat' );
            if ( array_intersect( $ancestors, $target_ids ) ) return true;
        }
        return false;
    }

    /**
     * Получить статус AI-обработки товара
     * @param int $product_id
     * @return string
     */
    public static function get_status($product_id) {
        return get_post_meta($product_id, '_ai_image_status', true);
    }

    /**
     * Установить статус AI-обработки товара
     * @param int $product_id
     * @param string $status
     */
    public static function set_status($product_id, $status) {
        update_post_meta($product_id, '_ai_image_status', $status);
    }

    /**
     * Получить текст ошибки AI-обработки товара
     * @param int $product_id
     * @return string
     */
    public static function get_error($product_id) {
        return get_post_meta($product_id, '_ai_image_error', true);
    }

    /**
     * Установить текст ошибки AI-обработки товара
     * @param int $product_id
     * @param string $error
     */
    public static function set_error($product_id, $error) {
        update_post_meta($product_id, '_ai_image_error', $error);
    }

    /**
     * Сбросить статус и ошибку AI-обработки товара
     * @param int $product_id
     */
    public static function reset_status($product_id) {
        delete_post_meta($product_id, '_ai_image_status');
        delete_post_meta($product_id, '_ai_image_error');
    }

    /**
     * Установить обработанное изображение как основное для товара и сменить статус на 'applied'
     * @param int $product_id
     * @param string $image_path Абсолютный путь к обработанному изображению
     * @return bool
     */
    public static function apply_processed_image_to_product($product_id, $image_path) {
        if (!file_exists($image_path)) {
            self::set_error($product_id, 'Файл обработанного изображения не найден: ' . $image_path);
            if (class_exists('AI_Product_Image_Logger')) {
                AI_Product_Image_Logger::log('Ошибка: не найден processed-файл для товара ' . $product_id . ': ' . $image_path);
            }
            return false;
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            self::set_error($product_id, 'Товар не найден: ' . $product_id);
            if (class_exists('AI_Product_Image_Logger')) {
                AI_Product_Image_Logger::log('Ошибка: товар не найден ' . $product_id);
            }
            return false;
        }
        // Сохраняем ID предыдущего изображения для возможного отката
        $prev_image_id = $product->get_image_id();
        // Загрузить изображение в медиабиблиотеку
        $upload = wp_upload_bits(basename($image_path), null, file_get_contents($image_path));
        if ($upload['error']) {
            self::set_error($product_id, 'Ошибка загрузки файла: ' . $upload['error']);
            if (class_exists('AI_Product_Image_Logger')) {
                AI_Product_Image_Logger::log('Ошибка загрузки файла для товара ' . $product_id . ': ' . $upload['error']);
            }
            return false;
        }
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
        // Установить как основное изображение товара
        $set = set_post_thumbnail($product_id, $attach_id);
        if (!$set) {
            // Откат к предыдущему изображению
            if ($prev_image_id) set_post_thumbnail($product_id, $prev_image_id);
            self::set_error($product_id, 'Не удалось установить обработанное изображение как основное. Откат выполнен.');
            if (class_exists('AI_Product_Image_Logger')) {
                AI_Product_Image_Logger::log('Ошибка: не удалось установить processed-изображение для товара ' . $product_id . '. Откат выполнен.');
            }
            return false;
        }
        self::set_status($product_id, 'applied');
        self::set_error($product_id, '');
        if (class_exists('AI_Product_Image_Logger')) {
            AI_Product_Image_Logger::log('Успешно применено processed-изображение для товара ' . $product_id . ': ' . $image_path);
        }
        return true;
    }
} 