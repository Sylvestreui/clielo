<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Dashboard {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 9 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
    }

    public static function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'clielo' ) === false ) {
            return;
        }
        if ( ! wp_script_is( 'clielo-admin-js', 'registered' ) ) {
            wp_register_script( 'clielo-admin-js', false, [ 'jquery' ], CLIELO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }
        wp_enqueue_script( 'clielo-admin-js' );

        wp_add_inline_style( 'wp-admin', '
            .clielo-stats { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
            .clielo-stat-card { flex: 1; min-width: 180px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 16px; }
            .clielo-stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
            .clielo-stat-icon .dashicons { font-size: 24px; width: 24px; height: 24px; color: #fff; }
            .clielo-stat-number { font-size: 28px; font-weight: 700; line-height: 1; color: #222; margin-bottom: 4px; }
            .clielo-stat-label { font-size: 13px; color: #888; }
            .clielo-section { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); overflow: hidden; }
            .clielo-section-title { padding: 14px 20px; border-bottom: 1px solid #eee; font-size: 14px; font-weight: 600; color: #333; margin: 0; display: flex; align-items: center; gap: 8px; }
            .clielo-section table { width: 100%; border-collapse: collapse; }
            .clielo-section th { text-align: left; padding: 10px 16px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #888; border-bottom: 1px solid #eee; background: #fafafa; }
            .clielo-section td { padding: 10px 16px; font-size: 13px; color: #444; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
            .clielo-section tr:last-child td { border-bottom: none; }
            .clielo-section tr:hover td { background: #f9fafb; }
            .clielo-status-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; color: #fff; line-height: 1.6; }
            .clielo-empty-row td { text-align: center; color: #999; padding: 30px 16px; }
            .clielo-msg-excerpt { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            @media (max-width: 782px) { .clielo-stats { flex-direction: column; } .clielo-stat-card { min-width: auto; } }
            .clielo-sc-list { display:flex; flex-direction:column; gap:16px; }
            .clielo-sc-card { background:#fff; border:1px solid #e0e0e0; border-radius:10px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.06); display:flex; gap:20px; align-items:flex-start; }
            .clielo-sc-card-icon { width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
            .clielo-sc-card-icon .dashicons { font-size:24px; width:24px; height:24px; color:#fff; }
            .clielo-sc-card-body { flex:1; min-width:0; }
            .clielo-sc-card-title { font-size:15px; font-weight:700; color:#222; margin:0 0 6px 0; }
            .clielo-sc-card-code { display:inline-flex; align-items:center; gap:8px; background:#f4f4f5; border:1px solid #e0e0e0; border-radius:6px; padding:6px 12px; font-family:monospace; font-size:13px; color:#333; margin:0 0 10px 0; cursor:pointer; user-select:all; }
            .clielo-sc-card-code:hover { background:#eee; }
            .clielo-sc-card-code .dashicons { font-size:16px; width:16px; height:16px; color:#888; }
            .clielo-sc-card-desc { font-size:13px; color:#555; line-height:1.6; margin:0 0 8px 0; }
            .clielo-sc-card-where { font-size:12px; color:#888; display:flex; align-items:center; gap:4px; }
            .clielo-sc-card-where .dashicons { font-size:14px; width:14px; height:14px; }
            .clielo-sc-copied { display:none; font-size:11px; color:#10b981; font-weight:600; margin-left:4px; }
            .clielo-sc-section { background:#fff; border:1px solid #e0e0e0; border-radius:10px; box-shadow:0 1px 3px rgba(0,0,0,0.06); overflow:hidden; margin-bottom:0; }
            .clielo-sc-section-header { display:flex; align-items:center; gap:14px; padding:18px 24px; border-bottom:1px solid #f0f0f0; }
            .clielo-sc-section-icon { width:40px; height:40px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
            .clielo-sc-section-icon .dashicons { font-size:20px; width:20px; height:20px; color:#fff; }
            .clielo-sc-section-title { font-size:14px; font-weight:700; color:#222; margin:0 0 2px 0; }
            .clielo-sc-section-desc { font-size:12px; color:#888; margin:0; }
            .clielo-sc-fields { display:flex; flex-direction:column; }
            .clielo-sc-field-row { display:flex; align-items:center; gap:16px; padding:10px 24px; border-bottom:1px solid #f9f9f9; }
            .clielo-sc-field-row:last-child { border-bottom:none; }
            .clielo-sc-field-label { font-size:13px; color:#555; flex:0 0 220px; }
            .clielo-sc-field-code { display:inline-flex; align-items:center; gap:6px; background:#f4f4f5; border:1px solid #e8e8e8; border-radius:5px; padding:4px 10px; font-family:monospace; font-size:12px; color:#333; cursor:pointer; user-select:all; }
            .clielo-sc-field-code:hover { background:#eee; }
            .clielo-sc-field-code .dashicons { font-size:13px; width:13px; height:13px; color:#aaa; }
            .clielo-sc-section-heading { font-size:13px; font-weight:700; color:#444; margin:28px 0 10px 0; text-transform:uppercase; letter-spacing:.05em; }
            @media (max-width:600px) { .clielo-sc-card { flex-direction:column; } .clielo-sc-field-row { flex-direction:column; align-items:flex-start; gap:6px; } .clielo-sc-field-label { flex:none; } }
        ' );

        wp_add_inline_script( 'clielo-admin-js', '
            function clieloCopySC(el){
                var text = el.querySelector(".clielo-sc-code-text").textContent;
                if(navigator.clipboard){
                    navigator.clipboard.writeText(text);
                } else {
                    var ta = document.createElement("textarea");
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand("copy");
                    document.body.removeChild(ta);
                }
                var badge = el.querySelector(".clielo-sc-copied");
                if(badge){ badge.style.display = "inline"; setTimeout(function(){ badge.style.display = "none"; }, 1500); }
            }
        ' );
    }

    public static function add_menu(): void {
        $icon_svg = file_get_contents( CLIELO_PLUGIN_DIR . 'assets/img/icon.svg' );
        $icon_url = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg );

        add_menu_page(
            __( 'Clielo', 'clielo' ),
            __( 'Clielo', 'clielo' ),
            'manage_options',
            'clielo',
            [ __CLASS__, 'render_dashboard' ],
            $icon_url,
            30
        );

        add_submenu_page(
            'clielo',
            __( 'Tableau de bord', 'clielo' ),
            __( 'Tableau de bord', 'clielo' ),
            'manage_options',
            'clielo',
            [ __CLASS__, 'render_dashboard' ]
        );

        add_submenu_page(
            'clielo',
            __( 'Shortcodes', 'clielo' ),
            __( 'Shortcodes', 'clielo' ),
            'manage_options',
            'clielo-shortcodes',
            [ __CLASS__, 'render_shortcodes' ]
        );

        add_submenu_page(
            'clielo',
            __( 'Clients WP', 'clielo' ),
            __( 'Clients WP', 'clielo' ),
            'manage_options',
            'clielo-wp-clients',
            [ __CLASS__, 'render_wp_clients' ]
        );
    }

    /**
     * Statistiques globales.
     */
    private static function get_stats(): array {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are plugin-defined constants, no user input.

        $order_table = Clielo_Orders::table_name();
        $msg_table   = Clielo_DB::table_name();

        $total_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$order_table}" );

        $in_progress = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$order_table} WHERE status = %s",
            'started'
        ) );

        $completed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$order_table} WHERE status IN ('completed', 'accepted')"
        );

        $pending = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$order_table} WHERE status = %s",
            'pending'
        ) );

        $conversations = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT client_id) FROM {$msg_table} WHERE client_id > 0"
        );

        // Stats factures
        $inv_table = Clielo_Invoices::invoices_table_name();
        $total_invoiced = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(total), 0) FROM {$inv_table} WHERE status IN ('validated','paid')"
        );
        $pending_invoices = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$inv_table} WHERE status = %s",
            'pending'
        ) );

        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return [
            'total_orders'     => $total_orders,
            'in_progress'      => $in_progress,
            'completed'        => $completed,
            'pending'          => $pending,
            'conversations'    => $conversations,
            'total_invoiced'   => $total_invoiced,
            'pending_invoices' => $pending_invoices,
        ];
    }

    /**
     * 15 dernières commandes.
     */
    private static function get_recent_orders(): array {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static query, table names only, no user input.

        $order_table = Clielo_Orders::table_name();

        return $wpdb->get_results(
            "SELECT o.*, u.display_name AS client_name, p.post_title AS service_name
             FROM {$order_table} o
             LEFT JOIN {$wpdb->users} u ON o.client_id = u.ID
             LEFT JOIN {$wpdb->posts} p ON o.post_id = p.ID
             ORDER BY o.created_at DESC
             LIMIT 15"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * 10 derniers messages (hors système).
     */
    private static function get_recent_messages(): array {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static query, table names only, no user input.

        $msg_table = Clielo_DB::table_name();

        return $wpdb->get_results(
            "SELECT m.*, u.display_name, p.post_title AS service_name
             FROM {$msg_table} m
             LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
             LEFT JOIN {$wpdb->posts} p ON m.post_id = p.ID
             WHERE m.user_id > 0
             ORDER BY m.created_at DESC
             LIMIT 10"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    public static function render_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $stats    = self::get_stats();
        $orders   = self::get_recent_orders();
        $messages = self::get_recent_messages();

        $status_labels = [
            'pending'   => __( 'En attente', 'clielo' ),
            'paid'      => __( 'Payée', 'clielo' ),
            'started'   => __( 'En cours', 'clielo' ),
            'completed' => __( 'Terminée', 'clielo' ),
            'revision'  => __( 'Retouche', 'clielo' ),
            'accepted'  => __( 'Acceptée', 'clielo' ),
        ];
        $status_colors = [
            'pending'   => '#f59e0b',
            'paid'      => '#8b5cf6',
            'started'   => '#3b82f6',
            'completed' => '#10b981',
            'revision'  => '#ef4444',
            'accepted'  => '#6b7280',
        ];

        $color = Clielo_Admin::get_color();
        ?>
        <div class="wrap clielo-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-format-chat" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php esc_html_e( 'Clielo — Tableau de bord', 'clielo' ); ?>
                <?php if ( clielo_is_premium() ) : ?>
                    <span style="background:#8b5cf6;color:#fff;font-size:12px;padding:2px 8px;border-radius:4px">PRO</span>
                <?php endif; ?>
            </h1>

            <!-- Cartes stats -->
            <div class="clielo-stats">
                <div class="clielo-stat-card">
                    <div class="clielo-stat-icon" style="background:#6366f1">
                        <span class="dashicons dashicons-cart"></span>
                    </div>
                    <div>
                        <div class="clielo-stat-number"><?php echo esc_html( $stats['total_orders'] ); ?></div>
                        <div class="clielo-stat-label"><?php esc_html_e( 'Commandes totales', 'clielo' ); ?></div>
                    </div>
                </div>
                <div class="clielo-stat-card">
                    <div class="clielo-stat-icon" style="background:#f59e0b">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <div>
                        <div class="clielo-stat-number"><?php echo esc_html( $stats['pending'] ); ?></div>
                        <div class="clielo-stat-label"><?php esc_html_e( 'En attente', 'clielo' ); ?></div>
                    </div>
                </div>
                <div class="clielo-stat-card">
                    <div class="clielo-stat-icon" style="background:#3b82f6">
                        <span class="dashicons dashicons-update"></span>
                    </div>
                    <div>
                        <div class="clielo-stat-number"><?php echo esc_html( $stats['in_progress'] ); ?></div>
                        <div class="clielo-stat-label"><?php esc_html_e( 'En cours', 'clielo' ); ?></div>
                    </div>
                </div>
                <div class="clielo-stat-card">
                    <div class="clielo-stat-icon" style="background:#10b981">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div>
                        <div class="clielo-stat-number"><?php echo esc_html( $stats['completed'] ); ?></div>
                        <div class="clielo-stat-label"><?php esc_html_e( 'Terminées / Acceptées', 'clielo' ); ?></div>
                    </div>
                </div>
                <div class="clielo-stat-card">
                    <div class="clielo-stat-icon" style="background:<?php echo esc_attr( $color ); ?>">
                        <span class="dashicons dashicons-admin-comments"></span>
                    </div>
                    <div>
                        <div class="clielo-stat-number"><?php echo esc_html( $stats['conversations'] ); ?></div>
                        <div class="clielo-stat-label"><?php esc_html_e( 'Conversations', 'clielo' ); ?></div>
                    </div>
                </div>
                <div class="clielo-stat-card">
                    <div class="clielo-stat-icon" style="background:#059669">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
                    <div>
                        <div class="clielo-stat-number"><?php echo esc_html( number_format( $stats['total_invoiced'], 2, ',', ' ' ) ); ?> &euro;</div>
                        <div class="clielo-stat-label"><?php esc_html_e( 'Total facturé', 'clielo' ); ?></div>
                    </div>
                </div>
                <div class="clielo-stat-card">
                    <div class="clielo-stat-icon" style="background:#f59e0b">
                        <span class="dashicons dashicons-media-text"></span>
                    </div>
                    <div>
                        <div class="clielo-stat-number"><?php echo esc_html( $stats['pending_invoices'] ); ?></div>
                        <div class="clielo-stat-label"><?php esc_html_e( 'Factures à valider', 'clielo' ); ?></div>
                    </div>
                </div>
            </div>

            <!-- Commandes récentes -->
            <div class="clielo-section">
                <h2 class="clielo-section-title">
                    <span class="dashicons dashicons-list-view" style="color:#6366f1"></span>
                    <?php esc_html_e( 'Commandes récentes', 'clielo' ); ?>
                </h2>
                <table>
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'N°', 'clielo' ); ?></th>
                            <th><?php esc_html_e( 'Client', 'clielo' ); ?></th>
                            <th><?php esc_html_e( 'Service', 'clielo' ); ?></th>
                            <th><?php esc_html_e( 'Statut', 'clielo' ); ?></th>
                            <th><?php esc_html_e( 'Prix', 'clielo' ); ?></th>
                            <th><?php esc_html_e( 'Délai', 'clielo' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'clielo' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $orders ) ) : ?>
                            <tr class="clielo-empty-row"><td colspan="7"><?php esc_html_e( 'Aucune commande.', 'clielo' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $orders as $o ) :
                                $s_label = $status_labels[ $o->status ] ?? $o->status;
                                $s_color = $status_colors[ $o->status ] ?? '#888';
                                $permalink = get_permalink( $o->post_id );
                            ?>
                            <tr>
                                <td><strong>#CMD-<?php echo esc_html( $o->id ); ?></strong></td>
                                <td><?php echo esc_html( $o->client_name ?: '—' ); ?></td>
                                <td>
                                    <?php if ( $permalink ) : ?>
                                        <a href="<?php echo esc_url( $permalink ); ?>" target="_blank"><?php echo esc_html( mb_strimwidth( $o->service_name ?: '—', 0, 40, '…' ) ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $o->service_name ?: '—' ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><span class="clielo-status-badge" style="background:<?php echo esc_attr( $s_color ); ?>"><?php echo esc_html( $s_label ); ?></span></td>
                                <td>
                                    <?php echo esc_html( number_format( (float) $o->total_price, 2, ',', ' ' ) ); ?> &euro;
                                    <?php if ( ! empty( $o->payment_mode ) && $o->payment_mode !== 'single' ) : ?>
                                        <span style="display:inline-block;margin-left:4px;padding:1px 5px;border-radius:4px;font-size:10px;font-weight:600;color:#fff;background:#f59e0b" title="<?php esc_attr_e( 'Paiement en plusieurs fois', 'clielo' ); ?>">
                                            <?php echo match ( $o->payment_mode ) {
                                                'deposit'      => esc_html__( 'Acompte', 'clielo' ),
                                                'installments' => esc_html__( 'Mensualités', 'clielo' ),
                                                'monthly'      => esc_html__( 'Abonnement', 'clielo' ),
                                                default        => esc_html( $o->payment_mode ),
                                            }; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $o->total_delay ); ?> <?php esc_html_e( 'j', 'clielo' ); ?></td>
                                <td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $o->created_at ) ) ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Activité récente -->
            <div class="clielo-section">
                <h2 class="clielo-section-title">
                    <span class="dashicons dashicons-admin-comments" style="color:<?php echo esc_attr( $color ); ?>"></span>
                    <?php esc_html_e( 'Activité récente', 'clielo' ); ?>
                </h2>
                <table>
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Utilisateur', 'clielo' ); ?></th>
                            <th><?php esc_html_e( 'Service', 'clielo' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'clielo' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'clielo' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $messages ) ) : ?>
                            <tr class="clielo-empty-row"><td colspan="4"><?php esc_html_e( 'Aucun message.', 'clielo' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $messages as $m ) : ?>
                            <tr>
                                <td><?php echo esc_html( $m->display_name ?: '—' ); ?></td>
                                <td><?php echo esc_html( mb_strimwidth( $m->service_name ?: '—', 0, 35, '…' ) ); ?></td>
                                <td><div class="clielo-msg-excerpt"><?php echo esc_html( mb_strimwidth( $m->message, 0, 80, '…' ) ); ?></div></td>
                                <td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $m->created_at ) ) ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Page listant tous les shortcodes du plugin.
     */
    public static function render_shortcodes(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $color = Clielo_Admin::get_color();

        $main_shortcodes = [
            [
                'code'  => '[clielo_options]',
                'title' => __( 'Options & Commande de service', 'clielo' ),
                'desc'  => __( 'Affiche la carte de sélection des packs et options pour un service. Permet au client de choisir un pack, cocher des options et passer commande via le chat. À placer sur les pages du CPT configuré.', 'clielo' ),
                'where' => __( 'Page single du CPT (template Elementor du service)', 'clielo' ),
                'icon'  => 'dashicons-cart',
                'color' => '#6366f1',
            ],
            [
                'code'  => '[clielo_account]',
                'title' => __( 'Widget Compte (Avatar / Connexion)', 'clielo' ),
                'desc'  => __( 'Affiche un bouton « Se connecter / S\'inscrire » si l\'utilisateur n\'est pas connecté, ou son avatar avec un menu déroulant (Mon compte, Mes commandes, Se déconnecter) s\'il est connecté. Idéal pour le header.', 'clielo' ),
                'where' => __( 'Header du site (widget Elementor, barre de navigation)', 'clielo' ),
                'icon'  => 'dashicons-admin-users',
                'color' => '#3b82f6',
            ],
            [
                'code'  => '[clielo_my_account]',
                'title' => __( 'Page Mon Compte', 'clielo' ),
                'desc'  => __( 'Affiche la page complète du compte utilisateur avec deux onglets : « Mes commandes » (historique filtrable par statut) et « Mon profil » (informations personnelles). Redirige vers la connexion si non connecté.', 'clielo' ),
                'where' => __( 'Page dédiée « Mon compte »', 'clielo' ),
                'icon'  => 'dashicons-id-alt',
                'color' => '#10b981',
            ],
            [
                'code'  => '[clielo_notifications]',
                'title' => __( 'Cloche de notifications', 'clielo' ),
                'desc'  => __( 'Affiche une icône de cloche avec le compteur de notifications non lues. Au clic, un menu déroulant affiche les dernières notifications (nouveaux messages, commandes, changements de statut). Masqué si l\'utilisateur n\'est pas connecté.', 'clielo' ),
                'where' => __( 'Header du site (barre de navigation, à côté du widget compte)', 'clielo' ),
                'icon'  => 'dashicons-bell',
                'color' => '#ef4444',
            ],
        ];

        $field_sections = [
            [
                'title' => __( 'Packs', 'clielo' ),
                'icon'  => 'dashicons-archive',
                'color' => '#6366f1',
                'desc'  => __( 'Affichent les données d\'un pack par son index (0 = premier). Attributs communs : index="0" post_id="".', 'clielo' ),
                'fields' => [
                    [ 'code' => '[clielo_pack_name index="0"]',          'label' => __( 'Nom du pack', 'clielo' ) ],
                    [ 'code' => '[clielo_pack_price index="0" currency="€"]',  'label' => __( 'Prix du pack', 'clielo' ) ],
                    [ 'code' => '[clielo_pack_starting_price currency="€"]',    'label' => __( 'Prix de départ (minimum des packs)', 'clielo' ) ],
                    [ 'code' => '[clielo_pack_delay index="0" suffix=" jour(s)"]', 'label' => __( 'Délai de livraison', 'clielo' ) ],
                    [ 'code' => '[clielo_pack_description index="0"]',   'label' => __( 'Description / infobulle', 'clielo' ) ],
                    [ 'code' => '[clielo_pack_features index="0" format="list"]', 'label' => __( 'Caractéristiques (format="list" ou "inline")', 'clielo' ) ],
                    [ 'code' => '[clielo_pack_count]',                    'label' => __( 'Nombre total de packs', 'clielo' ) ],
                ],
            ],
            [
                'title' => __( 'Options supplémentaires', 'clielo' ),
                'icon'  => 'dashicons-plus-alt',
                'color' => '#f59e0b',
                'desc'  => __( 'Affichent les données d\'une option par son index (0 = première). Attributs communs : index="0" post_id="".', 'clielo' ),
                'fields' => [
                    [ 'code' => '[clielo_option_name index="0"]',         'label' => __( 'Nom de l\'option', 'clielo' ) ],
                    [ 'code' => '[clielo_option_price index="0" currency="€"]', 'label' => __( 'Prix de l\'option', 'clielo' ) ],
                    [ 'code' => '[clielo_option_delay index="0" suffix=" jour(s)"]', 'label' => __( 'Délai de l\'option', 'clielo' ) ],
                    [ 'code' => '[clielo_option_description index="0"]',  'label' => __( 'Description de l\'option', 'clielo' ) ],
                    [ 'code' => '[clielo_option_count]',                   'label' => __( 'Nombre total d\'options', 'clielo' ) ],
                ],
            ],
            [
                'title' => __( 'Prix avancés', 'clielo' ),
                'icon'  => 'dashicons-tag',
                'color' => '#10b981',
                'desc'  => __( 'Options tarifaires avancées (Premium). Attributs : currency="€" post_id="".', 'clielo' ),
                'fields' => [
                    [ 'code' => '[clielo_extra_page_price currency="€"]',  'label' => __( 'Prix page supplémentaire', 'clielo' ) ],
                    [ 'code' => '[clielo_maintenance_price currency="€"]', 'label' => __( 'Prix maintenance mensuelle', 'clielo' ) ],
                    [ 'code' => '[clielo_express_price currency="€"]',     'label' => __( 'Prix livraison express (par jour)', 'clielo' ) ],
                ],
            ],
        ];
        ?>
        <div class="wrap clielo-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                <span class="dashicons dashicons-shortcode" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php esc_html_e( 'Clielo — Shortcodes', 'clielo' ); ?>
            </h1>
            <p style="font-size:14px;color:#666;margin:0 0 28px 0">
                <?php esc_html_e( 'Copiez un shortcode et collez-le dans n\'importe quelle page, article ou widget Elementor.', 'clielo' ); ?>
            </p>

            <!-- Shortcodes principaux -->
            <p class="clielo-sc-section-heading"><?php esc_html_e( 'Shortcodes principaux', 'clielo' ); ?></p>
            <div class="clielo-sc-list">
                <?php foreach ( $main_shortcodes as $sc ) : ?>
                <div class="clielo-sc-card">
                    <div class="clielo-sc-card-icon" style="background:<?php echo esc_attr( $sc['color'] ); ?>">
                        <span class="dashicons <?php echo esc_attr( $sc['icon'] ); ?>"></span>
                    </div>
                    <div class="clielo-sc-card-body">
                        <div class="clielo-sc-card-title"><?php echo esc_html( $sc['title'] ); ?></div>
                        <div class="clielo-sc-card-code" onclick="clieloCopySC(this)" title="<?php esc_attr_e( 'Cliquer pour copier', 'clielo' ); ?>">
                            <span class="dashicons dashicons-clipboard"></span>
                            <span class="clielo-sc-code-text"><?php echo esc_html( $sc['code'] ); ?></span>
                            <span class="clielo-sc-copied"><?php esc_html_e( 'Copié !', 'clielo' ); ?></span>
                        </div>
                        <p class="clielo-sc-card-desc"><?php echo esc_html( $sc['desc'] ); ?></p>
                        <div class="clielo-sc-card-where">
                            <span class="dashicons dashicons-location"></span>
                            <?php echo esc_html( $sc['where'] ); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Champs dynamiques -->
            <p class="clielo-sc-section-heading"><?php esc_html_e( 'Champs dynamiques (Dynamic Tags)', 'clielo' ); ?></p>
            <p style="font-size:13px;color:#666;margin:-4px 0 14px 0"><?php esc_html_e( 'À utiliser dans les templates Elementor des pages de service. Tous acceptent un attribut post_id="123" optionnel.', 'clielo' ); ?></p>
            <div class="clielo-sc-list">
                <?php foreach ( $field_sections as $section ) : ?>
                <div class="clielo-sc-section">
                    <div class="clielo-sc-section-header">
                        <div class="clielo-sc-section-icon" style="background:<?php echo esc_attr( $section['color'] ); ?>">
                            <span class="dashicons <?php echo esc_attr( $section['icon'] ); ?>"></span>
                        </div>
                        <div>
                            <p class="clielo-sc-section-title"><?php echo esc_html( $section['title'] ); ?></p>
                            <p class="clielo-sc-section-desc"><?php echo esc_html( $section['desc'] ); ?></p>
                        </div>
                    </div>
                    <div class="clielo-sc-fields">
                        <?php foreach ( $section['fields'] as $field ) : ?>
                        <div class="clielo-sc-field-row">
                            <span class="clielo-sc-field-label"><?php echo esc_html( $field['label'] ); ?></span>
                            <div class="clielo-sc-field-code" onclick="clieloCopySC(this)" title="<?php esc_attr_e( 'Cliquer pour copier', 'clielo' ); ?>">
                                <span class="dashicons dashicons-clipboard"></span>
                                <span class="clielo-sc-code-text"><?php echo esc_html( $field['code'] ); ?></span>
                                <span class="clielo-sc-copied"><?php esc_html_e( 'Copié !', 'clielo' ); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ================================================================
     *  Page Clients WP
     * ================================================================ */

    public static function render_wp_clients(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'clielo' ) );
        }

        global $wpdb;
        $order_table = Clielo_Orders::table_name();

        // Statuts labels + couleurs
        $status_labels = [
            'pending'   => __( 'En attente', 'clielo' ),
            'paid'      => __( 'Payée', 'clielo' ),
            'started'   => __( 'En cours', 'clielo' ),
            'completed' => __( 'Terminée', 'clielo' ),
            'revision'  => __( 'Retouche en cours', 'clielo' ),
            'accepted'  => __( 'Acceptée', 'clielo' ),
        ];
        $status_colors = [
            'pending'   => '#f59e0b',
            'paid'      => '#8b5cf6',
            'started'   => '#3b82f6',
            'completed' => '#10b981',
            'revision'  => '#ef4444',
            'accepted'  => '#6b7280',
        ];

        // Requête : clients ayant au moins une commande
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            "SELECT client_id,
                    COUNT(id)          AS order_count,
                    MAX(created_at)    AS last_order_date,
                    SUM(total_price)   AS total_spent,
                    (SELECT status FROM {$order_table} o2 WHERE o2.client_id = o.client_id ORDER BY o2.created_at DESC LIMIT 1) AS last_status
             FROM {$order_table} o
             WHERE client_id > 0
             GROUP BY client_id
             ORDER BY last_order_date DESC"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $color = Clielo_Admin::get_color();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Clients WP', 'clielo' ); ?></h1>
            <p style="color:#666;margin-bottom:16px"><?php esc_html_e( 'Utilisateurs WordPress ayant passé au moins une commande via Clielo.', 'clielo' ); ?></p>

            <?php if ( empty( $rows ) ) : ?>
                <p><?php esc_html_e( 'Aucun client pour le moment.', 'clielo' ); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden">
                <thead>
                    <tr>
                        <th style="width:44px"></th>
                        <th><?php esc_html_e( 'Client', 'clielo' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'clielo' ); ?></th>
                        <th style="width:100px;text-align:center"><?php esc_html_e( 'Commandes', 'clielo' ); ?></th>
                        <th style="width:130px;text-align:right"><?php esc_html_e( 'Total dépensé', 'clielo' ); ?></th>
                        <th style="width:130px"><?php esc_html_e( 'Dernier statut', 'clielo' ); ?></th>
                        <th style="width:130px"><?php esc_html_e( 'Dernière commande', 'clielo' ); ?></th>
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $user = get_userdata( (int) $row->client_id );
                    if ( ! $user ) {
                        continue;
                    }
                    $avatar     = get_avatar_url( (int) $row->client_id, [ 'size' => 36 ] );
                    $status     = $row->last_status ?? '';
                    $sLabel     = $status_labels[ $status ] ?? $status;
                    $sColor     = $status_colors[ $status ] ?? '#888';
                    $spent      = number_format( (float) $row->total_spent, 2, ',', ' ' );
                    $last_date  = $row->last_order_date ? wp_date( get_option( 'date_format' ), strtotime( $row->last_order_date ) ) : '—';
                    $edit_url   = get_edit_user_link( (int) $row->client_id );
                ?>
                    <tr>
                        <td><img src="<?php echo esc_url( $avatar ); ?>" style="width:36px;height:36px;border-radius:50%;vertical-align:middle" /></td>
                        <td><strong><?php echo esc_html( $user->display_name ); ?></strong></td>
                        <td style="color:#555"><?php echo esc_html( $user->user_email ); ?></td>
                        <td style="text-align:center;font-weight:600;color:<?php echo esc_attr( $color ); ?>"><?php echo absint( $row->order_count ); ?></td>
                        <td style="text-align:right;font-weight:600"><?php echo esc_html( $spent ); ?> €</td>
                        <td>
                            <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr( $sColor ); ?>">
                                <?php echo esc_html( $sLabel ); ?>
                            </span>
                        </td>
                        <td style="color:#888;font-size:12px"><?php echo esc_html( $last_date ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $edit_url ); ?>" style="color:<?php echo esc_attr( $color ); ?>;font-size:12px;font-weight:500;text-decoration:none">
                                <?php esc_html_e( 'Profil', 'clielo' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
