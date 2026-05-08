<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Account {

    private static int $instance = 0;

    public static function init(): void {
        add_shortcode( 'clielo_account',    [ __CLASS__, 'shortcode_account' ] );
        add_shortcode( 'clielo_my_account', [ __CLASS__, 'shortcode_my_account' ] );
        add_action( 'wp_ajax_clielo_update_profile', [ __CLASS__, 'ajax_update_profile' ] );
        add_action( 'wp_ajax_clielo_upload_avatar',  [ __CLASS__, 'ajax_upload_avatar' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_frontend_assets' ] );
    }

    public static function enqueue_frontend_assets(): void {
        if ( ! wp_style_is( 'clielo-account-css', 'registered' ) ) {
            wp_register_style( 'clielo-account-css', false, [], CLIELO_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }
        wp_enqueue_style( 'clielo-account-css' );
        if ( ! wp_script_is( 'clielo-account-js', 'registered' ) ) {
            wp_register_script( 'clielo-account-js', false, [ 'jquery' ], CLIELO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }
        wp_enqueue_script( 'clielo-account-js' );

        $color = esc_attr( Clielo_Admin::get_color() );
        wp_add_inline_style(
            'clielo-account-css',
            '#clielo-myaccount{width:100%!important;max-width:100%!important;margin:0 auto!important;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif!important;padding:0!important;box-sizing:border-box!important}' .
            '.clielo-ma-layout{display:flex!important;gap:32px!important;width:100%!important;box-sizing:border-box!important;align-items:flex-start!important}' .
            '.clielo-ma-sidebar{width:220px!important;min-width:220px!important;max-width:220px!important;flex-shrink:0!important}' .
            '.clielo-ma-sidebar nav{display:flex!important;flex-direction:column!important;gap:4px!important;position:sticky!important;top:100px!important}' .
            '.clielo-ma-tab{display:flex!important;align-items:center!important;gap:10px!important;padding:10px 14px!important;border:none!important;background:none!important;font-size:14px!important;font-weight:500!important;cursor:pointer!important;color:#555!important;border-radius:8px!important;text-align:left!important;font-family:inherit!important;transition:all .15s!important;width:100%!important;box-sizing:border-box!important}' .
            '.clielo-ma-tab:hover{background:#f5f5f5!important;color:#333!important}' .
            '.clielo-ma-tab.clielo-tab-active{background:' . $color . '!important;color:#fff!important;font-weight:600!important}' .
            '.clielo-ma-tab.clielo-tab-active:hover{background:' . $color . '!important;color:#fff!important}' .
            '.clielo-ma-tab.clielo-tab-logout{color:#ef4444!important;margin-top:12px!important;border-top:1px solid #f0f0f0!important;padding-top:14px!important;border-radius:8px!important}' .
            '.clielo-ma-tab.clielo-tab-logout:hover{background:#fef2f2!important;color:#dc2626!important}' .
            '.clielo-ma-content{flex:1 1 0%!important;min-width:0!important;max-width:calc(100% - 252px)!important;width:100%!important;box-sizing:border-box!important}' .
            '.clielo-ma-panel{width:100%!important;box-sizing:border-box!important}' .
            '@media(max-width:768px){' .
                '.clielo-ma-layout{flex-direction:column!important;gap:0!important}' .
                '.clielo-ma-sidebar{width:100%!important;min-width:100%!important;max-width:100%!important}' .
                '.clielo-ma-sidebar nav{flex-direction:row!important;overflow-x:auto!important;gap:4px!important;padding:0 0 12px 0!important;position:static!important;border-bottom:1px solid #e0e0e0!important;margin-bottom:20px!important}' .
                '.clielo-ma-tab{white-space:nowrap!important;padding:8px 14px!important;font-size:13px!important}' .
                '.clielo-ma-tab.clielo-tab-logout{margin-top:0!important;border-top:none!important;padding-top:8px!important}' .
                '.clielo-ma-content{max-width:100%!important}' .
                '.clielo-dash-stats{grid-template-columns:repeat(2,1fr)!important}' .
                '.clielo-dash-quick{grid-template-columns:repeat(3,1fr)!important}' .
            '}' .
            '@keyframes clielo-spin{to{transform:rotate(360deg)}}'
        );
    }

    /**
     * AJAX : Mise à jour du profil utilisateur.
     */
    public static function ajax_update_profile(): void {
        check_ajax_referer( 'clielo_profile_nonce', 'clielo_profile_nonce_field' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Non connecté.', 'clielo' ) ], 403 );
        }

        $user_id = get_current_user_id();
        $data    = [ 'ID' => $user_id ];

        $display_name = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
        $first_name   = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name    = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $user_email   = sanitize_email( wp_unslash( $_POST['user_email'] ?? '' ) );
        $new_password = wp_unslash( $_POST['new_password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be sanitized; special characters must be preserved.

        if ( ! empty( $display_name ) ) {
            $data['display_name'] = $display_name;
        }
        $data['first_name'] = $first_name;
        $data['last_name']  = $last_name;

        if ( ! empty( $user_email ) ) {
            // Vérifier que l'email n'est pas déjà pris par un autre utilisateur
            $existing = email_exists( $user_email );
            if ( $existing && $existing !== $user_id ) {
                wp_send_json_error( [ 'message' => __( 'Cette adresse e-mail est déjà utilisée.', 'clielo' ) ] );
            }
            $data['user_email'] = $user_email;
        }

        if ( ! empty( $new_password ) ) {
            if ( strlen( $new_password ) < 6 ) {
                wp_send_json_error( [ 'message' => __( 'Le mot de passe doit contenir au moins 6 caractères.', 'clielo' ) ] );
            }
            $data['user_pass'] = $new_password;
        }

        $result = wp_update_user( $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'message'      => __( 'Profil mis à jour avec succès.', 'clielo' ),
            'display_name' => $display_name,
        ] );
    }

    /**
     * AJAX : Upload d'avatar personnalisé.
     */
    public static function ajax_upload_avatar(): void {
        check_ajax_referer( 'clielo_profile_nonce', 'clielo_profile_nonce_field' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Non connecté.', 'clielo' ) ], 403 );
        }

        if ( empty( $_FILES['avatar'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Aucun fichier envoyé.', 'clielo' ) ] );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'avatar', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
        }

        $user_id = get_current_user_id();
        update_user_meta( $user_id, 'clielo_avatar_id', $attachment_id );

        $url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

        wp_send_json_success( [ 'url' => $url ] );
    }

    /**
     * Récupère l'URL de l'avatar (personnalisé ou Gravatar).
     */
    public static function get_user_avatar( int $user_id, int $size = 96 ): string {
        $custom_id = get_user_meta( $user_id, 'clielo_avatar_id', true );
        if ( $custom_id ) {
            $url = wp_get_attachment_image_url( (int) $custom_id, [ $size, $size ] );
            if ( $url ) {
                return $url;
            }
        }
        return get_avatar_url( $user_id, [ 'size' => $size ] );
    }

    private static function get_status_labels(): array {
        return [
            'pending'   => __( 'En attente', 'clielo' ),
            'paid'      => __( 'Payée', 'clielo' ),
            'started'   => __( 'En cours', 'clielo' ),
            'completed' => __( 'Terminée', 'clielo' ),
            'revision'  => __( 'Retouche', 'clielo' ),
            'accepted'  => __( 'Acceptée', 'clielo' ),
        ];
    }

    private static function get_status_colors(): array {
        return [
            'pending'   => '#f59e0b',
            'paid'      => '#8b5cf6',
            'started'   => '#3b82f6',
            'completed' => '#10b981',
            'revision'  => '#ef4444',
            'accepted'  => '#6b7280',
        ];
    }

    private static function get_account_page_url(): string {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time lookup of page by shortcode; result is used immediately.
        $page_id = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page'
             AND post_status = 'publish'
             AND post_content LIKE '%[clielo_my_account]%'
             LIMIT 1"
        );
        return $page_id ? get_permalink( $page_id ) : home_url();
    }

    /**
     * [clielo_account] — Widget avatar / boutons login.
     */
    public static function shortcode_account(): string {
        self::$instance++;
        $n     = self::$instance;
        $color = Clielo_Admin::get_color();

        ob_start();

        if ( ! is_user_logged_in() ) {
            $login_url    = wp_login_url( get_permalink() );
            $can_register = get_option( 'users_can_register' );
            ?>
            <div style="display:inline-flex !important;gap:8px !important;align-items:center !important;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif !important">
                <a href="<?php echo esc_url( $login_url ); ?>"
                   style="display:inline-flex !important;align-items:center !important;gap:6px !important;padding:8px 16px !important;border-radius:8px !important;background:<?php echo esc_attr( $color ); ?> !important;color:#fff !important;font-size:13px !important;font-weight:600 !important;text-decoration:none !important;white-space:nowrap !important;border:none !important;cursor:pointer !important;line-height:1.4 !important">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0 !important"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?php esc_html_e( 'Se connecter', 'clielo' ); ?>
                </a>
                <?php if ( $can_register ) : ?>
                <a href="<?php echo esc_url( wp_registration_url() ); ?>"
                   style="display:inline-flex !important;align-items:center !important;gap:6px !important;padding:8px 16px !important;border-radius:8px !important;background:transparent !important;color:<?php echo esc_attr( $color ); ?> !important;font-size:13px !important;font-weight:600 !important;text-decoration:none !important;white-space:nowrap !important;border:2px solid <?php echo esc_attr( $color ); ?> !important;cursor:pointer !important;line-height:1.4 !important">
                    <?php esc_html_e( "S'inscrire", 'clielo' ); ?>
                </a>
                <?php endif; ?>
            </div>
            <?php
        } else {
            $user       = wp_get_current_user();
            $avatar_url = self::get_user_avatar( $user->ID, 80 );
            $avatar_sm  = self::get_user_avatar( $user->ID, 40 );
            $acct_url   = self::get_account_page_url();
            $logout_url = wp_logout_url( get_permalink() );
            $toggle_id  = 'clielo-acct-toggle-' . $n;
            $drop_id    = 'clielo-acct-drop-' . $n;
            ?>
            <div style="position:relative !important;display:inline-block !important;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif !important">
                <button id="<?php echo esc_attr( $toggle_id ); ?>" type="button"
                        style="width:40px !important;height:40px !important;border-radius:50% !important;border:2px solid <?php echo esc_attr( $color ); ?> !important;padding:0 !important;margin:0 !important;cursor:pointer !important;overflow:hidden !important;background:none !important;display:flex !important;align-items:center !important;justify-content:center !important">
                    <img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>"
                         style="width:100% !important;height:100% !important;object-fit:cover !important;border-radius:50% !important" />
                </button>

                <div id="<?php echo esc_attr( $drop_id ); ?>"
                     style="display:none !important;position:absolute !important;top:48px !important;right:0 !important;min-width:220px !important;background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:10px !important;box-shadow:0 4px 16px rgba(0,0,0,0.12) !important;z-index:9999 !important;overflow:hidden !important;padding:0 !important;margin:0 !important">
                    <!-- Info utilisateur -->
                    <div style="padding:12px 16px !important;border-bottom:1px solid #f0f0f0 !important;display:flex !important;align-items:center !important;gap:10px !important">
                        <img src="<?php echo esc_url( $avatar_sm ); ?>" style="width:32px !important;height:32px !important;border-radius:50% !important;object-fit:cover !important" />
                        <div>
                            <div style="font-size:13px !important;font-weight:600 !important;color:#222 !important"><?php echo esc_html( $user->display_name ); ?></div>
                            <div style="font-size:11px !important;color:#999 !important"><?php echo esc_html( $user->user_email ); ?></div>
                        </div>
                    </div>
                    <!-- Liens -->
                    <a href="<?php echo esc_url( $acct_url . '#dashboard' ); ?>"
                       style="display:flex !important;align-items:center !important;gap:10px !important;padding:10px 16px !important;color:#333 !important;text-decoration:none !important;font-size:13px !important;font-weight:500 !important;border-bottom:1px solid #f5f5f5 !important">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        <?php esc_html_e( 'Mon compte', 'clielo' ); ?>
                    </a>
                    <a href="<?php echo esc_url( $acct_url . '#commandes' ); ?>"
                       style="display:flex !important;align-items:center !important;gap:10px !important;padding:10px 16px !important;color:#333 !important;text-decoration:none !important;font-size:13px !important;font-weight:500 !important;border-bottom:1px solid #f5f5f5 !important">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                        <?php esc_html_e( 'Mes commandes', 'clielo' ); ?>
                    </a>
                    <a href="<?php echo esc_url( $acct_url . '#factures' ); ?>"
                       style="display:flex !important;align-items:center !important;gap:10px !important;padding:10px 16px !important;color:#333 !important;text-decoration:none !important;font-size:13px !important;font-weight:500 !important;border-bottom:1px solid #f5f5f5 !important">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        <?php esc_html_e( 'Mes factures', 'clielo' ); ?>
                    </a>
                    <a href="<?php echo esc_url( $logout_url ); ?>"
                       style="display:flex !important;align-items:center !important;gap:10px !important;padding:10px 16px !important;color:#ef4444 !important;text-decoration:none !important;font-size:13px !important;font-weight:500 !important">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <?php esc_html_e( 'Se déconnecter', 'clielo' ); ?>
                    </a>
                </div>
            </div>
            <?php
            ob_start();
            ?>
            (function(){
                var t=document.getElementById('<?php echo esc_js( $toggle_id ); ?>');
                var d=document.getElementById('<?php echo esc_js( $drop_id ); ?>');
                if(!t||!d) return;
                t.addEventListener('click',function(e){
                    e.preventDefault();e.stopPropagation();
                    var open=d.style.getPropertyValue('display')!=='none';
                    d.style.setProperty('display',open?'none':'block','important');
                });
                document.addEventListener('click',function(e){
                    if(!t.contains(e.target)&&!d.contains(e.target)){
                        d.style.setProperty('display','none','important');
                    }
                });
            })();
            <?php
            wp_add_inline_script( 'clielo-account-js', ob_get_clean() );
            ?>
            <?php
        }

        return ob_get_clean();
    }

    /**
     * [clielo_my_account] — Page Mon Compte complète.
     */
    public static function shortcode_my_account(): string {
        $color = Clielo_Admin::get_color();

        ob_start();

        if ( ! is_user_logged_in() ) {
            $login_url    = wp_login_url( get_permalink() );
            $can_register = get_option( 'users_can_register' );
            ?>
            <div style="max-width:600px !important;margin:40px auto !important;text-align:center !important;padding:40px 20px !important;background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:12px !important;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif !important">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $color ); ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 16px !important;display:block !important"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <h2 style="font-size:20px !important;font-weight:700 !important;color:#222 !important;margin:0 0 8px !important"><?php esc_html_e( 'Connectez-vous pour accéder à votre compte', 'clielo' ); ?></h2>
                <p style="font-size:14px !important;color:#888 !important;margin:0 0 24px !important"><?php esc_html_e( 'Suivez vos commandes et gérez votre profil.', 'clielo' ); ?></p>
                <div style="display:flex !important;gap:12px !important;justify-content:center !important;flex-wrap:wrap !important">
                    <a href="<?php echo esc_url( $login_url ); ?>"
                       style="display:inline-flex !important;align-items:center !important;gap:6px !important;padding:10px 24px !important;border-radius:8px !important;background:<?php echo esc_attr( $color ); ?> !important;color:#fff !important;font-size:14px !important;font-weight:600 !important;text-decoration:none !important;border:none !important;cursor:pointer !important">
                        <?php esc_html_e( 'Se connecter', 'clielo' ); ?>
                    </a>
                    <?php if ( $can_register ) : ?>
                    <a href="<?php echo esc_url( wp_registration_url() ); ?>"
                       style="display:inline-flex !important;align-items:center !important;gap:6px !important;padding:10px 24px !important;border-radius:8px !important;background:transparent !important;color:<?php echo esc_attr( $color ); ?> !important;font-size:14px !important;font-weight:600 !important;text-decoration:none !important;border:2px solid <?php echo esc_attr( $color ); ?> !important;cursor:pointer !important">
                        <?php esc_html_e( "S'inscrire", 'clielo' ); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        $user   = wp_get_current_user();
        $orders = current_user_can( 'manage_options' )
            ? Clielo_Orders::get_all_orders()
            : Clielo_Orders::get_all_orders_for_client( $user->ID );

        $dashboard_html = self::render_dashboard_section( $user, $orders, $color );
        $orders_html    = self::render_orders_section( $orders, $color );
        $profile_html   = self::render_profile_section( $user, $color );
        $esc_color      = esc_attr( $color );
        ?>
        <?php $logout_url = wp_logout_url( get_permalink() ); ?>
        <div id="clielo-myaccount" style="width:100% !important;max-width:100% !important;margin:0 auto !important;padding:0 !important;box-sizing:border-box !important;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif !important">
            <div class="clielo-ma-layout" style="display:flex !important;gap:32px !important;width:100% !important;box-sizing:border-box !important;align-items:flex-start !important">
                <!-- Sidebar menu vertical -->
                <div class="clielo-ma-sidebar" style="width:220px !important;min-width:220px !important;max-width:220px !important;flex-shrink:0 !important;flex-grow:0 !important;box-sizing:border-box !important">
                    <nav style="display:flex !important;flex-direction:column !important;gap:4px !important;position:sticky !important;top:100px !important">
                        <button class="clielo-ma-tab clielo-tab-active" data-tab="dashboard">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            <?php esc_html_e( 'Tableau de bord', 'clielo' ); ?>
                        </button>
                        <button class="clielo-ma-tab" data-tab="commandes">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                            <?php esc_html_e( 'Mes commandes', 'clielo' ); ?>
                        </button>
                        <button class="clielo-ma-tab" data-tab="factures">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <?php esc_html_e( 'Mes factures', 'clielo' ); ?>
                        </button>
                        <button class="clielo-ma-tab" data-tab="profil">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <?php esc_html_e( 'Mon profil', 'clielo' ); ?>
                        </button>
                        <a href="<?php echo esc_url( $logout_url ); ?>" class="clielo-ma-tab clielo-tab-logout">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                            <?php esc_html_e( 'Déconnexion', 'clielo' ); ?>
                        </a>
                    </nav>
                </div>

                <!-- Contenu principal -->
                <div class="clielo-ma-content" style="flex:1 1 0% !important;min-width:0 !important;max-width:calc(100% - 252px) !important;box-sizing:border-box !important">
                    <div id="clielo-ma-panel-dashboard" class="clielo-ma-panel" style="display:block">
                        <?php echo $dashboard_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated internally by render_dashboard_section(). ?>
                    </div>
                    <div id="clielo-ma-panel-commandes" class="clielo-ma-panel" style="display:none">
                        <?php echo $orders_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated internally by render_orders_section(). ?>
                    </div>
                    <div id="clielo-ma-panel-factures" class="clielo-ma-panel" style="display:none">
                        <?php echo self::render_invoices_section( $color ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output from internal static method. ?>
                    </div>
                    <div id="clielo-ma-panel-profil" class="clielo-ma-panel" style="display:none">
                        <?php echo $profile_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated internally by render_profile_section(). ?>
                    </div>
                </div>
            </div>
        </div>

        <?php
        ob_start();
        ?>
        (function(){
            var color='<?php echo esc_js( $color ); ?>';

            /* Onglets verticaux */
            var tabs=document.querySelectorAll('.clielo-ma-tab[data-tab]');
            var panels=document.querySelectorAll('.clielo-ma-panel');
            tabs.forEach(function(tab){
                tab.addEventListener('click',function(){
                    tabs.forEach(function(t){ t.classList.remove('clielo-tab-active'); });
                    panels.forEach(function(p){ p.style.display='none'; });
                    tab.classList.add('clielo-tab-active');
                    var p=document.getElementById('clielo-ma-panel-'+tab.dataset.tab);
                    if(p) p.style.display='block';
                    window.location.hash=tab.dataset.tab==='dashboard'?'':tab.dataset.tab;
                });
            });

            /* Hash URL */
            function applyHash(){
                var hash=window.location.hash.replace('#','');
                if(hash==='profil'||hash==='factures'||hash==='commandes'||hash==='dashboard'){
                    var ht=document.querySelector('.clielo-ma-tab[data-tab="'+hash+'"]');
                    if(ht) ht.click();
                }
            }
            applyHash();
            window.addEventListener('hashchange', applyHash);

            /* Boutons accès rapide dashboard */
            var gotos=document.querySelectorAll('.clielo-dash-goto');
            gotos.forEach(function(btn){
                btn.addEventListener('click',function(){
                    var target=btn.dataset.goto;
                    if(target){
                        var ht=document.querySelector('.clielo-ma-tab[data-tab="'+target+'"]');
                        if(ht) ht.click();
                    }
                });
            });

            /* Filtres commandes */
            var filters=document.querySelectorAll('.clielo-ma-filter');
            var cards=document.querySelectorAll('.clielo-ma-order');
            var emptyEl=document.getElementById('clielo-ma-empty');
            filters.forEach(function(f){
                f.addEventListener('click',function(){
                    var st=f.dataset.status;
                    filters.forEach(function(ff){
                        ff.style.setProperty('background','#fff','important');
                        ff.style.setProperty('color','#666','important');
                        ff.style.setProperty('border-color','#e0e0e0','important');
                    });
                    f.style.setProperty('background',color,'important');
                    f.style.setProperty('color','#fff','important');
                    f.style.setProperty('border-color',color,'important');
                    var vis=0;
                    cards.forEach(function(c){
                        if(st==='all'||c.dataset.status===st){
                            c.style.setProperty('display','block','important');vis++;
                        } else {
                            c.style.setProperty('display','none','important');
                        }
                    });
                    if(emptyEl) emptyEl.style.setProperty('display',vis===0?'block':'none','important');
                });
            });
        })();
        <?php
        wp_add_inline_script( 'clielo-account-js', ob_get_clean() );
        ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Section tableau de bord utilisateur.
     */
    private static function render_dashboard_section( \WP_User $user, array $orders, string $color ): string {
        $esc_color  = esc_attr( $color );
        $avatar_url = self::get_user_avatar( $user->ID, 96 );

        $is_admin_view = current_user_can( 'manage_options' );

        // Stats commandes
        $total_orders   = count( $orders );
        $active_orders  = 0;
        $pending_orders = 0;
        $total_revenue  = 0.0;
        foreach ( $orders as $o ) {
            if ( in_array( $o->status, [ 'started', 'revision' ], true ) ) {
                $active_orders++;
            }
            if ( in_array( $o->status, [ 'pending', 'paid' ], true ) ) {
                $pending_orders++;
            }
            $total_revenue += (float) ( $o->total_price ?? 0 );
        }

        // Stats admin : clients uniques
        $unique_clients = $is_admin_view
            ? count( array_unique( array_column( $orders, 'client_id' ) ) )
            : 0;

        // Stats factures
        $total_invoices  = 0;
        $unpaid_invoices = 0;
        if ( class_exists( 'Clielo_Invoices' ) ) {
            $invoices = $is_admin_view
                ? Clielo_Invoices::get_invoices()
                : Clielo_Invoices::get_invoices_for_client( $user->ID );
            $total_invoices = count( $invoices );
            foreach ( $invoices as $inv ) {
                if ( $inv->status !== 'paid' ) {
                    $unpaid_invoices++;
                }
            }
        }

        // 3 dernières commandes
        $recent_orders = array_slice( $orders, 0, 3 );
        $labels = self::get_status_labels();
        $colors = self::get_status_colors();

        ob_start();
        ?>
        <!-- Bienvenue -->
        <div style="background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:12px !important;padding:24px !important;margin:0 0 20px 0 !important;display:flex !important;align-items:center !important;gap:16px !important">
            <img src="<?php echo esc_url( $avatar_url ); ?>" style="width:56px !important;height:56px !important;border-radius:50% !important;object-fit:cover !important;border:2px solid <?php echo esc_attr( $esc_color ); ?> !important;flex-shrink:0 !important" />
            <div>
                <div style="font-size:18px !important;font-weight:700 !important;color:#222 !important;margin:0 0 4px 0 !important"><?php /* translators: %s: user display name */ printf( esc_html__( 'Bonjour, %s', 'clielo' ), esc_html( $user->display_name ) ); ?></div>
                <div style="font-size:13px !important;color:#888 !important"><?php echo esc_html( $user->user_email ); ?> &middot; <?php esc_html_e( 'Membre depuis', 'clielo' ); ?> <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $user->user_registered ) ) ); ?></div>
            </div>
        </div>

        <!-- Stats -->
        <div class="clielo-dash-stats" style="display:grid !important;grid-template-columns:repeat(4,1fr) !important;gap:12px !important;margin:0 0 20px 0 !important">
            <?php
            $stats = $is_admin_view ? [
                [ 'label' => __( 'Total commandes', 'clielo' ), 'value' => $total_orders, 'color' => '#3b82f6', 'icon' => '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>' ],
                [ 'label' => __( 'En cours', 'clielo' ), 'value' => $active_orders, 'color' => '#f59e0b', 'icon' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>' ],
                [ 'label' => __( 'Clients', 'clielo' ), 'value' => $unique_clients, 'color' => '#8b5cf6', 'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>' ],
                [ 'label' => __( 'CA total', 'clielo' ), 'value' => number_format( $total_revenue, 2, ',', ' ' ) . ' €', 'color' => '#10b981', 'icon' => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>' ],
            ] : [
                [ 'label' => __( 'Total commandes', 'clielo' ), 'value' => $total_orders, 'color' => '#3b82f6', 'icon' => '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>' ],
                [ 'label' => __( 'En cours', 'clielo' ), 'value' => $active_orders, 'color' => '#f59e0b', 'icon' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>' ],
                [ 'label' => __( 'En attente', 'clielo' ), 'value' => $pending_orders, 'color' => '#8b5cf6', 'icon' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>' ],
                [ 'label' => __( 'Factures', 'clielo' ), 'value' => $total_invoices, 'color' => '#10b981', 'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>' ],
            ];
            foreach ( $stats as $st ) :
            ?>
            <div style="background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:10px !important;padding:16px !important;text-align:center !important">
                <div style="margin:0 auto 8px !important;width:36px !important;height:36px !important;border-radius:50% !important;background:<?php echo esc_attr( $st['color'] ); ?>15 !important;display:flex !important;align-items:center !important;justify-content:center !important">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $st['color'] ); ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $st['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG path defined in plugin code. ?></svg>
                </div>
                <div style="font-size:24px !important;font-weight:700 !important;color:#222 !important;line-height:1.2 !important"><?php echo esc_html( $st['value'] ); ?></div>
                <div style="font-size:12px !important;color:#888 !important;margin-top:4px !important"><?php echo esc_html( $st['label'] ); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Accès rapides -->
        <div class="clielo-dash-quick" style="display:grid !important;grid-template-columns:repeat(3,1fr) !important;gap:12px !important;margin:0 0 20px 0 !important">
            <button type="button" class="clielo-dash-goto" data-goto="commandes"
                    style="background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:10px !important;padding:16px !important;cursor:pointer !important;text-align:center !important;font-family:inherit !important;transition:border-color .15s !important">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $esc_color ); ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block !important;margin:0 auto 8px !important"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                <span style="font-size:13px !important;font-weight:600 !important;color:#333 !important"><?php esc_html_e( 'Mes commandes', 'clielo' ); ?></span>
            </button>
            <button type="button" class="clielo-dash-goto" data-goto="factures"
                    style="background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:10px !important;padding:16px !important;cursor:pointer !important;text-align:center !important;font-family:inherit !important;transition:border-color .15s !important">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $esc_color ); ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block !important;margin:0 auto 8px !important"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span style="font-size:13px !important;font-weight:600 !important;color:#333 !important"><?php esc_html_e( 'Mes factures', 'clielo' ); ?></span>
            </button>
            <button type="button" class="clielo-dash-goto" data-goto="profil"
                    style="background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:10px !important;padding:16px !important;cursor:pointer !important;text-align:center !important;font-family:inherit !important;transition:border-color .15s !important">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $esc_color ); ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block !important;margin:0 auto 8px !important"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span style="font-size:13px !important;font-weight:600 !important;color:#333 !important"><?php esc_html_e( 'Mon profil', 'clielo' ); ?></span>
            </button>
        </div>

        <!-- Dernières commandes -->
        <div style="background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:12px !important;padding:20px !important">
            <div style="display:flex !important;justify-content:space-between !important;align-items:center !important;margin:0 0 16px 0 !important">
                <h3 style="font-size:15px !important;font-weight:700 !important;color:#222 !important;margin:0 !important"><?php esc_html_e( 'Dernières commandes', 'clielo' ); ?></h3>
                <?php if ( $total_orders > 3 ) : ?>
                <button type="button" class="clielo-dash-goto" data-goto="commandes" style="background:none !important;border:none !important;cursor:pointer !important;font-size:12px !important;font-weight:600 !important;color:<?php echo esc_attr( $esc_color ); ?> !important;font-family:inherit !important;padding:0 !important"><?php esc_html_e( 'Tout voir', 'clielo' ); ?> &rarr;</button>
                <?php endif; ?>
            </div>
            <?php if ( empty( $recent_orders ) ) : ?>
                <div style="text-align:center !important;padding:20px !important;color:#999 !important;font-size:13px !important"><?php esc_html_e( 'Aucune commande pour le moment.', 'clielo' ); ?></div>
            <?php else : ?>
                <?php foreach ( $recent_orders as $o ) :
                    $s_label = $labels[ $o->status ] ?? $o->status;
                    $s_color = $colors[ $o->status ] ?? '#888';
                    $permalink = get_permalink( $o->post_id );
                ?>
                <div style="display:flex !important;align-items:center !important;justify-content:space-between !important;padding:10px 0 !important;border-bottom:1px solid #f5f5f5 !important">
                    <div style="min-width:0 !important;flex:1 !important">
                        <div style="font-size:13px !important;font-weight:600 !important;color:#222 !important">#CMD-<?php echo esc_html( $o->id ); ?> &middot; <?php echo esc_html( $o->service_name ?: '—' ); ?></div>
                        <div style="font-size:11px !important;color:#999 !important;margin-top:2px !important"><?php echo esc_html( number_format( (float) $o->total_price, 2, ',', ' ' ) ); ?> &euro; &middot; <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $o->created_at ) ) ); ?></div>
                    </div>
                    <span style="display:inline-block !important;padding:3px 10px !important;border-radius:10px !important;font-size:11px !important;font-weight:600 !important;color:#fff !important;background:<?php echo esc_attr( $s_color ); ?> !important;white-space:nowrap !important;flex-shrink:0 !important;margin-left:12px !important"><?php echo esc_html( $s_label ); ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ( $unpaid_invoices > 0 ) : ?>
        <!-- Alerte factures impayées -->
        <div style="margin:16px 0 0 0 !important;padding:12px 16px !important;background:#fef3c7 !important;border:1px solid #fcd34d !important;border-radius:8px !important;display:flex !important;align-items:center !important;gap:10px !important;font-size:13px !important;color:#92400e !important">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0 !important"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>
                <?php
                printf(
                    esc_html(
                        /* translators: %d: number of unpaid invoices */
                        _n( 'Vous avez %d facture en attente de paiement.', 'Vous avez %d factures en attente de paiement.', $unpaid_invoices, 'clielo' )
                    ),
                    $unpaid_invoices // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer value passed to printf %d format.
                ); ?>
                <button type="button" class="clielo-dash-goto" data-goto="factures" style="background:none !important;border:none !important;cursor:pointer !important;font-weight:700 !important;color:#92400e !important;text-decoration:underline !important;font-family:inherit !important;font-size:inherit !important;padding:0 !important"><?php esc_html_e( 'Voir les factures', 'clielo' ); ?></button>
            </span>
        </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Section commandes avec filtres.
     */
    private static function render_orders_section( array $orders, string $color ): string {
        $labels = self::get_status_labels();
        $colors = self::get_status_colors();
        $esc_color = esc_attr( $color );

        // Compteurs par statut
        $counts = [ 'all' => count( $orders ), 'pending' => 0, 'paid' => 0, 'started' => 0, 'completed' => 0, 'accepted' => 0 ];
        foreach ( $orders as $o ) {
            if ( isset( $counts[ $o->status ] ) ) {
                $counts[ $o->status ]++;
            }
        }

        ob_start();

        // Filtres
        $filter_items = [
            'all'       => __( 'Toutes', 'clielo' ),
            'pending'   => $labels['pending'],
            'paid'      => $labels['paid'],
            'started'   => $labels['started'],
            'completed' => $labels['completed'],
            'accepted'  => $labels['accepted'],
        ];
        ?>
        <div style="display:flex !important;gap:8px !important;margin:0 0 20px 0 !important;flex-wrap:wrap !important">
            <?php $first = true; foreach ( $filter_items as $key => $label ) : ?>
            <button class="clielo-ma-filter" data-status="<?php echo esc_attr( $key ); ?>"
                    style="padding:6px 14px !important;border-radius:20px !important;border:1px solid <?php echo $first ? $esc_color : '#e0e0e0'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Value was passed through esc_attr() at assignment. ?> !important;background:<?php echo $first ? $esc_color : '#fff'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Value was passed through esc_attr() at assignment. ?> !important;color:<?php echo $first ? '#fff' : '#666'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Value was passed through esc_attr() at assignment. ?> !important;font-size:12px !important;font-weight:600 !important;cursor:pointer !important;font-family:inherit !important;display:inline-flex !important;align-items:center !important;gap:5px !important">
                <?php echo esc_html( $label ); ?>
                <span style="background:<?php echo $first ? 'rgba(255,255,255,0.3)' : '#f0f0f0'; ?> !important;padding:1px 7px !important;border-radius:10px !important;font-size:11px !important"><?php echo esc_html( $counts[ $key ] ); ?></span>
            </button>
            <?php $first = false; endforeach; ?>
        </div>

        <?php if ( empty( $orders ) ) : ?>
            <div id="clielo-ma-empty" style="display:block !important;text-align:center !important;padding:40px 20px !important;color:#999 !important;font-size:14px !important">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 12px !important;display:block !important"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                <p style="margin:0 !important"><?php esc_html_e( 'Aucune commande pour le moment.', 'clielo' ); ?></p>
            </div>
        <?php else : ?>
            <div id="clielo-ma-empty" style="display:none !important;text-align:center !important;padding:40px 20px !important;color:#999 !important;font-size:14px !important">
                <p style="margin:0 !important"><?php esc_html_e( 'Aucune commande dans cette catégorie.', 'clielo' ); ?></p>
            </div>
            <?php foreach ( $orders as $o ) :
                $s_label = $labels[ $o->status ] ?? $o->status;
                $s_color = $colors[ $o->status ] ?? '#888';
                $permalink = get_permalink( $o->post_id );
            ?>
            <div class="clielo-ma-order" data-status="<?php echo esc_attr( $o->status ); ?>"
                 style="background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:10px !important;padding:16px !important;margin:0 0 12px 0 !important;display:block !important">

                <!-- N° + Statut -->
                <div style="display:flex !important;justify-content:space-between !important;align-items:center !important;margin:0 0 10px 0 !important">
                    <span style="font-size:14px !important;font-weight:700 !important;color:#222 !important">#CMD-<?php echo esc_html( $o->id ); ?></span>
                    <span style="display:inline-block !important;padding:3px 10px !important;border-radius:10px !important;font-size:11px !important;font-weight:600 !important;color:#fff !important;background:<?php echo esc_attr( $s_color ); ?> !important"><?php echo esc_html( $s_label ); ?></span>
                </div>

                <!-- Service -->
                <div style="margin:0 0 8px 0 !important">
                    <?php if ( $permalink ) : ?>
                        <a href="<?php echo esc_url( $permalink ); ?>" style="font-size:14px !important;font-weight:500 !important;color:<?php echo esc_attr( $esc_color ); ?> !important;text-decoration:none !important"><?php echo esc_html( $o->service_name ?: '—' ); ?></a>
                    <?php else : ?>
                        <span style="font-size:14px !important;font-weight:500 !important;color:#333 !important"><?php echo esc_html( $o->service_name ?: '—' ); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Meta -->
                <div style="display:flex !important;gap:16px !important;flex-wrap:wrap !important;font-size:12px !important;color:#888 !important">
                    <span><?php echo esc_html( number_format( (float) $o->total_price, 2, ',', ' ' ) ); ?> &euro;</span>
                    <span><?php echo esc_html( $o->total_delay ); ?> <?php esc_html_e( 'jour(s)', 'clielo' ); ?></span>
                    <span><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $o->created_at ) ) ); ?></span>
                    <?php if ( $o->estimated_date && in_array( $o->status, [ 'started', 'revision' ], true ) ) : ?>
                        <span><?php esc_html_e( 'Livraison :', 'clielo' ); ?> <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $o->estimated_date ) ) ); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Progression (Premium) -->
                <?php if ( function_exists( 'clielo_is_premium' ) && clielo_is_premium() && in_array( $o->status, [ 'started', 'completed', 'revision' ], true ) ) :
                    $todos_data = Clielo_Todos::build_todos_response( (int) $o->id );
                    if ( ! empty( $todos_data['items'] ) ) :
                        $pct = $todos_data['progress']['percent'];
                ?>
                <div style="margin:10px 0 0 0 !important;padding:10px 0 0 0 !important;border-top:1px solid #f0f0f0 !important">
                    <div style="display:flex !important;justify-content:space-between !important;font-size:11px !important;color:#666 !important;margin:0 0 4px 0 !important">
                        <span><?php esc_html_e( 'Progression', 'clielo' ); ?></span>
                        <span><?php echo esc_html( $todos_data['progress']['completed'] . '/' . $todos_data['progress']['total'] ); ?> (<?php echo esc_html( $pct ); ?>%)</span>
                    </div>
                    <div style="height:6px !important;background:#e5e7eb !important;border-radius:3px !important;overflow:hidden !important;margin:0 0 8px 0 !important">
                        <div style="height:100% !important;width:<?php echo esc_attr( $pct ); ?>% !important;background:<?php echo esc_attr( $esc_color ); ?> !important;border-radius:3px !important"></div>
                    </div>
                    <?php foreach ( $todos_data['items'] as $item ) : ?>
                    <div style="display:flex !important;align-items:flex-start !important;gap:6px !important;padding:2px 0 !important;font-size:12px !important">
                        <?php if ( $item['is_completed'] ) : ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $esc_color ); ?>" stroke-width="2.5" style="flex-shrink:0 !important;margin-top:1px"><path d="M20 6L9 17l-5-5"/></svg>
                        <?php else : ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="2" style="flex-shrink:0 !important;margin-top:1px"><circle cx="12" cy="12" r="10"/></svg>
                        <?php endif; ?>
                        <div style="flex:1 !important;min-width:0 !important">
                            <span style="<?php echo $item['is_completed'] ? 'text-decoration:line-through !important;color:#999 !important' : 'color:#333 !important'; ?>"><?php echo esc_html( $item['label'] ); ?></span>
                            <?php if ( ! empty( $item['admin_note'] ) ) : ?>
                                <div style="font-size:11px !important;color:#888 !important;margin-top:1px !important"><?php echo esc_html( $item['admin_note'] ); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; endif; ?>

                <!-- Échéancier de paiement -->
                <?php if ( class_exists( 'Clielo_Payments' ) && isset( $o->payment_mode ) && $o->payment_mode !== 'single' ) :
                    echo Clielo_Payments::render_schedule_block( (int) $o->id, $color, current_user_can( 'manage_options' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is internally generated HTML from Clielo_Payments::render_schedule_block().
                endif; ?>

                <!-- Lien chat -->
                <?php if ( $permalink ) : ?>
                <div style="margin:10px 0 0 0 !important">
                    <a href="<?php echo esc_url( $permalink ); ?>" style="display:inline-flex !important;align-items:center !important;gap:4px !important;font-size:12px !important;color:<?php echo esc_attr( $esc_color ); ?> !important;text-decoration:none !important;font-weight:600 !important">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <?php esc_html_e( 'Accéder au chat', 'clielo' ); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Section profil.
     */
    private static function render_profile_section( \WP_User $user, string $color ): string {
        $avatar_url = self::get_user_avatar( $user->ID, 96 );
        $esc_color  = esc_attr( $color );

        ob_start();
        ?>
        <div style="background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:12px !important;padding:24px !important;width:100% !important;box-sizing:border-box !important">
            <!-- Avatar + info -->
            <div style="display:flex;align-items:center;gap:16px;margin:0 0 24px 0">
                <div id="clielo-avatar-wrap" style="position:relative;width:68px;height:68px;flex-shrink:0;cursor:pointer">
                    <img src="<?php echo esc_url( $avatar_url ); ?>" id="clielo-profile-avatar"
                         style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid <?php echo esc_attr( $esc_color ); ?>" />
                    <div id="clielo-avatar-overlay"
                         style="position:absolute;top:0;left:0;width:64px;height:64px;border-radius:50%;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s;border:2px solid transparent">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    </div>
                    <input type="file" id="clielo-avatar-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" />
                </div>
                <div>
                    <div style="font-size:18px;font-weight:700;color:#222" id="clielo-profile-heading"><?php echo esc_html( $user->display_name ); ?></div>
                    <div style="font-size:13px;color:#888"><?php echo esc_html( $user->user_email ); ?> &middot; <?php esc_html_e( 'Membre depuis', 'clielo' ); ?> <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $user->user_registered ) ) ); ?></div>
                </div>
            </div>

            <!-- Formulaire -->
            <form id="clielo-profile-form">
                <?php wp_nonce_field( 'clielo_profile_nonce', 'clielo_profile_nonce_field' ); ?>

                <div style="margin-bottom:16px">
                    <label style="display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:6px"><?php esc_html_e( "Nom d'utilisateur", 'clielo' ); ?></label>
                    <input type="text" value="<?php echo esc_attr( $user->user_login ); ?>" readonly
                           style="width:100%;padding:10px 14px;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box;background:#f9fafb;color:#999;cursor:not-allowed" />
                </div>

                <div style="margin-bottom:16px">
                    <label style="display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:6px"><?php esc_html_e( 'Nom affiché', 'clielo' ); ?></label>
                    <input type="text" name="display_name" value="<?php echo esc_attr( $user->display_name ); ?>"
                           style="width:100%;padding:10px 14px;border:1px solid #d0d5dd;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box" />
                </div>

                <div style="display:flex;gap:16px;margin-bottom:16px">
                    <div style="flex:1">
                        <label style="display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:6px"><?php esc_html_e( 'Prénom', 'clielo' ); ?></label>
                        <input type="text" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>"
                               style="width:100%;padding:10px 14px;border:1px solid #d0d5dd;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box" />
                    </div>
                    <div style="flex:1">
                        <label style="display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:6px"><?php esc_html_e( 'Nom', 'clielo' ); ?></label>
                        <input type="text" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>"
                               style="width:100%;padding:10px 14px;border:1px solid #d0d5dd;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box" />
                    </div>
                </div>

                <div style="margin-bottom:16px">
                    <label style="display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:6px"><?php esc_html_e( 'Adresse e-mail', 'clielo' ); ?></label>
                    <input type="email" name="user_email" value="<?php echo esc_attr( $user->user_email ); ?>"
                           style="width:100%;padding:10px 14px;border:1px solid #d0d5dd;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box" />
                </div>

                <div style="border-top:1px solid #f0f0f0;padding-top:16px;margin-bottom:16px">
                    <label style="display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:6px"><?php esc_html_e( 'Nouveau mot de passe', 'clielo' ); ?> <span style="font-weight:400;color:#aaa">(<?php esc_html_e( 'laisser vide pour ne pas changer', 'clielo' ); ?>)</span></label>
                    <input type="password" name="new_password" value="" autocomplete="new-password"
                           style="width:100%;padding:10px 14px;border:1px solid #d0d5dd;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box" />
                </div>

                <div id="clielo-profile-msg" style="display:none;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;margin-bottom:12px"></div>

                <button type="submit" id="clielo-profile-save"
                        style="display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border:none;border-radius:8px;background:<?php echo esc_attr( $esc_color ); ?>;color:#fff;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <?php esc_html_e( 'Enregistrer', 'clielo' ); ?>
                </button>
            </form>
        </div>

        <?php
        ob_start();
        ?>
        (function(){
            var form = document.getElementById('clielo-profile-form');
            if(!form) return;
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var btn = document.getElementById('clielo-profile-save');
                var msg = document.getElementById('clielo-profile-msg');
                btn.disabled = true;
                btn.style.opacity = '0.6';

                var fd = new FormData(form);
                fd.append('action', 'clielo_update_profile');

                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {method:'POST',body:fd})
                .then(function(r){return r.json();})
                .then(function(res){
                    msg.style.display = 'block';
                    if(res.success){
                        msg.style.background = '#ecfdf5';
                        msg.style.color = '#065f46';
                        msg.textContent = res.data.message || '<?php echo esc_js( __( 'Profil mis à jour.', 'clielo' ) ); ?>';
                        var heading = document.getElementById('clielo-profile-heading');
                        if(heading && res.data.display_name) heading.textContent = res.data.display_name;
                    } else {
                        msg.style.background = '#fef2f2';
                        msg.style.color = '#991b1b';
                        msg.textContent = res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Erreur lors de la mise à jour.', 'clielo' ) ); ?>';
                    }
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    setTimeout(function(){ msg.style.display='none'; }, 5000);
                })
                .catch(function(){
                    msg.style.display = 'block';
                    msg.style.background = '#fef2f2';
                    msg.style.color = '#991b1b';
                    msg.textContent = '<?php echo esc_js( __( 'Erreur réseau.', 'clielo' ) ); ?>';
                    btn.disabled = false;
                    btn.style.opacity = '1';
                });
            });

            /* Avatar upload */
            var avatarWrap = document.getElementById('clielo-avatar-wrap');
            var avatarImg = document.getElementById('clielo-profile-avatar');
            var avatarOverlay = document.getElementById('clielo-avatar-overlay');
            var avatarInput = document.getElementById('clielo-avatar-input');
            if(avatarWrap && avatarImg && avatarOverlay && avatarInput){
                avatarWrap.addEventListener('mouseenter', function(){ avatarOverlay.style.opacity='1'; });
                avatarWrap.addEventListener('mouseleave', function(){ avatarOverlay.style.opacity='0'; });
                avatarWrap.addEventListener('click', function(){ avatarInput.click(); });
                avatarInput.addEventListener('change', function(){
                    if(!this.files || !this.files[0]) return;
                    var fd = new FormData();
                    fd.append('action', 'clielo_upload_avatar');
                    fd.append('clielo_profile_nonce_field', form.querySelector('[name="clielo_profile_nonce_field"]').value);
                    fd.append('avatar', this.files[0]);
                    avatarOverlay.innerHTML = '<div style="width:20px;height:20px;border:3px solid #fff;border-top-color:transparent;border-radius:50%;animation:clielo-spin .6s linear infinite"></div>';
                    avatarOverlay.style.opacity = '1';
                    fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {method:'POST',body:fd})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        avatarOverlay.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>';
                        avatarOverlay.style.opacity = '0';
                        if(res.success && res.data.url){
                            avatarImg.src = res.data.url;
                        } else {
                            msg.style.display = 'block';
                            msg.style.background = '#fef2f2';
                            msg.style.color = '#991b1b';
                            msg.textContent = res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Erreur lors du téléchargement.', 'clielo' ) ); ?>';
                            setTimeout(function(){ msg.style.display='none'; }, 5000);
                        }
                    });
                    this.value = '';
                });
            }
        })();
        <?php
        wp_add_inline_script( 'clielo-account-js', ob_get_clean() );
        ?>

        <?php if ( current_user_can( 'manage_options' ) && class_exists( 'Clielo_Payments' ) ) : ?>
        <?php
        ob_start();
        ?>
        (function(){
            var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce   = '<?php echo esc_js( wp_create_nonce( 'clielo_nonce' ) ); ?>';

            function sfPaymentAjax( action, scheduleId, btn ){
                btn.disabled = true;
                var origText = btn.textContent;
                btn.textContent = '...';
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', nonce);
                fd.append('schedule_id', scheduleId);
                fetch(ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.text(); })
                .then(function(text){
                    var res;
                    try { res = JSON.parse(text); } catch(e) {
                        btn.disabled = false;
                        btn.textContent = origText;
                        alert('Erreur serveur :\n' + text.substring(0, 500));
                        return;
                    }
                    if(res.success){
                        location.reload();
                    } else {
                        btn.disabled = false;
                        btn.textContent = origText;
                        alert(res.data && res.data.message ? res.data.message : 'Erreur inconnue');
                    }
                })
                .catch(function(err){
                    btn.disabled = false;
                    btn.textContent = origText;
                    alert('Erreur réseau : ' + err);
                });
            }

            document.addEventListener('click', function(e){
                var btn = e.target.closest('.sf-send-payment-link');
                if(btn) sfPaymentAjax('clielo_send_payment_link', btn.dataset.scheduleId, btn);

                var btn2 = e.target.closest('.sf-mark-payment-paid');
                if(btn2) sfPaymentAjax('clielo_mark_payment_paid', btn2.dataset.scheduleId, btn2);

                var btn3 = e.target.closest('.sf-rebuild-schedule');
                if(btn3){
                    btn3.disabled = true;
                    btn3.textContent = '...';
                    var fd = new FormData();
                    fd.append('action', 'clielo_rebuild_schedule');
                    fd.append('nonce', nonce);
                    fd.append('order_id', btn3.dataset.orderId);
                    fetch(ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if(res.success){ location.reload(); }
                        else { btn3.disabled = false; btn3.textContent = 'Recréer l\'échéancier'; alert(res.data && res.data.message ? res.data.message : 'Erreur'); }
                    });
                }
            });
        })();
        <?php
        wp_add_inline_script( 'clielo-account-js', ob_get_clean() );
        ?>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Section factures (onglet Mon Compte).
     */
    private static function render_invoices_section( string $color ): string {
        if ( ! class_exists( 'Clielo_Invoices' ) ) {
            return '';
        }

        $invoices  = Clielo_Invoices::get_invoices_for_client( get_current_user_id() );
        $esc_color = esc_attr( $color );

        $inv_labels = [
            'validated' => __( 'Validée', 'clielo' ),
            'paid'      => __( 'Payée', 'clielo' ),
        ];
        $inv_colors = [
            'validated' => '#3b82f6',
            'paid'      => '#10b981',
        ];

        ob_start();

        if ( empty( $invoices ) ) :
            ?>
            <div style="text-align:center !important;padding:40px 20px !important;color:#888 !important">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:block !important;margin:0 auto 12px !important"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <p style="font-size:14px !important;margin:0 !important"><?php esc_html_e( 'Aucune facture pour le moment.', 'clielo' ); ?></p>
            </div>
            <?php
        else :
            foreach ( $invoices as $inv ) :
                $badge_color = $inv_colors[ $inv->status ] ?? '#9ca3af';
                $status_text = $inv_labels[ $inv->status ] ?? $inv->status;
                $view_url    = admin_url( 'admin-ajax.php?action=clielo_view_invoice&invoice_id=' . (int) $inv->id );
                ?>
                <div style="background:#fff !important;border:1px solid #e0e0e0 !important;border-radius:10px !important;padding:16px !important;margin:0 0 10px 0 !important;box-shadow:0 1px 3px rgba(0,0,0,0.04) !important">
                    <div style="display:flex !important;justify-content:space-between !important;align-items:center !important;margin:0 0 8px 0 !important">
                        <strong style="font-size:14px !important;color:#222 !important"><?php echo esc_html( $inv->invoice_number ); ?></strong>
                        <span style="display:inline-block !important;padding:3px 10px !important;border-radius:12px !important;font-size:11px !important;font-weight:600 !important;color:#fff !important;background:<?php echo esc_attr( $badge_color ); ?> !important"><?php echo esc_html( $status_text ); ?></span>
                    </div>
                    <div style="display:flex !important;gap:16px !important;flex-wrap:wrap !important;font-size:12px !important;color:#888 !important;margin:0 0 10px 0 !important">
                        <span style="font-weight:600 !important;color:#333 !important;font-size:14px !important"><?php echo esc_html( number_format( (float) $inv->total, 2, ',', ' ' ) ); ?> &euro;</span>
                        <span><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $inv->created_at ) ) ); ?></span>
                        <?php if ( $inv->paid_at ) : ?>
                            <span><?php esc_html_e( 'Payée le', 'clielo' ); ?> <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $inv->paid_at ) ) ); ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo esc_url( $view_url ); ?>" target="_blank"
                       style="display:inline-flex !important;align-items:center !important;gap:4px !important;font-size:12px !important;color:<?php echo esc_attr( $esc_color ); ?> !important;text-decoration:none !important;font-weight:600 !important">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <?php esc_html_e( 'Voir / Imprimer', 'clielo' ); ?>
                    </a>
                </div>
                <?php
            endforeach;
        endif;

        return ob_get_clean();
    }
}
