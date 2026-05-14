<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_DB {

    const TABLE_NAME = 'clielo_messages';

    /**
     * Retourne le nom complet de la table avec le préfixe WP.
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Crée la table des messages à l'activation du plugin.
     */
    public static function create_table(): void {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY post_client (post_id, client_id),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'clielo_db_version', CLIELO_VERSION );
    }

    /**
     * Insère un nouveau message.
     */
    public static function insert_message( int $post_id, int $user_id, string $message, int $client_id = 0 ): int|false {
        global $wpdb;

        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            self::table_name(),
            [
                'post_id'   => $post_id,
                'client_id' => $client_id,
                'user_id'   => $user_id,
                'message'   => $message,
            ],
            [ '%d', '%d', '%d', '%s' ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Récupère les messages d'un post, filtrés par client_id si fourni.
     */
    public static function get_messages( int $post_id, int $client_id = 0, int $limit = 50, int $offset = 0 ): array {
        global $wpdb;

        $table = self::table_name();

        if ( $client_id > 0 ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT m.*, u.display_name, u.user_email
                     FROM {$table} m
                     LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
                     WHERE m.post_id = %d AND m.client_id = %d
                     ORDER BY m.created_at ASC, m.id ASC
                     LIMIT %d OFFSET %d",
                    $post_id,
                    $client_id,
                    $limit,
                    $offset
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT m.*, u.display_name, u.user_email
                 FROM {$table} m
                 LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
                 WHERE m.post_id = %d
                 ORDER BY m.created_at ASC, m.id ASC
                 LIMIT %d OFFSET %d",
                $post_id,
                $limit,
                $offset
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Récupère les messages plus récents qu'un ID donné (pour le polling).
     */
    public static function get_new_messages( int $post_id, int $last_id, int $client_id = 0 ): array {
        global $wpdb;

        $table = self::table_name();

        if ( $client_id > 0 ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    "SELECT m.*, u.display_name, u.user_email
                     FROM {$table} m
                     LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
                     WHERE m.post_id = %d AND m.client_id = %d AND m.id > %d
                     ORDER BY m.created_at ASC, m.id ASC",
                    $post_id,
                    $client_id,
                    $last_id
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT m.*, u.display_name, u.user_email
                 FROM {$table} m
                 LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
                 WHERE m.post_id = %d AND m.id > %d
                 ORDER BY m.created_at ASC, m.id ASC",
                $post_id,
                $last_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Migre les messages existants pour renseigner client_id.
     */
    public static function migrate_client_ids(): void {
        global $wpdb;

        if ( get_option( 'clielo_client_id_migrated' ) ) {
            return;
        }

        $table = self::table_name();

        // Étape 1 : Messages d'utilisateurs non-admin → client_id = user_id
        $admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
        if ( ! empty( $admin_ids ) ) {
            $admin_ids_str = implode( ',', array_map( 'absint', $admin_ids ) );
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "UPDATE {$table} SET client_id = user_id
                 WHERE client_id = 0 AND user_id > 0 AND user_id NOT IN ({$admin_ids_str})"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            // Pas d'admin trouvé : tous les user_id > 0 sont des clients
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "UPDATE {$table} SET client_id = user_id WHERE client_id = 0 AND user_id > 0"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // Étape 2 : Messages admin/système → chercher le client via les commandes
        $order_table = Clielo_Orders::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $orphan_posts = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT DISTINCT post_id FROM {$table} WHERE client_id = 0"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        foreach ( $orphan_posts as $pid ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $client = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT client_id FROM {$order_table} WHERE post_id = %d GROUP BY client_id LIMIT 1",
                $pid
            ) );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            if ( $client ) {
                $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $table,
                    [ 'client_id' => (int) $client ],
                    [ 'post_id' => (int) $pid, 'client_id' => 0 ]
                );
            }
        }

        update_option( 'clielo_client_id_migrated', '1' );
    }
}
