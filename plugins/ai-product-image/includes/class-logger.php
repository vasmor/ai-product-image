<?php
/**
 * Класс для логирования действий и ошибок AI Product Image
 *
 * @package AI_Product_Image
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AI_Product_Image_Logger
 */
class AI_Product_Image_Logger {
    /**
     * Путь к папке логов
     * @var string
     */
    private $logs_dir;
    private $log_file;
    private $max_size = 1048576; // 1 МБ

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->logs_dir = trailingslashit( $upload_dir['basedir'] ) . 'ai_image/logs/';
        if ( ! is_dir( $this->logs_dir ) ) {
            wp_mkdir_p( $this->logs_dir );
        }
        $this->log_file = $this->logs_dir . 'plugin.log';
    }

    /**
     * Записать сообщение в лог
     * @param string $message
     * @param string $level
     */
    public function log( $message, $level = 'info' ) {
        $this->rotate_logs();
        $line = sprintf( "[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message );
        file_put_contents( $this->log_file, $line, FILE_APPEND );
    }

    /**
     * Сокращённые методы
     */
    public function info( $message ) { $this->log( $message, 'info' ); }
    public function error( $message ) { $this->log( $message, 'error' ); }

    /**
     * Ротация логов по размеру
     */
    private function rotate_logs() {
        if ( file_exists( $this->log_file ) && filesize( $this->log_file ) > $this->max_size ) {
            $archive = $this->logs_dir . 'plugin_' . date('Ymd_His') . '.log';
            rename( $this->log_file, $archive );
        }
    }
} 