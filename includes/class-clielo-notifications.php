<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Notifications {

    private static int $instance = 0;

    public static function init(): void {
        add_shortcode( 'clielo_notifications', [ __CLASS__, 'shortcode_bell' ] );

        add_action( 'wp_ajax_clielo_notif_count',    [ __CLASS__, 'ajax_count' ] );
        add_action( 'wp_ajax_clielo_notif_list',      [ __CLASS__, 'ajax_list' ] );
        add_action( 'wp_ajax_clielo_notif_read_all',  [ __CLASS__, 'ajax_read_all' ] );

        // Hooks sur les événements du plugin (in-app notifications = free, emails = premium)
        add_action( 'clielo_message_sent',         [ __CLASS__, 'on_message_sent' ], 10, 4 );
        add_action( 'clielo_order_created',         [ __CLASS__, 'on_order_created' ], 10, 3 );
        add_action( 'clielo_order_status_changed',  [ __CLASS__, 'on_order_status_changed' ], 10, 4 );

        add_action( 'wp_enqueue_scripts',    [ __CLASS__, 'enqueue_frontend_assets' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );

        if ( clielo_is_premium() ) {
            add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
            add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
            add_action( 'wp_ajax_clielo_preview_email', [ __CLASS__, 'ajax_preview_email' ] );
        }
    }

    public static function enqueue_frontend_assets(): void {
        if ( ! wp_script_is( 'clielo-notif-js', 'registered' ) ) {
            wp_register_script( 'clielo-notif-js', false, [ 'jquery' ], CLIELO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }
        wp_enqueue_script( 'clielo-notif-js' );
    }

    public static function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'clielo-notifications' ) === false ) {
            return;
        }
        if ( ! wp_script_is( 'clielo-notif-admin-js', 'registered' ) ) {
            wp_register_script( 'clielo-notif-admin-js', false, [ 'jquery' ], CLIELO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }
        wp_enqueue_script( 'clielo-notif-admin-js' );

        $color = esc_attr( Clielo_Admin::get_color() );
        wp_add_inline_style(
            'wp-admin',
            '.clielo-notif-section{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:24px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)}' .
            '.clielo-notif-section h2{font-size:15px;font-weight:700;color:#222;margin:0 0 16px 0;padding:0 0 12px 0;border-bottom:1px solid #eee;display:flex;align-items:center;gap:8px}' .
            '.clielo-notif-row{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid #f5f5f5}' .
            '.clielo-notif-row:last-child{border-bottom:none}' .
            '.clielo-notif-row-label{font-size:14px;color:#333;font-weight:500}' .
            '.clielo-notif-row-desc{font-size:12px;color:#888;margin-top:2px}' .
            '.clielo-toggle-sw{position:relative;width:44px;height:24px;flex-shrink:0}' .
            '.clielo-toggle-sw input{opacity:0;width:0;height:0;position:absolute}' .
            '.clielo-toggle-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#ccc;border-radius:24px;transition:.3s}' .
            '.clielo-toggle-slider:before{content:"";position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}' .
            '.clielo-toggle-sw input:checked + .clielo-toggle-slider{background:' . $color . '}' .
            '.clielo-toggle-sw input:checked + .clielo-toggle-slider:before{transform:translateX(20px)}' .
            '.clielo-notif-input{width:100%;max-width:400px;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px}' .
            '.clielo-notif-input:focus{border-color:' . $color . ';outline:none;box-shadow:0 0 0 2px ' . $color . '33}' .
            '.clielo-notif-field{margin-bottom:16px}' .
            '.clielo-notif-field:last-child{margin-bottom:0}' .
            '.clielo-notif-field label{display:block;font-size:13px;font-weight:600;color:#444;margin-bottom:6px}' .
            '.clielo-tpl-block{background:#fafafa;border:1px solid #eee;border-radius:6px;padding:16px;margin-bottom:16px}' .
            '.clielo-tpl-block:last-child{margin-bottom:0}' .
            '.clielo-tpl-block h3{margin:0 0 12px 0;font-size:14px;font-weight:600;color:#333}' .
            '.clielo-tpl-block label{display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:4px}' .
            '.clielo-tpl-block input[type="text"],.clielo-tpl-block textarea{width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-sizing:border-box}' .
            '.clielo-tpl-block input[type="text"]:focus,.clielo-tpl-block textarea:focus{border-color:' . $color . ';outline:none;box-shadow:0 0 0 2px ' . $color . '33}' .
            '.clielo-tpl-block textarea{min-height:80px;resize:vertical}' .
            '.clielo-tpl-vars{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:12px}' .
            '.clielo-tpl-var{display:inline-block;background:' . $color . '15;color:' . $color . ';font-size:11px;font-weight:600;padding:2px 8px;border-radius:4px;font-family:monospace;cursor:pointer}' .
            '.clielo-tpl-var:hover{background:' . $color . '25}' .
            '.clielo-tpl-field{margin-bottom:10px}' .
            '.clielo-tpl-field:last-child{margin-bottom:0}'
        );
    }

    /* ================================================================
     *  TABLE
     * ================================================================ */

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'clielo_notifications';
    }

    public static function create_table(): void {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(30) NOT NULL,
            ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sender_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            data TEXT,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_read (user_id, is_read),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ================================================================
     *  SETTINGS
     * ================================================================ */

    public static function get_settings(): array {
        $defaults = [
            'email_new_message'       => '1',
            'email_new_order'         => '1',
            'email_order_status'      => '1',
            'email_payment_link'      => '1',
            'email_payment_reminder'  => '1',
            'reminder_days_before'    => '3',
            'sender_name'             => get_bloginfo( 'name' ),
            'sender_email'            => get_bloginfo( 'admin_email' ),
            // Templates — sujets
            'tpl_subject_new_message'      => 'Nouveau message de {sender_name} — {service_name}',
            'tpl_subject_new_order'        => 'Nouvelle commande sur {service_name}',
            'tpl_subject_order_status'     => 'Commande {order_number} — Statut : {status_label}',
            'tpl_subject_payment_link'     => '💳 {installment_label} à régler — {service_name}',
            'tpl_subject_payment_reminder' => '⏰ Rappel de paiement — {service_name}',
            // Templates — corps
            'tpl_body_new_message'     => "{sender_name} vous a envoyé un message concernant le service « {service_name} ».\n\n« {excerpt} »",
            'tpl_body_new_order'       => "Une nouvelle commande a été passée par {client_name} sur le service « {service_name} ».\n\nMontant : {total_price} €\nRéférence : {order_number}",
            'tpl_body_order_status'    => "Le statut de votre commande {order_number} sur « {service_name} » a été mis à jour.\n\nNouveau statut : {status_label}",
            'tpl_body_payment_link'    => "Votre {installment_label} de {amount} € pour le service « {service_name} » est disponible.\n\nCliquez sur le bouton ci-dessous pour procéder au règlement.",
            'tpl_body_payment_reminder' => "Rappel : votre {installment_label} de {amount} € pour le service « {service_name} » (commande {order_number}) est due le {due_date}.\n\nMerci de procéder au règlement.",
        ];
        $saved = get_option( 'clielo_notif_settings', [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        return wp_parse_args( $saved, $defaults );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'clielo',
            __( 'Notifications', 'clielo' ),
            __( 'Notifications', 'clielo' ),
            'manage_options',
            'clielo-notifications',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( 'clielo_notif_settings', 'clielo_notif_settings', [
            'type'              => 'array',
            'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
        ] );
    }

    public static function sanitize_settings( $input ): array {
        if ( ! is_array( $input ) ) {
            $input = [];
        }
        return [
            'email_new_message'            => ! empty( $input['email_new_message'] ) ? '1' : '0',
            'email_new_order'              => ! empty( $input['email_new_order'] ) ? '1' : '0',
            'email_order_status'           => ! empty( $input['email_order_status'] ) ? '1' : '0',
            'email_payment_link'           => ! empty( $input['email_payment_link'] ) ? '1' : '0',
            'email_payment_reminder'       => ! empty( $input['email_payment_reminder'] ) ? '1' : '0',
            'reminder_days_before'         => max( 0, (int) ( $input['reminder_days_before'] ?? 3 ) ),
            'sender_name'                  => sanitize_text_field( $input['sender_name'] ?? '' ),
            'sender_email'                 => sanitize_email( $input['sender_email'] ?? '' ),
            'tpl_subject_new_message'      => sanitize_text_field( $input['tpl_subject_new_message'] ?? '' ),
            'tpl_subject_new_order'        => sanitize_text_field( $input['tpl_subject_new_order'] ?? '' ),
            'tpl_subject_order_status'     => sanitize_text_field( $input['tpl_subject_order_status'] ?? '' ),
            'tpl_subject_payment_link'     => sanitize_text_field( $input['tpl_subject_payment_link'] ?? '' ),
            'tpl_subject_payment_reminder' => sanitize_text_field( $input['tpl_subject_payment_reminder'] ?? '' ),
            'tpl_body_new_message'         => sanitize_textarea_field( $input['tpl_body_new_message'] ?? '' ),
            'tpl_body_new_order'           => sanitize_textarea_field( $input['tpl_body_new_order'] ?? '' ),
            'tpl_body_order_status'        => sanitize_textarea_field( $input['tpl_body_order_status'] ?? '' ),
            'tpl_body_payment_link'        => sanitize_textarea_field( $input['tpl_body_payment_link'] ?? '' ),
            'tpl_body_payment_reminder'    => sanitize_textarea_field( $input['tpl_body_payment_reminder'] ?? '' ),
        ];
    }

    /* ================================================================
     *  ADMIN PAGE — Notifications settings
     * ================================================================ */

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = self::get_settings();
        $color    = Clielo_Admin::get_color();
        ?>
        <div class="wrap clielo-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-bell" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php esc_html_e( 'Clielo — Notifications', 'clielo' ); ?>
            </h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'clielo_notif_settings' ); ?>


                <!-- Section Expéditeur -->
                <div class="clielo-notif-section">
                    <h2>
                        <span class="dashicons dashicons-email" style="color:<?php echo esc_attr( $color ); ?>"></span>
                        <?php esc_html_e( 'Expéditeur des emails', 'clielo' ); ?>
                    </h2>

                    <div class="clielo-notif-field">
                        <label for="clielo_sender_name"><?php esc_html_e( 'Nom de l\'expéditeur', 'clielo' ); ?></label>
                        <input type="text" id="clielo_sender_name"
                               name="clielo_notif_settings[sender_name]"
                               value="<?php echo esc_attr( $settings['sender_name'] ); ?>"
                               class="clielo-notif-input"
                               placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                    </div>

                    <div class="clielo-notif-field">
                        <label for="clielo_sender_email"><?php esc_html_e( 'Adresse email de l\'expéditeur', 'clielo' ); ?></label>
                        <input type="email" id="clielo_sender_email"
                               name="clielo_notif_settings[sender_email]"
                               value="<?php echo esc_attr( $settings['sender_email'] ); ?>"
                               class="clielo-notif-input"
                               placeholder="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>">
                    </div>
                </div>

                <!-- Section Notifications par email -->
                <div class="clielo-notif-section">
                    <h2>
                        <span class="dashicons dashicons-bell" style="color:<?php echo esc_attr( $color ); ?>"></span>
                        <?php esc_html_e( 'Notifications par email', 'clielo' ); ?>
                    </h2>

                    <div class="clielo-notif-row">
                        <div>
                            <div class="clielo-notif-row-label"><?php esc_html_e( 'Nouveau message', 'clielo' ); ?></div>
                            <div class="clielo-notif-row-desc"><?php esc_html_e( 'Envoyer un email lorsqu\'un nouveau message est reçu dans le chat.', 'clielo' ); ?></div>
                        </div>
                        <label class="clielo-toggle-sw">
                            <input type="checkbox" name="clielo_notif_settings[email_new_message]" value="1" <?php checked( $settings['email_new_message'], '1' ); ?>>
                            <span class="clielo-toggle-slider"></span>
                        </label>
                    </div>

                    <div class="clielo-notif-row">
                        <div>
                            <div class="clielo-notif-row-label"><?php esc_html_e( 'Nouvelle commande', 'clielo' ); ?></div>
                            <div class="clielo-notif-row-desc"><?php esc_html_e( 'Envoyer un email à l\'administrateur lorsqu\'une nouvelle commande est passée.', 'clielo' ); ?></div>
                        </div>
                        <label class="clielo-toggle-sw">
                            <input type="checkbox" name="clielo_notif_settings[email_new_order]" value="1" <?php checked( $settings['email_new_order'], '1' ); ?>>
                            <span class="clielo-toggle-slider"></span>
                        </label>
                    </div>

                    <div class="clielo-notif-row">
                        <div>
                            <div class="clielo-notif-row-label"><?php esc_html_e( 'Changement de statut de commande', 'clielo' ); ?></div>
                            <div class="clielo-notif-row-desc"><?php esc_html_e( 'Envoyer un email lorsque le statut d\'une commande change (démarrée, terminée, retouche, etc.).', 'clielo' ); ?></div>
                        </div>
                        <label class="clielo-toggle-sw">
                            <input type="checkbox" name="clielo_notif_settings[email_order_status]" value="1" <?php checked( $settings['email_order_status'], '1' ); ?>>
                            <span class="clielo-toggle-slider"></span>
                        </label>
                    </div>

                    <div class="clielo-notif-row">
                        <div>
                            <div class="clielo-notif-row-label"><?php esc_html_e( 'Lien de paiement mensualité', 'clielo' ); ?></div>
                            <div class="clielo-notif-row-desc"><?php esc_html_e( 'Envoyer un email au client avec le lien de paiement Stripe lorsqu\'une mensualité est envoyée.', 'clielo' ); ?></div>
                        </div>
                        <label class="clielo-toggle-sw">
                            <input type="checkbox" name="clielo_notif_settings[email_payment_link]" value="1" <?php checked( $settings['email_payment_link'], '1' ); ?>>
                            <span class="clielo-toggle-slider"></span>
                        </label>
                    </div>

                    <div class="clielo-notif-row">
                        <div>
                            <div class="clielo-notif-row-label"><?php esc_html_e( 'Rappel de paiement', 'clielo' ); ?></div>
                            <div class="clielo-notif-row-desc">
                                <?php esc_html_e( 'Envoyer un rappel email avant la date d\'échéance d\'une mensualité.', 'clielo' ); ?>
                                <br>
                                <label style="margin-top:6px;display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#555">
                                    <?php esc_html_e( 'Jours avant échéance :', 'clielo' ); ?>
                                    <input type="number" name="clielo_notif_settings[reminder_days_before]"
                                           value="<?php echo (int) $settings['reminder_days_before']; ?>"
                                           min="0" max="30"
                                           style="width:60px;padding:2px 6px;border:1px solid #ddd;border-radius:4px;font-size:12px">
                                </label>
                            </div>
                        </div>
                        <label class="clielo-toggle-sw">
                            <input type="checkbox" name="clielo_notif_settings[email_payment_reminder]" value="1" <?php checked( $settings['email_payment_reminder'], '1' ); ?>>
                            <span class="clielo-toggle-slider"></span>
                        </label>
                    </div>
                </div>

                <!-- Section Templates d'emails -->
                <div class="clielo-notif-section">
                    <h2>
                        <span class="dashicons dashicons-edit" style="color:<?php echo esc_attr( $color ); ?>"></span>
                        <?php esc_html_e( 'Templates d\'emails', 'clielo' ); ?>
                    </h2>
                    <p style="margin:0 0 16px 0;font-size:13px;color:#666;line-height:1.6">
                        <?php esc_html_e( 'Personnalisez le contenu des emails envoyés automatiquement. Utilisez les variables entre accolades pour insérer des données dynamiques.', 'clielo' ); ?>
                    </p>


                    <!-- Template : Nouveau message -->
                    <div class="clielo-tpl-block" data-email-type="new_message">
                        <h3><?php esc_html_e( 'Nouveau message', 'clielo' ); ?></h3>
                        <div class="clielo-tpl-vars">
                            <span class="clielo-tpl-var" data-var="{sender_name}">{sender_name}</span>
                            <span class="clielo-tpl-var" data-var="{service_name}">{service_name}</span>
                            <span class="clielo-tpl-var" data-var="{excerpt}">{excerpt}</span>
                            <span class="clielo-tpl-var" data-var="{recipient_name}">{recipient_name}</span>
                        </div>
                        <div class="clielo-tpl-field">
                            <label><?php esc_html_e( 'Sujet', 'clielo' ); ?></label>
                            <input type="text" name="clielo_notif_settings[tpl_subject_new_message]" value="<?php echo esc_attr( $settings['tpl_subject_new_message'] ); ?>">
                        </div>
                        <div class="clielo-tpl-field">
                            <label><?php esc_html_e( 'Corps du message', 'clielo' ); ?></label>
                            <textarea name="clielo_notif_settings[tpl_body_new_message]"><?php echo esc_textarea( $settings['tpl_body_new_message'] ); ?></textarea>
                        </div>
                    </div>

                    <!-- Template : Nouvelle commande -->
                    <div class="clielo-tpl-block" data-email-type="new_order">
                        <h3><?php esc_html_e( 'Nouvelle commande', 'clielo' ); ?></h3>
                        <div class="clielo-tpl-vars">
                            <span class="clielo-tpl-var" data-var="{client_name}">{client_name}</span>
                            <span class="clielo-tpl-var" data-var="{service_name}">{service_name}</span>
                            <span class="clielo-tpl-var" data-var="{total_price}">{total_price}</span>
                            <span class="clielo-tpl-var" data-var="{order_number}">{order_number}</span>
                            <span class="clielo-tpl-var" data-var="{recipient_name}">{recipient_name}</span>
                        </div>
                        <div class="clielo-tpl-field">
                            <label><?php esc_html_e( 'Sujet', 'clielo' ); ?></label>
                            <input type="text" name="clielo_notif_settings[tpl_subject_new_order]" value="<?php echo esc_attr( $settings['tpl_subject_new_order'] ); ?>">
                        </div>
                        <div class="clielo-tpl-field">
                            <label><?php esc_html_e( 'Corps du message', 'clielo' ); ?></label>
                            <textarea name="clielo_notif_settings[tpl_body_new_order]"><?php echo esc_textarea( $settings['tpl_body_new_order'] ); ?></textarea>
                        </div>
                    </div>

                    <!-- Template : Changement de statut -->
                    <div class="clielo-tpl-block" data-email-type="order_status">
                        <h3><?php esc_html_e( 'Changement de statut de commande', 'clielo' ); ?></h3>
                        <div class="clielo-tpl-vars">
                            <span class="clielo-tpl-var" data-var="{order_number}">{order_number}</span>
                            <span class="clielo-tpl-var" data-var="{status_label}">{status_label}</span>
                            <span class="clielo-tpl-var" data-var="{service_name}">{service_name}</span>
                            <span class="clielo-tpl-var" data-var="{recipient_name}">{recipient_name}</span>
                        </div>
                        <div class="clielo-tpl-field">
                            <label><?php esc_html_e( 'Sujet', 'clielo' ); ?></label>
                            <input type="text" name="clielo_notif_settings[tpl_subject_order_status]" value="<?php echo esc_attr( $settings['tpl_subject_order_status'] ); ?>">
                        </div>
                        <div class="clielo-tpl-field">
                            <label><?php esc_html_e( 'Corps du message', 'clielo' ); ?></label>
                            <textarea name="clielo_notif_settings[tpl_body_order_status]"><?php echo esc_textarea( $settings['tpl_body_order_status'] ); ?></textarea>
                        </div>
                    </div>

                    <!-- Lien de paiement -->
                    <div class="clielo-tpl-block" data-email-type="payment_link">
                        <h3>💳 <?php esc_html_e( 'Lien de paiement mensualité', 'clielo' ); ?></h3>
                        <div class="clielo-tpl-vars">
                            <?php foreach ( [ '{service_name}', '{order_number}', '{installment_label}', '{amount}', '{recipient_name}' ] as $v ) : ?>
                                <span class="clielo-tpl-var" data-var="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $v ); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="clielo-tpl-field">
                            <label><?php esc_html_e( 'Sujet', 'clielo' ); ?></label>
                            <input type="text" name="clielo_notif_settings[tpl_subject_payment_link]" value="<?php echo esc_attr( $settings['tpl_subject_payment_link'] ); ?>">
                        </div>
                        <div class="clielo-tpl-field">
                            <label><?php esc_html_e( 'Corps du message', 'clielo' ); ?></label>
                            <textarea name="clielo_notif_settings[tpl_body_payment_link]"><?php echo esc_textarea( $settings['tpl_body_payment_link'] ); ?></textarea>
                        </div>
                    </div>

                    <!-- Rappel de paiement -->
                    <div class="clielo-tpl-block" data-email-type="payment_reminder">
                        <h3>⏰ <?php esc_html_e( 'Rappel de paiement', 'clielo' ); ?></h3>
                        <div class="clielo-tpl-vars">
                            <?php foreach ( [ '{service_name}', '{order_number}', '{installment_label}', '{amount}', '{due_date}', '{recipient_name}' ] as $v ) : ?>
                                <span class="clielo-tpl-var" data-var="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $v ); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="clielo-tpl-field">
                            <label><?php esc_html_e( 'Sujet', 'clielo' ); ?></label>
                            <input type="text" name="clielo_notif_settings[tpl_subject_payment_reminder]" value="<?php echo esc_attr( $settings['tpl_subject_payment_reminder'] ); ?>">
                        </div>
                        <div class="clielo-tpl-field">
                            <label><?php esc_html_e( 'Corps du message', 'clielo' ); ?></label>
                            <textarea name="clielo_notif_settings[tpl_body_payment_reminder]"><?php echo esc_textarea( $settings['tpl_body_payment_reminder'] ); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Boutons prévisualiser dans chaque bloc -->
                <?php
                ob_start();
                ?>
                (function(){
                    var color='<?php echo esc_js( $color ); ?>';
                    var btnLabel='<?php echo esc_js( __( '👁 Prévisualiser', 'clielo' ) ); ?>';
                    ['new_message','new_order','order_status','payment_link','payment_reminder'].forEach(function(et){
                        var block=document.querySelector('[data-email-type="'+et+'"]');
                        if(!block) return;
                        var h3=block.querySelector('h3');
                        if(!h3) return;
                        var btn=document.createElement('button');
                        btn.type='button';
                        btn.textContent=btnLabel;
                        btn.style.cssText='float:right;background:none;border:1px solid '+color+';color:'+color+';border-radius:5px;padding:2px 10px;font-size:12px;font-weight:600;cursor:pointer';
                        h3.appendChild(btn);
                        btn.addEventListener('click',function(){
                            var subjectInput=block.querySelector('input[type="text"]');
                            var bodyTextarea=block.querySelector('textarea');
                            sfPreviewEmail(et,subjectInput?subjectInput.value:'',bodyTextarea?bodyTextarea.value:'');
                        });
                    });
                })();
                <?php
                wp_add_inline_script( 'clielo-notif-admin-js', ob_get_clean() );

                ob_start();
                ?>
                /* Variables cliquables */
                document.querySelectorAll('.clielo-tpl-var').forEach(function(el){
                    el.addEventListener('click',function(){
                        var block=this.closest('.clielo-tpl-block');
                        var textarea=block.querySelector('textarea');
                        if(!textarea) return;
                        var v=this.getAttribute('data-var');
                        var start=textarea.selectionStart;
                        var end=textarea.selectionEnd;
                        var text=textarea.value;
                        textarea.value=text.substring(0,start)+v+text.substring(end);
                        textarea.selectionStart=textarea.selectionEnd=start+v.length;
                        textarea.focus();
                    });
                });

                /* Modal prévisualisation */
                var sfPreviewModal=null;

                function sfCreateModal(){
                    if(sfPreviewModal) return;
                    var overlay=document.createElement('div');
                    overlay.id='sf-email-preview-overlay';
                    overlay.style.cssText='display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999999;overflow-y:auto;padding:30px 16px';
                    overlay.innerHTML=
                        '<div style="max-width:660px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3)">'
                        +'<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:<?php echo esc_js( $color ); ?>;color:#fff">'
                        +'<div>'
                        +'<div style="font-size:11px;opacity:.8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px"><?php echo esc_js( __( 'Objet :', 'clielo' ) ); ?></div>'
                        +'<div id="sf-preview-subject" style="font-size:14px;font-weight:600"></div>'
                        +'</div>'
                        +'<button id="sf-preview-close" type="button" style="background:rgba(255,255,255,0.2);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:18px;line-height:1;flex-shrink:0">&times;</button>'
                        +'</div>'
                        +'<div id="sf-preview-loading" style="padding:40px;text-align:center;color:#999;font-size:13px"><?php echo esc_js( __( 'Chargement…', 'clielo' ) ); ?></div>'
                        +'<iframe id="sf-preview-frame" style="display:none;width:100%;border:none;min-height:500px"></iframe>'
                        +'</div>';
                    document.body.appendChild(overlay);
                    sfPreviewModal=overlay;
                    document.getElementById('sf-preview-close').addEventListener('click',function(){
                        overlay.style.display='none';
                    });
                    overlay.addEventListener('click',function(e){
                        if(e.target===overlay) overlay.style.display='none';
                    });
                }

                function sfPreviewEmail(type,subject,body){
                    sfCreateModal();
                    sfPreviewModal.style.display='block';
                    document.getElementById('sf-preview-subject').textContent=subject||'—';
                    document.getElementById('sf-preview-loading').style.display='block';
                    var frame=document.getElementById('sf-preview-frame');
                    frame.style.display='none';
                    var fd=new FormData();
                    fd.append('action','clielo_preview_email');
                    fd.append('nonce','<?php echo esc_js( wp_create_nonce( 'clielo_nonce' ) ); ?>');
                    fd.append('type',type);
                    fd.append('subject',subject);
                    fd.append('body',body);
                    fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',body:fd})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        document.getElementById('sf-preview-loading').style.display='none';
                        if(res.success){
                            document.getElementById('sf-preview-subject').textContent=res.data.subject||subject;
                            frame.style.display='block';
                            frame.srcdoc=res.data.html;
                        } else {
                            document.getElementById('sf-preview-loading').style.display='block';
                            document.getElementById('sf-preview-loading').textContent='<?php echo esc_js( __( 'Erreur lors du chargement.', 'clielo' ) ); ?>';
                        }
                    })
                    .catch(function(){
                        document.getElementById('sf-preview-loading').style.display='block';
                        document.getElementById('sf-preview-loading').textContent='<?php echo esc_js( __( 'Erreur réseau.', 'clielo' ) ); ?>';
                    });
                }
                <?php
                wp_add_inline_script( 'clielo-notif-admin-js', ob_get_clean() );
                ?>

                <p class="submit">
                    <button type="submit" class="button" style="background:<?php echo esc_attr( $color ); ?>;color:#fff;border:none;padding:8px 24px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer">
                        <?php esc_html_e( 'Enregistrer les modifications', 'clielo' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /* ================================================================
     *  NOTIFICATION CREATION
     * ================================================================ */

    private static function create_notification( int $user_id, string $type, int $ref_id, int $post_id, int $sender_id, array $data ): int|false {
        global $wpdb;

        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            self::table_name(),
            [
                'user_id'    => $user_id,
                'type'       => $type,
                'ref_id'     => $ref_id,
                'post_id'    => $post_id,
                'sender_id'  => $sender_id,
                'data'       => wp_json_encode( $data ),
                'is_read'    => 0,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%d', '%d', '%s', '%d', '%s' ]
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    private static function get_admin_ids(): array {
        return get_users( [
            'role'   => 'administrator',
            'fields' => 'ID',
        ] );
    }

    /* ================================================================
     *  EVENT HANDLERS
     * ================================================================ */

    public static function on_message_sent( int $post_id, int $user_id, int $client_id, int $message_id ): void {
        $sender  = get_userdata( $user_id );
        $service = get_the_title( $post_id );

        // Récupérer un extrait du message
        global $wpdb;
        $msg_table = Clielo_DB::table_name();
        $msg_row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT message FROM {$msg_table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $msg_table is a plugin-defined constant.
                $message_id
            )
        );
        $excerpt = $msg_row ? mb_strimwidth( $msg_row->message, 0, 80, '…' ) : '';

        $data = [
            'sender_name'  => $sender ? $sender->display_name : '',
            'service_name' => $service,
            'excerpt'      => $excerpt,
        ];

        if ( user_can( $user_id, 'manage_options' ) ) {
            // Admin envoie → notifier le client
            self::create_notification( $client_id, 'new_message', $message_id, $post_id, $user_id, $data );
            self::maybe_send_email( 'new_message', $client_id, $data, $post_id );
        } else {
            // Client envoie → notifier tous les admins
            foreach ( self::get_admin_ids() as $admin_id ) {
                self::create_notification( (int) $admin_id, 'new_message', $message_id, $post_id, $user_id, $data );
                self::maybe_send_email( 'new_message', (int) $admin_id, $data, $post_id );
            }
        }
    }

    public static function on_order_created( int $order_id, int $post_id, int $client_id ): void {
        $client  = get_userdata( $client_id );
        $service = get_the_title( $post_id );
        $order   = Clielo_Orders::get_order( $order_id );

        $data = [
            'client_name'  => $client ? $client->display_name : '',
            'service_name' => $service,
            'total_price'  => $order ? (float) $order->total_price : 0,
            'order_number' => '#CMD-' . $order_id,
        ];

        // Notifier tous les admins
        foreach ( self::get_admin_ids() as $admin_id ) {
            self::create_notification( (int) $admin_id, 'new_order', $order_id, $post_id, $client_id, $data );
            self::maybe_send_email( 'new_order', (int) $admin_id, $data, $post_id );
        }
    }

    public static function on_order_status_changed( int $order_id, string $new_status, string $old_status, int $acting_user_id ): void {
        $order = Clielo_Orders::get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $service = get_the_title( (int) $order->post_id );

        $status_labels = [
            'pending'   => __( 'En attente', 'clielo' ),
            'paid'      => __( 'Payée', 'clielo' ),
            'started'   => __( 'En cours', 'clielo' ),
            'completed' => __( 'Terminée', 'clielo' ),
            'revision'  => __( 'Retouche', 'clielo' ),
            'accepted'  => __( 'Acceptée', 'clielo' ),
        ];

        $data = [
            'status'       => $new_status,
            'status_label' => $status_labels[ $new_status ] ?? $new_status,
            'service_name' => $service,
            'order_number' => '#CMD-' . $order_id,
        ];

        if ( user_can( $acting_user_id, 'manage_options' ) ) {
            // Admin change le statut → notifier le client
            self::create_notification( (int) $order->client_id, 'order_status', $order_id, (int) $order->post_id, $acting_user_id, $data );
            self::maybe_send_email( 'order_status', (int) $order->client_id, $data, (int) $order->post_id );
        } else {
            // Client change le statut → notifier tous les admins
            foreach ( self::get_admin_ids() as $admin_id ) {
                self::create_notification( (int) $admin_id, 'order_status', $order_id, (int) $order->post_id, $acting_user_id, $data );
                self::maybe_send_email( 'order_status', (int) $admin_id, $data, (int) $order->post_id );
            }
        }
    }

    /* ================================================================
     *  EMAILS — Paiements
     * ================================================================ */

    /**
     * Appelé depuis Clielo_Payments::send_payment_link().
     * Envoie un email au client avec le lien de paiement Stripe.
     */
    public static function on_payment_link_sent( object $row, object $order, string $checkout_url ): void {
        if ( ! clielo_is_premium() ) {
            return;
        }

        $client = get_userdata( (int) $order->client_id );
        if ( ! $client || ! $client->user_email ) {
            return;
        }

        $service_name    = get_the_title( (int) $order->post_id ) ?: 'Clielo';
        $installment_label = class_exists( 'Clielo_Payments' )
            ? Clielo_Payments::get_type_label( $row->type, (int) $row->installment_no )
            : ( 'Mensualité ' . (int) $row->installment_no );
        $amount_fmt      = number_format( floatval( $row->amount_ttc ), 2, ',', ' ' );

        $data = [
            'service_name'      => $service_name,
            'order_number'      => '#CMD-' . (int) $order->id,
            'installment_label' => $installment_label,
            'amount'            => $amount_fmt,
            'payment_url'       => $checkout_url,
            'recipient_name'    => $client->display_name ?: $client->user_login,
        ];

        self::send_payment_email( 'payment_link', $client, $data, (int) $order->post_id );
    }

    /**
     * Appelé depuis le cron quotidien pour envoyer les rappels N jours avant échéance.
     */
    public static function on_payment_reminder( object $row, object $order ): void {
        if ( ! clielo_is_premium() ) {
            return;
        }

        $client = get_userdata( (int) $order->client_id );
        if ( ! $client || ! $client->user_email ) {
            return;
        }

        $service_name      = get_the_title( (int) $order->post_id ) ?: 'Clielo';
        $installment_label = class_exists( 'Clielo_Payments' )
            ? Clielo_Payments::get_type_label( $row->type, (int) $row->installment_no )
            : ( 'Mensualité ' . (int) $row->installment_no );
        $amount_fmt        = number_format( floatval( $row->amount_ttc ), 2, ',', ' ' );
        $due_date_fmt      = $row->due_date ? date_i18n( get_option( 'date_format' ), strtotime( $row->due_date ) ) : '';

        $data = [
            'service_name'      => $service_name,
            'order_number'      => '#CMD-' . (int) $order->id,
            'installment_label' => $installment_label,
            'amount'            => $amount_fmt,
            'due_date'          => $due_date_fmt,
            'payment_url'       => $row->checkout_url ?: '',
            'recipient_name'    => $client->display_name ?: $client->user_login,
        ];

        self::send_payment_email( 'payment_reminder', $client, $data, (int) $order->post_id );
    }

    /**
     * Envoi effectif d'un email de type paiement (payment_link ou payment_reminder).
     */
    private static function send_payment_email( string $type, WP_User $recipient, array $data, int $post_id ): void {
        $settings = self::get_settings();

        $setting_key = 'email_' . $type;
        if ( ( $settings[ $setting_key ] ?? '0' ) !== '1' ) {
            return;
        }

        $subject = self::get_email_subject( $type, $data );
        $body    = self::get_email_body( $type, $data, $recipient, $post_id );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $sender_name  = $settings['sender_name'];
        $sender_email = $settings['sender_email'];
        if ( $sender_name && $sender_email ) {
            $headers[] = 'From: ' . $sender_name . ' <' . $sender_email . '>';
        }

        wp_mail( $recipient->user_email, $subject, $body, $headers );
    }

    /* ================================================================
     *  EMAILS
     * ================================================================ */

    private static function maybe_send_email( string $type, int $recipient_id, array $data, int $post_id ): void {
        if ( ! clielo_is_premium() ) {
            return;
        }

        $settings = self::get_settings();

        $type_map = [
            'new_message'  => 'email_new_message',
            'new_order'    => 'email_new_order',
            'order_status' => 'email_order_status',
        ];

        $setting_key = $type_map[ $type ] ?? '';
        if ( ! $setting_key || $settings[ $setting_key ] !== '1' ) {
            return;
        }

        $recipient = get_userdata( $recipient_id );
        if ( ! $recipient || ! $recipient->user_email ) {
            return;
        }

        $data['recipient_name'] = $recipient->display_name ?: $recipient->user_login;

        $subject = self::get_email_subject( $type, $data );
        $body    = self::get_email_body( $type, $data, $recipient, $post_id );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $sender_name  = $settings['sender_name'];
        $sender_email = $settings['sender_email'];

        if ( $sender_name && $sender_email ) {
            $headers[] = 'From: ' . $sender_name . ' <' . $sender_email . '>';
        }

        wp_mail( $recipient->user_email, $subject, $body, $headers );
    }

    private static function get_email_subject( string $type, array $data ): string {
        $settings = self::get_settings();
        $key      = 'tpl_subject_' . $type;
        $template = $settings[ $key ] ?? '';

        if ( ! $template ) {
            return __( 'Notification Clielo', 'clielo' );
        }

        return self::replace_placeholders( $template, $data );
    }

    private static function get_email_body( string $type, array $data, WP_User $recipient, int $post_id ): string {
        $color       = Clielo_Admin::get_color();
        $site_name   = get_bloginfo( 'name' );
        $service_url = get_permalink( $post_id ) ?: home_url();
        $name        = $recipient->display_name ?: $recipient->user_login;

        // Récupérer le template personnalisé
        $settings = self::get_settings();
        $key      = 'tpl_body_' . $type;
        $template = $settings[ $key ] ?? '';

        // Ajouter recipient_name aux données
        $data['recipient_name'] = $name;

        // Formater le prix si présent
        if ( isset( $data['total_price'] ) ) {
            $data['total_price'] = number_format( (float) $data['total_price'], 2, ',', ' ' );
        }

        // Remplacer les placeholders et convertir en HTML
        $content_text = self::replace_placeholders( $template, $data );
        $content_html = '<p style="margin:0 0 12px 0;font-size:15px;color:#333;line-height:1.6">'
            . nl2br( esc_html( $content_text ) )
            . '</p>';

        // Badge statut pour order_status
        if ( $type === 'order_status' && ! empty( $data['status'] ) ) {
            $status_colors = [
                'pending'   => '#f59e0b',
                'paid'      => '#8b5cf6',
                'started'   => '#3b82f6',
                'completed' => '#10b981',
                'revision'  => '#ef4444',
                'accepted'  => '#6b7280',
            ];
            $sc = $status_colors[ $data['status'] ] ?? '#888';
            $content_html .= '<p style="margin:0 0 16px 0"><span style="display:inline-block;background:' . esc_attr( $sc ) . ';color:#fff;padding:4px 14px;border-radius:12px;font-size:13px;font-weight:600">'
                . esc_html( $data['status_label'] ?? '' )
                . '</span></p>';
        }

        // Bouton
        $btn_labels = [
            'new_message'      => __( 'Voir le chat', 'clielo' ),
            'new_order'        => __( 'Voir la commande', 'clielo' ),
            'order_status'     => __( 'Voir la commande', 'clielo' ),
            'payment_link'     => __( '💳 Payer maintenant', 'clielo' ),
            'payment_reminder' => __( 'Voir mes paiements', 'clielo' ),
        ];
        $btn_label = $btn_labels[ $type ] ?? __( 'Voir', 'clielo' );

        // Pour payment_link : le bouton pointe directement vers Stripe
        if ( $type === 'payment_link' && ! empty( $data['payment_url'] ) ) {
            $service_url = $data['payment_url'];
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
            . '<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">'
            . '<div style="max-width:600px;margin:0 auto;padding:20px">'
            // Header
            . '<div style="background:' . esc_attr( $color ) . ';padding:20px 24px;border-radius:8px 8px 0 0">'
            . '<h1 style="margin:0;font-size:18px;color:#fff;font-weight:700">' . esc_html( $site_name ) . '</h1>'
            . '</div>'
            // Body
            . '<div style="background:#fff;padding:24px;border-left:1px solid #e0e0e0;border-right:1px solid #e0e0e0">'
            . '<p style="margin:0 0 20px 0;font-size:15px;color:#333">'
            /* translators: %s: recipient display name */
            . sprintf( esc_html__( 'Bonjour %s,', 'clielo' ), esc_html( $name ) )
            . '</p>'
            . $content_html
            . '<p style="margin:20px 0 0 0;text-align:center">'
            . '<a href="' . esc_url( $service_url ) . '" style="display:inline-block;background:' . esc_attr( $color ) . ';color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px">'
            . esc_html( $btn_label )
            . '</a></p>'
            . '</div>'
            // Footer
            . '<div style="padding:16px 24px;text-align:center;font-size:12px;color:#999;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;background:#fafafa">'
            . esc_html__( 'Cet email a été envoyé automatiquement par le système de chat.', 'clielo' )
            . '</div>'
            . '</div>'
            . '</body></html>';
    }

    private static function replace_placeholders( string $template, array $data ): string {
        $replacements = [
            '{sender_name}'      => $data['sender_name'] ?? '',
            '{service_name}'     => $data['service_name'] ?? '',
            '{excerpt}'          => $data['excerpt'] ?? '',
            '{client_name}'      => $data['client_name'] ?? '',
            '{total_price}'      => $data['total_price'] ?? '',
            '{order_number}'     => $data['order_number'] ?? '',
            '{status_label}'     => $data['status_label'] ?? '',
            '{recipient_name}'   => $data['recipient_name'] ?? '',
            '{installment_label}'=> $data['installment_label'] ?? '',
            '{amount}'           => $data['amount'] ?? '',
            '{due_date}'         => $data['due_date'] ?? '',
            '{payment_url}'      => $data['payment_url'] ?? '',
        ];

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }

    /* ================================================================
     *  AJAX — Prévisualisation d'un template email
     * ================================================================ */

    public static function ajax_preview_email(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [], 403 );
        }

        $type    = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
        $subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
        $body    = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );

        $allowed = [ 'new_message', 'new_order', 'order_status', 'payment_link', 'payment_reminder' ];
        if ( ! in_array( $type, $allowed, true ) ) {
            wp_send_json_error( [ 'message' => 'Type invalide.' ], 400 );
        }

        // Données d'exemple par type
        $samples = [
            'new_message' => [
                'sender_name'  => 'Jean Dupont',
                'service_name' => 'Création de site web',
                'excerpt'      => 'Bonjour, pouvez-vous me confirmer la date de livraison ?',
                'recipient_name' => 'Marie Martin',
            ],
            'new_order' => [
                'client_name'  => 'Jean Dupont',
                'service_name' => 'Création de site web',
                'total_price'  => '890,00',
                'order_number' => '#CMD-42',
                'recipient_name' => 'Admin',
            ],
            'order_status' => [
                'status'       => 'started',
                'status_label' => 'En cours',
                'service_name' => 'Création de site web',
                'order_number' => '#CMD-42',
                'recipient_name' => 'Marie Martin',
            ],
            'payment_link' => [
                'service_name'      => 'Maintenance mensuelle',
                'order_number'      => '#CMD-42',
                'installment_label' => 'Mensualité 2',
                'amount'            => '139,24',
                'payment_url'       => home_url( '/' ),
                'recipient_name'    => 'Marie Martin',
            ],
            'payment_reminder' => [
                'service_name'      => 'Maintenance mensuelle',
                'order_number'      => '#CMD-42',
                'installment_label' => 'Mensualité 3',
                'amount'            => '139,24',
                'due_date'          => date_i18n( get_option( 'date_format' ), strtotime( '+3 days' ) ),
                'payment_url'       => home_url( '/' ),
                'recipient_name'    => 'Marie Martin',
            ],
        ];

        $data = $samples[ $type ];

        // Utiliser les templates du POST (version en cours d'édition, pas encore sauvegardée)
        // On surcharge temporairement les settings pour le rendu
        $settings_override = self::get_settings();
        $settings_override[ 'tpl_subject_' . $type ] = $subject;
        $settings_override[ 'tpl_body_' . $type ]    = $body;

        // Rendre le sujet
        $rendered_subject = self::replace_placeholders( $subject, $data );

        // Rendre le corps via get_email_body avec les settings surchargés
        // On crée un faux WP_User pour le rendu
        $fake_user = new WP_User();
        $fake_user->display_name = $data['recipient_name'];
        $fake_user->user_login   = strtolower( str_replace( ' ', '.', $data['recipient_name'] ) );
        $fake_user->user_email   = 'exemple@votresite.com';

        // Surcharger temporairement les settings
        $original = get_option( 'clielo_notif_settings', [] );
        $override  = is_array( $original ) ? $original : [];
        $override[ 'tpl_body_' . $type ]    = $body;
        $override[ 'tpl_subject_' . $type ] = $subject;
        update_option( 'clielo_notif_settings', $override );

        $html = self::get_email_body( $type, $data, $fake_user, 0 );

        // Restaurer
        update_option( 'clielo_notif_settings', $original );

        wp_send_json_success( [
            'subject' => $rendered_subject,
            'html'    => $html,
        ] );
    }

    /* ================================================================
     *  AJAX HANDLERS
     * ================================================================ */

    public static function ajax_count(): void {
        check_ajax_referer( 'clielo_notif_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [], 403 );
        }

        global $wpdb;
        $table = self::table_name();
        $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a plugin-defined constant.
                get_current_user_id()
            )
        );

        wp_send_json_success( [ 'count' => $count ] );
    }

    public static function ajax_list(): void {
        check_ajax_referer( 'clielo_notif_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [], 403 );
        }

        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 15", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a plugin-defined constant.
                get_current_user_id()
            )
        );

        $notifications = [];
        foreach ( $rows as $row ) {
            $nd              = json_decode( $row->data, true ) ?: [];
            $notifications[] = [
                'id'       => (int) $row->id,
                'type'     => $row->type,
                'message'  => self::format_notification_text( $row->type, $nd ),
                'time_ago' => self::time_ago( $row->created_at ),
                'url'      => get_permalink( (int) $row->post_id ) ?: home_url(),
                'is_read'  => (int) $row->is_read,
            ];
        }

        wp_send_json_success( [ 'notifications' => $notifications ] );
    }

    public static function ajax_read_all(): void {
        check_ajax_referer( 'clielo_notif_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [], 403 );
        }

        global $wpdb;
        $table = self::table_name();
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $table,
            [ 'is_read' => 1 ],
            [ 'user_id' => get_current_user_id(), 'is_read' => 0 ]
        );

        wp_send_json_success();
    }

    /* ================================================================
     *  HELPERS
     * ================================================================ */

    private static function format_notification_text( string $type, array $data ): string {
        switch ( $type ) {
            case 'new_message':
                return sprintf(
                    /* translators: %1$s: sender name, %2$s: service name */
                    __( '%1$s vous a envoyé un message sur « %2$s »', 'clielo' ),
                    $data['sender_name'] ?? '',
                    $data['service_name'] ?? ''
                );
            case 'new_order':
                return sprintf(
                    /* translators: %1$s: client name, %2$s: service name, %3$s: total price */
                    __( 'Nouvelle commande de %1$s sur « %2$s » — %3$s €', 'clielo' ),
                    $data['client_name'] ?? '',
                    $data['service_name'] ?? '',
                    number_format( (float) ( $data['total_price'] ?? 0 ), 2, ',', ' ' )
                );
            case 'order_status':
                return sprintf(
                    /* translators: %1$s: order number, %2$s: status label */
                    __( 'Commande %1$s — Statut : %2$s', 'clielo' ),
                    $data['order_number'] ?? '',
                    $data['status_label'] ?? ''
                );
            default:
                return __( 'Nouvelle notification', 'clielo' );
        }
    }

    private static function time_ago( string $datetime ): string {
        $now  = current_time( 'timestamp' );
        $time = strtotime( $datetime );
        $diff = $now - $time;

        if ( $diff < 60 ) {
            return __( 'À l\'instant', 'clielo' );
        }
        if ( $diff < 3600 ) {
            $m = (int) floor( $diff / 60 );
            /* translators: %d: number of minutes ago */
            return sprintf( __( 'Il y a %d min', 'clielo' ), $m );
        }
        if ( $diff < 86400 ) {
            $h = (int) floor( $diff / 3600 );
            /* translators: %d: number of hours ago */
            return sprintf( __( 'Il y a %d h', 'clielo' ), $h );
        }
        if ( $diff < 604800 ) {
            $d = (int) floor( $diff / 86400 );
            /* translators: %d: number of days ago */
            return sprintf( __( 'Il y a %d j', 'clielo' ), $d );
        }

        return date_i18n( 'd/m/Y', $time );
    }

    /* ================================================================
     *  SHORTCODE — Bell notifications [clielo_notifications]
     * ================================================================ */

    public static function shortcode_bell(): string {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        self::$instance++;
        $n     = self::$instance;
        $color = Clielo_Admin::get_color();
        $nonce = wp_create_nonce( 'clielo_notif_nonce' );
        $ajax  = admin_url( 'admin-ajax.php' );

        // Compteur initial
        global $wpdb;
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
            get_current_user_id()
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        ob_start();
        ?>
        <div id="clielo-notif-wrap-<?php echo absint( $n ); ?>" style="position:relative !important;display:inline-block !important;vertical-align:middle !important">
            <!-- Bouton cloche -->
            <button id="clielo-notif-btn-<?php echo absint( $n ); ?>" type="button" style="background:none !important;border:none !important;cursor:pointer !important;padding:6px !important;position:relative !important;display:flex !important;align-items:center !important;justify-content:center !important;line-height:1 !important">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $color ); ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block !important"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span id="clielo-notif-badge-<?php echo absint( $n ); ?>" style="position:absolute !important;top:0 !important;right:0 !important;background:#ef4444 !important;color:#fff !important;font-size:10px !important;font-weight:700 !important;min-width:18px !important;height:18px !important;line-height:18px !important;text-align:center !important;border-radius:9px !important;padding:0 4px !important;box-sizing:border-box !important;display:<?php echo $count > 0 ? 'block' : 'none'; ?> !important"><?php echo absint( $count ); ?></span>
            </button>

            <!-- Dropdown -->
            <div id="clielo-notif-drop-<?php echo absint( $n ); ?>" style="display:none !important;position:absolute !important;top:calc(100% + 6px) !important;right:0 !important;width:360px !important;max-width:calc(100vw - 32px) !important;background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:10px !important;box-shadow:0 8px 30px rgba(0,0,0,0.12) !important;z-index:999999 !important;overflow:hidden !important">
                <!-- Header -->
                <div style="padding:14px 16px !important;border-bottom:1px solid #eee !important;display:flex !important;align-items:center !important;justify-content:space-between !important">
                    <span style="font-size:15px !important;font-weight:700 !important;color:#222 !important"><?php esc_html_e( 'Notifications', 'clielo' ); ?></span>
                    <button id="clielo-notif-readall-<?php echo absint( $n ); ?>" type="button" style="background:none !important;border:none !important;color:<?php echo esc_attr( $color ); ?> !important;font-size:12px !important;font-weight:600 !important;cursor:pointer !important;padding:0 !important"><?php esc_html_e( 'Tout marquer comme lu', 'clielo' ); ?></button>
                </div>
                <!-- Liste -->
                <div id="clielo-notif-list-<?php echo absint( $n ); ?>" style="max-height:360px !important;overflow-y:auto !important">
                    <div style="padding:30px 16px !important;text-align:center !important;color:#999 !important;font-size:13px !important"><?php esc_html_e( 'Chargement…', 'clielo' ); ?></div>
                </div>
            </div>
        </div>

        <?php
        ob_start();
        ?>
        (function(){
            var n=<?php echo absint( $n ); ?>,
                ajax='<?php echo esc_js( $ajax ); ?>',
                nonce='<?php echo esc_js( $nonce ); ?>',
                color='<?php echo esc_js( $color ); ?>';

            var btn=document.getElementById('clielo-notif-btn-'+n),
                drop=document.getElementById('clielo-notif-drop-'+n),
                badge=document.getElementById('clielo-notif-badge-'+n),
                list=document.getElementById('clielo-notif-list-'+n),
                readAllBtn=document.getElementById('clielo-notif-readall-'+n),
                isOpen=false;

            function hexToRgba(hex,a){
                var r=parseInt(hex.slice(1,3),16),g=parseInt(hex.slice(3,5),16),b=parseInt(hex.slice(5,7),16);
                return 'rgba('+r+','+g+','+b+','+a+')';
            }

            function typeIcon(type){
                if(type==='new_message') return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="'+color+'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
                if(type==='new_order') return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="'+color+'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
                return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="'+color+'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>';
            }

            function toggleDrop(){
                isOpen=!isOpen;
                drop.style.setProperty('display',isOpen?'block':'none','important');
                if(isOpen) loadList();
            }

            btn.addEventListener('click',function(e){
                e.stopPropagation();
                toggleDrop();
            });

            document.addEventListener('click',function(e){
                if(isOpen && !drop.contains(e.target) && !btn.contains(e.target)){
                    isOpen=false;
                    drop.style.setProperty('display','none','important');
                }
            });

            function loadList(){
                var xhr=new XMLHttpRequest();
                xhr.open('GET',ajax+'?action=clielo_notif_list&nonce='+encodeURIComponent(nonce));
                xhr.onload=function(){
                    try{
                        var r=JSON.parse(xhr.responseText);
                        if(r.success && r.data.notifications){
                            renderList(r.data.notifications);
                            var hasUnread=r.data.notifications.some(function(it){return !it.is_read;});
                            if(hasUnread){
                                var x2=new XMLHttpRequest();
                                x2.open('POST',ajax);
                                x2.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
                                x2.onload=function(){
                                    badge.style.setProperty('display','none','important');
                                    badge.textContent='0';
                                    var links=list.querySelectorAll('a');
                                    for(var i=0;i<links.length;i++){links[i].style.setProperty('background','#fff','important');}
                                };
                                x2.send('action=clielo_notif_read_all&nonce='+encodeURIComponent(nonce));
                            }
                        }
                    }catch(e){}
                };
                xhr.send();
            }

            function renderList(items){
                if(!items.length){
                    list.innerHTML='<div style="padding:30px 16px;text-align:center;color:#999;font-size:13px"><?php echo esc_js( __( 'Aucune notification', 'clielo' ) ); ?></div>';
                    return;
                }
                var html='';
                var bgUnread=hexToRgba(color,0.06);
                items.forEach(function(it){
                    var bg=it.is_read?'#fff':bgUnread;
                    html+='<a href="'+it.url+'" style="display:flex !important;gap:12px !important;padding:12px 16px !important;text-decoration:none !important;border-bottom:1px solid #f0f0f0 !important;background:'+bg+' !important;align-items:flex-start !important">'
                        +'<div style="flex-shrink:0 !important;width:36px !important;height:36px !important;border-radius:8px !important;background:'+hexToRgba(color,0.1)+' !important;display:flex !important;align-items:center !important;justify-content:center !important">'+typeIcon(it.type)+'</div>'
                        +'<div style="flex:1 !important;min-width:0 !important">'
                        +'<div style="font-size:13px !important;color:#333 !important;line-height:1.4 !important;margin-bottom:2px !important">'+it.message+'</div>'
                        +'<div style="font-size:11px !important;color:#999 !important">'+it.time_ago+'</div>'
                        +'</div>'
                        +'</a>';
                });
                list.innerHTML=html;
            }

            readAllBtn.addEventListener('click',function(e){
                e.stopPropagation();
                var xhr=new XMLHttpRequest();
                xhr.open('POST',ajax);
                xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
                xhr.onload=function(){
                    badge.style.setProperty('display','none','important');
                    badge.textContent='0';
                    var links=list.querySelectorAll('a');
                    for(var i=0;i<links.length;i++){
                        links[i].style.setProperty('background','#fff','important');
                    }
                };
                xhr.send('action=clielo_notif_read_all&nonce='+encodeURIComponent(nonce));
            });

            // Polling toutes les 30 secondes
            setInterval(function(){
                var xhr=new XMLHttpRequest();
                xhr.open('GET',ajax+'?action=clielo_notif_count&nonce='+encodeURIComponent(nonce));
                xhr.onload=function(){
                    try{
                        var r=JSON.parse(xhr.responseText);
                        if(r.success){
                            var c=r.data.count;
                            badge.textContent=c;
                            badge.style.setProperty('display',c>0?'block':'none','important');
                        }
                    }catch(e){}
                };
                xhr.send();
            },30000);
        })();
        <?php
        wp_add_inline_script( 'clielo-notif-js', ob_get_clean() );
        ?>
        <?php
        return ob_get_clean();
    }
}
