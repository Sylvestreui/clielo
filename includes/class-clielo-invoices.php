<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Invoices {

    const STATUS_DRAFT     = 'draft';
    const STATUS_PENDING   = 'pending';
    const STATUS_VALIDATED = 'validated';
    const STATUS_PAID      = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    public static function init(): void {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_scripts' ] );

        // AJAX clients externes (gratuit)
        add_action( 'wp_ajax_clielo_save_ext_client',   [ __CLASS__, 'ajax_save_client' ] );
        add_action( 'wp_ajax_clielo_delete_ext_client', [ __CLASS__, 'ajax_delete_client' ] );

        // AJAX factures — création/modification (gratuit pour clients externes, premium pour WP users)
        add_action( 'wp_ajax_clielo_invoice_save',      [ __CLASS__, 'ajax_save_invoice' ] );
        add_action( 'wp_ajax_clielo_invoice_update',    [ __CLASS__, 'ajax_update_invoice' ] );
        add_action( 'wp_ajax_clielo_invoice_validate',  [ __CLASS__, 'ajax_validate' ] );
        add_action( 'wp_ajax_clielo_invoice_mark_paid', [ __CLASS__, 'ajax_mark_paid' ] );
        add_action( 'wp_ajax_clielo_invoice_cancel',    [ __CLASS__, 'ajax_cancel' ] );
        add_action( 'wp_ajax_clielo_invoice_set_status', [ __CLASS__, 'ajax_set_status' ] );
        add_action( 'wp_ajax_clielo_save_invoice_settings', [ __CLASS__, 'ajax_save_settings' ] );

        // AJAX devis manuels (gratuit)
        add_action( 'wp_ajax_clielo_save_quote',   [ __CLASS__, 'ajax_save_quote' ] );
        add_action( 'wp_ajax_clielo_update_quote', [ __CLASS__, 'ajax_update_quote' ] );
        add_action( 'wp_ajax_clielo_delete_quote', [ __CLASS__, 'ajax_delete_quote' ] );

        // AJAX frontend (vue client)
        add_action( 'wp_ajax_clielo_view_invoice', [ __CLASS__, 'ajax_client_view_invoice' ] );

        // Premium uniquement : génération document devis depuis commande + auto-facture
        if ( clielo_is_premium() ) {
            add_action( 'wp_ajax_clielo_generate_quote_doc', [ __CLASS__, 'ajax_generate_quote_doc' ] );
            add_action( 'clielo_order_status_changed', [ __CLASS__, 'on_order_accepted' ], 10, 4 );
        }
    }

    /* ================================================================
     *  TABLES
     * ================================================================ */

    public static function invoices_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'clielo_invoices';
    }

    public static function clients_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'clielo_clients';
    }

    public static function create_invoices_table(): void {
        global $wpdb;

        $table   = self::invoices_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_number  VARCHAR(30)     NOT NULL,
            order_id        BIGINT UNSIGNED DEFAULT NULL,
            client_id       BIGINT UNSIGNED DEFAULT NULL,
            ext_client_id   BIGINT UNSIGNED DEFAULT NULL,
            status          VARCHAR(20)     NOT NULL DEFAULT 'draft',
            items           LONGTEXT        NOT NULL,
            subtotal        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            tax_rate        DECIMAL(5,2)    NOT NULL DEFAULT 20.00,
            tax_amount      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            total           DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            notes           TEXT            DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            validated_at    DATETIME        DEFAULT NULL,
            paid_at         DATETIME        DEFAULT NULL,
            invoice_type    VARCHAR(30)     NOT NULL DEFAULT 'single',
            schedule_id     BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY invoice_number (invoice_number),
            KEY order_id (order_id),
            KEY client_id (client_id),
            KEY ext_client_id (ext_client_id),
            KEY status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function create_clients_table(): void {
        global $wpdb;

        $table   = self::clients_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(255)    NOT NULL,
            email       VARCHAR(255)    DEFAULT NULL,
            company     VARCHAR(255)    DEFAULT NULL,
            address     TEXT            DEFAULT NULL,
            city        VARCHAR(100)    DEFAULT NULL,
            postal_code VARCHAR(20)     DEFAULT NULL,
            country     VARCHAR(100)    DEFAULT 'France',
            phone       VARCHAR(50)     DEFAULT NULL,
            vat_number  VARCHAR(50)     DEFAULT NULL,
            siret       VARCHAR(50)     DEFAULT NULL,
            notes       TEXT            DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email (email(191))
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ================================================================
     *  SETTINGS
     * ================================================================ */

    public static function get_settings(): array {
        $defaults = [
            'company_name'    => '',
            'company_address' => '',
            'company_city'    => '',
            'company_postal'  => '',
            'company_country' => 'France',
            'company_phone'   => '',
            'company_email'   => get_bloginfo( 'admin_email' ),
            'company_logo'    => '',
            'vat_number'      => '',
            'siret_ifu'       => '',
            'siret_label'     => 'SIRET/IFU',
            'invoice_prefix'  => 'FACT-',
            'invoice_start'   => 1,
            'invoice_padding' => 3,
            'quote_prefix'    => 'DEVIS-',
            'quote_start'     => 1,
            'quote_padding'   => 3,
            'tax_rate'        => 20,
            'tax_notice'      => '',
            'payment_terms'   => __( 'Paiement à réception de facture.', 'clielo' ),
            'footer_text'     => '',
        ];
        $saved = get_option( 'clielo_invoice_settings', [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        return wp_parse_args( $saved, $defaults );
    }

    /* ================================================================
     *  HELPERS
     * ================================================================ */

    private static function get_status_labels(): array {
        return [
            self::STATUS_DRAFT     => __( 'Brouillon', 'clielo' ),
            self::STATUS_PENDING   => __( 'En attente', 'clielo' ),
            self::STATUS_VALIDATED => __( 'Validée', 'clielo' ),
            self::STATUS_PAID      => __( 'Payée', 'clielo' ),
            self::STATUS_CANCELLED => __( 'Annulée', 'clielo' ),
        ];
    }

    private static function get_status_colors(): array {
        return [
            self::STATUS_DRAFT     => '#9ca3af',
            self::STATUS_PENDING   => '#f59e0b',
            self::STATUS_VALIDATED => '#3b82f6',
            self::STATUS_PAID      => '#10b981',
            self::STATUS_CANCELLED => '#ef4444',
        ];
    }

    private static function generate_quote_number(): string {
        global $wpdb;

        $settings = self::get_settings();
        $prefix   = $settings['quote_prefix'] ?: 'DEVIS-';
        $start    = absint( $settings['quote_start'] ?? 1 );
        $padding  = max( 1, absint( $settings['quote_padding'] ?? 3 ) );
        $table    = self::invoices_table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $last = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT invoice_number FROM {$table} WHERE invoice_number LIKE %s ORDER BY id DESC LIMIT 1",
            $wpdb->esc_like( $prefix ) . '%'
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $seq = $start;
        if ( $last ) {
            $num = absint( substr( $last, strlen( $prefix ) ) );
            if ( $num >= $start ) {
                $seq = $num + 1;
            }
        }

        return $prefix . str_pad( $seq, $padding, '0', STR_PAD_LEFT );
    }

    public static function ajax_generate_quote_doc(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Action non autorisée.', 'clielo' ) ], 403 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $post_id  = absint( $_POST['post_id'] ?? 0 );

        if ( ! $order_id || ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'clielo' ) ], 400 );
        }

        $order = Clielo_Orders::get_order( $order_id );
        if ( ! $order || $order->status !== Clielo_Orders::STATUS_QUOTE ) {
            wp_send_json_error( [ 'message' => __( 'Devis introuvable.', 'clielo' ) ], 404 );
        }

        global $wpdb;
        $table = self::invoices_table_name();

        // Vérifier qu'un document DEVIS n'existe pas déjà pour cette commande
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d AND invoice_type = 'quote' LIMIT 1", $order_id ) );
        if ( $existing ) {
            $view_url = admin_url( 'admin.php?page=clielo-invoice-view&invoice_id=' . (int) $existing );
            wp_send_json_success( [ 'invoice_id' => (int) $existing, 'view_url' => $view_url ] );
            return;
        }

        $settings  = self::get_settings();
        $tax_rate  = floatval( $settings['tax_rate'] ?? 20 );
        $base      = json_decode( $order->base_offer ?? '{}', true ) ?: [];
        $options   = json_decode( $order->selected_options ?? '[]', true ) ?: [];

        // Prix personnalisés envoyés depuis la modale (mode hide_prices)
        $custom_prices_raw = sanitize_text_field( wp_unslash( $_POST['custom_prices'] ?? '' ) );
        $custom_prices     = $custom_prices_raw ? ( json_decode( $custom_prices_raw, true ) ?: [] ) : [];

        $service_name = get_the_title( (int) $order->post_id );
        $pack_name    = $base['name'] ?? '';

        $items = [];
        if ( $pack_name !== '' ) {
            $pack_price = isset( $custom_prices['pack'] ) ? floatval( $custom_prices['pack'] ) : floatval( $base['price'] ?? 0 );
            $items[] = [
                'service_name' => $service_name,
                'description'  => $pack_name,
                'quantity'     => 1,
                'unit_price'   => $pack_price,
                'total'        => $pack_price,
            ];
        }
        foreach ( $options as $i => $opt ) {
            $opt_price = isset( $custom_prices[ 'opt_' . $i ] ) ? floatval( $custom_prices[ 'opt_' . $i ] ) : floatval( $opt['price'] ?? 0 );
            $items[] = [
                'description' => $opt['name'] ?? '',
                'quantity'    => 1,
                'unit_price'  => $opt_price,
                'total'       => $opt_price,
            ];
        }

        // Options avancées dynamiques
        if ( ! empty( $order->advanced_options_data ) ) {
            $adv_data = json_decode( $order->advanced_options_data, true ) ?: [];
            foreach ( $adv_data as $adv ) {
                $lbl   = sanitize_text_field( $adv['label'] ?? '' );
                $qty   = absint( $adv['qty'] ?? 1 );
                $price = floatval( $adv['price'] ?? 0 );
                $mode  = $adv['mode'] ?? 'unit';
                if ( ! $lbl || ! $price ) {
                    continue;
                }
                $items[] = [
                    'description' => $lbl . ( $mode === 'monthly' ? ' (' . __( 'mensuel', 'clielo' ) . ')' : '' ),
                    'quantity'    => $qty,
                    'unit_price'  => $price,
                    'total'       => round( $qty * $price, 2 ),
                ];
            }
        }

        $subtotal   = array_sum( array_column( $items, 'total' ) );
        $tax_amount = round( $subtotal * $tax_rate / 100, 2 );
        $total      = round( $subtotal + $tax_amount, 2 );

        $quote_number = self::generate_quote_number();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert(
            $table,
            [
                'invoice_number' => $quote_number,
                'order_id'       => $order_id,
                'client_id'      => (int) $order->client_id,
                'status'         => self::STATUS_DRAFT,
                'items'          => wp_json_encode( $items ),
                'subtotal'       => $subtotal,
                'tax_rate'       => $tax_rate,
                'tax_amount'     => $tax_amount,
                'total'          => $total,
                'invoice_type'   => 'quote',
                'created_at'     => current_time( 'mysql' ),
                'updated_at'     => current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de la génération du devis.', 'clielo' ) ], 500 );
        }

        $invoice_id = (int) $wpdb->insert_id;
        $view_url   = admin_url( 'admin.php?page=clielo-invoice-view&invoice_id=' . $invoice_id );

        // Message système dans le chat
        $doc_msg = sprintf(
            "--- %s %s ---\n%s",
            $quote_number,
            __( 'Devis généré', 'clielo' ),
            sprintf(
                /* translators: %s: quote document number */
                __( 'Un document devis (%s) a été généré pour cette demande.', 'clielo' ),
                $quote_number
            )
        );
        Clielo_DB::insert_message( (int) $order->post_id, 0, $doc_msg, (int) $order->client_id );

        wp_send_json_success( [ 'invoice_id' => $invoice_id, 'view_url' => $view_url, 'quote_number' => $quote_number ] );
    }

    private static function generate_invoice_number(): string {
        global $wpdb;

        $settings = self::get_settings();
        $prefix   = $settings['invoice_prefix'] ?: 'FACT-';
        $start    = absint( $settings['invoice_start'] ?? 1 );
        $padding  = max( 1, absint( $settings['invoice_padding'] ?? 3 ) );
        $table    = self::invoices_table_name();

        // Chercher uniquement les factures qui commencent par le préfixe actuel
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $last = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT invoice_number FROM {$table} WHERE invoice_number LIKE %s ORDER BY id DESC LIMIT 1",
            $wpdb->esc_like( $prefix ) . '%'
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $seq = max( 1, $start );
        if ( $last ) {
            // Extraire la partie numérique après le préfixe exact
            $num_part = substr( $last, strlen( $prefix ) );
            $num      = absint( $num_part );
            if ( $num > 0 ) {
                $seq = max( $seq, $num + 1 );
            }
        }

        return $prefix . str_pad( $seq, $padding, '0', STR_PAD_LEFT );
    }

    public static function get_client_info( ?int $client_id, ?int $ext_client_id ): ?object {
        global $wpdb;

        if ( $client_id ) {
            $user = get_userdata( $client_id );
            if ( $user ) {
                return (object) [
                    'name'        => $user->display_name,
                    'email'       => $user->user_email,
                    'company'     => '',
                    'address'     => '',
                    'city'        => '',
                    'postal_code' => '',
                    'country'     => '',
                    'phone'       => '',
                    'vat_number'  => '',
                    'siret'       => '',
                    'is_wp'       => true,
                ];
            }
        }

        if ( $ext_client_id ) {
            $table  = self::clients_table_name();
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are plugin-defined constants.
            $client = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $ext_client_id ) );
            if ( $client ) {
                $client->is_wp = false;
                return $client;
            }
        }

        return null;
    }

    public static function get_invoices( string $status = '', int $limit = 50 ): array {
        global $wpdb;

        $table = self::invoices_table_name();
        $where = "WHERE invoice_type NOT IN ('quote', 'quote_request')";
        if ( $status && in_array( $status, [ self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_PAID, self::STATUS_CANCELLED ], true ) ) {
            $where .= $wpdb->prepare( ' AND status = %s', $status );
        }

        $limit = absint( $limit );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are plugin-defined constants; queries use no user input.
        return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT {$limit}" );
    }

    public static function get_invoices_for_client( int $client_id ): array {
        global $wpdb;

        $table = self::invoices_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT * FROM {$table} WHERE client_id = %d AND status IN ('validated','paid') ORDER BY created_at DESC",
            $client_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function get_invoice( int $invoice_id ): ?object {
        global $wpdb;

        $table = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are plugin-defined constants.
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $invoice_id ) );
    }

    private static function get_status_counts(): array {
        global $wpdb;

        $table  = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are plugin-defined constants; queries use no user input.
        $rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$table} WHERE invoice_type NOT IN ('quote', 'quote_request') GROUP BY status" );
        $counts = [ 'all' => 0 ];

        foreach ( [ self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_PAID, self::STATUS_CANCELLED ] as $s ) {
            $counts[ $s ] = 0;
        }

        foreach ( $rows as $row ) {
            $counts[ $row->status ] = (int) $row->cnt;
            $counts['all']         += (int) $row->cnt;
        }

        return $counts;
    }

    private static function get_all_ext_clients(): array {
        global $wpdb;
        $table = self::clients_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are plugin-defined constants; queries use no user input.
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );
    }

    /* ================================================================
     *  ADMIN MENU
     * ================================================================ */

    public static function add_menu(): void {
        add_submenu_page(
            'clielo',
            __( 'Invoices', 'clielo' ),
            __( 'Invoices', 'clielo' ),
            'manage_options',
            'clielo-invoices',
            [ __CLASS__, 'render_invoices_list' ]
        );
        add_submenu_page(
            'clielo',
            __( 'New invoice', 'clielo' ),
            __( 'New invoice', 'clielo' ),
            'manage_options',
            'clielo-invoice-new',
            [ __CLASS__, 'render_invoice_new' ]
        );
        add_submenu_page(
            'clielo',
            __( 'External clients', 'clielo' ),
            __( 'External clients', 'clielo' ),
            'manage_options',
            'clielo-clients',
            [ __CLASS__, 'render_clients_page' ]
        );
        add_submenu_page(
            'clielo',
            __( 'Invoice settings', 'clielo' ),
            __( 'Invoice settings', 'clielo' ),
            'manage_options',
            'clielo-invoice-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
        // Hidden page to view an invoice
        add_submenu_page(
            null,
            __( 'View invoice', 'clielo' ),
            '',
            'manage_options',
            'clielo-invoice-view',
            [ __CLASS__, 'render_invoice_view' ]
        );
        // Hidden page to edit a draft invoice
        add_submenu_page(
            null,
            __( 'Edit invoice', 'clielo' ),
            '',
            'manage_options',
            'clielo-invoice-edit',
            [ __CLASS__, 'render_invoice_edit' ]
        );
    }

    private static function get_invoice_view_css( string $color ): string {
        return
            '.clielo-invoice-page{max-width:800px;margin:20px auto;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:40px;padding-bottom:60px;box-shadow:0 2px 8px rgba(0,0,0,0.06);font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#333}' .
            '.clielo-inv-header{display:flex;justify-content:space-between;align-items:flex-start}' .
            '.clielo-inv-logo img{max-height:60px}' .
            '.clielo-inv-company{font-size:12px;color:#555;line-height:1.6;margin-top:10px}' .
            '.clielo-inv-company strong{font-size:16px;color:#222;display:block;margin-bottom:4px}' .
            '.clielo-inv-header-right{text-align:right}' .
            '.clielo-inv-header-right h3{font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin:0 0 8px}' .
            '.clielo-inv-header-right p{margin:0;font-size:13px;line-height:1.5}' .
            '.clielo-inv-parties{display:flex;justify-content:space-between;gap:20px;margin-top:6px;margin-bottom:15px;align-items:flex-end}' .
            '.clielo-inv-emetteur{flex:1}' .
            '.clielo-inv-emetteur h3,.clielo-inv-client h3{font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin:0 0 8px}' .
            '.clielo-inv-emetteur p,.clielo-inv-client p{margin:0;font-size:13px;line-height:1.5}' .
            '.clielo-inv-client{flex:1;text-align:right}' .
            '.clielo-inv-items-table{width:100%;border-collapse:collapse;margin-top:80px;margin-bottom:20px}' .
            '.clielo-inv-items-table th{background:#f9f9f9;text-align:left;padding:10px 12px;font-size:11px;font-weight:700;color:#555;text-transform:uppercase;border-bottom:2px solid #e0e0e0}' .
            '.clielo-inv-items-table td{padding:10px 12px;font-size:13px;border-bottom:1px solid #f0f0f0}' .
            '.clielo-inv-items-table .text-right{text-align:right}' .
            '.clielo-inv-totals{margin-left:auto;width:280px}' .
            '.clielo-inv-totals-row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px}' .
            '.clielo-inv-totals-row.total-row{border-top:2px solid #222;font-size:16px;font-weight:700;padding-top:10px;margin-top:4px}' .
            '.clielo-inv-notes{margin-top:40px;padding:16px;background:#f9f9f9;border-radius:6px;font-size:12px;color:#555;line-height:1.5;width:40%}' .
            '.clielo-inv-footer-text{text-align:center;font-size:11px;color:#999;border-top:1px solid #eee;padding-top:12px;margin-top:auto}' .
            '.clielo-inv-actions{margin-top:20px;display:flex;gap:8px;flex-wrap:wrap}' .
            '.clielo-inv-actions button{padding:8px 20px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}' .
            '@media print{' .
                '@page{size:A4;margin:10mm 10mm 14mm 10mm}' .
                '#adminmenumain,#wpadminbar,.no-print,#wpfooter,.update-nag,.notice{display:none!important}' .
                '#wpcontent{margin-left:0!important;padding:0!important}' .
                '.clielo-invoice-page{box-shadow:none!important;border:none!important;border-radius:0!important;padding:20px!important;padding-bottom:10px!important;margin:0!important;max-width:100%!important;display:flex!important;flex-direction:column!important;min-height:calc(297mm - 24mm - 40px)!important}' .
                '.clielo-inv-header{display:flex!important;justify-content:space-between!important;align-items:flex-start!important}' .
                '.clielo-inv-parties{display:flex!important;justify-content:space-between!important;gap:20px!important;margin-top:6px!important;margin-bottom:15px!important;align-items:flex-end!important}' .
                '.clielo-inv-emetteur{flex:1!important}' .
                '.clielo-inv-client{flex:1!important;text-align:right!important}' .
                '.clielo-inv-notes{margin-top:40px!important;width:40%!important;background:#f9f9f9!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}' .
                '.clielo-inv-items-table{margin-top:80px!important}' .
                '.clielo-inv-items-table th{background:#f9f9f9!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}' .
                '.clielo-inv-footer-text{margin-top:auto!important;text-align:center!important;padding-top:8px!important;border-top:1px solid #ccc!important;font-size:9px!important;color:#999!important;display:block!important}' .
            '}';
    }

    public static function enqueue_admin_scripts( string $hook ): void {
        if ( strpos( $hook, 'clielo-invoice' ) === false && strpos( $hook, 'clielo-clients' ) === false && strpos( $hook, 'clielo-quote' ) === false ) {
            return;
        }

        if ( strpos( $hook, 'clielo-invoice-settings' ) !== false ) {
            wp_enqueue_media();
        }

        if ( ! wp_script_is( 'clielo-invoices-js', 'registered' ) ) {
            wp_register_script( 'clielo-invoices-js', false, [ 'jquery' ], CLIELO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }
        wp_enqueue_script( 'clielo-invoices-js' );

        $color = esc_attr( Clielo_Admin::get_color() );

        wp_add_inline_style(
            'wp-admin',
            // Settings page
            '.clielo-inv-section{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:24px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)}' .
            '.clielo-inv-section h2{font-size:15px;font-weight:700;color:#222;margin:0 0 16px;padding:0 0 12px;border-bottom:1px solid #eee;display:flex;align-items:center;gap:8px}' .
            '.clielo-inv-field{margin-bottom:14px}' .
            '.clielo-inv-field label{display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:4px}' .
            '.clielo-inv-field input[type="text"],.clielo-inv-field input[type="number"],.clielo-inv-field input[type="email"],.clielo-inv-field textarea{width:100%;max-width:500px}' .
            '.clielo-inv-field textarea{height:80px}' .
            '.clielo-inv-row{display:flex;gap:16px;flex-wrap:wrap}' .
            '.clielo-inv-row .clielo-inv-field{flex:1;min-width:200px}' .
            '.clielo-inv-logo-preview{margin-top:8px}' .
            '.clielo-inv-logo-preview img{max-height:80px;border:1px solid #e0e0e0;border-radius:4px;padding:4px;background:#fafafa}' .
            // Clients page
            '.clielo-clients-wrap{display:flex;gap:24px;flex-wrap:wrap}' .
            '.clielo-clients-list{flex:1;min-width:400px}' .
            '.clielo-clients-form{flex:0 0 380px}' .
            '.clielo-clients-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden}' .
            '.clielo-clients-table th{background:#f9f9f9;text-align:left;padding:10px 12px;font-size:12px;font-weight:600;color:#555;border-bottom:1px solid #e0e0e0}' .
            '.clielo-clients-table td{padding:10px 12px;font-size:13px;color:#333;border-bottom:1px solid #f5f5f5}' .
            '.clielo-clients-table tr:last-child td{border-bottom:none}' .
            '.clielo-cl-form-card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)}' .
            '.clielo-cl-form-card h3{margin:0 0 16px;font-size:15px;font-weight:700;color:#222}' .
            '.clielo-cl-field{margin-bottom:12px}' .
            '.clielo-cl-field label{display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:3px}' .
            '.clielo-cl-field input,.clielo-cl-field textarea{width:100%}' .
            '.clielo-cl-field textarea{height:60px}' .
            '.clielo-cl-row{display:flex;gap:10px}' .
            '.clielo-cl-row .clielo-cl-field{flex:1}' .
            '.clielo-cl-actions a{cursor:pointer;font-size:12px;margin-right:8px}' .
            '.clielo-cl-actions .edit{color:#0073aa}' .
            '.clielo-cl-actions .delete{color:#dc3545}' .
            // List page (dynamic color)
            '.clielo-inv-filters{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}' .
            '.clielo-inv-filter{padding:6px 14px;border:1px solid #ddd;border-radius:20px;font-size:13px;font-weight:500;color:#555;text-decoration:none;background:#fff;cursor:pointer;transition:all .15s}' .
            '.clielo-inv-filter.active,.clielo-inv-filter:hover{border-color:' . $color . ';color:' . $color . '}' .
            '.clielo-inv-filter .count{font-size:11px;color:#999;margin-left:4px}' .
            '.clielo-inv-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden}' .
            '.clielo-inv-table th{background:#f9f9f9;text-align:left;padding:10px 12px;font-size:12px;font-weight:600;color:#555;border-bottom:1px solid #e0e0e0}' .
            '.clielo-inv-table td{padding:10px 12px;font-size:13px;color:#333;border-bottom:1px solid #f5f5f5}' .
            '.clielo-inv-table tr:last-child td{border-bottom:none}' .
            '.clielo-inv-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;color:#fff}' .
            '.clielo-inv-status-wrap{position:relative;display:inline-block}' .
            '.clielo-inv-badge.clickable{cursor:pointer;transition:opacity .15s}' .
            '.clielo-inv-badge.clickable:hover{opacity:.8}' .
            '.clielo-inv-status-dd{display:none;position:absolute;top:100%;left:0;margin-top:4px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.12);z-index:100;min-width:140px;overflow:hidden}' .
            '.clielo-inv-status-dd.open{display:block}' .
            '.clielo-inv-status-dd a{display:flex;align-items:center;gap:6px;padding:7px 12px;font-size:12px;color:#333;text-decoration:none;white-space:nowrap;transition:background .1s}' .
            '.clielo-inv-status-dd a:hover{background:#f5f5f5}' .
            '.clielo-inv-status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0}' .
            '.clielo-inv-act{font-size:12px;margin-right:6px;cursor:pointer;text-decoration:none}' .
            // New/Edit invoice
            '.clielo-newinv-section{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:24px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)}' .
            '.clielo-newinv-section h2{font-size:15px;font-weight:700;color:#222;margin:0 0 16px;padding:0 0 12px;border-bottom:1px solid #eee;display:flex;align-items:center;gap:8px}' .
            '.clielo-newinv-field{margin-bottom:14px}' .
            '.clielo-newinv-field label{display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:4px}' .
            '.clielo-newinv-field select,.clielo-newinv-field input,.clielo-newinv-field textarea{width:100%;max-width:500px}' .
            '.clielo-newinv-field textarea{height:80px}' .
            '.clielo-newinv-radio{display:flex;gap:16px;margin-bottom:12px}' .
            '.clielo-newinv-radio label{display:flex;align-items:center;gap:6px;font-size:14px;font-weight:500;cursor:pointer;color:#333}' .
            '.clielo-items-table{width:100%;border-collapse:collapse;margin-bottom:8px}' .
            '.clielo-items-table th{text-align:left;padding:8px;font-size:12px;font-weight:600;color:#555;background:#f9f9f9;border-bottom:1px solid #e0e0e0}' .
            '.clielo-items-table td{padding:6px 8px;border-bottom:1px solid #f5f5f5}' .
            '.clielo-items-table input{width:100%}' .
            '.clielo-items-rm{background:#dc3545;color:#fff;border:none;border-radius:3px;padding:4px 10px;cursor:pointer;font-size:11px}' .
            '.clielo-items-rm:hover{background:#c82333}' .
            '.clielo-newinv-totals{text-align:right;font-size:14px;color:#333;line-height:2}' .
            '.clielo-newinv-totals strong{font-size:16px}' .
            // View invoice page
            self::get_invoice_view_css( $color )
        );
    }

    /* ================================================================
     *  AUTO-GÉNÉRATION (commande acceptée)
     * ================================================================ */

    public static function on_order_accepted( int $order_id, string $new_status, string $old_status, int $acting_user_id ): void {
        if ( $new_status !== Clielo_Orders::STATUS_ACCEPTED && $new_status !== Clielo_Orders::STATUS_COMPLETED ) {
            return;
        }

        global $wpdb;

        // Vérifier qu'aucune facture de paiement non-annulée n'existe déjà
        // (le document DEVIS invoice_type='quote' ne compte pas comme facture de paiement)
        $table  = self::invoices_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT id FROM {$table} WHERE order_id = %d AND status != %s AND invoice_type != 'quote' LIMIT 1",
            $order_id, self::STATUS_CANCELLED
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $exists ) {
            return;
        }

        $order = Clielo_Orders::get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Les commandes avec échéancier ont leurs propres factures partielles
        if ( isset( $order->payment_mode ) && $order->payment_mode !== 'single' ) {
            return;
        }

        $settings = self::get_settings();
        $tax_rate = floatval( $settings['tax_rate'] );

        // Construire les items depuis la commande
        $service_name = get_the_title( (int) $order->post_id ) ?: '';
        $items        = [];
        $base_offer   = json_decode( $order->base_offer, true );
        if ( $base_offer ) {
            $items[] = [
                'service_name' => $service_name,
                'description'  => $base_offer['name'] ?? 'Pack',
                'quantity'     => 1,
                'unit_price'   => floatval( $base_offer['price'] ?? 0 ),
                'total'        => floatval( $base_offer['price'] ?? 0 ),
            ];
        }

        $selected_options = json_decode( $order->selected_options, true );
        if ( is_array( $selected_options ) ) {
            foreach ( $selected_options as $opt ) {
                $items[] = [
                    'description' => $opt['name'] ?? 'Option',
                    'quantity'    => 1,
                    'unit_price'  => floatval( $opt['price'] ?? 0 ),
                    'total'       => floatval( $opt['price'] ?? 0 ),
                ];
            }
        }

        // Options avancées dynamiques (nouveau système)
        $adv_order_data = [];
        if ( ! empty( $order->advanced_options_data ) ) {
            $adv_order_data = json_decode( $order->advanced_options_data, true ) ?: [];
        }

        if ( ! empty( $adv_order_data ) ) {
            // Nouveau système : lire depuis advanced_options_data
            foreach ( $adv_order_data as $asel ) {
                $qty   = absint( $asel['qty'] ?? 1 );
                $price = floatval( $asel['price'] ?? 0 );
                $mode  = $asel['mode'] ?? 'unit';
                $lbl   = sanitize_text_field( $asel['label'] ?? '' );
                if ( ! $lbl || ! $price ) {
                    continue;
                }
                $items[] = [
                    'description' => $lbl . ( $mode === 'monthly' ? ' (' . __( 'mensuel', 'clielo' ) . ')' : '' ),
                    'quantity'    => $qty,
                    'unit_price'  => $price,
                    'total'       => round( $qty * $price, 2 ),
                ];
            }
        } else {
            // Ancien système : colonnes individuelles (backward compat)
            $extra_pages      = (int) ( $order->extra_pages ?? 0 );
            $extra_page_price = floatval( $order->extra_page_price ?? 0 );
            $maintenance      = floatval( $order->maintenance_price ?? 0 );
            $express_days     = (int) ( $order->express_days ?? 0 );
            $express_price    = floatval( $order->express_price ?? 0 );

            if ( $extra_pages > 0 && $extra_page_price > 0 ) {
                $items[] = [
                    'description' => Clielo_Admin::get_extra_pages_label( (int) $order->post_id ),
                    'quantity'    => $extra_pages,
                    'unit_price'  => $extra_page_price,
                    'total'       => round( $extra_pages * $extra_page_price, 2 ),
                ];
            }
            if ( $maintenance > 0 ) {
                $items[] = [
                    'description' => Clielo_Admin::get_maintenance_label( (int) $order->post_id ),
                    'quantity'    => 1,
                    'unit_price'  => $maintenance,
                    'total'       => $maintenance,
                ];
            }
            if ( $express_days > 0 && $express_price > 0 ) {
                $items[] = [
                    'description' => Clielo_Admin::get_express_label( (int) $order->post_id ),
                    'quantity'    => $express_days,
                    'unit_price'  => $express_price,
                    'total'       => round( $express_days * $express_price, 2 ),
                ];
            }
        }

        $subtotal   = array_sum( array_column( $items, 'total' ) );
        $tax_amount = round( $subtotal * $tax_rate / 100, 2 );
        $total      = $subtotal + $tax_amount;

        // Stripe encaissé OU commande auto-terminée → facture directement « paid »
        $is_stripe_paid = ! empty( $order->stripe_payment_intent );
        $invoice_status = ( $is_stripe_paid || $new_status === Clielo_Orders::STATUS_COMPLETED )
            ? self::STATUS_PAID
            : self::STATUS_PENDING;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert( $table, [
            'invoice_number' => self::generate_invoice_number(),
            'order_id'       => $order_id,
            'client_id'      => (int) $order->client_id,
            'ext_client_id'  => null,
            'status'         => $invoice_status,
            'items'          => wp_json_encode( $items ),
            'subtotal'       => $subtotal,
            'tax_rate'       => $tax_rate,
            'tax_amount'     => $tax_amount,
            'total'          => $total,
            'notes'          => $settings['payment_terms'],
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
            'paid_at'        => ( $is_stripe_paid || $new_status === Clielo_Orders::STATUS_COMPLETED ) ? current_time( 'mysql' ) : null,
        ] );

        if ( ! $inserted ) {
            // silent fail — no action needed
        }
    }

    /* ================================================================
     *  Factures partielles (acompte / solde / mensualité)
     * ================================================================ */

    /**
     * Crée une facture partielle liée à une ligne d'échéancier (mode deposit ou installments).
     *
     * @param int    $order_id       ID commande
     * @param float  $amount_ttc     Montant TTC de cette facture
     * @param string $invoice_type   'acompte' | 'solde' | 'mensualite'
     * @param int    $schedule_id    ID ligne clielo_payment_schedule (0 si inconnu)
     * @param int    $installment_no Numéro de mensualité
     * @return int|false  ID facture ou false
     */
    public static function create_partial_invoice( int $order_id, float $amount_ttc, string $invoice_type, int $schedule_id = 0, int $installment_no = 0 ) {
        global $wpdb;
        $table = self::invoices_table_name();

        // Idempotence : une seule facture par ligne d'échéancier
        if ( $schedule_id ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                "SELECT id FROM {$table} WHERE schedule_id = %d AND status != %s LIMIT 1",
                $schedule_id, self::STATUS_CANCELLED
            ) );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( $exists ) {
                return false;
            }
        }

        $order = Clielo_Orders::get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        $settings = self::get_settings();
        $tax_rate = floatval( $settings['tax_rate'] );

        $service_name     = get_the_title( (int) $order->post_id ) ?: 'Service';
        $base_offer       = json_decode( $order->base_offer ?? '', true ) ?: [];
        $selected_options = json_decode( $order->selected_options ?? '', true ) ?: [];

        $type_label = match ( $invoice_type ) {
            /* translators: deposit percentage label on invoice */
            'acompte'    => __( 'Acompte 50%', 'clielo' ),
            'solde'      => __( 'Solde', 'clielo' ),
            /* translators: %d: installment number */
            'mensualite' => sprintf( __( 'Mensualité %d', 'clielo' ), $installment_no ),
            default      => '',
        };

        $pack_name = $base_offer['name'] ?? '';

        // Facteur de réduction : proportion de ce paiement sur le total TTC du contrat
        $full_total_ttc = floatval( $order->total_price ?? 0 );
        $factor         = ( $full_total_ttc > 0 ) ? ( $amount_ttc / $full_total_ttc ) : 1.0;

        // Construction des items à prix proportionnel
        $items        = [];
        $items_ht_sum = 0.0;

        // Pack principal (en gras via service_name)
        $pack_ht  = round( floatval( $base_offer['price'] ?? 0 ) * $factor, 2 );
        $items_ht_sum += $pack_ht;
        $items[] = [
            'service_name' => $service_name,
            'description'  => $pack_name . ( $type_label ? ' — ' . $type_label : '' ),
            'quantity'     => 1,
            'unit_price'   => $pack_ht,
            'total'        => $pack_ht,
        ];

        // Options sélectionnées (packs/options classiques)
        foreach ( $selected_options as $opt ) {
            $opt_name = $opt['name'] ?? '';
            if ( empty( $opt_name ) ) {
                continue;
            }
            $opt_ht = round( floatval( $opt['price'] ?? 0 ) * $factor, 2 );
            $items_ht_sum += $opt_ht;
            $items[] = [
                'description' => $opt_name,
                'quantity'    => 1,
                'unit_price'  => $opt_ht,
                'total'       => $opt_ht,
            ];
        }

        // Options avancées dynamiques
        $adv_order_data_partial = [];
        if ( ! empty( $order->advanced_options_data ) ) {
            $adv_order_data_partial = json_decode( $order->advanced_options_data, true ) ?: [];
        }
        if ( ! empty( $adv_order_data_partial ) ) {
            foreach ( $adv_order_data_partial as $asel ) {
                $lbl  = sanitize_text_field( $asel['label'] ?? '' );
                $qty  = absint( $asel['qty'] ?? 1 );
                $pht  = floatval( $asel['price'] ?? 0 );
                if ( ! $lbl ) {
                    continue;
                }
                $unit_ht  = round( $pht * $factor, 2 );
                $total_ht = round( $unit_ht * $qty, 2 );
                $items_ht_sum += $total_ht;
                $items[] = [
                    'description' => $lbl,
                    'quantity'    => $qty,
                    'unit_price'  => $unit_ht,
                    'total'       => $total_ht,
                ];
            }
        } else {
            // Backward compat : colonnes individuelles (anciennes commandes)
            $extra_pages = (int) ( $order->extra_pages ?? 0 );
            if ( $extra_pages > 0 ) {
                $unit_ht  = round( floatval( $order->extra_page_price ?? 0 ) * $factor, 2 );
                $total_ht = round( $unit_ht * $extra_pages, 2 );
                $items_ht_sum += $total_ht;
                $items[] = [
                    'description' => Clielo_Admin::get_extra_pages_label( (int) $order->post_id ),
                    'quantity'    => $extra_pages,
                    'unit_price'  => $unit_ht,
                    'total'       => $total_ht,
                ];
            }
            $maintenance = floatval( $order->maintenance_price ?? 0 );
            if ( $maintenance > 0 ) {
                $unit_ht = round( $maintenance * $factor, 2 );
                $items_ht_sum += $unit_ht;
                $items[] = [
                    'description' => Clielo_Admin::get_maintenance_label( (int) $order->post_id ),
                    'quantity'    => 1,
                    'unit_price'  => $unit_ht,
                    'total'       => $unit_ht,
                ];
            }
            $express_days = (int) ( $order->express_days ?? 0 );
            if ( $express_days > 0 ) {
                $unit_ht  = round( floatval( $order->express_price ?? 0 ) * $factor, 2 );
                $total_ht = round( $unit_ht * $express_days, 2 );
                $items_ht_sum += $total_ht;
                $items[] = [
                    'description' => Clielo_Admin::get_express_label( (int) $order->post_id ),
                    'quantity'    => $express_days,
                    'unit_price'  => $unit_ht,
                    'total'       => $total_ht,
                ];
            }
        }

        // Totaux : assure que sous-total + TVA = amount_ttc exactement
        $subtotal   = round( $items_ht_sum, 2 );
        $tax_amount = round( $amount_ttc - $subtotal, 2 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert( $table, [
            'invoice_number' => self::generate_invoice_number(),
            'order_id'       => $order_id,
            'client_id'      => (int) $order->client_id,
            'ext_client_id'  => null,
            'status'         => self::STATUS_PAID,
            'items'          => wp_json_encode( $items ),
            'subtotal'       => $subtotal,
            'tax_rate'       => $tax_rate,
            'tax_amount'     => $tax_amount,
            'total'          => $amount_ttc,
            'notes'          => $settings['payment_terms'],
            'invoice_type'   => $invoice_type,
            'schedule_id'    => $schedule_id ?: null,
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
            'paid_at'        => current_time( 'mysql' ),
        ] );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /* ================================================================
     *  AJAX — Transitions de statut
     * ================================================================ */

    public static function ajax_validate(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        global $wpdb;
        $invoice_id = absint( $_POST['invoice_id'] ?? 0 );
        if ( ! $invoice_id ) {
            wp_send_json_error( [ 'message' => __( 'ID manquant.', 'clielo' ) ], 400 );
        }

        $table = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $table, [
            'status'       => self::STATUS_VALIDATED,
            'validated_at' => current_time( 'mysql' ),
            'updated_at'   => current_time( 'mysql' ),
        ], [ 'id' => $invoice_id ] );

        wp_send_json_success();
    }

    public static function ajax_mark_paid(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        global $wpdb;
        $invoice_id = absint( $_POST['invoice_id'] ?? 0 );
        if ( ! $invoice_id ) {
            wp_send_json_error( [ 'message' => __( 'ID manquant.', 'clielo' ) ], 400 );
        }

        $table = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $table, [
            'status'     => self::STATUS_PAID,
            'paid_at'    => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $invoice_id ] );

        wp_send_json_success();
    }

    public static function ajax_cancel(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        global $wpdb;
        $invoice_id = absint( $_POST['invoice_id'] ?? 0 );
        if ( ! $invoice_id ) {
            wp_send_json_error( [ 'message' => __( 'ID manquant.', 'clielo' ) ], 400 );
        }

        $table = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $table, [
            'status'     => self::STATUS_CANCELLED,
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $invoice_id ] );

        wp_send_json_success();
    }

    /* ================================================================
     *  AJAX — Sauvegarde facture manuelle
     * ================================================================ */

    public static function ajax_save_invoice(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        global $wpdb;

        $client_type   = sanitize_text_field( wp_unslash( $_POST['client_type'] ?? 'wp' ) );
        $client_id     = absint( $_POST['client_id'] ?? 0 );
        $ext_client_id = absint( $_POST['ext_client_id'] ?? 0 );
        $order_id      = absint( $_POST['order_id'] ?? 0 );
        $tax_rate      = floatval( $_POST['tax_rate'] ?? 20 );
        $notes         = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $save_status   = sanitize_text_field( wp_unslash( $_POST['save_status'] ?? self::STATUS_DRAFT ) );
        $invoice_type  = sanitize_text_field( wp_unslash( $_POST['invoice_type'] ?? 'single' ) );
        if ( ! in_array( $invoice_type, [ 'single', 'acompte', 'solde' ], true ) ) {
            $invoice_type = 'single';
        }

        // Construire les items
        $raw_items = wp_unslash( $_POST['items'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array items are sanitized individually in the foreach loop below.
        $items     = [];
        if ( is_array( $raw_items ) ) {
            foreach ( $raw_items as $item ) {
                $desc = sanitize_text_field( $item['description'] ?? '' );
                if ( empty( $desc ) ) {
                    continue;
                }
                $qty   = max( 1, absint( $item['quantity'] ?? 1 ) );
                $price = floatval( $item['unit_price'] ?? 0 );
                $items[] = [
                    'description' => $desc,
                    'quantity'    => $qty,
                    'unit_price'  => $price,
                    'total'       => round( $qty * $price, 2 ),
                ];
            }
        }

        if ( empty( $items ) ) {
            wp_send_json_error( [ 'message' => __( 'Ajoutez au moins un article.', 'clielo' ) ], 400 );
        }

        if ( $client_type === 'wp' && ! clielo_is_premium() ) {
            wp_send_json_error( [ 'message' => __( 'La facturation des clients WordPress nécessite le plan premium.', 'clielo' ) ], 403 );
        }
        if ( $client_type === 'wp' && ! $client_id ) {
            wp_send_json_error( [ 'message' => __( 'Sélectionnez un client.', 'clielo' ) ], 400 );
        }
        if ( $client_type === 'ext' && ! $ext_client_id ) {
            wp_send_json_error( [ 'message' => __( 'Sélectionnez un client externe.', 'clielo' ) ], 400 );
        }

        $subtotal   = array_sum( array_column( $items, 'total' ) );
        $tax_amount = round( $subtotal * $tax_rate / 100, 2 );
        $total      = $subtotal + $tax_amount;

        $valid_statuses = [ self::STATUS_DRAFT, self::STATUS_VALIDATED ];
        if ( ! in_array( $save_status, $valid_statuses, true ) ) {
            $save_status = self::STATUS_DRAFT;
        }

        $data = [
            'invoice_number' => self::generate_invoice_number(),
            'order_id'       => $order_id ?: null,
            'client_id'      => $client_type === 'wp' ? $client_id : null,
            'ext_client_id'  => $client_type === 'ext' ? $ext_client_id : null,
            'status'         => $save_status,
            'items'          => wp_json_encode( $items ),
            'subtotal'       => $subtotal,
            'tax_rate'       => $tax_rate,
            'tax_amount'     => $tax_amount,
            'total'          => $total,
            'notes'          => $notes,
            'invoice_type'   => $invoice_type,
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
        ];

        if ( $save_status === self::STATUS_VALIDATED ) {
            $data['validated_at'] = current_time( 'mysql' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert( self::invoices_table_name(), $data );

        if ( ! $inserted ) {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de la création.', 'clielo' ) ], 500 );
        }

        wp_send_json_success( [ 'invoice_id' => (int) $wpdb->insert_id ] );
    }

    /* ================================================================
     *  AJAX — Mise à jour facture brouillon
     * ================================================================ */

    public static function ajax_update_invoice(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        global $wpdb;

        $invoice_id = absint( $_POST['invoice_id'] ?? 0 );
        if ( ! $invoice_id ) {
            wp_send_json_error( [ 'message' => __( 'Facture introuvable.', 'clielo' ) ], 400 );
        }

        $invoice = self::get_invoice( $invoice_id );
        if ( ! $invoice || $invoice->status !== self::STATUS_DRAFT ) {
            wp_send_json_error( [ 'message' => __( 'Seuls les brouillons peuvent être modifiés.', 'clielo' ) ], 400 );
        }

        $client_type   = sanitize_text_field( wp_unslash( $_POST['client_type'] ?? 'wp' ) );
        $client_id     = absint( $_POST['client_id'] ?? 0 );
        $ext_client_id = absint( $_POST['ext_client_id'] ?? 0 );
        $tax_rate      = floatval( $_POST['tax_rate'] ?? 20 );
        $notes         = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $save_status   = sanitize_text_field( wp_unslash( $_POST['save_status'] ?? self::STATUS_DRAFT ) );
        $invoice_type  = sanitize_text_field( wp_unslash( $_POST['invoice_type'] ?? 'single' ) );
        if ( ! in_array( $invoice_type, [ 'single', 'acompte', 'solde' ], true ) ) {
            $invoice_type = 'single';
        }

        $raw_items = wp_unslash( $_POST['items'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array items are sanitized individually in the foreach loop below.
        $items     = [];
        if ( is_array( $raw_items ) ) {
            foreach ( $raw_items as $item ) {
                $desc = sanitize_text_field( $item['description'] ?? '' );
                if ( empty( $desc ) ) {
                    continue;
                }
                $qty   = max( 1, absint( $item['quantity'] ?? 1 ) );
                $price = floatval( $item['unit_price'] ?? 0 );
                $items[] = [
                    'description' => $desc,
                    'quantity'    => $qty,
                    'unit_price'  => $price,
                    'total'       => round( $qty * $price, 2 ),
                ];
            }
        }

        if ( empty( $items ) ) {
            wp_send_json_error( [ 'message' => __( 'Ajoutez au moins un article.', 'clielo' ) ], 400 );
        }

        if ( $client_type === 'wp' && ! clielo_is_premium() ) {
            wp_send_json_error( [ 'message' => __( 'La facturation des clients WordPress nécessite le plan premium.', 'clielo' ) ], 403 );
        }
        if ( $client_type === 'wp' && ! $client_id ) {
            wp_send_json_error( [ 'message' => __( 'Sélectionnez un client.', 'clielo' ) ], 400 );
        }
        if ( $client_type === 'ext' && ! $ext_client_id ) {
            wp_send_json_error( [ 'message' => __( 'Sélectionnez un client externe.', 'clielo' ) ], 400 );
        }

        $subtotal   = array_sum( array_column( $items, 'total' ) );
        $tax_amount = round( $subtotal * $tax_rate / 100, 2 );
        $total      = $subtotal + $tax_amount;

        $valid_statuses = [ self::STATUS_DRAFT, self::STATUS_VALIDATED ];
        if ( ! in_array( $save_status, $valid_statuses, true ) ) {
            $save_status = self::STATUS_DRAFT;
        }

        $data = [
            'client_id'      => $client_type === 'wp' ? $client_id : null,
            'ext_client_id'  => $client_type === 'ext' ? $ext_client_id : null,
            'status'         => $save_status,
            'items'          => wp_json_encode( $items ),
            'subtotal'       => $subtotal,
            'tax_rate'       => $tax_rate,
            'tax_amount'     => $tax_amount,
            'total'          => $total,
            'notes'          => $notes,
            'invoice_type'   => $invoice_type,
            'updated_at'     => current_time( 'mysql' ),
        ];

        if ( $save_status === self::STATUS_VALIDATED ) {
            $data['validated_at'] = current_time( 'mysql' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update( self::invoices_table_name(), $data, [ 'id' => $invoice_id ] );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de la mise à jour.', 'clielo' ) ], 500 );
        }

        wp_send_json_success( [ 'invoice_id' => $invoice_id ] );
    }

    /* ================================================================
     *  AJAX — Changement de statut rapide
     * ================================================================ */

    public static function ajax_set_status(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        global $wpdb;

        $invoice_id = absint( $_POST['invoice_id'] ?? 0 );
        $new_status = sanitize_text_field( wp_unslash( $_POST['new_status'] ?? '' ) );

        if ( ! $invoice_id ) {
            wp_send_json_error( [ 'message' => __( 'Facture introuvable.', 'clielo' ) ], 400 );
        }

        $invoice = self::get_invoice( $invoice_id );
        if ( ! $invoice ) {
            wp_send_json_error( [ 'message' => __( 'Facture introuvable.', 'clielo' ) ], 404 );
        }

        // Transitions autorisées
        $allowed = [
            self::STATUS_DRAFT     => [ self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_CANCELLED ],
            self::STATUS_PENDING   => [ self::STATUS_VALIDATED, self::STATUS_CANCELLED ],
            self::STATUS_VALIDATED => [ self::STATUS_PAID, self::STATUS_CANCELLED ],
            self::STATUS_PAID      => [],
            self::STATUS_CANCELLED => [],
        ];

        $transitions = $allowed[ $invoice->status ] ?? [];
        if ( ! in_array( $new_status, $transitions, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Transition de statut non autorisée.', 'clielo' ) ], 400 );
        }

        $data = [
            'status'     => $new_status,
            'updated_at' => current_time( 'mysql' ),
        ];

        if ( $new_status === self::STATUS_VALIDATED ) {
            $data['validated_at'] = current_time( 'mysql' );
        }
        if ( $new_status === self::STATUS_PAID ) {
            $data['paid_at'] = current_time( 'mysql' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( self::invoices_table_name(), $data, [ 'id' => $invoice_id ] );

        wp_send_json_success();
    }

    /* ================================================================
     *  AJAX — Clients externes CRUD
     * ================================================================ */

    public static function ajax_save_client(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        global $wpdb;

        $id   = absint( $_POST['client_id'] ?? 0 );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => __( 'Le nom est obligatoire.', 'clielo' ) ], 400 );
        }

        $data = [
            'name'        => $name,
            'email'       => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
            'company'     => sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) ),
            'address'     => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
            'city'        => sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ),
            'postal_code' => sanitize_text_field( wp_unslash( $_POST['postal_code'] ?? '' ) ),
            'country'     => sanitize_text_field( wp_unslash( $_POST['country'] ?? 'France' ) ),
            'phone'       => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
            'vat_number'  => sanitize_text_field( wp_unslash( $_POST['vat_number'] ?? '' ) ),
            'siret'       => sanitize_text_field( wp_unslash( $_POST['siret'] ?? '' ) ),
            'notes'       => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
        ];

        $table = self::clients_table_name();

        if ( $id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update( $table, $data, [ 'id' => $id ] );
            wp_send_json_success( [ 'client_id' => $id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->insert( $table, $data );
            wp_send_json_success( [ 'client_id' => (int) $wpdb->insert_id ] );
        }
    }

    public static function ajax_delete_client(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        global $wpdb;
        $id = absint( $_POST['client_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'ID manquant.', 'clielo' ) ], 400 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( self::clients_table_name(), [ 'id' => $id ] );
        wp_send_json_success();
    }

    /* ================================================================
     *  AJAX — Sauvegarde réglages
     * ================================================================ */

    public static function ajax_save_settings(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        $settings = [
            'company_name'    => sanitize_text_field( wp_unslash( $_POST['company_name'] ?? '' ) ),
            'company_address' => sanitize_textarea_field( wp_unslash( $_POST['company_address'] ?? '' ) ),
            'company_city'    => sanitize_text_field( wp_unslash( $_POST['company_city'] ?? '' ) ),
            'company_postal'  => sanitize_text_field( wp_unslash( $_POST['company_postal'] ?? '' ) ),
            'company_country' => sanitize_text_field( wp_unslash( $_POST['company_country'] ?? 'France' ) ),
            'company_phone'   => sanitize_text_field( wp_unslash( $_POST['company_phone'] ?? '' ) ),
            'company_email'   => sanitize_email( wp_unslash( $_POST['company_email'] ?? '' ) ),
            'company_logo'    => esc_url_raw( wp_unslash( $_POST['company_logo'] ?? '' ) ),
            'vat_number'      => sanitize_text_field( wp_unslash( $_POST['vat_number'] ?? '' ) ),
            'siret_ifu'       => sanitize_text_field( wp_unslash( $_POST['siret_ifu'] ?? '' ) ),
            'siret_label'     => sanitize_text_field( wp_unslash( $_POST['siret_label'] ?? 'SIRET/IFU' ) ),
            'invoice_prefix'  => sanitize_text_field( wp_unslash( $_POST['invoice_prefix'] ?? 'FACT-' ) ),
            'invoice_start'   => max( 1, absint( $_POST['invoice_start'] ?? 1 ) ),
            'invoice_padding' => max( 1, min( 8, absint( $_POST['invoice_padding'] ?? 3 ) ) ),
            'quote_prefix'    => sanitize_text_field( wp_unslash( $_POST['quote_prefix'] ?? 'DEVIS-' ) ),
            'quote_start'     => max( 1, absint( $_POST['quote_start'] ?? 1 ) ),
            'quote_padding'   => max( 1, min( 8, absint( $_POST['quote_padding'] ?? 3 ) ) ),
            'tax_rate'        => floatval( $_POST['tax_rate'] ?? 20 ),
            'tax_notice'      => sanitize_textarea_field( wp_unslash( $_POST['tax_notice'] ?? '' ) ),
            'payment_terms'   => sanitize_textarea_field( wp_unslash( $_POST['payment_terms'] ?? '' ) ),
            'footer_text'     => sanitize_textarea_field( wp_unslash( $_POST['footer_text'] ?? '' ) ),
        ];

        update_option( 'clielo_invoice_settings', $settings );
        wp_send_json_success();
    }

    /* ================================================================
     *  AJAX — Vue client (frontend)
     * ================================================================ */

    public static function ajax_client_view_invoice(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Vous devez être connecté.', 'clielo' ) );
        }

        $invoice_id = absint( $_GET['invoice_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view; ownership verified below.
        if ( ! $invoice_id ) {
            wp_die( esc_html__( 'Facture introuvable.', 'clielo' ) );
        }

        $invoice = self::get_invoice( $invoice_id );
        if ( ! $invoice ) {
            wp_die( esc_html__( 'Facture introuvable.', 'clielo' ) );
        }

        // Les admins peuvent voir toutes les factures
        if ( ! current_user_can( 'manage_options' ) && (int) $invoice->client_id !== get_current_user_id() ) {
            wp_die( esc_html__( 'Accès non autorisé.', 'clielo' ) );
        }

        // Les devis (type=quote) sont toujours visibles par le client propriétaire
        $is_quote_doc = ( $invoice->invoice_type ?? '' ) === 'quote';
        if ( ! $is_quote_doc && ! in_array( $invoice->status, [ self::STATUS_VALIDATED, self::STATUS_PAID ], true ) ) {
            wp_die( esc_html__( 'Cette facture n\'est pas encore disponible.', 'clielo' ) );
        }

        $color = esc_attr( Clielo_Admin::get_color() );
        header( 'Content-Type: text/html; charset=utf-8' );
        ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php esc_html_e( 'Facture', 'clielo' ); ?></title>
<style><?php echo self::get_invoice_view_css( $color ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS built from hardcoded strings and already-escaped color value. ?></style>
</head>
<body>
<?php
        self::render_invoice_html( $invoice, false );
        ?>
<script>function clieloPrintInvoice(){window.print();}</script>
</body>
</html>
<?php
        exit;
    }

    /* ================================================================
     *  PAGE ADMIN — Réglages facturation
     * ================================================================ */

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s     = self::get_settings();
        $color = esc_attr( Clielo_Admin::get_color() );
        $nonce = wp_create_nonce( 'clielo_nonce' );
        ?>
        <div class="wrap clielo-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-media-text" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php esc_html_e( 'Clielo — Réglages facturation', 'clielo' ); ?>
            </h1>

            <div id="clielo-inv-settings-form">
                <!-- Entreprise -->
                <div class="clielo-inv-section">
                    <h2><span class="dashicons dashicons-building"></span> <?php esc_html_e( 'Informations de l\'entreprise', 'clielo' ); ?></h2>
                    <div class="clielo-inv-field">
                        <label><?php esc_html_e( 'Nom de l\'entreprise', 'clielo' ); ?></label>
                        <input type="text" id="clielo-inv-company-name" value="<?php echo esc_attr( $s['company_name'] ); ?>" />
                    </div>
                    <div class="clielo-inv-field">
                        <label><?php esc_html_e( 'Adresse', 'clielo' ); ?></label>
                        <textarea id="clielo-inv-company-address"><?php echo esc_textarea( $s['company_address'] ); ?></textarea>
                    </div>
                    <div class="clielo-inv-row">
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Code postal', 'clielo' ); ?></label>
                            <input type="text" id="clielo-inv-company-postal" value="<?php echo esc_attr( $s['company_postal'] ); ?>" />
                        </div>
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Ville', 'clielo' ); ?></label>
                            <input type="text" id="clielo-inv-company-city" value="<?php echo esc_attr( $s['company_city'] ); ?>" />
                        </div>
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Pays', 'clielo' ); ?></label>
                            <input type="text" id="clielo-inv-company-country" value="<?php echo esc_attr( $s['company_country'] ); ?>" />
                        </div>
                    </div>
                    <div class="clielo-inv-row">
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Téléphone', 'clielo' ); ?></label>
                            <input type="text" id="clielo-inv-company-phone" value="<?php echo esc_attr( $s['company_phone'] ); ?>" />
                        </div>
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Email', 'clielo' ); ?></label>
                            <input type="email" id="clielo-inv-company-email" value="<?php echo esc_attr( $s['company_email'] ); ?>" />
                        </div>
                    </div>
                </div>

                <!-- Logo -->
                <div class="clielo-inv-section">
                    <h2><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Logo', 'clielo' ); ?></h2>
                    <div class="clielo-inv-field">
                        <input type="hidden" id="clielo-inv-company-logo" value="<?php echo esc_url( $s['company_logo'] ); ?>" />
                        <button type="button" id="clielo-inv-upload-logo" class="button"><?php esc_html_e( 'Choisir un logo', 'clielo' ); ?></button>
                        <button type="button" id="clielo-inv-remove-logo" class="button" style="<?php echo empty( $s['company_logo'] ) ? 'display:none' : ''; ?>"><?php esc_html_e( 'Supprimer', 'clielo' ); ?></button>
                        <div class="clielo-inv-logo-preview">
                            <?php if ( ! empty( $s['company_logo'] ) ) : ?>
                                <img src="<?php echo esc_url( $s['company_logo'] ); ?>" />
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Identification -->
                <div class="clielo-inv-section">
                    <h2><span class="dashicons dashicons-id-alt"></span> <?php esc_html_e( 'Identification', 'clielo' ); ?></h2>
                    <div class="clielo-inv-row">
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Numéro de TVA', 'clielo' ); ?></label>
                            <input type="text" id="clielo-inv-vat" value="<?php echo esc_attr( $s['vat_number'] ); ?>" />
                        </div>
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Libellé identifiant', 'clielo' ); ?></label>
                            <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                                <select id="clielo-inv-siret-label-select" style="max-width:180px">
                                    <option value="SIRET" <?php selected( $s['siret_label'], 'SIRET' ); ?>>SIRET</option>
                                    <option value="IFU" <?php selected( $s['siret_label'], 'IFU' ); ?>>IFU</option>
                                    <option value="SIRET/IFU" <?php selected( $s['siret_label'], 'SIRET/IFU' ); ?>>SIRET/IFU</option>
                                    <option value="custom" <?php echo ! in_array( $s['siret_label'], [ 'SIRET', 'IFU', 'SIRET/IFU' ], true ) ? 'selected' : ''; ?>><?php esc_html_e( 'Personnalisé', 'clielo' ); ?></option>
                                </select>
                                <input type="text" id="clielo-inv-siret-label-custom" value="<?php echo esc_attr( $s['siret_label'] ); ?>" placeholder="<?php esc_attr_e( 'Ex: RCS, SIREN...', 'clielo' ); ?>" style="max-width:200px;<?php echo in_array( $s['siret_label'], [ 'SIRET', 'IFU', 'SIRET/IFU' ], true ) ? 'display:none' : ''; ?>" />
                            </div>
                            <input type="hidden" id="clielo-inv-siret-label" value="<?php echo esc_attr( $s['siret_label'] ); ?>" />
                            <label><?php esc_html_e( 'Numéro', 'clielo' ); ?></label>
                            <input type="text" id="clielo-inv-siret" value="<?php echo esc_attr( $s['siret_ifu'] ); ?>" />
                        </div>
                    </div>
                </div>

                <!-- Facturation -->
                <div class="clielo-inv-section">
                    <h2><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Facturation', 'clielo' ); ?></h2>
                    <div class="clielo-inv-row">
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Préfixe des factures', 'clielo' ); ?></label>
                            <input type="text" id="clielo-inv-prefix" value="<?php echo esc_attr( $s['invoice_prefix'] ); ?>" placeholder="FACT-" />
                        </div>
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Premier numéro de facture', 'clielo' ); ?></label>
                            <input type="number" id="clielo-inv-start" value="<?php echo absint( $s['invoice_start'] ?? 1 ); ?>" min="1" step="1" style="max-width:120px" />
                            <p class="description" style="margin:4px 0 0;font-size:12px;color:#777"><?php esc_html_e( 'Utile si vous migrez depuis un autre système de facturation.', 'clielo' ); ?></p>
                        </div>
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Zéros de remplissage (digits)', 'clielo' ); ?></label>
                            <input type="number" id="clielo-inv-padding" value="<?php echo absint( $s['invoice_padding'] ?? 3 ); ?>" min="1" max="8" step="1" style="max-width:80px" />
                            <p class="description" style="margin:4px 0 0;font-size:12px;color:#777"><?php esc_html_e( '3 → FACT-001, 4 → FACT-0001', 'clielo' ); ?></p>
                        </div>
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Taux de TVA (%)', 'clielo' ); ?></label>
                            <input type="number" id="clielo-inv-taxrate" value="<?php echo esc_attr( $s['tax_rate'] ); ?>" min="0" max="100" step="0.01" />
                        </div>
                    </div>
                    <div class="clielo-inv-row" style="margin-top:12px;padding-top:12px;border-top:1px solid #eee">
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Préfixe des devis', 'clielo' ); ?></label>
                            <input type="text" id="clielo-inv-quote-prefix" value="<?php echo esc_attr( $s['quote_prefix'] ?? 'DEVIS-' ); ?>" placeholder="DEVIS-" />
                        </div>
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Premier numéro de devis', 'clielo' ); ?></label>
                            <input type="number" id="clielo-inv-quote-start" value="<?php echo absint( $s['quote_start'] ?? 1 ); ?>" min="1" step="1" style="max-width:120px" />
                            <p class="description" style="margin:4px 0 0;font-size:12px;color:#777"><?php esc_html_e( 'Utile si vous migrez depuis un autre système.', 'clielo' ); ?></p>
                        </div>
                        <div class="clielo-inv-field">
                            <label><?php esc_html_e( 'Zéros de remplissage devis (digits)', 'clielo' ); ?></label>
                            <input type="number" id="clielo-inv-quote-padding" value="<?php echo absint( $s['quote_padding'] ?? 3 ); ?>" min="1" max="8" step="1" style="max-width:80px" />
                            <p class="description" style="margin:4px 0 0;font-size:12px;color:#777"><?php esc_html_e( '3 → DEVIS-001, 4 → DEVIS-0001', 'clielo' ); ?></p>
                        </div>
                    </div>
                    <div class="clielo-inv-field">
                        <label><?php esc_html_e( 'Mention TVA (affiché si taux = 0%)', 'clielo' ); ?></label>
                        <input type="text" id="clielo-inv-taxnotice" value="<?php echo esc_attr( $s['tax_notice'] ); ?>" placeholder="<?php esc_attr_e( 'TVA non applicable, article 293 B du CGI', 'clielo' ); ?>" style="width:100%" />
                    </div>
                </div>

                <!-- Textes -->
                <div class="clielo-inv-section">
                    <h2><span class="dashicons dashicons-editor-alignleft"></span> <?php esc_html_e( 'Textes', 'clielo' ); ?></h2>
                    <div class="clielo-inv-field">
                        <label><?php esc_html_e( 'Conditions de paiement', 'clielo' ); ?></label>
                        <textarea id="clielo-inv-terms"><?php echo esc_textarea( $s['payment_terms'] ); ?></textarea>
                    </div>
                    <div class="clielo-inv-field">
                        <label><?php esc_html_e( 'Pied de page facture', 'clielo' ); ?></label>
                        <textarea id="clielo-inv-footer"><?php echo esc_textarea( $s['footer_text'] ); ?></textarea>
                    </div>
                </div>

                <button type="button" id="clielo-inv-save-settings" class="button button-primary" style="background:<?php echo esc_attr( $color ); ?>;border-color:<?php echo esc_attr( $color ); ?>;padding:8px 24px;font-size:14px">
                    <?php esc_html_e( 'Enregistrer', 'clielo' ); ?>
                </button>
            </div>

            <?php
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';

                // Logo uploader
                var uploadBtn = document.getElementById('clielo-inv-upload-logo');
                var removeBtn = document.getElementById('clielo-inv-remove-logo');
                var logoInput = document.getElementById('clielo-inv-company-logo');
                var preview   = document.querySelector('.clielo-inv-logo-preview');

                if(uploadBtn){
                    uploadBtn.addEventListener('click', function(){
                        var frame = wp.media({ title: '<?php echo esc_js( __( 'Choisir un logo', 'clielo' ) ); ?>', multiple: false });
                        frame.on('select', function(){
                            var attachment = frame.state().get('selection').first().toJSON();
                            logoInput.value = attachment.url;
                            preview.innerHTML = '<img src="'+attachment.url+'" />';
                            removeBtn.style.display = '';
                        });
                        frame.open();
                    });
                }
                if(removeBtn){
                    removeBtn.addEventListener('click', function(){
                        logoInput.value = '';
                        preview.innerHTML = '';
                        removeBtn.style.display = 'none';
                    });
                }

                // Libellé SIRET/IFU select ↔ custom
                var siretSelect = document.getElementById('clielo-inv-siret-label-select');
                var siretCustom = document.getElementById('clielo-inv-siret-label-custom');
                var siretHidden = document.getElementById('clielo-inv-siret-label');
                function syncSiretLabel(){
                    if(siretSelect.value === 'custom'){
                        siretCustom.style.display = '';
                        siretHidden.value = siretCustom.value;
                    } else {
                        siretCustom.style.display = 'none';
                        siretHidden.value = siretSelect.value;
                    }
                }
                siretSelect.addEventListener('change', syncSiretLabel);
                siretCustom.addEventListener('input', function(){ siretHidden.value = this.value; });

                // Save
                document.getElementById('clielo-inv-save-settings').addEventListener('click', function(){
                    var btn = this;
                    btn.disabled = true;
                    btn.textContent = '<?php echo esc_js( __( 'Enregistrement...', 'clielo' ) ); ?>';

                    var fd = new FormData();
                    fd.append('action', 'clielo_save_invoice_settings');
                    fd.append('nonce', nonce);
                    fd.append('company_name', document.getElementById('clielo-inv-company-name').value);
                    fd.append('company_address', document.getElementById('clielo-inv-company-address').value);
                    fd.append('company_postal', document.getElementById('clielo-inv-company-postal').value);
                    fd.append('company_city', document.getElementById('clielo-inv-company-city').value);
                    fd.append('company_country', document.getElementById('clielo-inv-company-country').value);
                    fd.append('company_phone', document.getElementById('clielo-inv-company-phone').value);
                    fd.append('company_email', document.getElementById('clielo-inv-company-email').value);
                    fd.append('company_logo', document.getElementById('clielo-inv-company-logo').value);
                    fd.append('vat_number', document.getElementById('clielo-inv-vat').value);
                    fd.append('siret_ifu', document.getElementById('clielo-inv-siret').value);
                    fd.append('siret_label', document.getElementById('clielo-inv-siret-label').value);
                    fd.append('invoice_prefix', document.getElementById('clielo-inv-prefix').value);
                    fd.append('invoice_start', document.getElementById('clielo-inv-start').value);
                    fd.append('invoice_padding', document.getElementById('clielo-inv-padding').value);
                    fd.append('quote_prefix', document.getElementById('clielo-inv-quote-prefix').value);
                    fd.append('quote_start', document.getElementById('clielo-inv-quote-start').value);
                    fd.append('quote_padding', document.getElementById('clielo-inv-quote-padding').value);
                    fd.append('tax_rate', document.getElementById('clielo-inv-taxrate').value);
                    fd.append('tax_notice', document.getElementById('clielo-inv-taxnotice').value);
                    fd.append('payment_terms', document.getElementById('clielo-inv-terms').value);
                    fd.append('footer_text', document.getElementById('clielo-inv-footer').value);

                    fetch(ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js( __( 'Enregistrer', 'clielo' ) ); ?>';
                        if(res.success){
                            btn.textContent = '<?php echo esc_js( __( 'Enregistré !', 'clielo' ) ); ?>';
                            setTimeout(function(){ btn.textContent = '<?php echo esc_js( __( 'Enregistrer', 'clielo' ) ); ?>'; }, 2000);
                        } else {
                            alert(res.data && res.data.message ? res.data.message : 'Erreur');
                        }
                    });
                });
            })();
            <?php
            wp_add_inline_script( 'clielo-invoices-js', ob_get_clean() );
            ?>
        </div>
        <?php
    }

    /* ================================================================
     *  PAGE ADMIN — Clients externes
     * ================================================================ */

    public static function render_clients_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $clients = self::get_all_ext_clients();
        $color   = esc_attr( Clielo_Admin::get_color() );
        $nonce   = wp_create_nonce( 'clielo_nonce' );
        ?>
        <style>
            .clielo-clients-wrap{display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start}
            .clielo-clients-list{flex:1;min-width:400px}
            .clielo-clients-form{flex:0 0 380px}
            .clielo-clients-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden}
            .clielo-clients-table th{background:#f9f9f9;text-align:left;padding:10px 12px;font-size:12px;font-weight:600;color:#555;border-bottom:1px solid #e0e0e0}
            .clielo-clients-table td{padding:10px 12px;font-size:13px;color:#333;border-bottom:1px solid #f5f5f5}
            .clielo-clients-table tr:last-child td{border-bottom:none}
            .clielo-cl-form-card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)}
            .clielo-cl-form-card h3{margin:0 0 16px;font-size:15px;font-weight:700;color:#222}
            .clielo-cl-field{margin-bottom:12px}
            .clielo-cl-field label{display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:3px}
            .clielo-cl-field input,.clielo-cl-field textarea{width:100%;box-sizing:border-box}
            .clielo-cl-field textarea{height:60px}
            .clielo-cl-row{display:flex;gap:10px}
            .clielo-cl-row .clielo-cl-field{flex:1}
            .clielo-cl-actions a{cursor:pointer;font-size:12px;margin-right:8px;text-decoration:none}
            .clielo-cl-actions .edit{color:#0073aa}
            .clielo-cl-actions .delete{color:#dc3545}
        </style>
        <div class="wrap clielo-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-groups" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php esc_html_e( 'Clielo — Clients externes', 'clielo' ); ?>
            </h1>

            <div class="clielo-clients-wrap">
                <div class="clielo-clients-list">
                    <table class="clielo-clients-table" id="clielo-cl-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Nom', 'clielo' ); ?></th>
                                <th><?php esc_html_e( 'Email', 'clielo' ); ?></th>
                                <th><?php esc_html_e( 'Société', 'clielo' ); ?></th>
                                <th><?php esc_html_e( 'Ville', 'clielo' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'clielo' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $clients ) ) : ?>
                                <tr><td colspan="5" style="text-align:center;color:#888;padding:24px"><?php esc_html_e( 'Aucun client externe.', 'clielo' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $clients as $cl ) : ?>
                                    <tr data-id="<?php echo (int) $cl->id; ?>"
                                        data-name="<?php echo esc_attr( $cl->name ); ?>"
                                        data-email="<?php echo esc_attr( $cl->email ); ?>"
                                        data-company="<?php echo esc_attr( $cl->company ); ?>"
                                        data-address="<?php echo esc_attr( $cl->address ); ?>"
                                        data-city="<?php echo esc_attr( $cl->city ); ?>"
                                        data-postal="<?php echo esc_attr( $cl->postal_code ); ?>"
                                        data-country="<?php echo esc_attr( $cl->country ); ?>"
                                        data-phone="<?php echo esc_attr( $cl->phone ); ?>"
                                        data-vat="<?php echo esc_attr( $cl->vat_number ); ?>"
                                        data-siret="<?php echo esc_attr( $cl->siret ); ?>"
                                        data-notes="<?php echo esc_attr( $cl->notes ); ?>">
                                        <td><?php echo esc_html( $cl->name ); ?></td>
                                        <td><?php echo esc_html( $cl->email ); ?></td>
                                        <td><?php echo esc_html( $cl->company ); ?></td>
                                        <td><?php echo esc_html( $cl->city ); ?></td>
                                        <td class="clielo-cl-actions">
                                            <a class="edit" data-id="<?php echo (int) $cl->id; ?>"><?php esc_html_e( 'Modifier', 'clielo' ); ?></a>
                                            <a class="delete" data-id="<?php echo (int) $cl->id; ?>"><?php esc_html_e( 'Supprimer', 'clielo' ); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="clielo-clients-form">
                    <div class="clielo-cl-form-card">
                        <h3 id="clielo-cl-form-title"><?php esc_html_e( 'Ajouter un client', 'clielo' ); ?></h3>
                        <input type="hidden" id="clielo-cl-id" value="0" />
                        <div class="clielo-cl-field">
                            <label><?php esc_html_e( 'Nom *', 'clielo' ); ?></label>
                            <input type="text" id="clielo-cl-name" />
                        </div>
                        <div class="clielo-cl-field">
                            <label><?php esc_html_e( 'Email', 'clielo' ); ?></label>
                            <input type="email" id="clielo-cl-email" />
                        </div>
                        <div class="clielo-cl-field">
                            <label><?php esc_html_e( 'Société', 'clielo' ); ?></label>
                            <input type="text" id="clielo-cl-company" />
                        </div>
                        <div class="clielo-cl-field">
                            <label><?php esc_html_e( 'Adresse', 'clielo' ); ?></label>
                            <textarea id="clielo-cl-address"></textarea>
                        </div>
                        <div class="clielo-cl-row">
                            <div class="clielo-cl-field">
                                <label><?php esc_html_e( 'Code postal', 'clielo' ); ?></label>
                                <input type="text" id="clielo-cl-postal" />
                            </div>
                            <div class="clielo-cl-field">
                                <label><?php esc_html_e( 'Ville', 'clielo' ); ?></label>
                                <input type="text" id="clielo-cl-city" />
                            </div>
                        </div>
                        <div class="clielo-cl-row">
                            <div class="clielo-cl-field">
                                <label><?php esc_html_e( 'Pays', 'clielo' ); ?></label>
                                <input type="text" id="clielo-cl-country" value="France" />
                            </div>
                            <div class="clielo-cl-field">
                                <label><?php esc_html_e( 'Téléphone', 'clielo' ); ?></label>
                                <input type="text" id="clielo-cl-phone" />
                            </div>
                        </div>
                        <div class="clielo-cl-row">
                            <div class="clielo-cl-field">
                                <label><?php esc_html_e( 'N° TVA', 'clielo' ); ?></label>
                                <input type="text" id="clielo-cl-vat" />
                            </div>
                            <div class="clielo-cl-field">
                                <label><?php esc_html_e( 'SIRET / IFU', 'clielo' ); ?></label>
                                <input type="text" id="clielo-cl-siret" />
                            </div>
                        </div>
                        <div class="clielo-cl-field">
                            <label><?php esc_html_e( 'Notes', 'clielo' ); ?></label>
                            <textarea id="clielo-cl-notes"></textarea>
                        </div>
                        <button type="button" id="clielo-cl-save" class="button button-primary" style="background:<?php echo esc_attr( $color ); ?>;border-color:<?php echo esc_attr( $color ); ?>;margin-right:8px">
                            <?php esc_html_e( 'Enregistrer', 'clielo' ); ?>
                        </button>
                        <button type="button" id="clielo-cl-reset" class="button"><?php esc_html_e( 'Annuler', 'clielo' ); ?></button>
                    </div>
                </div>
            </div>

            <?php
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';

                function resetForm(){
                    document.getElementById('clielo-cl-id').value = '0';
                    document.getElementById('clielo-cl-form-title').textContent = '<?php echo esc_js( __( 'Ajouter un client', 'clielo' ) ); ?>';
                    ['name','email','company','address','postal','city','phone','vat','siret','notes'].forEach(function(f){
                        document.getElementById('clielo-cl-'+f).value = '';
                    });
                    document.getElementById('clielo-cl-country').value = 'France';
                }

                document.getElementById('clielo-cl-reset').addEventListener('click', resetForm);

                // Edit
                document.querySelectorAll('.clielo-cl-actions .edit').forEach(function(a){
                    a.addEventListener('click', function(){
                        var tr = this.closest('tr');
                        document.getElementById('clielo-cl-id').value = tr.dataset.id;
                        document.getElementById('clielo-cl-name').value = tr.dataset.name;
                        document.getElementById('clielo-cl-email').value = tr.dataset.email;
                        document.getElementById('clielo-cl-company').value = tr.dataset.company;
                        document.getElementById('clielo-cl-address').value = tr.dataset.address;
                        document.getElementById('clielo-cl-city').value = tr.dataset.city;
                        document.getElementById('clielo-cl-postal').value = tr.dataset.postal;
                        document.getElementById('clielo-cl-country').value = tr.dataset.country;
                        document.getElementById('clielo-cl-phone').value = tr.dataset.phone;
                        document.getElementById('clielo-cl-vat').value = tr.dataset.vat;
                        document.getElementById('clielo-cl-siret').value = tr.dataset.siret;
                        document.getElementById('clielo-cl-notes').value = tr.dataset.notes;
                        document.getElementById('clielo-cl-form-title').textContent = '<?php echo esc_js( __( 'Modifier le client', 'clielo' ) ); ?>';
                        document.querySelector('.clielo-clients-form').scrollIntoView({behavior:'smooth'});
                    });
                });

                // Delete
                document.querySelectorAll('.clielo-cl-actions .delete').forEach(function(a){
                    a.addEventListener('click', function(){
                        if(!confirm('<?php echo esc_js( __( 'Supprimer ce client ?', 'clielo' ) ); ?>')) return;
                        var id = this.dataset.id;
                        var fd = new FormData();
                        fd.append('action','clielo_delete_ext_client');
                        fd.append('nonce',nonce);
                        fd.append('client_id',id);
                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){ if(res.success) location.reload(); else alert(res.data.message||'Erreur'); });
                    });
                });

                // Save
                document.getElementById('clielo-cl-save').addEventListener('click', function(){
                    var btn = this;
                    btn.disabled = true;
                    var fd = new FormData();
                    fd.append('action','clielo_save_ext_client');
                    fd.append('nonce',nonce);
                    fd.append('client_id', document.getElementById('clielo-cl-id').value);
                    fd.append('name', document.getElementById('clielo-cl-name').value);
                    fd.append('email', document.getElementById('clielo-cl-email').value);
                    fd.append('company', document.getElementById('clielo-cl-company').value);
                    fd.append('address', document.getElementById('clielo-cl-address').value);
                    fd.append('city', document.getElementById('clielo-cl-city').value);
                    fd.append('postal_code', document.getElementById('clielo-cl-postal').value);
                    fd.append('country', document.getElementById('clielo-cl-country').value);
                    fd.append('phone', document.getElementById('clielo-cl-phone').value);
                    fd.append('vat_number', document.getElementById('clielo-cl-vat').value);
                    fd.append('siret', document.getElementById('clielo-cl-siret').value);
                    fd.append('notes', document.getElementById('clielo-cl-notes').value);
                    fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){ btn.disabled=false; if(res.success) location.reload(); else alert(res.data.message||'Erreur'); });
                });
            })();
            <?php
            wp_add_inline_script( 'clielo-invoices-js', ob_get_clean() );
            ?>
        </div>
        <?php
    }

    /* ================================================================
     *  PAGE ADMIN — Liste des factures
     * ================================================================ */

    public static function render_invoices_list(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $filter   = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin-only list filter, read-only.
        $invoices = self::get_invoices( $filter );
        $counts   = self::get_status_counts();
        $labels   = self::get_status_labels();
        $colors   = self::get_status_colors();
        $color    = esc_attr( Clielo_Admin::get_color() );
        $nonce    = wp_create_nonce( 'clielo_nonce' );
        $page_url = admin_url( 'admin.php?page=clielo-invoices' );
        $curr_data = Clielo_Admin::get_currency_data();
        $curr_sym  = $curr_data['symbol'];
        $curr_dec  = $curr_data['decimals'];
        ?>
        <div class="wrap clielo-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-media-text" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php esc_html_e( 'Clielo — Factures', 'clielo' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=clielo-invoice-new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Nouvelle facture', 'clielo' ); ?></a>
            </h1>

            <!-- Filtres -->
            <div class="clielo-inv-filters">
                <a href="<?php echo esc_url( $page_url ); ?>" class="clielo-inv-filter <?php echo empty( $filter ) ? 'active' : ''; ?>">
                    <?php esc_html_e( 'Toutes', 'clielo' ); ?> <span class="count">(<?php echo absint( $counts['all'] ); ?>)</span>
                </a>
                <?php foreach ( $labels as $key => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'status', $key, $page_url ) ); ?>" class="clielo-inv-filter <?php echo $filter === $key ? 'active' : ''; ?>">
                        <?php echo esc_html( $label ); ?> <span class="count">(<?php echo absint( $counts[ $key ] ?? 0 ); ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Tableau -->
            <table class="clielo-inv-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'N° Facture', 'clielo' ); ?></th>
                        <th><?php esc_html_e( 'Client', 'clielo' ); ?></th>
                        <th><?php esc_html_e( 'Commande', 'clielo' ); ?></th>
                        <th><?php esc_html_e( 'Statut', 'clielo' ); ?></th>
                        <th><?php esc_html_e( 'Total TTC', 'clielo' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'clielo' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'clielo' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $invoices ) ) : ?>
                        <tr><td colspan="7" style="text-align:center;color:#888;padding:24px"><?php esc_html_e( 'Aucune facture.', 'clielo' ); ?></td></tr>
                    <?php else : ?>
                        <?php
                        $transitions_map = [
                            self::STATUS_DRAFT     => [ self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_CANCELLED ],
                            self::STATUS_PENDING   => [ self::STATUS_VALIDATED, self::STATUS_CANCELLED ],
                            self::STATUS_VALIDATED => [ self::STATUS_PAID, self::STATUS_CANCELLED ],
                            self::STATUS_PAID      => [],
                            self::STATUS_CANCELLED => [],
                        ];
                        ?>
                        <?php foreach ( $invoices as $inv ) :
                            $client_info  = self::get_client_info( $inv->client_id ? (int) $inv->client_id : null, $inv->ext_client_id ? (int) $inv->ext_client_id : null );
                            $client_name  = $client_info ? $client_info->name : '—';
                            $badge_color  = $colors[ $inv->status ] ?? '#9ca3af';
                            $status_text  = $labels[ $inv->status ] ?? $inv->status;
                            $view_url     = admin_url( 'admin.php?page=clielo-invoice-view&invoice_id=' . $inv->id );
                            $allowed_next = $transitions_map[ $inv->status ] ?? [];
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $inv->invoice_number ); ?></strong></td>
                            <td><?php echo esc_html( $client_name ); ?></td>
                            <td><?php echo $inv->order_id ? '#CMD-' . esc_html( $inv->order_id ) : '—'; ?></td>
                            <td>
                                <div class="clielo-inv-status-wrap">
                                    <span class="clielo-inv-badge <?php echo ! empty( $allowed_next ) ? 'clickable' : ''; ?>" style="background:<?php echo esc_attr( $badge_color ); ?>" <?php if ( ! empty( $allowed_next ) ) : ?>data-toggle-dd="dd-<?php echo (int) $inv->id; ?>"<?php endif; ?>><?php echo esc_html( $status_text ); ?></span>
                                    <?php if ( ! empty( $allowed_next ) ) : ?>
                                    <div class="clielo-inv-status-dd" id="dd-<?php echo (int) $inv->id; ?>">
                                        <?php foreach ( $allowed_next as $ns ) : ?>
                                            <a href="#" class="clielo-inv-set-status" data-id="<?php echo (int) $inv->id; ?>" data-status="<?php echo esc_attr( $ns ); ?>">
                                                <span class="clielo-inv-status-dot" style="background:<?php echo esc_attr( $colors[ $ns ] ?? '#999' ); ?>"></span>
                                                <?php echo esc_html( $labels[ $ns ] ?? $ns ); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><strong><?php echo esc_html( number_format( (float) $inv->total, 2, ',', ' ' ) ); ?> <?php echo esc_html( $curr_sym ); ?></strong></td>
                            <td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $inv->created_at ) ) ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $view_url ); ?>" class="clielo-inv-act" style="color:<?php echo esc_attr( $color ); ?>"><?php esc_html_e( 'Voir', 'clielo' ); ?></a>
                                <?php if ( $inv->status === self::STATUS_DRAFT ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=clielo-invoice-edit&invoice_id=' . $inv->id ) ); ?>" class="clielo-inv-act" style="color:#f59e0b"><?php esc_html_e( 'Modifier', 'clielo' ); ?></a>
                                <?php endif; ?>
                                <?php if ( in_array( $inv->status, [ self::STATUS_DRAFT, self::STATUS_PENDING ], true ) ) : ?>
                                    <a class="clielo-inv-act clielo-inv-action" data-action="clielo_invoice_validate" data-id="<?php echo (int) $inv->id; ?>" style="color:#10b981"><?php esc_html_e( 'Valider', 'clielo' ); ?></a>
                                <?php endif; ?>
                                <?php if ( $inv->status === self::STATUS_VALIDATED ) : ?>
                                    <a class="clielo-inv-act clielo-inv-action" data-action="clielo_invoice_mark_paid" data-id="<?php echo (int) $inv->id; ?>" style="color:#10b981"><?php esc_html_e( 'Payer', 'clielo' ); ?></a>
                                <?php endif; ?>
                                <?php if ( ! in_array( $inv->status, [ self::STATUS_PAID, self::STATUS_CANCELLED ], true ) ) : ?>
                                    <a class="clielo-inv-act clielo-inv-action" data-action="clielo_invoice_cancel" data-id="<?php echo (int) $inv->id; ?>" style="color:#ef4444"><?php esc_html_e( 'Annuler', 'clielo' ); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';

                // Actions classiques (Valider, Payer, Annuler)
                document.querySelectorAll('.clielo-inv-action').forEach(function(a){
                    a.addEventListener('click', function(e){
                        e.preventDefault();
                        var action = this.dataset.action;
                        var id     = this.dataset.id;
                        var label  = action === 'clielo_invoice_cancel' ? '<?php echo esc_js( __( 'Annuler cette facture ?', 'clielo' ) ); ?>' : '<?php echo esc_js( __( 'Confirmer cette action ?', 'clielo' ) ); ?>';
                        if(!confirm(label)) return;

                        var fd = new FormData();
                        fd.append('action', action);
                        fd.append('nonce', nonce);
                        fd.append('invoice_id', id);
                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){ if(res.success) location.reload(); else alert(res.data.message||'Erreur'); });
                    });
                });

                // Toggle dropdown statut
                document.querySelectorAll('[data-toggle-dd]').forEach(function(badge){
                    badge.addEventListener('click', function(e){
                        e.stopPropagation();
                        var ddId = this.dataset.toggleDd;
                        var dd = document.getElementById(ddId);
                        // Fermer les autres
                        document.querySelectorAll('.clielo-inv-status-dd.open').forEach(function(d){
                            if(d.id !== ddId) d.classList.remove('open');
                        });
                        dd.classList.toggle('open');
                    });
                });

                // Fermer dropdown si clic ailleurs
                document.addEventListener('click', function(){
                    document.querySelectorAll('.clielo-inv-status-dd.open').forEach(function(d){
                        d.classList.remove('open');
                    });
                });

                // Changement de statut via dropdown
                document.querySelectorAll('.clielo-inv-set-status').forEach(function(a){
                    a.addEventListener('click', function(e){
                        e.preventDefault();
                        e.stopPropagation();
                        var id = this.dataset.id;
                        var newStatus = this.dataset.status;
                        if(!confirm('<?php echo esc_js( __( 'Changer le statut de cette facture ?', 'clielo' ) ); ?>')) return;

                        var fd = new FormData();
                        fd.append('action', 'clielo_invoice_set_status');
                        fd.append('nonce', nonce);
                        fd.append('invoice_id', id);
                        fd.append('new_status', newStatus);
                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){ if(res.success) location.reload(); else alert(res.data&&res.data.message?res.data.message:'Erreur'); });
                    });
                });
            })();
            <?php
            wp_add_inline_script( 'clielo-invoices-js', ob_get_clean() );
            ?>
        </div>
        <?php
    }

    /* ================================================================
     *  PAGE ADMIN — Nouvelle facture
     * ================================================================ */

    public static function render_invoice_new(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings    = self::get_settings();
        $color       = esc_attr( Clielo_Admin::get_color() );
        $nonce       = wp_create_nonce( 'clielo_nonce' );
        $ext_clients = self::get_all_ext_clients();
        $curr_data   = Clielo_Admin::get_currency_data();
        $curr_sym    = $curr_data['symbol'];
        $curr_dec    = $curr_data['decimals'];

        // Utilisateurs WP non-admin
        $wp_users = get_users( [ 'role__not_in' => [ 'administrator' ], 'orderby' => 'display_name', 'number' => 200 ] );
        ?>
        <div class="wrap clielo-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-plus-alt" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php esc_html_e( 'Clielo — Nouvelle facture', 'clielo' ); ?>
            </h1>

            <div id="clielo-newinv-form">
                <!-- Client -->
                <div class="clielo-newinv-section">
                    <h2><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e( 'Client', 'clielo' ); ?></h2>
                    <?php if ( clielo_is_premium() ) : ?>
                    <div class="clielo-newinv-radio">
                        <label><input type="radio" name="clielo_client_type" value="wp" checked /> <?php esc_html_e( 'Utilisateur WordPress', 'clielo' ); ?></label>
                        <label><input type="radio" name="clielo_client_type" value="ext" /> <?php esc_html_e( 'Client externe', 'clielo' ); ?></label>
                    </div>
                    <div class="clielo-newinv-field" id="clielo-newinv-wp-client">
                        <label><?php esc_html_e( 'Sélectionner un utilisateur', 'clielo' ); ?></label>
                        <select id="clielo-newinv-client-id">
                            <option value=""><?php esc_html_e( '— Choisir —', 'clielo' ); ?></option>
                            <?php foreach ( $wp_users as $u ) : ?>
                                <option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <input type="hidden" name="clielo_client_type" value="<?php echo clielo_is_premium() ? '' : 'ext'; ?>" id="clielo-newinv-client-type-hidden" <?php echo clielo_is_premium() ? 'style="display:none"' : ''; ?> />
                    <div class="clielo-newinv-field" id="clielo-newinv-ext-client"<?php echo clielo_is_premium() ? ' style="display:none"' : ''; ?>>
                        <label><?php esc_html_e( 'Sélectionner un client externe', 'clielo' ); ?></label>
                        <select id="clielo-newinv-ext-id">
                            <option value=""><?php esc_html_e( '— Choisir —', 'clielo' ); ?></option>
                            <?php foreach ( $ext_clients as $ec ) : ?>
                                <option value="<?php echo (int) $ec->id; ?>"><?php echo esc_html( $ec->name . ( $ec->company ? ' — ' . $ec->company : '' ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=clielo-clients' ) ); ?>" style="font-size:12px"><?php esc_html_e( 'Gérer les clients externes', 'clielo' ); ?></a>
                    </div>
                </div>

                <!-- Articles -->
                <div class="clielo-newinv-section">
                    <h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Articles', 'clielo' ); ?></h2>
                    <table class="clielo-items-table" id="clielo-items-table">
                        <thead>
                            <tr>
                                <th style="width:50%"><?php esc_html_e( 'Description', 'clielo' ); ?></th>
                                <th style="width:10%"><?php esc_html_e( 'Qté', 'clielo' ); ?></th>
                                <th style="width:20%"><?php esc_html_e( 'Prix unitaire HT', 'clielo' ); ?></th>
                                <th style="width:15%"><?php esc_html_e( 'Total HT', 'clielo' ); ?></th>
                                <th style="width:5%"></th>
                            </tr>
                        </thead>
                        <tbody id="clielo-items-body">
                            <tr class="clielo-item-row">
                                <td><input type="text" class="clielo-item-desc" placeholder="<?php esc_attr_e( 'Description du service', 'clielo' ); ?>" /></td>
                                <td><input type="number" class="clielo-item-qty" value="1" min="1" step="1" /></td>
                                <td><input type="number" class="clielo-item-price" value="0" min="0" step="0.01" /></td>
                                <td class="clielo-item-total" style="text-align:right;font-weight:600">0,00 <?php echo esc_html( $curr_sym ); ?></td>
                                <td><button type="button" class="clielo-items-rm">&times;</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" id="clielo-add-item" class="button">+ <?php esc_html_e( 'Ajouter un article', 'clielo' ); ?></button>

                    <div class="clielo-newinv-totals" style="margin-top:16px">
                        <div><?php esc_html_e( 'Sous-total HT', 'clielo' ); ?> : <span id="clielo-newinv-subtotal">0,00</span> <?php echo esc_html( $curr_sym ); ?></div>
                        <div><?php esc_html_e( 'TVA', 'clielo' ); ?> (<span id="clielo-newinv-taxrate-display"><?php echo esc_html( $settings['tax_rate'] ); ?></span>%) : <span id="clielo-newinv-tax">0,00</span> <?php echo esc_html( $curr_sym ); ?></div>
                        <div><strong><?php esc_html_e( 'Total TTC', 'clielo' ); ?> : <span id="clielo-newinv-total">0,00</span> <?php echo esc_html( $curr_sym ); ?></strong></div>
                    </div>
                </div>

                <!-- TVA et notes -->
                <div class="clielo-newinv-section">
                    <h2><span class="dashicons dashicons-editor-alignleft"></span> <?php esc_html_e( 'Détails', 'clielo' ); ?></h2>
                    <div class="clielo-newinv-field" style="max-width:200px">
                        <label><?php esc_html_e( 'Taux TVA (%)', 'clielo' ); ?></label>
                        <input type="number" id="clielo-newinv-taxrate" value="<?php echo esc_attr( $settings['tax_rate'] ); ?>" min="0" max="100" step="0.01" />
                    </div>
                    <div class="clielo-newinv-field" style="max-width:280px">
                        <label><?php esc_html_e( 'Type de facture', 'clielo' ); ?></label>
                        <select id="clielo-newinv-type">
                            <option value="single"><?php esc_html_e( 'Facture simple', 'clielo' ); ?></option>
                            <option value="acompte"><?php esc_html_e( "Facture d'acompte", 'clielo' ); ?></option>
                            <option value="solde"><?php esc_html_e( 'Facture de solde', 'clielo' ); ?></option>
                        </select>
                    </div>
                    <div class="clielo-newinv-field">
                        <label><?php esc_html_e( 'Notes / Conditions', 'clielo' ); ?></label>
                        <textarea id="clielo-newinv-notes"><?php echo esc_textarea( $settings['payment_terms'] ); ?></textarea>
                    </div>
                </div>

                <!-- Actions -->
                <button type="button" class="button clielo-newinv-save" data-status="draft" style="margin-right:8px"><?php esc_html_e( 'Enregistrer en brouillon', 'clielo' ); ?></button>
                <button type="button" class="button button-primary clielo-newinv-save" data-status="validated" style="background:<?php echo esc_attr( $color ); ?>;border-color:<?php echo esc_attr( $color ); ?>"><?php esc_html_e( 'Enregistrer et valider', 'clielo' ); ?></button>
            </div>

            <?php
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';

                // Toggle client type
                document.querySelectorAll('input[name="clielo_client_type"]').forEach(function(r){
                    r.addEventListener('change', function(){
                        document.getElementById('clielo-newinv-wp-client').style.display = this.value==='wp' ? '' : 'none';
                        document.getElementById('clielo-newinv-ext-client').style.display = this.value==='ext' ? '' : 'none';
                    });
                });

                // Calcul totaux
                function recalc(){
                    var subtotal = 0;
                    document.querySelectorAll('.clielo-item-row').forEach(function(row){
                        var qty   = parseFloat(row.querySelector('.clielo-item-qty').value) || 0;
                        var price = parseFloat(row.querySelector('.clielo-item-price').value) || 0;
                        var lt    = Math.round(qty * price * 100) / 100;
                        row.querySelector('.clielo-item-total').textContent = lt.toFixed(2).replace('.',',') + ' \u20ac';
                        subtotal += lt;
                    });
                    var rate = parseFloat(document.getElementById('clielo-newinv-taxrate').value) || 0;
                    var tax  = Math.round(subtotal * rate) / 100;
                    var total = subtotal + tax;
                    document.getElementById('clielo-newinv-subtotal').textContent = subtotal.toFixed(2).replace('.',',');
                    document.getElementById('clielo-newinv-tax').textContent = tax.toFixed(2).replace('.',',');
                    document.getElementById('clielo-newinv-total').textContent = total.toFixed(2).replace('.',',');
                    document.getElementById('clielo-newinv-taxrate-display').textContent = rate;
                }

                document.addEventListener('input', function(e){
                    if(e.target.classList.contains('clielo-item-qty') || e.target.classList.contains('clielo-item-price') || e.target.id === 'clielo-newinv-taxrate') recalc();
                });

                // Ajouter article
                document.getElementById('clielo-add-item').addEventListener('click', function(){
                    var row = document.createElement('tr');
                    row.className = 'clielo-item-row';
                    row.innerHTML = '<td><input type="text" class="clielo-item-desc" placeholder="<?php echo esc_js( __( 'Description du service', 'clielo' ) ); ?>" /></td>' +
                        '<td><input type="number" class="clielo-item-qty" value="1" min="1" step="1" /></td>' +
                        '<td><input type="number" class="clielo-item-price" value="0" min="0" step="0.01" /></td>' +
                        '<td class="clielo-item-total" style="text-align:right;font-weight:600">0,00 \u20ac</td>' +
                        '<td><button type="button" class="clielo-items-rm">&times;</button></td>';
                    document.getElementById('clielo-items-body').appendChild(row);
                });

                // Supprimer article
                document.addEventListener('click', function(e){
                    if(e.target.classList.contains('clielo-items-rm')){
                        var rows = document.querySelectorAll('.clielo-item-row');
                        if(rows.length > 1){ e.target.closest('tr').remove(); recalc(); }
                    }
                });

                // Save
                document.querySelectorAll('.clielo-newinv-save').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var status = this.dataset.status;
                        this.disabled = true;

                        var ctRadio = document.querySelector('input[name="clielo_client_type"]:checked');
                        var clientType = ctRadio ? ctRadio.value : 'ext';
                        var fd = new FormData();
                        fd.append('action', 'clielo_invoice_save');
                        fd.append('nonce', nonce);
                        fd.append('client_type', clientType);
                        fd.append('client_id', document.getElementById('clielo-newinv-client-id') ? document.getElementById('clielo-newinv-client-id').value : '');
                        fd.append('ext_client_id', document.getElementById('clielo-newinv-ext-id').value);
                        fd.append('tax_rate', document.getElementById('clielo-newinv-taxrate').value);
                        fd.append('notes', document.getElementById('clielo-newinv-notes').value);
                        fd.append('invoice_type', document.getElementById('clielo-newinv-type').value);
                        fd.append('save_status', status);

                        var rows = document.querySelectorAll('.clielo-item-row');
                        rows.forEach(function(row, i){
                            fd.append('items['+i+'][description]', row.querySelector('.clielo-item-desc').value);
                            fd.append('items['+i+'][quantity]', row.querySelector('.clielo-item-qty').value);
                            fd.append('items['+i+'][unit_price]', row.querySelector('.clielo-item-price').value);
                        });

                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){
                            btn.disabled = false;
                            if(res.success){
                                window.location.href = '<?php echo esc_url( admin_url( 'admin.php?page=clielo-invoices' ) ); ?>';
                            } else {
                                alert(res.data && res.data.message ? res.data.message : 'Erreur');
                            }
                        });
                    });
                });
            })();
            <?php
            wp_add_inline_script( 'clielo-invoices-js', ob_get_clean() );
            ?>
        </div>
        <?php
    }

    /* ================================================================
     *  PAGE ADMIN — Modifier facture (brouillon uniquement)
     * ================================================================ */

    public static function render_invoice_edit(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $invoice_id = absint( $_GET['invoice_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin-only render, capability checked above.
        $invoice    = $invoice_id ? self::get_invoice( $invoice_id ) : null;

        if ( ! $invoice || $invoice->status !== self::STATUS_DRAFT ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Seuls les brouillons peuvent être modifiés.', 'clielo' ) . '</p></div>';
            return;
        }

        $settings    = self::get_settings();
        $color       = esc_attr( Clielo_Admin::get_color() );
        $nonce       = wp_create_nonce( 'clielo_nonce' );
        $ext_clients = self::get_all_ext_clients();
        $curr_data   = Clielo_Admin::get_currency_data();
        $curr_sym    = $curr_data['symbol'];
        $curr_dec    = $curr_data['decimals'];
        $wp_users    = get_users( [ 'role__not_in' => [ 'administrator' ], 'orderby' => 'display_name', 'number' => 200 ] );
        $items       = json_decode( $invoice->items, true ) ?: [];
        $is_wp       = ! empty( $invoice->client_id );
        ?>
        <div class="wrap clielo-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-edit" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php
                /* translators: %s: invoice number */
                printf( esc_html__( 'Modifier — %s', 'clielo' ), esc_html( $invoice->invoice_number ) ); ?>
            </h1>

            <div id="clielo-newinv-form">
                <!-- Client -->
                <div class="clielo-newinv-section">
                    <h2><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e( 'Client', 'clielo' ); ?></h2>
                    <div class="clielo-newinv-radio">
                        <label><input type="radio" name="clielo_client_type" value="wp" <?php checked( $is_wp ); ?> /> <?php esc_html_e( 'Utilisateur WordPress', 'clielo' ); ?></label>
                        <label><input type="radio" name="clielo_client_type" value="ext" <?php checked( ! $is_wp ); ?> /> <?php esc_html_e( 'Client externe', 'clielo' ); ?></label>
                    </div>
                    <div class="clielo-newinv-field" id="clielo-newinv-wp-client" style="<?php echo $is_wp ? '' : 'display:none'; ?>">
                        <label><?php esc_html_e( 'Sélectionner un utilisateur', 'clielo' ); ?></label>
                        <select id="clielo-newinv-client-id">
                            <option value=""><?php esc_html_e( '— Choisir —', 'clielo' ); ?></option>
                            <?php foreach ( $wp_users as $u ) : ?>
                                <option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $invoice->client_id, $u->ID ); ?>><?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="clielo-newinv-field" id="clielo-newinv-ext-client" style="<?php echo $is_wp ? 'display:none' : ''; ?>">
                        <label><?php esc_html_e( 'Sélectionner un client externe', 'clielo' ); ?></label>
                        <select id="clielo-newinv-ext-id">
                            <option value=""><?php esc_html_e( '— Choisir —', 'clielo' ); ?></option>
                            <?php foreach ( $ext_clients as $ec ) : ?>
                                <option value="<?php echo (int) $ec->id; ?>" <?php selected( (int) $invoice->ext_client_id, $ec->id ); ?>><?php echo esc_html( $ec->name . ( $ec->company ? ' — ' . $ec->company : '' ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Articles -->
                <div class="clielo-newinv-section">
                    <h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Articles', 'clielo' ); ?></h2>
                    <table class="clielo-items-table" id="clielo-items-table">
                        <thead>
                            <tr>
                                <th style="width:50%"><?php esc_html_e( 'Description', 'clielo' ); ?></th>
                                <th style="width:10%"><?php esc_html_e( 'Qté', 'clielo' ); ?></th>
                                <th style="width:20%"><?php esc_html_e( 'Prix unitaire HT', 'clielo' ); ?></th>
                                <th style="width:15%"><?php esc_html_e( 'Total HT', 'clielo' ); ?></th>
                                <th style="width:5%"></th>
                            </tr>
                        </thead>
                        <tbody id="clielo-items-body">
                            <?php foreach ( $items as $item ) : ?>
                            <tr class="clielo-item-row">
                                <td><input type="text" class="clielo-item-desc" value="<?php echo esc_attr( $item['description'] ?? '' ); ?>" /></td>
                                <td><input type="number" class="clielo-item-qty" value="<?php echo esc_attr( $item['quantity'] ?? 1 ); ?>" min="1" step="1" /></td>
                                <td><input type="number" class="clielo-item-price" value="<?php echo esc_attr( $item['unit_price'] ?? 0 ); ?>" min="0" step="0.01" /></td>
                                <td class="clielo-item-total" style="text-align:right;font-weight:600"><?php echo esc_html( number_format( floatval( $item['total'] ?? 0 ), 2, ',', ' ' ) ); ?> <?php echo esc_html( $curr_sym ); ?></td>
                                <td><button type="button" class="clielo-items-rm">&times;</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="button" id="clielo-add-item" class="button">+ <?php esc_html_e( 'Ajouter un article', 'clielo' ); ?></button>

                    <div class="clielo-newinv-totals" style="margin-top:16px">
                        <div><?php esc_html_e( 'Sous-total HT', 'clielo' ); ?> : <span id="clielo-newinv-subtotal"><?php echo esc_html( number_format( (float) $invoice->subtotal, 2, ',', '' ) ); ?></span> <?php echo esc_html( $curr_sym ); ?></div>
                        <div><?php esc_html_e( 'TVA', 'clielo' ); ?> (<span id="clielo-newinv-taxrate-display"><?php echo esc_html( $invoice->tax_rate ); ?></span>%) : <span id="clielo-newinv-tax"><?php echo esc_html( number_format( (float) $invoice->tax_amount, 2, ',', '' ) ); ?></span> <?php echo esc_html( $curr_sym ); ?></div>
                        <div><strong><?php esc_html_e( 'Total TTC', 'clielo' ); ?> : <span id="clielo-newinv-total"><?php echo esc_html( number_format( (float) $invoice->total, 2, ',', '' ) ); ?></span> <?php echo esc_html( $curr_sym ); ?></strong></div>
                    </div>
                </div>

                <!-- TVA et notes -->
                <div class="clielo-newinv-section">
                    <h2><span class="dashicons dashicons-editor-alignleft"></span> <?php esc_html_e( 'Détails', 'clielo' ); ?></h2>
                    <div class="clielo-newinv-field" style="max-width:200px">
                        <label><?php esc_html_e( 'Taux TVA (%)', 'clielo' ); ?></label>
                        <input type="number" id="clielo-newinv-taxrate" value="<?php echo esc_attr( $invoice->tax_rate ); ?>" min="0" max="100" step="0.01" />
                    </div>
                    <div class="clielo-newinv-field" style="max-width:280px">
                        <label><?php esc_html_e( 'Type de facture', 'clielo' ); ?></label>
                        <select id="clielo-newinv-type">
                            <option value="single" <?php selected( $invoice->invoice_type, 'single' ); ?>><?php esc_html_e( 'Facture simple', 'clielo' ); ?></option>
                            <option value="acompte" <?php selected( $invoice->invoice_type, 'acompte' ); ?>><?php esc_html_e( "Facture d'acompte", 'clielo' ); ?></option>
                            <option value="solde" <?php selected( $invoice->invoice_type, 'solde' ); ?>><?php esc_html_e( 'Facture de solde', 'clielo' ); ?></option>
                        </select>
                    </div>
                    <div class="clielo-newinv-field">
                        <label><?php esc_html_e( 'Notes / Conditions', 'clielo' ); ?></label>
                        <textarea id="clielo-newinv-notes"><?php echo esc_textarea( $invoice->notes ); ?></textarea>
                    </div>
                </div>

                <!-- Actions -->
                <button type="button" class="button clielo-newinv-save" data-status="draft" style="margin-right:8px"><?php esc_html_e( 'Enregistrer en brouillon', 'clielo' ); ?></button>
                <button type="button" class="button button-primary clielo-newinv-save" data-status="validated" style="background:<?php echo esc_attr( $color ); ?>;border-color:<?php echo esc_attr( $color ); ?>"><?php esc_html_e( 'Enregistrer et valider', 'clielo' ); ?></button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=clielo-invoices' ) ); ?>" style="padding:8px 20px;font-size:13px;text-decoration:none;color:#555">&larr; <?php esc_html_e( 'Retour', 'clielo' ); ?></a>
            </div>

            <?php
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';
                var invoiceId = <?php echo (int) $invoice->id; ?>;

                document.querySelectorAll('input[name="clielo_client_type"]').forEach(function(r){
                    r.addEventListener('change', function(){
                        document.getElementById('clielo-newinv-wp-client').style.display = this.value==='wp' ? '' : 'none';
                        document.getElementById('clielo-newinv-ext-client').style.display = this.value==='ext' ? '' : 'none';
                    });
                });

                function recalc(){
                    var subtotal = 0;
                    document.querySelectorAll('.clielo-item-row').forEach(function(row){
                        var qty   = parseFloat(row.querySelector('.clielo-item-qty').value) || 0;
                        var price = parseFloat(row.querySelector('.clielo-item-price').value) || 0;
                        var lt    = Math.round(qty * price * 100) / 100;
                        row.querySelector('.clielo-item-total').textContent = lt.toFixed(2).replace('.',',') + ' \u20ac';
                        subtotal += lt;
                    });
                    var rate = parseFloat(document.getElementById('clielo-newinv-taxrate').value) || 0;
                    var tax  = Math.round(subtotal * rate) / 100;
                    var total = subtotal + tax;
                    document.getElementById('clielo-newinv-subtotal').textContent = subtotal.toFixed(2).replace('.',',');
                    document.getElementById('clielo-newinv-tax').textContent = tax.toFixed(2).replace('.',',');
                    document.getElementById('clielo-newinv-total').textContent = total.toFixed(2).replace('.',',');
                    document.getElementById('clielo-newinv-taxrate-display').textContent = rate;
                }

                document.addEventListener('input', function(e){
                    if(e.target.classList.contains('clielo-item-qty') || e.target.classList.contains('clielo-item-price') || e.target.id === 'clielo-newinv-taxrate') recalc();
                });

                document.getElementById('clielo-add-item').addEventListener('click', function(){
                    var row = document.createElement('tr');
                    row.className = 'clielo-item-row';
                    row.innerHTML = '<td><input type="text" class="clielo-item-desc" placeholder="<?php echo esc_js( __( 'Description du service', 'clielo' ) ); ?>" /></td>' +
                        '<td><input type="number" class="clielo-item-qty" value="1" min="1" step="1" /></td>' +
                        '<td><input type="number" class="clielo-item-price" value="0" min="0" step="0.01" /></td>' +
                        '<td class="clielo-item-total" style="text-align:right;font-weight:600">0,00 \u20ac</td>' +
                        '<td><button type="button" class="clielo-items-rm">&times;</button></td>';
                    document.getElementById('clielo-items-body').appendChild(row);
                });

                document.addEventListener('click', function(e){
                    if(e.target.classList.contains('clielo-items-rm')){
                        var rows = document.querySelectorAll('.clielo-item-row');
                        if(rows.length > 1){ e.target.closest('tr').remove(); recalc(); }
                    }
                });

                document.querySelectorAll('.clielo-newinv-save').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var status = this.dataset.status;
                        this.disabled = true;

                        var clientType = document.querySelector('input[name="clielo_client_type"]:checked').value;
                        var fd = new FormData();
                        fd.append('action', 'clielo_invoice_update');
                        fd.append('nonce', nonce);
                        fd.append('invoice_id', invoiceId);
                        fd.append('client_type', clientType);
                        fd.append('client_id', document.getElementById('clielo-newinv-client-id').value);
                        fd.append('ext_client_id', document.getElementById('clielo-newinv-ext-id').value);
                        fd.append('tax_rate', document.getElementById('clielo-newinv-taxrate').value);
                        fd.append('notes', document.getElementById('clielo-newinv-notes').value);
                        fd.append('invoice_type', document.getElementById('clielo-newinv-type').value);
                        fd.append('save_status', status);

                        var rows = document.querySelectorAll('.clielo-item-row');
                        rows.forEach(function(row, i){
                            fd.append('items['+i+'][description]', row.querySelector('.clielo-item-desc').value);
                            fd.append('items['+i+'][quantity]', row.querySelector('.clielo-item-qty').value);
                            fd.append('items['+i+'][unit_price]', row.querySelector('.clielo-item-price').value);
                        });

                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){
                            btn.disabled = false;
                            if(res.success){
                                window.location.href = '<?php echo esc_url( admin_url( 'admin.php?page=clielo-invoices' ) ); ?>';
                            } else {
                                alert(res.data && res.data.message ? res.data.message : 'Erreur');
                            }
                        });
                    });
                });
            })();
            <?php
            wp_add_inline_script( 'clielo-invoices-js', ob_get_clean() );
            ?>
        </div>
        <?php
    }

    /* ================================================================
     *  PAGE ADMIN — Vue facture (imprimable)
     * ================================================================ */

    public static function render_invoice_view(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $invoice_id = absint( $_GET['invoice_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin-only render, capability checked above.
        if ( ! $invoice_id ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Facture introuvable.', 'clielo' ) . '</p></div>';
            return;
        }

        $invoice = self::get_invoice( $invoice_id );
        if ( ! $invoice ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Facture introuvable.', 'clielo' ) . '</p></div>';
            return;
        }

        self::render_invoice_html( $invoice, true );
    }

    /* ================================================================
     *  RENDU HTML FACTURE (partagé admin/client)
     * ================================================================ */

    private static function render_invoice_html( object $invoice, bool $is_admin ): void {
        $s           = self::get_settings();
        $color       = esc_attr( Clielo_Admin::get_color() );
        $labels      = self::get_status_labels();
        $colors      = self::get_status_colors();
        $items       = json_decode( $invoice->items, true ) ?: [];
        $client_info = self::get_client_info(
            $invoice->client_id ? (int) $invoice->client_id : null,
            $invoice->ext_client_id ? (int) $invoice->ext_client_id : null
        );
        $nonce       = wp_create_nonce( 'clielo_nonce' );
        $curr_data   = Clielo_Admin::get_currency_data();
        $curr_sym    = $curr_data['symbol'];
        $curr_dec    = $curr_data['decimals'];
        $badge_color = $colors[ $invoice->status ] ?? '#9ca3af';
        $status_text = $labels[ $invoice->status ] ?? $invoice->status;
        ?>
        <div class="clielo-invoice-page">
            <!-- Header : logo + entreprise à gauche, infos facture à droite -->
            <div class="clielo-inv-header">
                <div class="clielo-inv-header-left">
                    <?php if ( ! empty( $s['company_logo'] ) ) : ?>
                        <div class="clielo-inv-logo">
                            <img src="<?php echo esc_url( $s['company_logo'] ); ?>" alt="" />
                        </div>
                    <?php else : ?>
                        <div class="clielo-inv-company">
                            <strong><?php echo esc_html( $s['company_name'] ); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="clielo-inv-header-right">
                    <h3><?php
                        $invoice_type_val = $invoice->invoice_type ?? 'single';
                        if ( $invoice_type_val === 'quote' || $invoice_type_val === 'quote_request' ) {
                            esc_html_e( 'Devis', 'clielo' );
                        } elseif ( $invoice_type_val === 'acompte' ) {
                            esc_html_e( "Facture d'acompte", 'clielo' );
                        } elseif ( $invoice_type_val === 'solde' ) {
                            esc_html_e( 'Facture de solde', 'clielo' );
                        } elseif ( $invoice_type_val === 'mensualite' ) {
                            $sched_row = $invoice->schedule_id && class_exists( 'Clielo_Payments' )
                                ? Clielo_Payments::get_row( (int) $invoice->schedule_id )
                                : null;
                            $n = $sched_row ? (int) $sched_row->installment_no : 0;
                            /* translators: %d: installment number */
                            echo esc_html( sprintf( __( 'Facture — Mensualité %d', 'clielo' ), $n ) );
                        } else {
                            esc_html_e( 'Facture', 'clielo' );
                        }
                    ?></h3>
                    <p>
                        <strong><?php echo esc_html( $invoice->invoice_number ); ?></strong><br />
                        <?php esc_html_e( 'Date', 'clielo' ); ?> : <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $invoice->created_at ) ) ); ?>
                        <?php if ( $invoice->order_id && ! in_array( $invoice->invoice_type, [ 'quote', 'quote_request' ], true ) ) : ?><br /><?php esc_html_e( 'Commande', 'clielo' ); ?> : #CMD-<?php echo esc_html( $invoice->order_id ); ?><?php endif; ?>
                        <?php if ( $invoice->paid_at ) : ?><br /><?php esc_html_e( 'Payée le', 'clielo' ); ?> : <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $invoice->paid_at ) ) ); ?><?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Émetteur + Client côte à côte -->
            <div class="clielo-inv-parties">
                <div class="clielo-inv-emetteur">
                    <h3><?php esc_html_e( 'Émetteur', 'clielo' ); ?></h3>
                    <p>
                        <strong><?php echo esc_html( $s['company_name'] ); ?></strong><br />
                        <?php if ( $s['company_address'] ) : ?><?php echo nl2br( esc_html( $s['company_address'] ) ); ?><br /><?php endif; ?>
                        <?php if ( $s['company_postal'] || $s['company_city'] ) : ?><?php echo esc_html( $s['company_postal'] . ' ' . $s['company_city'] ); ?><br /><?php endif; ?>
                        <?php if ( $s['company_country'] ) : ?><?php echo esc_html( $s['company_country'] ); ?><br /><?php endif; ?>
                        <?php if ( $s['company_phone'] ) : ?><?php echo esc_html( $s['company_phone'] ); ?><br /><?php endif; ?>
                        <?php if ( $s['company_email'] ) : ?><?php echo esc_html( $s['company_email'] ); ?><br /><?php endif; ?>
                        <?php if ( $s['vat_number'] ) : ?>TVA : <?php echo esc_html( $s['vat_number'] ); ?><br /><?php endif; ?>
                        <?php if ( $s['siret_ifu'] ) : ?><?php echo esc_html( $s['siret_label'] ?: 'SIRET/IFU' ); ?> : <?php echo esc_html( $s['siret_ifu'] ); ?><?php endif; ?>
                    </p>
                </div>
                <div class="clielo-inv-client">
                <h3><?php esc_html_e( 'Client', 'clielo' ); ?></h3>
                <?php if ( $client_info ) : ?>
                    <p>
                        <strong><?php echo esc_html( $client_info->name ); ?></strong><br />
                        <?php if ( ! empty( $client_info->company ) ) : ?><?php echo esc_html( $client_info->company ); ?><br /><?php endif; ?>
                        <?php if ( ! empty( $client_info->address ) ) : ?><?php echo nl2br( esc_html( $client_info->address ) ); ?><br /><?php endif; ?>
                        <?php if ( ! empty( $client_info->postal_code ) || ! empty( $client_info->city ) ) : ?><?php echo esc_html( ( $client_info->postal_code ?? '' ) . ' ' . ( $client_info->city ?? '' ) ); ?><br /><?php endif; ?>
                        <?php if ( ! empty( $client_info->email ) ) : ?><?php echo esc_html( $client_info->email ); ?><br /><?php endif; ?>
                        <?php if ( ! empty( $client_info->vat_number ) ) : ?>TVA : <?php echo esc_html( $client_info->vat_number ); ?><?php endif; ?>
                    </p>
                <?php else : ?>
                    <p style="color:#999"><?php esc_html_e( 'Client inconnu', 'clielo' ); ?></p>
                <?php endif; ?>
                </div>
            </div>

            <!-- Tableau articles -->
            <table class="clielo-inv-items-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Description', 'clielo' ); ?></th>
                        <th class="text-right"><?php esc_html_e( 'Qté', 'clielo' ); ?></th>
                        <th class="text-right"><?php esc_html_e( 'Prix unitaire HT', 'clielo' ); ?></th>
                        <th class="text-right"><?php esc_html_e( 'Total HT', 'clielo' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $items as $item ) :
                        $is_info = ! empty( $item['info_only'] );
                    ?>
                        <tr<?php if ( $is_info ) : ?> style="color:#888;font-size:0.92em"<?php endif; ?>>
                            <td>
                                <?php if ( ! empty( $item['service_name'] ) ) : ?>
                                    <strong><?php echo esc_html( $item['service_name'] ); ?></strong> &mdash;
                                <?php endif; ?>
                                <?php echo esc_html( $item['description'] ?? '' ); ?>
                            </td>
                            <td class="text-right"><?php echo esc_html( $item['quantity'] ?? 1 ); ?></td>
                            <?php if ( $is_info ) : ?>
                            <td class="text-right" style="color:#aaa"><?php esc_html_e( 'Inclus', 'clielo' ); ?></td>
                            <td class="text-right" style="color:#aaa">—</td>
                            <?php else : ?>
                            <td class="text-right"><?php echo esc_html( number_format( floatval( $item['unit_price'] ?? 0 ), 2, ',', ' ' ) ); ?> <?php echo esc_html( $curr_sym ); ?></td>
                            <td class="text-right"><?php echo esc_html( number_format( floatval( $item['total'] ?? 0 ), 2, ',', ' ' ) ); ?> <?php echo esc_html( $curr_sym ); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Totaux -->
            <div class="clielo-inv-totals">
                <?php if ( floatval( $invoice->tax_rate ) > 0 ) : ?>
                    <div class="clielo-inv-totals-row">
                        <span><?php esc_html_e( 'Sous-total HT', 'clielo' ); ?></span>
                        <span><?php echo esc_html( number_format( (float) $invoice->subtotal, 2, ',', ' ' ) ); ?> <?php echo esc_html( $curr_sym ); ?></span>
                    </div>
                    <div class="clielo-inv-totals-row">
                        <span><?php esc_html_e( 'TVA', 'clielo' ); ?> (<?php echo esc_html( $invoice->tax_rate ); ?>%)</span>
                        <span><?php echo esc_html( number_format( (float) $invoice->tax_amount, 2, ',', ' ' ) ); ?> <?php echo esc_html( $curr_sym ); ?></span>
                    </div>
                    <div class="clielo-inv-totals-row total-row">
                        <span><?php esc_html_e( 'Total TTC', 'clielo' ); ?></span>
                        <span><?php echo esc_html( number_format( (float) $invoice->total, 2, ',', ' ' ) ); ?> <?php echo esc_html( $curr_sym ); ?></span>
                    </div>
                <?php else : ?>
                    <div class="clielo-inv-totals-row total-row">
                        <span><?php esc_html_e( 'Total', 'clielo' ); ?></span>
                        <span><?php echo esc_html( number_format( (float) $invoice->total, 2, ',', ' ' ) ); ?> <?php echo esc_html( $curr_sym ); ?></span>
                    </div>
                    <?php if ( ! empty( $s['tax_notice'] ) ) : ?>
                        <div class="clielo-inv-totals-row" style="font-size:11px;color:#888;font-style:italic;border-top:none;padding-top:4px">
                            <span><?php echo esc_html( $s['tax_notice'] ); ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Notes -->
            <?php if ( ! empty( $invoice->notes ) ) : ?>
                <div class="clielo-inv-notes">
                    <strong><?php esc_html_e( 'Conditions', 'clielo' ); ?></strong><br />
                    <?php echo nl2br( esc_html( $invoice->notes ) ); ?>
                </div>
            <?php endif; ?>

            <!-- Footer -->
            <?php if ( ! empty( $s['footer_text'] ) ) : ?>
                <div class="clielo-inv-footer-text"><?php echo nl2br( esc_html( $s['footer_text'] ) ); ?></div>
            <?php endif; ?>
        </div>

        <!-- Actions (non imprimables) -->
        <div class="clielo-inv-actions no-print" style="max-width:800px;margin:0 auto">
            <button onclick="clieloPrintInvoice()" style="background:<?php echo esc_attr( $color ); ?>;color:#fff">
                <?php esc_html_e( 'Imprimer', 'clielo' ); ?>
            </button>
            <?php if ( $is_admin ) : ?>
                <?php if ( in_array( $invoice->status, [ self::STATUS_DRAFT, self::STATUS_PENDING ], true ) ) : ?>
                    <button class="clielo-inv-view-action" data-action="clielo_invoice_validate" data-id="<?php echo (int) $invoice->id; ?>" style="background:#10b981;color:#fff">
                        <?php esc_html_e( 'Valider', 'clielo' ); ?>
                    </button>
                <?php endif; ?>
                <?php if ( $invoice->status === self::STATUS_VALIDATED ) : ?>
                    <button class="clielo-inv-view-action" data-action="clielo_invoice_mark_paid" data-id="<?php echo (int) $invoice->id; ?>" style="background:#10b981;color:#fff">
                        <?php esc_html_e( 'Marquer comme payée', 'clielo' ); ?>
                    </button>
                <?php endif; ?>
                <?php if ( ! in_array( $invoice->status, [ self::STATUS_PAID, self::STATUS_CANCELLED ], true ) ) : ?>
                    <button class="clielo-inv-view-action" data-action="clielo_invoice_cancel" data-id="<?php echo (int) $invoice->id; ?>" style="background:#ef4444;color:#fff">
                        <?php esc_html_e( 'Annuler', 'clielo' ); ?>
                    </button>
                <?php endif; ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=clielo-invoices' ) ); ?>" style="padding:8px 20px;font-size:13px;text-decoration:none;color:#555">&larr; <?php esc_html_e( 'Retour à la liste', 'clielo' ); ?></a>
            <?php endif; ?>
        </div>

        <?php
        wp_add_inline_script(
            'clielo-invoices-js',
            'function clieloPrintInvoice(){' .
            'var content=document.querySelector(".clielo-invoice-page");' .
            'if(!content){window.print();return;}' .
            'var styles="";' .
            'document.querySelectorAll("link[rel=\'stylesheet\'],style").forEach(function(el){styles+=el.outerHTML;});' .
            'var win=window.open("","_blank","width=900,height=700");' .
            'if(!win){window.print();return;}' .
            'win.document.write("<!DOCTYPE html><html><head><meta charset=\'utf-8\'>"+styles+"<style>.no-print,.clielo-inv-actions{display:none!important}</style></head><body>");' .
            'win.document.write(content.outerHTML);' .
            'win.document.write("</body></html>");' .
            'win.document.close();' .
            'win.focus();' .
            'win.onload=function(){win.print();};' .
            '}'
        );
        if ( $is_admin ) :
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';
                document.querySelectorAll('.clielo-inv-view-action').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        if(!confirm('<?php echo esc_js( __( 'Confirmer cette action ?', 'clielo' ) ); ?>')) return;
                        btn.disabled = true;
                        var fd = new FormData();
                        fd.append('action', this.dataset.action);
                        fd.append('nonce', nonce);
                        fd.append('invoice_id', this.dataset.id);
                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){ if(res.success) location.reload(); else { btn.disabled=false; alert(res.data.message||'Erreur'); } });
                    });
                });
            })();
            <?php
            wp_add_inline_script( 'clielo-invoices-js', ob_get_clean() );
        endif; ?>
        <?php
    }

    /* ================================================================
     *  Migration ponctuelle : factures manquantes pour commandes via devis
     * ================================================================ */

    /**
     * Crée rétroactivement les factures manquantes pour les commandes payées
     * via le flux devis, avant le déploiement du correctif (mai 2026).
     *
     * Déclenchée une seule fois via admin_init (option clielo_fix_quote_invoices_v1).
     */
    public static function maybe_fix_missing_quote_invoices(): void {
        if ( get_option( 'clielo_fix_quote_invoices_v1' ) ) {
            return;
        }

        if ( ! class_exists( 'Clielo_Payments' ) || ! class_exists( 'Clielo_Orders' ) ) {
            return;
        }

        global $wpdb;

        $order_table   = Clielo_Orders::table_name();
        $invoice_table = self::invoices_table_name();

        // Commandes payées via Stripe (stripe_payment_intent non-vide) avec un DEVIS
        // mais sans facture de paiement (non-quote, non-cancelled).
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $affected = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT o.*
             FROM {$order_table} o
             WHERE o.stripe_payment_intent != ''
               AND o.stripe_payment_intent IS NOT NULL
               AND o.status IN ('started','completed','revision','accepted')
               AND EXISTS (
                   SELECT 1 FROM {$invoice_table} qi
                   WHERE qi.order_id = o.id AND qi.invoice_type = 'quote'
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$invoice_table} pi
                   WHERE pi.order_id = o.id
                     AND pi.invoice_type != 'quote'
                     AND pi.status != 'cancelled'
               )"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( empty( $affected ) ) {
            update_option( 'clielo_fix_quote_invoices_v1', '1' );
            return;
        }

        foreach ( $affected as $order ) {
            $order_id = (int) $order->id;
            $post_id  = (int) $order->post_id;

            // Lire le vrai mode de paiement depuis les méta du service (source de vérité)
            $real_mode         = get_post_meta( $post_id, '_clielo_payment_mode', true ) ?: 'single';
            $installments_count = (int) ( get_post_meta( $post_id, '_clielo_installments_count', true ) ?: 3 );

            if ( $real_mode !== 'single' && $order->payment_mode !== $real_mode ) {
                // Corriger le mode de paiement stocké dans la commande
                $deposit_percent = ( $real_mode === 'deposit' ) ? 50 : ( $real_mode === 'installments' ? 40 : 100 );
                $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $order_table,
                    [
                        'payment_mode'       => $real_mode,
                        'deposit_percent'    => $deposit_percent,
                        'installments_count' => $installments_count,
                    ],
                    [ 'id' => $order_id ],
                    [ '%s', '%d', '%d' ],
                    [ '%d' ]
                );
            }

            if ( $real_mode === 'single' ) {
                // Mode unique : générer la facture complète
                self::on_order_accepted( $order_id, Clielo_Orders::STATUS_ACCEPTED, (string) $order->status, (int) $order->client_id );
            } else {
                // Mode non-single : créer l'échéancier si manquant, puis la facture d'acompte
                $schedule = Clielo_Payments::get_schedule_for_order( $order_id );
                if ( empty( $schedule ) ) {
                    Clielo_Payments::create_schedule_for_order( $order_id );
                    $schedule = Clielo_Payments::get_schedule_for_order( $order_id );
                }

                foreach ( $schedule as $row ) {
                    $is_upfront = ( $row->type === 'upfront' )
                        || ( $real_mode === 'monthly' && (int) $row->installment_no === 1 );
                    if ( $is_upfront ) {
                        $inv_type = ( $real_mode === 'monthly' ) ? 'mensualite' : 'acompte';
                        self::create_partial_invoice(
                            $order_id,
                            floatval( $row->amount_ttc ),
                            $inv_type,
                            (int) $row->id,
                            (int) $row->installment_no
                        );
                        break;
                    }
                }
            }
        }

        update_option( 'clielo_fix_quote_invoices_v1', '1' );
    }

    /* ================================================================
     *  AJAX — Devis manuels (gratuit)
     * ================================================================ */

    public static function ajax_save_quote(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        global $wpdb;

        $client_type   = sanitize_text_field( wp_unslash( $_POST['client_type'] ?? 'ext' ) );
        $client_id     = absint( $_POST['client_id'] ?? 0 );
        $ext_client_id = absint( $_POST['ext_client_id'] ?? 0 );
        $title         = sanitize_text_field( wp_unslash( $_POST['quote_title'] ?? '' ) );
        $tax_rate      = floatval( $_POST['tax_rate'] ?? 20 );
        $notes         = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $validity      = sanitize_text_field( wp_unslash( $_POST['validity_date'] ?? '' ) );

        if ( $client_type === 'wp' && ! clielo_is_premium() ) {
            wp_send_json_error( [ 'message' => __( 'La facturation des clients WordPress nécessite le plan premium.', 'clielo' ) ], 403 );
        }
        if ( $client_type === 'wp' && ! $client_id ) {
            wp_send_json_error( [ 'message' => __( 'Sélectionnez un client.', 'clielo' ) ], 400 );
        }
        if ( $client_type === 'ext' && ! $ext_client_id ) {
            wp_send_json_error( [ 'message' => __( 'Sélectionnez un client externe.', 'clielo' ) ], 400 );
        }

        $raw_items = wp_unslash( $_POST['items'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $items     = [];
        if ( is_array( $raw_items ) ) {
            foreach ( $raw_items as $item ) {
                $desc = sanitize_text_field( $item['description'] ?? '' );
                if ( empty( $desc ) ) {
                    continue;
                }
                $qty   = max( 1, absint( $item['quantity'] ?? 1 ) );
                $price = floatval( $item['unit_price'] ?? 0 );
                $items[] = [
                    'description' => $desc,
                    'quantity'    => $qty,
                    'unit_price'  => $price,
                    'total'       => round( $qty * $price, 2 ),
                ];
            }
        }

        if ( empty( $items ) ) {
            wp_send_json_error( [ 'message' => __( 'Ajoutez au moins un article.', 'clielo' ) ], 400 );
        }

        $subtotal   = array_sum( array_column( $items, 'total' ) );
        $tax_amount = round( $subtotal * $tax_rate / 100, 2 );
        $total      = $subtotal + $tax_amount;

        $quote_number = self::generate_quote_number();

        $notes_full = $title !== '' ? $title . ( $notes !== '' ? "\n" . $notes : '' ) : $notes;
        if ( $validity !== '' ) {
            /* translators: %s: validity date */
            $notes_full .= ( $notes_full !== '' ? "\n" : '' ) . sprintf( __( 'Valable jusqu\'au %s.', 'clielo' ), esc_html( $validity ) );
        }

        $table = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert(
            $table,
            [
                'invoice_number' => $quote_number,
                'order_id'       => null,
                'client_id'      => $client_type === 'wp' ? $client_id : null,
                'ext_client_id'  => $client_type === 'ext' ? $ext_client_id : null,
                'status'         => self::STATUS_DRAFT,
                'items'          => wp_json_encode( $items ),
                'subtotal'       => $subtotal,
                'tax_rate'       => $tax_rate,
                'tax_amount'     => $tax_amount,
                'total'          => $total,
                'notes'          => $notes_full,
                'invoice_type'   => 'quote',
                'created_at'     => current_time( 'mysql' ),
                'updated_at'     => current_time( 'mysql' ),
            ]
        );

        if ( ! $inserted ) {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de la création du devis.', 'clielo' ) ], 500 );
        }

        wp_send_json_success( [ 'quote_id' => (int) $wpdb->insert_id, 'quote_number' => $quote_number ] );
    }

    public static function ajax_update_quote(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        global $wpdb;

        $quote_id = absint( $_POST['quote_id'] ?? 0 );
        if ( ! $quote_id ) {
            wp_send_json_error( [ 'message' => __( 'Devis introuvable.', 'clielo' ) ], 400 );
        }

        $table = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $quote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND invoice_type = 'quote' AND order_id IS NULL", $quote_id ) );
        if ( ! $quote || $quote->status !== self::STATUS_DRAFT ) {
            wp_send_json_error( [ 'message' => __( 'Seuls les brouillons peuvent être modifiés.', 'clielo' ) ], 400 );
        }

        $client_type   = sanitize_text_field( wp_unslash( $_POST['client_type'] ?? 'ext' ) );
        $client_id     = absint( $_POST['client_id'] ?? 0 );
        $ext_client_id = absint( $_POST['ext_client_id'] ?? 0 );
        $title         = sanitize_text_field( wp_unslash( $_POST['quote_title'] ?? '' ) );
        $tax_rate      = floatval( $_POST['tax_rate'] ?? 20 );
        $notes         = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $validity      = sanitize_text_field( wp_unslash( $_POST['validity_date'] ?? '' ) );
        $new_status    = sanitize_text_field( wp_unslash( $_POST['new_status'] ?? self::STATUS_DRAFT ) );

        if ( $client_type === 'wp' && ! clielo_is_premium() ) {
            wp_send_json_error( [ 'message' => __( 'La facturation des clients WordPress nécessite le plan premium.', 'clielo' ) ], 403 );
        }

        $raw_items = wp_unslash( $_POST['items'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $items     = [];
        if ( is_array( $raw_items ) ) {
            foreach ( $raw_items as $item ) {
                $desc = sanitize_text_field( $item['description'] ?? '' );
                if ( empty( $desc ) ) {
                    continue;
                }
                $qty   = max( 1, absint( $item['quantity'] ?? 1 ) );
                $price = floatval( $item['unit_price'] ?? 0 );
                $items[] = [
                    'description' => $desc,
                    'quantity'    => $qty,
                    'unit_price'  => $price,
                    'total'       => round( $qty * $price, 2 ),
                ];
            }
        }

        if ( empty( $items ) ) {
            wp_send_json_error( [ 'message' => __( 'Ajoutez au moins un article.', 'clielo' ) ], 400 );
        }

        $subtotal   = array_sum( array_column( $items, 'total' ) );
        $tax_amount = round( $subtotal * $tax_rate / 100, 2 );
        $total      = $subtotal + $tax_amount;

        $allowed_statuses = [ self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_CANCELLED ];
        if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
            $new_status = self::STATUS_DRAFT;
        }

        $notes_full = $title !== '' ? $title . ( $notes !== '' ? "\n" . $notes : '' ) : $notes;
        if ( $validity !== '' ) {
            /* translators: %s: validity date */
            $notes_full .= ( $notes_full !== '' ? "\n" : '' ) . sprintf( __( 'Valable jusqu\'au %s.', 'clielo' ), esc_html( $validity ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            [
                'client_id'     => $client_type === 'wp' ? $client_id : null,
                'ext_client_id' => $client_type === 'ext' ? $ext_client_id : null,
                'status'        => $new_status,
                'items'         => wp_json_encode( $items ),
                'subtotal'      => $subtotal,
                'tax_rate'      => $tax_rate,
                'tax_amount'    => $tax_amount,
                'total'         => $total,
                'notes'         => $notes_full,
                'updated_at'    => current_time( 'mysql' ),
            ],
            [ 'id' => $quote_id ]
        );

        wp_send_json_success( [ 'quote_id' => $quote_id ] );
    }

    public static function ajax_delete_quote(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        global $wpdb;

        $quote_id = absint( $_POST['quote_id'] ?? 0 );
        if ( ! $quote_id ) {
            wp_send_json_error( [ 'message' => __( 'Devis introuvable.', 'clielo' ) ], 400 );
        }

        $table = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $quote = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE id = %d AND invoice_type = 'quote' AND order_id IS NULL", $quote_id ) );
        if ( ! $quote ) {
            wp_send_json_error( [ 'message' => __( 'Devis introuvable.', 'clielo' ) ], 404 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $table, [ 'id' => $quote_id ], [ '%d' ] );

        wp_send_json_success();
    }

    /* ================================================================
     *  PAGE ADMIN — Nouveau devis manuel (gratuit)
     * ================================================================ */

    public static function render_quote_new(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings    = self::get_settings();
        $color       = esc_attr( Clielo_Admin::get_color() );
        $nonce       = wp_create_nonce( 'clielo_nonce' );
        $ext_clients = self::get_all_ext_clients();
        $curr_data   = Clielo_Admin::get_currency_data();
        $curr_sym    = $curr_data['symbol'];
        $curr_dec    = $curr_data['decimals'];
        $wp_users    = clielo_is_premium() ? get_users( [ 'role__not_in' => [ 'administrator' ], 'orderby' => 'display_name', 'number' => 200 ] ) : [];

        $quote_id = absint( $_GET['quote_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $quote    = null;
        $items    = [];
        if ( $quote_id ) {
            global $wpdb;
            $table = self::invoices_table_name();
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $quote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND invoice_type = 'quote' AND order_id IS NULL", $quote_id ) );
            if ( $quote ) {
                $items = json_decode( $quote->items, true ) ?: [];
            }
        }

        $is_edit = $quote && $quote->status === self::STATUS_DRAFT;
        $title   = $is_edit ? __( 'Modifier le devis', 'clielo' ) : __( 'Nouveau devis', 'clielo' );
        ?>
        <div class="wrap clielo-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-media-text" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php echo esc_html( $title ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=clielo-quotes' ) ); ?>" style="font-size:13px;margin-left:8px;font-weight:400;color:#888;text-decoration:none">&#8592; <?php esc_html_e( 'Retour', 'clielo' ); ?></a>
            </h1>

            <div id="clielo-newquote-form">
                <!-- Client -->
                <div class="clielo-newinv-section">
                    <h2><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e( 'Client', 'clielo' ); ?></h2>
                    <?php if ( clielo_is_premium() ) : ?>
                    <div class="clielo-newinv-radio">
                        <label><input type="radio" name="clielo_quote_client_type" value="wp" <?php checked( $is_edit && ! empty( $quote->client_id ) ); ?> /> <?php esc_html_e( 'Utilisateur WordPress', 'clielo' ); ?></label>
                        <label><input type="radio" name="clielo_quote_client_type" value="ext" <?php checked( ! $is_edit || ! empty( $quote->ext_client_id ) ); ?> /> <?php esc_html_e( 'Client externe', 'clielo' ); ?></label>
                    </div>
                    <div class="clielo-newinv-field" id="clielo-newquote-wp-client" style="<?php echo ( $is_edit && ! empty( $quote->client_id ) ) ? '' : 'display:none'; ?>">
                        <label><?php esc_html_e( 'Utilisateur WordPress', 'clielo' ); ?></label>
                        <select id="clielo-newquote-client-id">
                            <option value=""><?php esc_html_e( '— Choisir —', 'clielo' ); ?></option>
                            <?php foreach ( $wp_users as $u ) : ?>
                                <option value="<?php echo (int) $u->ID; ?>" <?php selected( $is_edit ? (int) $quote->client_id : 0, (int) $u->ID ); ?>>
                                    <?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="clielo-newinv-field" id="clielo-newquote-ext-client"<?php echo ( clielo_is_premium() && $is_edit && ! empty( $quote->client_id ) ) ? ' style="display:none"' : ''; ?>>
                        <label><?php esc_html_e( 'Client externe', 'clielo' ); ?></label>
                        <select id="clielo-newquote-ext-id">
                            <option value=""><?php esc_html_e( '— Choisir —', 'clielo' ); ?></option>
                            <?php foreach ( $ext_clients as $ec ) : ?>
                                <option value="<?php echo (int) $ec->id; ?>" <?php selected( $is_edit ? (int) $quote->ext_client_id : 0, (int) $ec->id ); ?>>
                                    <?php echo esc_html( $ec->name . ( $ec->company ? ' — ' . $ec->company : '' ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=clielo-clients' ) ); ?>" style="font-size:12px;margin-left:8px"><?php esc_html_e( 'Gérer les clients externes', 'clielo' ); ?></a>
                    </div>
                </div>

                <!-- Objet -->
                <div class="clielo-newinv-section">
                    <h2><span class="dashicons dashicons-editor-textcolor"></span> <?php esc_html_e( 'Objet du devis', 'clielo' ); ?></h2>
                    <div class="clielo-newinv-field">
                        <label><?php esc_html_e( 'Intitulé (affiché dans les notes)', 'clielo' ); ?></label>
                        <input type="text" id="clielo-newquote-title" value="<?php echo esc_attr( $is_edit ? $quote->notes : '' ); ?>" placeholder="<?php esc_attr_e( 'Ex : Création site web vitrine', 'clielo' ); ?>" style="max-width:500px" />
                    </div>
                    <div class="clielo-newinv-field" style="max-width:200px">
                        <label><?php esc_html_e( 'Date de validité', 'clielo' ); ?></label>
                        <input type="date" id="clielo-newquote-validity" />
                    </div>
                </div>

                <!-- Articles -->
                <div class="clielo-newinv-section">
                    <h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Articles', 'clielo' ); ?></h2>
                    <table class="clielo-items-table" id="clielo-quote-items-table">
                        <thead>
                            <tr>
                                <th style="width:50%"><?php esc_html_e( 'Description', 'clielo' ); ?></th>
                                <th style="width:10%"><?php esc_html_e( 'Qté', 'clielo' ); ?></th>
                                <th style="width:20%"><?php esc_html_e( 'Prix unitaire HT', 'clielo' ); ?></th>
                                <th style="width:15%"><?php esc_html_e( 'Total HT', 'clielo' ); ?></th>
                                <th style="width:5%"></th>
                            </tr>
                        </thead>
                        <tbody id="clielo-quote-items-body">
                            <?php if ( empty( $items ) ) : ?>
                            <tr class="clielo-qitem-row">
                                <td><input type="text" class="clielo-qitem-desc" placeholder="<?php esc_attr_e( 'Description du service', 'clielo' ); ?>" /></td>
                                <td><input type="number" class="clielo-qitem-qty" value="1" min="1" step="1" /></td>
                                <td><input type="number" class="clielo-qitem-price" value="0" min="0" step="0.01" /></td>
                                <td class="clielo-qitem-total" style="text-align:right;font-weight:600">0,00 <?php echo esc_html( $curr_sym ); ?></td>
                                <td><button type="button" class="clielo-items-rm">&times;</button></td>
                            </tr>
                            <?php else : ?>
                                <?php foreach ( $items as $it ) : ?>
                                <tr class="clielo-qitem-row">
                                    <td><input type="text" class="clielo-qitem-desc" value="<?php echo esc_attr( $it['description'] ?? '' ); ?>" /></td>
                                    <td><input type="number" class="clielo-qitem-qty" value="<?php echo absint( $it['quantity'] ?? 1 ); ?>" min="1" step="1" /></td>
                                    <td><input type="number" class="clielo-qitem-price" value="<?php echo esc_attr( number_format( (float) ( $it['unit_price'] ?? 0 ), 2, '.', '' ) ); ?>" min="0" step="0.01" /></td>
                                    <td class="clielo-qitem-total" style="text-align:right;font-weight:600"><?php echo esc_html( number_format( (float) ( $it['total'] ?? 0 ), 2, ',', ' ' ) ); ?> <?php echo esc_html( $curr_sym ); ?></td>
                                    <td><button type="button" class="clielo-items-rm">&times;</button></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <button type="button" id="clielo-add-qitem" class="button">+ <?php esc_html_e( 'Ajouter un article', 'clielo' ); ?></button>

                    <div class="clielo-newinv-totals" style="margin-top:16px">
                        <div><?php esc_html_e( 'Sous-total HT', 'clielo' ); ?> : <span id="clielo-newquote-subtotal">0,00</span> <?php echo esc_html( $curr_sym ); ?></div>
                        <div><?php esc_html_e( 'TVA', 'clielo' ); ?> (<span id="clielo-newquote-taxrate-display"><?php echo esc_html( $settings['tax_rate'] ); ?></span>%) : <span id="clielo-newquote-tax">0,00</span> <?php echo esc_html( $curr_sym ); ?></div>
                        <div><strong><?php esc_html_e( 'Total TTC', 'clielo' ); ?> : <span id="clielo-newquote-total">0,00</span> <?php echo esc_html( $curr_sym ); ?></strong></div>
                    </div>
                </div>

                <!-- TVA et notes -->
                <div class="clielo-newinv-section">
                    <h2><span class="dashicons dashicons-editor-alignleft"></span> <?php esc_html_e( 'Détails', 'clielo' ); ?></h2>
                    <div class="clielo-newinv-field" style="max-width:200px">
                        <label><?php esc_html_e( 'Taux TVA (%)', 'clielo' ); ?></label>
                        <input type="number" id="clielo-newquote-taxrate" value="<?php echo esc_attr( $is_edit ? $quote->tax_rate : $settings['tax_rate'] ); ?>" min="0" max="100" step="0.01" />
                    </div>
                    <div class="clielo-newinv-field">
                        <label><?php esc_html_e( 'Notes / Conditions', 'clielo' ); ?></label>
                        <textarea id="clielo-newquote-notes"><?php echo esc_textarea( $settings['payment_terms'] ); ?></textarea>
                    </div>
                </div>

                <!-- Actions -->
                <button type="button" id="clielo-newquote-save" class="button button-primary" style="background:<?php echo esc_attr( $color ); ?>;border-color:<?php echo esc_attr( $color ); ?>">
                    <?php echo $is_edit ? esc_html__( 'Enregistrer les modifications', 'clielo' ) : esc_html__( 'Créer le devis', 'clielo' ); ?>
                </button>
                <?php if ( $is_edit ) : ?>
                    <button type="button" id="clielo-newquote-send" class="button" style="margin-left:8px"><?php esc_html_e( 'Marquer comme envoyé', 'clielo' ); ?></button>
                <?php endif; ?>
            </div>

            <?php
            ob_start();
            $ajax_action = $is_edit ? 'clielo_update_quote' : 'clielo_save_quote';
            $redirect    = admin_url( 'admin.php?page=clielo-quotes' );
            ?>
            (function(){
                var ajaxUrl  = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce    = '<?php echo esc_js( $nonce ); ?>';
                var quoteId  = <?php echo $is_edit ? (int) $quote->id : 0; ?>;
                var isPremium = <?php echo clielo_is_premium() ? 'true' : 'false'; ?>;

                // Toggle client type (premium only)
                if(isPremium){
                    document.querySelectorAll('input[name="clielo_quote_client_type"]').forEach(function(r){
                        r.addEventListener('change', function(){
                            document.getElementById('clielo-newquote-wp-client').style.display = this.value==='wp' ? '' : 'none';
                            document.getElementById('clielo-newquote-ext-client').style.display = this.value==='ext' ? '' : 'none';
                        });
                    });
                }

                // Calcul totaux
                function recalcQ(){
                    var subtotal = 0;
                    document.querySelectorAll('.clielo-qitem-row').forEach(function(row){
                        var qty   = parseFloat(row.querySelector('.clielo-qitem-qty').value) || 0;
                        var price = parseFloat(row.querySelector('.clielo-qitem-price').value) || 0;
                        var lt    = Math.round(qty * price * 100) / 100;
                        row.querySelector('.clielo-qitem-total').textContent = lt.toFixed(2).replace('.',',') + ' €';
                        subtotal += lt;
                    });
                    var rate  = parseFloat(document.getElementById('clielo-newquote-taxrate').value) || 0;
                    var tax   = Math.round(subtotal * rate) / 100;
                    var total = subtotal + tax;
                    document.getElementById('clielo-newquote-subtotal').textContent = subtotal.toFixed(2).replace('.',',');
                    document.getElementById('clielo-newquote-tax').textContent = tax.toFixed(2).replace('.',',');
                    document.getElementById('clielo-newquote-total').textContent = total.toFixed(2).replace('.',',');
                    document.getElementById('clielo-newquote-taxrate-display').textContent = rate;
                }

                document.addEventListener('input', function(e){
                    if(e.target.classList.contains('clielo-qitem-qty') || e.target.classList.contains('clielo-qitem-price') || e.target.id === 'clielo-newquote-taxrate') recalcQ();
                });

                // Ajouter article
                document.getElementById('clielo-add-qitem').addEventListener('click', function(){
                    var row = document.createElement('tr');
                    row.className = 'clielo-qitem-row';
                    row.innerHTML = '<td><input type="text" class="clielo-qitem-desc" placeholder="<?php echo esc_js( __( 'Description du service', 'clielo' ) ); ?>" /></td>' +
                        '<td><input type="number" class="clielo-qitem-qty" value="1" min="1" step="1" /></td>' +
                        '<td><input type="number" class="clielo-qitem-price" value="0" min="0" step="0.01" /></td>' +
                        '<td class="clielo-qitem-total" style="text-align:right;font-weight:600">0,00 €</td>' +
                        '<td><button type="button" class="clielo-items-rm">&times;</button></td>';
                    document.getElementById('clielo-quote-items-body').appendChild(row);
                });

                // Supprimer article
                document.addEventListener('click', function(e){
                    if(e.target.classList.contains('clielo-items-rm')){
                        var rows = document.querySelectorAll('.clielo-qitem-row');
                        if(rows.length > 1){ e.target.closest('tr').remove(); recalcQ(); }
                    }
                });

                function buildFormData(extraStatus){
                    var ctRadio = document.querySelector('input[name="clielo_quote_client_type"]:checked');
                    var clientType = ctRadio ? ctRadio.value : 'ext';
                    var fd = new FormData();
                    fd.append('action', quoteId ? 'clielo_update_quote' : 'clielo_save_quote');
                    fd.append('nonce', nonce);
                    if(quoteId) fd.append('quote_id', quoteId);
                    fd.append('client_type', clientType);
                    fd.append('client_id', document.getElementById('clielo-newquote-client-id') ? document.getElementById('clielo-newquote-client-id').value : '');
                    fd.append('ext_client_id', document.getElementById('clielo-newquote-ext-id').value);
                    fd.append('quote_title', document.getElementById('clielo-newquote-title').value);
                    fd.append('validity_date', document.getElementById('clielo-newquote-validity').value);
                    fd.append('tax_rate', document.getElementById('clielo-newquote-taxrate').value);
                    fd.append('notes', document.getElementById('clielo-newquote-notes').value);
                    if(extraStatus) fd.append('new_status', extraStatus);
                    document.querySelectorAll('.clielo-qitem-row').forEach(function(row, i){
                        fd.append('items['+i+'][description]', row.querySelector('.clielo-qitem-desc').value);
                        fd.append('items['+i+'][quantity]', row.querySelector('.clielo-qitem-qty').value);
                        fd.append('items['+i+'][unit_price]', row.querySelector('.clielo-qitem-price').value);
                    });
                    return fd;
                }

                document.getElementById('clielo-newquote-save').addEventListener('click', function(){
                    var btn = this;
                    btn.disabled = true;
                    fetch(ajaxUrl,{method:'POST',body:buildFormData(null),credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        btn.disabled = false;
                        if(res.success){
                            window.location.href = '<?php echo esc_url( $redirect ); ?>';
                        } else {
                            alert(res.data && res.data.message ? res.data.message : 'Erreur');
                        }
                    });
                });

                <?php if ( $is_edit ) : ?>
                document.getElementById('clielo-newquote-send').addEventListener('click', function(){
                    var btn = this;
                    btn.disabled = true;
                    fetch(ajaxUrl,{method:'POST',body:buildFormData('pending'),credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        btn.disabled = false;
                        if(res.success){
                            window.location.href = '<?php echo esc_url( $redirect ); ?>';
                        } else {
                            alert(res.data && res.data.message ? res.data.message : 'Erreur');
                        }
                    });
                });
                <?php endif; ?>

                recalcQ();
            })();
            <?php
            wp_add_inline_script( 'clielo-invoices-js', ob_get_clean() );
            ?>
        </div>
        <?php
    }
}
