<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Shortcodes {

    public static function init(): void {
        // Packs
        add_shortcode( 'clielo_pack_name',          [ __CLASS__, 'pack_name' ] );
        add_shortcode( 'clielo_pack_price',         [ __CLASS__, 'pack_price' ] );
        add_shortcode( 'clielo_pack_starting_price',[ __CLASS__, 'pack_starting_price' ] );
        add_shortcode( 'clielo_pack_delay',         [ __CLASS__, 'pack_delay' ] );
        add_shortcode( 'clielo_pack_description',   [ __CLASS__, 'pack_description' ] );
        add_shortcode( 'clielo_pack_features',      [ __CLASS__, 'pack_features' ] );
        add_shortcode( 'clielo_pack_count',         [ __CLASS__, 'pack_count' ] );

        // Options
        add_shortcode( 'clielo_option_name',        [ __CLASS__, 'option_name' ] );
        add_shortcode( 'clielo_option_price',       [ __CLASS__, 'option_price' ] );
        add_shortcode( 'clielo_option_delay',       [ __CLASS__, 'option_delay' ] );
        add_shortcode( 'clielo_option_description', [ __CLASS__, 'option_description' ] );
        add_shortcode( 'clielo_option_count',       [ __CLASS__, 'option_count' ] );

        // Prix avancés (Premium)
        add_shortcode( 'clielo_extra_page_price',   [ __CLASS__, 'extra_page_price' ] );
        add_shortcode( 'clielo_maintenance_price',  [ __CLASS__, 'maintenance_price' ] );
        add_shortcode( 'clielo_express_price',      [ __CLASS__, 'express_price' ] );
    }

    /* ================================================================
     *  HELPERS
     * ================================================================ */

    private static function get_post_id( array $atts ): int {
        return ! empty( $atts['post_id'] ) ? (int) $atts['post_id'] : ( get_the_ID() ?: get_queried_object_id() );
    }

    private static function get_packs( array $atts ): array {
        return Clielo_Options::get_packs( self::get_post_id( $atts ) ) ?: [];
    }

    private static function get_options( array $atts ): array {
        return Clielo_Options::get_options( self::get_post_id( $atts ) ) ?: [];
    }

    private static function format_price( float $price, string $currency ): string {
        return number_format( $price, 2, ',', ' ' ) . ' ' . $currency;
    }

    /* ================================================================
     *  PACK SHORTCODES
     * ================================================================ */

    /**
     * [clielo_pack_name index="0" post_id=""]
     */
    public static function pack_name( array $atts ): string {
        $atts  = shortcode_atts( [ 'index' => 0, 'post_id' => '' ], $atts );
        $packs = self::get_packs( $atts );
        $idx   = (int) $atts['index'];
        return esc_html( $packs[ $idx ]['name'] ?? '' );
    }

    /**
     * [clielo_pack_price index="0" currency="€" post_id=""]
     */
    public static function pack_price( array $atts ): string {
        $atts  = shortcode_atts( [ 'index' => 0, 'currency' => '€', 'post_id' => '' ], $atts );
        $packs = self::get_packs( $atts );
        $idx   = (int) $atts['index'];
        $price = (float) ( $packs[ $idx ]['price'] ?? 0 );
        return esc_html( self::format_price( $price, $atts['currency'] ) );
    }

    /**
     * [clielo_pack_starting_price currency="€" post_id=""]
     */
    public static function pack_starting_price( array $atts ): string {
        $atts  = shortcode_atts( [ 'currency' => '€', 'post_id' => '' ], $atts );
        $packs = self::get_packs( $atts );
        if ( empty( $packs ) ) {
            return '';
        }
        $prices = array_map( fn( $p ) => (float) ( $p['price'] ?? 0 ), $packs );
        return esc_html( self::format_price( min( $prices ), $atts['currency'] ) );
    }

    /**
     * [clielo_pack_delay index="0" suffix=" jour(s)" post_id=""]
     */
    public static function pack_delay( array $atts ): string {
        $atts  = shortcode_atts( [ 'index' => 0, 'suffix' => ' jour(s)', 'post_id' => '' ], $atts );
        $packs = self::get_packs( $atts );
        $idx   = (int) $atts['index'];
        $delay = $packs[ $idx ]['delay'] ?? 0;
        return esc_html( $delay . $atts['suffix'] );
    }

    /**
     * [clielo_pack_description index="0" post_id=""]
     */
    public static function pack_description( array $atts ): string {
        $atts  = shortcode_atts( [ 'index' => 0, 'post_id' => '' ], $atts );
        $packs = self::get_packs( $atts );
        $idx   = (int) $atts['index'];
        return esc_html( $packs[ $idx ]['description'] ?? '' );
    }

    /**
     * [clielo_pack_features index="0" format="list" separator=", " post_id=""]
     * format : "list" (ul/li) ou "inline" (séparateur)
     */
    public static function pack_features( array $atts ): string {
        $atts     = shortcode_atts( [ 'index' => 0, 'format' => 'list', 'separator' => ', ', 'post_id' => '' ], $atts );
        $packs    = self::get_packs( $atts );
        $idx      = (int) $atts['index'];
        $features = $packs[ $idx ]['features'] ?? [];

        if ( empty( $features ) || ! is_array( $features ) ) {
            return '';
        }

        if ( $atts['format'] === 'list' ) {
            $items = array_map( fn( $f ) => '<li>' . esc_html( $f ) . '</li>', $features );
            return '<ul class="clielo-features-list">' . implode( '', $items ) . '</ul>';
        }

        return esc_html( implode( $atts['separator'], $features ) );
    }

    /**
     * [clielo_pack_count post_id=""]
     */
    public static function pack_count( array $atts ): string {
        $atts = shortcode_atts( [ 'post_id' => '' ], $atts );
        return (string) count( self::get_packs( $atts ) );
    }

    /* ================================================================
     *  OPTION SHORTCODES
     * ================================================================ */

    /**
     * [clielo_option_name index="0" post_id=""]
     */
    public static function option_name( array $atts ): string {
        $atts = shortcode_atts( [ 'index' => 0, 'post_id' => '' ], $atts );
        $opts = self::get_options( $atts );
        $idx  = (int) $atts['index'];
        return esc_html( $opts[ $idx ]['name'] ?? '' );
    }

    /**
     * [clielo_option_price index="0" currency="€" post_id=""]
     */
    public static function option_price( array $atts ): string {
        $atts  = shortcode_atts( [ 'index' => 0, 'currency' => '€', 'post_id' => '' ], $atts );
        $opts  = self::get_options( $atts );
        $idx   = (int) $atts['index'];
        $price = (float) ( $opts[ $idx ]['price'] ?? 0 );
        return esc_html( self::format_price( $price, $atts['currency'] ) );
    }

    /**
     * [clielo_option_delay index="0" suffix=" jour(s)" post_id=""]
     */
    public static function option_delay( array $atts ): string {
        $atts  = shortcode_atts( [ 'index' => 0, 'suffix' => ' jour(s)', 'post_id' => '' ], $atts );
        $opts  = self::get_options( $atts );
        $idx   = (int) $atts['index'];
        $delay = $opts[ $idx ]['delay'] ?? 0;
        return esc_html( $delay . $atts['suffix'] );
    }

    /**
     * [clielo_option_description index="0" post_id=""]
     */
    public static function option_description( array $atts ): string {
        $atts = shortcode_atts( [ 'index' => 0, 'post_id' => '' ], $atts );
        $opts = self::get_options( $atts );
        $idx  = (int) $atts['index'];
        return esc_html( $opts[ $idx ]['description'] ?? '' );
    }

    /**
     * [clielo_option_count post_id=""]
     */
    public static function option_count( array $atts ): string {
        $atts = shortcode_atts( [ 'post_id' => '' ], $atts );
        return (string) count( self::get_options( $atts ) );
    }

    /* ================================================================
     *  PRIX AVANCÉS (Premium)
     * ================================================================ */

    /**
     * [clielo_extra_page_price currency="€" post_id=""]
     */
    public static function extra_page_price( array $atts ): string {
        $atts    = shortcode_atts( [ 'currency' => '€', 'post_id' => '' ], $atts );
        $post_id = self::get_post_id( $atts );
        $price   = (float) get_post_meta( $post_id, '_clielo_extra_page_price', true );
        return esc_html( self::format_price( $price, $atts['currency'] ) );
    }

    /**
     * [clielo_maintenance_price currency="€" post_id=""]
     */
    public static function maintenance_price( array $atts ): string {
        $atts    = shortcode_atts( [ 'currency' => '€', 'post_id' => '' ], $atts );
        $post_id = self::get_post_id( $atts );
        $price   = (float) get_post_meta( $post_id, '_clielo_maintenance_price', true );
        return esc_html( self::format_price( $price, $atts['currency'] ) );
    }

    /**
     * [clielo_express_price currency="€" post_id=""]
     */
    public static function express_price( array $atts ): string {
        $atts    = shortcode_atts( [ 'currency' => '€', 'post_id' => '' ], $atts );
        $post_id = self::get_post_id( $atts );
        $price   = (float) get_post_meta( $post_id, '_clielo_express_price', true );
        return esc_html( self::format_price( $price, $atts['currency'] ) );
    }
}
