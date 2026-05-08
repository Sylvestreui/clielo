<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Front {

    public static function init(): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_footer', [ __CLASS__, 'render_chat' ], 9 );
        add_shortcode( 'clielo_options', [ __CLASS__, 'shortcode_options' ] );
    }

    public static function enqueue_assets(): void {
        if ( ! self::is_chat_page() ) {
            return;
        }

        wp_enqueue_style(
            'clielo-inter',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap',
            [],
            CLIELO_VERSION
        );

        wp_enqueue_style(
            'clielo-style',
            CLIELO_PLUGIN_URL . 'assets/css/clielo.css',
            [ 'clielo-inter' ],
            CLIELO_VERSION
        );

        if ( ! wp_script_is( 'clielo-chat-js', 'registered' ) ) {
            wp_register_script( 'clielo-chat-js', false, [ 'jquery' ], CLIELO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }
        wp_enqueue_script( 'clielo-chat-js' );

        // Inline CSS dynamique basé sur la couleur du plugin
        $c     = esc_attr( Clielo_Admin::get_color() );
        $hex   = ltrim( $c, '#' );
        $r_int = hexdec( substr( $hex, 0, 2 ) );
        $g_int = hexdec( substr( $hex, 2, 2 ) );
        $b_int = hexdec( substr( $hex, 4, 2 ) );
        $c_muted = sprintf( 'rgba(%d,%d,%d,0.7)', $r_int, $g_int, $b_int );

        wp_add_inline_style( 'clielo-style',
            '.clielo-info-tip{position:relative !important;cursor:help !important;display:inline-flex !important;align-items:center !important;color:#aaa !important}' .
            '.clielo-info-tip .clielo-tip-text{visibility:hidden !important;opacity:0 !important;position:absolute !important;left:50% !important;bottom:calc(100% + 8px) !important;transform:translateX(-50%) !important;background:#333 !important;color:#fff !important;font-size:12px !important;font-weight:400 !important;line-height:1.4 !important;padding:8px 12px !important;border-radius:6px !important;max-width:350px !important;width:max-content !important;z-index:100 !important;pointer-events:none !important;transition:opacity .15s !important;box-shadow:0 2px 8px rgba(0,0,0,0.15) !important;text-align:left !important}' .
            '.clielo-info-tip .clielo-tip-text::after{content:"" !important;position:absolute !important;top:100% !important;left:50% !important;transform:translateX(-50%) !important;border:5px solid transparent !important;border-top-color:#333 !important}' .
            '.clielo-info-tip:hover .clielo-tip-text{visibility:visible !important;opacity:1 !important}' .
            '.clielo-sc-check{-webkit-appearance:none !important;-moz-appearance:none !important;appearance:none !important;width:18px !important;height:18px !important;min-width:18px !important;border:2px solid #ccc !important;border-radius:4px !important;background:#fff !important;cursor:pointer !important;position:relative !important;transition:all .15s !important;margin:2px 0 0 0 !important;flex-shrink:0 !important}' .
            '.clielo-sc-check:checked{background:' . $c . ' !important;border-color:' . $c . ' !important}' .
            '.clielo-sc-check:checked::after{content:"" !important;position:absolute !important;left:5px !important;top:1px !important;width:5px !important;height:10px !important;border:solid #fff !important;border-width:0 2px 2px 0 !important;transform:rotate(45deg) !important}' .
            '#clielo-sc-card{scrollbar-width:none !important}' .
            '#clielo-sc-card::-webkit-scrollbar{width:1px !important}' .
            '#clielo-sc-card::-webkit-scrollbar-track{background:transparent !important}' .
            '#clielo-sc-card::-webkit-scrollbar-thumb{background:rgba(0,0,0,0.15) !important;border-radius:1px !important}' .
            '#clielo-sc-card:hover::-webkit-scrollbar{width:2px !important}' .
            '#clielo-sc-card:hover::-webkit-scrollbar-thumb{background:' . $c_muted . ' !important}' .
            '@media (min-width: 768px) { #clielo-container { min-height: 520px !important; max-height: 680px !important; } }'
        );
    }

    /**
     * Renders the service options card (packs, options, order button).
     *
     * @param array $args {
     *     @type int    $post_id           Post ID. Defaults to queried object.
     *     @type string $color             Hex color. Defaults to plugin setting.
     *     @type bool   $show_order_button Whether to show total + order button. Default true.
     * }
     */
    public static function render_options_card( array $args = [] ): string {
        $post_id           = ! empty( $args['post_id'] ) ? absint( $args['post_id'] ) : get_queried_object_id();
        $show_order_button = isset( $args['show_order_button'] ) ? (bool) $args['show_order_button'] : true;
        $packs             = Clielo_Options::get_packs( $post_id );
        $options           = Clielo_Options::get_options( $post_id );
        $color             = esc_attr( ! empty( $args['color'] ) ? $args['color'] : Clielo_Admin::get_color() );

        if ( empty( $packs ) ) {
            return '';
        }

        if ( ! wp_style_is( 'clielo-style', 'enqueued' ) ) {
            wp_enqueue_style( 'clielo-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap', [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
            wp_enqueue_style( 'clielo-style', CLIELO_PLUGIN_URL . 'assets/css/clielo.css', [ 'clielo-inter' ], CLIELO_VERSION );
            $hex_e = ltrim( $color, '#' );
            $r_e   = absint( hexdec( substr( $hex_e, 0, 2 ) ) );
            $g_e   = absint( hexdec( substr( $hex_e, 2, 2 ) ) );
            $b_e   = absint( hexdec( substr( $hex_e, 4, 2 ) ) );
            wp_add_inline_style( 'clielo-style',
                '.clielo-sc-check:checked{background:' . esc_attr( $color ) . ' !important;border-color:' . esc_attr( $color ) . ' !important}' .
                '#clielo-sc-card:hover::-webkit-scrollbar-thumb{background:rgba(' . $r_e . ',' . $g_e . ',' . $b_e . ',0.7) !important}'
            );
        }
        if ( ! wp_script_is( 'clielo-chat-js', 'registered' ) ) {
            wp_register_script( 'clielo-chat-js', false, [ 'jquery' ], CLIELO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }
        wp_enqueue_script( 'clielo-chat-js' );

        $c              = $color;
        $first_price    = floatval( $packs[0]['price'] ?? 0 );
        $first_delay    = absint( $packs[0]['delay'] ?? 0 );
        $tax_rate       = clielo_is_premium() ? floatval( Clielo_Invoices::get_settings()['tax_rate'] ?? 0 ) : 0;
        $payment_mode        = Clielo_Options::get_payment_mode( $post_id );
        $installments_count  = Clielo_Options::get_installments_count( $post_id );
        $payment_mode_labels = [
            'single'       => __( 'Paiement unique', 'clielo' ),
            'deposit'      => __( 'Acompte 50% + solde à la livraison', 'clielo' ),
            'installments' => __( 'Mensualités', 'clielo' ),
            'monthly'      => __( 'Abonnement mensuel', 'clielo' ),
        ];
        $payment_mode_label = $payment_mode_labels[ $payment_mode ] ?? __( 'Paiement unique', 'clielo' );

        ob_start();
        ?>
        <?php
        // Calcul de la luminance pour déterminer la couleur de texte lisible sur le fond du tooltip
        $hex = ltrim( $c, '#' );
        $r   = hexdec( substr( $hex, 0, 2 ) ) / 255;
        $g   = hexdec( substr( $hex, 2, 2 ) ) / 255;
        $b   = hexdec( substr( $hex, 4, 2 ) ) / 255;
        $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;
        $tip_text = $lum > 0.5 ? '#222' : '#fff';

        // Variante adoucie de la couleur pour les éléments secondaires (features, bordures actives)
        $r_int = hexdec( substr( $hex, 0, 2 ) );
        $g_int = hexdec( substr( $hex, 2, 2 ) );
        $b_int = hexdec( substr( $hex, 4, 2 ) );
        $c_light = sprintf( 'rgba(%d,%d,%d,0.12)', $r_int, $g_int, $b_int );
        $c_muted = sprintf( 'rgba(%d,%d,%d,0.7)', $r_int, $g_int, $b_int );
        ?>
        <div id="clielo-sc-card" style="font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif !important;border:1px solid #e0e0e0 !important;border-radius:12px !important;overflow-x:hidden !important;overflow-y:auto !important;max-height:calc(100vh - 80px) !important;background:#fff !important;box-shadow:0 2px 8px rgba(0,0,0,0.06) !important;max-width:100% !important;padding:0 !important;margin:0 0 20px 0 !important">
            <div style="display:flex !important;align-items:center !important;gap:8px !important;padding:12px 16px !important;background:<?php echo esc_attr( $c ); ?> !important;color:#fff !important;font-size:14px !important;font-weight:600 !important;margin:0 !important;border-radius:0 !important;position:sticky !important;top:0 !important;z-index:2 !important">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"></path><rect x="9" y="3" width="6" height="4" rx="1"></rect></svg>
                <span style="color:#fff !important;font-size:14px !important;font-weight:600 !important"><?php esc_html_e( 'Options de service', 'clielo' ); ?></span>
            </div>

            <!-- Packs -->
            <div style="padding:12px 16px 2px !important;font-size:12px !important;font-weight:600 !important;text-transform:uppercase !important;letter-spacing:0.5px !important;color:#888 !important;margin:0 !important"><?php esc_html_e( 'Choisissez votre pack', 'clielo' ); ?></div>
            <div style="padding:0 16px 8px !important;margin:0 !important">
                <span style="display:inline-flex !important;align-items:center !important;gap:4px !important;font-size:11px !important;font-weight:500 !important;color:#fff !important;background:<?php echo esc_attr( $c ); ?> !important;padding:2px 8px !important;border-radius:20px !important;opacity:0.85 !important">&#128179; <?php echo esc_html( $payment_mode_label ); ?></span>
            </div>

            <div id="clielo-sc-packs" data-color="<?php echo esc_attr( $c ); ?>" style="display:flex !important;flex-direction:column !important;gap:10px !important;padding:8px 16px 16px !important;margin:0 !important">
                <?php foreach ( $packs as $i => $pack ) :
                    $pack_delay = absint( $pack['delay'] ?? 0 );
                    $is_sel     = ( $i === 0 );
                    $brd        = $is_sel ? $c_muted : '#e0e0e0';
                    $bg         = $is_sel ? $c_light : '#fff';
                    $dot_bg     = $is_sel ? $c : 'transparent';
                    $dot_brd    = $is_sel ? $c : '#ccc';
                    $features   = $pack['features'] ?? [];
                ?>
                <div class="clielo-sc-pack" data-index="<?php echo absint( $i ); ?>" data-price="<?php echo esc_attr( $pack['price'] ); ?>" data-delay="<?php echo esc_attr( $pack_delay ); ?>" <?php echo $is_sel ? 'data-selected="true"' : ''; ?>
                     style="border:2px solid <?php echo esc_attr( $brd ); ?> !important;border-radius:10px !important;padding:12px !important;cursor:pointer !important;background:<?php echo esc_attr( $bg ); ?> !important;position:relative !important;box-sizing:border-box !important;margin:0 !important;transition:border-color .15s,background .15s !important">
                    <div class="clielo-pack-dot" style="position:absolute !important;top:10px !important;right:10px !important;width:18px !important;height:18px !important;border-radius:50% !important;border:2px solid <?php echo esc_attr( $dot_brd ); ?> !important;background:<?php echo esc_attr( $dot_bg ); ?> !important;display:flex !important;align-items:center !important;justify-content:center !important"><?php if ( $is_sel ) : ?><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6L9 17l-5-5"></path></svg><?php endif; ?></div>
                    <div style="display:flex !important;align-items:center !important;gap:6px !important;margin:0 0 4px 0 !important;padding-right:28px !important">
                        <span style="font-size:14px !important;font-weight:700 !important;color:#222 !important"><?php echo esc_html( $pack['name'] ); ?></span>
                        <?php if ( ! empty( $pack['description'] ) ) : ?>
                            <span class="clielo-info-tip">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                <span class="clielo-tip-text"><?php echo esc_html( $pack['description'] ); ?></span>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex !important;align-items:baseline !important;gap:8px !important;margin:0 0 4px 0 !important">
                        <span style="font-size:14px !important;font-weight:800 !important;color:<?php echo esc_attr( $c_muted ); ?> !important"><?php echo esc_html( number_format( $pack['price'], 2, ',', ' ' ) ); ?> &euro;</span>
                        <?php if ( $pack_delay > 0 ) : ?>
                            <span style="font-size:11px !important;color:#888 !important">&#9201; <?php echo esc_html( $pack_delay ); ?> <?php esc_html_e( 'jour(s)', 'clielo' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ( is_array( $features ) && ! empty( $features ) ) : ?>
                        <div style="margin:6px 0 0 0 !important;padding:0 !important">
                            <?php foreach ( $features as $feat ) : ?>
                                <div style="display:flex !important;align-items:center !important;gap:6px !important;font-size:12px !important;color:#555 !important;line-height:1.6 !important;margin:0 !important;padding:0 !important">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $c_muted ); ?>" stroke-width="2.5" style="flex-shrink:0 !important"><path d="M20 6L9 17l-5-5"></path></svg>
                                    <span style="font-size:12px !important;color:#555 !important"><?php echo esc_html( $feat ); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $options ) ) : ?>
            <div style="height:0 !important;border-top:1px solid #e8e8e8 !important;margin:0 !important;padding:0 !important"></div>
            <div style="padding:12px 16px 4px !important;font-size:12px !important;font-weight:600 !important;text-transform:uppercase !important;letter-spacing:0.5px !important;color:#888 !important;margin:0 !important"><?php esc_html_e( 'Options supplémentaires', 'clielo' ); ?></div>

            <?php foreach ( $options as $i => $opt ) :
                $opt_delay = absint( $opt['delay'] ?? 0 );
            ?>
            <div class="clielo-sc-opt-wrap" style="display:flex !important;align-items:flex-start !important;gap:10px !important;padding:10px 16px !important;cursor:pointer !important;border-bottom:1px solid #f5f5f5 !important;margin:0 !important">
                <input type="checkbox" class="clielo-sc-check" data-index="<?php echo absint( $i ); ?>" data-price="<?php echo esc_attr( $opt['price'] ); ?>" data-delay="<?php echo esc_attr( $opt_delay ); ?>" style="width:18px !important;height:18px !important;min-width:18px !important;margin:2px 0 0 0 !important;flex-shrink:0 !important;cursor:pointer !important" />
                <div style="flex:1 !important;min-width:0 !important">
                    <div style="display:flex !important;justify-content:space-between !important;align-items:baseline !important;gap:8px !important;margin:0 !important;padding:0 !important">
                        <span style="font-size:13px !important;font-weight:500 !important;color:#333 !important"><?php echo esc_html( $opt['name'] ); ?></span>
                        <span style="font-size:13px !important;font-weight:600 !important;color:#555 !important;white-space:nowrap !important">+<?php echo esc_html( number_format( $opt['price'], 2, ',', ' ' ) ); ?> &euro;<?php if ( $opt_delay > 0 ) : ?> <span style="font-size:11px !important;color:#999 !important">+<?php echo esc_html( $opt_delay ); ?>j</span><?php endif; ?></span>
                    </div>
                    <?php if ( ! empty( $opt['description'] ) ) : ?>
                        <div style="font-size:11px !important;color:#999 !important;line-height:1.3 !important;margin:2px 0 0 0 !important;padding:0 !important"><?php echo esc_html( $opt['description'] ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php
            // Options avancées dynamiques
            $advanced_options = [];
            if ( clielo_is_premium() ) {
                $adv_raw = get_post_meta( $post_id, '_clielo_advanced_options', true );
                $advanced_options = $adv_raw ? ( json_decode( $adv_raw, true ) ?: [] ) : [];
            }
            ?>

            <?php if ( ! empty( $advanced_options ) ) : ?>
            <div style="height:0 !important;border-top:1px solid #e8e8e8 !important;margin:0 !important;padding:0 !important"></div>
            <div style="padding:4px 0 0 0 !important;margin:0 !important">
                <div style="padding:8px 16px 4px !important;font-size:12px !important;font-weight:600 !important;text-transform:uppercase !important;letter-spacing:0.5px !important;color:#888 !important;margin:0 !important"><?php esc_html_e( 'Options avancées', 'clielo' ); ?></div>
                <?php foreach ( $advanced_options as $opt_i => $opt ) :
                    $opt_label  = esc_html( $opt['label'] ?? '' );
                    $opt_price  = floatval( $opt['price'] ?? 0 );
                    $opt_mode   = $opt['mode'] ?? 'unit';
                    $opt_unit   = esc_html( $opt['unit_label'] ?? '' );
                    if ( ! $opt_price ) continue;
                    $opt_id     = 'sf-adv-' . absint( $opt_i );
                    $is_counter = in_array( $opt_mode, [ 'unit', 'daily' ], true );
                    $price_fmt  = esc_html( number_format( $opt_price, 2, ',', ' ' ) );
                    $mode_suffix = match ( $opt_mode ) {
                        'monthly' => ' / ' . __( 'mois', 'clielo' ),
                        'daily'   => ' / ' . __( 'jour', 'clielo' ),
                        'unit'    => $opt_unit ? ' / ' . $opt_unit : '',
                        default   => '',
                    };
                ?>
                <div style="display:flex !important;align-items:center !important;gap:10px !important;padding:8px 16px !important;border-top:1px solid #f5f5f5 !important;margin:0 !important">
                    <?php if ( $is_counter ) : ?>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $c ); ?>" stroke-width="2" style="flex-shrink:0 !important"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                    <?php else : ?>
                    <input type="checkbox" id="<?php echo esc_attr( $opt_id ); ?>-check"
                           class="clielo-sc-check sf-adv-opt-check"
                           data-opt-index="<?php echo absint( $opt_i ); ?>"
                           data-opt-mode="<?php echo esc_attr( $opt_mode ); ?>"
                           data-opt-price="<?php echo esc_attr( $opt_price ); ?>"
                           data-opt-label="<?php echo esc_attr( $opt['label'] ?? '' ); ?>"
                           style="width:18px !important;height:18px !important;min-width:18px !important;margin:0 !important;flex-shrink:0 !important;cursor:pointer !important" />
                    <?php endif; ?>
                    <div style="flex:1 !important;min-width:0 !important">
                        <div style="font-size:13px !important;font-weight:500 !important;color:#333 !important"><?php echo esc_html( $opt['label'] ?? '' ); ?></div>
                        <div style="font-size:11px !important;color:#999 !important"><?php echo $price_fmt; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped via esc_html() when built. ?> &euro;<?php echo esc_html( $mode_suffix ); ?></div>
                    </div>
                    <?php if ( $is_counter ) : ?>
                    <div style="display:flex !important;align-items:center !important;gap:6px !important">
                        <button type="button" class="sf-adv-qty-minus" data-target="<?php echo esc_attr( $opt_id ); ?>"
                                style="width:28px !important;height:28px !important;border:1px solid #ddd !important;border-radius:6px !important;background:#fff !important;cursor:pointer !important;font-size:16px !important;line-height:1 !important;color:#555 !important;display:flex !important;align-items:center !important;justify-content:center !important">-</button>
                        <input type="number" id="<?php echo esc_attr( $opt_id ); ?>-qty"
                               class="sf-adv-opt-qty"
                               data-opt-index="<?php echo absint( $opt_i ); ?>"
                               data-opt-mode="<?php echo esc_attr( $opt_mode ); ?>"
                               data-opt-price="<?php echo esc_attr( $opt_price ); ?>"
                               data-opt-label="<?php echo esc_attr( $opt['label'] ?? '' ); ?>"
                               value="0" min="0" max="99"
                               style="width:44px !important;text-align:center !important;border:1px solid #ddd !important;border-radius:6px !important;padding:4px !important;font-size:13px !important;font-weight:600 !important" />
                        <button type="button" class="sf-adv-qty-plus" data-target="<?php echo esc_attr( $opt_id ); ?>"
                                style="width:28px !important;height:28px !important;border:1px solid #ddd !important;border-radius:6px !important;background:#fff !important;cursor:pointer !important;font-size:16px !important;line-height:1 !important;color:#555 !important;display:flex !important;align-items:center !important;justify-content:center !important">+</button>
                    </div>
                    <?php else : ?>
                    <span style="font-size:13px !important;font-weight:600 !important;color:#555 !important;white-space:nowrap !important"><?php echo $price_fmt; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped via esc_html() when built. ?> &euro;<?php echo esc_html( $mode_suffix ); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Total + Délai + Commander (masqué pour admin) -->
            <?php if ( ! current_user_can( 'manage_options' ) && $show_order_button ) : ?>
            <?php
                $first_tva   = $tax_rate > 0 ? round( $first_price * $tax_rate / 100, 2 ) : 0;
                $first_total = round( $first_price + $first_tva, 2 );
            ?>
            <div style="padding:14px 16px !important;border-top:2px solid #e8e8e8 !important;background:#fafafa !important;margin:0 !important;position:sticky !important;bottom:0 !important;z-index:10 !important;border-radius:0 0 12px 12px !important">
                <div style="display:flex !important;justify-content:space-between !important;align-items:center !important;font-size:12px !important;color:#888 !important;margin:0 0 2px 0 !important;padding:0 !important">
                    <span><?php esc_html_e( 'Sous-total', 'clielo' ); ?></span>
                    <span id="clielo-sc-subtotal-val"><?php echo esc_html( number_format( $first_price, 2, ',', ' ' ) ); ?> &euro;</span>
                </div>
                <div style="display:flex !important;justify-content:space-between !important;align-items:center !important;font-size:12px !important;color:#888 !important;margin:0 0 6px 0 !important;padding:0 !important">
                    <?php if ( $tax_rate > 0 ) : ?>
                        <span><?php esc_html_e( 'TVA', 'clielo' ); ?> (<?php echo esc_html( $tax_rate ); ?>%)</span>
                        <span id="clielo-sc-tva-val"><?php echo esc_html( number_format( $first_tva, 2, ',', ' ' ) ); ?> &euro;</span>
                    <?php else : ?>
                        <span id="clielo-sc-tva-val" style="font-style:italic !important"><?php esc_html_e( 'TVA : 0% (non applicable)', 'clielo' ); ?></span>
                        <span></span>
                    <?php endif; ?>
                </div>
                <div style="display:flex !important;justify-content:space-between !important;align-items:center !important;font-size:16px !important;font-weight:700 !important;color:#222 !important;margin:0 0 4px 0 !important;padding:0 !important;border-top:1px solid #e8e8e8 !important;padding-top:6px !important">
                    <span style="font-size:16px !important;font-weight:700 !important;color:#222 !important"><?php esc_html_e( 'Total', 'clielo' ); ?></span>
                    <span id="clielo-sc-total-val" style="font-size:16px !important;font-weight:700 !important;color:<?php echo esc_attr( $c_muted ); ?> !important"><?php echo esc_html( number_format( $first_total, 2, ',', ' ' ) ); ?> &euro;</span>
                </div>
                <?php if ( $payment_mode !== 'single' ) : ?>
                <div id="clielo-sc-breakdown" style="background:<?php echo esc_attr( $c_light ); ?> !important;border-radius:6px !important;padding:8px 10px !important;margin:6px 0 8px 0 !important;font-size:12px !important;color:#555 !important"></div>
                <?php else : ?>
                <div id="clielo-sc-breakdown" style="display:none !important"></div>
                <?php endif; ?>
                <div id="clielo-sc-delay-row" style="display:flex !important;justify-content:space-between !important;align-items:center !important;font-size:13px !important;color:#888 !important;margin:0 0 12px 0 !important;padding:0 !important">
                    <span style="font-size:13px !important;color:#888 !important">&#9201; <?php esc_html_e( 'Délai estimé', 'clielo' ); ?></span>
                    <span id="clielo-sc-delay-val" style="font-size:13px !important;font-weight:600 !important;color:#555 !important"><?php echo esc_html( $first_delay ); ?> <?php esc_html_e( 'jour(s)', 'clielo' ); ?></span>
                </div>
                <button type="button" id="clielo-sc-order" style="display:flex !important;align-items:center !important;justify-content:center !important;gap:8px !important;width:100% !important;padding:12px !important;border:none !important;border-radius:8px !important;background:<?php echo esc_attr( $c ); ?> !important;color:#fff !important;font-size:14px !important;font-weight:600 !important;cursor:pointer !important;font-family:inherit !important;margin:0 !important;line-height:1.4 !important">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    <?php echo ( clielo_is_premium() && Clielo_Stripe::is_enabled() ) ? esc_html__( 'Payer et commander', 'clielo' ) : esc_html__( 'Commander via le chat', 'clielo' ); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ( ! current_user_can( 'manage_options' ) && $show_order_button ) : ?>
        <!-- Barre sticky mobile : visible uniquement quand la carte options n'est pas à l'écran -->
        <div id="clielo-mobile-bar" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:2147483645;background:#fff;box-shadow:0 -2px 12px rgba(0,0,0,0.12);padding:12px 16px;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                <div style="flex-shrink:0">
                    <div style="font-size:11px;color:#888;text-transform:uppercase;font-weight:600;letter-spacing:0.3px"><?php esc_html_e( 'À partir de', 'clielo' ); ?></div>
                    <div id="clielo-mobile-price" style="font-size:16px;font-weight:600;color:<?php echo esc_attr( $c ); ?>"><?php echo esc_html( number_format( $first_price, 2, ',', ' ' ) ); ?> &euro;</div>
                </div>
                <button type="button" id="clielo-mobile-cta" style="flex:1;max-width:240px;padding:12px 16px;border:none;border-radius:8px;background:<?php echo esc_attr( $c ); ?>;color:#fff;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;line-height:1.4">
                    <?php esc_html_e( 'Voir les offres', 'clielo' ); ?>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <?php
        wp_add_inline_script( 'clielo-chat-js', '(function(){
            var wraps = document.querySelectorAll(".clielo-sc-opt-wrap");
            wraps.forEach(function(w){ w.addEventListener("click", function(e){ if(e.target.type!=="checkbox"){ var cb=w.querySelector("input[type=\'checkbox\']"); if(cb&&!cb.disabled){cb.checked=!cb.checked;cb.dispatchEvent(new Event("change"));} } }); });
            var packs=document.querySelectorAll(".clielo-sc-pack");
            var packBox=document.getElementById("clielo-sc-packs");
            var pColor=packBox?packBox.dataset.color:"#3b82f6";
            var hexToRgb=function(h){h=h.replace("#","");return{r:parseInt(h.substring(0,2),16),g:parseInt(h.substring(2,4),16),b:parseInt(h.substring(4,6),16)};};
            var rgb=hexToRgb(pColor);
            var pColorMuted="rgba("+rgb.r+","+rgb.g+","+rgb.b+",0.7)";
            var pColorLight="rgba("+rgb.r+","+rgb.g+","+rgb.b+",0.12)";
            var checkSvg=\'<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6L9 17l-5-5"></path></svg>\';
            packs.forEach(function(p){ p.addEventListener("click",function(){ if(packBox&&packBox.hasAttribute("data-frozen"))return; packs.forEach(function(pp){ pp.removeAttribute("data-selected"); pp.style.setProperty("border-color","#e0e0e0","important"); pp.style.setProperty("background","#fff","important"); var d=pp.querySelector(".clielo-pack-dot"); if(d){d.style.setProperty("border-color","#ccc","important");d.style.setProperty("background","transparent","important");d.innerHTML="";} }); p.setAttribute("data-selected","true"); p.style.setProperty("border-color",pColorMuted,"important"); p.style.setProperty("background",pColorLight,"important"); var d=p.querySelector(".clielo-pack-dot"); if(d){d.style.setProperty("border-color",pColor,"important");d.style.setProperty("background",pColor,"important");d.innerHTML=checkSvg;} document.dispatchEvent(new Event("clielo_pack_changed")); }); });
            var mobileBar=document.getElementById("clielo-mobile-bar");
            var scCard=document.getElementById("clielo-sc-card");
            var mobileCta=document.getElementById("clielo-mobile-cta");
            var mobilePrice=document.getElementById("clielo-mobile-price");
            var chatBtn=document.getElementById("clielo-toggle");
            if(mobileBar&&scCard){
                var isMobile=function(){return window.innerWidth<=768;};
                var adjustChatBtn=function(barVisible){if(!chatBtn)return;chatBtn.style.setProperty("bottom",barVisible&&isMobile()?"80px":"24px","important");};
                var observer=new IntersectionObserver(function(entries){if(!isMobile()){mobileBar.style.display="none";adjustChatBtn(false);return;}var visible=entries[0].isIntersecting;mobileBar.style.display=visible?"none":"block";adjustChatBtn(!visible);},{threshold:0.1});
                observer.observe(scCard);
                window.addEventListener("resize",function(){if(!isMobile()){mobileBar.style.display="none";adjustChatBtn(false);}});
                if(mobileCta){mobileCta.addEventListener("click",function(){scCard.scrollIntoView({behavior:"smooth",block:"center"});});}
                if(mobilePrice){var syncPrice=function(){var totalEl=document.getElementById("clielo-sc-total-val");if(totalEl)mobilePrice.textContent=totalEl.textContent;};document.addEventListener("clielo_pack_changed",syncPrice);var checks=document.querySelectorAll(".clielo-sc-check");checks.forEach(function(cb){cb.addEventListener("change",function(){setTimeout(syncPrice,50);});});}
            }
            document.querySelectorAll(".sf-adv-qty-minus,.sf-adv-qty-plus").forEach(function(btn){btn.addEventListener("click",function(e){e.preventDefault();e.stopPropagation();var inp=document.getElementById(btn.dataset.target+"-qty");if(!inp)return;var v=parseInt(inp.value)||0;inp.value=btn.classList.contains("sf-adv-qty-minus")?Math.max(0,v-1):v+1;document.dispatchEvent(new Event("clielo_pack_changed"));});});
            document.querySelectorAll(".sf-adv-opt-qty").forEach(function(inp){inp.addEventListener("change",function(){if(parseInt(inp.value)<0)inp.value=0;document.dispatchEvent(new Event("clielo_pack_changed"));});});
            document.querySelectorAll(".sf-adv-opt-check").forEach(function(cb){cb.addEventListener("change",function(){document.dispatchEvent(new Event("clielo_pack_changed"));});});
        })();' );
        return ob_get_clean();
    }

    /**
     * Shortcode [clielo_options] — affiche packs + options de service.
     */
    public static function shortcode_options(): string {
        if ( ! self::is_chat_page() ) {
            return '';
        }
        return self::render_options_card();
    }

    /**
     * Popup chat (bouton flottant + panneau messages).
     */
    public static function render_chat(): void {
        if ( ! self::is_chat_page() ) {
            return;
        }

        $color       = esc_attr( Clielo_Admin::get_color() );
        $position    = Clielo_Admin::get_position();
        $pos_parts   = explode( '-', $position );
        $vertical    = $pos_parts[0] ?? '';
        $horizontal  = $pos_parts[1] ?? '';
        $is_logged   = is_user_logged_in();
        $post_id     = get_queried_object_id();

        if ( empty( $vertical ) || ! in_array( $vertical, [ 'top', 'bottom' ], true ) ) {
            $vertical = 'bottom';
        }
        if ( empty( $horizontal ) || ! in_array( $horizontal, [ 'left', 'right' ], true ) ) {
            $horizontal = 'right';
        }

        $btn_style = implode( ';', [
            'position:fixed !important',
            'z-index:2147483647 !important',
            'width:60px !important',
            'height:60px !important',
            'border:none !important',
            'border-radius:50% !important',
            'background:' . $color . ' !important',
            'color:#fff !important',
            'cursor:pointer',
            'display:flex !important',
            'align-items:center',
            'justify-content:center',
            'box-shadow:0 4px 16px rgba(0,0,0,0.25)',
            'padding:0 !important',
            'margin:0 !important',
            'line-height:1',
            'visibility:visible !important',
            'opacity:1 !important',
            $vertical . ':24px !important',
            $horizontal . ':24px !important',
        ] );

        $popup_v = $vertical === 'bottom' ? 'bottom:100px' : 'top:100px';
        $popup_style = implode( ';', [
            'position:fixed !important',
            'z-index:2147483646 !important',
            'width:380px',
            'max-width:calc(100vw - 24px)',
            'max-height:520px',
            'border-radius:16px',
            'overflow:hidden',
            'background:#fff !important',
            'box-shadow:0 8px 32px rgba(0,0,0,0.18)',
            'flex-direction:column',
            'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif',
            'display:none',
            $popup_v . ' !important',
            $horizontal . ':24px !important',
        ] );

        $packs   = Clielo_Options::get_packs( $post_id );
        $options = Clielo_Options::get_options( $post_id );

        $is_admin = current_user_can( 'manage_options' );

        $active_order = null;
        if ( $is_logged ) {
            $active_order = Clielo_Orders::build_order_response( $post_id );
        }

        // Options avancées dynamiques
        $advanced_options = [];
        if ( clielo_is_premium() ) {
            $adv_raw = get_post_meta( $post_id, '_clielo_advanced_options', true );
            $advanced_options = $adv_raw ? ( json_decode( $adv_raw, true ) ?: [] ) : [];
        }
        $tax_rate           = clielo_is_premium() ? floatval( Clielo_Invoices::get_settings()['tax_rate'] ?? 0 ) : 0;
        $payment_mode       = Clielo_Options::get_payment_mode( $post_id );
        $installments_count = Clielo_Options::get_installments_count( $post_id );

        $js_config = wp_json_encode( [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'clielo_nonce' ),
            'post_id'      => $post_id,
            'post_title'   => get_the_title( $post_id ),
            'user_id'      => get_current_user_id(),
            'color'        => $color,
            'is_admin'     => $is_admin,
            'is_logged'    => $is_logged,
            'login_url'    => wp_login_url( get_permalink( $post_id ) ),
            'active_order' => $active_order,
            'packs'        => ! empty( $packs ) ? $packs : [],
            'options'      => ! empty( $options ) ? $options : [],
            'advanced_options'        => $advanced_options,
            'tax_rate'                => $tax_rate,
            'payment_mode'            => $payment_mode,
            'installments_count'      => $installments_count,
            'is_premium'              => clielo_is_premium(),
            'stripe_enabled'          => Clielo_Stripe::is_enabled(),
            'stripe_checkout_action'  => 'clielo_stripe_checkout',
            'i18n'         => [
                'error'             => __( 'Erreur lors de l\'envoi.', 'clielo' ),
                'empty'             => __( 'Pas encore de message — posez votre première question ! 👋', 'clielo' ),
                'order_btn'         => Clielo_Stripe::is_enabled()
                    ? __( 'Payer et commander', 'clielo' )
                    : __( 'Commander via le chat', 'clielo' ),
                'order_modify_btn'  => __( 'Modifier la commande', 'clielo' ),
                'order_locked'      => __( 'Commande en cours...', 'clielo' ),
                'order_replace'     => __( 'Vous avez déjà envoyé une commande. Voulez-vous la remplacer par cette nouvelle sélection ?', 'clielo' ),
                'order_modify'      => __( 'Modification de commande', 'clielo' ),
                'days'              => __( 'jour(s)', 'clielo' ),
                'estimated'         => __( 'Livraison estimée', 'clielo' ),
                'start_order'       => __( 'Démarrer', 'clielo' ),
                'complete_order'    => __( 'Terminer', 'clielo' ),
                'request_revision'  => __( 'Demander une retouche', 'clielo' ),
                'accept_delivery'   => __( 'Accepter la livraison', 'clielo' ),
                'validate_order'    => __( 'Valider', 'clielo' ),
                'validate_revision' => __( 'Valider la retouche', 'clielo' ),
                'revision_delay_placeholder' => __( 'Délai (jours)', 'clielo' ),
                'status_pending'    => __( 'En attente', 'clielo' ),
                'status_paid'       => __( 'Payée', 'clielo' ),
                'status_started'    => __( 'En cours', 'clielo' ),
                'status_completed'  => __( 'Terminée', 'clielo' ),
                'status_revision'   => __( 'Retouche demandée', 'clielo' ),
                'status_accepted'   => __( 'Acceptée', 'clielo' ),
                'client'            => __( 'Client', 'clielo' ),
                'service'           => __( 'Service', 'clielo' ),
                'delay_total'       => __( 'Délai total', 'clielo' ),
                'order_error'       => __( 'Erreur lors de la création de la commande.', 'clielo' ),
                'order_no_modify'   => __( 'La commande ne peut plus être modifiée.', 'clielo' ),
                'conversations'     => __( 'Conversations', 'clielo' ),
                'no_conversations'  => __( 'Aucune conversation.', 'clielo' ),
                'chat'              => __( 'Chat', 'clielo' ),
                'today'             => __( 'Aujourd\'hui', 'clielo' ),
                'balance'           => __( 'Solde à la livraison', 'clielo' ),
                'monthly_then'      => __( 'puis', 'clielo' ),
                'monthly_per_month' => __( '/mois', 'clielo' ),
                'payment_redirect'  => __( 'Redirection vers le paiement...', 'clielo' ),
                'payment_error'     => __( 'Erreur lors de la création du paiement.', 'clielo' ),
                'login_required'    => __( 'Connectez-vous pour commander.', 'clielo' ),
                'progress'          => __( 'Progression', 'clielo' ),
                'add_note'          => __( 'Ajouter une note (optionnel)', 'clielo' ),
                'todo_note'         => __( 'Note', 'clielo' ),
            ],
        ] );
        ?>

        <!-- Clielo : Bouton flottant -->
        <button id="clielo-toggle" style="<?php echo esc_attr( $btn_style ); ?>" aria-label="Chat">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            <span id="clielo-badge" style="position:absolute;top:-2px;right:-2px;min-width:20px;height:20px;background:#e53e3e;color:#fff;border-radius:50%;font-size:11px;font-weight:700;align-items:center;justify-content:center;line-height:1;display:none"></span>
        </button>

        <!-- Clielo : Popup -->
        <div id="clielo-container" style="<?php echo esc_attr( $popup_style ); ?>">
            <!-- Header avec bouton retour -->
            <div id="clielo-header" style="background:<?php echo esc_attr( $color ); ?> !important;color:#fff !important;padding:14px 20px !important;flex-shrink:0 !important;display:flex !important;align-items:center !important;gap:10px !important;margin:0 !important">
                <button id="clielo-back" type="button" style="display:none !important;background:none !important;border:none !important;color:#fff !important;cursor:pointer !important;padding:0 !important;margin:0 !important;flex-shrink:0 !important;line-height:1 !important">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
                </button>
                <h3 id="clielo-header-title" style="margin:0 !important;font-size:16px !important;font-weight:600 !important;color:#fff !important;flex:1 !important"><?php esc_html_e( 'Chat', 'clielo' ); ?></h3>
            </div>

            <!-- Liste de conversations (admin uniquement) -->
            <div id="clielo-client-list" style="display:none !important;flex:1 !important;overflow-y:auto !important;background:#f9fafb !important;padding:0 !important"></div>

            <div id="clielo-order-bar" style="display:none;padding:10px 16px;background:#f0f4ff;border-bottom:1px solid #d0d9e8;font-size:13px;flex-shrink:0"></div>
            <div id="clielo-todo-bar" style="display:none;padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:12px;flex-shrink:0;max-height:200px;overflow-y:auto"></div>

            <div id="clielo-messages" class="clielo-messages">
                <div class="clielo-loading"><?php esc_html_e( 'Chargement...', 'clielo' ); ?></div>
            </div>

            <?php if ( $is_logged ) : ?>
                <form id="clielo-form" class="clielo-form" onsubmit="return false;">
                    <div class="clielo-input-wrapper">
                        <textarea id="clielo-input" class="clielo-input" placeholder="<?php esc_attr_e( 'Votre message...', 'clielo' ); ?>" rows="1" maxlength="1000"></textarea>
                        <button type="button" id="clielo-send" class="clielo-send" style="background:<?php echo esc_attr( $color ); ?>">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"></path><path d="M22 2L15 22L11 13L2 9L22 2Z"></path></svg>
                        </button>
                    </div>
                </form>
            <?php else : ?>
                <div class="clielo-login-notice">
                    <p><?php
                        printf(
                            /* translators: %s: HTML link tag for login page */
                            esc_html__( '%s pour participer au chat.', 'clielo' ),
                            '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Connectez-vous', 'clielo' ) . '</a>'
                        );
                    ?></p>
                </div>
            <?php endif; ?>
        </div>

        <?php ob_start(); ?>(function(){
            var C = <?php echo $js_config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() ensures safe JSON output ?>;
            var POLL = 5000;
            var lsKey = 'sf_last_seen_' + C.post_id + '_' + C.user_id;
            var lastId = parseInt(localStorage.getItem(lsKey) || '0', 10);
            var hasLoaded = false, unread = 0;

            var btn         = document.getElementById('clielo-toggle');
            var box         = document.getElementById('clielo-container');
            var msgs        = document.getElementById('clielo-messages');
            var input       = document.getElementById('clielo-input');
            var send        = document.getElementById('clielo-send');
            var badge       = document.getElementById('clielo-badge');
            var orderBar    = document.getElementById('clielo-order-bar');
            var todoBar     = document.getElementById('clielo-todo-bar');
            var clientList  = document.getElementById('clielo-client-list');
            var backBtn     = document.getElementById('clielo-back');
            var headerTitle = document.getElementById('clielo-header-title');
            var chatForm    = document.getElementById('clielo-form');

            if(!btn || !box || !msgs) return;

            /* ── Admin : état conversation ────────────── */
            var selectedClientId = 0;
            var sfOrderCollapsed = false;
            var sfTodoCollapsed  = false;

            /* ── Toggle popup ────────────────────────── */
            function markSeen(){
                if(lastId > 0) localStorage.setItem(lsKey, lastId);
            }

            function openChat(){
                box.style.display = 'flex';
                unread = 0; updBadge(); markSeen();

                if(C.is_admin){
                    showClientList();
                } else {
                    if(!hasLoaded){ hasLoaded = true; loadMsgs(); }
                    else { scrollEnd(); }
                    renderOrderBar();
                    if(input) input.focus();
                }
            }

            function closeChat(){ box.style.display = 'none'; }

            btn.addEventListener('click', function(e){
                e.preventDefault(); e.stopPropagation();
                box.style.display === 'flex' ? closeChat() : openChat();
            });

            document.addEventListener('click', function(e){
                if(box.style.display==='flex' && !box.contains(e.target) && !btn.contains(e.target)) closeChat();
            });

            /* ── Retour Stripe (sf_payment_success) ──── */
            (function(){
                var urlParams = new URLSearchParams(window.location.search);
                var schedId   = parseInt(urlParams.get('schedule_id') || '0', 10);
                if(!urlParams.get('sf_payment_success') || !schedId || C.is_admin) return;

                // Vérifier si le paiement est confirmé (fallback si webhook pas encore reçu)
                fetch(C.ajax_url + '?action=clielo_schedule_check&schedule_id='+schedId+'&nonce='+C.nonce)
                .then(function(r){ return r.json(); })
                .then(function(res){
                    // Ouvrir le chat dans tous les cas pour que le client voit l'état
                    openChat();
                    // Nettoyer l'URL
                    var clean = window.location.pathname + window.location.search
                        .replace(/[?&]sf_payment_success=1/, '')
                        .replace(/[?&]schedule_id=\d+/, '');
                    if(clean !== window.location.pathname + window.location.search){
                        history.replaceState(null, '', clean || window.location.pathname);
                    }
                })
                .catch(function(){ openChat(); });
            })();

            /* ── Admin : liste de conversations ──────── */
            function showClientList(){
                selectedClientId = 0;
                clientList.style.display = 'block';
                clientList.style.setProperty('display','block','important');
                msgs.style.display = 'none';
                orderBar.style.display = 'none';
                if(chatForm) chatForm.style.display = 'none';
                if(backBtn) backBtn.style.setProperty('display','none','important');
                if(headerTitle) headerTitle.textContent = C.i18n.conversations;
                loadClientList();
            }

            function loadClientList(){
                if(!C.is_admin) return;
                clientList.innerHTML = '<div style="padding:30px 20px !important;text-align:center !important;color:#999 !important;font-size:14px !important">Chargement...</div>';
                fetch(C.ajax_url+'?'+new URLSearchParams({action:'clielo_get_clients',post_id:C.post_id,nonce:C.nonce}))
                .then(function(r){return r.json();})
                .then(function(res){
                    if(!res.success||!res.data||!res.data.length){
                        clientList.innerHTML='<div style="padding:30px 20px !important;text-align:center !important;color:#999 !important;font-size:14px !important">'+esc(C.i18n.no_conversations)+'</div>';
                        return;
                    }
                    var html = '';
                    res.data.forEach(function(cl){
                        var dotColors = {pending:'#f59e0b',paid:'#8b5cf6',started:'#3b82f6',completed:'#10b981',revision:'#ef4444',accepted:'#6b7280'};
                        var statusDot = '';
                        if(cl.has_order && cl.order_status){
                            var dc = dotColors[cl.order_status]||'#ccc';
                            statusDot = '<span style="display:inline-block !important;width:8px !important;height:8px !important;border-radius:50% !important;background:'+dc+' !important;flex-shrink:0 !important"></span>';
                        }
                        var orderLabel = cl.has_order ? '<span style="font-size:11px !important;color:#888 !important">#CMD-'+cl.order_id+'</span>' : '';
                        html += '<div class="clielo-client-row" data-client-id="'+cl.client_id+'" data-client-name="'+esc(cl.display_name)+'" style="display:flex !important;align-items:center !important;gap:10px !important;padding:12px 16px !important;border-bottom:1px solid #eee !important;cursor:pointer !important;background:#fff !important;transition:background 0.15s !important">'
                            + '<img src="'+esc(cl.avatar)+'" style="width:36px !important;height:36px !important;border-radius:50% !important;object-fit:cover !important;flex-shrink:0 !important" />'
                            + '<div style="flex:1 !important;min-width:0 !important">'
                            + '<div style="font-size:14px !important;font-weight:600 !important;color:#222 !important">'+esc(cl.display_name)+'</div>'
                            + orderLabel
                            + '</div>'
                            + statusDot
                            + '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="2" style="flex-shrink:0 !important"><path d="M9 18l6-6-6-6"></path></svg>'
                            + '</div>';
                    });
                    clientList.innerHTML = html;

                    clientList.querySelectorAll('.clielo-client-row').forEach(function(row){
                        row.addEventListener('click', function(){
                            selectClient(parseInt(row.dataset.clientId), row.dataset.clientName);
                        });
                    });
                });
            }

            function selectClient(clientId, clientName){
                selectedClientId = clientId;
                clientList.style.setProperty('display','none','important');
                msgs.style.display = 'flex';
                if(chatForm) chatForm.style.display = 'block';
                if(backBtn) backBtn.style.setProperty('display','inline-flex','important');
                if(headerTitle) headerTitle.textContent = clientName || 'Chat';

                hasLoaded = false;
                lastId = 0;
                msgs.innerHTML = '<div class="clielo-loading">Chargement...</div>';
                loadMsgs();
                renderOrderBar();
                if(input) input.focus();
            }

            if(backBtn){
                backBtn.addEventListener('click', function(e){
                    e.preventDefault(); e.stopPropagation();
                    showClientList();
                });
            }

            /* ── Shortcode elements ──────────────────── */
            var scOrder   = document.getElementById('clielo-sc-order');
            var scChecks  = document.querySelectorAll('.clielo-sc-check');
            var scPacks   = document.querySelectorAll('.clielo-sc-pack');
            var scTotal     = document.getElementById('clielo-sc-total-val');
            var scSubtotal  = document.getElementById('clielo-sc-subtotal-val');
            var scTva       = document.getElementById('clielo-sc-tva-val');
            var scDelay     = document.getElementById('clielo-sc-delay-val');
            var scBreakdown = document.getElementById('clielo-sc-breakdown');
            var packBox   = document.getElementById('clielo-sc-packs');
            var scOrigBg  = C.color;

            var selectedPackIdx = 0;
            var orderSent   = false;
            var isEditing   = false;
            var orderFrozen = false;
            var lastOrderStateKey = null;

            /* Options avancées dynamiques : getAdvOptSelections() collecte depuis les inputs du shortcode */

            var svgChat = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
            var svgEdit = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';

            /* Pack sélection — écouter l'événement du shortcode */
            document.addEventListener('clielo_pack_changed', function(){
                var sel = document.querySelector('.clielo-sc-pack[data-selected]');
                if(sel) selectedPackIdx = parseInt(sel.dataset.index);
                calcScTotal();
            });

            function getSelectedPack(){
                return C.packs[selectedPackIdx] || C.packs[0] || {name:'',price:0,delay:0};
            }

            function setOrderBtnText(text, icon){
                if(!scOrder) return;
                scOrder.innerHTML = icon + ' ' + esc(text);
            }

            function lockCard(){
                orderSent = true;
                isEditing = false;
                scChecks.forEach(function(cb){ cb.disabled = true; cb.style.opacity = '0.5'; cb.style.cursor = 'default'; });
                document.querySelectorAll('.clielo-sc-opt-wrap').forEach(function(w){ w.style.cursor = 'default'; w.style.opacity = '0.7'; });
                scPacks.forEach(function(p){ p.style.setProperty('opacity','0.7','important'); p.style.setProperty('cursor','default','important'); });
                if(packBox) packBox.setAttribute('data-frozen','true');
                setOrderBtnText(C.i18n.order_modify_btn, svgEdit);
                if(scOrder){
                    scOrder.style.setProperty('background', scOrigBg, 'important');
                    scOrder.style.setProperty('opacity', '1', 'important');
                    scOrder.style.setProperty('cursor', 'pointer', 'important');
                    scOrder.disabled = false;
                }
            }

            function unlockCard(){
                isEditing = true;
                scChecks.forEach(function(cb){ cb.disabled = false; cb.style.opacity = '1'; cb.style.cursor = 'pointer'; });
                document.querySelectorAll('.clielo-sc-opt-wrap').forEach(function(w){ w.style.cursor = 'pointer'; w.style.opacity = '1'; });
                scPacks.forEach(function(p){ p.style.setProperty('opacity','1','important'); p.style.setProperty('cursor','pointer','important'); });
                if(packBox) packBox.removeAttribute('data-frozen');
                setOrderBtnText(C.i18n.order_btn, svgChat);
                if(scOrder){
                    scOrder.style.setProperty('background', scOrigBg, 'important');
                    scOrder.style.setProperty('opacity', '1', 'important');
                    scOrder.style.setProperty('cursor', 'pointer', 'important');
                    scOrder.disabled = false;
                }
            }

            function freezeCard(){
                orderFrozen = true;
                orderSent = true;
                isEditing = false;
                scChecks.forEach(function(cb){ cb.disabled = true; cb.style.opacity = '0.5'; cb.style.cursor = 'default'; });
                document.querySelectorAll('.clielo-sc-opt-wrap').forEach(function(w){ w.style.cursor = 'default'; w.style.opacity = '0.7'; });
                scPacks.forEach(function(p){ p.style.setProperty('opacity','0.7','important'); p.style.setProperty('cursor','default','important'); });
                if(packBox) packBox.setAttribute('data-frozen','true');
                setOrderBtnText(C.i18n.order_locked, svgChat);
                if(scOrder){
                    scOrder.style.setProperty('background', '#6c757d', 'important');
                    scOrder.style.setProperty('opacity', '0.6', 'important');
                    scOrder.style.setProperty('cursor', 'not-allowed', 'important');
                    scOrder.disabled = true;
                }
            }

            function resetCard(){
                orderSent = false;
                isEditing = false;
                orderFrozen = false;
                scChecks.forEach(function(cb){ cb.disabled = false; cb.style.opacity = '1'; cb.style.cursor = 'pointer'; cb.checked = false; });
                document.querySelectorAll('.clielo-sc-opt-wrap').forEach(function(w){ w.style.cursor = 'pointer'; w.style.opacity = '1'; });
                scPacks.forEach(function(p){ p.style.setProperty('opacity','1','important'); p.style.setProperty('cursor','pointer','important'); });
                if(packBox) packBox.removeAttribute('data-frozen');
                setOrderBtnText(C.i18n.order_btn, svgChat);
                if(scOrder){
                    scOrder.style.setProperty('background', scOrigBg, 'important');
                    scOrder.style.setProperty('opacity', '1', 'important');
                    scOrder.style.setProperty('cursor', 'pointer', 'important');
                    scOrder.disabled = false;
                }
                calcScTotal();
            }

            function syncCardState(){
                if(C.is_admin) return;
                var o = C.active_order;
                if(!o || !o.id) return;
                if(o.status === 'accepted'){
                    resetCard();
                } else if(o.status === 'pending'){
                    lockCard();
                } else {
                    freezeCard();
                }
            }
            syncCardState();

            function buildOrderMsg(isMod){
                var pack = getSelectedPack();
                var prefix = isMod ? '\ud83d\udd04 ' + C.i18n.order_modify + ' :\n' : '';
                var lines = [prefix + '\ud83d\udccb Ma s\u00e9lection :'];
                var pDelay = parseInt(pack.delay)||0;
                var pDelayStr = pDelay > 0 ? ' (\u23f1 ' + pDelay + 'j)' : '';
                lines.push('\ud83d\udce6 ' + pack.name + ' \u2014 ' + fmtPrice(pack.price) + pDelayStr);

                var totalDelay = pDelay;
                scChecks.forEach(function(cb){
                    if(cb.checked){
                        var opt = C.options[parseInt(cb.dataset.index)];
                        if(opt){
                            var d = parseInt(cb.dataset.delay)||0;
                            var ds = d > 0 ? ' (+' + d + 'j)' : '';
                            lines.push('\u2705 ' + opt.name + ' \u2014 ' + fmtPrice(opt.price) + ds);
                            totalDelay += d;
                        }
                    }
                });

                var total = parseFloat(pack.price)||0;
                scChecks.forEach(function(cb){ if(cb.checked && !cb.classList.contains('sf-adv-opt-check')) total += parseFloat(cb.dataset.price)||0; });

                var advSels2 = getAdvOptSelections();
                for(var ai2=0; ai2<advSels2.length; ai2++){
                    var asel2 = advSels2[ai2];
                    if(asel2.mode === 'daily'){
                        var minD2 = Math.ceil(totalDelay * 0.45);
                        var maxOff2 = totalDelay - minD2;
                        var ed2 = Math.min(asel2.qty, maxOff2);
                        var exTotal2 = ed2 * asel2.price;
                        lines.push('\u26a1 ' + (C.advanced_options && C.advanced_options[asel2.index] ? C.advanced_options[asel2.index].label : 'Express') + ' (-' + ed2 + 'j) \u2014 ' + fmtPrice(exTotal2));
                        total += exTotal2;
                        totalDelay -= ed2;
                    } else {
                        var advTotal2 = asel2.qty * asel2.price;
                        var advLbl2 = C.advanced_options && C.advanced_options[asel2.index] ? C.advanced_options[asel2.index].label : '';
                        lines.push('\u2795 ' + advLbl2 + (asel2.qty > 1 ? ' (' + asel2.qty + ')' : '') + ' \u2014 ' + fmtPrice(advTotal2));
                        total += advTotal2;
                    }
                }

                var taxRate = parseFloat(C.tax_rate) || 0;
                var totalTTC = taxRate > 0 ? Math.round(total * (1 + taxRate / 100) * 100) / 100 : total;
                lines.push('\ud83d\udcb0 Total TTC : ' + totalTTC.toFixed(2).replace('.',',') + ' \u20ac');
                if(totalDelay > 0) lines.push('\u23f0 ' + C.i18n.delay_total + ' : ' + totalDelay + ' ' + C.i18n.days);
                return lines.join('\n');
            }

            function getSelectedIndices(){
                var indices = [];
                scChecks.forEach(function(cb){ if(cb.checked) indices.push(parseInt(cb.dataset.index)); });
                return indices;
            }

            if(scOrder){
                var svgChat = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
                function setOrderBtnText(txt, icon){ scOrder.innerHTML = (icon||'') + ' ' + txt; }

                scOrder.addEventListener('click', function(e){
                    e.preventDefault(); e.stopPropagation();
                    if(!C.is_logged){
                        window.location.href = C.login_url;
                        return;
                    }
                    if(C.is_admin) return;
                    if(orderFrozen) return;

                    if(orderSent && !isEditing){
                        unlockCard();
                        return;
                    }

                    if(C.stripe_enabled){
                        /* ── FLUX STRIPE ── */
                        scOrder.disabled = true;
                        setOrderBtnText(C.i18n.payment_redirect, svgChat);

                        var fd = new FormData();
                        fd.append('action', C.stripe_checkout_action);
                        fd.append('post_id', C.post_id);
                        fd.append('nonce', C.nonce);
                        fd.append('selected_pack', selectedPackIdx);
                        fd.append('selected_indices', JSON.stringify(getSelectedIndices()));
                        fd.append('advanced_options_data', JSON.stringify(getAdvOptSelections()));

                        fetch(C.ajax_url, {method:'POST', body:fd})
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if(res.success && res.data && res.data.checkout_url){
                                window.location.href = res.data.checkout_url;
                            } else {
                                alert(res.data && res.data.message ? res.data.message : C.i18n.payment_error);
                                scOrder.disabled = false;
                                setOrderBtnText(C.i18n.order_btn, svgChat);
                            }
                        })
                        .catch(function(){
                            alert(C.i18n.payment_error);
                            scOrder.disabled = false;
                            setOrderBtnText(C.i18n.order_btn, svgChat);
                        });
                    } else {
                        /* ── FLUX MANUEL (existant) ── */
                        if(!input) return;
                        var msg = buildOrderMsg(isEditing);
                        openChat();

                        var fd = new FormData();
                        fd.append('action', 'clielo_create_order');
                        fd.append('post_id', C.post_id);
                        fd.append('nonce', C.nonce);
                        fd.append('selected_pack', selectedPackIdx);
                        fd.append('selected_indices', JSON.stringify(getSelectedIndices()));
                        fd.append('advanced_options_data', JSON.stringify(getAdvOptSelections()));

                        fetch(C.ajax_url, {method:'POST', body:fd})
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if(res.success && res.data){
                                C.active_order = res.data.active_order;
                                renderOrderBar();
                                input.value = msg;
                                doSend();
                                lockCard();
                            }
                        });
                    }
                });
            }

            /* ── Total + delay calc ──────────────────── */
            function getAdvOptSelections(){
                var sels = [];
                var seen = {};
                /* Compteurs (unit / daily) */
                document.querySelectorAll('.sf-adv-opt-qty').forEach(function(inp){
                    var qty = parseInt(inp.value)||0;
                    var idx = inp.dataset.optIndex;
                    if(qty > 0 && !seen[idx]){
                        seen[idx] = true;
                        sels.push({
                            index: parseInt(idx),
                            qty:   qty,
                            mode:  inp.dataset.optMode,
                            price: parseFloat(inp.dataset.optPrice)||0,
                            label: inp.dataset.optLabel||''
                        });
                    }
                });
                /* Checkboxes (monthly / fixed) */
                document.querySelectorAll('.sf-adv-opt-check').forEach(function(cb){
                    var idx = cb.dataset.optIndex;
                    if(cb.checked && !seen[idx]){
                        seen[idx] = true;
                        sels.push({
                            index: parseInt(idx),
                            qty:   1,
                            mode:  cb.dataset.optMode,
                            price: parseFloat(cb.dataset.optPrice)||0,
                            label: cb.dataset.optLabel||''
                        });
                    }
                });
                return sels;
            }

            function calcScTotal(){
                var pack = getSelectedPack();
                var t = parseFloat(pack.price) || 0;
                var d = parseInt(pack.delay) || 0;
                scChecks.forEach(function(cb){
                    if(cb.checked && !cb.classList.contains('sf-adv-opt-check')){
                        t += parseFloat(cb.dataset.price)||0;
                        d += parseInt(cb.dataset.delay)||0;
                    }
                });
                var advSels = getAdvOptSelections();
                for(var ai=0; ai<advSels.length; ai++){
                    var asel = advSels[ai];
                    if(asel.mode === 'daily'){
                        var minDelay = Math.ceil(d * 0.45);
                        var maxDaysOff = d - minDelay;
                        var ed = Math.min(asel.qty, maxDaysOff);
                        t += ed * asel.price;
                        d -= ed;
                    } else {
                        t += asel.qty * asel.price;
                    }
                }
                var taxRate = parseFloat(C.tax_rate) || 0;
                var tva     = taxRate > 0 ? Math.round(t * taxRate / 100 * 100) / 100 : 0;
                var total   = Math.round((t + tva) * 100) / 100;
                if(scSubtotal) scSubtotal.textContent = t.toFixed(2).replace('.',',') + ' \u20ac';
                if(scTva && taxRate > 0) scTva.textContent = tva.toFixed(2).replace('.',',') + ' \u20ac';
                if(scTotal) scTotal.textContent = total.toFixed(2).replace('.',',') + ' \u20ac';
                if(scDelay) scDelay.textContent = d + ' ' + C.i18n.days;

                /* ── Payment breakdown (deposit / installments / monthly) ── */
                if(scBreakdown){
                    var mode = C.payment_mode || 'single';
                    var fmt  = function(v){ return v.toFixed(2).replace('.',',') + ' \u20ac'; };
                    var row  = function(label, val, bold){
                        return '<div style="display:flex;justify-content:space-between;align-items:center;margin:2px 0">'
                            + '<span>' + label + '</span>'
                            + '<span style="font-weight:' + (bold ? '700' : '600') + ';color:' + C.color + '">' + fmt(val) + '</span>'
                            + '</div>';
                    };
                    if(mode === 'deposit'){
                        var upfront = Math.round(total * 0.50 * 100) / 100;
                        var balance = Math.round((total - upfront) * 100) / 100;
                        scBreakdown.style.display = '';
                        scBreakdown.innerHTML = row(C.i18n.today, upfront, true) + row(C.i18n.balance, balance, false);
                    } else if(mode === 'installments'){
                        var n = parseInt(C.installments_count, 10) || 3;
                        var upfront2 = Math.round(total * 0.40 * 100) / 100;
                        var remaining = Math.round((total - upfront2) * 100) / 100;
                        var monthly2  = Math.round(remaining / n * 100) / 100;
                        scBreakdown.style.display = '';
                        scBreakdown.innerHTML = row(C.i18n.today, upfront2, true)
                            + row(C.i18n.monthly_then + ' ' + n + '\u00d7', monthly2, false);
                    } else if(mode === 'monthly'){
                        var n2 = parseInt(C.installments_count, 10) || 3;
                        var monthly3 = Math.round(total / n2 * 100) / 100;
                        scBreakdown.style.display = '';
                        scBreakdown.innerHTML = row(C.i18n.today, monthly3, true)
                            + row(C.i18n.monthly_then + ' ' + (n2 - 1) + '\u00d7', monthly3, false);
                    } else {
                        scBreakdown.style.display = 'none';
                    }
                }
            }
            scChecks.forEach(function(cb){ if(!cb.classList.contains('sf-adv-opt-check')) cb.addEventListener('change', calcScTotal); });
            calcScTotal();

            function fmtPrice(p){ return parseFloat(p).toFixed(2).replace('.',',') + ' \u20ac'; }

            /* ── Send message ────────────────────────── */
            if(send && input){
                send.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); doSend(); });
                input.addEventListener('keydown', function(e){
                    if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); doSend(); }
                });
                input.addEventListener('input', function(){
                    input.style.height = 'auto';
                    input.style.height = Math.min(input.scrollHeight, 120)+'px';
                });
            }

            function doSend(){
                var msg = input.value.trim();
                if(!msg) return;
                if(C.is_admin && !selectedClientId) return;
                send.disabled = true;
                var fd = new FormData();
                fd.append('action','clielo_send');
                fd.append('post_id', C.post_id);
                fd.append('nonce', C.nonce);
                fd.append('message', msg);
                if(C.is_admin) fd.append('client_id', selectedClientId);

                fetch(C.ajax_url, {method:'POST', body:fd})
                .then(function(r){return r.json();})
                .then(function(res){
                    if(res.success){
                        rmEmpty();
                        res.data.is_mine = true;
                        addMsg(res.data);
                        scrollEnd();
                        input.value = '';
                        input.style.height = 'auto';
                    }
                })
                .finally(function(){ send.disabled = false; input.focus(); });
            }

            /* ── Load ────────────────────────────────── */
            function loadMsgs(){
                var params = {action:'clielo_load',post_id:C.post_id,nonce:C.nonce};
                if(C.is_admin && selectedClientId) params.client_id = selectedClientId;
                fetch(C.ajax_url+'?'+new URLSearchParams(params))
                .then(function(r){return r.json();})
                .then(function(res){
                    msgs.innerHTML='';
                    hasLoaded = true;
                    var msgsData = res.data && res.data.messages ? res.data.messages : (res.data || []);
                    if(!res.success||!msgsData.length){
                        msgs.innerHTML='<div class="clielo-empty">'+esc(C.i18n.empty)+'</div>';
                        return;
                    }
                    msgsData.forEach(function(m){addMsg(m);});
                    updatePayButtons(res.data.paid_schedule_ids||[], res.data.expired_schedule_ids||[]);
                    scrollEnd();
                })
                .catch(function(){ msgs.innerHTML='<div class="clielo-empty">'+esc(C.i18n.error)+'</div>'; });
            }

            /* ── Mise à jour boutons paiement dans le chat ── */
            function updatePayButtons(paidIds, expiredIds){
                if(paidIds && paidIds.length){
                    paidIds.forEach(function(id){
                        var btn = msgs.querySelector('[data-sf-sched-id="'+id+'"]');
                        if(btn){
                            var span = document.createElement('span');
                            span.style.cssText = 'display:inline-block !important;margin-top:6px !important;padding:5px 14px !important;background:#10b981 !important;color:#fff !important;border-radius:6px !important;font-size:12px !important;font-weight:600 !important';
                            span.textContent = '✅ <?php echo esc_js( __( 'Payé', 'clielo' ) ); ?>';
                            btn.parentNode.replaceChild(span, btn);
                        }
                    });
                }
                if(expiredIds && expiredIds.length){
                    expiredIds.forEach(function(id){
                        var btn = msgs.querySelector('[data-sf-sched-id="'+id+'"]');
                        if(btn){
                            var span = document.createElement('span');
                            span.style.cssText = 'display:inline-block !important;margin-top:6px !important;padding:5px 14px !important;background:#6b7280 !important;color:#fff !important;border-radius:6px !important;font-size:12px !important;font-weight:600 !important;cursor:default !important';
                            span.textContent = '⏱ <?php echo esc_js( __( 'Lien expiré — contactez votre prestataire', 'clielo' ) ); ?>';
                            btn.parentNode.replaceChild(span, btn);
                        }
                    });
                }
            }

            /* ── Poll ────────────────────────────────── */
            setInterval(function(){
                if(C.is_admin && !selectedClientId) return;
                var params = {action:'clielo_poll',post_id:C.post_id,last_id:lastId,nonce:C.nonce};
                if(C.is_admin && selectedClientId) params.client_id = selectedClientId;

                fetch(C.ajax_url+'?'+new URLSearchParams(params))
                .then(function(r){return r.json();})
                .then(function(res){
                    if(!res.success||!res.data) return;
                    var pollMsgs = res.data.messages || res.data;
                    var pollOrder = res.data.active_order;

                    // Sync active_order — syncCardState uniquement si le statut change
                    if(typeof pollOrder !== 'undefined'){
                        var newKey = pollOrder ? (pollOrder.id + ':' + pollOrder.status) : 'none';
                        C.active_order = pollOrder;
                        renderOrderBar();
                        if(newKey !== lastOrderStateKey){
                            lastOrderStateKey = newKey;
                            syncCardState();
                        }
                    }

                    updatePayButtons(res.data.paid_schedule_ids||[], res.data.expired_schedule_ids||[]);
                    if(!pollMsgs||!pollMsgs.length) return;
                    var isOpen = box.style.display==='flex';
                    if(!hasLoaded){
                        pollMsgs.forEach(function(m){
                            lastId=Math.max(lastId,parseInt(m.id));
                            if(parseInt(m.user_id)!==parseInt(C.user_id) && !isOpen) unread++;
                        });
                        updBadge(); return;
                    }
                    rmEmpty();
                    pollMsgs.forEach(function(m){
                        if(!document.querySelector('[data-msg-id="'+m.id+'"]')){
                            addMsg(m);
                            if(parseInt(m.user_id)!==parseInt(C.user_id)&&!isOpen) unread++;
                        }
                    });
                    updBadge();
                    if(isOpen){ scrollEnd(); markSeen(); }
                });
            }, POLL);

            /* ── Render message ──────────────────────── */
            function addMsg(m){
                var isSys = m.is_system || parseInt(m.user_id) === 0;

                if(isSys){
                    var el = document.createElement('div');
                    el.className = 'clielo-message clielo-message--system';
                    el.setAttribute('data-msg-id', m.id);
                    // Convertir les URLs en boutons cliquables (token-based: remplacer avant esc())
                    var rawMsg = m.message;
                    // Supprimer la ligne marker [SF_SCHED:X]
                    rawMsg = rawMsg.replace(/^\[SF_SCHED:\d+\]\n?/m, '');
                    var isPaid   = !!m.schedule_paid;
                    var schedId  = m.schedule_id || 0;
                    var sysHtml  = rawMsg.replace(/(https?:\/\/[^\s]+)/g, function(url){
                        return '\x00PAY_LINK:'+url+'\x00';
                    });
                    sysHtml = esc(sysHtml).replace(/\n/g,'<br>');
                    sysHtml = sysHtml.replace(/\x00PAY_LINK:(.*?)\x00/g, function(_, url){
                        if(isPaid){
                            return '<span style="display:inline-block !important;margin-top:6px !important;padding:5px 14px !important;background:#10b981 !important;color:#fff !important;border-radius:6px !important;font-size:12px !important;font-weight:600 !important">✅ <?php echo esc_js( __( 'Payé', 'clielo' ) ); ?></span>';
                        }
                        return '<a href="'+url+'" target="_blank" rel="noopener"'+(schedId?' data-sf-sched-id="'+schedId+'"':'')+' style="display:inline-block !important;margin-top:6px !important;padding:5px 14px !important;background:'+C.color+' !important;color:#fff !important;border-radius:6px !important;font-size:12px !important;font-weight:600 !important;text-decoration:none !important">💳 <?php echo esc_js( __( 'Payer maintenant', 'clielo' ) ); ?></a>';
                    });
                    el.innerHTML = '<div style="background:#f0f4ff !important;border:1px solid #d0d9e8 !important;border-radius:10px !important;padding:10px 14px !important;text-align:center !important;font-size:13px !important;line-height:1.5 !important;color:#444 !important;width:100% !important">'+sysHtml+'</div>';
                    msgs.appendChild(el);
                    lastId = Math.max(lastId, parseInt(m.id));
                    return;
                }

                var mine = m.is_mine||(parseInt(m.user_id)===parseInt(C.user_id));
                var el = document.createElement('div');
                el.className = 'clielo-message clielo-message--'+(mine?'mine':'other');
                el.setAttribute('data-msg-id', m.id);
                var bubbleStyle = mine ? 'background:'+C.color+';color:#fff;border-bottom-right-radius:4px' : '';
                el.innerHTML =
                    '<div class="clielo-avatar"><img src="'+esc(m.avatar)+'" alt="'+esc(m.display_name)+'" style="width:36px;height:36px;border-radius:50%;object-fit:cover"></div>'+
                    '<div class="clielo-bubble-wrap">'+
                        (!mine?'<div class="clielo-username">'+esc(m.display_name)+'</div>':'')+
                        '<div class="clielo-bubble" style="'+bubbleStyle+'">'+esc(m.message).replace(/\n/g,'<br>')+'</div>'+
                        '<div class="clielo-time">'+fmtTime(m.created_at)+'</div>'+
                    '</div>';
                msgs.appendChild(el);
                lastId = Math.max(lastId, parseInt(m.id));
            }

            /* ── Order bar ───────────────────────────── */
            function renderOrderBar(){
                if(!orderBar) return;
                var o = C.active_order;
                if(!o){ orderBar.style.display = 'none'; return; }

                var contentHtml = '';
                if(C.is_admin && Array.isArray(o)){
                    // Admin dans une conversation : filtrer par client sélectionné
                    var filtered = selectedClientId ? o.filter(function(ord){ return parseInt(ord.client_id) === selectedClientId; }) : o;
                    if(filtered.length === 0){ orderBar.style.display = 'none'; return; }
                    filtered.forEach(function(order, idx){
                        if(idx > 0) contentHtml += '<div style="border-top:1px solid #d0d9e8;margin:8px 0"></div>';
                        contentHtml += renderSingleOrder(order, true);
                    });
                } else if(!C.is_admin && o && o.id){
                    contentHtml = renderSingleOrder(o, false);
                } else {
                    orderBar.style.display = 'none';
                    return;
                }

                var arrow = sfOrderCollapsed ? '\u25b6' : '\u25bc';
                var html = '<div data-sf-toggle="order" style="cursor:pointer;display:flex;justify-content:space-between;align-items:center;padding:0 0 6px;margin-bottom:6px;border-bottom:1px solid #d0d9e8;font-size:12px;font-weight:600;color:#555;user-select:none">';
                html += '<span>' + esc(C.i18n.orders_label || 'Commande') + '</span>';
                html += '<span style="font-size:9px;color:#aaa">' + arrow + '</span></div>';
                if(!sfOrderCollapsed) html += contentHtml;

                // Sauvegarder les valeurs des inputs de délai retouche avant re-render
                var savedDelays = {};
                orderBar.querySelectorAll('[data-revision-delay-for]').forEach(function(inp){
                    var v = inp.value.trim();
                    if(v) savedDelays[inp.dataset.revisionDelayFor] = v;
                });

                orderBar.innerHTML = html;
                orderBar.style.display = 'block';

                // Restaurer les valeurs
                Object.keys(savedDelays).forEach(function(oid){
                    var inp = orderBar.querySelector('[data-revision-delay-for="'+oid+'"]');
                    if(inp) inp.value = savedDelays[oid];
                });

                var orderToggle = orderBar.querySelector('[data-sf-toggle="order"]');
                if(orderToggle){
                    orderToggle.addEventListener('click', function(e){
                        e.stopPropagation();
                        sfOrderCollapsed = !sfOrderCollapsed;
                        renderOrderBar();
                    });
                }

                if(!sfOrderCollapsed){
                    orderBar.querySelectorAll('[data-order-action]').forEach(function(b){
                        b.addEventListener('click', function(){
                            var action = b.dataset.orderAction;
                            var orderId = parseInt(b.dataset.orderId);
                            if(action === 'revision_accept'){
                                var inp = orderBar.querySelector('[data-revision-delay-for="'+orderId+'"]');
                                var delay = inp ? (parseInt(inp.value) || 0) : 0;
                                doRevisionTransition(orderId, delay);
                            } else {
                                doOrderTransition(orderId, action);
                            }
                        });
                    });
                }

                renderTodoList();
            }

            /* ── Todo list bar ───────────────────────── */
            function renderTodoList(){
                if(!todoBar || !C.is_premium) return;
                var o = C.active_order;
                var order = null;

                if(C.is_admin && Array.isArray(o)){
                    var filtered = selectedClientId ? o.filter(function(ord){ return parseInt(ord.client_id) === selectedClientId; }) : [];
                    order = filtered.length ? filtered[0] : null;
                } else if(!C.is_admin && o && o.id){
                    order = o;
                }

                if(!order || !order.todos || !order.todos.items || !order.todos.items.length){
                    todoBar.style.display = 'none';
                    return;
                }

                if(['pending','paid','accepted'].indexOf(order.status) !== -1){
                    todoBar.style.display = 'none';
                    return;
                }

                var t = order.todos;
                var pct = t.progress.percent;
                var contentHtml = '';

                // Barre de progression
                contentHtml += '<div style="margin-bottom:8px">';
                contentHtml += '<div style="display:flex;justify-content:space-between;font-size:11px;color:#666;margin-bottom:4px">';
                contentHtml += '<span>'+esc(C.i18n.progress)+'</span>';
                contentHtml += '<span>'+t.progress.completed+'/'+t.progress.total+' ('+pct+'%)</span>';
                contentHtml += '</div>';
                contentHtml += '<div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden">';
                contentHtml += '<div style="height:100%;width:'+pct+'%;background:'+C.color+';border-radius:3px;transition:width .3s"></div>';
                contentHtml += '</div></div>';

                // Checklist
                t.items.forEach(function(item){
                    var checked = item.is_completed;
                    contentHtml += '<div style="display:flex;align-items:flex-start;gap:8px;padding:3px 0;font-size:12px">';

                    if(C.is_admin){
                        contentHtml += '<input type="checkbox" class="sf-todo-check" data-todo-id="'+item.id+'" data-order-id="'+order.id+'" '+(checked?'checked':'')+' style="margin-top:2px;cursor:pointer;accent-color:'+C.color+';flex-shrink:0" />';
                    } else {
                        if(checked){
                            contentHtml += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="'+C.color+'" stroke-width="2.5" style="flex-shrink:0;margin-top:2px"><path d="M20 6L9 17l-5-5"/></svg>';
                        } else {
                            contentHtml += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="2" style="flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/></svg>';
                        }
                    }

                    contentHtml += '<div style="flex:1;min-width:0">';
                    contentHtml += '<span style="'+(checked?'text-decoration:line-through;color:#999':'color:#333')+'">'+esc(item.label)+'</span>';
                    if(item.source === 'option') contentHtml += ' <span style="font-size:10px;color:#aaa;font-style:italic">(option)</span>';
                    if(item.admin_note){
                        contentHtml += '<div style="font-size:11px;color:#888;margin-top:1px">'+esc(C.i18n.todo_note)+' : '+esc(item.admin_note)+'</div>';
                    }
                    contentHtml += '</div></div>';
                });

                var arrow = sfTodoCollapsed ? '\u25b6' : '\u25bc';
                var html = '<div data-sf-toggle="todo" style="cursor:pointer;display:flex;justify-content:space-between;align-items:center;padding:0 0 6px;margin-bottom:6px;border-bottom:1px solid #e2e8f0;font-size:12px;font-weight:600;color:#555;user-select:none">';
                html += '<span>' + esc(C.i18n.todos_label || 'Tâches') + ' ('+t.progress.completed+'/'+t.progress.total+')</span>';
                html += '<span style="font-size:9px;color:#aaa">' + arrow + '</span></div>';
                if(!sfTodoCollapsed) html += contentHtml;

                todoBar.innerHTML = html;
                todoBar.style.display = 'block';

                var todoToggle = todoBar.querySelector('[data-sf-toggle="todo"]');
                if(todoToggle){
                    todoToggle.addEventListener('click', function(e){
                        e.stopPropagation();
                        sfTodoCollapsed = !sfTodoCollapsed;
                        renderTodoList();
                    });
                }

                // Admin : handlers checkbox
                if(!sfTodoCollapsed && C.is_admin){
                    todoBar.querySelectorAll('.sf-todo-check').forEach(function(cb){
                        cb.addEventListener('change', function(){
                            var todoId = parseInt(cb.dataset.todoId);
                            var orderId = parseInt(cb.dataset.orderId);
                            var isChecked = cb.checked;
                            var note = '';
                            if(isChecked){
                                note = prompt(C.i18n.add_note) || '';
                            }
                            doToggleTodo(orderId, todoId, isChecked, note);
                        });
                    });
                }
            }

            function doToggleTodo(orderId, todoId, completed, note){
                var fd = new FormData();
                fd.append('action', 'clielo_toggle_todo');
                fd.append('nonce', C.nonce);
                fd.append('order_id', orderId);
                fd.append('todo_id', todoId);
                fd.append('completed', completed ? '1' : '0');
                fd.append('note', note);

                fetch(C.ajax_url, {method:'POST', body:fd})
                .then(function(r){return r.json();})
                .then(function(res){
                    if(res.success && res.data.todos){
                        // Mettre à jour les todos dans active_order
                        var o = C.active_order;
                        if(C.is_admin && Array.isArray(o)){
                            o.forEach(function(ord){
                                if(ord.id === orderId){
                                    ord.todos = res.data.todos;
                                    if(res.data.order_completed) ord.status = 'completed';
                                }
                            });
                        } else if(o && o.id === orderId){
                            o.todos = res.data.todos;
                            if(res.data.order_completed) o.status = 'completed';
                        }
                        if(res.data.order_completed){
                            renderOrderBar();
                        } else {
                            renderTodoList();
                        }
                    }
                });
            }

            function truncate(str, max){
                if(!str) return '';
                return str.length > max ? str.substring(0, max) + '\u2026' : str;
            }

            function fmtDate(d){
                if(!d) return '';
                var p = d.split('-');
                if(p.length === 3) return p[2]+'/'+p[1]+'/'+p[0];
                return d;
            }

            function renderSingleOrder(order, isAdmin){
                var sLabels = {'pending':C.i18n.status_pending,'paid':C.i18n.status_paid,'started':C.i18n.status_started,'completed':C.i18n.status_completed,'revision':C.i18n.status_revision,'accepted':C.i18n.status_accepted};
                var sColors = {'pending':'#f59e0b','paid':'#8b5cf6','started':'#3b82f6','completed':'#10b981','revision':'#ef4444','accepted':'#6b7280'};
                var sL = sLabels[order.status] || order.status;
                var sC = sColors[order.status] || '#888';
                var orderNum = order.order_number || ('#CMD-'+order.id);

                var html = '';
                if(isAdmin){
                    var cl = order.client_name ? esc(order.client_name) : '?';
                    var sv = truncate(C.post_title, 30);
                    html += '<div style="font-size:12px;color:#666;margin-bottom:4px"><strong>'+cl+'</strong> &mdash; <span style="color:#999;font-weight:600">'+esc(orderNum)+'</span></div>';
                } else {
                    html += '<div style="font-size:12px;color:#666;margin-bottom:6px;font-weight:600">'+esc(orderNum)+'</div>';
                }

                html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">';
                html += '<div style="display:flex;align-items:center;gap:6px">';
                html += '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:'+sC+'">'+esc(sL)+'</span>';
                if(order.estimated_date && (order.status==='started'||order.status==='revision')){
                    html += '<span style="font-size:11px;color:#666">'+esc(C.i18n.estimated)+' : '+fmtDate(order.estimated_date)+'</span>';
                }
                html += '</div><div style="display:flex;gap:4px">';

                if(isAdmin){
                    if(order.status==='pending'||order.status==='paid'){
                        html += makeBtn(order.id,'started',C.i18n.start_order,'#3b82f6');
                    } else if(order.status==='revision'){
                        html += '<input type="number" data-revision-delay-for="'+order.id+'" min="1" max="365" placeholder="'+esc(C.i18n.revision_delay_placeholder)+'" style="width:90px;padding:3px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:11px;font-family:inherit" />';
                        html += '<button data-order-id="'+order.id+'" data-order-action="revision_accept" style="padding:4px 10px;border:none;border-radius:6px;font-size:11px;font-weight:600;color:#fff;background:#3b82f6;cursor:pointer">'+esc(C.i18n.validate_revision)+'</button>';
                    } else if(order.status==='started'){
                        html += makeBtn(order.id,'completed',C.i18n.complete_order,'#10b981');
                    }
                } else {
                    if(order.status==='completed'){
                        html += makeBtn(order.id,'accepted',C.i18n.accept_delivery,'#10b981');
                        html += makeBtn(order.id,'revision',C.i18n.request_revision,'#ef4444');
                    }
                }
                html += '</div></div>';
                return html;
            }

            function makeBtn(id,action,label,color){
                return '<button data-order-id="'+id+'" data-order-action="'+action+'" style="padding:4px 10px;border:none;border-radius:6px;font-size:11px;font-weight:600;color:#fff;background:'+color+';cursor:pointer">'+esc(label)+'</button>';
            }

            function doOrderTransition(orderId, newStatus, extraData){
                var fd = new FormData();
                fd.append('action', 'clielo_order_transition');
                fd.append('nonce', C.nonce);
                fd.append('order_id', orderId);
                fd.append('new_status', newStatus);
                fd.append('post_id', C.post_id);
                if(extraData) Object.keys(extraData).forEach(function(k){ fd.append(k, extraData[k]); });

                fetch(C.ajax_url, {method:'POST', body:fd})
                .then(function(r){return r.json();})
                .then(function(res){
                    if(res.success && res.data){
                        C.active_order = res.data.active_order;
                        renderOrderBar();
                        syncCardState();
                        loadMsgs();
                    }
                });
            }

            function doRevisionTransition(orderId, revisionDelay){
                doOrderTransition(orderId, 'started', revisionDelay > 0 ? {revision_delay: revisionDelay} : null);
            }

            /* ── Badge ───────────────────────────────── */
            function updBadge(){
                if(!badge) return;
                var isOpen = box.style.display==='flex';
                if(unread>0 && !isOpen){
                    badge.textContent = unread>9?'9+':unread;
                    badge.style.display = 'flex';
                } else { badge.style.display = 'none'; unread = 0; }
            }

            /* ── Helpers ─────────────────────────────── */
            function scrollEnd(){ msgs.scrollTop = msgs.scrollHeight; }
            function rmEmpty(){ var e=msgs.querySelector('.clielo-empty'); if(e)e.remove(); }
            function fmtTime(s){
                if(!s)return'';
                var d=new Date(s.replace(' ','T'));
                return d.getHours().toString().padStart(2,'0')+':'+d.getMinutes().toString().padStart(2,'0');
            }
            function esc(s){
                if(!s)return'';
                var d=document.createElement('div'); d.textContent=s; return d.innerHTML;
            }
        })();
        <?php
        wp_add_inline_script( 'clielo-chat-js', ob_get_clean() );
    }

    private static function is_chat_page(): bool {
        return is_singular( Clielo_Admin::get_post_type() );
    }
}
