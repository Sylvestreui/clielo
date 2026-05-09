<?php
/**
 * Plugin Name: Clielo
 * Plugin URI:  https://github.com/SylvestreUi/clielo
 * Description: Service management plugin with integrated chat, order tracking, invoicing, Stripe payments and client notifications for any Custom Post Type.
 * Version:     1.0.9
 * Author:      SylvestreUi
 * Author URI:  https://github.com/SylvestreUi
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: clielo
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CLIELO_VERSION', '1.0.9' );
define( 'CLIELO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLIELO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* ================================================================
 *  FREEMIUS SDK
 * ================================================================ */

if ( ! function_exists( 'clielo_fs' ) ) {
    function clielo_fs() {
        global $clielo_fs;

        if ( ! isset( $clielo_fs ) ) {
            require_once dirname( __FILE__ ) . '/vendor/freemius/wordpress-sdk/start.php';

            $clielo_fs = fs_dynamic_init( array(
                'id'                  => '24444',
                'slug'                => 'clielo',
                'premium_slug'        => 'clielo-premium',
                'type'                => 'plugin',
                'public_key'          => 'pk_b46863119a89c13fd6d225cc981e1',
                'is_premium'          => false,
                'has_premium_version' => false,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                'trial'               => array(
                    'days'               => 14,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    'slug'           => 'clielo',
                    'first-path'     => 'admin.php?page=clielo',
                    'support'        => false,
                ),
            ) );
        }

        return $clielo_fs;
    }

    clielo_fs();
    do_action( 'clielo_fs_loaded' );
}

/**
 * Vérifie si le plan premium est actif.
 */
function clielo_is_premium(): bool {
    return clielo_fs()->is_paying();
}

// Stripe PHP SDK
$clielo_stripe_init = CLIELO_PLUGIN_DIR . 'lib/init.php';
if ( file_exists( $clielo_stripe_init ) ) {
    require_once $clielo_stripe_init;
}

// Chargement des fichiers
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-db.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-admin.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-ajax.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-front.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-options.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-orders.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-dashboard.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-account.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-notifications.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-invoices.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-stripe.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-payments.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-todos.php';
require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-shortcodes.php';

// Activation
register_activation_hook( __FILE__, function () {
    Clielo_DB::create_table();
    Clielo_Orders::create_table();
    Clielo_Notifications::create_table();
    Clielo_Invoices::create_invoices_table();
    Clielo_Invoices::create_clients_table();
    Clielo_Todos::create_table();
    Clielo_Payments::create_table();
    Clielo_DB::migrate_client_ids();
    if ( ! wp_next_scheduled( 'clielo_daily_payments' ) ) {
        wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', 'clielo_daily_payments' );
    }
} );

// Désactivation
register_deactivation_hook( __FILE__, function () {
    $ts = wp_next_scheduled( 'clielo_daily_payments' );
    if ( $ts ) {
        wp_unschedule_event( $ts, 'clielo_daily_payments' );
    }
} );

// Mise à jour DB pour installations existantes
add_action( 'admin_init', function () {
    if ( get_option( 'clielo_db_version' ) !== CLIELO_VERSION ) {
        Clielo_DB::create_table();
        Clielo_Orders::create_table();
        Clielo_Notifications::create_table();
        Clielo_Invoices::create_invoices_table();
        Clielo_Invoices::create_clients_table();
        Clielo_Todos::create_table();
        Clielo_Payments::create_table();
        Clielo_DB::migrate_client_ids();
        Clielo_Payments::migrate_due_dates();
        update_option( 'clielo_db_version', CLIELO_VERSION );
    }

    // Migration ponctuelle : ajout des colonnes extra_* dans clielo_orders (v1.0.0 patch)
    if ( ! get_option( 'clielo_orders_extra_cols' ) ) {
        Clielo_Orders::create_table();
        update_option( 'clielo_orders_extra_cols', '1' );
    }
    // Migration ponctuelle : ajout de la colonne advanced_options_data dans clielo_orders
    if ( ! get_option( 'clielo_orders_advanced_opts_col' ) ) {
        Clielo_Orders::create_table();
        update_option( 'clielo_orders_advanced_opts_col', '1' );
    }
    if ( ! wp_next_scheduled( 'clielo_daily_payments' ) ) {
        wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', 'clielo_daily_payments' );
    }
} );

// Avertissement si WP-Cron est désactivé (envoi automatique des paiements ne fonctionnera pas)
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
        echo '<div class="notice notice-warning"><p>'
            . '<strong>Clielo :</strong> '
            . esc_html__( 'WP-Cron est désactivé sur ce site (DISABLE_WP_CRON). L\'envoi automatique des liens de paiement à la date d\'échéance ne fonctionnera pas. Ajoutez une vraie cron job système pointant vers wp-cron.php, ou retirez DISABLE_WP_CRON de wp-config.php.', 'clielo' )
            . '</p></div>';
    }
} );

// Restreindre l'accès au tableau de bord WP aux non-admins
add_action( 'admin_init', function () {
    if ( wp_doing_ajax() ) {
        return; // Les requêtes AJAX doivent toujours passer
    }
    if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
        wp_safe_redirect( home_url() );
        exit;
    }
} );

add_action( 'after_setup_theme', function () {
    if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
        show_admin_bar( false );
    }
} );

// Initialisation
add_action( 'init', function () {
    Clielo_Dashboard::init();
    Clielo_Admin::init();
    Clielo_Ajax::init();
    Clielo_Front::init();
    Clielo_Options::init();
    Clielo_Orders::init();
    Clielo_Account::init();
    Clielo_Notifications::init();
    Clielo_Invoices::init();
    Clielo_Stripe::init();
    Clielo_Payments::init();
    Clielo_Todos::init();
    Clielo_Shortcodes::init();
} );

// Elementor Dynamic Tags
add_action( 'elementor/dynamic_tags/register', function ( $manager ) {
    require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-elementor.php';
    Clielo_Elementor::register_dynamic_tags( $manager );
} );

// Elementor Widgets (Elementor Free compatible)
add_action( 'elementor/elements/categories_registered', function ( $elements_manager ) {
    if ( ! class_exists( 'Clielo_Elementor_Widgets' ) ) {
        require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-elementor-widgets.php';
    }
    Clielo_Elementor_Widgets::add_category( $elements_manager );
} );

add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
    try {
        if ( ! class_exists( 'Clielo_Elementor_Widgets' ) ) {
            require_once CLIELO_PLUGIN_DIR . 'includes/class-clielo-elementor-widgets.php';
        }
        Clielo_Elementor_Widgets::register_widgets( $widgets_manager );
    } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        // Elementor widget registration failure — silently ignored to avoid fatal errors.
    }
} );
