<?php
// plugins/ai-product-image/process_results_cron.php

define('WP_USE_THEMES', false);
require_once dirname(__DIR__, 3) . '/wp-load.php';

if (class_exists('AI_Product_Image_Task_Manager')) {
    $tm = new AI_Product_Image_Task_Manager();
    $updated = 0;
    try {
        $updated = $tm->process_results();
        if ($updated > 0) {
            echo "Обновлено товаров: $updated\n";
        }
    } catch (Throwable $e) {
        echo "[ERROR] " . $e->getMessage() . "\n";
    }
} else {
    echo "Класс AI_Product_Image_Task_Manager не найден\n";
} 