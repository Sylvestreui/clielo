<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Elementor_Widgets {

    public static function init(): void {
        add_action( 'elementor/elements/categories_registered', [ __CLASS__, 'add_category' ] );
        add_action( 'elementor/widgets/register', [ __CLASS__, 'register_widgets' ] );
    }

    public static function add_category( $elements_manager ): void {
        $elements_manager->add_category( 'clielo', [
            'title' => 'Clielo',
            'icon'  => 'eicon-plug',
        ] );
    }

    public static function register_widgets( $widgets_manager ): void {
        $widgets_manager->register( new Clielo_Widget_Pack() );
        $widgets_manager->register( new Clielo_Widget_Option() );
        $widgets_manager->register( new Clielo_Widget_Advanced_Price() );
        $widgets_manager->register( new Clielo_Widget_Service_Options() );
    }
}

/* ================================================================
 *  BASE WIDGET
 * ================================================================ */

abstract class Clielo_Widget_Base extends \Elementor\Widget_Base {

    public function get_categories(): array {
        return [ 'clielo' ];
    }

    protected function get_post_id(): int {
        return get_the_ID() ?: get_queried_object_id();
    }

    protected function register_html_tag_control(): void {
        $this->add_control( 'html_tag', [
            'label'   => __( 'Balise HTML', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'span',
            'options' => [
                'span' => 'span',
                'p'    => 'p',
                'div'  => 'div',
                'h1'   => 'h1',
                'h2'   => 'h2',
                'h3'   => 'h3',
                'h4'   => 'h4',
                'h5'   => 'h5',
                'h6'   => 'h6',
            ],
        ] );
    }

    protected function register_style_section(): void {
        $this->start_controls_section( 'section_style', [
            'label' => __( 'Style', 'clielo' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'text_color', [
            'label'     => __( 'Couleur', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .sf-widget-text' => 'color: {{VALUE}};' ],
        ] );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [ 'name' => 'typography', 'selector' => '{{WRAPPER}} .sf-widget-text' ]
        );

        $this->add_responsive_control( 'text_align', [
            'label'     => __( 'Alignement', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::CHOOSE,
            'options'   => [
                'left'   => [ 'title' => __( 'Gauche', 'clielo' ),  'icon' => 'eicon-text-align-left' ],
                'center' => [ 'title' => __( 'Centre', 'clielo' ),  'icon' => 'eicon-text-align-center' ],
                'right'  => [ 'title' => __( 'Droite', 'clielo' ),  'icon' => 'eicon-text-align-right' ],
            ],
            'selectors' => [ '{{WRAPPER}} .sf-widget-text' => 'text-align: {{VALUE}};' ],
        ] );

        $this->end_controls_section();
    }

    protected function render_text( string $content ): void {
        $tag = $this->get_settings_for_display( 'html_tag' ) ?: 'span';
        $allowed = [ 'span', 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];
        $tag = in_array( $tag, $allowed, true ) ? $tag : 'span';
        $safe_tag = esc_attr( $tag );
        echo '<' . $safe_tag . ' class="sf-widget-text">' . wp_kses_post( $content ) . '</' . $safe_tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tag is validated against an allowlist above; $content is processed by Elementor's get_settings_for_display().
    }
}

/* ================================================================
 *  WIDGET PACK
 * ================================================================ */

class Clielo_Widget_Pack extends Clielo_Widget_Base {

    public function get_name(): string  { return 'sf_pack'; }
    public function get_title(): string { return __( 'SF — Pack', 'clielo' ); }
    public function get_icon(): string  { return 'eicon-archive'; }

    protected function register_controls(): void {

        /* ── Contenu ── */
        $this->start_controls_section( 'section_content', [
            'label' => __( 'Contenu', 'clielo' ),
        ] );

        $this->add_control( 'field', [
            'label'   => __( 'Champ', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'name',
            'options' => [
                'name'          => __( 'Nom', 'clielo' ),
                'price'         => __( 'Prix', 'clielo' ),
                'starting_price'=> __( 'Prix de départ (min)', 'clielo' ),
                'delay'         => __( 'Délai de livraison', 'clielo' ),
                'description'   => __( 'Description', 'clielo' ),
                'features'      => __( 'Caractéristiques', 'clielo' ),
                'count'         => __( 'Nombre de packs', 'clielo' ),
            ],
        ] );

        $this->add_control( 'pack_index', [
            'label'     => __( 'N° du pack (0 = premier)', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::NUMBER,
            'default'   => 0,
            'min'       => 0,
            'condition' => [ 'field!' => [ 'starting_price', 'count' ] ],
        ] );

        $this->add_control( 'currency', [
            'label'     => __( 'Devise', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => '€',
            'condition' => [ 'field' => [ 'price', 'starting_price' ] ],
        ] );

        $this->add_control( 'delay_suffix', [
            'label'     => __( 'Suffixe', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => ' jour(s)',
            'condition' => [ 'field' => 'delay' ],
        ] );

        $this->add_control( 'features_format', [
            'label'     => __( 'Format', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::SELECT,
            'default'   => 'list',
            'options'   => [
                'list'   => __( 'Liste (ul/li)', 'clielo' ),
                'inline' => __( 'En ligne', 'clielo' ),
            ],
            'condition' => [ 'field' => 'features' ],
        ] );

        $this->add_control( 'features_separator', [
            'label'     => __( 'Séparateur', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => ', ',
            'condition' => [ 'field' => 'features', 'features_format' => 'inline' ],
        ] );

        $this->register_html_tag_control();

        $this->end_controls_section();

        /* ── Style texte ── */
        $this->register_style_section();

        /* ── Style liste (features uniquement) ── */
        $this->start_controls_section( 'section_style_list', [
            'label'     => __( 'Style liste', 'clielo' ),
            'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
            'condition' => [ 'field' => 'features' ],
        ] );

        $this->add_control( 'list_color', [
            'label'     => __( 'Couleur texte', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .sf-features-list li' => 'color: {{VALUE}};' ],
        ] );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [ 'name' => 'list_typography', 'selector' => '{{WRAPPER}} .sf-features-list li' ]
        );

        $this->add_responsive_control( 'list_gap', [
            'label'      => __( 'Espacement items', 'clielo' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
            'default'    => [ 'size' => 6, 'unit' => 'px' ],
            'selectors'  => [ '{{WRAPPER}} .sf-features-list li + li' => 'margin-top: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->add_control( 'list_padding', [
            'label'      => __( 'Retrait liste', 'clielo' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
            'default'    => [ 'size' => 20, 'unit' => 'px' ],
            'selectors'  => [ '{{WRAPPER}} .sf-features-list' => 'padding-left: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $s       = $this->get_settings_for_display();
        $packs   = Clielo_Options::get_packs( $this->get_post_id() ) ?: [];
        $idx     = (int) ( $s['pack_index'] ?? 0 );
        $field   = $s['field'] ?? 'name';
        $currency = esc_html( $s['currency'] ?? '€' );

        switch ( $field ) {
            case 'name':
                $this->render_text( esc_html( $packs[ $idx ]['name'] ?? '' ) );
                break;

            case 'price':
                $price = (float) ( $packs[ $idx ]['price'] ?? 0 );
                $this->render_text( esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency ) );
                break;

            case 'starting_price':
                if ( empty( $packs ) ) break;
                $prices = array_map( fn( $p ) => (float) ( $p['price'] ?? 0 ), $packs );
                $this->render_text( esc_html( number_format( min( $prices ), 2, ',', ' ' ) . ' ' . $currency ) );
                break;

            case 'delay':
                $delay  = $packs[ $idx ]['delay'] ?? 0;
                $suffix = $s['delay_suffix'] ?? ' jour(s)';
                $this->render_text( esc_html( $delay . $suffix ) );
                break;

            case 'description':
                $this->render_text( esc_html( $packs[ $idx ]['description'] ?? '' ) );
                break;

            case 'features':
                $features = $packs[ $idx ]['features'] ?? [];
                if ( empty( $features ) || ! is_array( $features ) ) break;
                if ( ( $s['features_format'] ?? 'list' ) === 'list' ) {
                    echo '<ul class="sf-features-list">';
                    foreach ( $features as $f ) {
                        echo '<li>' . esc_html( $f ) . '</li>';
                    }
                    echo '</ul>';
                } else {
                    $sep = $s['features_separator'] ?? ', ';
                    $this->render_text( esc_html( implode( $sep, $features ) ) );
                }
                break;

            case 'count':
                $this->render_text( esc_html( (string) count( $packs ) ) );
                break;
        }
    }
}

/* ================================================================
 *  WIDGET OPTION
 * ================================================================ */

class Clielo_Widget_Option extends Clielo_Widget_Base {

    public function get_name(): string  { return 'sf_option'; }
    public function get_title(): string { return __( 'SF — Option', 'clielo' ); }
    public function get_icon(): string  { return 'eicon-plus-circle'; }

    protected function register_controls(): void {

        $this->start_controls_section( 'section_content', [
            'label' => __( 'Contenu', 'clielo' ),
        ] );

        $this->add_control( 'field', [
            'label'   => __( 'Champ', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'name',
            'options' => [
                'name'        => __( 'Nom', 'clielo' ),
                'price'       => __( 'Prix', 'clielo' ),
                'delay'       => __( 'Délai', 'clielo' ),
                'description' => __( 'Description', 'clielo' ),
                'count'       => __( 'Nombre d\'options', 'clielo' ),
            ],
        ] );

        $this->add_control( 'option_index', [
            'label'     => __( 'N° de l\'option (0 = première)', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::NUMBER,
            'default'   => 0,
            'min'       => 0,
            'condition' => [ 'field!' => 'count' ],
        ] );

        $this->add_control( 'currency', [
            'label'     => __( 'Devise', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => '€',
            'condition' => [ 'field' => 'price' ],
        ] );

        $this->add_control( 'delay_suffix', [
            'label'     => __( 'Suffixe', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => ' jour(s)',
            'condition' => [ 'field' => 'delay' ],
        ] );

        $this->register_html_tag_control();

        $this->end_controls_section();

        $this->register_style_section();
    }

    protected function render(): void {
        $s        = $this->get_settings_for_display();
        $opts     = Clielo_Options::get_options( $this->get_post_id() ) ?: [];
        $idx      = (int) ( $s['option_index'] ?? 0 );
        $field    = $s['field'] ?? 'name';
        $currency = esc_html( $s['currency'] ?? '€' );

        switch ( $field ) {
            case 'name':
                $this->render_text( esc_html( $opts[ $idx ]['name'] ?? '' ) );
                break;

            case 'price':
                $price = (float) ( $opts[ $idx ]['price'] ?? 0 );
                $this->render_text( esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency ) );
                break;

            case 'delay':
                $delay  = $opts[ $idx ]['delay'] ?? 0;
                $suffix = $s['delay_suffix'] ?? ' jour(s)';
                $this->render_text( esc_html( $delay . $suffix ) );
                break;

            case 'description':
                $this->render_text( esc_html( $opts[ $idx ]['description'] ?? '' ) );
                break;

            case 'count':
                $this->render_text( esc_html( (string) count( $opts ) ) );
                break;
        }
    }
}

/* ================================================================
 *  WIDGET PRIX AVANCÉS (Premium)
 * ================================================================ */

class Clielo_Widget_Advanced_Price extends Clielo_Widget_Base {

    public function get_name(): string  { return 'sf_advanced_price'; }
    public function get_title(): string { return __( 'SF — Prix avancé', 'clielo' ); }
    public function get_icon(): string  { return 'eicon-price-table'; }

    protected function register_controls(): void {

        $this->start_controls_section( 'section_content', [
            'label' => __( 'Contenu', 'clielo' ),
        ] );

        $this->add_control( 'field', [
            'label'   => __( 'Champ', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'extra_page',
            'options' => [
                'extra_page'  => __( 'Page supplémentaire', 'clielo' ),
                'maintenance' => __( 'Maintenance mensuelle', 'clielo' ),
                'express'     => __( 'Livraison express (par jour)', 'clielo' ),
            ],
        ] );

        $this->add_control( 'currency', [
            'label'   => __( 'Devise', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '€',
        ] );

        $this->register_html_tag_control();

        $this->end_controls_section();

        $this->register_style_section();
    }

    protected function render(): void {
        $s        = $this->get_settings_for_display();
        $post_id  = $this->get_post_id();
        $currency = esc_html( $s['currency'] ?? '€' );

        $meta_keys = [
            'extra_page'  => '_clielo_extra_page_price',
            'maintenance' => '_clielo_maintenance_price',
            'express'     => '_clielo_express_price',
        ];

        $key   = $meta_keys[ $s['field'] ?? 'extra_page' ] ?? '_clielo_extra_page_price';
        $price = (float) get_post_meta( $post_id, $key, true );

        $this->render_text( esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency ) );
    }
}

/* ================================================================
 *  WIDGET OPTIONS DE SERVICE (packs + options + bouton commander)
 * ================================================================ */

class Clielo_Widget_Service_Options extends \Elementor\Widget_Base {

    public function get_name(): string  { return 'clielo_service_options'; }
    public function get_title(): string { return __( 'Clielo — Options de service', 'clielo' ); }
    public function get_icon(): string  { return 'eicon-form-horizontal'; }
    public function get_categories(): array { return [ 'clielo' ]; }
    public function get_keywords(): array { return [ 'clielo', 'service', 'pack', 'options', 'prix' ]; }

    protected function register_controls(): void {

        /* ── Contenu ── */
        $this->start_controls_section( 'section_content', [
            'label' => __( 'Contenu', 'clielo' ),
        ] );

        $this->add_control( 'post_id', [
            'label'       => __( 'ID du service', 'clielo' ),
            'type'        => \Elementor\Controls_Manager::NUMBER,
            'description' => __( 'Laisser vide pour utiliser le service de la page courante.', 'clielo' ),
            'min'         => 0,
            'default'     => '',
        ] );

        $this->add_control( 'show_order_button', [
            'label'        => __( 'Afficher bouton commander', 'clielo' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => __( 'Oui', 'clielo' ),
            'label_off'    => __( 'Non', 'clielo' ),
            'return_value' => 'yes',
            'default'      => 'yes',
        ] );

        $this->add_control( 'show_chat_bubble', [
            'label'        => __( 'Afficher bouton chat flottant', 'clielo' ),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => __( 'Oui', 'clielo' ),
            'label_off'    => __( 'Non', 'clielo' ),
            'return_value' => 'yes',
            'default'      => 'yes',
        ] );

        $this->end_controls_section();

        /* ── Style ── */
        $this->start_controls_section( 'section_style', [
            'label' => __( 'Style', 'clielo' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'color_override', [
            'label'       => __( 'Couleur principale', 'clielo' ),
            'type'        => \Elementor\Controls_Manager::COLOR,
            'description' => __( 'Laisser vide pour utiliser la couleur du plugin.', 'clielo' ),
            'default'     => '',
        ] );

        $this->add_control( 'card_border_radius', [
            'label'      => __( 'Rayon de la carte', 'clielo' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 32 ] ],
            'default'    => [ 'size' => 12, 'unit' => 'px' ],
            'selectors'  => [ '{{WRAPPER}} #clielo-sc-card' => 'border-radius: {{SIZE}}{{UNIT}} !important;' ],
        ] );

        $this->add_control( 'button_border_radius', [
            'label'      => __( 'Rayon du bouton commander', 'clielo' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 32 ] ],
            'default'    => [ 'size' => 8, 'unit' => 'px' ],
            'selectors'  => [ '{{WRAPPER}} #clielo-sc-order' => 'border-radius: {{SIZE}}{{UNIT}} !important;' ],
        ] );

        $this->add_control( 'button_color', [
            'label'     => __( 'Couleur du bouton commander', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '',
            'selectors' => [ '{{WRAPPER}} #clielo-sc-order' => 'background: {{VALUE}} !important;' ],
        ] );

        $this->add_control( 'button_text_color', [
            'label'     => __( 'Couleur texte bouton', 'clielo' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'selectors' => [ '{{WRAPPER}} #clielo-sc-order' => 'color: {{VALUE}} !important;' ],
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $s                 = $this->get_settings_for_display();
        $post_id           = ! empty( $s['post_id'] ) ? absint( $s['post_id'] ) : 0;
        $color             = ! empty( $s['color_override'] ) ? sanitize_hex_color( $s['color_override'] ) : '';
        $show_order_button = 'yes' === ( $s['show_order_button'] ?? 'yes' );
        $show_chat_bubble  = 'yes' === ( $s['show_chat_bubble'] ?? 'yes' );

        $args = [
            'show_order_button' => $show_order_button,
        ];
        if ( $post_id ) {
            $args['post_id'] = $post_id;
        }
        if ( $color ) {
            $args['color'] = $color;
        }

        if ( ! $show_chat_bubble ) {
            echo '<style>#clielo-toggle{display:none !important}</style>';
        }

        echo Clielo_Front::render_options_card( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML already escaped in render_options_card().
    }
}
