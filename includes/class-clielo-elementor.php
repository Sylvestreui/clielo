<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Elementor {

    public static function init(): void {
        add_action( 'elementor/dynamic_tags/register', [ __CLASS__, 'register_dynamic_tags' ] );
    }

    public static function register_dynamic_tags( $dynamic_tags_manager ): void {
        // Groupe Clielo
        if ( method_exists( $dynamic_tags_manager, 'register_group' ) ) {
            $dynamic_tags_manager->register_group( 'clielo', [
                'title' => 'Clielo',
            ] );
        }

        // Tags individuels
        $dynamic_tags_manager->register( new Clielo_Tag_Pack_Name() );
        $dynamic_tags_manager->register( new Clielo_Tag_Pack_Price() );
        $dynamic_tags_manager->register( new Clielo_Tag_Pack_Starting_Price() );
        $dynamic_tags_manager->register( new Clielo_Tag_Pack_Delay() );
        $dynamic_tags_manager->register( new Clielo_Tag_Pack_Description() );
        $dynamic_tags_manager->register( new Clielo_Tag_Pack_Features() );
        $dynamic_tags_manager->register( new Clielo_Tag_Pack_Count() );
        $dynamic_tags_manager->register( new Clielo_Tag_Option_Name() );
        $dynamic_tags_manager->register( new Clielo_Tag_Option_Price() );
        $dynamic_tags_manager->register( new Clielo_Tag_Option_Delay() );
        $dynamic_tags_manager->register( new Clielo_Tag_Option_Description() );
        $dynamic_tags_manager->register( new Clielo_Tag_Option_Count() );
        $dynamic_tags_manager->register( new Clielo_Tag_Extra_Page_Price() );
        $dynamic_tags_manager->register( new Clielo_Tag_Maintenance_Price() );
        $dynamic_tags_manager->register( new Clielo_Tag_Express_Price() );
    }
}

/* ================================================================
 *  BASE TAG
 * ================================================================ */

abstract class Clielo_Tag_Base extends \Elementor\Core\DynamicTags\Tag {

    public function get_group(): string {
        return 'clielo';
    }

    public function get_categories(): array {
        return [ 'text' ];
    }

    protected function get_post_id(): int {
        return get_the_ID() ?: get_queried_object_id();
    }

    protected function get_packs(): array {
        return Clielo_Options::get_packs( $this->get_post_id() ) ?: [];
    }

    protected function get_options_list(): array {
        return Clielo_Options::get_options( $this->get_post_id() ) ?: [];
    }

    protected function register_pack_index_control(): void {
        $this->add_control( 'pack_index', [
            'label'   => __( 'N° du pack (0 = premier)', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 0,
            'min'     => 0,
        ] );
    }

    protected function register_option_index_control(): void {
        $this->add_control( 'option_index', [
            'label'   => __( 'N° de l\'option (0 = première)', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 0,
            'min'     => 0,
        ] );
    }

    protected function register_separator_control(): void {
        $this->add_control( 'separator', [
            'label'   => __( 'Séparateur', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => ', ',
        ] );
    }

    protected function register_currency_control(): void {
        $this->add_control( 'currency', [
            'label'   => __( 'Devise', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '€',
        ] );
    }
}

/* ================================================================
 *  PACK TAGS
 * ================================================================ */

class Clielo_Tag_Pack_Name extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_pack_name'; }
    public function get_title(): string { return __( 'Pack — Nom', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_pack_index_control();
    }

    public function render(): void {
        $packs = $this->get_packs();
        $idx   = (int) $this->get_settings( 'pack_index' );
        echo esc_html( $packs[ $idx ]['name'] ?? '' );
    }
}

class Clielo_Tag_Pack_Price extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_pack_price'; }
    public function get_title(): string { return __( 'Pack — Prix', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_pack_index_control();
        $this->register_currency_control();
    }

    public function render(): void {
        $packs    = $this->get_packs();
        $idx      = (int) $this->get_settings( 'pack_index' );
        $currency = $this->get_settings( 'currency' );
        $price    = $packs[ $idx ]['price'] ?? 0;
        echo esc_html( number_format( (float) $price, 2, ',', ' ' ) . ' ' . $currency );
    }
}

class Clielo_Tag_Pack_Starting_Price extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_pack_starting_price'; }
    public function get_title(): string { return __( 'Pack — Prix de départ', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_currency_control();
    }

    public function render(): void {
        $packs    = $this->get_packs();
        $currency = $this->get_settings( 'currency' );
        if ( empty( $packs ) ) {
            return;
        }
        $prices = array_map( function ( $p ) { return (float) ( $p['price'] ?? 0 ); }, $packs );
        $min    = min( $prices );
        echo esc_html( number_format( $min, 2, ',', ' ' ) . ' ' . $currency );
    }
}

class Clielo_Tag_Pack_Delay extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_pack_delay'; }
    public function get_title(): string { return __( 'Pack — Délai', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_pack_index_control();
        $this->add_control( 'suffix', [
            'label'   => __( 'Suffixe', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => ' jour(s)',
        ] );
    }

    public function render(): void {
        $packs  = $this->get_packs();
        $idx    = (int) $this->get_settings( 'pack_index' );
        $suffix = $this->get_settings( 'suffix' );
        $delay  = $packs[ $idx ]['delay'] ?? 0;
        echo esc_html( $delay . $suffix );
    }
}

class Clielo_Tag_Pack_Description extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_pack_description'; }
    public function get_title(): string { return __( 'Pack — Description', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_pack_index_control();
    }

    public function render(): void {
        $packs = $this->get_packs();
        $idx   = (int) $this->get_settings( 'pack_index' );
        echo esc_html( $packs[ $idx ]['description'] ?? '' );
    }
}

class Clielo_Tag_Pack_Features extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_pack_features'; }
    public function get_title(): string { return __( 'Pack — Features', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_pack_index_control();
        $this->register_separator_control();
        $this->add_control( 'format', [
            'label'   => __( 'Format', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'list',
            'options' => [
                'list'   => __( 'Liste (ul)', 'clielo' ),
                'inline' => __( 'En ligne (séparateur)', 'clielo' ),
            ],
        ] );
    }

    public function render(): void {
        $packs    = $this->get_packs();
        $idx      = (int) $this->get_settings( 'pack_index' );
        $format   = $this->get_settings( 'format' );
        $sep      = $this->get_settings( 'separator' );
        $features = $packs[ $idx ]['features'] ?? [];

        if ( empty( $features ) ) {
            return;
        }

        if ( $format === 'list' ) {
            echo '<ul class="clielo-features-list">';
            foreach ( $features as $f ) {
                echo '<li>' . esc_html( $f ) . '</li>';
            }
            echo '</ul>';
        } else {
            echo esc_html( implode( $sep, $features ) );
        }
    }
}

class Clielo_Tag_Pack_Count extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_pack_count'; }
    public function get_title(): string { return __( 'Pack — Nombre', 'clielo' ); }

    public function render(): void {
        echo esc_html( count( $this->get_packs() ) );
    }
}

/* ================================================================
 *  OPTION TAGS
 * ================================================================ */

class Clielo_Tag_Option_Name extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_option_name'; }
    public function get_title(): string { return __( 'Option — Nom', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_option_index_control();
    }

    public function render(): void {
        $opts = $this->get_options_list();
        $idx  = (int) $this->get_settings( 'option_index' );
        echo esc_html( $opts[ $idx ]['name'] ?? '' );
    }
}

class Clielo_Tag_Option_Price extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_option_price'; }
    public function get_title(): string { return __( 'Option — Prix', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_option_index_control();
        $this->register_currency_control();
    }

    public function render(): void {
        $opts     = $this->get_options_list();
        $idx      = (int) $this->get_settings( 'option_index' );
        $currency = $this->get_settings( 'currency' );
        $price    = $opts[ $idx ]['price'] ?? 0;
        echo esc_html( number_format( (float) $price, 2, ',', ' ' ) . ' ' . $currency );
    }
}

class Clielo_Tag_Option_Delay extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_option_delay'; }
    public function get_title(): string { return __( 'Option — Délai', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_option_index_control();
        $this->add_control( 'suffix', [
            'label'   => __( 'Suffixe', 'clielo' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => ' jour(s)',
        ] );
    }

    public function render(): void {
        $opts   = $this->get_options_list();
        $idx    = (int) $this->get_settings( 'option_index' );
        $suffix = $this->get_settings( 'suffix' );
        $delay  = $opts[ $idx ]['delay'] ?? 0;
        echo esc_html( $delay . $suffix );
    }
}

class Clielo_Tag_Option_Description extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_option_description'; }
    public function get_title(): string { return __( 'Option — Description', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_option_index_control();
    }

    public function render(): void {
        $opts = $this->get_options_list();
        $idx  = (int) $this->get_settings( 'option_index' );
        echo esc_html( $opts[ $idx ]['description'] ?? '' );
    }
}

class Clielo_Tag_Option_Count extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_option_count'; }
    public function get_title(): string { return __( 'Option — Nombre', 'clielo' ); }

    public function render(): void {
        echo esc_html( count( $this->get_options_list() ) );
    }
}

/* ================================================================
 *  PRICING TAGS
 * ================================================================ */

class Clielo_Tag_Extra_Page_Price extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_extra_page_price'; }
    public function get_title(): string { return __( 'Prix — Page supplémentaire', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_currency_control();
    }

    public function render(): void {
        $post_id  = $this->get_post_id();
        $currency = $this->get_settings( 'currency' );
        $price    = (float) get_post_meta( $post_id, '_clielo_extra_page_price', true );
        echo esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency );
    }
}

class Clielo_Tag_Maintenance_Price extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_maintenance_price'; }
    public function get_title(): string { return __( 'Prix — Maintenance mensuelle', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_currency_control();
    }

    public function render(): void {
        $post_id  = $this->get_post_id();
        $currency = $this->get_settings( 'currency' );
        $price    = (float) get_post_meta( $post_id, '_clielo_maintenance_price', true );
        echo esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency );
    }
}

class Clielo_Tag_Express_Price extends Clielo_Tag_Base {
    public function get_name(): string { return 'clielo_express_price'; }
    public function get_title(): string { return __( 'Prix — Livraison express', 'clielo' ); }

    protected function register_controls(): void {
        $this->register_currency_control();
    }

    public function render(): void {
        $post_id  = $this->get_post_id();
        $currency = $this->get_settings( 'currency' );
        $price    = (float) get_post_meta( $post_id, '_clielo_express_price', true );
        echo esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency );
    }
}
