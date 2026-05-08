<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Clielo_Admin {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'clielo',
            __( 'Clielo - Settings', 'clielo' ),
            __( 'Settings', 'clielo' ),
            'manage_options',
            'clielo-settings',
            [ __CLASS__, 'render_settings_page' ]
        );

        if ( function_exists( 'clielo_fs' ) && ! clielo_fs()->is_paying() ) {
            add_submenu_page(
                'clielo',
                __( 'Activer la licence', 'clielo' ),
                '🔑 ' . __( 'Activer la licence', 'clielo' ),
                'manage_options',
                'clielo-activate',
                [ __CLASS__, 'render_activate_page' ]
            );
        }
    }

    public static function render_activate_page(): void {
        if ( ! function_exists( 'clielo_fs' ) ) {
            return;
        }
        $fs = clielo_fs();

        if ( $fs->is_paying() ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Licence active ✓', 'clielo' ) . '</h1><p>' . esc_html__( 'Votre licence premium est active. Toutes les fonctionnalités sont débloquées.', 'clielo' ) . '</p></div>';
            return;
        }

        $connect_url  = $fs->is_registered() ? $fs->get_account_url() : admin_url( 'admin.php?page=clielo-account' );
        $upgrade_url  = $fs->get_upgrade_url();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Activer votre licence Clielo', 'clielo' ); ?></h1>
            <div style="max-width:560px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:28px 32px;margin-top:16px">
                <h2 style="margin-top:0"><?php esc_html_e( 'Vous avez déjà une licence ?', 'clielo' ); ?></h2>
                <p><?php esc_html_e( 'Connectez-vous à votre compte Freemius pour activer les fonctionnalités premium (Stripe, Factures, Todos, Emails…).', 'clielo' ); ?></p>
                <a href="<?php echo esc_url( $connect_url ); ?>" class="button button-primary button-large">
                    <?php esc_html_e( 'Connecter mon compte &amp; activer la licence', 'clielo' ); ?>
                </a>
                <hr style="margin:24px 0">
                <h2 style="margin-top:0"><?php esc_html_e( 'Pas encore de licence ?', 'clielo' ); ?></h2>
                <a href="<?php echo esc_url( $upgrade_url ); ?>" class="button button-secondary button-large">
                    <?php esc_html_e( 'Voir les plans premium', 'clielo' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    public static function register_settings(): void {
        // CPT
        register_setting( 'clielo_settings', 'clielo_post_type', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'annonce',
        ] );

        // Couleur principale
        register_setting( 'clielo_settings', 'clielo_color', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#0073aa',
        ] );

        // Position du bouton
        register_setting( 'clielo_settings', 'clielo_position', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'bottom-right',
        ] );

        // Section générale
        add_settings_section(
            'clielo_main_section',
            __( 'General settings', 'clielo' ),
            null,
            'clielo-settings'
        );

        add_settings_field(
            'clielo_post_type',
            __( 'Custom Post Type', 'clielo' ),
            [ __CLASS__, 'render_post_type_field' ],
            'clielo-settings',
            'clielo_main_section'
        );

        // Section apparence
        add_settings_section(
            'clielo_style_section',
            __( 'Appearance', 'clielo' ),
            null,
            'clielo-settings'
        );

        add_settings_field(
            'clielo_color',
            __( 'Couleur du chat', 'clielo' ),
            [ __CLASS__, 'render_color_field' ],
            'clielo-settings',
            'clielo_style_section'
        );

        add_settings_field(
            'clielo_position',
            __( 'Position du bouton', 'clielo' ),
            [ __CLASS__, 'render_position_field' ],
            'clielo-settings',
            'clielo_style_section'
        );

    }

    public static function render_post_type_field(): void {
        $current    = get_option( 'clielo_post_type', 'annonce' );
        $post_types = get_post_types( [ 'public' => true ], 'objects' );

        echo '<select name="clielo_post_type" id="clielo_post_type">';
        foreach ( $post_types as $pt ) {
            printf(
                '<option value="%s" %s>%s (%s)</option>',
                esc_attr( $pt->name ),
                selected( $current, $pt->name, false ),
                esc_html( $pt->labels->singular_name ),
                esc_html( $pt->name )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Sélectionnez le CPT sur lequel activer le chat.', 'clielo' ) . '</p>';
    }

    public static function render_color_field(): void {
        $color = get_option( 'clielo_color', '#0073aa' );
        printf(
            '<input type="color" name="clielo_color" id="clielo_color" value="%s" />',
            esc_attr( $color )
        );
        echo '<p class="description">' . esc_html__( 'Couleur principale du bouton et du header du chat.', 'clielo' ) . '</p>';
    }

    public static function render_position_field(): void {
        $current = get_option( 'clielo_position', 'bottom-right' );

        $positions = [
            'bottom-right' => __( 'Bas droite', 'clielo' ),
            'bottom-left'  => __( 'Bas gauche', 'clielo' ),
            'top-right'    => __( 'Haut droite', 'clielo' ),
            'top-left'     => __( 'Haut gauche', 'clielo' ),
        ];

        echo '<select name="clielo_position" id="clielo_position">';
        foreach ( $positions as $value => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $value ),
                selected( $current, $value, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'clielo_settings' );
                do_settings_sections( 'clielo-settings' );
                submit_button( __( 'Enregistrer', 'clielo' ) );
                ?>
            </form>
        </div>
        <?php
    }

    public static function is_extra_pages_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_clielo_extra_pages_enabled', true ) === '1';
    }

    public static function is_maintenance_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_clielo_maintenance_enabled', true ) === '1';
    }

    public static function is_express_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_clielo_express_enabled', true ) === '1';
    }

    public static function get_extra_pages_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_clielo_extra_pages_label', true ) : '';
        return $label ?: __( 'Pages supplémentaires', 'clielo' );
    }

    public static function get_maintenance_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_clielo_maintenance_label', true ) : '';
        return $label ?: __( 'Maintenance mensuelle', 'clielo' );
    }

    public static function get_express_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_clielo_express_label', true ) : '';
        return $label ?: __( 'Livraison express', 'clielo' );
    }

    public static function get_post_type(): string {
        return get_option( 'clielo_post_type', 'annonce' );
    }

    public static function get_color(): string {
        return get_option( 'clielo_color', '#0073aa' );
    }

    public static function get_position(): string {
        return get_option( 'clielo_position', 'bottom-right' );
    }
}
