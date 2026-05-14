<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Stripe {

    const STRIPE_API_VERSION = '2024-06-20';

    public static function init(): void {
        // Le webhook admin-ajax est toujours enregistré (serveur → serveur, sans auth)
        add_action( 'wp_ajax_nopriv_clielo_stripe_webhook', [ __CLASS__, 'handle_webhook_ajax' ] );
        add_action( 'wp_ajax_clielo_stripe_webhook',        [ __CLASS__, 'handle_webhook_ajax' ] );

        if ( ! clielo_is_premium() ) {
            return;
        }

        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );

        if ( self::is_enabled() ) {
            add_action( 'wp_ajax_clielo_stripe_checkout',         [ __CLASS__, 'ajax_create_checkout_session' ] );
            add_action( 'wp_ajax_clielo_stripe_checkout_pending', [ __CLASS__, 'ajax_create_checkout_pending' ] );
            add_action( 'template_redirect', [ __CLASS__, 'handle_return_redirect' ] );
        }
    }

    /* ─── Settings ─────────────────────────────────────────── */

    public static function get_settings(): array {
        $defaults = [
            'enabled'              => '0',
            'mode'                 => 'test',
            'test_publishable'     => '',
            'test_secret'          => '',
            'live_publishable'     => '',
            'live_secret'          => '',
            'webhook_secret'       => '',
            'currency'             => 'eur',
            'default_payment_mode' => 'single',
        ];
        return wp_parse_args( get_option( 'clielo_stripe_settings', [] ), $defaults );
    }

    public static function is_enabled(): bool {
        if ( ! clielo_is_premium() ) {
            return false;
        }
        $s = self::get_settings();
        return $s['enabled'] === '1' && ! empty( self::get_secret_key() );
    }

    public static function get_secret_key(): string {
        $s = self::get_settings();
        return $s['mode'] === 'live' ? $s['live_secret'] : $s['test_secret'];
    }

    public static function get_publishable_key(): string {
        $s = self::get_settings();
        return $s['mode'] === 'live' ? $s['live_publishable'] : $s['test_publishable'];
    }

    public static function get_webhook_url(): string {
        return admin_url( 'admin-ajax.php?action=clielo_stripe_webhook' );
    }

    public static function stripe(): \Stripe\StripeClient {
        return new \Stripe\StripeClient( [
            'api_key'        => self::get_secret_key(),
            'stripe_version' => self::STRIPE_API_VERSION,
        ] );
    }

    /* ─── Admin Menu & Settings Page ──────────────────────── */

    public static function add_menu(): void {
        add_submenu_page(
            'clielo',
            __( 'Clielo - Paiement', 'clielo' ),
            __( 'Paiement', 'clielo' ),
            'manage_options',
            'clielo-stripe',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( 'clielo_stripe_settings', 'clielo_stripe_settings', [
            'type'              => 'array',
            'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
        ] );
    }

    public static function sanitize_settings( $input ): array {
        $clean = [];
        $clean['enabled']          = ! empty( $input['enabled'] ) ? '1' : '0';
        $clean['mode']             = in_array( $input['mode'] ?? '', [ 'test', 'live' ], true ) ? $input['mode'] : 'test';
        $clean['test_publishable'] = sanitize_text_field( $input['test_publishable'] ?? '' );
        $clean['test_secret']      = sanitize_text_field( $input['test_secret'] ?? '' );
        $clean['live_publishable'] = sanitize_text_field( $input['live_publishable'] ?? '' );
        $clean['live_secret']      = sanitize_text_field( $input['live_secret'] ?? '' );
        $clean['webhook_secret']   = sanitize_text_field( $input['webhook_secret'] ?? '' );
        $clean['currency']             = sanitize_text_field( strtolower( $input['currency'] ?? 'eur' ) );
        $clean['default_payment_mode'] = in_array( $input['default_payment_mode'] ?? '', [ 'single', 'deposit', 'installments' ], true )
            ? $input['default_payment_mode']
            : 'single';
        return $clean;
    }

    public static function render_settings_page(): void {
        $s     = self::get_settings();
        $color = esc_attr( Clielo_Admin::get_color() );
        ?>
        <div class="wrap" style="max-width:750px">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:24px">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $color ); ?>" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                <?php esc_html_e( 'Paiement Stripe', 'clielo' ); ?>
            </h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'clielo_stripe_settings' ); ?>

                <?php // ── Activation ── ?>
                <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:20px 24px;margin-bottom:20px">
                    <label style="display:flex;align-items:center;gap:12px;cursor:pointer">
                        <input type="checkbox" name="clielo_stripe_settings[enabled]" value="1" <?php checked( $s['enabled'], '1' ); ?>
                               style="width:18px;height:18px" />
                        <div>
                            <strong style="font-size:15px"><?php esc_html_e( 'Activer les paiements Stripe', 'clielo' ); ?></strong>
                            <div style="color:#666;font-size:13px;margin-top:2px"><?php esc_html_e( 'Les clients paieront directement lors de la commande. Si désactivé, le flux manuel reste en place.', 'clielo' ); ?></div>
                        </div>
                    </label>
                </div>

                <?php // ── Mode ── ?>
                <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:20px 24px;margin-bottom:20px">
                    <h2 style="font-size:15px;margin:0 0 16px 0"><?php esc_html_e( 'Mode', 'clielo' ); ?></h2>
                    <select name="clielo_stripe_settings[mode]" style="width:100%;max-width:300px;padding:8px 12px;border:1px solid #d0d5dd;border-radius:6px">
                        <option value="test" <?php selected( $s['mode'], 'test' ); ?>><?php esc_html_e( 'Test (sandbox)', 'clielo' ); ?></option>
                        <option value="live" <?php selected( $s['mode'], 'live' ); ?>><?php esc_html_e( 'Production (live)', 'clielo' ); ?></option>
                    </select>
                    <?php if ( $s['mode'] === 'test' ) : ?>
                        <p style="margin:8px 0 0;color:#f59e0b;font-size:13px">&#9888; <?php esc_html_e( 'Mode test actif — aucun paiement réel ne sera effectué.', 'clielo' ); ?></p>
                    <?php endif; ?>
                </div>

                <?php // ── Clés API ── ?>
                <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:20px 24px;margin-bottom:20px">
                    <h2 style="font-size:15px;margin:0 0 16px 0"><?php esc_html_e( 'Clés API Test', 'clielo' ); ?></h2>
                    <?php self::render_field( 'test_publishable', __( 'Clé publique (pk_test_...)', 'clielo' ), $s['test_publishable'] ); ?>
                    <?php self::render_field( 'test_secret', __( 'Clé secrète (sk_test_...)', 'clielo' ), $s['test_secret'], true ); ?>

                    <h2 style="font-size:15px;margin:24px 0 16px 0"><?php esc_html_e( 'Clés API Production', 'clielo' ); ?></h2>
                    <?php self::render_field( 'live_publishable', __( 'Clé publique (pk_live_...)', 'clielo' ), $s['live_publishable'] ); ?>
                    <?php self::render_field( 'live_secret', __( 'Clé secrète (sk_live_...)', 'clielo' ), $s['live_secret'], true ); ?>
                </div>

                <?php // ── Webhook ── ?>
                <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:20px 24px;margin-bottom:20px">
                    <h2 style="font-size:15px;margin:0 0 16px 0"><?php esc_html_e( 'Webhook', 'clielo' ); ?></h2>

                    <div style="margin-bottom:20px">
                        <label style="display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:6px"><?php esc_html_e( 'URL du webhook', 'clielo' ); ?></label>
                        <div style="display:flex;gap:8px">
                            <input type="text" readonly value="<?php echo esc_attr( self::get_webhook_url() ); ?>" id="clielo-webhook-url"
                                   style="flex:1;padding:8px 12px;border:1px solid #d0d5dd;border-radius:6px;background:#f9fafb;font-family:monospace;font-size:12px" />
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('clielo-webhook-url').value);this.textContent='<?php esc_attr_e( 'Copié !', 'clielo' ); ?>';setTimeout(()=>{this.textContent='<?php esc_attr_e( 'Copier', 'clielo' ); ?>'},2000)"
                                    style="padding:8px 16px;border:1px solid #d0d5dd;border-radius:6px;background:#fff;cursor:pointer;font-size:13px"><?php esc_html_e( 'Copier', 'clielo' ); ?></button>
                        </div>
                    </div>

                    <?php // ── Instructions de configuration ── ?>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;margin-bottom:20px">
                        <div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:12px;display:flex;align-items:center;gap:6px">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#334155" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                            <?php esc_html_e( 'Comment configurer le webhook dans Stripe', 'clielo' ); ?>
                        </div>
                        <ol style="margin:0;padding:0 0 0 20px;font-size:13px;color:#475569;line-height:2">
                            <li><?php
                            printf(
                                /* translators: %s: HTML link to Stripe Dashboard */
                                esc_html__( 'Allez dans votre %s', 'clielo' ),
                                '<a href="https://dashboard.stripe.com/webhooks" target="_blank" rel="noopener" style="color:' . esc_attr( $color ) . ';font-weight:600">Stripe Dashboard &rarr; Developers &rarr; Webhooks</a>'
                            ); ?></li>
                            <li><?php esc_html_e( 'Cliquez sur "Ajouter un endpoint" (ou "Add endpoint")', 'clielo' ); ?></li>
                            <li><?php esc_html_e( 'Collez l\'URL du webhook ci-dessus dans le champ "Endpoint URL"', 'clielo' ); ?></li>
                            <li><?php esc_html_e( 'Dans "Select events to listen to", cliquez sur "Select events"', 'clielo' ); ?></li>
                            <li>
                                <?php esc_html_e( 'Cochez uniquement l\'événement suivant :', 'clielo' ); ?>
                                <code style="background:#e2e8f0;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;color:#1e293b">checkout.session.completed</code>
                            </li>
                            <li><?php esc_html_e( 'Cliquez sur "Add endpoint" pour sauvegarder', 'clielo' ); ?></li>
                            <li><?php esc_html_e( 'Sur la page du webhook, cliquez sur "Reveal" sous "Signing secret" et copiez la clé (whsec_...)', 'clielo' ); ?></li>
                            <li><?php esc_html_e( 'Collez cette clé dans le champ "Secret du webhook" ci-dessous', 'clielo' ); ?></li>
                        </ol>

                        <?php if ( $s['mode'] === 'test' ) : ?>
                        <div style="margin-top:12px;padding:10px 14px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;font-size:12px;color:#92400e;display:flex;align-items:flex-start;gap:8px">
                            <span style="flex-shrink:0;margin-top:1px">&#9888;</span>
                            <span><?php esc_html_e( 'En mode test, utilisez le Stripe Dashboard en mode "Test" (toggle en haut à droite) pour créer le webhook et obtenir les clés de test.', 'clielo' ); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php self::render_field( 'webhook_secret', __( 'Secret du webhook (whsec_...)', 'clielo' ), $s['webhook_secret'], true ); ?>
                </div>

                <?php // ── Mode de paiement par défaut ── ?>
                <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:20px 24px;margin-bottom:20px">
                    <h2 style="font-size:15px;margin:0 0 8px 0"><?php esc_html_e( 'Mode de paiement par défaut', 'clielo' ); ?></h2>
                    <p style="font-size:13px;color:#666;margin:0 0 16px 0"><?php esc_html_e( 'Applicable à tous les services. Peut être surchargé par service dans l\'éditeur.', 'clielo' ); ?></p>
                    <select name="clielo_stripe_settings[default_payment_mode]" style="width:100%;max-width:320px;padding:8px 12px;border:1px solid #d0d5dd;border-radius:6px">
                        <option value="single"       <?php selected( $s['default_payment_mode'] ?? 'single', 'single' ); ?>><?php esc_html_e( 'Paiement unique (100%)', 'clielo' ); ?></option>
                        <option value="deposit"      <?php selected( $s['default_payment_mode'] ?? 'single', 'deposit' ); ?>><?php esc_html_e( 'Acompte — 50% maintenant + 50% à la livraison', 'clielo' ); ?></option>
                        <option value="installments" <?php selected( $s['default_payment_mode'] ?? 'single', 'installments' ); ?>><?php esc_html_e( 'Mensualités — 40% maintenant + N mensualités', 'clielo' ); ?></option>
                    </select>
                </div>

                <?php // ── Devise ── ?>
                <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:20px 24px;margin-bottom:20px">
                    <h2 style="font-size:15px;margin:0 0 16px 0"><?php esc_html_e( 'Devise', 'clielo' ); ?></h2>
                    <select name="clielo_stripe_settings[currency]" style="width:100%;max-width:200px;padding:8px 12px;border:1px solid #d0d5dd;border-radius:6px">
                        <?php
                        $currencies = [ 'eur' => 'EUR (€)', 'usd' => 'USD ($)', 'gbp' => 'GBP (£)', 'chf' => 'CHF', 'cad' => 'CAD ($)', 'xof' => 'XOF (FCFA)' ];
                        foreach ( $currencies as $code => $label ) :
                        ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['currency'], $code ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php submit_button( __( 'Enregistrer', 'clielo' ) ); ?>
            </form>
        </div>
        <?php
    }

    private static function render_field( string $key, string $label, string $value, bool $secret = false ): void {
        $type = $secret ? 'password' : 'text';
        ?>
        <div style="margin-bottom:16px">
            <label style="display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:6px"><?php echo esc_html( $label ); ?></label>
            <input type="<?php echo esc_attr( $type ); ?>" name="clielo_stripe_settings[<?php echo esc_attr( $key ); ?>]"
                   value="<?php echo esc_attr( $value ); ?>"
                   style="width:100%;padding:8px 12px;border:1px solid #d0d5dd;border-radius:6px;font-family:monospace;font-size:13px" />
        </div>
        <?php
    }

    /* ─── AJAX : Créer une session Stripe Checkout ────────── */

    public static function ajax_create_checkout_session(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Non connecté.', 'clielo' ) ], 403 );
        }

        if ( current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Les administrateurs ne peuvent pas commander.', 'clielo' ) ], 403 );
        }

        $post_id           = absint( $_POST['post_id'] ?? 0 );
        $selected_pack_idx = absint( $_POST['selected_pack'] ?? 0 );
        $selected_indices  = array_map( 'absint', (array) json_decode( sanitize_text_field( wp_unslash( $_POST['selected_indices'] ?? '[]' ) ), true ) );
        $adv_data_raw      = isset( $_POST['advanced_options_data'] ) ? sanitize_text_field( wp_unslash( $_POST['advanced_options_data'] ) ) : '[]';
        $adv_data          = json_decode( $adv_data_raw, true );
        $adv_data          = is_array( $adv_data ) ? $adv_data : [];

        if ( ! $post_id || ! is_array( $selected_indices ) ) {
            wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'clielo' ) ], 400 );
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== Clielo_Admin::get_post_type() ) {
            wp_send_json_error( [ 'message' => __( 'Post invalide.', 'clielo' ) ], 400 );
        }

        $client_id = get_current_user_id();

        // Vérifier qu'il n'y a pas de commande active non modifiable
        $existing = Clielo_Orders::get_order_for_client( $post_id, $client_id );
        if ( $existing && ! in_array( $existing->status, [ Clielo_Orders::STATUS_PENDING, Clielo_Orders::STATUS_ACCEPTED ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Une commande est déjà en cours.', 'clielo' ) ], 403 );
        }

        // Récupérer le pack + options
        $packs    = Clielo_Options::get_packs( $post_id );
        $all_opts = Clielo_Options::get_options( $post_id );

        if ( empty( $packs ) || ! isset( $packs[ $selected_pack_idx ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Pack invalide.', 'clielo' ) ], 400 );
        }

        $base_offer = $packs[ $selected_pack_idx ];
        $selected   = [];
        foreach ( $selected_indices as $idx ) {
            $idx = absint( $idx );
            if ( isset( $all_opts[ $idx ] ) ) {
                $selected[] = $all_opts[ $idx ];
            }
        }

        $total_delay = absint( $base_offer['delay'] ?? 0 );
        foreach ( $selected as $opt ) {
            $total_delay += absint( $opt['delay'] ?? 0 );
        }

        // Calculer les extras depuis les options avancées dynamiques
        $extra_pages = 0; $extra_page_price = 0; $maintenance_price = 0; $express_days = 0; $express_price = 0;
        $adv_extra_total_ht = 0.0;
        foreach ( $adv_data as $sel ) {
            $qty   = absint( $sel['qty'] ?? 0 );
            $price = floatval( $sel['price'] ?? 0 );
            $mode  = $sel['mode'] ?? 'unit';
            if ( $mode === 'daily' ) {
                // Livraison express : délai ne peut pas descendre sous 45% du délai initial
                $min_delay    = (int) ceil( $total_delay * 0.45 );
                $max_days_off = $total_delay - $min_delay;
                $qty          = min( $qty, $max_days_off );
                if ( $qty > 0 ) {
                    $express_days += $qty;
                    $express_price = $price; // last daily price wins for legacy compat
                    $total_delay  -= $qty;
                }
            } else {
                $adv_extra_total_ht += $qty * $price;
            }
        }

        $settings           = self::get_settings();
        $currency           = $settings['currency'];
        $tax_rate           = floatval( Clielo_Invoices::get_settings()['tax_rate'] ?? 0 );
        $payment_mode       = Clielo_Options::get_payment_mode( $post_id );
        $installments_count = Clielo_Options::get_installments_count( $post_id );

        // Calculer le total TTC (un mois pour monthly, total contrat pour les autres)
        $single_ttc = Clielo_Payments::compute_total_ttc(
            $base_offer, $selected, $extra_pages, $extra_page_price,
            $maintenance_price, $express_days, $express_price, $tax_rate
        );
        // Ajouter les options avancées dynamiques (non-daily) au total TTC
        if ( $adv_extra_total_ht > 0 ) {
            $single_ttc += round( $adv_extra_total_ht * ( $tax_rate > 0 ? ( 1 + $tax_rate / 100 ) : 1.0 ), 2 );
        }

        // Pour 'monthly' : total contrat = tarif mensuel × N mois ; upfront = 1 mois
        if ( $payment_mode === 'monthly' ) {
            $full_total_ttc = round( $single_ttc * $installments_count, 2 );
            $upfront_amount = Clielo_Payments::get_monthly_fee( $full_total_ttc, $installments_count );
        } else {
            $full_total_ttc = $single_ttc;
            $upfront_amount = Clielo_Payments::get_upfront_amount( $full_total_ttc, $payment_mode );
        }

        // Libellé de paiement partiel ajouté au nom du pack (affiché sur Stripe Checkout)
        $pack_suffix = match ( $payment_mode ) {
            /* translators: deposit percentage label on checkout */
            'deposit'      => __( 'Acompte 50%', 'clielo' ),
            /* translators: first installment label on checkout */
            'installments' => __( 'Premier versement 40%', 'clielo' ),
            /* translators: %d: total number of monthly installments */
            'monthly'      => sprintf( __( 'Mois 1 / %d', 'clielo' ), $installments_count ),
            default        => '',
        };

        $line_items = self::build_line_items(
            $base_offer, $selected, $currency,
            $extra_pages, $extra_page_price, $maintenance_price,
            $express_days, $express_price, $tax_rate, $post_id,
            $adv_data,
            $payment_mode !== 'single' ? $upfront_amount : 0.0,
            $pack_suffix
        );

        $service_page = get_permalink( $post_id );
        $user         = wp_get_current_user();

        try {
            $stripe  = self::stripe();
            $session = $stripe->checkout->sessions->create( [
                'mode'                => 'payment',
                'customer_email'      => $user->user_email,
                'client_reference_id' => $post_id . '_' . $client_id,
                'line_items'          => $line_items,
                'metadata'            => [
                    'post_id'               => $post_id,
                    'client_id'             => $client_id,
                    'selected_pack'         => $selected_pack_idx,
                    'selected_indices'      => wp_json_encode( $selected_indices ),
                    'total_delay'           => $total_delay,
                    'extra_pages'           => $extra_pages,
                    'extra_page_price'      => $extra_page_price,
                    'maintenance_price'     => $maintenance_price,
                    'express_days'          => $express_days,
                    'express_price'         => $express_price,
                    'payment_mode'          => $payment_mode,
                    'installments_count'    => $installments_count,
                    'full_total_ttc'        => $full_total_ttc,
                    // Format compact (i/q/m/p) pour rester sous la limite Stripe de 500 chars par valeur de métadonnée
                    'advanced_options_data' => wp_json_encode( array_map( function( $sel ) {
                        return [ 'i' => absint( $sel['index'] ?? 0 ), 'q' => absint( $sel['qty'] ?? 0 ), 'm' => sanitize_text_field( $sel['mode'] ?? 'unit' ), 'p' => floatval( $sel['price'] ?? 0 ) ];
                    }, $adv_data ), JSON_UNESCAPED_UNICODE ),
                ],
                'success_url' => add_query_arg( [
                    'stripe_success' => '1',
                    'session_id'     => '{CHECKOUT_SESSION_ID}',
                ], $service_page ),
                'cancel_url' => add_query_arg( 'stripe_cancel', '1', $service_page ),
            ] );

            wp_send_json_success( [ 'checkout_url' => $session->url ] );

        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * AJAX : Créer une session Stripe pour une commande pending (devis accepté, paiement différé).
     * Prend une commande existante en status pending et génère la session Stripe pour l'upfront.
     */
    public static function ajax_create_checkout_pending(): void {
        check_ajax_referer( 'clielo_nonce', 'nonce' );

        if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Action non autorisée.', 'clielo' ) ], 403 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'clielo' ) ], 400 );
        }

        $order = Clielo_Orders::get_order( $order_id );
        if ( ! $order || $order->status !== Clielo_Orders::STATUS_PENDING || (int) $order->client_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'Commande introuvable.', 'clielo' ) ], 404 );
        }

        $post_id            = (int) $order->post_id;
        $base_offer         = json_decode( $order->base_offer ?? '{}', true ) ?: [];
        $payment_mode       = $order->payment_mode ?? 'single';
        $installments_count = (int) ( $order->installments_count ?? 0 );
        $full_total_ttc     = (float) $order->total_price;
        $tax_rate           = floatval( Clielo_Invoices::get_settings()['tax_rate'] ?? 0 );
        $settings           = self::get_settings();
        $currency           = $settings['currency'];

        // Calculer le montant upfront
        if ( $payment_mode === 'monthly' ) {
            $upfront_amount = Clielo_Payments::get_monthly_fee( $full_total_ttc, $installments_count );
        } else {
            $upfront_amount = Clielo_Payments::get_upfront_amount( $full_total_ttc, $payment_mode );
        }

        $pack_suffix = match ( $payment_mode ) {
            /* translators: deposit label on Stripe checkout */
            'deposit'      => __( 'Acompte 50%', 'clielo' ),
            /* translators: first installment label on Stripe checkout */
            'installments' => __( 'Premier versement 40%', 'clielo' ),
            /* translators: %d: number of installments */
            'monthly'      => sprintf( __( 'Mois 1 / %d', 'clielo' ), $installments_count ),
            default        => '',
        };

        $pack_name = ( $base_offer['name'] ?? get_the_title( $post_id ) ) . ' (#CMD-' . $order_id . ')';
        if ( $pack_suffix ) {
            $pack_name .= ' — ' . $pack_suffix;
        }

        // Décomposer TTC en HT + TVA pour Stripe
        $upfront_ht  = $tax_rate > 0 ? round( $upfront_amount / ( 1 + $tax_rate / 100 ), 2 ) : $upfront_amount;
        $upfront_tva = round( $upfront_amount - $upfront_ht, 2 );

        $line_items = [
            [
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => (int) round( $upfront_ht * 100 ),
                    'product_data' => [ 'name' => $pack_name ],
                ],
                'quantity' => 1,
            ],
        ];

        if ( $tax_rate > 0 && $upfront_tva > 0.005 ) {
            $line_items[] = [
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => (int) round( $upfront_tva * 100 ),
                    /* translators: %s: TVA percentage */
                    'product_data' => [ 'name' => sprintf( __( 'TVA %s%%', 'clielo' ), number_format( $tax_rate, 1 ) ) ],
                ],
                'quantity' => 1,
            ];
        }

        $service_page = get_permalink( $post_id );
        $user         = wp_get_current_user();

        try {
            $session = self::stripe()->checkout->sessions->create( [
                'mode'                => 'payment',
                'customer_email'      => $user->user_email,
                'client_reference_id' => $post_id . '_' . get_current_user_id(),
                'line_items'          => $line_items,
                'metadata'            => [
                    'existing_order_id'  => $order_id,
                    'post_id'            => $post_id,
                    'client_id'          => get_current_user_id(),
                    'payment_mode'       => $payment_mode,
                    'installments_count' => $installments_count,
                    'full_total_ttc'     => $full_total_ttc,
                ],
                'success_url' => add_query_arg( [
                    'stripe_success' => '1',
                    'session_id'     => '{CHECKOUT_SESSION_ID}',
                ], $service_page ),
                'cancel_url' => add_query_arg( 'stripe_cancel', '1', $service_page ),
            ] );

            wp_send_json_success( [ 'checkout_url' => $session->url ] );
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public static function build_line_items( array $base_offer, array $selected_options, string $currency, int $extra_pages = 0, float $extra_page_price = 0, float $maintenance_price = 0, int $express_days = 0, float $express_price = 0, float $tax_rate = 0.0, int $post_id = 0, array $adv_data = [], float $upfront_amount = 0.0, string $pack_suffix = '' ): array {
        $items    = [];
        $total_ht = 0.0;

        // Pack principal
        $pack_ht   = floatval( $base_offer['price'] ?? 0 );
        $total_ht += $pack_ht;
        $pack_name = $base_offer['name'] ?? __( 'Pack', 'clielo' );
        if ( $pack_suffix ) {
            $pack_name .= ' — ' . $pack_suffix;
        }
        $items[] = [
            'price_data' => [
                'currency'     => $currency,
                'unit_amount'  => (int) round( $pack_ht * 100 ),
                'product_data' => [ 'name' => $pack_name ],
            ],
            'quantity' => 1,
        ];

        // Options supplémentaires
        foreach ( $selected_options as $opt ) {
            $opt_ht   = floatval( $opt['price'] ?? 0 );
            $total_ht += $opt_ht;
            $items[]  = [
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => (int) round( $opt_ht * 100 ),
                    'product_data' => [ 'name' => $opt['name'] ?? __( 'Option', 'clielo' ) ],
                ],
                'quantity' => 1,
            ];
        }

        // Pages supplémentaires
        if ( $extra_pages > 0 && $extra_page_price > 0 ) {
            $total_ht += $extra_pages * $extra_page_price;
            $items[]   = [
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => (int) round( $extra_page_price * 100 ),
                    'product_data' => [ 'name' => Clielo_Admin::get_extra_pages_label( $post_id ) ],
                ],
                'quantity' => $extra_pages,
            ];
        }

        // Maintenance mensuelle
        if ( $maintenance_price > 0 ) {
            $total_ht += $maintenance_price;
            $items[]   = [
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => (int) round( $maintenance_price * 100 ),
                    'product_data' => [ 'name' => Clielo_Admin::get_maintenance_label( $post_id ) ],
                ],
                'quantity' => 1,
            ];
        }

        // Livraison express
        if ( $express_days > 0 && $express_price > 0 ) {
            $total_ht += $express_days * $express_price;
            $items[]   = [
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => (int) round( $express_price * 100 ),
                    'product_data' => [ 'name' => Clielo_Admin::get_express_label( $post_id ) ],
                ],
                'quantity' => $express_days,
            ];
        }

        // Options avancées dynamiques (non-daily)
        foreach ( $adv_data as $sel ) {
            $qty   = absint( $sel['qty'] ?? 0 );
            $price = floatval( $sel['price'] ?? 0 );
            $mode  = $sel['mode'] ?? 'unit';
            $lbl   = sanitize_text_field( $sel['label'] ?? __( 'Option', 'clielo' ) );
            if ( $mode === 'daily' || ! $qty || ! $price ) {
                continue;
            }
            $total_ht += $qty * $price;
            $items[]   = [
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => (int) round( $price * 100 ),
                    'product_data' => [ 'name' => $lbl ],
                ],
                'quantity' => $qty,
            ];
        }

        // Appliquer le facteur de réduction si paiement partiel (dépôt / versement / mensualité)
        $ht_sum_cents = 0;
        if ( $upfront_amount > 0.0 && $total_ht > 0 ) {
            $full_ttc = $total_ht * ( $tax_rate > 0 ? ( 1 + $tax_rate / 100 ) : 1.0 );
            $scale    = $upfront_amount / $full_ttc;
            foreach ( $items as &$item ) {
                $scaled = (int) round( $item['price_data']['unit_amount'] * $scale );
                $item['price_data']['unit_amount'] = $scaled;
                $ht_sum_cents += $scaled * (int) $item['quantity'];
            }
            unset( $item );
        } else {
            foreach ( $items as $item ) {
                $ht_sum_cents += $item['price_data']['unit_amount'] * (int) $item['quantity'];
            }
        }

        // Ligne TVA séparée
        if ( $tax_rate > 0 ) {
            if ( $upfront_amount > 0.0 ) {
                // Assure que la somme finale = upfront_amount exactement
                $tax_cents = (int) round( $upfront_amount * 100 ) - $ht_sum_cents;
            } else {
                $tax_cents = (int) round( $total_ht * $tax_rate / 100 * 100 );
            }
            if ( $tax_cents > 0 ) {
                $items[] = [
                    'price_data' => [
                        'currency'     => $currency,
                        'unit_amount'  => $tax_cents,
                        'product_data' => [
                            /* translators: %s: tax rate percentage, e.g. "20" */
                            'name' => sprintf( __( 'TVA (%s%%)', 'clielo' ), $tax_rate ),
                        ],
                    ],
                    'quantity' => 1,
                ];
            }
        }

        return $items;
    }

    /* ─── Webhook : Stripe envoie la confirmation (admin-ajax) ─ */

    public static function register_webhook_route(): void {
        // Conservé pour compatibilité ascendante si l'URL REST était déjà configurée
        if ( function_exists( 'register_rest_route' ) ) {
            register_rest_route( 'clielo/v1', '/stripe-webhook', [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_webhook_rest' ],
                'permission_callback' => '__return_true',
            ] );
        }
    }

    /** Entrée REST (compatibilité) */
    public static function handle_webhook_rest( WP_REST_Request $request ): WP_REST_Response {
        $payload    = $request->get_body();
        $sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stripe signature is validated by the Stripe SDK
        $result     = self::process_webhook_payload( $payload, $sig_header );

        return new WP_REST_Response( $result['body'], $result['status'] );
    }

    /** Entrée admin-ajax (principal) — fonctionne sans pretty permalinks ni REST API */
    public static function handle_webhook_ajax(): void {
        $payload    = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- php://input required for Stripe webhook signature verification
        $sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stripe signature is validated by the Stripe SDK
        $result     = self::process_webhook_payload( $payload, $sig_header );

        status_header( $result['status'] );
        header( 'Content-Type: application/json' );
        echo wp_json_encode( $result['body'] );
        exit;
    }

    /** Logique commune webhook (REST + admin-ajax) */
    private static function process_webhook_payload( string $payload, string $sig_header ): array {
        $settings = self::get_settings();

        if ( empty( $settings['webhook_secret'] ) ) {
            return [ 'status' => 400, 'body' => [ 'error' => 'Webhook secret not configured' ] ];
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $settings['webhook_secret']
            );
        } catch ( \Exception $e ) {
            return [ 'status' => 400, 'body' => [ 'error' => 'Invalid signature' ] ];
        }

        if ( $event->type !== 'checkout.session.completed' ) {
            return [ 'status' => 200, 'body' => [ 'status' => 'ignored' ] ];
        }

        self::process_completed_session( $event->data->object );

        return [ 'status' => 200, 'body' => [ 'status' => 'ok' ] ];
    }

    /**
     * Traite le paiement d'une ligne d'échéancier via Stripe (client a cliqué "Payer").
     */
    private static function process_schedule_payment_session( $session ): void {
        $meta        = $session->metadata;
        $schedule_id = (int) ( $meta->sf_schedule_id ?? 0 );
        if ( ! $schedule_id || ! class_exists( 'Clielo_Payments' ) ) {
            return;
        }

        $row = Clielo_Payments::get_row( $schedule_id );
        if ( ! $row || $row->status === 'paid' ) {
            return; // Idempotence
        }

        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            Clielo_Payments::table_name(),
            [
                'status'            => 'paid',
                'stripe_session_id' => $session->id,
                'paid_at'           => current_time( 'mysql' ),
            ],
            [ 'id' => $schedule_id ]
        );

        // Créer la facture partielle
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
    }

    private static function build_order_summary_message( array $base_offer, array $selected, float $total_price, int $total_delay, int $extra_pages = 0, float $extra_page_price = 0, float $maintenance_price = 0, int $express_days = 0, float $express_price = 0, string $payment_mode = 'single', float $upfront_paid = 0.0, int $post_id = 0, int $installments_count = 0, string $invoice_number = '' ): string {
        $lines   = [];
        $lines[] = "\xF0\x9F\x93\x8B " . __( 'Ma sélection :', 'clielo' );

        $p_delay = absint( $base_offer['delay'] ?? 0 );
        $p_str   = $p_delay > 0 ? " (\xE2\x8F\xB1 {$p_delay}j)" : '';
        $lines[] = "\xF0\x9F\x93\xA6 " . ( $base_offer['name'] ?? 'Pack' )
                 . ' — ' . number_format( (float) ( $base_offer['price'] ?? 0 ), 2, ',', ' ' ) . " \xE2\x82\xAC" . $p_str;

        foreach ( $selected as $opt ) {
            $d   = absint( $opt['delay'] ?? 0 );
            $ds  = $d > 0 ? " (+{$d}j)" : '';
            $lines[] = "\xE2\x9C\x85 " . ( $opt['name'] ?? 'Option' )
                     . ' — ' . number_format( (float) ( $opt['price'] ?? 0 ), 2, ',', ' ' ) . " \xE2\x82\xAC" . $ds;
        }

        // Pages supplémentaires
        if ( $extra_pages > 0 && $extra_page_price > 0 ) {
            $pages_total = $extra_pages * $extra_page_price;
            $lines[] = "\xF0\x9F\x93\x84 " . sprintf(
                /* translators: %1$s: extra pages label, %2$d: number of pages, %3$s: unit price, %4$s: total price */
                __( '%1$s : %2$d × %3$s = %4$s', 'clielo' ),
                Clielo_Admin::get_extra_pages_label( $post_id ),
                $extra_pages,
                number_format( $extra_page_price, 2, ',', ' ' ) . " \xE2\x82\xAC",
                number_format( $pages_total, 2, ',', ' ' ) . " \xE2\x82\xAC"
            );
        }

        // Livraison express
        if ( $express_days > 0 && $express_price > 0 ) {
            $express_total = $express_days * $express_price;
            $lines[] = "\xE2\x9A\xA1 " . sprintf(
                /* translators: %1$s: express label, %2$d: number of days saved, %3$s: express delivery total cost */
                __( '%1$s : -%2$d jour(s) — %3$s', 'clielo' ),
                Clielo_Admin::get_express_label( $post_id ),
                $express_days,
                number_format( $express_total, 2, ',', ' ' ) . " \xE2\x82\xAC"
            );
        }

        // Maintenance mensuelle
        if ( $maintenance_price > 0 ) {
            $lines[] = "\xF0\x9F\x94\xA7 " . sprintf(
                /* translators: %1$s: maintenance label, %2$s: formatted monthly maintenance price */
                __( '%1$s : %2$s / mois', 'clielo' ),
                Clielo_Admin::get_maintenance_label( $post_id ),
                number_format( $maintenance_price, 2, ',', ' ' ) . " \xE2\x82\xAC"
            );
        }

        // Mode de paiement
        $mode_labels = [
            'single'       => __( 'Paiement unique', 'clielo' ),
            'deposit'      => __( 'Acompte 50% + solde à la livraison', 'clielo' ),
            'installments' => __( 'Mensualités', 'clielo' ),
            'monthly'      => __( 'Abonnement mensuel', 'clielo' ),
        ];
        $lines[] = "\xF0\x9F\x92\xB3 " . ( $mode_labels[ $payment_mode ] ?? __( 'Paiement unique', 'clielo' ) );

        // Ventilation selon le mode
        if ( $payment_mode === 'single' || $upfront_paid <= 0 ) {
            $lines[] = "\xF0\x9F\x92\xB0 " . __( 'Total :', 'clielo' ) . ' ' . number_format( $total_price, 2, ',', ' ' ) . " \xE2\x82\xAC";
            $lines[] = "\xE2\x9C\x85 " . __( 'Payé par Stripe', 'clielo' );
        } elseif ( $payment_mode === 'monthly' ) {
            $n_months = $upfront_paid > 0 ? (int) round( $total_price / $upfront_paid ) : 1;
            /* translators: %s: formatted monthly fee */
            $lines[] = "\xF0\x9F\x92\xB0 " . sprintf( __( 'Tarif mensuel : %s', 'clielo' ), number_format( $upfront_paid, 2, ',', ' ' ) . " \xE2\x82\xAC" );
            /* translators: %1$d: number of months, %2$s: total price */
            $lines[] = "\xF0\x9F\x93\x85 " . sprintf( __( 'Durée : %1$d mois (total : %2$s)', 'clielo' ), $n_months, number_format( $total_price, 2, ',', ' ' ) . " \xE2\x82\xAC" );
            $lines[] = "\xE2\x9C\x85 " . __( 'Mois 1 payé via Stripe', 'clielo' );
        } elseif ( $payment_mode === 'deposit' ) {
            $balance = round( $total_price - $upfront_paid, 2 );
            $lines[] = "\xF0\x9F\x92\xB0 " . __( 'Total contrat :', 'clielo' ) . ' ' . number_format( $total_price, 2, ',', ' ' ) . " \xE2\x82\xAC";
            /* translators: %s: deposit amount paid */
            $lines[] = "\xE2\x9C\x85 " . sprintf( __( 'Acompte versé (50%) : %s', 'clielo' ), number_format( $upfront_paid, 2, ',', ' ' ) . " \xE2\x82\xAC" );
            /* translators: %s: balance amount due at delivery */
            $lines[] = "\xF0\x9F\x94\x9C " . sprintf( __( 'Solde à la livraison (50%) : %s', 'clielo' ), number_format( $balance, 2, ',', ' ' ) . " \xE2\x82\xAC" );
        } else {
            // installments (40% upfront + N mensualités)
            $n        = max( 1, $installments_count );
            $balance  = round( $total_price - $upfront_paid, 2 );
            $monthly  = $n > 0 ? round( $balance / $n, 2 ) : $balance;
            $lines[] = "\xF0\x9F\x92\xB0 " . __( 'Total contrat :', 'clielo' ) . ' ' . number_format( $total_price, 2, ',', ' ' ) . " \xE2\x82\xAC";
            /* translators: %s: first installment amount paid */
            $lines[] = "\xE2\x9C\x85 " . sprintf( __( 'Premier versement (40%) : %s', 'clielo' ), number_format( $upfront_paid, 2, ',', ' ' ) . " \xE2\x82\xAC" );
            /* translators: %1$d: number of installments, %2$s: monthly amount */
            $lines[] = "\xF0\x9F\x94\x9C " . sprintf( __( 'Puis %1$d × %2$s / mois', 'clielo' ), $n, number_format( $monthly, 2, ',', ' ' ) . " \xE2\x82\xAC" );
        }

        if ( $total_delay > 0 ) {
            $lines[] = "\xE2\x8F\xB0 " . __( 'Délai total', 'clielo' ) . ' : ' . $total_delay . ' ' . __( 'jour(s)', 'clielo' );
        }

        if ( $invoice_number !== '' ) {
            /* translators: %s: invoice reference number */
            $lines[] = "\xF0\x9F\xA7\xBE " . sprintf( __( 'Facture : %s', 'clielo' ), $invoice_number );
        }

        return implode( "\n", $lines );
    }

    /* ─── Retour client depuis Stripe ─────────────────────── */

    public static function handle_return_redirect(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Stripe return URL, nonce not applicable (redirect from Stripe).
        if ( empty( $_GET['stripe_success'] ) ) {
            return;
        }

        $session_id = sanitize_text_field( wp_unslash( $_GET['session_id'] ?? '' ) );
        if ( ! $session_id ) {
            return;
        }

        // Éviter le double traitement si le webhook a déjà agi
        global $wpdb;
        $table  = Clielo_Orders::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT id FROM {$table} WHERE stripe_session_id = %s LIMIT 1",
            $session_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( $exists ) {
            return; // Déjà traité par le webhook → rien à faire
        }

        // Fallback : interroger Stripe directement pour créer la commande
        try {
            $stripe  = self::stripe();
            $session = $stripe->checkout->sessions->retrieve( $session_id );

            if ( $session->payment_status !== 'paid' ) {
                return;
            }

            self::process_completed_session( $session );

        } catch ( \Exception $e ) {
            // silent — fallback non critique
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /* ─── Logique commune webhook + fallback ───────────────── */

    public static function process_completed_session( $session ): void {
        $meta = $session->metadata;

        // Paiement d'une ligne d'échéancier (send_payment_link)
        if ( isset( $meta->sf_schedule_id ) ) {
            self::process_schedule_payment_session( $session );
            return;
        }

        // Paiement d'une commande pending issue d'un devis (quote → pending → paid)
        if ( isset( $meta->existing_order_id ) && (int) $meta->existing_order_id > 0 ) {
            self::process_pending_order_payment( $session );
            return;
        }

        global $wpdb;
        $table  = Clielo_Orders::table_name();

        // Idempotence
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT id FROM {$table} WHERE stripe_session_id = %s LIMIT 1",
            $session->id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $exists ) {
            return;
        }

        $post_id           = (int) ( $meta->post_id ?? 0 );
        $client_id         = (int) ( $meta->client_id ?? 0 );
        $selected_pack_idx = (int) ( $meta->selected_pack ?? 0 );
        $selected_indices  = json_decode( $meta->selected_indices ?? '[]', true );
        $total_delay       = (int) ( $meta->total_delay ?? 0 );
        $extra_pages            = (int) ( $meta->extra_pages ?? 0 );
        $extra_page_price       = (float) ( $meta->extra_page_price ?? 0 );
        $maintenance_price      = (float) ( $meta->maintenance_price ?? 0 );
        $express_days           = (int) ( $meta->express_days ?? 0 );
        $express_price          = (float) ( $meta->express_price ?? 0 );
        $advanced_options_data  = (string) ( $meta->advanced_options_data ?? '' );

        if ( ! $post_id || ! $client_id ) {
            return;
        }

        // Reconstituer les libellés depuis le post meta si format compact (clés i/q/m/p)
        if ( ! empty( $advanced_options_data ) ) {
            $adv_compact = json_decode( $advanced_options_data, true ) ?: [];
            if ( ! empty( $adv_compact ) && array_key_exists( 'i', (array) ( $adv_compact[0] ?? [] ) ) ) {
                $adv_meta_raw  = get_post_meta( $post_id, '_clielo_advanced_options', true );
                $post_adv_opts = is_array( $adv_meta_raw ) ? $adv_meta_raw : ( json_decode( (string) ( $adv_meta_raw ?: '[]' ), true ) ?: [] );
                $adv_full = [];
                foreach ( $adv_compact as $item ) {
                    $idx        = absint( $item['i'] ?? 0 );
                    $adv_full[] = [
                        'index' => $idx,
                        'qty'   => absint( $item['q'] ?? 0 ),
                        'mode'  => sanitize_text_field( $item['m'] ?? 'unit' ),
                        'price' => floatval( $item['p'] ?? 0 ),
                        'label' => sanitize_text_field( $post_adv_opts[ $idx ]['label'] ?? '' ),
                    ];
                }
                $advanced_options_data = wp_json_encode( $adv_full, JSON_UNESCAPED_UNICODE );
            }
        }

        $packs    = Clielo_Options::get_packs( $post_id );
        $all_opts = Clielo_Options::get_options( $post_id );

        if ( empty( $packs ) || ! isset( $packs[ $selected_pack_idx ] ) ) {
            return;
        }

        $base_offer = $packs[ $selected_pack_idx ];
        $selected   = [];
        foreach ( (array) $selected_indices as $idx ) {
            if ( isset( $all_opts[ $idx ] ) ) {
                $selected[] = $all_opts[ $idx ];
            }
        }

        // total_price = valeur totale du contrat (pas juste l'upfront)
        $payment_mode       = $meta->payment_mode ?? 'single';
        $installments_count = (int) ( $meta->installments_count ?? 0 );
        $full_total_ttc     = floatval( $meta->full_total_ttc ?? 0 );
        $total_price        = $full_total_ttc > 0 ? $full_total_ttc : ( $session->amount_total / 100 );

        $payment_context = [
            'payment_mode'       => $payment_mode,
            'deposit_percent'    => $payment_mode === 'deposit' ? 50 : ( $payment_mode === 'installments' ? 40 : 100 ),
            'installments_count' => $installments_count,
        ];

        $order_id = Clielo_Orders::create_order_paid(
            $post_id,
            $client_id,
            $base_offer,
            $selected,
            $total_price,
            $total_delay,
            $session->id,
            $session->payment_intent ?? '',
            $payment_context,
            $extra_pages,
            $extra_page_price,
            $maintenance_price,
            $express_days,
            $express_price,
            $advanced_options_data
        );

        if ( $order_id ) {
            $order   = Clielo_Orders::get_order( $order_id );
            $message = Clielo_Orders::format_status_message( 'started', $order, '', '' );
            if ( $message ) {
                Clielo_DB::insert_message( $post_id, 0, $message, $client_id );
            }

            do_action( 'clielo_order_created', $order_id, $post_id, $client_id );
            do_action( 'clielo_order_status_changed', $order_id, 'started', '', 0 );

            // Créer échéancier + facture AVANT le message récap pour inclure la référence.
            $invoice_number = '';
            if ( $payment_mode !== 'single' ) {
                Clielo_Payments::create_schedule_for_order( $order_id );

                if ( class_exists( 'Clielo_Invoices' ) ) {
                    $schedule = Clielo_Payments::get_schedule_for_order( $order_id );
                    foreach ( $schedule as $row ) {
                        // deposit/installments : ligne 'upfront' | monthly : installment_no=1 paid
                        if ( $row->type === 'upfront' || ( $payment_mode === 'monthly' && (int) $row->installment_no === 1 ) ) {
                            $inv_type  = $payment_mode === 'monthly' ? 'mensualite' : 'acompte';
                            $inv_id    = Clielo_Invoices::create_partial_invoice(
                                $order_id,
                                floatval( $row->amount_ttc ),
                                $inv_type,
                                (int) $row->id,
                                (int) $row->installment_no
                            );
                            if ( $inv_id ) {
                                $inv = Clielo_Invoices::get_invoice( $inv_id );
                                if ( $inv ) {
                                    $invoice_number = $inv->invoice_number;
                                }
                            }
                            break;
                        }
                    }
                }
            } elseif ( class_exists( 'Clielo_Invoices' ) ) {
                // Paiement unique : générer la facture complète immédiatement (déjà payée).
                Clielo_Invoices::on_order_accepted( $order_id, Clielo_Orders::STATUS_ACCEPTED, Clielo_Orders::STATUS_STARTED, $client_id );
                global $wpdb;
                $inv_table = Clielo_Invoices::invoices_table_name();
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $inv = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_number FROM {$inv_table} WHERE order_id = %d AND status != 'cancelled' ORDER BY id DESC LIMIT 1", $order_id ) );
                if ( $inv ) {
                    $invoice_number = $inv->invoice_number;
                }
            }

            $upfront_paid = $payment_mode === 'monthly'
                ? Clielo_Payments::get_monthly_fee( $total_price, $installments_count )
                : Clielo_Payments::get_upfront_amount( $total_price, $payment_mode );
            $summary = self::build_order_summary_message( $base_offer, $selected, $total_price, $total_delay, $extra_pages, $extra_page_price, $maintenance_price, $express_days, $express_price, $payment_mode, $upfront_paid, $post_id, $installments_count, $invoice_number );
            Clielo_DB::insert_message( $post_id, $client_id, $summary, $client_id );
        }
    }

    /**
     * Traite le paiement Stripe d'une commande pending issue d'un devis accepté.
     * Identique au flux principal mais mise à jour de la commande existante au lieu d'en créer une.
     */
    private static function process_pending_order_payment( $session ): void {
        $meta     = $session->metadata;
        $order_id = (int) ( $meta->existing_order_id ?? 0 );
        if ( ! $order_id ) {
            return;
        }

        $order = Clielo_Orders::get_order( $order_id );
        if ( ! $order || $order->status !== Clielo_Orders::STATUS_PENDING ) {
            return;
        }

        // Idempotence
        if ( $order->stripe_session_id === $session->id ) {
            return;
        }

        $post_id            = (int) $order->post_id;
        $client_id          = (int) $order->client_id;
        $base_offer         = json_decode( $order->base_offer ?? '{}', true ) ?: [];
        $selected           = json_decode( $order->selected_options ?? '[]', true ) ?: [];
        $payment_mode       = (string) ( $meta->payment_mode ?? $order->payment_mode ?? 'single' );
        $installments_count = (int) ( $meta->installments_count ?? $order->installments_count ?? 0 );
        $full_total_ttc     = floatval( $meta->full_total_ttc ?? $order->total_price );
        $total_delay        = (int) $order->total_delay;

        $payment_context = [
            'payment_mode'       => $payment_mode,
            'deposit_percent'    => $payment_mode === 'deposit' ? 50 : ( $payment_mode === 'installments' ? 40 : 100 ),
            'installments_count' => $installments_count,
        ];

        // create_order_paid() détecte l'ordre pending existant et le met à jour → started
        $result_id = Clielo_Orders::create_order_paid(
            $post_id,
            $client_id,
            $base_offer,
            $selected,
            $full_total_ttc,
            $total_delay,
            $session->id,
            $session->payment_intent ?? '',
            $payment_context,
            (int) ( $order->extra_pages ?? 0 ),
            (float) ( $order->extra_page_price ?? 0 ),
            (float) ( $order->maintenance_price ?? 0 ),
            (int) ( $order->express_days ?? 0 ),
            (float) ( $order->express_price ?? 0 ),
            (string) ( $order->advanced_options_data ?? '' )
        );

        if ( ! $result_id ) {
            return;
        }

        $order = Clielo_Orders::get_order( $result_id );

        do_action( 'clielo_order_status_changed', $result_id, 'started', 'pending', 0 );

        // Échéancier + facture partielle pour les modes non-single
        $invoice_number = '';
        if ( $payment_mode !== 'single' ) {
            Clielo_Payments::create_schedule_for_order( $result_id );
            if ( class_exists( 'Clielo_Invoices' ) ) {
                $schedule = Clielo_Payments::get_schedule_for_order( $result_id );
                foreach ( $schedule as $row ) {
                    if ( $row->type === 'upfront' || ( $payment_mode === 'monthly' && (int) $row->installment_no === 1 ) ) {
                        $inv_type = $payment_mode === 'monthly' ? 'mensualite' : 'acompte';
                        $inv_id   = Clielo_Invoices::create_partial_invoice(
                            $result_id,
                            floatval( $row->amount_ttc ),
                            $inv_type,
                            (int) $row->id,
                            (int) $row->installment_no
                        );
                        if ( $inv_id ) {
                            $inv = Clielo_Invoices::get_invoice( $inv_id );
                            if ( $inv ) {
                                $invoice_number = $inv->invoice_number;
                            }
                        }
                        break;
                    }
                }
            }
        } elseif ( class_exists( 'Clielo_Invoices' ) ) {
            Clielo_Invoices::on_order_accepted( $result_id, Clielo_Orders::STATUS_ACCEPTED, Clielo_Orders::STATUS_STARTED, $client_id );
            global $wpdb;
            $inv_table = Clielo_Invoices::invoices_table_name();
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $inv = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_number FROM {$inv_table} WHERE order_id = %d AND status != 'cancelled' ORDER BY id DESC LIMIT 1", $result_id ) );
            if ( $inv ) {
                $invoice_number = $inv->invoice_number;
            }
        }

        // Message récap dans le chat
        $upfront_paid = $payment_mode === 'monthly'
            ? Clielo_Payments::get_monthly_fee( $full_total_ttc, $installments_count )
            : Clielo_Payments::get_upfront_amount( $full_total_ttc, $payment_mode );

        $summary = self::build_order_summary_message(
            $base_offer, $selected, $full_total_ttc, $total_delay,
            (int) ( $order->extra_pages ?? 0 ),
            (float) ( $order->extra_page_price ?? 0 ),
            (float) ( $order->maintenance_price ?? 0 ),
            (int) ( $order->express_days ?? 0 ),
            (float) ( $order->express_price ?? 0 ),
            $payment_mode, $upfront_paid, $post_id, $installments_count, $invoice_number
        );
        Clielo_DB::insert_message( $post_id, $client_id, $summary, $client_id );
    }
}
