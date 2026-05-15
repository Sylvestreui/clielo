<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Payments {

    /* ================================================================
     *  Utilitaires statut paiement
     * ================================================================ */

    /**
     * Retourne les IDs d'échéancier payés pour une commande.
     */
    public static function get_paid_schedule_ids( int $order_id ): array {
        global $wpdb;
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids   = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT id FROM {$table} WHERE order_id = %d AND status = 'paid'",
            $order_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_map( 'intval', $ids ?: [] );
    }

    /**
     * Retourne quels IDs parmi la liste fournie sont payés.
     */
    public static function get_paid_by_ids( array $ids ): array {
        if ( empty( $ids ) ) {
            return [];
        }
        global $wpdb;
        $table        = self::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( "SELECT id FROM {$table} WHERE id IN ({$placeholders}) AND status = 'paid'", ...$ids ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders contains %d tokens built from count($ids).
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_map( 'intval', $results ?: [] );
    }

    /**
     * Retourne tous les IDs payés pour un post (toutes commandes confondues).
     */
    public static function get_paid_schedule_ids_for_post( int $post_id ): array {
        global $wpdb;
        $sched = self::table_name();
        $ord   = Clielo_Orders::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids   = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT s.id FROM {$sched} s
             INNER JOIN {$ord} o ON o.id = s.order_id
             WHERE o.post_id = %d AND s.status = 'paid'",
            $post_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_map( 'intval', $ids ?: [] );
    }

    /**
     * Retourne les IDs de schedules dont la session Stripe est expirée
     * (status = 'sent' ET sent_at > 24h) pour un post donné.
     */
    public static function get_expired_schedule_ids_for_post( int $post_id ): array {
        global $wpdb;
        $sched    = self::table_name();
        $ord      = Clielo_Orders::table_name();
        $deadline = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids      = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT s.id FROM {$sched} s
             INNER JOIN {$ord} o ON o.id = s.order_id
             WHERE o.post_id = %d AND s.status = 'sent' AND s.sent_at < %s",
            $post_id,
            $deadline
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_map( 'intval', $ids ?: [] );
    }

    /* ================================================================
     *  Cron — Envoi automatique à la date d'échéance
     * ================================================================ */

    public static function cron_send_due_links(): void {
        global $wpdb;
        $table = self::table_name();
        $today = gmdate( 'Y-m-d' );

        // 1. Envoyer les liens de paiement pour les échéances du jour
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $due_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT * FROM {$table} WHERE status = 'pending' AND due_date = %s AND type != 'deposit_balance'",
            $today
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ( $due_rows as $row ) {
            self::send_payment_link( (int) $row->id, 0 );
        }

        // 2. Envoyer les rappels N jours avant l'échéance (si activé)
        if ( class_exists( 'Clielo_Notifications' ) ) {
            $settings     = Clielo_Notifications::get_settings();
            $days_before  = (int) ( $settings['reminder_days_before'] ?? 3 );
            if ( $days_before > 0 && ( $settings['email_payment_reminder'] ?? '0' ) === '1' ) {
                $reminder_date = gmdate( 'Y-m-d', strtotime( "+{$days_before} days" ) );
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $reminder_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    "SELECT * FROM {$table} WHERE status = 'pending' AND due_date = %s AND type != 'deposit_balance' AND sent_at IS NULL",
                    $reminder_date
                ) );
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                foreach ( $reminder_rows as $row ) {
                    $order = Clielo_Orders::get_order( (int) $row->order_id );
                    if ( $order ) {
                        Clielo_Notifications::on_payment_reminder( $row, $order );
                    }
                }
            }
        }
    }

    public static function init(): void {
        if ( ! clielo_is_premium() ) {
            return;
        }
        add_action( 'wp_ajax_clielo_send_payment_link',        [ __CLASS__, 'ajax_send_payment_link' ] );
        add_action( 'wp_ajax_clielo_mark_payment_paid',        [ __CLASS__, 'ajax_mark_payment_paid' ] );
        add_action( 'wp_ajax_clielo_rebuild_schedule',         [ __CLASS__, 'ajax_rebuild_schedule' ] );
        add_action( 'wp_ajax_nopriv_clielo_schedule_check',    [ __CLASS__, 'ajax_schedule_check' ] );
        add_action( 'wp_ajax_clielo_schedule_check',           [ __CLASS__, 'ajax_schedule_check' ] );
        add_action( 'clielo_order_status_changed', [ __CLASS__, 'on_order_status_changed' ], 20, 4 );
        add_action( 'clielo_daily_payments', [ __CLASS__, 'cron_send_due_links' ] );
    }

    /* ================================================================
     *  Table
     * ================================================================ */

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'clielo_payment_schedule';
    }

    public static function create_table(): void {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            order_id        BIGINT UNSIGNED  NOT NULL,
            type            VARCHAR(30)      NOT NULL DEFAULT 'installment',
            installment_no  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            amount_ttc      DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
            status          VARCHAR(20)      NOT NULL DEFAULT 'pending',
            due_date        DATE             DEFAULT NULL,
            stripe_session_id VARCHAR(255)   DEFAULT NULL,
            checkout_url    TEXT             DEFAULT NULL,
            sent_at         DATETIME         DEFAULT NULL,
            paid_at         DATETIME         DEFAULT NULL,
            created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY status (status),
            KEY due_date (due_date)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ================================================================
     *  Calculs montants
     * ================================================================ */

    /**
     * Calcule le total TTC HT à partir des composantes de la commande.
     */
    public static function compute_total_ttc( array $base_offer, array $selected, int $extra_pages, float $extra_page_price, float $maintenance_price, int $express_days, float $express_price, float $tax_rate ): float {
        $ht = floatval( $base_offer['price'] ?? 0 );
        foreach ( $selected as $opt ) {
            $ht += floatval( $opt['price'] ?? 0 );
        }
        if ( $extra_pages > 0 && $extra_page_price > 0 ) {
            $ht += $extra_pages * $extra_page_price;
        }
        if ( $maintenance_price > 0 ) {
            $ht += $maintenance_price;
        }
        if ( $express_days > 0 && $express_price > 0 ) {
            $ht += $express_days * $express_price;
        }
        return round( $ht * ( 1 + $tax_rate / 100 ), 2 );
    }

    /**
     * Retourne le ratio acompte selon le mode.
     * deposit: 0.50 | installments: 0.40 | single: 1.0
     */
    public static function get_upfront_ratio( string $mode ): float {
        return match ( $mode ) {
            'deposit'      => 0.50,
            'installments' => 0.40,
            'monthly'      => 0.0, // upfront = 1 mois, calculé séparément via get_monthly_fee()
            default        => 1.0,
        };
    }

    /**
     * Retourne le montant upfront en euros (arrondi au centime supérieur).
     * Pour le mode 'monthly', utiliser get_monthly_fee() à la place.
     */
    public static function get_upfront_amount( float $total_ttc, string $mode ): float {
        return round( $total_ttc * self::get_upfront_ratio( $mode ), 2 );
    }

    /**
     * Pour le mode 'monthly' : retourne le tarif mensuel (= total / N mois).
     */
    public static function get_monthly_fee( float $total_ttc, int $months ): float {
        if ( $months <= 0 ) {
            return $total_ttc;
        }
        return round( $total_ttc / $months, 2 );
    }

    /* ================================================================
     *  Création de l'échéancier
     * ================================================================ */

    /**
     * Appelé juste après create_order_paid(). Crée les lignes de l'échéancier.
     */
    public static function create_schedule_for_order( int $order_id ): void {
        global $wpdb;
        $order = Clielo_Orders::get_order( $order_id );
        if ( ! $order || $order->payment_mode === 'single' ) {
            return;
        }

        $table = self::table_name();
        $mode  = $order->payment_mode;
        $total = floatval( $order->total_price );
        $now   = current_time( 'mysql' );

        if ( $mode === 'monthly' ) {
            // Abonnement mensuel pur : N mensualités égales, la 1re (déjà payée) + N-1 à envoyer
            $n          = max( 1, (int) $order->installments_count );
            $per_month  = floor( $total / $n * 100 ) / 100;
            $last_amount = round( $total - $per_month * ( $n - 1 ), 2 );

            for ( $i = 1; $i <= $n; $i++ ) {
                $amount   = ( $i === $n ) ? $last_amount : $per_month;
                $due_date = gmdate( 'Y-m-d', strtotime( '+' . ( $i - 1 ) . ' month', strtotime( gmdate( 'Y-m-01' ) ) ) );
                $wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    'order_id'       => $order_id,
                    'type'           => 'installment',
                    'installment_no' => $i,
                    'amount_ttc'     => $amount,
                    'status'         => $i === 1 ? 'paid' : 'pending',
                    'due_date'       => $due_date,
                    'paid_at'        => $i === 1 ? $now : null,
                    'created_at'     => $now,
                ] );
            }
            return;
        }

        // Pour deposit et installments : ligne upfront déjà payée
        $upfront   = self::get_upfront_amount( $total, $mode );
        $remaining = round( $total - $upfront, 2 );

        $wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'order_id'       => $order_id,
            'type'           => 'upfront',
            'installment_no' => 0,
            'amount_ttc'     => $upfront,
            'status'         => 'paid',
            'paid_at'        => $now,
            'created_at'     => $now,
        ] );

        if ( $mode === 'deposit' ) {
            $wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                'order_id'       => $order_id,
                'type'           => 'deposit_balance',
                'installment_no' => 1,
                'amount_ttc'     => $remaining,
                'status'         => 'pending',
                'created_at'     => $now,
            ] );

        } elseif ( $mode === 'installments' ) {
            $n           = max( 1, (int) $order->installments_count );
            $per_month   = floor( $remaining / $n * 100 ) / 100;
            $last_amount = round( $remaining - $per_month * ( $n - 1 ), 2 );

            for ( $i = 1; $i <= $n; $i++ ) {
                $amount   = ( $i === $n ) ? $last_amount : $per_month;
                $due_date = gmdate( 'Y-m-d', strtotime( '+' . $i . ' month', strtotime( gmdate( 'Y-m-01' ) ) ) );
                $wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    'order_id'       => $order_id,
                    'type'           => 'installment',
                    'installment_no' => $i,
                    'amount_ttc'     => $amount,
                    'status'         => 'pending',
                    'due_date'       => $due_date,
                    'created_at'     => $now,
                ] );
            }
        }
    }

    /* ================================================================
     *  Migration
     * ================================================================ */

    /**
     * Backfill due_date pour les lignes existantes créées avant v1.6.3.
     * Calcule la date à partir du created_at de la commande + installment_no.
     */
    public static function migrate_due_dates(): void {
        global $wpdb;
        $table = self::table_name();
        $ord   = Clielo_Orders::table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT s.id, s.installment_no, o.payment_mode, o.created_at
             FROM {$table} s
             INNER JOIN {$ord} o ON o.id = s.order_id
             WHERE s.due_date IS NULL AND s.status = 'pending' AND s.type != 'deposit_balance'"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        foreach ( $rows as $row ) {
            $base   = strtotime( gmdate( 'Y-m-01', strtotime( $row->created_at ) ) );
            $offset = (int) $row->installment_no;
            // mode monthly : installment_no commence à 1 (1er mois déjà payé), donc offset = no - 1
            if ( $row->payment_mode === 'monthly' ) {
                $offset = max( 0, $offset - 1 );
            }
            $due_date = gmdate( 'Y-m-d', strtotime( "+{$offset} month", $base ) );
            $wpdb->update( $table, [ 'due_date' => $due_date ], [ 'id' => (int) $row->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        }
    }

    /* ================================================================
     *  Lecture
     * ================================================================ */

    public static function get_schedule_for_order( int $order_id ): array {
        global $wpdb;
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT * FROM {$table} WHERE order_id = %d ORDER BY installment_no ASC",
            $order_id
        ) ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function get_row( int $id ): ?object {
        global $wpdb;
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /* ================================================================
     *  Envoi d'un lien de paiement
     * ================================================================ */

    /**
     * Crée une session Stripe Checkout pour le montant de la ligne, envoie l'URL dans le chat.
     */
    public static function send_payment_link( int $schedule_id, int $acting_user_id ): bool {
        global $wpdb;

        $row = self::get_row( $schedule_id );
        if ( ! $row || $row->status === 'paid' ) {
            return false;
        }

        $order = Clielo_Orders::get_order( (int) $row->order_id );
        if ( ! $order ) {
            return false;
        }

        // Créer une session Stripe Checkout pour ce montant
        try {
            $stripe   = Clielo_Stripe::stripe();
            $settings = Clielo_Stripe::get_settings();
            $currency = $settings['currency'];
            $label    = self::get_type_label( $row->type, (int) $row->installment_no );

            // Construire les lignes détaillées (pack + options proportionnels au montant de cette échéance)
            $base_offer       = json_decode( $order->base_offer ?? '', true ) ?: [];
            $selected_options = json_decode( $order->selected_options ?? '', true ) ?: [];
            $adv_data         = json_decode( $order->advanced_options_data ?? '', true ) ?: [];
            $tax_rate         = floatval( Clielo_Invoices::get_settings()['tax_rate'] ?? 0 );
            $line_items = Clielo_Stripe::build_line_items(
                $base_offer,
                $selected_options,
                $currency,
                (int) ( $order->extra_pages ?? 0 ),
                floatval( $order->extra_page_price ?? 0 ),
                floatval( $order->maintenance_price ?? 0 ),
                (int) ( $order->express_days ?? 0 ),
                floatval( $order->express_price ?? 0 ),
                $tax_rate,
                (int) $order->post_id,
                $adv_data,
                floatval( $row->amount_ttc ),
                $label
            );

            $session = $stripe->checkout->sessions->create( [
                'mode'          => 'payment',
                'customer_email' => ( ( $u = get_userdata( (int) $order->client_id ) ) ? $u->user_email : '' ),
                'line_items'    => $line_items,
                'metadata' => [
                    'sf_schedule_id' => $schedule_id,
                    'order_id'       => $row->order_id,
                ],
                'success_url' => add_query_arg( [
                    'sf_payment_success' => '1',
                    'schedule_id'        => $schedule_id,
                ], get_permalink( (int) $order->post_id ) ),
                'cancel_url' => get_permalink( (int) $order->post_id ),
            ] );

            $checkout_url = $session->url;

            // Mettre à jour la ligne
            $table = self::table_name();
            $wpdb->update( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                'status'            => 'sent',
                'stripe_session_id' => $session->id,
                'checkout_url'      => $checkout_url,
                'sent_at'           => current_time( 'mysql' ),
            ], [ 'id' => $schedule_id ] );

            // Envoyer l'URL dans le chat (le renderer convertit l'URL en bouton cliquable)
            // Le marker [SF_SCHED:{id}] permet au JS de lier le bouton au statut de paiement
            $amount_fmt = number_format( floatval( $row->amount_ttc ), 2, ',', ' ' );
            $msg  = sprintf( "[SF_SCHED:%d]\n", $schedule_id );
            $msg .= sprintf( "--- %s ---\n", $label );
            /* translators: %s: formatted payment amount */
            $msg .= sprintf( __( '💳 Montant : %s €', 'clielo' ), $amount_fmt ) . "\n";
            $msg .= $checkout_url;

            Clielo_DB::insert_message( (int) $order->post_id, 0, $msg, (int) $order->client_id );

            // Email au client avec le lien de paiement
            if ( class_exists( 'Clielo_Notifications' ) ) {
                Clielo_Notifications::on_payment_link_sent( $row, $order, $checkout_url );
            }

            return true;

        } catch ( \Exception $e ) {
            return false;
        }
    }

    /* ================================================================
     *  Hook : acompte → envoi automatique du solde quand le client accepte
     * ================================================================ */

    public static function on_order_status_changed( int $order_id, string $new_status, string $old_status, int $acting_user_id ): void {
        // Le solde est envoyé quand le client accepte la livraison (avec ou sans retouche)
        if ( $new_status !== Clielo_Orders::STATUS_ACCEPTED ) {
            return;
        }
        // Ne rien faire si Stripe n'est pas configuré : l'admin gère le solde manuellement
        if ( ! class_exists( 'Clielo_Stripe' ) || ! Clielo_Stripe::is_enabled() ) {
            return;
        }
        $order = Clielo_Orders::get_order( $order_id );
        if ( ! $order || $order->payment_mode !== 'deposit' ) {
            return;
        }

        // Rattrapage : si l'échéancier n'a jamais été créé (migration tardive), le créer maintenant
        $schedule = self::get_schedule_for_order( $order_id );
        if ( empty( $schedule ) ) {
            self::create_schedule_for_order( $order_id );
            $schedule = self::get_schedule_for_order( $order_id );
        }

        // Envoyer le lien de solde
        foreach ( $schedule as $row ) {
            if ( $row->type === 'deposit_balance' && $row->status === 'pending' ) {
                self::send_payment_link( (int) $row->id, $acting_user_id );
                break;
            }
        }
    }

    /* ================================================================
     *  AJAX — Admin envoie une mensualité
     * ================================================================ */

    public static function ajax_send_payment_link(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        $schedule_id = absint( $_POST['schedule_id'] ?? 0 );
        if ( ! $schedule_id ) {
            wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'clielo' ) ], 400 );
        }

        $ok = self::send_payment_link( $schedule_id, get_current_user_id() );
        if ( $ok ) {
            wp_send_json_success( [ 'message' => __( 'Lien envoyé dans le chat.', 'clielo' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de l\'envoi.', 'clielo' ) ], 500 );
        }
    }

    /* ================================================================
     *  AJAX — Vérification statut paiement (retour Stripe success URL)
     * ================================================================ */

    public static function ajax_schedule_check(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );

        $schedule_id = absint( $_GET['schedule_id'] ?? 0 );
        if ( ! $schedule_id ) {
            wp_send_json_error( [], 400 );
        }

        $row = self::get_row( $schedule_id );
        if ( ! $row ) {
            wp_send_json_error( [], 404 );
        }

        // Si déjà payé en DB → ok
        if ( $row->status === 'paid' ) {
            wp_send_json_success( [ 'paid' => true ] );
        }

        // Sinon : vérifier via Stripe API si la session est payée
        if ( ! empty( $row->stripe_session_id ) ) {
            try {
                $session = Clielo_Stripe::stripe()->checkout->sessions->retrieve( $row->stripe_session_id );
                if ( $session->payment_status === 'paid' ) {
                    Clielo_Stripe::process_completed_session( $session );
                    wp_send_json_success( [ 'paid' => true ] );
                }
            } catch ( \Exception $e ) {
                // Session expirée ou invalide
            }
        }

        wp_send_json_success( [ 'paid' => false ] );
    }

    /* ================================================================
     *  AJAX — Marquer manuellement une mensualité comme payée
     * ================================================================ */

    public static function ajax_mark_payment_paid(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'clielo' ) ], 403 );
        }

        $schedule_id = absint( $_POST['schedule_id'] ?? 0 );
        if ( ! $schedule_id ) {
            wp_send_json_error( [], 400 );
        }

        $row = self::get_row( $schedule_id );
        if ( ! $row || $row->status === 'paid' ) {
            wp_send_json_error( [ 'message' => __( 'Ligne introuvable ou déjà payée.', 'clielo' ) ], 400 );
        }

        global $wpdb;
        $wpdb->update( self::table_name(), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'status'  => 'paid',
            'paid_at' => current_time( 'mysql' ),
        ], [ 'id' => $schedule_id ] );

        // Créer la facture partielle correspondante
        if ( class_exists( 'Clielo_Invoices' ) ) {
            $type = match ( $row->type ) {
                'deposit_balance' => 'solde',
                'installment'     => 'mensualite',
                default           => 'solde',
            };
            Clielo_Invoices::create_partial_invoice(
                (int) $row->order_id,
                floatval( $row->amount_ttc ),
                $type,
                $schedule_id,
                (int) $row->installment_no
            );
        }

        wp_send_json_success();
    }

    /* ================================================================
     *  AJAX — Recréer l'échéancier (rattrapage migration)
     * ================================================================ */

    public static function ajax_rebuild_schedule(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [], 403 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [], 400 );
        }

        // Ne recréer que si vraiment vide
        $existing = self::get_schedule_for_order( $order_id );
        if ( ! empty( $existing ) ) {
            wp_send_json_error( [ 'message' => __( 'L\'échéancier existe déjà.', 'clielo' ) ], 400 );
        }

        self::create_schedule_for_order( $order_id );

        // Si le client a déjà accepté la livraison et mode deposit → envoyer le solde immédiatement
        $order = Clielo_Orders::get_order( $order_id );
        if ( $order && $order->payment_mode === 'deposit' && $order->status === Clielo_Orders::STATUS_ACCEPTED ) {
            $schedule = self::get_schedule_for_order( $order_id );
            foreach ( $schedule as $row ) {
                if ( $row->type === 'deposit_balance' && $row->status === 'pending' ) {
                    self::send_payment_link( (int) $row->id, get_current_user_id() );
                    break;
                }
            }
        }

        wp_send_json_success();
    }

    /* ================================================================
     *  Rendu — Bloc échéancier (page compte admin + client)
     * ================================================================ */

    public static function render_schedule_block( int $order_id, string $color, bool $is_admin ): string {
        $schedule = self::get_schedule_for_order( $order_id );

        // Rattrapage admin : si l'échéancier est vide, proposer de le (re)créer
        if ( empty( $schedule ) ) {
            if ( ! $is_admin ) {
                return '';
            }
            ob_start();
            ?>
            <div style="margin-top:12px !important;border-top:1px solid #e5e7eb !important;padding-top:12px !important">
                <div style="font-size:11px !important;color:#888 !important;margin-bottom:8px !important">
                    <?php esc_html_e( 'Échéancier introuvable pour cette commande.', 'clielo' ); ?>
                </div>
                <button class="sf-rebuild-schedule" data-order-id="<?php echo (int) $order_id; ?>"
                    style="padding:4px 10px !important;border:none !important;border-radius:6px !important;font-size:11px !important;font-weight:600 !important;color:#fff !important;background:<?php echo esc_attr( $color ); ?> !important;cursor:pointer !important">
                    <?php esc_html_e( 'Recréer l\'échéancier', 'clielo' ); ?>
                </button>
            </div>
            <?php
            return ob_get_clean();
        }

        $status_labels = [
            'pending' => __( 'En attente', 'clielo' ),
            'sent'    => __( 'Envoyé', 'clielo' ),
            'paid'    => __( 'Payé', 'clielo' ),
        ];
        $status_colors = [
            'pending' => '#f59e0b',
            'sent'    => '#3b82f6',
            'paid'    => '#10b981',
        ];

        ob_start();
        ?>
        <div style="margin-top:12px !important;border-top:1px solid #e5e7eb !important;padding-top:12px !important">
            <div style="font-size:12px !important;font-weight:700 !important;color:#555 !important;margin-bottom:8px !important;text-transform:uppercase !important;letter-spacing:.5px !important">
                <?php esc_html_e( 'Échéancier', 'clielo' ); ?>
            </div>
            <?php foreach ( $schedule as $row ) :
                $s_label = $status_labels[ $row->status ] ?? $row->status;
                $s_color = $status_colors[ $row->status ] ?? '#888';
                $type_label = self::get_type_label( $row->type, (int) $row->installment_no );
                ?>
                <div style="display:flex !important;justify-content:space-between !important;align-items:center !important;padding:6px 0 !important;border-bottom:1px solid #f3f4f6 !important;gap:8px !important;flex-wrap:wrap !important">
                    <div style="flex:1 !important;min-width:0 !important">
                        <div style="font-size:12px !important;font-weight:600 !important;color:#333 !important"><?php echo esc_html( $type_label ); ?></div>
                        <div style="font-size:11px !important;color:#888 !important"><?php echo esc_html( number_format( floatval( $row->amount_ttc ), 2, ',', ' ' ) ); ?> €</div>
                    </div>
                    <div style="display:flex !important;align-items:center !important;gap:6px !important">
                        <span style="display:inline-block !important;padding:2px 8px !important;border-radius:10px !important;font-size:10px !important;font-weight:600 !important;color:#fff !important;background:<?php echo esc_attr( $s_color ); ?> !important">
                            <?php echo esc_html( $s_label ); ?>
                        </span>
                        <?php if ( $is_admin && $row->status !== 'paid' ) : ?>
                            <?php if ( $row->type !== 'upfront' ) : ?>
                                <button
                                    class="sf-send-payment-link"
                                    data-schedule-id="<?php echo (int) $row->id; ?>"
                                    style="padding:3px 8px !important;border:none !important;border-radius:5px !important;font-size:10px !important;font-weight:600 !important;color:#fff !important;background:<?php echo esc_attr( $color ); ?> !important;cursor:pointer !important">
                                    <?php echo $row->status === 'sent'
                                        ? esc_html__( 'Renvoyer', 'clielo' )
                                        : esc_html__( 'Envoyer lien', 'clielo' ); ?>
                                </button>
                                <button
                                    class="sf-mark-payment-paid"
                                    data-schedule-id="<?php echo (int) $row->id; ?>"
                                    style="padding:3px 8px !important;border:none !important;border-radius:5px !important;font-size:10px !important;font-weight:600 !important;color:#fff !important;background:#10b981 !important;cursor:pointer !important">
                                    <?php esc_html_e( '✓ Payé', 'clielo' ); ?>
                                </button>
                            <?php endif; ?>
                        <?php elseif ( ! $is_admin && $row->status === 'sent' && ! empty( $row->checkout_url ) ) : ?>
                            <a href="<?php echo esc_url( $row->checkout_url ); ?>"
                               style="padding:3px 8px !important;border-radius:5px !important;font-size:10px !important;font-weight:600 !important;color:#fff !important;background:<?php echo esc_attr( $color ); ?> !important;text-decoration:none !important;display:inline-block !important">
                                <?php esc_html_e( 'Payer', 'clielo' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
     *  Utilitaires
     * ================================================================ */

    public static function get_type_label( string $type, int $installment_no = 0 ): string {
        return match ( $type ) {
            'upfront'         => __( 'Acompte versé', 'clielo' ),
            'deposit_balance' => __( 'Solde à régler', 'clielo' ),
            /* translators: %d: installment number */
            'installment'     => sprintf( __( 'Mensualité %d', 'clielo' ), $installment_no ),
            default           => ucfirst( $type ),
        };
    }
}
