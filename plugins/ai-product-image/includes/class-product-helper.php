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
} 