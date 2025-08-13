<?php

namespace OBA\APIsIntegration\Database;

class CartTable {
    private static $instance = null;
    private $table_name;
    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'oba_cart_items';
    }

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if table exists
     */
    public static function table_exists() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oba_cart_items';
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== null;
    }

    /**
     * Initialize table creation
     */
    public static function init() {
        $instance = new self();
        $instance->create_table();
    }

    public function create_table() {
        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $this->table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            product_id bigint(20) NOT NULL,
            variation_id bigint(20) DEFAULT NULL,
            quantity int(11) NOT NULL DEFAULT 1,
            options text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_cart_item (user_id, product_id, variation_id),
            KEY user_id (user_id),
            KEY product_id (product_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function drop_table() {
        $sql = "DROP TABLE IF EXISTS $this->table_name";
        $this->wpdb->query($sql);
    }

    public function get_cart_items($user_id) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM $this->table_name WHERE user_id = %d ORDER BY created_at DESC",
                $user_id
            )
        );
    }

    public function get_cart_item($user_id, $product_id, $variation_id = null) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM $this->table_name WHERE user_id = %d AND product_id = %d AND variation_id = %d",
                $user_id,
                $product_id,
                $variation_id
            )
        );
    }

    public function get_cart_item_by_id($cart_item_id) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM $this->table_name WHERE id = %d",
                $cart_item_id
            )
        );
    }

    public function add_item($user_id, $product_id, $quantity = 1, $variation_id = null, $options = []) {
        $options = maybe_serialize($options);
        
        return $this->wpdb->insert(
            $this->table_name,
            [
                'user_id' => $user_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $quantity,
                'options' => $options
            ],
            ['%d', '%d', '%d', '%d', '%s']
        );
    }

    public function update_item($user_id, $product_id, $quantity, $variation_id = null, $options = []) {
        $options = maybe_serialize($options);
        
        return $this->wpdb->update(
            $this->table_name,
            [
                'quantity' => $quantity,
                'options' => $options
            ],
            [
                'user_id' => $user_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id
            ],
            ['%d', '%s'],
            ['%d', '%d', '%d']
        );
    }

    public function update_item_by_id($cart_item_id, $quantity, $options = []) {
        $options = maybe_serialize($options);
        
        return $this->wpdb->update(
            $this->table_name,
            [
                'quantity' => $quantity,
                'options' => $options
            ],
            ['id' => $cart_item_id],
            ['%d', '%s'],
            ['%d']
        );
    }

    public function delete_item($user_id, $product_id, $variation_id = null) {
        return $this->wpdb->delete(
            $this->table_name,
            [
                'user_id' => $user_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id
            ],
            ['%d', '%d', '%d']
        );
    }

    public function delete_item_by_id($cart_item_id) {
        return $this->wpdb->delete(
            $this->table_name,
            ['id' => $cart_item_id],
            ['%d']
        );
    }

    public function clear_cart($user_id) {
        return $this->wpdb->delete(
            $this->table_name,
            ['user_id' => $user_id],
            ['%d']
        );
    }

    public function get_cart_count($user_id) {
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM $this->table_name WHERE user_id = %d",
                $user_id
            )
        );
    }

    public function get_cart_total($user_id) {
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT SUM(quantity) FROM $this->table_name WHERE user_id = %d",
                $user_id
            )
        );
    }
}
