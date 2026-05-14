<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Options {

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
        add_action( 'save_post', [ __CLASS__, 'save_meta' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_scripts' ] );
    }

    public static function add_meta_box(): void {
        $cpt = Clielo_Admin::get_post_type();

        add_meta_box(
            'clielo_options',
            __( 'Options de service', 'clielo' ),
            [ __CLASS__, 'render_meta_box' ],
            $cpt,
            'normal',
            'high'
        );
    }

    /**
     * Compte le nombre de services déjà configurés (avec au moins un pack).
     */
    public static function count_active_services( int $exclude_id = 0 ): int {
        global $wpdb;
        $cpt = Clielo_Admin::get_post_type();
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s AND p.post_status IN ('publish','draft')
             AND pm.meta_key = '_clielo_packs' AND pm.meta_value != '' AND pm.meta_value != 'a:0:{}'
             AND p.ID != %d",
            $cpt,
            $exclude_id
        );
        return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Query built with wpdb::prepare() above.
    }

    public static function render_meta_box( \WP_Post $post ): void {
        wp_nonce_field( 'clielo_options_save', 'clielo_options_nonce' );

        $packs   = get_post_meta( $post->ID, '_clielo_packs', true );
        $options = get_post_meta( $post->ID, '_clielo_options', true );

        // Free = 1 seul service autorisé
        if ( ! clielo_is_premium() && empty( $packs ) && self::count_active_services( $post->ID ) >= 1 ) {
            printf(
                '<div style="padding:20px;text-align:center;color:#666"><p>%s</p><a href="%s" class="button button-primary">%s</a></div>',
                esc_html__( 'La version gratuite est limitée à 1 service. Passez à Pro pour des services illimités.', 'clielo' ),
                esc_url( admin_url( 'admin.php?page=clielo-pricing' ) ),
                esc_html__( 'Passer à Pro', 'clielo' )
            );
            return;
        }

        // Migration : ancien format base_offer → packs
        if ( ! is_array( $packs ) || empty( $packs ) ) {
            $base = get_post_meta( $post->ID, '_clielo_base_offer', true );
            if ( is_array( $base ) && ! empty( $base['name'] ) ) {
                $packs = [ $base ];
            } else {
                $packs = [];
            }
        }
        if ( ! is_array( $options ) ) {
            $options = [];
        }
        ?>
        <!-- Packs -->
        <div class="clielo-meta-section">
            <h4><?php esc_html_e( 'Packs', 'clielo' ); ?></h4>
            <p class="clielo-section-desc"><?php esc_html_e( 'Le client en choisit un seul. Ajoutez au moins un pack.', 'clielo' ); ?></p>
            <div id="clielo-packs-list">
                <?php foreach ( $packs as $i => $pack ) : ?>
                    <div class="clielo-pack-item" data-index="<?php echo absint( $i ); ?>">
                        <button type="button" class="clielo-pack-remove"><?php esc_html_e( 'Supprimer', 'clielo' ); ?></button>
                        <div class="clielo-meta-row">
                            <div class="clielo-field">
                                <label><?php esc_html_e( 'Nom', 'clielo' ); ?></label>
                                <input type="text" name="clielo_packs[<?php echo absint( $i ); ?>][name]" value="<?php echo esc_attr( $pack['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Ex: Pack Basique', 'clielo' ); ?>" />
                            </div>
                            <div class="clielo-field-price">
                                <label><?php esc_html_e( 'Prix (€)', 'clielo' ); ?></label>
                                <input type="number" name="clielo_packs[<?php echo absint( $i ); ?>][price]" value="<?php echo esc_attr( $pack['price'] ?? '' ); ?>" min="0" step="0.01" />
                            </div>
                            <div class="clielo-field-price">
                                <label><?php esc_html_e( 'Délai (jours)', 'clielo' ); ?></label>
                                <input type="number" name="clielo_packs[<?php echo absint( $i ); ?>][delay]" value="<?php echo esc_attr( $pack['delay'] ?? '' ); ?>" min="0" step="1" />
                            </div>
                        </div>
                        <div class="clielo-meta-row">
                            <div class="clielo-field">
                                <label><?php esc_html_e( 'Description (infobulle)', 'clielo' ); ?></label>
                                <input type="text" name="clielo_packs[<?php echo absint( $i ); ?>][description]" value="<?php echo esc_attr( $pack['description'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Texte affiché au survol de l\'icône info', 'clielo' ); ?>" />
                            </div>
                        </div>
                        <div class="clielo-features-section">
                            <label><?php esc_html_e( 'Caractéristiques du pack', 'clielo' ); ?></label>
                            <div class="clielo-features-list">
                                <?php
                                $features = $pack['features'] ?? [];
                                if ( is_array( $features ) ) :
                                    foreach ( $features as $fi => $feat ) : ?>
                                        <div class="clielo-feature-item">
                                            <input type="text" name="clielo_packs[<?php echo absint( $i ); ?>][features][]" value="<?php echo esc_attr( $feat ); ?>" placeholder="<?php esc_attr_e( 'Ex: 5 pages incluses', 'clielo' ); ?>" />
                                            <button type="button" class="clielo-feature-rm">&times;</button>
                                        </div>
                                    <?php endforeach;
                                endif; ?>
                            </div>
                            <button type="button" class="clielo-add-feature" data-pack-index="<?php echo absint( $i ); ?>">+ <?php esc_html_e( 'Ajouter une caractéristique', 'clielo' ); ?></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="clielo-add-pack" class="button"><?php esc_html_e( '+ Ajouter un pack', 'clielo' ); ?></button>
        </div>

        <?php if ( clielo_is_premium() ) :
            $adv_opts_raw = get_post_meta( $post->ID, '_clielo_advanced_options', true );
            $adv_opts     = is_array( $adv_opts_raw ) ? $adv_opts_raw : ( ( $adv_opts_raw && is_string( $adv_opts_raw ) ) ? ( json_decode( $adv_opts_raw, true ) ?: [] ) : [] );
            if ( ! is_array( $adv_opts ) ) {
                $adv_opts = [];
            }
            $mode_labels = [
                'unit'    => __( 'Par unité', 'clielo' ),
                'monthly' => __( 'Par mois', 'clielo' ),
                'fixed'   => __( 'Forfait', 'clielo' ),
                'daily'   => __( 'Express (jours)', 'clielo' ),
            ];
        ?>
        <div class="clielo-meta-section">
            <h4><?php esc_html_e( 'Options avancées', 'clielo' ); ?></h4>
            <p class="clielo-section-desc"><?php esc_html_e( 'Ajoutez des options avec tarification flexible. Chaque option est affichée sur le widget de commande.', 'clielo' ); ?></p>

            <div id="sf-adv-opts-list">
                <?php foreach ( $adv_opts as $i => $opt ) :
                    $opt_label      = sanitize_text_field( $opt['label'] ?? '' );
                    $opt_price      = floatval( $opt['price'] ?? 0 );
                    $opt_mode       = in_array( $opt['mode'] ?? '', [ 'unit', 'monthly', 'fixed', 'daily' ], true ) ? $opt['mode'] : 'unit';
                    $opt_unit_label = sanitize_text_field( $opt['unit_label'] ?? '' );
                ?>
                <div class="sf-adv-opt-row">
                    <div class="sf-adv-opt-handle" style="cursor:grab;color:#aaa;padding:0 6px;font-size:18px;line-height:36px">&#8942;&#8942;</div>
                    <div class="sf-adv-opt-fields" style="flex:1;display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:8px;align-items:end">
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Libellé', 'clielo' ); ?></label>
                            <input type="text" name="sf_adv_opt_label[]" value="<?php echo esc_attr( $opt_label ); ?>" placeholder="<?php esc_attr_e( 'Nom de l\'option', 'clielo' ); ?>" style="width:100%" required />
                        </div>
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Mode', 'clielo' ); ?></label>
                            <select name="sf_adv_opt_mode[]" class="sf-adv-opt-mode" style="width:100%">
                                <?php foreach ( $mode_labels as $mval => $mlabel ) : ?>
                                <option value="<?php echo esc_attr( $mval ); ?>" <?php selected( $opt_mode, $mval ); ?>><?php echo esc_html( $mlabel ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sf-adv-opt-unit-wrap" <?php if ( in_array( $opt_mode, [ 'monthly', 'fixed' ], true ) ) : ?>style="visibility:hidden"<?php endif; ?>>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Libellé unité', 'clielo' ); ?></label>
                            <input type="text" name="sf_adv_opt_unit[]" value="<?php echo esc_attr( $opt_unit_label ); ?>" placeholder="<?php esc_attr_e( 'ex. page, heure...', 'clielo' ); ?>" style="width:100%" />
                        </div>
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Prix (€)', 'clielo' ); ?></label>
                            <input type="number" name="sf_adv_opt_price[]" value="<?php echo esc_attr( $opt_price ); ?>" min="0" step="0.01" placeholder="0" style="width:100%" />
                        </div>
                    </div>
                    <button type="button" class="sf-adv-opt-remove button-link" style="color:#a00;margin-left:8px;padding:0 6px;line-height:36px;font-size:18px" title="<?php esc_attr_e( 'Supprimer', 'clielo' ); ?>">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>

            <template id="sf-adv-opt-tpl">
                <div class="sf-adv-opt-row">
                    <div class="sf-adv-opt-handle" style="cursor:grab;color:#aaa;padding:0 6px;font-size:18px;line-height:36px">&#8942;&#8942;</div>
                    <div class="sf-adv-opt-fields" style="flex:1;display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:8px;align-items:end">
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Libellé', 'clielo' ); ?></label>
                            <input type="text" name="sf_adv_opt_label[]" value="" placeholder="<?php esc_attr_e( 'Nom de l\'option', 'clielo' ); ?>" style="width:100%" required />
                        </div>
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Mode', 'clielo' ); ?></label>
                            <select name="sf_adv_opt_mode[]" class="sf-adv-opt-mode" style="width:100%">
                                <option value="unit"><?php esc_html_e( 'Par unité', 'clielo' ); ?></option>
                                <option value="monthly"><?php esc_html_e( 'Par mois', 'clielo' ); ?></option>
                                <option value="fixed"><?php esc_html_e( 'Forfait', 'clielo' ); ?></option>
                                <option value="daily"><?php esc_html_e( 'Express (jours)', 'clielo' ); ?></option>
                            </select>
                        </div>
                        <div class="sf-adv-opt-unit-wrap">
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Libellé unité', 'clielo' ); ?></label>
                            <input type="text" name="sf_adv_opt_unit[]" value="" placeholder="<?php esc_attr_e( 'ex. page, heure...', 'clielo' ); ?>" style="width:100%" />
                        </div>
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Prix (€)', 'clielo' ); ?></label>
                            <input type="number" name="sf_adv_opt_price[]" value="" min="0" step="0.01" placeholder="0" style="width:100%" />
                        </div>
                    </div>
                    <button type="button" class="sf-adv-opt-remove button-link" style="color:#a00;margin-left:8px;padding:0 6px;line-height:36px;font-size:18px" title="<?php esc_attr_e( 'Supprimer', 'clielo' ); ?>">&times;</button>
                </div>
            </template>

            <button type="button" id="sf-adv-opts-add" class="button" style="margin-top:10px">
                + <?php esc_html_e( 'Ajouter une option', 'clielo' ); ?>
            </button>
        </div>

        <?php endif; ?>

        <?php if ( clielo_is_premium() && Clielo_Stripe::is_enabled() ) :
            $payment_mode       = get_post_meta( $post->ID, '_clielo_payment_mode', true ) ?: ( Clielo_Stripe::get_settings()['default_payment_mode'] ?? 'single' );
            $installments_count = (int) get_post_meta( $post->ID, '_clielo_installments_count', true ) ?: 3;
        ?>
        <div class="clielo-meta-section">
            <h4><?php esc_html_e( 'Mode de paiement', 'clielo' ); ?></h4>
            <div class="clielo-meta-row">
                <div class="clielo-field">
                    <label><?php esc_html_e( 'Type de paiement', 'clielo' ); ?></label>
                    <select name="clielo_payment_mode" id="sf-payment-mode-select">
                        <option value="single"       <?php selected( $payment_mode, 'single' ); ?>><?php esc_html_e( 'Paiement unique', 'clielo' ); ?></option>
                        <option value="deposit"      <?php selected( $payment_mode, 'deposit' ); ?>><?php esc_html_e( 'Acompte 50% + solde à la livraison', 'clielo' ); ?></option>
                        <option value="installments" <?php selected( $payment_mode, 'installments' ); ?>><?php esc_html_e( 'Mensualités (40% initial + N mensualités)', 'clielo' ); ?></option>
                        <option value="monthly"      <?php selected( $payment_mode, 'monthly' ); ?>><?php esc_html_e( 'Abonnement mensuel pur (N × tarif mensuel)', 'clielo' ); ?></option>
                    </select>
                    <p id="sf-monthly-hint" style="font-size:11px;color:#888;margin:4px 0 0;<?php echo $payment_mode !== 'monthly' ? 'display:none' : ''; ?>"><?php esc_html_e( 'Le prix du pack = tarif mensuel. Le 1er mois est réglé au départ, les suivants sont envoyés manuellement.', 'clielo' ); ?></p>
                </div>
                <div class="clielo-field-price" id="sf-installments-field" style="<?php echo in_array( $payment_mode, [ 'installments', 'monthly' ], true ) ? '' : 'display:none'; ?>">
                    <label id="sf-installments-label"><?php echo $payment_mode === 'monthly' ? esc_html__( 'Nombre de mois', 'clielo' ) : esc_html__( 'Nombre de mensualités', 'clielo' ); ?></label>
                    <input type="number" name="clielo_installments_count" value="<?php echo esc_attr( $installments_count ); ?>" min="1" max="60" step="1" />
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Options supplémentaires -->
        <div class="clielo-meta-section">
            <h4><?php esc_html_e( 'Options supplémentaires', 'clielo' ); ?></h4>
            <p class="clielo-section-desc"><?php esc_html_e( 'Cumulables avec le pack choisi.', 'clielo' ); ?></p>
            <div id="clielo-options-list">
                <?php foreach ( $options as $i => $opt ) : ?>
                    <div class="clielo-option-item" data-index="<?php echo absint( $i ); ?>">
                        <button type="button" class="clielo-option-remove"><?php esc_html_e( 'Supprimer', 'clielo' ); ?></button>
                        <div class="clielo-meta-row">
                            <div class="clielo-field">
                                <label><?php esc_html_e( 'Nom', 'clielo' ); ?></label>
                                <input type="text" name="clielo_opts[<?php echo absint( $i ); ?>][name]" value="<?php echo esc_attr( $opt['name'] ?? '' ); ?>" />
                            </div>
                            <div class="clielo-field-price">
                                <label><?php esc_html_e( 'Prix (€)', 'clielo' ); ?></label>
                                <input type="number" name="clielo_opts[<?php echo absint( $i ); ?>][price]" value="<?php echo esc_attr( $opt['price'] ?? '' ); ?>" min="0" step="0.01" />
                            </div>
                            <div class="clielo-field-price">
                                <label><?php esc_html_e( 'Délai (jours)', 'clielo' ); ?></label>
                                <input type="number" name="clielo_opts[<?php echo absint( $i ); ?>][delay]" value="<?php echo esc_attr( $opt['delay'] ?? '' ); ?>" min="0" step="1" />
                            </div>
                        </div>
                        <div class="clielo-meta-row">
                            <div class="clielo-field">
                                <label><?php esc_html_e( 'Description', 'clielo' ); ?></label>
                                <input type="text" name="clielo_opts[<?php echo absint( $i ); ?>][description]" value="<?php echo esc_attr( $opt['description'] ?? '' ); ?>" />
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="clielo-add-option" class="button"><?php esc_html_e( '+ Ajouter une option', 'clielo' ); ?></button>
        </div>
        <?php
    }

    public static function admin_scripts( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== Clielo_Admin::get_post_type() ) {
            return;
        }

        wp_enqueue_script( 'jquery-ui-sortable' );

        wp_add_inline_style( 'wp-admin',
            '.clielo-meta-section { margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 6px; }' .
            '.clielo-meta-section h4 { margin: 0 0 4px; font-size: 14px; }' .
            '.clielo-meta-section .clielo-section-desc { font-size: 12px; color: #888; margin: 0 0 12px; }' .
            '.clielo-meta-row { display: flex; gap: 12px; margin-bottom: 10px; align-items: flex-start; }' .
            '.clielo-meta-row label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; color: #555; }' .
            '.clielo-meta-row input[type="text"],.clielo-meta-row input[type="number"],.clielo-meta-row textarea { width: 100%; }' .
            '.clielo-meta-row .clielo-field { flex: 1; }' .
            '.clielo-meta-row .clielo-field-price { flex: 0 0 120px; }' .
            '.clielo-pack-item,.clielo-option-item { padding: 12px; background: #fff; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 8px; position: relative; }' .
            '.clielo-pack-remove,.clielo-option-remove { position: absolute; top: 8px; right: 8px; background: #dc3545; color: #fff; border: none; border-radius: 4px; padding: 4px 10px; cursor: pointer; font-size: 12px; }' .
            '.clielo-pack-remove:hover,.clielo-option-remove:hover { background: #c82333; }' .
            '#clielo-add-pack,#clielo-add-option { margin-top: 8px; }' .
            '.clielo-features-section { margin-top: 8px; padding: 8px; background: #f5f5f5; border: 1px solid #e0e0e0; border-radius: 4px; }' .
            '.clielo-features-section > label { display: block; font-weight: 600; font-size: 12px; color: #555; margin-bottom: 6px; }' .
            '.clielo-feature-item { display: flex; gap: 6px; align-items: center; margin-bottom: 4px; }' .
            '.clielo-feature-item input[type="text"] { flex: 1; }' .
            '.clielo-feature-rm { background: #dc3545; color: #fff; border: none; border-radius: 3px; padding: 2px 8px; cursor: pointer; font-size: 11px; line-height: 1.6; }' .
            '.clielo-feature-rm:hover { background: #c82333; }' .
            '.clielo-add-feature { font-size: 12px; cursor: pointer; color: #0073aa; background: none; border: none; padding: 4px 0; }' .
            '.clielo-add-feature:hover { text-decoration: underline; }' .
            '.sf-adv-opt-row{display:flex;align-items:center;background:#f9f9f9;border:1px solid #e0e0e0;border-radius:4px;padding:8px;margin-bottom:6px}' .
            '.sf-adv-opt-handle{cursor:grab}.sf-adv-opt-handle:active{cursor:grabbing}' .
            '.sf-adv-opt-placeholder{background:#e8f0fe;border:2px dashed #4a90d9;border-radius:4px;margin-bottom:6px}' .
            '#sf-adv-opts-list:empty::before{content:"' . esc_js( __( 'Aucune option. Cliquez sur Ajouter.', 'clielo' ) ) . '";color:#999;font-style:italic;font-size:12px;display:block;padding:8px 0}'
        );

        wp_add_inline_script( 'jquery-ui-sortable', '
            jQuery(function($){
                $("#sf-adv-opts-list").sortable({
                    handle: ".sf-adv-opt-handle",
                    axis: "y",
                    tolerance: "pointer",
                    placeholder: "sf-adv-opt-placeholder",
                    forcePlaceholderSize: true,
                    start: function(e,ui){ ui.item.css("opacity","0.6"); },
                    stop:  function(e,ui){ ui.item.css("opacity","1"); }
                });
                function bindRow(row){
                    var modeEl=row.querySelector(".sf-adv-opt-mode");
                    var unitWrap=row.querySelector(".sf-adv-opt-unit-wrap");
                    function toggleUnit(){ var m=modeEl?modeEl.value:"unit"; if(unitWrap) unitWrap.style.visibility=(m==="monthly"||m==="fixed")?"hidden":""; }
                    if(modeEl){ modeEl.addEventListener("change",toggleUnit); toggleUnit(); }
                    var rmBtn=row.querySelector(".sf-adv-opt-remove");
                    if(rmBtn){ rmBtn.addEventListener("click",function(){ row.remove(); }); }
                }
                document.querySelectorAll(".sf-adv-opt-row").forEach(bindRow);
                var addBtn=document.getElementById("sf-adv-opts-add");
                var tpl=document.getElementById("sf-adv-opt-tpl");
                if(addBtn&&tpl){
                    addBtn.addEventListener("click",function(){
                        var clone=tpl.content.cloneNode(true);
                        var list=document.getElementById("sf-adv-opts-list");
                        list.appendChild(clone);
                        var rows=list.querySelectorAll(".sf-adv-opt-row");
                        bindRow(rows[rows.length-1]);
                    });
                }
            });
        ' );

        wp_add_inline_script( 'jquery-core',
            '(function(){
                var sel=document.getElementById("sf-payment-mode-select");
                var field=document.getElementById("sf-installments-field");
                var lbl=document.getElementById("sf-installments-label");
                var hint=document.getElementById("sf-monthly-hint");
                if(sel&&field){
                    sel.addEventListener("change",function(){
                        var v=sel.value;
                        field.style.display=(v==="installments"||v==="monthly")?"":"none";
                        if(lbl) lbl.textContent=v==="monthly"?"' . esc_js( __( 'Nombre de mois', 'clielo' ) ) . '":"' . esc_js( __( 'Nombre de mensualités', 'clielo' ) ) . '";
                        if(hint) hint.style.display=v==="monthly"?"":"none";
                    });
                }
            })();',
            'after'
        );

        wp_add_inline_script( 'jquery-core', "
            jQuery(function($){
                /* ── Packs repeater ── */
                var packIdx = $('#clielo-packs-list .clielo-pack-item').length;

                $('#clielo-add-pack').on('click', function(){
                    var html = '<div class=\"clielo-pack-item\" data-index=\"'+packIdx+'\">' +
                        '<button type=\"button\" class=\"clielo-pack-remove\">" . esc_js( __( 'Supprimer', 'clielo' ) ) . "</button>' +
                        '<div class=\"clielo-meta-row\">' +
                            '<div class=\"clielo-field\"><label>" . esc_js( __( 'Nom', 'clielo' ) ) . "</label><input type=\"text\" name=\"clielo_packs['+packIdx+'][name]\" placeholder=\"" . esc_js( __( 'Ex: Pack Basique', 'clielo' ) ) . "\" /></div>' +
                            '<div class=\"clielo-field-price\"><label>" . esc_js( __( 'Prix (€)', 'clielo' ) ) . "</label><input type=\"number\" name=\"clielo_packs['+packIdx+'][price]\" min=\"0\" step=\"0.01\" /></div>' +
                            '<div class=\"clielo-field-price\"><label>" . esc_js( __( 'Délai (jours)', 'clielo' ) ) . "</label><input type=\"number\" name=\"clielo_packs['+packIdx+'][delay]\" min=\"0\" step=\"1\" /></div>' +
                        '</div>' +
                        '<div class=\"clielo-meta-row\">' +
                            '<div class=\"clielo-field\"><label>" . esc_js( __( 'Description (infobulle)', 'clielo' ) ) . "</label><input type=\"text\" name=\"clielo_packs['+packIdx+'][description]\" placeholder=\"" . esc_js( __( "Texte affiché au survol de l'icône info", 'clielo' ) ) . "\" /></div>' +
                        '</div>' +
                        '<div class=\"clielo-features-section\">' +
                            '<label>" . esc_js( __( 'Caractéristiques du pack', 'clielo' ) ) . "</label>' +
                            '<div class=\"clielo-features-list\"></div>' +
                            '<button type=\"button\" class=\"clielo-add-feature\" data-pack-index=\"'+packIdx+'\">+ " . esc_js( __( 'Ajouter une caractéristique', 'clielo' ) ) . "</button>' +
                        '</div>' +
                    '</div>';
                    $('#clielo-packs-list').append(html);
                    packIdx++;
                });

                $(document).on('click', '.clielo-pack-remove', function(){
                    $(this).closest('.clielo-pack-item').remove();
                });

                /* ── Features repeater ── */
                $(document).on('click', '.clielo-add-feature', function(){
                    var idx = $(this).closest('.clielo-pack-item').data('index');
                    var html = '<div class=\"clielo-feature-item\">' +
                        '<input type=\"text\" name=\"clielo_packs['+idx+'][features][]\" placeholder=\"" . esc_js( __( 'Ex: 5 pages incluses', 'clielo' ) ) . "\" />' +
                        '<button type=\"button\" class=\"clielo-feature-rm\">&times;</button>' +
                    '</div>';
                    $(this).siblings('.clielo-features-list').append(html);
                });

                $(document).on('click', '.clielo-feature-rm', function(){
                    $(this).closest('.clielo-feature-item').remove();
                });

                /* ── Options repeater ── */
                var optIdx = $('#clielo-options-list .clielo-option-item').length;

                $('#clielo-add-option').on('click', function(){
                    var html = '<div class=\"clielo-option-item\" data-index=\"'+optIdx+'\">' +
                        '<button type=\"button\" class=\"clielo-option-remove\">" . esc_js( __( 'Supprimer', 'clielo' ) ) . "</button>' +
                        '<div class=\"clielo-meta-row\">' +
                            '<div class=\"clielo-field\"><label>" . esc_js( __( 'Nom', 'clielo' ) ) . "</label><input type=\"text\" name=\"clielo_opts['+optIdx+'][name]\" /></div>' +
                            '<div class=\"clielo-field-price\"><label>" . esc_js( __( 'Prix (€)', 'clielo' ) ) . "</label><input type=\"number\" name=\"clielo_opts['+optIdx+'][price]\" min=\"0\" step=\"0.01\" /></div>' +
                            '<div class=\"clielo-field-price\"><label>" . esc_js( __( 'Délai (jours)', 'clielo' ) ) . "</label><input type=\"number\" name=\"clielo_opts['+optIdx+'][delay]\" min=\"0\" step=\"1\" /></div>' +
                        '</div>' +
                        '<div class=\"clielo-meta-row\">' +
                            '<div class=\"clielo-field\"><label>" . esc_js( __( 'Description', 'clielo' ) ) . "</label><input type=\"text\" name=\"clielo_opts['+optIdx+'][description]\" /></div>' +
                        '</div>' +
                    '</div>';
                    $('#clielo-options-list').append(html);
                    optIdx++;
                });

                $(document).on('click', '.clielo-option-remove', function(){
                    $(this).closest('.clielo-option-item').remove();
                });
            });
        " );
    }

    public static function save_meta( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['clielo_options_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clielo_options_nonce'] ) ), 'clielo_options_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( $post->post_type !== Clielo_Admin::get_post_type() ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Free = 1 seul service autorisé
        if ( ! clielo_is_premium() ) {
            $existing_packs = get_post_meta( $post_id, '_clielo_packs', true );
            if ( empty( $existing_packs ) && self::count_active_services( $post_id ) >= 1 ) {
                return;
            }
        }

        // Packs
        $packs_raw = wp_unslash( $_POST['clielo_packs'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually in the loop below.
        $packs = [];
        if ( is_array( $packs_raw ) ) {
            foreach ( $packs_raw as $pack ) {
                $name = sanitize_text_field( $pack['name'] ?? '' );
                if ( empty( $name ) ) {
                    continue;
                }
                $features = [];
                if ( ! empty( $pack['features'] ) && is_array( $pack['features'] ) ) {
                    $features = array_values( array_filter( array_map( 'sanitize_text_field', $pack['features'] ) ) );
                }
                $packs[] = [
                    'name'        => $name,
                    'price'       => floatval( $pack['price'] ?? 0 ),
                    'description' => sanitize_text_field( $pack['description'] ?? '' ),
                    'delay'       => absint( $pack['delay'] ?? 0 ),
                    'features'    => $features,
                ];
            }
        }
        update_post_meta( $post_id, '_clielo_packs', $packs );

        // Options supplémentaires
        $opts_raw = wp_unslash( $_POST['clielo_opts'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually in the loop below.
        $opts = [];
        if ( is_array( $opts_raw ) ) {
            foreach ( $opts_raw as $opt ) {
                $name = sanitize_text_field( $opt['name'] ?? '' );
                if ( empty( $name ) ) {
                    continue;
                }
                $opts[] = [
                    'name'        => $name,
                    'price'       => floatval( $opt['price'] ?? 0 ),
                    'description' => sanitize_text_field( $opt['description'] ?? '' ),
                    'delay'       => absint( $opt['delay'] ?? 0 ),
                ];
            }
        }
        update_post_meta( $post_id, '_clielo_options', $opts );

        // Options avancées dynamiques
        $adv_opts   = [];
        $adv_labels = isset( $_POST['sf_adv_opt_label'] ) ? (array) wp_unslash( $_POST['sf_adv_opt_label'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per item below
        $adv_prices = isset( $_POST['sf_adv_opt_price'] ) ? (array) wp_unslash( $_POST['sf_adv_opt_price'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $adv_modes  = isset( $_POST['sf_adv_opt_mode'] )  ? (array) wp_unslash( $_POST['sf_adv_opt_mode'] )  : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $adv_units  = isset( $_POST['sf_adv_opt_unit'] )  ? (array) wp_unslash( $_POST['sf_adv_opt_unit'] )  : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $allowed_modes = [ 'unit', 'monthly', 'fixed', 'daily' ];
        foreach ( $adv_labels as $i => $raw_label ) {
            $label = sanitize_text_field( $raw_label );
            if ( empty( $label ) ) {
                continue;
            }
            $adv_opts[] = [
                'label'      => $label,
                'price'      => floatval( $adv_prices[ $i ] ?? 0 ),
                'mode'       => in_array( $adv_modes[ $i ] ?? '', $allowed_modes, true ) ? $adv_modes[ $i ] : 'unit',
                'unit_label' => sanitize_text_field( $adv_units[ $i ] ?? '' ),
            ];
        }
        update_post_meta( $post_id, '_clielo_advanced_options', wp_json_encode( $adv_opts, JSON_UNESCAPED_UNICODE ) );

        // Mode de paiement
        if ( isset( $_POST['clielo_payment_mode'] ) ) {
            $mode = sanitize_text_field( wp_unslash( $_POST['clielo_payment_mode'] ) );
            if ( in_array( $mode, [ 'single', 'deposit', 'installments', 'monthly' ], true ) ) {
                update_post_meta( $post_id, '_clielo_payment_mode', $mode );
            }
        }
        if ( isset( $_POST['clielo_installments_count'] ) ) {
            update_post_meta( $post_id, '_clielo_installments_count', absint( wp_unslash( $_POST['clielo_installments_count'] ) ) );
        }
    }

    public static function get_payment_mode( int $post_id ): string {
        if ( ! clielo_is_premium() ) {
            return 'single';
        }
        $meta = get_post_meta( $post_id, '_clielo_payment_mode', true );
        if ( in_array( $meta, [ 'single', 'deposit', 'installments', 'monthly' ], true ) ) {
            return $meta;
        }
        return Clielo_Stripe::get_settings()['default_payment_mode'] ?? 'single';
    }

    public static function get_installments_count( int $post_id ): int {
        return max( 1, (int) get_post_meta( $post_id, '_clielo_installments_count', true ) ?: 3 );
    }

    /**
     * Récupère les packs d'un post (avec migration depuis l'ancien format).
     */
    public static function get_packs( int $post_id ): array {
        $packs = get_post_meta( $post_id, '_clielo_packs', true );
        if ( is_array( $packs ) && ! empty( $packs ) ) {
            return $packs;
        }

        // Migration : ancien format base_offer → pack unique
        $base = get_post_meta( $post_id, '_clielo_base_offer', true );
        if ( is_array( $base ) && ! empty( $base['name'] ) ) {
            return [ $base ];
        }

        return [];
    }

    /**
     * Récupère les options supplémentaires d'un post.
     */
    public static function get_options( int $post_id ): array {
        $opts = get_post_meta( $post_id, '_clielo_options', true );
        return is_array( $opts ) ? $opts : [];
    }
}
