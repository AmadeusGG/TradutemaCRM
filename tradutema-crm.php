<?php
/**
 * Plugin Name: Tradutema CRM
 * Plugin URI: https://tradutema.com/
 * Description: CRM interno de gestión de pedidos para Tradutema integrado con WooCommerce.
 * Version: 1.0.0
 * Author: Tradutema
 * Text Domain: tradutema-crm
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TRADUTEMA_CRM_VERSION' ) ) {
    define( 'TRADUTEMA_CRM_VERSION', '1.0.0' );
}

if ( ! defined( 'TRADUTEMA_CRM_PATH' ) ) {
    define( 'TRADUTEMA_CRM_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'TRADUTEMA_CRM_URL' ) ) {
    define( 'TRADUTEMA_CRM_URL', plugin_dir_url( __FILE__ ) );
}

require_once TRADUTEMA_CRM_PATH . 'includes/helpers.php';
require_once TRADUTEMA_CRM_PATH . 'includes/class-activator.php';
require_once TRADUTEMA_CRM_PATH . 'includes/class-core.php';

/**
 * Asegura que los roles y capacidades necesarios existan.
 */
function tradutema_crm_ensure_role_capabilities() {
    $capability = 'manage_tradutema_crm';

    $manager_capabilities = array(
        'read'                 => true,
        $capability            => true,
        'list_users'           => false,
    );

    $manager_role = get_role( 'tradutema_manager' );

    if ( ! $manager_role ) {
        add_role( 'tradutema_manager', __( 'Gestor Tradutema', 'tradutema-crm' ), $manager_capabilities );
    } else {
        foreach ( $manager_capabilities as $capability_key => $grant ) {
            if ( $grant ) {
                $manager_role->add_cap( $capability_key );
            } else {
                $manager_role->remove_cap( $capability_key );
            }
        }
    }

    $administrator = get_role( 'administrator' );

    if ( $administrator && ! $administrator->has_cap( $capability ) ) {
        $administrator->add_cap( $capability );
    }
}
add_action( 'init', 'tradutema_crm_ensure_role_capabilities' );

/**
 * Activación del plugin.
 */
function tradutema_crm_activate() {
    \Tradutema_CRM_Activator::activate();
}
register_activation_hook( __FILE__, 'tradutema_crm_activate' );

/**
 * Carga de funcionalidades principales.
 */
function tradutema_crm_bootstrap() {
    load_plugin_textdomain( 'tradutema-crm', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    \Tradutema_CRM_Core::instance();
}
add_action( 'plugins_loaded', 'tradutema_crm_bootstrap' );

/**
 * Comprueba si un usuario debe ser dirigido al panel del CRM.
 *
 * @param \WP_User $user Usuario autenticado.
 * @return bool
 */
function tradutema_crm_user_needs_dashboard_redirect( $user ) {
    if ( ! $user instanceof \WP_User ) {
        return false;
    }

    if ( in_array( 'tradutema_manager', (array) $user->roles, true ) ) {
        return true;
    }

    return false;
}

/**
 * Devuelve la URL del panel principal del CRM.
 *
 * @return string
 */
function tradutema_crm_dashboard_url() {
    return admin_url( 'admin.php?page=tradutema-crm' );
}

/**
 * Redirige a los perfiles con acceso al CRM al panel principal tras iniciar sesión.
 *
 * @param string       $redirect_url URL de redirección calculada.
 * @param \WP_User|mixed $user       Usuario autenticado.
 * @return string
 */
function tradutema_crm_redirect_to_dashboard( $redirect_url, $user ) {
    if ( ! tradutema_crm_user_needs_dashboard_redirect( $user ) ) {
        return $redirect_url;
    }

    return tradutema_crm_dashboard_url();
}

add_filter( 'login_redirect', 'tradutema_crm_redirect_to_dashboard', 10, 2 );
add_filter( 'woocommerce_login_redirect', 'tradutema_crm_redirect_to_dashboard', 10, 2 );

/**
 * Evita que WooCommerce muestre la página "Mi cuenta" a gestores y administradores del CRM.
 *
 * Cuando WooCommerce procesa la redirección tras el login puede llegar a forzar la visita
 * a /mi-cuenta/. Este callback garantiza que el usuario adecuado aterriza en el panel del CRM
 * incluso si llega manualmente a dicha URL.
 */
function tradutema_crm_bypass_my_account_page() {
    if ( ! function_exists( 'is_account_page' ) || ! is_user_logged_in() ) {
        return;
    }

    if ( ! is_account_page() ) {
        return;
    }

    $user = wp_get_current_user();

    if ( ! tradutema_crm_user_needs_dashboard_redirect( $user ) ) {
        return;
    }

    wp_safe_redirect( tradutema_crm_dashboard_url() );
    exit;
}

add_action( 'template_redirect', 'tradutema_crm_bypass_my_account_page' );

/**
 * Permite que los gestores accedan al escritorio sin redirecciones de WooCommerce.
 *
 * WooCommerce bloquea el acceso al área de administración para los roles sin capacidades de
 * edición, lo que provocaba un bucle de redirección entre /mi-cuenta/ y el panel del CRM.
 *
 * @param bool $prevent_access Valor actual del filtro.
 * @return bool
 */
function tradutema_crm_allow_admin_access_for_managers( $prevent_access ) {
    if ( ! is_user_logged_in() ) {
        return $prevent_access;
    }

    $user = wp_get_current_user();

    if ( tradutema_crm_user_needs_dashboard_redirect( $user ) ) {
        return false;
    }

    return $prevent_access;
}

add_filter( 'woocommerce_prevent_admin_access', 'tradutema_crm_allow_admin_access_for_managers' );

/**
 * Redirige a los gestores del CRM cuando acceden a pantallas genéricas del escritorio.
 */
function tradutema_crm_redirect_from_default_admin_pages() {
    if ( ! is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
        return;
    }

    $user = wp_get_current_user();

    if ( ! tradutema_crm_user_needs_dashboard_redirect( $user ) ) {
        return;
    }

    if ( isset( $_GET['page'] ) && 0 === strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'tradutema-crm' ) ) {
        return;
    }

    global $pagenow;

    if ( in_array( $pagenow, array( 'index.php', 'profile.php' ), true ) ) {
        wp_safe_redirect( tradutema_crm_dashboard_url() );
        exit;
    }
}

add_action( 'admin_init', 'tradutema_crm_redirect_from_default_admin_pages' );
