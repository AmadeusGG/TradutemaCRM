<?php
/**
 * Núcleo del plugin Tradutema CRM.
 *
 * @package Tradutema_CRM
 */

namespace Tradutema_CRM;

use WC_Countries;
use WC_Order;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase principal que controla el comportamiento del CRM.
 */
class Core {

    /**
     * Instancia única.
     *
     * @var Core|null
     */
    private static $instance = null;

    /**
     * Cache local de proveedores para evitar consultas repetidas.
     *
     * @var array|null
     */
    private $cached_proveedores = null;

    /**
     * Registro de carpetas de Google Drive que ya han sido procesadas.
     *
     * @var array<string,bool>
     */
    private $drive_permission_cache = array();

    /**
     * Enlaces compartidos recuperados desde la API de Google Drive.
     *
     * @var array<string,string>
     */
    private $drive_share_link_cache = array();

    /**
     * Slug de la página específica para gestionar pedidos.
     */
    const MANAGE_ORDER_PAGE = 'tradutema-crm-order';

    /**
     * Dirección que debe recibir copia oculta de todos los correos enviados.
     */
    const BCC_EMAIL = 'jpardofurness@gmail.com';

    /**
     * Query var utilizada para identificar la subida de ficheros del proveedor.
     */
    const PROVIDER_UPLOAD_QUERY_VAR = 'tradutema_upload_token';

    /**
     * Prefijo de la opción utilizada para guardar los tokens de subida.
     */
    const PROVIDER_UPLOAD_OPTION_PREFIX = 'tradutema_crm_upload_token_';

    /**
     * Clave de la subcarpeta de Google Drive destinada a la entrega al cliente.
     */
    const TO_CLIENT_FOLDER_KEY = '04-ToClient';

    /**
     * Clave de la subcarpeta de Google Drive destinada a las traducciones internas.
     */
    const TRANSLATION_FOLDER_KEY = '03-Translation';

    /**
     * Constructor privado para aplicar patrón singleton.
     */
    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_submissions' ) );
        add_action( 'admin_notices', array( $this, 'render_notices' ) );
        add_action( 'admin_head', array( $this, 'print_styles' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_filter( 'admin_body_class', array( $this, 'add_body_class' ) );
        add_filter( 'user_has_cap', array( $this, 'grant_shop_order_capabilities' ), 10, 4 );
        add_filter( 'map_meta_cap', array( $this, 'map_shop_order_meta_capabilities' ), 10, 4 );
        add_action( 'template_redirect', array( $this, 'maybe_render_provider_upload_page' ) );
        add_filter( 'pre_trash_post', array( $this, 'prevent_shop_order_deletion' ), 10, 2 );
        add_filter( 'pre_delete_post', array( $this, 'prevent_shop_order_deletion' ), 10, 3 );
    }

    /**
     * Devuelve la instancia única del núcleo.
     *
     * @return Core
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Garantiza que los usuarios con acceso al CRM puedan consultar pedidos de WooCommerce.
     *
     * El botón "WOO" de la tabla dirige al editor del pedido (post.php). WordPress
     * requiere varias capacidades relacionadas con pedidos para cargar esa pantalla,
     * por lo que concedemos solo las capacidades de lectura a quienes ya cuentan
     * con la capacidad principal del CRM.
     *
     * @param array    $allcaps Todas las capacidades del usuario.
     * @param array    $caps    Capacidades primitivas que se están comprobando.
     * @param array    $args    Argumentos adicionales relacionados con la comprobación.
     * @param WP_User  $user    Usuario que está siendo evaluado.
     *
     * @return array Capacidades actualizadas.
     */
    public function grant_shop_order_capabilities( $allcaps, $caps, $args, $user ) {
        if ( empty( $allcaps['manage_tradutema_crm'] ) ) {
            return $allcaps;
        }

        $requested_cap = isset( $args[0] ) ? $args[0] : '';

        $shop_order_caps = array(
            'read_shop_order',
            'read_private_shop_orders',
        );

        if ( in_array( $requested_cap, $shop_order_caps, true ) ) {
            $allcaps[ $requested_cap ] = true;
        }

        $post_cap_map = array(
            'read_post' => 'read_shop_order',
        );

        if ( isset( $post_cap_map[ $requested_cap ], $args[2] ) ) {
            $post_id = (int) $args[2];

            if ( $post_id > 0 && 'shop_order' === get_post_type( $post_id ) ) {
                $mapped_cap = $post_cap_map[ $requested_cap ];
                $allcaps[ $mapped_cap ] = true;
                $allcaps[ $requested_cap ] = true;
            }
        }

        return $allcaps;
    }

    /**
     * Mapea las capacidades meta de los pedidos para los gestores del CRM.
     *
     * WordPress traduce capacidades como `edit_post` y `edit_shop_order` en
     * capacidades primitivas mediante este filtro. Si el pedido es un
     * `shop_order` permitimos que quienes gestionan el CRM lo consulten sin
     * conceder permisos de edición o borrado sobre los pedidos.
     *
     * @param array  $caps    Capacidades primitivas requeridas.
     * @param string $cap     Capacidad meta solicitada.
     * @param int    $user_id ID del usuario evaluado.
     * @param array  $args    Argumentos adicionales.
     *
     * @return array
     */
    public function map_shop_order_meta_capabilities( $caps, $cap, $user_id, $args ) {
        $user = $user_id ? get_user_by( 'id', $user_id ) : null;

        if ( $user && ! empty( $user->allcaps['manage_tradutema_crm'] ) ) {
            $read_caps = array(
                'read_shop_order',
                'read_private_shop_orders',
            );

            if ( in_array( $cap, $read_caps, true ) ) {
                return array( 'manage_tradutema_crm' );
            }

            if ( 'read_post' === $cap ) {
                $post_id = isset( $args[0] ) ? (int) $args[0] : 0;

                if ( $post_id > 0 && 'shop_order' === get_post_type( $post_id ) ) {
                    return array( 'manage_tradutema_crm' );
                }
            }
        }

        return $caps;
    }

    /**
     * Evita que cualquier pedido pueda moverse a la papelera o eliminarse.
     *
     * @param mixed      $delete       Valor devuelto por filtros previos.
     * @param int|WP_Post $post         Publicación que se intenta borrar.
     * @param bool       $force_delete Indica si se debe borrar definitivamente.
     *
     * @return false|null Devuelve false para bloquear el borrado de pedidos.
     */
    public function prevent_shop_order_deletion( $delete, $post, $force_delete = false ) {
    $post_obj = $post instanceof WP_Post ? $post : get_post( $post );

    if ( ! $post_obj || 'shop_order' !== $post_obj->post_type ) {
        return $delete;
    }

    // Permitir SOLO desde el listado estándar de WooCommerce
    if ( is_admin() && isset( $_REQUEST['post_type'] ) && $_REQUEST['post_type'] === 'shop_order' ) {
        return $delete;
    }

    // Bloquear cualquier otro intento
    return false;
}


    /**
     * Muestra la vista pública para que el proveedor suba los ficheros finales.
     */
    public function maybe_render_provider_upload_page() {
        if ( is_admin() ) {
            return;
        }

        if ( ! isset( $_GET[ self::PROVIDER_UPLOAD_QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $token = isset( $_GET[ self::PROVIDER_UPLOAD_QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PROVIDER_UPLOAD_QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $is_internal_provider = false;

        if ( '' === $token ) {
            $this->render_provider_upload_template( null, $token, array( __( 'El enlace proporcionado no es válido.', 'tradutema-crm' ) ), false, array(), array(), $is_internal_provider );
            exit;
        }

        $token_data = $this->resolve_provider_upload_token( $token );

        if ( is_wp_error( $token_data ) ) {
            $this->render_provider_upload_template( null, $token, array( $token_data->get_error_message() ), false, array(), array(), $is_internal_provider );
            exit;
        }

        $order_id = isset( $token_data['order_id'] ) ? absint( $token_data['order_id'] ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : false;

        if ( ! $order ) {
            $this->render_provider_upload_template( null, $token, array( __( 'No se pudo localizar el pedido asociado a este enlace.', 'tradutema-crm' ) ), false, array(), $token_data, $is_internal_provider );
            exit;
        }

        $meta      = $this->get_order_meta( $order_id );
        $provider  = array();
        $errors    = array();
        $success   = false;

        if ( ! empty( $meta['proveedor_id'] ) ) {
            $provider = $this->get_proveedor( absint( $meta['proveedor_id'] ) );

            if ( ! is_array( $provider ) ) {
                $provider = array();
            }
        }

        $is_internal_provider = ! empty( $provider['interno'] );
        $uploaded_files       = array();

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! empty( $token_data['used'] ) ) {
                $errors[] = __( 'Este enlace ya ha sido utilizado y no se puede volver a usar.', 'tradutema-crm' );
            } else {
                $result = $this->handle_provider_upload_submission( $order, $token, $token_data );

                if ( is_wp_error( $result ) ) {
                    $errors[] = $result->get_error_message();
                } else {
                    $success        = true;
                    $uploaded_files = $result;
                    $token_data     = $this->resolve_provider_upload_token( $token );

                    if ( is_wp_error( $token_data ) ) {
                        $token_data = array();
                    }
                }
            }
        } elseif ( ! empty( $token_data['used'] ) ) {
            $errors[] = __( 'Este enlace ya ha sido utilizado y no se puede volver a usar.', 'tradutema-crm' );
        }

        $this->render_provider_upload_template( $order, $token, $errors, $success, $uploaded_files, $token_data, $is_internal_provider );
        exit;
    }

    /**
     * Registra las páginas de menú del CRM.
     */
    public function register_menu() {
        add_menu_page(
            __( 'Tradutema CRM', 'tradutema-crm' ),
            __( 'Tradutema CRM', 'tradutema-crm' ),
            'manage_tradutema_crm',
            'tradutema-crm',
            array( $this, 'render_dashboard' ),
            'dashicons-portfolio',
            2
        );

        add_submenu_page(
            'tradutema-crm',
            __( 'Dashboard', 'tradutema-crm' ),
            __( 'Dashboard', 'tradutema-crm' ),
            'manage_tradutema_crm',
            'tradutema-crm',
            array( $this, 'render_dashboard' )
        );

        add_submenu_page(
            'tradutema-crm',
            __( 'Proveedores', 'tradutema-crm' ),
            __( 'Proveedores', 'tradutema-crm' ),
            'manage_tradutema_crm',
            'tradutema-crm-proveedores',
            array( $this, 'render_proveedores' )
        );

        add_submenu_page(
            'tradutema-crm',
            __( 'Plantillas de email', 'tradutema-crm' ),
            __( 'Plantillas de email', 'tradutema-crm' ),
            'manage_tradutema_crm',
            'tradutema-crm-plantillas',
            array( $this, 'render_email_templates' )
        );

        add_submenu_page(
            null,
            __( 'Gestionar pedido', 'tradutema-crm' ),
            __( 'Gestionar pedido', 'tradutema-crm' ),
            'manage_tradutema_crm',
            self::MANAGE_ORDER_PAGE,
            array( $this, 'render_manage_order_page' )
        );
    }

    /**
     * Maneja los formularios enviados desde las páginas del CRM.
     */
    public function handle_submissions() {
        if ( empty( $_POST['tradutema_crm_action'] ) ) {
            return;
        }

        // Algunos entornos han perdido temporalmente la capacidad
        // `manage_tradutema_crm` pese a que el usuario sea administrador,
        // provocando que los formularios de pedido y proveedores no se
        // procesen. Damos acceso de respaldo a quienes puedan gestionar
        // opciones para evitar bloqueos del panel.
        if ( ! current_user_can( 'manage_tradutema_crm' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = sanitize_text_field( wp_unslash( $_POST['tradutema_crm_action'] ) );

        switch ( $action ) {
            case 'save_proveedor':
                $this->handle_save_proveedor();
                break;
            case 'delete_proveedor':
                $this->handle_delete_proveedor();
                break;
            case 'save_email_template':
                $this->handle_save_email_template();
                break;
            case 'delete_email_template':
                $this->handle_delete_email_template();
                break;
            case 'update_order':
                $this->handle_update_order();
                break;
            case 'send_email_template':
                $this->handle_send_email_template();
                break;
        }
    }

    /**
     * Renderiza las notificaciones generadas al procesar formularios.
     */
    public function render_notices() {
        $stored_notices = get_transient( 'tradutema_crm_notices' );

        if ( ! empty( $stored_notices ) && is_array( $stored_notices ) ) {
            foreach ( $stored_notices as $notice ) {
                $type    = isset( $notice['type'] ) ? $notice['type'] : 'error';
                $code    = isset( $notice['code'] ) ? $notice['code'] : uniqid( 'tradutema-crm', true );
                $message = isset( $notice['message'] ) ? $notice['message'] : '';

                add_settings_error( 'tradutema-crm', $code, $message, $type );
            }

            delete_transient( 'tradutema_crm_notices' );
        }

        settings_errors( 'tradutema-crm' );
    }

    /**
     * Imprime estilos básicos para las vistas del CRM.
     */
    public function print_styles() {
        if ( ! $this->is_crm_screen() ) {
            return;
        }

        echo '<style id="tradutema-crm-inline-styles">'
            . 'body.tradutema-crm-admin #wpcontent{margin-left:0;padding-left:0;}'
            . 'body.tradutema-crm-admin #wpbody-content{padding-bottom:0;}'
            . 'body.tradutema-crm-admin #adminmenumain,'
            . 'body.tradutema-crm-admin #adminmenuback,'
            . 'body.tradutema-crm-admin #wpadminbar,'
            . 'body.tradutema-crm-admin #wpfooter{display:none;}'
            . 'body.tradutema-crm-admin #wpbody{padding-top:0;}'
            . '</style>';
    }

    /**
     * Carga los estilos y scripts necesarios para la experiencia modernizada.
     *
     * @param string $hook Hook actual de WordPress.
     */
    public function enqueue_assets( $hook ) {
        if ( false === strpos( (string) $hook, 'tradutema-crm' ) ) {
            return;
        }

        wp_enqueue_style( 'tradutema-crm-bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', array(), '5.3.3' );
        wp_enqueue_style( 'tradutema-crm-datatables', 'https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css', array( 'tradutema-crm-bootstrap' ), '1.13.8' );
        wp_enqueue_style( 'tradutema-crm-datatables-responsive', 'https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css', array( 'tradutema-crm-datatables' ), '2.5.0' );

        wp_register_style( 'tradutema-crm-admin', false, array( 'tradutema-crm-datatables-responsive' ), TRADUTEMA_CRM_VERSION );
        wp_enqueue_style( 'tradutema-crm-admin' );
        wp_add_inline_style( 'tradutema-crm-admin', $this->get_admin_css() );

        wp_enqueue_script( 'tradutema-crm-bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', array(), '5.3.3', true );
        wp_enqueue_script( 'tradutema-crm-datatables', 'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js', array( 'jquery' ), '1.13.8', true );
        wp_enqueue_script( 'tradutema-crm-datatables-bs', 'https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js', array( 'tradutema-crm-datatables' ), '1.13.8', true );
        wp_enqueue_script( 'tradutema-crm-datatables-responsive', 'https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js', array( 'tradutema-crm-datatables' ), '2.5.0', true );
        wp_enqueue_script( 'tradutema-crm-datatables-responsive-bs', 'https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js', array( 'tradutema-crm-datatables-responsive' ), '2.5.0', true );

        wp_register_script( 'tradutema-crm-admin', '', array( 'jquery', 'tradutema-crm-bootstrap', 'tradutema-crm-datatables-bs', 'tradutema-crm-datatables-responsive-bs' ), TRADUTEMA_CRM_VERSION, true );
        wp_enqueue_script( 'tradutema-crm-admin' );
        wp_add_inline_script( 'tradutema-crm-admin', $this->get_admin_js() );
    }

    /**
     * Devuelve el CSS interno del panel.
     *
     * @return string
     */
    private function get_admin_css() {
        return <<< 'CSS'
body.tradutema-crm-admin {
    background-color: #f3f5f9;
    min-height: 100vh;
}

body.tradutema-crm-admin #wpbody-content {
    padding: 0;
    margin: 0;
}

body.tradutema-crm-admin #wpbody-content > .notice {
    margin: 24px auto 0;
    max-width: 1280px;
}

body.tradutema-crm-admin .tooltip-inner {
    max-width: min(420px, 80vw);
    text-align: left;
    white-space: pre-wrap;
}

.tradutema-crm-shell {
    --tradutema-crm-sidebar-width: 260px;
    display: flex;
    gap: 24px;
    padding: 5px;
    margin-top: -29px;
    box-sizing: border-box;
    position: relative;
}

.tradutema-crm-sidebar {
    flex: 0 0 var(--tradutema-crm-sidebar-width);
    max-width: var(--tradutema-crm-sidebar-width);
    width: var(--tradutema-crm-sidebar-width);
    background: linear-gradient(180deg, #1f2937 0%, #111827 100%);
    color: #ffffff;
    border-radius: 24px;
    padding: 32px 24px;
    display: flex;
    flex-direction: column;
    gap: 24px;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.25);
    transition: opacity 0.2s ease;
}

.tradutema-crm-shell.is-sidebar-collapsed {
    --tradutema-crm-sidebar-width: 0px;
}

.tradutema-crm-shell.is-sidebar-collapsed .tradutema-crm-sidebar {
    display: none;
}

.tradutema-crm-brand {
    font-size: 1.25rem;
    font-weight: 600;
    line-height: 1.2;
}

.tradutema-crm-user {
    font-size: 0.875rem;
    color: rgba(255, 255, 255, 0.65);
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.tradutema-crm-logout {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
    color: #f9fafb;
    text-decoration: none;
}

.tradutema-crm-logout:hover,
.tradutema-crm-logout:focus {
    color: #ffffff;
    text-decoration: underline;
}

.tradutema-crm-nav .nav-link {
    color: rgba(255, 255, 255, 0.65);
    padding: 0.75rem 1rem;
    border-radius: 16px;
    font-weight: 500;
    transition: all 0.2s ease;
}

.tradutema-crm-nav .nav-link:hover,
.tradutema-crm-nav .nav-link:focus {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.1);
    text-decoration: none;
}

.tradutema-crm-nav .nav-link.active {
    color: #111827;
    background: #ffffff;
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.2);
}

.tradutema-crm-main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.tradutema-crm-header {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.tradutema-crm-header-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.tradutema-crm-header-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1 1 auto;
    min-width: 0;
}

.tradutema-crm-header-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}

.tradutema-crm-header-actions > * {
    flex-shrink: 0;
}

.tradutema-crm-sidebar-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-radius: 999px;
    border: 1px solid rgba(37, 99, 235, 0.35);
    background: #ffffff;
    color: #1d4ed8;
    padding: 0.45rem 1rem;
    font-weight: 500;
    font-size: 0.875rem;
    cursor: pointer;
    transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.12);
    appearance: none;
    -webkit-appearance: none;
}

.tradutema-crm-sidebar-toggle:hover,
.tradutema-crm-sidebar-toggle:focus {
    background: #1d4ed8;
    color: #ffffff;
    border-color: #1d4ed8;
    text-decoration: none;
}

.tradutema-crm-sidebar-toggle:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25);
}

.tradutema-crm-shell.is-sidebar-collapsed .tradutema-crm-sidebar-toggle {
    background: #1d4ed8;
    color: #ffffff;
    border-color: #1d4ed8;
}

.tradutema-crm-sidebar-toggle-icon {
    width: 0;
    height: 0;
    border-top: 6px solid transparent;
    border-bottom: 6px solid transparent;
    border-left: 6px solid currentColor;
    transition: transform 0.2s ease;
}

.tradutema-crm-shell.is-sidebar-collapsed .tradutema-crm-sidebar-toggle-icon {
    transform: rotate(180deg);
}

.tradutema-crm-header h1 {
    font-weight: 600;
    font-size: 2rem;
    color: #111827;
    margin: 0;
}

.tradutema-crm-header p {
    margin: 0;
    color: #4b5563;
    max-width: 720px;
}

.tradutema-crm-card {
    background: #ffffff;
    border-radius: 20px;
    border: none;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.1);
    max-width: 100% !important;
    padding: 0 !important;
}

.tradutema-crm-card .card-header {
    background: transparent;
    border-bottom: 1px solid rgba(15, 23, 42, 0.07);
    padding: 1.5rem 1.75rem 0.75rem;
    font-weight: 600;
    font-size: 1rem;
    color: #111827;
}

.tradutema-crm-card .card-footer {
    background: transparent;
    border-top: 1px solid rgba(15, 23, 42, 0.07);
    padding: 1.25rem 1.75rem;
}

.tradutema-crm-section {
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 1.25rem 1.5rem;
    background: #f9fafb;
    margin-bottom: 1.5rem;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.3), 0 12px 30px rgba(15, 23, 42, 0.06);
}

.tradutema-crm-section-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #0f172a;
}

.tradutema-crm-section-title::before {
    content: '';
    display: inline-block;
    width: 0.75rem;
    height: 0.75rem;
    border-radius: 0.375rem;
    background: currentColor;
    opacity: 0.6;
}

.tradutema-crm-section-woo {
    border-color: #c7d2fe;
    background: #f5f3ff;
    color: #312e81;
}

.tradutema-crm-woo-customer-name {
    font-size: 1.05rem;
}

.tradutema-crm-woo-comment-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 999px;
    border: 1px solid rgba(49, 46, 129, 0.3);
    background: rgba(255, 255, 255, 0.8);
    color: #312e81;
    font-size: 0.85rem;
    line-height: 1;
    cursor: help;
}

.tradutema-crm-section-provider {
    border-color: #bae6fd;
    background: #f0f9ff;
    color: #0f172a;
}

.tradutema-crm-provider-grid {
    --bs-gutter-x: 1rem;
    --bs-gutter-y: 0.85rem;
}

.tradutema-crm-provider-subtitle {
    letter-spacing: 0.02em;
}

.tradutema-crm-provider-subcard {
    background: #e2f3ff;
    padding: 0.9rem 1rem;
}

.tradutema-crm-provider-grid .form-label {
    margin-bottom: 0.35rem;
}

.tradutema-crm-provider-notes textarea {
    min-height: 120px;
}

.tradutema-crm-address-toggle {
    font-weight: 600;
}

.tradutema-crm-addresses {
    margin-top: 0.25rem;
}

.tradutema-crm-section-email {
    border-color: #bbf7d0;
    background: #ecfdf3;
    color: #065f46;
}

.tradutema-crm-section-history {
    border-color: #fcd34d;
    background: #fffbeb;
    color: #92400e;
}

.tradutema-crm-filters .form-label {
    font-weight: 500;
    color: #374151;
}

.tradutema-crm-filter-group {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    padding: 1rem 1.25rem;
    height: 100%;
}

.tradutema-crm-filter-group__title {
    font-size: 0.95rem;
    font-weight: 600;
    color: #111827;
    margin: 0 0 0.75rem;
}

.tradutema-crm-filter-group--statuses {
    background: #eef2ff;
    border-color: #c7d2fe;
}

.tradutema-crm-filter-group--dates {
    background: #fef9c3;
    border-color: #fde68a;
}

.tradutema-crm-filter-group--providers {
    background: #ecfdf3;
    border-color: #bbf7d0;
}

.tradutema-crm-filters .btn {
    padding-inline: 1.75rem;
}

.tradutema-crm-placeholder-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #eef2ff;
    color: #3730a3;
    border-radius: 9999px;
    padding: 0.25rem 0.75rem;
    font-size: 0.75rem;
    margin: 0 0.5rem 0.5rem 0;
}

.tradutema-crm-actions .btn {
    margin-right: 0.5rem;
}

.tradutema-crm-actions .btn:last-child {
    margin-right: 0;
}

.tradutema-crm-action-btn {
    width: 2.25rem;
    height: 2.25rem;
    padding: 0.35rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

.tradutema-crm-action-icon svg {
    width: 1.25rem;
    height: 1.25rem;
    display: block;
}

.tradutema-crm-table-wrapper {
    overflow-x: visible;
}

.table.tradutema-datatable {
    margin-bottom: 0 !important;
    width: 100%;
}

.table.tradutema-datatable thead th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6b7280;
    border-bottom-width: 1px;
}

.table.tradutema-datatable thead th:first-child,
.table.tradutema-datatable tbody td:first-child {
    min-width: 5.5rem;
}

.table.tradutema-datatable tbody td {
    vertical-align: middle;
    color: #111827;
    word-break: break-word;
    white-space: normal;
}

.tradutema-crm-table-truncate {
    display: inline-block;
    max-width: min(320px, 45vw);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: top;
    cursor: help;
}

.tradutema-crm-status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1.25;
    background: #e5e5e5;
    color: #454545;
    white-space: nowrap;
}

.dataTables_wrapper .dataTables_filter input {
    margin-left: 0.5rem;
    border-radius: 999px;
    padding: 0.25rem 0.75rem;
}

.dataTables_wrapper .dataTables_length select {
    border-radius: 999px;
    padding: 0.25rem 2rem 0.25rem 0.75rem;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
    border-radius: 999px !important;
    padding: 0.25rem 0.75rem !important;
    margin: 0 0.25rem !important;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background: #2563eb !important;
    color: #ffffff !important;
    border: none !important;
}

.tradutema-crm-main form .form-control,
.tradutema-crm-main form .form-select {
    border-radius: 12px;
    border-color: rgba(15, 23, 42, 0.1);
    box-shadow: none;
}

.tradutema-crm-main form .form-control:focus,
.tradutema-crm-main form .form-select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

.tradutema-crm-main .btn {
    border-radius: 12px;
}

.tradutema-crm-main .btn-primary {
    background: linear-gradient(90deg, #2563eb 0%, #1d4ed8 100%);
    border: none;
}

.tradutema-crm-main .btn-outline-primary {
    color: #1d4ed8;
    border-color: #1d4ed8;
}

.tradutema-crm-main .btn-outline-primary:hover,
.tradutema-crm-main .btn-outline-primary:focus {
    background: #1d4ed8;
    color: #ffffff;
}

@media (max-width: 960px) {
    .tradutema-crm-shell {
        flex-direction: column;
    }

    .tradutema-crm-shell:not(.is-sidebar-collapsed) {
        --tradutema-crm-sidebar-width: 100%;
    }

    .tradutema-crm-sidebar {
        max-width: 100%;
        width: 100%;
    }

    .tradutema-crm-header-bar {
        align-items: flex-start;
    }

    .tradutema-crm-header-actions {
        justify-content: flex-start;
    }

    .tradutema-crm-nav .nav-link {
        border-radius: 12px;
    }
}
CSS;
    }

    /**
     * Devuelve el JavaScript interno del panel.
     *
     * @return string
     */
    private function get_admin_js() {
        return <<< 'JS'
(function ($) {
    'use strict';

    const storage = (() => {
        try {
            if (typeof window.localStorage === 'undefined') {
                return { get: () => null, set: () => {} };
            }

            return {
                get: (key) => window.localStorage.getItem(key),
                set: (key, value) => window.localStorage.setItem(key, value),
            };
        } catch (error) {
            return {
                get: () => null,
                set: () => {},
            };
        }
    })();

    const language = {
        search: 'Buscar:',
        emptyTable: 'No hay datos disponibles en la tabla.',
        info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
        infoEmpty: 'Sin resultados disponibles',
        infoFiltered: '(filtrado de _MAX_ registros totales)',
        lengthMenu: 'Mostrar _MENU_ registros',
        zeroRecords: 'No se encontraron resultados',
        paginate: {
            first: 'Primero',
            last: 'Último',
            next: 'Siguiente',
            previous: 'Anterior',
        },
    };

    const escapeRegex = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    const initTooltips = (context) => {
        if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) {
            return;
        }

        const $context = context ? $(context) : $(document);
        $context.find('[data-bs-toggle="tooltip"]').each((_, element) => {
            const instance = bootstrap.Tooltip.getInstance(element);
            if (instance) {
                instance.dispose();
            }
            new bootstrap.Tooltip(element);
        });
    };

    const initDataTable = (table) => {
        if (!$.fn.DataTable || $.fn.DataTable.isDataTable(table)) {
            return null;
        }

        const $table = $(table);
        let order = [];

        const orderAttr = $table.attr('data-order');
        if (orderAttr) {
            try {
                order = JSON.parse(orderAttr);
            } catch (error) {
                order = [];
            }
        }

        const pageLength = parseInt($table.attr('data-page-length') || '10', 10);

        const tableLanguage = Object.assign({}, language);
        const emptyMessage = $table.data('emptyMessage');

        if (emptyMessage) {
            tableLanguage.emptyTable = emptyMessage;
        }

        const dataTable = $table.DataTable({
            order,
            pageLength,
            language: tableLanguage,
            autoWidth: false,
            scrollX: true,
        });

        initTooltips($table);
        $table.on('draw.dt', () => initTooltips($table));

        return dataTable;
    };

    const bindOrderDateFilters = () => {
        const $table = $('.tradutema-datatable').first();

        if (!$table.length || !$.fn.DataTable || !$.fn.DataTable.isDataTable($table[0])) {
            return;
        }

        const dataTable = $table.DataTable();
        const filters = [
            { selector: '#filter_fecha_solicitud', columnIndex: 10 },
            { selector: '#filter_fecha_real', columnIndex: 11 },
        ];

        filters.forEach(({ selector, columnIndex }) => {
            const $input = $(selector);

            if (!$input.length) {
                return;
            }

            const applyFilter = () => {
                const rawValue = ($input.val() || '').toString().trim();
                const searchValue = rawValue ? rawValue.replace(/\s+/g, ' ') : '';

                dataTable.column(columnIndex).search(searchValue, false, false, true).draw();
            };

            $input.on('input change', applyFilter);
            applyFilter();
        });
    };

    const bindSidebarToggle = () => {
        const $shell = $('.tradutema-crm-shell');
        const $toggles = $('.tradutema-crm-sidebar-toggle');

        if (!$shell.length || !$toggles.length) {
            return;
        }

        const storageKey = 'tradutemaCrmSidebarCollapsed';

        const updateToggle = (toggle, collapsed) => {
            const $toggle = $(toggle);
            const dataset = toggle.dataset || {};
            const label = collapsed ? dataset.labelCollapsed || '' : dataset.labelExpanded || '';
            const $label = $toggle.find('.tradutema-crm-sidebar-toggle-label');

            if (label) {
                if ($label.length) {
                    $label.text(label);
                } else {
                    $toggle.text(label);
                }
                $toggle.attr('aria-label', label);
            }

            $toggle.attr('aria-expanded', (!collapsed).toString());
        };

        const applyState = (collapsed) => {
            $shell.toggleClass('is-sidebar-collapsed', collapsed);
            $toggles.each((_, toggle) => updateToggle(toggle, collapsed));
        };

        const stored = storage.get(storageKey);
        if (stored !== null) {
            applyState(stored === '1');
        } else {
            applyState($shell.hasClass('is-sidebar-collapsed'));
        }

        $toggles.on('click', (event) => {
            event.preventDefault();
            const collapsed = !$shell.hasClass('is-sidebar-collapsed');
            applyState(collapsed);
            storage.set(storageKey, collapsed ? '1' : '0');
        });
    };

    const initTranslationPairs = () => {
        const languages = [
            'Alemán',
            'Árabe',
            'Búlgaro',
            'Catalán',
            'Checo',
            'Chino',
            'Eslovaco',
            'Esloveno',
            'Español',
            'Euskera',
            'Finés',
            'Francés',
            'Gallego',
            'Georgiano',
            'Griego',
            'Hebreo',
            'Hindi',
            'Holandés',
            'Húngaro',
            'Inglés',
            'Italiano',
            'Lituano',
            'Neerlandés',
            'Noruego',
            'Polaco',
            'Portugués',
            'Rumano',
            'Ruso',
            'Serbio',
            'Sueco',
            'Tagalo',
            'Turco',
            'Ucraniano',
            'Vasco',
        ];

        const populateSourceSelect = ($select, selected = '', allowCustom = false) => {
            const placeholder = $select.data('placeholder') || '';

            $select.empty();

            if (placeholder) {
                $select.append(new Option(placeholder, ''));
            }

            languages.forEach((language) => {
                const option = new Option(language, language, false, language === selected);
                $select.append(option);
            });

            if (allowCustom && selected && !$select.val()) {
                $select.append(new Option(selected, selected, true, true));
                $select.val(selected);
            }
        };

        const populateTargetSelect = ($select, source = '', selected = '', allowCustom = false) => {
            const placeholder = $select.data('placeholder') || '';

            $select.empty();

            if (placeholder) {
                $select.append(new Option(placeholder, ''));
            }

            languages.forEach((language) => {
                const option = new Option(language, language, false, language === selected);
                $select.append(option);
            });

            if (allowCustom && selected && !$select.val()) {
                $select.append(new Option(selected, selected, true, true));
                $select.val(selected);
            }
        };

        const $containers = $('[data-translation-pairs]');

        if (!$containers.length) {
            return;
        }

        $containers.each((_, element) => {
            const $container = $(element);
            const $list = $container.find('[data-translation-pairs-list]');
            const $hidden = $container.find('input[type="hidden"]').first();
            const templateHtml = $container.find('[data-translation-pairs-template]').html();
            const $form = $container.closest('form');

            if (!$list.length || !$hidden.length || !templateHtml) {
                return;
            }

            const parsePairs = () => {
                const raw = $hidden.val();

                if (!raw) {
                    return [];
                }

                try {
                    const parsed = JSON.parse(raw);

                    if (Array.isArray(parsed)) {
                        return parsed
                            .filter((item) => item && (item.source || item.target))
                            .map((item) => ({
                                source: (item.source || '').toString(),
                                target: (item.target || '').toString(),
                            }));
                    }
                } catch (error) {
                    return [];
                }

                return [];
            };

            const syncPairs = () => {
                const pairs = [];

                $list.find('[data-translation-pair-row]').each((_, row) => {
                    const $row = $(row);
                    const source = ($row.find('[data-translation-pair-source]').val() || '').trim();
                    const target = ($row.find('[data-translation-pair-target]').val() || '').trim();

                    if (source || target) {
                        pairs.push({ source, target });
                    }
                });

                $hidden.val(JSON.stringify(pairs));
            };

            const addRow = (pair = { source: '', target: '' }) => {
                const $row = $(templateHtml.trim());
                const $source = $row.find('[data-translation-pair-source]');
                const $target = $row.find('[data-translation-pair-target]');

                populateSourceSelect($source, pair.source || '', true);
                populateTargetSelect($target, pair.source || '', pair.target || '', true);
                $list.append($row);
            };

            const ensureRow = () => {
                if (!$list.children().length) {
                    addRow();
                }
            };

            $container.on('click', '[data-add-translation-pair]', (event) => {
                event.preventDefault();
                addRow();
                syncPairs();
            });

            $container.on('click', '[data-remove-translation-pair]', (event) => {
                event.preventDefault();
                const $row = $(event.currentTarget).closest('[data-translation-pair-row]');
                $row.remove();
                ensureRow();
                syncPairs();
            });

            $container.on('change', '[data-translation-pair-source]', (event) => {
                const $source = $(event.currentTarget);
                const $row = $source.closest('[data-translation-pair-row]');
                const $target = $row.find('[data-translation-pair-target]');
                const previousValue = $target.val();

                populateTargetSelect($target, $source.val(), previousValue, false);
                syncPairs();
            });

            $container.on('change', '[data-translation-pair-target]', syncPairs);
            $container.on('input', '[data-translation-pair-target]', syncPairs);

            if ($form.length) {
                $form.on('submit', syncPairs);
            }

            const initialPairs = parsePairs();

            if (initialPairs.length) {
                initialPairs.forEach((pair) => addRow(pair));
            }

            ensureRow();
            syncPairs();
        });
    };

    const syncOrderFormWithTemplateForms = () => {
        const $orderForm = $('#tradutema-crm-order-form');
        const $templateForms = $('.tradutema-crm-email-template-form');

        if (!$orderForm.length || !$templateForms.length) {
            return;
        }

        const fields = [
            'estado_operacional',
            'proveedor_id',
            'fecha_real_entrega_pdf',
            'referencia',
            'comentario_interno',
            'comentario_linguistico',
            'fecha_prevista_entrega',
            'hora_prevista_entrega',
            'envio_papel',
        ];

        $templateForms.on('submit', function () {
            const $currentForm = $(this);

            fields.forEach((field) => {
                const $target = $currentForm.find(`[name="${field}"]`);
                const $source = $orderForm.find(`[name="${field}"]`);

                if (!$target.length) {
                    return;
                }

                if (!$source.length) {
                    $target.val('');
                    return;
                }

                if ($source.attr('type') === 'checkbox') {
                    $target.val($source.is(':checked') ? '1' : '0');
                    return;
                }

                $target.val($source.val());
            });
        });
    };

    const bindAddressToggles = () => {
        if (typeof bootstrap === 'undefined' || !bootstrap.Collapse) {
            return;
        }

        const $toggles = $('.tradutema-crm-address-toggle');

        if (!$toggles.length) {
            return;
        }

        $toggles.each((_, toggle) => {
            const targetSelector = toggle.getAttribute('data-bs-target') || toggle.getAttribute('href');

            if (!targetSelector) {
                return;
            }

            const target = document.querySelector(targetSelector);

            if (!target) {
                return;
            }

            const collapse = new bootstrap.Collapse(target, { toggle: false });
            const expandedLabel = toggle.getAttribute('data-label-expanded') || toggle.textContent || '';
            const collapsedLabel = toggle.getAttribute('data-label-collapsed') || toggle.textContent || '';

            const syncToggle = (isExpanded) => {
                toggle.setAttribute('aria-expanded', isExpanded.toString());
                toggle.classList.toggle('collapsed', !isExpanded);
                const label = isExpanded ? expandedLabel : collapsedLabel;

                if (label) {
                    toggle.textContent = label;
                }
            };

            toggle.addEventListener('click', (event) => {
                event.preventDefault();
                const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

                if (isExpanded) {
                    collapse.hide();
                } else {
                    collapse.show();
                }
            });

            target.addEventListener('shown.bs.collapse', () => syncToggle(true));
            target.addEventListener('hidden.bs.collapse', () => syncToggle(false));

            collapse.hide();
            syncToggle(false);
        });
    };

    const boot = () => {
        $('.tradutema-datatable').each((_, table) => initDataTable(table));

        bindOrderDateFilters();
        bindSidebarToggle();
        initTooltips(document);
        initTranslationPairs();
        syncOrderFormWithTemplateForms();
        bindAddressToggles();

        const $notices = $('#wpbody-content > .notice');
        const $main = $('.tradutema-crm-main');

        if ($notices.length && $main.length) {
            $main.prepend($notices);
        }
    };

    $(document).ready(boot);
})(jQuery);
JS;
    }

    /**
     * Añade una clase personalizada al body del administrador.
     *
     * @param string $classes Clases actuales.
     * @return string
     */
    public function add_body_class( $classes ) {
        if ( $this->is_crm_screen() ) {
            $classes .= ' tradutema-crm-admin';
        }

        return $classes;
    }

    /**
     * Comprueba si la pantalla actual pertenece al CRM.
     *
     * @param \WP_Screen|null $screen Pantalla actual.
     * @return bool
     */
    private function is_crm_screen( $screen = null ) {
        if ( null === $screen && function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
        }

        if ( ! $screen ) {
            return false;
        }

        return false !== strpos( $screen->id, 'tradutema-crm' );
    }

    /**
     * Renderiza el dashboard del CRM.
     */
    public function render_dashboard() {
        $this->ensure_capability();

        if ( ! empty( $_GET['order_id'] ) ) {
            $manage_url = add_query_arg(
                array(
                    'page'     => self::MANAGE_ORDER_PAGE,
                    'order_id' => absint( $_GET['order_id'] ),
                ),
                admin_url( 'admin.php' )
            );

            wp_safe_redirect( $manage_url );
            exit;
        }

        $filters          = $this->get_order_filters();
        $orders           = $this->get_orders( $filters );
        $order_statuses   = wc_get_order_statuses();
        $estado_operacion = tradutema_crm_operational_statuses();
        $proveedores      = $this->get_proveedores();

        $filter_fecha_solicitud = $filters['filter_fecha_solicitud'];
        $filter_fecha_real      = $filters['filter_fecha_real'];

        $current_user  = wp_get_current_user();
        $current_page  = tradutema_crm_current_admin_page();
        $navigation    = tradutema_crm_admin_nav_items();
        $brand_message = sprintf(
            /* translators: %s: user display name */
            esc_html__( 'Sesión iniciada como %s', 'tradutema-crm' ),
            esc_html( $current_user->display_name )
        );
        $logout_url   = wp_logout_url( admin_url() );

        ?>
        <div class="tradutema-crm-shell is-sidebar-collapsed">
            <aside id="tradutema-crm-sidebar" class="tradutema-crm-sidebar">
                <div>
                    <div class="tradutema-crm-brand"><?php esc_html_e( 'Tradutema CRM', 'tradutema-crm' ); ?></div>
                    <p class="tradutema-crm-user">
                        <?php echo esc_html( $brand_message ); ?>
                        <a class="tradutema-crm-logout" href="<?php echo esc_url( $logout_url ); ?>">
                            <?php esc_html_e( 'Cerrar sesión', 'tradutema-crm' ); ?>
                        </a>
                    </p>
                </div>
                <nav class="tradutema-crm-nav nav flex-column" aria-label="<?php esc_attr_e( 'Secciones del CRM', 'tradutema-crm' ); ?>">
                    <?php foreach ( $navigation as $item ) :
                        $is_active = $current_page === $item['page'];
                        ?>
                        <a class="nav-link<?php echo $is_active ? ' active' : ''; ?>" <?php echo $is_active ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item['page'] ) ); ?>">
                            <?php echo esc_html( $item['label'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </aside>
            <main class="tradutema-crm-main">
                <div class="tradutema-crm-header">
                    <div class="tradutema-crm-header-bar">
                        <div class="tradutema-crm-header-group">
                            <h1><?php esc_html_e( 'Gestión de pedidos. Listando último mes filtra por Desde-Hasta si quieres listar otras fechas.', 'tradutema-crm' ); ?></h1>
                        </div>
                        <div class="tradutema-crm-header-actions">
                            <button type="button" class="tradutema-crm-sidebar-toggle" aria-controls="tradutema-crm-sidebar" aria-expanded="false" aria-label="<?php echo esc_attr__( 'Mostrar menú', 'tradutema-crm' ); ?>" data-label-expanded="<?php echo esc_attr__( 'Ocultar menú', 'tradutema-crm' ); ?>" data-label-collapsed="<?php echo esc_attr__( 'Mostrar menú', 'tradutema-crm' ); ?>">
                                <span class="tradutema-crm-sidebar-toggle-icon" aria-hidden="true"></span>
                                <span class="tradutema-crm-sidebar-toggle-label"><?php esc_html_e( 'Mostrar menú', 'tradutema-crm' ); ?></span>
                            </button>
                        </div>
                    </div>
                </div>

                <form method="get" class="row g-3 align-items-end tradutema-crm-filters">
                    <input type="hidden" name="page" value="tradutema-crm" />

                    <div class="col-12 col-xl-4 col-xxl-3">
                        <div class="tradutema-crm-filter-group tradutema-crm-filter-group--statuses">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label for="payment_status" class="form-label"><?php esc_html_e( 'Estado de pago', 'tradutema-crm' ); ?></label>
                                    <select name="payment_status" id="payment_status" class="form-select">
                                        <option value=""><?php esc_html_e( 'Todos los estados de pago', 'tradutema-crm' ); ?></option>
                                        <?php foreach ( $order_statuses as $status_key => $status_label ) : ?>
                                            <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $filters['payment_status'], $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="estado_operacional" class="form-label"><?php esc_html_e( 'Estado operacional', 'tradutema-crm' ); ?></label>
                                    <select name="estado_operacional" id="estado_operacional" class="form-select">
                                        <option value=""><?php esc_html_e( 'Todos los estados operacionales', 'tradutema-crm' ); ?></option>
                                        <?php foreach ( $estado_operacion as $estado_key => $estado_label ) : ?>
                                            <option value="<?php echo esc_attr( $estado_key ); ?>" <?php selected( $filters['estado_operacional'], $estado_key ); ?>><?php echo esc_html( $estado_label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3 col-xxl-3">
                        <div class="tradutema-crm-filter-group tradutema-crm-filter-group--dates">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label for="date_start" class="form-label"><?php esc_html_e( 'Desde', 'tradutema-crm' ); ?></label>
                                    <input type="date" name="date_start" id="date_start" value="<?php echo esc_attr( $filters['date_start'] ); ?>" class="form-control" />
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="date_end" class="form-label"><?php esc_html_e( 'Hasta', 'tradutema-crm' ); ?></label>
                                    <input type="date" name="date_end" id="date_end" value="<?php echo esc_attr( $filters['date_end'] ); ?>" class="form-control" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-2 col-xxl-3">
                        <div class="tradutema-crm-filter-group tradutema-crm-filter-group--providers">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="proveedor_id" class="form-label"><?php esc_html_e( 'Proveedor', 'tradutema-crm' ); ?></label>
                                    <select name="proveedor_id" id="proveedor_id" class="form-select">
                                        <option value=""><?php esc_html_e( 'Todos los proveedores', 'tradutema-crm' ); ?></option>
                                        <option value="none" <?php selected( $filters['proveedor_id'], 'none' ); ?>><?php esc_html_e( 'Sin proveedor asignado', 'tradutema-crm' ); ?></option>
                                        <?php foreach ( $proveedores as $proveedor ) : ?>
                                            <option value="<?php echo esc_attr( $proveedor['id'] ); ?>" <?php selected( (string) $filters['proveedor_id'], (string) $proveedor['id'] ); ?>><?php echo esc_html( $proveedor['nombre_comercial'] ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-2 col-xxl-2">
                        <div class="tradutema-crm-filter-group tradutema-crm-filter-group--delivery-dates">
                            <div class="row g-3">
                                <div class="col-6">
                                    <label for="filter_fecha_solicitud" class="form-label"><?php esc_html_e( 'F. sol.', 'tradutema-crm' ); ?></label>
                                    <input type="text" id="filter_fecha_solicitud" name="filter_fecha_solicitud" value="<?php echo esc_attr( $filter_fecha_solicitud ); ?>" class="form-control" placeholder="dd/mm" aria-label="<?php echo esc_attr__( 'Filtrar por fecha solicitada', 'tradutema-crm' ); ?>" />
                                </div>
                                <div class="col-6">
                                    <label for="filter_fecha_real" class="form-label"><?php esc_html_e( 'F. real', 'tradutema-crm' ); ?></label>
                                    <input type="text" id="filter_fecha_real" name="filter_fecha_real" value="<?php echo esc_attr( $filter_fecha_real ); ?>" class="form-control" placeholder="dd/mm" aria-label="<?php echo esc_attr__( 'Filtrar por fecha real', 'tradutema-crm' ); ?>" />
                                </div>
                            </div>
                        </div>
                    </div>
<!--
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label for="per_page" class="form-label"><?php esc_html_e( 'Resultados por página', 'tradutema-crm' ); ?></label>
                        <input type="number" min="1" max="200" name="per_page" id="per_page" value="<?php echo esc_attr( $filters['per_page'] ); ?>" class="form-control" placeholder="<?php esc_attr_e( 'Todos', 'tradutema-crm' ); ?>" />
                        <div class="form-text"><?php esc_html_e( 'Déjalo vacío para mostrar todos los pedidos.', 'tradutema-crm' ); ?></div>
                    </div>
-->
                    <div class="col-12 col-xl-auto d-flex gap-2 ms-xl-auto">
                        <button type="submit" class="btn btn-primary"><?php esc_html_e( 'Aplicar filtros', 'tradutema-crm' ); ?></button>
                        <a class="btn btn-outline-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=tradutema-crm' ) ); ?>"><?php esc_html_e( 'Resetear', 'tradutema-crm' ); ?></a>
                    </div>
                </form>

                <div class="card tradutema-crm-card">
                    <div class="card-header">
                    </div>
                    <div class="card-body">
                        <div class="tradutema-crm-table-wrapper">
                            <table class="table table-striped table-hover align-middle tradutema-datatable" data-order='[[0,"desc"]]' data-page-length="20" data-empty-message="<?php echo esc_attr__( 'No se encontraron pedidos con los criterios seleccionados.', 'tradutema-crm' ); ?>">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'ID', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Fecha', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Com. Cli', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Cliente', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Com. Trad', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Pago', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( '¿Pagado?', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'PPL', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Origen', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Proveedor', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'F. sol.', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'F. real', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Estado', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Idiomas', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Pág.', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Precio', 'tradutema-crm' ); ?></th>
                                        <th><?php esc_html_e( 'Teléfono', 'tradutema-crm' ); ?></th>
                                        <th class="text-end"><?php esc_html_e( 'Acciones', 'tradutema-crm' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ( ! empty( $orders ) ) : ?>
                                        <?php foreach ( $orders as $order ) :
                                            $customer_note           = trim( wp_strip_all_tags( isset( $order['customer_note'] ) ? $order['customer_note'] : '' ) );
                                            $customer_note_preview    = '' !== $customer_note ? \wp_html_excerpt( $customer_note, 6, '...' ) : '';
                                            $tradutema_comment        = trim( wp_strip_all_tags( isset( $order['comentario_tradutema'] ) ? $order['comentario_tradutema'] : '' ) );
                                            $tradutema_comment_preview = '' !== $tradutema_comment ? \wp_html_excerpt( $tradutema_comment, 6, '...' ) : '';
                                            $language_pair_label = $this->format_language_pair( $order['idioma_origen'], $order['idioma_destino'] );
                                            $payment_method           = trim( wp_strip_all_tags( isset( $order['payment_method'] ) ? $order['payment_method'] : '' ) );
                                            $payment_method_preview   = '' !== $payment_method ? \wp_html_excerpt( $payment_method, 4, '...' ) : '';
                                            $billing_phone            = trim( (string) tradutema_array_get( $order, 'phone', '' ) );
                                            ?>
                                            <tr>
                                                <?php
                                                $manage_order_url = add_query_arg(
                                                    array(
                                                        'page'     => self::MANAGE_ORDER_PAGE,
                                                        'order_id' => $order['id'],
                                                    ),
                                                    admin_url( 'admin.php' )
                                                );
                                                ?>
                                                <td>
                                                    <a href="<?php echo esc_url( $manage_order_url ); ?>" target="_blank" rel="noopener noreferrer">
                                                        <?php echo esc_html( $order['woo_id'] ); ?>
                                                    </a>
                                                </td>
                                                <td data-order="<?php echo esc_attr( $order['date_order'] ); ?>">
                                                    <?php
                                                    $date_parts = tradutema_array_get(
                                                        $order,
                                                        'date_lines',
                                                        array(
                                                            'date' => '',
                                                            'time' => '',
                                                        )
                                                    );

                                                    if ( '' !== $date_parts['date'] ) :
                                                        ?>
                                                        <div class="tradutema-crm-date-lines">
                                                            <div><?php echo esc_html( $date_parts['date'] ); ?></div>
                                                            <?php if ( '' !== $date_parts['time'] ) : ?>
                                                                <div><?php echo esc_html( $date_parts['time'] ); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else : ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ( '' !== $customer_note ) : ?>
                                                        <span class="tradutema-crm-table-truncate" data-bs-toggle="tooltip" data-bs-placement="auto" title="<?php echo esc_attr( $customer_note ); ?>">
                                                            <?php echo esc_html( $customer_note_preview ); ?>
                                                        </span>
                                                    <?php else : ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $customer_name = $order['customer'] ? $order['customer'] : __( 'Cliente no especificado', 'tradutema-crm' );

                                                    if ( ! empty( $order['customer_user_id'] ) ) {
                                                        $customer_orders_url = add_query_arg(
                                                            array(
                                                                'post_status'    => 'all',
                                                                'post_type'      => 'shop_order',
                                                                '_customer_user' => absint( $order['customer_user_id'] ),
                                                            ),
                                                            admin_url( 'edit.php' )
                                                        );

                                                        echo '<a href="' . esc_url( $customer_orders_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $customer_name ) . '</a>';
                                                    } else {
                                                        echo esc_html( $customer_name );
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ( '' !== $tradutema_comment ) : ?>
                                                        <span class="tradutema-crm-table-truncate" data-bs-toggle="tooltip" data-bs-placement="auto" title="<?php echo esc_attr( $tradutema_comment ); ?>">
                                                            <?php echo esc_html( $tradutema_comment_preview ); ?>
                                                        </span>
                                                    <?php else : ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ( '' !== $payment_method ) : ?>
                                                        <span class="tradutema-crm-table-truncate" data-bs-toggle="tooltip" data-bs-placement="auto" title="<?php echo esc_attr( $payment_method ); ?>">
                                                            <?php echo esc_html( $payment_method_preview ); ?>
                                                        </span>
                                                    <?php else : ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_badge_style = $this->get_payment_status_badge_style( $order['status_key'] );
                                                    ?>
                                                    <span class="tradutema-crm-status-badge"<?php echo '' !== $status_badge_style ? ' style="' . esc_attr( $status_badge_style ) . '"' : ''; ?>>
                                                        <?php echo esc_html( $order['status'] ); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo esc_html( $order['paper_label'] ); ?></td>
                                                <td>
                                                    <?php
                                                    $order_origin = $order['origen_pedido'];

                                                    if ( 'ADI' === $order_origin ) {
                                                        $reference_id = absint( $order['referencia'] );

                                                        if ( $reference_id > 0 ) {
                                                            $adi_label = sprintf( '%s %s', $order_origin, $order['referencia'] );
                                                            $adi_url   = add_query_arg(
                                                                array(
                                                                    'page'     => self::MANAGE_ORDER_PAGE,
                                                                    'order_id' => $reference_id,
                                                                ),
                                                                admin_url( 'admin.php' )
                                                            );

                                                            echo '<a href="' . esc_url( $adi_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $adi_label ) . '</a>';
                                                        } else {
                                                            echo esc_html( $order_origin );
                                                        }
                                                    } else {
                                                        echo esc_html( $order_origin );
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $proveedor_label   = $order['proveedor'];
                                                    $proveedor_preview = '' !== $proveedor_label ? \wp_html_excerpt( $proveedor_label, 9, '...' ) : '';

                                                    if ( ! empty( $proveedor_label ) && ! empty( $order['proveedor_id'] ) ) {
                                                        $proveedor_url = add_query_arg(
                                                            array(
                                                                'page'         => 'tradutema-crm-proveedores',
                                                                'proveedor_id' => $order['proveedor_id'],
                                                            ),
                                                            admin_url( 'admin.php' )
                                                        );

                                                        printf(
                                                            '<a href="%1$s" target="_blank" rel="noopener noreferrer" class="tradutema-crm-table-truncate" data-bs-toggle="tooltip" data-bs-placement="auto" title="%3$s">%2$s</a>',
                                                            esc_url( $proveedor_url ),
                                                            esc_html( '' !== $proveedor_preview ? $proveedor_preview : $proveedor_label ),
                                                            esc_attr( $proveedor_label )
                                                        );
                                                    } else {
                                                        $proveedor_fallback = '' !== $proveedor_label ? $proveedor_label : __( 'Sin asignar', 'tradutema-crm' );
                                                        $proveedor_display  = '' !== $proveedor_preview ? $proveedor_preview : $proveedor_fallback;

                                                        printf(
                                                            '<span class="tradutema-crm-table-truncate" data-bs-toggle="tooltip" data-bs-placement="auto" title="%2$s">%1$s</span>',
                                                            esc_html( $proveedor_display ),
                                                            esc_attr( $proveedor_fallback )
                                                        );
                                                    }
                                                    ?>
                                                </td>
                                                <td data-order="<?php echo esc_attr( $order['fecha_solicitud_order'] ); ?>">
                                                    <?php
                                                    $requested_date_parts = tradutema_array_get(
                                                        $order,
                                                        'fecha_solicitud_lines',
                                                        array(
                                                            'date' => '',
                                                            'time' => '',
                                                        )
                                                    );

                                                    if ( '' !== $requested_date_parts['date'] ) :
                                                        ?>
                                                        <div class="tradutema-crm-date-lines">
                                                            <div><?php echo esc_html( $requested_date_parts['date'] ); ?></div>
                                                            <?php if ( '' !== $requested_date_parts['time'] ) : ?>
                                                                <div><?php echo esc_html( $requested_date_parts['time'] ); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else : ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-order="<?php echo esc_attr( $order['fecha_real_order'] ); ?>">
                                                    <?php
                                                    $real_date_parts = tradutema_array_get(
                                                        $order,
                                                        'fecha_real_lines',
                                                        array(
                                                            'date' => '',
                                                            'time' => '',
                                                        )
                                                    );

                                                    if ( '' !== $real_date_parts['date'] ) :
                                                        ?>
                                                        <div class="tradutema-crm-date-lines">
                                                            <div><?php echo esc_html( $real_date_parts['date'] ); ?></div>
                                                            <?php if ( '' !== $real_date_parts['time'] ) : ?>
                                                                <div><?php echo esc_html( $real_date_parts['time'] ); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else : ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php
                                                $operational_status_key   = isset( $order['estado_operacional_key'] ) ? $order['estado_operacional_key'] : '';
                                                $operational_status_label = tradutema_array_get( $estado_operacion, $operational_status_key, $operational_status_key );
                                                $operational_status_preview = '' !== $operational_status_label ? \wp_html_excerpt( $operational_status_label, 14, '...' ) : '';
                                                $operational_status_style = $this->get_operational_status_badge_style( $operational_status_key );
                                                ?>
                                                <td>
                                                    <?php if ( '' !== $operational_status_label ) : ?>
                                                        <span class="tradutema-crm-status-badge tradutema-crm-table-truncate"<?php echo '' !== $operational_status_style ? ' style="' . esc_attr( $operational_status_style ) . '"' : ''; ?> data-bs-toggle="tooltip" data-bs-placement="auto" title="<?php echo esc_attr( $operational_status_label ); ?>">
                                                            <?php echo esc_html( $operational_status_preview ); ?>
                                                        </span>
                                                    <?php else : ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ( '' !== $language_pair_label ) : ?>
                                                        <?php echo esc_html( $language_pair_label ); ?>
                                                    <?php else : ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo esc_html( $order['paginas'] ); ?></td>
                                                <td class="text-end">
                                                    <?php echo wp_kses_post( wc_price( $order['total'], array( 'currency' => $order['currency'] ) ) ); ?>
                                                </td>
                                                <td>
                                                    <?php if ( '' !== $billing_phone ) :
                                                        $whatsapp_number = preg_replace( '/[^0-9]/', '', $billing_phone );
                                                        $whatsapp_url    = $whatsapp_number ? 'https://wa.me/' . rawurlencode( $whatsapp_number ) : '';
                                                        ?>
                                                        <?php if ( '' !== $whatsapp_url ) : ?>
                                                            <a href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" rel="noopener noreferrer" class="text-decoration-none" aria-label="<?php esc_attr_e( 'Abrir chat de WhatsApp', 'tradutema-crm' ); ?>">
                                                                <?php echo esc_html( $billing_phone ); ?>
                                                            </a>
                                                        <?php else : ?>
                                                            <?php echo esc_html( $billing_phone ); ?>
                                                        <?php endif; ?>
                                                    <?php else : ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-2">
                                                        <?php if ( $order['edit_url'] ) : ?>
                                                            <a class="btn btn-sm btn-outline-secondary tradutema-crm-action-btn" href="<?php echo esc_url( $order['edit_url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Abrir en WooCommerce', 'tradutema-crm' ); ?>" title="<?php esc_attr_e( 'Abrir en WooCommerce', 'tradutema-crm' ); ?>">
                                                            <span class="tradutema-crm-action-icon" aria-hidden="true">W</span>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ( ! empty( $order['gdrive_url'] ) ) : ?>
                                                            <a class="btn btn-sm btn-outline-success tradutema-crm-action-btn" href="<?php echo esc_url( $order['gdrive_url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Abrir en Google Drive', 'tradutema-crm' ); ?>" title="<?php esc_attr_e( 'Abrir en Google Drive', 'tradutema-crm' ); ?>">
                                                            <span class="tradutema-crm-action-icon" aria-hidden="true">D</span>
                                                            </a>
                                                        <?php endif; ?>
                                                        <a class="btn btn-sm btn-primary tradutema-crm-action-btn" href="<?php echo esc_url( $manage_order_url ); ?>" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Gestionar pedido', 'tradutema-crm' ); ?>" title="<?php esc_attr_e( 'Gestionar pedido', 'tradutema-crm' ); ?>">
                                                        <span class="tradutema-crm-action-icon" aria-hidden="true">G</span>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </main>
        </div>
        <?php
    }

    /**
     * Renderiza la vista dedicada a gestionar un pedido concreto.
     */
    public function render_manage_order_page() {
        $this->ensure_capability();

        $order_id        = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $selected_order  = null;
        $order_meta      = array();
        $order_object    = null;
        $order_number    = '';
        $order_customer  = '';
        $order_email     = '';
        $order_payment_label = '';
        $order_payment_status_label = '';
        $order_total     = '';
        $order_edit_url  = '';
        $gdrive_url      = '';
        $order_pages     = '';
        $order_delivery_display = '';
        $delivery_combined      = '';
        $real_delivery_input_value = '';
        $order_shipping_type    = '';
        $order_billing_details  = array();
        $order_shipping_details = array();
        $order_logs      = array();
        $order_has_shipping_details = false;
        $assigned_provider_details = null;
        $order_customer_note = '';

        $estado_operacion          = tradutema_crm_operational_statuses();
        $proveedores               = $this->get_proveedores();
        $all_proveedores_indexed   = $this->get_proveedores_indexed();
        $email_templates           = $this->get_active_email_templates();
        $order_language_pair_label = '';

        if ( ! empty( $order_id ) ) {
            $selected_order = $this->get_order_details( $order_id );

            if ( is_wp_error( $selected_order ) ) {
                add_settings_error( 'tradutema-crm', 'order_error', $selected_order->get_error_message(), 'error' );
                $selected_order = null;
            }
        } elseif ( isset( $_GET['order_id'] ) ) {
            add_settings_error(
                'tradutema-crm',
                'order_missing',
                __( 'Debes seleccionar un pedido válido para gestionarlo.', 'tradutema-crm' ),
                'error'
            );
        }

        if ( $selected_order && isset( $selected_order['order'] ) && $selected_order['order'] instanceof WC_Order ) {
            $order_object   = $selected_order['order'];
            $order_number   = $order_object->get_order_number();
            $order_customer = trim( $order_object->get_formatted_billing_full_name() );

            if ( '' === $order_customer ) {
                $order_customer = trim( $order_object->get_billing_first_name() . ' ' . $order_object->get_billing_last_name() );
            }

            $order_email                = $order_object->get_billing_email();
            $order_payment_method_name  = $order_object->get_payment_method_title();
            $order_payment_status_label = wc_get_order_status_name( $order_object->get_status() );
            $order_payment_label        = $order_payment_method_name ? $order_payment_method_name : '';
            $order_total                = $order_object->get_formatted_order_total();
            $order_edit_url             = get_edit_post_link( $order_object->get_id(), '' );

            if ( empty( $order_edit_url ) ) {
                $order_edit_url = admin_url( sprintf( 'post.php?post=%d&action=edit', $order_object->get_id() ) );
            }

            $gdrive_url = ! empty( $selected_order['gdrive_url'] ) ? $selected_order['gdrive_url'] : '';
            $order_meta = isset( $selected_order['meta'] ) && is_array( $selected_order['meta'] ) ? $selected_order['meta'] : array();
            $order_logs = $this->get_order_logs( $order_object->get_id() );

            $countries_instance = class_exists( WC_Countries::class ) ? new WC_Countries() : null;

            $order_pages = $this->find_order_item_meta_value(
                $order_object,
                array( '¿Cuántas páginas tiene?', 'Número de páginas', 'Paginas' )
            );

            $delivery_date_value = $this->find_order_item_meta_value( $order_object, array( 'Fecha', 'Fecha de entrega', 'Fecha de Entrega' ) );
            $delivery_time_value = $this->find_order_item_meta_value( $order_object, array( 'Hora', 'Hora de entrega', 'Hora de Entrega' ) );

            list( $delivery_combined, $delivery_has_time ) = $this->prepare_datetime_value( $delivery_date_value, $delivery_time_value );

            if ( '' !== $delivery_combined ) {
                $formatted_delivery = $this->format_display_date( $delivery_combined, $delivery_has_time );
                $order_delivery_display = '' !== $formatted_delivery ? $formatted_delivery : $delivery_combined;
            }

            $billing_data = $order_object->get_address( 'billing' );
            $billing_name = trim( $order_object->get_formatted_billing_full_name() );

            if ( '' === $billing_name ) {
                $billing_name = trim(
                    (string) tradutema_array_get( $billing_data, 'first_name' ) . ' ' . (string) tradutema_array_get( $billing_data, 'last_name' )
                );
            }

            if ( '' !== $billing_name ) {
                $order_billing_details[] = array(
                    'label' => __( 'Nombre completo', 'tradutema-crm' ),
                    'value' => $billing_name,
                );
            }

            $billing_company = trim( (string) tradutema_array_get( $billing_data, 'company' ) );

            if ( '' !== $billing_company ) {
                $order_billing_details[] = array(
                    'label' => __( 'Empresa', 'tradutema-crm' ),
                    'value' => $billing_company,
                );
            }

            if ( $order_email ) {
                $order_billing_details[] = array(
                    'label' => __( 'Correo electrónico', 'tradutema-crm' ),
                    'value' => $order_email,
                );
            }

            $billing_phone = $order_object->get_billing_phone();

            if ( $billing_phone ) {
                $sanitized_phone = wc_sanitize_phone_number( $billing_phone );
                $wa_phone        = preg_replace( '/\D+/', '', $sanitized_phone );

                if ( '' === $wa_phone ) {
                    $wa_phone = $sanitized_phone;
                }

                $order_billing_details[] = array(
                    'label'      => __( 'Teléfono', 'tradutema-crm' ),
                    'value'      => $billing_phone,
                    'value_html' => sprintf(
                        '<a href="tel:%1$s">%2$s</a> <span class="text-muted">|</span> <a href="https://wa.me/%3$s" target="_blank" rel="noopener noreferrer">%4$s</a>',
                        esc_attr( $sanitized_phone ),
                        esc_html( $billing_phone ),
                        esc_attr( $wa_phone ),
                        esc_html__( 'WhatsApp', 'tradutema-crm' )
                    ),
                );
            }

            $billing_address_lines = array_filter(
                array(
                    trim( (string) tradutema_array_get( $billing_data, 'address_1' ) ),
                    trim( (string) tradutema_array_get( $billing_data, 'address_2' ) ),
                )
            );

            if ( ! empty( $billing_address_lines ) ) {
                $order_billing_details[] = array(
                    'label' => __( 'Dirección', 'tradutema-crm' ),
                    'value' => implode( ', ', $billing_address_lines ),
                );
            }

            $billing_postcode = trim( (string) tradutema_array_get( $billing_data, 'postcode' ) );

            if ( '' !== $billing_postcode ) {
                $order_billing_details[] = array(
                    'label' => __( 'Código postal', 'tradutema-crm' ),
                    'value' => $billing_postcode,
                );
            }

            $billing_city = trim( (string) tradutema_array_get( $billing_data, 'city' ) );

            if ( '' !== $billing_city ) {
                $order_billing_details[] = array(
                    'label' => __( 'Ciudad', 'tradutema-crm' ),
                    'value' => $billing_city,
                );
            }

            $billing_state_code = trim( (string) tradutema_array_get( $billing_data, 'state' ) );

            if ( '' !== $billing_state_code ) {
                $billing_state_label = $billing_state_code;

                if ( $countries_instance ) {
                    $states = $countries_instance->get_states( tradutema_array_get( $billing_data, 'country' ) );

                    if ( is_array( $states ) && isset( $states[ $billing_state_code ] ) ) {
                        $billing_state_label = $states[ $billing_state_code ];
                    }
                }

                $order_billing_details[] = array(
                    'label' => __( 'Provincia/Estado', 'tradutema-crm' ),
                    'value' => $billing_state_label,
                );
            }

            $billing_country_code = trim( (string) tradutema_array_get( $billing_data, 'country' ) );

            if ( '' !== $billing_country_code ) {
                $billing_country_label = $billing_country_code;

                if ( $countries_instance ) {
                    $billing_country_label = tradutema_array_get( $countries_instance->countries, $billing_country_code, $billing_country_code );
                }

                $order_billing_details[] = array(
                    'label' => __( 'País', 'tradutema-crm' ),
                    'value' => $billing_country_label,
                );
            }

            $shipping_data = $order_object->get_address( 'shipping' );

            $shipping_name = trim(
                (string) tradutema_array_get( $shipping_data, 'first_name' ) . ' ' . (string) tradutema_array_get( $shipping_data, 'last_name' )
            );

            if ( '' !== $shipping_name ) {
                $order_shipping_details[] = array(
                    'label' => __( 'Nombre completo', 'tradutema-crm' ),
                    'value' => $shipping_name,
                );
            }

            $shipping_company = trim( (string) tradutema_array_get( $shipping_data, 'company' ) );

            if ( '' !== $shipping_company ) {
                $order_shipping_details[] = array(
                    'label' => __( 'Empresa', 'tradutema-crm' ),
                    'value' => $shipping_company,
                );
            }

            $shipping_phone = method_exists( $order_object, 'get_shipping_phone' ) ? $order_object->get_shipping_phone() : '';

            if ( $shipping_phone ) {
                $order_shipping_details[] = array(
                    'label' => __( 'Teléfono', 'tradutema-crm' ),
                    'value' => $shipping_phone,
                );
            }

            $shipping_address_lines = array_filter(
                array(
                    trim( (string) tradutema_array_get( $shipping_data, 'address_1' ) ),
                    trim( (string) tradutema_array_get( $shipping_data, 'address_2' ) ),
                )
            );

            if ( ! empty( $shipping_address_lines ) ) {
                $order_shipping_details[] = array(
                    'label' => __( 'Dirección', 'tradutema-crm' ),
                    'value' => implode( ', ', $shipping_address_lines ),
                );
            }

            $shipping_postcode = trim( (string) tradutema_array_get( $shipping_data, 'postcode' ) );

            if ( '' !== $shipping_postcode ) {
                $order_shipping_details[] = array(
                    'label' => __( 'Código postal', 'tradutema-crm' ),
                    'value' => $shipping_postcode,
                );
            }

            $shipping_city = trim( (string) tradutema_array_get( $shipping_data, 'city' ) );

            if ( '' !== $shipping_city ) {
                $order_shipping_details[] = array(
                    'label' => __( 'Ciudad', 'tradutema-crm' ),
                    'value' => $shipping_city,
                );
            }

            $shipping_state_code = trim( (string) tradutema_array_get( $shipping_data, 'state' ) );

            if ( '' !== $shipping_state_code ) {
                $shipping_state_label = $shipping_state_code;

                if ( $countries_instance ) {
                    $states = $countries_instance->get_states( tradutema_array_get( $shipping_data, 'country' ) );

                    if ( is_array( $states ) && isset( $states[ $shipping_state_code ] ) ) {
                        $shipping_state_label = $states[ $shipping_state_code ];
                    }
                }

                $order_shipping_details[] = array(
                    'label' => __( 'Provincia/Estado', 'tradutema-crm' ),
                    'value' => $shipping_state_label,
                );
            }

            $shipping_country_code = trim( (string) tradutema_array_get( $shipping_data, 'country' ) );

            if ( '' !== $shipping_country_code ) {
                $shipping_country_label = $shipping_country_code;

                if ( $countries_instance ) {
                    $shipping_country_label = tradutema_array_get( $countries_instance->countries, $shipping_country_code, $shipping_country_code );
                }

                $order_shipping_details[] = array(
                    'label' => __( 'País', 'tradutema-crm' ),
                    'value' => $shipping_country_label,
                );
            }

            $order_has_shipping_details = ! empty( $order_shipping_details );

        }

        $order_meta = wp_parse_args(
            $order_meta,
            array(
                'estado_operacional'     => 'recibido',
                'proveedor_id'           => 0,
                'referencia'             => '',
                'fecha_prevista_entrega' => '',
                'hora_prevista_entrega'  => '',
                'fecha_real_entrega_pdf' => '',
                'envio_papel'            => 0,
                'comentario_interno'     => '',
                'comentario_linguistico' => '',
            )
        );

        $order_language_origin      = isset( $order_meta['idioma_origen'] ) ? trim( (string) $order_meta['idioma_origen'] ) : '';
        $order_language_destination = isset( $order_meta['idioma_destino'] ) ? trim( (string) $order_meta['idioma_destino'] ) : '';

        if ( '' === $order_language_origin && $order_object instanceof WC_Order ) {
            $order_language_origin = $this->find_order_item_meta_value(
                $order_object,
                array( '¿En qué idioma está el documento?', 'Idioma origen', 'Idioma del documento' )
            );
        }

        if ( '' === $order_language_destination && $order_object instanceof WC_Order ) {
            $order_language_destination = $this->find_order_item_meta_value(
                $order_object,
                array( '¿A qué idioma quieres traducirlo?', 'Idioma destino' )
            );
        }
        $order_language_pair_label  = $this->format_language_pair( $order_language_origin, $order_language_destination );

        $available_proveedores = $this->filter_proveedores_by_language_pair( $proveedores, $order_language_origin, $order_language_destination );

        if ( $order_meta['proveedor_id'] ) {
            $current_provider_id = absint( $order_meta['proveedor_id'] );
            $provider_in_list    = false;

            foreach ( $available_proveedores as $available_provider ) {
                if ( $current_provider_id === absint( tradutema_array_get( $available_provider, 'id' ) ) ) {
                    $provider_in_list = true;
                    break;
                }
            }

            if ( ! $provider_in_list && isset( $all_proveedores_indexed[ $current_provider_id ] ) ) {
                $available_proveedores[] = $all_proveedores_indexed[ $current_provider_id ];
            }
        }

        $proveedores = $available_proveedores;

        if ( $order_object ) {
            $order_shipping_type = $this->resolve_order_shipping_type( $order_object, $order_meta );
            $order_customer_note = trim( wp_strip_all_tags( (string) $order_object->get_customer_note() ) );
        }

        $proveedores_indexed = $all_proveedores_indexed;

        if ( $order_meta['proveedor_id'] && isset( $proveedores_indexed[ $order_meta['proveedor_id'] ] ) ) {
            $assigned_provider_details = $proveedores_indexed[ $order_meta['proveedor_id'] ];
        }

        $provider_shipping_address = '';
        $provider_contact_email    = '';
        $provider_contact_phone    = '';
        $current_provider_id       = isset( $order_meta['proveedor_id'] ) ? absint( $order_meta['proveedor_id'] ) : 0;
        $has_assigned_provider     = $current_provider_id > 0 && isset( $proveedores_indexed[ $current_provider_id ] );

        if ( $assigned_provider_details ) {
            $provider_shipping_address = trim( (string) tradutema_array_get( $assigned_provider_details, 'direccion_recogida' ) );
            $provider_contact_email    = trim( (string) tradutema_array_get( $assigned_provider_details, 'email' ) );
            $provider_contact_phone    = trim( (string) tradutema_array_get( $assigned_provider_details, 'telefono' ) );
        }

        $stored_real_delivery      = trim( (string) tradutema_array_get( $order_meta, 'fecha_real_entrega_pdf', '' ) );
        $real_delivery_input_value = $this->format_datetime_for_input( $stored_real_delivery );

        if ( '' === $stored_real_delivery && '' !== $delivery_combined ) {
            $real_delivery_input_value = $this->format_datetime_for_input( $delivery_combined );
        }

        $real_delivery_date        = '';
        $real_delivery_time        = '';
        $real_delivery_time_options = array( '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00' );

        if ( $real_delivery_input_value ) {
            $real_delivery_date = substr( $real_delivery_input_value, 0, 10 );
            $real_delivery_time = substr( $real_delivery_input_value, 11, 5 );
        }

        $current_user  = wp_get_current_user();
        $current_page  = tradutema_crm_current_admin_page();
        $navigation    = tradutema_crm_admin_nav_items();
        $brand_message = sprintf(
            /* translators: %s: user display name */
            esc_html__( 'Sesión iniciada como %s', 'tradutema-crm' ),
            esc_html( $current_user->display_name )
        );
        $logout_url = wp_logout_url( admin_url() );

        $page_title = __( 'Gestión del pedido', 'tradutema-crm' );
        $header_title = $page_title;

        if ( $order_number ) {
            $header_title = sprintf(
                /* translators: %s: order number */
                __( 'Pedido #%s', 'tradutema-crm' ),
                $order_number
            );
        }

        $order_id_field = $order_object instanceof WC_Order ? $order_object->get_id() : $order_id;

        ?>
        <div class="tradutema-crm-shell">
            <aside id="tradutema-crm-sidebar" class="tradutema-crm-sidebar">
                <div>
                    <div class="tradutema-crm-brand"><?php esc_html_e( 'Tradutema CRM', 'tradutema-crm' ); ?></div>
                    <p class="tradutema-crm-user">
                        <?php echo esc_html( $brand_message ); ?>
                        <a class="tradutema-crm-logout" href="<?php echo esc_url( $logout_url ); ?>">
                            <?php esc_html_e( 'Cerrar sesión', 'tradutema-crm' ); ?>
                        </a>
                    </p>
                </div>
                <nav class="tradutema-crm-nav nav flex-column" aria-label="<?php esc_attr_e( 'Secciones del CRM', 'tradutema-crm' ); ?>">
                    <?php foreach ( $navigation as $item ) :
                        $is_active = $current_page === $item['page'];
                        ?>
                        <a class="nav-link<?php echo $is_active ? ' active' : ''; ?>" <?php echo $is_active ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item['page'] ) ); ?>">
                            <?php echo esc_html( $item['label'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </aside>
            <main class="tradutema-crm-main">
                <div class="tradutema-crm-header">
                    <div class="tradutema-crm-header-bar">
                        <div class="tradutema-crm-header-group">
                            <h1><?php echo esc_html( $header_title ); ?></h1>
                        </div>
                        <div class="tradutema-crm-header-actions">
                            <button type="button" class="tradutema-crm-sidebar-toggle" aria-controls="tradutema-crm-sidebar" aria-expanded="true" aria-label="<?php echo esc_attr__( 'Ocultar menú', 'tradutema-crm' ); ?>" data-label-expanded="<?php echo esc_attr__( 'Ocultar menú', 'tradutema-crm' ); ?>" data-label-collapsed="<?php echo esc_attr__( 'Mostrar menú', 'tradutema-crm' ); ?>">
                                <span class="tradutema-crm-sidebar-toggle-icon" aria-hidden="true"></span>
                                <span class="tradutema-crm-sidebar-toggle-label"><?php esc_html_e( 'Ocultar menú', 'tradutema-crm' ); ?></span>
                            </button>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=tradutema-crm' ) ); ?>" class="btn btn-outline-secondary">
                                <?php esc_html_e( 'Volver al listado', 'tradutema-crm' ); ?>
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ( ! $order_object ) : ?>
                    <div class="card tradutema-crm-card">
                        <div class="card-body py-5 text-center">
                            <p class="mb-0 text-muted"><?php esc_html_e( 'Selecciona un pedido desde el dashboard para gestionarlo aquí.', 'tradutema-crm' ); ?></p>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="card tradutema-crm-card">
                        <div class="card-body">
                            <div class="tradutema-crm-section tradutema-crm-section-woo">
                                <h2 class="h5 mb-3 tradutema-crm-section-title"><?php esc_html_e( 'Información de WooCommerce', 'tradutema-crm' ); ?></h2>
                                <div class="row g-3 g-xl-4 mb-3">
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <p class="text-muted mb-1"><?php esc_html_e( 'Cliente', 'tradutema-crm' ); ?></p>
                                        <p class="mb-0 fw-semibold tradutema-crm-woo-customer-name"><?php echo esc_html( $order_customer ? $order_customer : __( 'Cliente no especificado', 'tradutema-crm' ) ); ?></p>
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <p class="text-muted mb-1"><?php esc_html_e( 'Correo electrónico', 'tradutema-crm' ); ?></p>
                                        <p class="mb-0"><?php echo $order_email ? esc_html( $order_email ) : '<span class="text-muted">&mdash;</span>'; ?></p>
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <p class="text-muted mb-1"><?php esc_html_e( 'Pago', 'tradutema-crm' ); ?></p>
                                        <p class="mb-0"><?php echo $order_payment_label ? esc_html( $order_payment_label ) : '<span class="text-muted">&mdash;</span>'; ?></p>
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <p class="text-muted mb-1"><?php esc_html_e( 'Total', 'tradutema-crm' ); ?></p>
                                        <p class="mb-0"><?php echo $order_total ? wp_kses_post( $order_total ) : '<span class="text-muted">&mdash;</span>'; ?></p>
                                    </div>
                                </div>
                                <div class="row g-3 g-xl-4 mb-3">
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <p class="text-muted mb-1"><?php esc_html_e( 'Estado de pago', 'tradutema-crm' ); ?></p>
                                        <p class="mb-0"><?php echo $order_payment_status_label ? esc_html( $order_payment_status_label ) : '<span class="text-muted">&mdash;</span>'; ?></p>
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <p class="text-muted mb-1"><?php esc_html_e( 'Número de páginas', 'tradutema-crm' ); ?></p>
                                        <p class="mb-0"><?php echo $order_pages ? esc_html( $order_pages ) : '<span class="text-muted">&mdash;</span>'; ?></p>
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <p class="text-muted mb-1"><?php esc_html_e( 'Fecha y hora solicitada', 'tradutema-crm' ); ?></p>
                                        <p class="mb-0"><?php echo $order_delivery_display ? esc_html( $order_delivery_display ) : '<span class="text-muted">&mdash;</span>'; ?></p>
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <p class="text-muted mb-1"><?php esc_html_e( 'Tipo de envío', 'tradutema-crm' ); ?></p>
                                        <p class="mb-0"><?php echo $order_shipping_type ? esc_html( $order_shipping_type ) : '<span class="text-muted">&mdash;</span>'; ?></p>
                                    </div>
                                </div>
                                <div class="row g-3 g-xl-4 mb-3">
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <p class="text-muted mb-1"><?php esc_html_e( 'Comentario del cliente', 'tradutema-crm' ); ?></p>
                                        <p class="mb-0">
                                            <?php if ( '' !== $order_customer_note ) : ?>
                                                <span class="tradutema-crm-woo-comment-badge" data-bs-toggle="tooltip" data-bs-placement="auto" title="<?php echo esc_attr( $order_customer_note ); ?>" aria-label="<?php esc_attr_e( 'Ver comentario del cliente', 'tradutema-crm' ); ?>">💬</span>
                                            <?php else : ?>
                                                <span class="text-muted">&mdash;</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                                    <h3 class="h6 text-uppercase text-muted mb-0"><?php esc_html_e( 'Direcciones', 'tradutema-crm' ); ?></h3>
                                    <button type="button" class="btn btn-sm btn-outline-primary tradutema-crm-address-toggle collapsed" data-bs-toggle="collapse" data-bs-target="#tradutema-crm-addresses" aria-expanded="false" data-label-expanded="<?php esc_attr_e( 'Ocultar direcciones', 'tradutema-crm' ); ?>" data-label-collapsed="<?php esc_attr_e( 'Ver direcciones', 'tradutema-crm' ); ?>">
                                        <?php esc_html_e( 'Ver direcciones', 'tradutema-crm' ); ?>
                                    </button>
                                </div>
                                <div class="collapse tradutema-crm-addresses" id="tradutema-crm-addresses">
                                    <div class="row g-4 mt-0">
                                        <div class="col-12 col-xl-6">
                                            <h3 class="h6 text-uppercase text-muted mb-2"><?php esc_html_e( 'Datos de facturación', 'tradutema-crm' ); ?></h3>
                                            <?php if ( ! empty( $order_billing_details ) ) : ?>
                                                <dl class="row g-1 small mb-0">
                                                    <?php foreach ( $order_billing_details as $detail ) : ?>
                                                        <dt class="col-sm-5 text-muted mb-1"><?php echo esc_html( $detail['label'] ); ?></dt>
                                                        <?php
                                                        $detail_value = isset( $detail['value_html'] ) ? wp_kses_post( $detail['value_html'] ) : esc_html( $detail['value'] );
                                                        ?>
                                                        <dd class="col-sm-7 text-break mb-1 fw-semibold"><?php echo $detail_value; ?></dd>
                                                    <?php endforeach; ?>
                                                </dl>
                                            <?php else : ?>
                                                <p class="text-muted small mb-0"><?php esc_html_e( 'Sin datos de facturación.', 'tradutema-crm' ); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-12 col-xl-6">
                                            <h3 class="h6 text-uppercase text-muted mb-2"><?php esc_html_e( 'Datos de envío', 'tradutema-crm' ); ?></h3>
                                            <?php if ( $order_has_shipping_details ) : ?>
                                                <dl class="row g-1 small mb-0">
                                                    <?php foreach ( $order_shipping_details as $detail ) : ?>
                                                        <dt class="col-sm-5 text-muted mb-1"><?php echo esc_html( $detail['label'] ); ?></dt>
                                                        <dd class="col-sm-7 text-break mb-1 fw-semibold"><?php echo esc_html( $detail['value'] ); ?></dd>
                                                    <?php endforeach; ?>
                                                </dl>
                                            <?php else : ?>
                                                <p class="text-muted small mb-0"><?php esc_html_e( 'El pedido no incluye datos de envío.', 'tradutema-crm' ); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mb-4">
                                <?php if ( $order_edit_url ) : ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url( $order_edit_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'WOO', 'tradutema-crm' ); ?></a>
                                <?php endif; ?>
                                <?php if ( $gdrive_url ) : ?>
                                    <a class="btn btn-sm btn-outline-success" href="<?php echo esc_url( $gdrive_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'GDrive', 'tradutema-crm' ); ?></a>
                                <?php endif; ?>
                            </div>

                            <form method="post" class="row g-3 tradutema-crm-section tradutema-crm-section-provider tradutema-crm-provider-grid" id="tradutema-crm-order-form">
                                <?php wp_nonce_field( 'tradutema_crm_update_order' ); ?>
                                <input type="hidden" name="tradutema_crm_action" value="update_order" />
                                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id_field ); ?>" />

                                <div class="col-12">
                                    <h2 class="h6 text-uppercase mb-2 tradutema-crm-section-title"><?php esc_html_e( 'Gestión del proveedor', 'tradutema-crm' ); ?></h2>
                                </div>
                                <div class="col-12">
                                    <div class="row g-3 align-items-start">
                                        <div class="col-12 col-xl-6">
                                            <div class="d-flex flex-column gap-3 h-100">
                                                <div>
                                                    <label for="order_estado_operacional" class="form-label"><?php esc_html_e( 'Estado operacional', 'tradutema-crm' ); ?></label>
                                                    <select name="estado_operacional" id="order_estado_operacional" class="form-select">
                                                        <?php foreach ( $estado_operacion as $estado_key => $estado_label ) : ?>
                                                            <option value="<?php echo esc_attr( $estado_key ); ?>" <?php selected( $order_meta['estado_operacional'], $estado_key ); ?>><?php echo esc_html( $estado_label ); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label for="order_referencia" class="form-label"><?php esc_html_e( 'Referencia interna', 'tradutema-crm' ); ?></label>
                                                    <input type="text" name="referencia" id="order_referencia" value="<?php echo esc_attr( $order_meta['referencia'] ); ?>" class="form-control" />
                                                </div>
                                                <div>
                                                    <h2 class="h6 text-uppercase text-muted mb-2 tradutema-crm-provider-subtitle"><?php esc_html_e( 'Planificación y entrega', 'tradutema-crm' ); ?></h2>
                                                    <label for="order_fecha_real_pdf_date" class="form-label"><?php esc_html_e( 'Fecha y ahora Entrega real Proveedor', 'tradutema-crm' ); ?></label>
                                                    <div class="row g-2">
                                                        <div class="col-7">
                                                            <input type="date" id="order_fecha_real_pdf_date" value="<?php echo esc_attr( $real_delivery_date ); ?>" class="form-control" />
                                                        </div>
                                                        <div class="col-5">
                                                            <select id="order_fecha_real_pdf_time" class="form-select">
                                                                <option value=""><?php esc_html_e( 'Selecciona hora', 'tradutema-crm' ); ?></option>
                                                                <?php foreach ( $real_delivery_time_options as $time_option ) : ?>
                                                                    <option value="<?php echo esc_attr( $time_option ); ?>" <?php selected( $real_delivery_time, $time_option ); ?>><?php echo esc_html( $time_option ); ?> h</option>
                                                                <?php endforeach; ?>
                                                                <?php if ( $real_delivery_time && ! in_array( $real_delivery_time, $real_delivery_time_options, true ) ) : ?>
                                                                    <option value="<?php echo esc_attr( $real_delivery_time ); ?>" selected><?php echo esc_html( $real_delivery_time ); ?> h</option>
                                                                <?php endif; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <input type="hidden" name="fecha_real_entrega_pdf" id="order_fecha_real_pdf" value="<?php echo esc_attr( $real_delivery_input_value ); ?>" />
                                                    <script>
                                                        document.addEventListener('DOMContentLoaded', function () {
                                                            const realDeliveryDate = document.getElementById('order_fecha_real_pdf_date');
                                                            const realDeliveryTime = document.getElementById('order_fecha_real_pdf_time');
                                                            const realDeliveryField = document.getElementById('order_fecha_real_pdf');

                                                            if (!realDeliveryDate || !realDeliveryTime || !realDeliveryField) {
                                                                return;
                                                            }

                                                            const syncRealDeliveryValue = () => {
                                                                const dateValue = realDeliveryDate.value;
                                                                const timeValue = realDeliveryTime.value;

                                                                realDeliveryField.value = dateValue && timeValue ? `${dateValue}T${timeValue}` : '';
                                                            };

                                                            realDeliveryDate.addEventListener('change', syncRealDeliveryValue);
                                                            realDeliveryTime.addEventListener('change', syncRealDeliveryValue);

                                                            syncRealDeliveryValue();
                                                        });
                                                    </script>
                                                </div>
                                                <div class="tradutema-crm-provider-notes">
                                                    <h2 class="h6 text-uppercase text-muted mb-2 tradutema-crm-provider-subtitle"><?php esc_html_e( 'Comentario interno', 'tradutema-crm' ); ?></h2>
                                                    <label for="order_comentario" class="form-label"><?php esc_html_e( 'Comentario interno', 'tradutema-crm' ); ?></label>
                                                    <textarea name="comentario_interno" id="order_comentario" rows="3" class="form-control"><?php echo esc_textarea( $order_meta['comentario_interno'] ); ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12 col-xl-6">
                                            <div class="d-flex flex-column gap-3 h-100">
                                                <div>
                                                    <label for="order_proveedor_id" class="form-label"><?php esc_html_e( 'Proveedor asignado', 'tradutema-crm' ); ?></label>
                                                    <select name="proveedor_id" id="order_proveedor_id" class="form-select">
                                                        <option value="0"><?php esc_html_e( 'Sin asignar', 'tradutema-crm' ); ?></option>
                                                        <?php foreach ( $proveedores as $proveedor ) : ?>
                                                            <option value="<?php echo esc_attr( $proveedor['id'] ); ?>" <?php selected( (int) $order_meta['proveedor_id'], (int) $proveedor['id'] ); ?>><?php echo esc_html( $proveedor['nombre_comercial'] ); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if ( $order_language_pair_label ) : ?>
                                                        <p class="form-text mb-0"><?php printf( esc_html__( 'Par de idiomas del pedido: %s', 'tradutema-crm' ), esc_html( $order_language_pair_label ) ); ?></p>
                                                    <?php else : ?>
                                                        <p class="form-text text-muted mb-0"><?php esc_html_e( 'El pedido no tiene un par de idiomas registrado.', 'tradutema-crm' ); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ( $order_language_pair_label && empty( $proveedores ) ) : ?>
                                                        <p class="text-danger small mb-0 mt-1"><?php esc_html_e( 'No hay proveedores con este par de idiomas en sus servicios.', 'tradutema-crm' ); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ( $assigned_provider_details ) : ?>
                                                    <div class="bg-light border rounded-3 p-3 tradutema-crm-provider-subcard">
                                                        <h3 class="h6 mb-2"><?php esc_html_e( 'Datos de envío del proveedor', 'tradutema-crm' ); ?></h3>
                                                        <?php if ( $provider_shipping_address ) : ?>
                                                            <p class="mb-1 small"><strong><?php esc_html_e( 'Dirección de recogida', 'tradutema-crm' ); ?>:</strong> <span class="text-break"><?php echo nl2br( esc_html( $provider_shipping_address ) ); ?></span></p>
                                                        <?php endif; ?>
                                                        <?php if ( $provider_contact_email ) : ?>
                                                            <p class="mb-1 small"><strong><?php esc_html_e( 'Correo de contacto', 'tradutema-crm' ); ?>:</strong> <a href="mailto:<?php echo esc_attr( $provider_contact_email ); ?>"><?php echo esc_html( $provider_contact_email ); ?></a></p>
                                                        <?php endif; ?>
                                                        <?php if ( $provider_contact_phone ) : ?>
                                                            <p class="mb-0 small"><strong><?php esc_html_e( 'Teléfono de contacto', 'tradutema-crm' ); ?>:</strong> <a href="tel:<?php echo esc_attr( $provider_contact_phone ); ?>"><?php echo esc_html( $provider_contact_phone ); ?></a></p>
                                                        <?php endif; ?>
                                                        <?php if ( ! $provider_shipping_address && ! $provider_contact_email && ! $provider_contact_phone ) : ?>
                                                            <p class="mb-0 text-muted small"><?php esc_html_e( 'El proveedor no tiene datos de envío registrados.', 'tradutema-crm' ); ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="tradutema-crm-provider-notes">
                                                    <h2 class="h6 text-uppercase text-muted mb-2 tradutema-crm-provider-subtitle"><?php esc_html_e( 'Comentario lingüístico', 'tradutema-crm' ); ?></h2>
                                                    <label for="order_comentario_linguistico" class="form-label"><?php esc_html_e( 'Comentario lingüístico', 'tradutema-crm' ); ?></label>
                                                    <textarea name="comentario_linguistico" id="order_comentario_linguistico" rows="3" class="form-control"><?php echo esc_textarea( $order_meta['comentario_linguistico'] ); ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary px-4"><?php esc_html_e( 'Guardar cambios', 'tradutema-crm' ); ?></button>
                                </div>
                            </form>
                            <div class="tradutema-crm-section tradutema-crm-section-email mt-4">
                                <h2 class="h5 mb-3 tradutema-crm-section-title"><?php esc_html_e( 'Plantillas de email', 'tradutema-crm' ); ?></h2>
                                <?php if ( empty( $email_templates ) ) : ?>
                                    <p class="text-muted mb-0"><?php esc_html_e( 'No hay plantillas de email activas disponibles.', 'tradutema-crm' ); ?></p>
                                <?php elseif ( ! $order_email ) : ?>
                                    <p class="text-muted mb-0"><?php esc_html_e( 'Añade un correo electrónico del cliente para habilitar el envío de plantillas.', 'tradutema-crm' ); ?></p>
                                <?php else : ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ( $email_templates as $template ) : ?>
                                            <?php
                                            $template_recipients = (string) tradutema_array_get( $template, 'destinatarios', '' );
                                            $requires_provider   = false !== strpos( $template_recipients, '{{proveedor.email}}' );
                                            $disable_template    = $requires_provider && ! $has_assigned_provider;
                                            ?>
                                            <form method="post" class="d-inline tradutema-crm-email-template-form">
                                                <?php wp_nonce_field( 'tradutema_crm_send_email_template' ); ?>
                                                <input type="hidden" name="tradutema_crm_action" value="send_email_template" />
                                                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id_field ); ?>" />
                                                <input type="hidden" name="template_id" value="<?php echo esc_attr( $template['id'] ); ?>" />
                                                <input type="hidden" name="update_order_nonce" value="<?php echo esc_attr( wp_create_nonce( 'tradutema_crm_update_order' ) ); ?>" />
                                                <input type="hidden" name="estado_operacional" value="" />
                                                <input type="hidden" name="proveedor_id" value="" />
                                                <input type="hidden" name="fecha_real_entrega_pdf" value="" />
                                                <input type="hidden" name="referencia" value="" />
                                                <input type="hidden" name="comentario_interno" value="" />
                                                <input type="hidden" name="comentario_linguistico" value="" />
                                                <input type="hidden" name="fecha_prevista_entrega" value="" />
                                                <input type="hidden" name="hora_prevista_entrega" value="" />
                                                <input type="hidden" name="envio_papel" value="" />
                                                <button type="submit" class="btn btn-outline-primary btn-sm" <?php disabled( $disable_template ); ?><?php if ( $disable_template ) : ?> aria-disabled="true" title="<?php esc_attr_e( 'Selecciona un proveedor antes de enviar esta plantilla.', 'tradutema-crm' ); ?>"<?php endif; ?>><?php printf( esc_html__( 'Enviar %s', 'tradutema-crm' ), esc_html( $template['nombre'] ) ); ?></button>
                                                <?php if ( $disable_template ) : ?>
                                                    <p class="text-danger small mb-0 mt-1"><?php esc_html_e( 'Selecciona un proveedor antes de enviar esta plantilla.', 'tradutema-crm' ); ?></p>
                                                <?php endif; ?>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="tradutema-crm-section tradutema-crm-section-history mt-4 mb-0">
                                <h2 class="h5 mb-3 tradutema-crm-section-title"><?php esc_html_e( 'Historial del pedido', 'tradutema-crm' ); ?></h2>
                                <?php if ( empty( $order_logs ) ) : ?>
                                    <p class="text-muted mb-0"><?php esc_html_e( 'Todavía no hay registros asociados a este pedido.', 'tradutema-crm' ); ?></p>
                                <?php else : ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th scope="col"><?php esc_html_e( 'Acción', 'tradutema-crm' ); ?></th>
                                                    <th scope="col"><?php esc_html_e( 'Usuario', 'tradutema-crm' ); ?></th>
                                                    <th scope="col"><?php esc_html_e( 'Fecha', 'tradutema-crm' ); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ( $order_logs as $log_entry ) : ?>
                                                    <tr>
                                                        <td>
                                                            <span class="badge bg-secondary me-2"><?php echo esc_html( $log_entry['type_label'] ); ?></span>
                                                            <?php echo esc_html( $log_entry['detail'] ); ?>
                                                            <?php if ( ! empty( $log_entry['extra_lines'] ) ) : ?>
                                                                <ul class="mb-0 small text-muted ps-3 mt-1">
                                                                    <?php foreach ( $log_entry['extra_lines'] as $extra_line ) : ?>
                                                                        <li><?php echo esc_html( $extra_line ); ?></li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo esc_html( $log_entry['user_label'] ); ?></td>
                                                        <td><?php echo esc_html( $log_entry['created_label'] ); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
        <?php
    }

    /**
     * Renderiza la gestión de proveedores.
     */
    public function render_proveedores() {
        $this->ensure_capability();

        $proveedores       = $this->get_proveedores();
        $proveedores_usage = $this->get_proveedores_order_counts();
        $current           = array();
        $current_prov_id   = isset( $_GET['proveedor_id'] ) ? absint( $_GET['proveedor_id'] ) : 0;

        if ( $current_prov_id ) {
            $current = $this->get_proveedor( $current_prov_id );
            if ( ! $current ) {
                add_settings_error(
                    'tradutema-crm',
                    'missing_proveedor',
                    __( 'El proveedor indicado no existe.', 'tradutema-crm' ),
                    'error'
                );
                $current = array();
            }
        }

        $current_pairs      = array();
        if ( is_array( $current ) && array_key_exists( 'pares_servicio', $current ) ) {
            $current_pairs = $this->decode_translation_pairs( $current['pares_servicio'] );
        }
        $current_pairs_json = wp_json_encode( $current_pairs );
        if ( false === $current_pairs_json ) {
            $current_pairs_json = '[]';
        }

        $is_editing    = ! empty( $current );
        $form_title    = $is_editing ? __( 'Editar proveedor', 'tradutema-crm' ) : __( 'Añadir proveedor', 'tradutema-crm' );
        $current_page  = tradutema_crm_current_admin_page();
        $navigation    = tradutema_crm_admin_nav_items();
        $current_user  = wp_get_current_user();
        $brand_message = sprintf(
            /* translators: %s: user display name */
            esc_html__( 'Sesión iniciada como %s', 'tradutema-crm' ),
            esc_html( $current_user->display_name )
        );
        $logout_url   = wp_logout_url( admin_url() );

        $current_recipients = '';

        if ( $is_editing ) {
            $current_recipients = (string) tradutema_array_get( $current, 'destinatarios', '' );

            if ( '' === $current_recipients ) {
                $legacy_recipients = (string) tradutema_array_get( $current, 'para', '' );

                if ( '' !== $legacy_recipients ) {
                    $current_recipients = $legacy_recipients;
                }
            }
        }

        if ( '' === $current_recipients ) {
            $current_recipients = '{{customer_email}}';
        }

        ?>
        <div class="tradutema-crm-shell">
            <aside id="tradutema-crm-sidebar" class="tradutema-crm-sidebar">
                <div>
                    <div class="tradutema-crm-brand"><?php esc_html_e( 'Tradutema CRM', 'tradutema-crm' ); ?></div>
                    <p class="tradutema-crm-user">
                        <?php echo esc_html( $brand_message ); ?>
                        <a class="tradutema-crm-logout" href="<?php echo esc_url( $logout_url ); ?>">
                            <?php esc_html_e( 'Cerrar sesión', 'tradutema-crm' ); ?>
                        </a>
                    </p>
                </div>
                <nav class="tradutema-crm-nav nav flex-column" aria-label="<?php esc_attr_e( 'Secciones del CRM', 'tradutema-crm' ); ?>">
                    <?php foreach ( $navigation as $item ) :
                        $is_active = $current_page === $item['page'];
                        ?>
                        <a class="nav-link<?php echo $is_active ? ' active' : ''; ?>" <?php echo $is_active ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item['page'] ) ); ?>">
                            <?php echo esc_html( $item['label'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </aside>
            <main class="tradutema-crm-main">
                <div class="tradutema-crm-header">
                    <div class="tradutema-crm-header-bar">
                        <div class="tradutema-crm-header-group">
                            <h1><?php esc_html_e( 'Proveedores', 'tradutema-crm' ); ?></h1>
                            <p><?php esc_html_e( 'Administra tus colaboradores, consulta sus datos y actualiza su disponibilidad de manera ágil.', 'tradutema-crm' ); ?></p>
                        </div>
                        <div class="tradutema-crm-header-actions">
                            <button type="button" class="tradutema-crm-sidebar-toggle" aria-controls="tradutema-crm-sidebar" aria-expanded="true" aria-label="<?php echo esc_attr__( 'Ocultar menú', 'tradutema-crm' ); ?>" data-label-expanded="<?php echo esc_attr__( 'Ocultar menú', 'tradutema-crm' ); ?>" data-label-collapsed="<?php echo esc_attr__( 'Mostrar menú', 'tradutema-crm' ); ?>">
                                <span class="tradutema-crm-sidebar-toggle-icon" aria-hidden="true"></span>
                                <span class="tradutema-crm-sidebar-toggle-label"><?php esc_html_e( 'Ocultar menú', 'tradutema-crm' ); ?></span>
                            </button>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=tradutema-crm-proveedores' ) ); ?>" class="btn btn-outline-primary">
                                <?php esc_html_e( 'Añadir nuevo', 'tradutema-crm' ); ?>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-12 col-xl-7">
                        <div class="card tradutema-crm-card h-100">
                            <div class="card-header">
                                <?php esc_html_e( 'Listado de proveedores', 'tradutema-crm' ); ?>
                            </div>
                            <div class="card-body">
                                <div class="tradutema-crm-table-wrapper">
                                    <table class="table table-striped table-hover align-middle tradutema-datatable" data-page-length="15" data-empty-message="<?php echo esc_attr__( 'Aún no hay proveedores registrados.', 'tradutema-crm' ); ?>">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e( 'Nombre', 'tradutema-crm' ); ?></th>
                                                <th><?php esc_html_e( 'Email', 'tradutema-crm' ); ?></th>
                                                <th><?php esc_html_e( 'Teléfono', 'tradutema-crm' ); ?></th>
                                                <th><?php esc_html_e( 'Tarifa mínima', 'tradutema-crm' ); ?></th>
                                                <th><?php esc_html_e( 'Tarifa interno', 'tradutema-crm' ); ?></th>
                                                <th><?php esc_html_e( 'Interno', 'tradutema-crm' ); ?></th>
                                                <th><?php esc_html_e( 'Pares en servicio', 'tradutema-crm' ); ?></th>
                                                <th class="text-end"><?php esc_html_e( 'Acciones', 'tradutema-crm' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ( ! empty( $proveedores ) ) : ?>
                                                <?php foreach ( $proveedores as $proveedor ) :
                                                    $pares_summary = $this->format_translation_pairs_summary( $this->decode_translation_pairs( isset( $proveedor['pares_servicio'] ) ? $proveedor['pares_servicio'] : array() ) );
                                                    $has_orders    = $this->proveedor_has_orders( (int) $proveedor['id'], $proveedores_usage );
                                                    ?>
                                                    <tr>
                                                        <td><?php echo esc_html( $proveedor['nombre_comercial'] ); ?></td>
                                                        <td>
                                                            <?php if ( ! empty( $proveedor['email'] ) ) : ?>
                                                                <a href="mailto:<?php echo esc_attr( $proveedor['email'] ); ?>" class="text-decoration-none"><?php echo esc_html( $proveedor['email'] ); ?></a>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo esc_html( $proveedor['telefono'] ); ?></td>
                                                        <td><?php echo esc_html( tradutema_array_get( $proveedor, 'tarifa_minima_text' ) ); ?></td>
                                                        <td><?php echo esc_html( tradutema_array_get( $proveedor, 'tarifa_interno' ) ); ?></td>
                                                        <td><?php echo ! empty( $proveedor['interno'] ) ? esc_html__( 'Sí', 'tradutema-crm' ) : esc_html__( 'No', 'tradutema-crm' ); ?></td>
                                                        <td><?php echo esc_html( $pares_summary ); ?></td>
                                                        <td class="text-end">
                                                            <div class="d-flex justify-content-end gap-2">
                                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'tradutema-crm-proveedores', 'proveedor_id' => $proveedor['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Editar', 'tradutema-crm' ); ?></a>
                                                                <?php if ( $has_orders ) : ?>
                                                                    <button type="button" class="btn btn-sm btn-outline-danger" disabled data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo esc_attr__( 'No puedes eliminar proveedores con pedidos asignados.', 'tradutema-crm' ); ?>"><?php esc_html_e( 'Eliminar', 'tradutema-crm' ); ?></button>
                                                                <?php else : ?>
                                                                    <form method="post" onsubmit="return confirm('<?php echo esc_js( __( '¿Seguro que deseas eliminar este proveedor?', 'tradutema-crm' ) ); ?>');">
                                                                        <?php wp_nonce_field( 'tradutema_crm_delete_proveedor' ); ?>
                                                                        <input type="hidden" name="tradutema_crm_action" value="delete_proveedor" />
                                                                        <input type="hidden" name="proveedor_id" value="<?php echo esc_attr( $proveedor['id'] ); ?>" />
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger"><?php esc_html_e( 'Eliminar', 'tradutema-crm' ); ?></button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-xl-5">
                        <div class="card tradutema-crm-card h-100">
                            <div class="card-header">
                                <?php echo esc_html( $form_title ); ?>
                            </div>
                            <div class="card-body">
                                <form method="post" class="row g-3" action="<?php echo esc_url( admin_url( 'admin.php?page=tradutema-crm-proveedores' ) ); ?>">
                                    <?php wp_nonce_field( 'tradutema_crm_save_proveedor' ); ?>
                                    <input type="hidden" name="tradutema_crm_action" value="save_proveedor" />
                                    <input type="hidden" name="proveedor_id" value="<?php echo esc_attr( tradutema_array_get( $current, 'id' ) ); ?>" />

                                    <div class="col-12">
                                        <label for="proveedor_nombre" class="form-label"><?php esc_html_e( 'Nombre', 'tradutema-crm' ); ?></label>
                                        <input type="text" class="form-control" id="proveedor_nombre" name="nombre_comercial" value="<?php echo esc_attr( tradutema_array_get( $current, 'nombre_comercial' ) ); ?>" required />
                                    </div>
                                    <div class="col-12">
                                        <label for="proveedor_email" class="form-label"><?php esc_html_e( 'Email', 'tradutema-crm' ); ?></label>
                                        <input type="email" class="form-control" id="proveedor_email" name="email" value="<?php echo esc_attr( tradutema_array_get( $current, 'email' ) ); ?>" />
                                    </div>
                                    <div class="col-12">
                                        <label for="proveedor_telefono" class="form-label"><?php esc_html_e( 'Teléfono', 'tradutema-crm' ); ?></label>
                                        <input type="text" class="form-control" id="proveedor_telefono" name="telefono" value="<?php echo esc_attr( tradutema_array_get( $current, 'telefono' ) ); ?>" />
                                    </div>
                                    <div class="col-12">
                                        <label for="proveedor_comentarios" class="form-label"><?php esc_html_e( 'Comentarios internos', 'tradutema-crm' ); ?></label>
                                        <textarea id="proveedor_comentarios" name="comentarios" rows="4" class="form-control"><?php echo esc_textarea( tradutema_array_get( $current, 'comentarios' ) ); ?></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label for="proveedor_tarifa_minima" class="form-label"><?php esc_html_e( 'Tarifa mínima', 'tradutema-crm' ); ?></label>
                                        <input type="text" class="form-control" id="proveedor_tarifa_minima" name="tarifa_minima_text" value="<?php echo esc_attr( tradutema_array_get( $current, 'tarifa_minima_text' ) ); ?>" />
                                    </div>
                                    <div class="col-12">
                                        <label for="proveedor_tarifa_interno" class="form-label"><?php esc_html_e( 'Tarifa interno', 'tradutema-crm' ); ?></label>
                                        <input type="text" class="form-control" id="proveedor_tarifa_interno" name="tarifa_interno" value="<?php echo esc_attr( tradutema_array_get( $current, 'tarifa_interno' ) ); ?>" />
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="proveedor_interno" name="interno" value="1" <?php checked( (int) tradutema_array_get( $current, 'interno', 0 ), 1 ); ?> />
                                            <label class="form-check-label" for="proveedor_interno"><?php esc_html_e( 'Proveedor interno', 'tradutema-crm' ); ?></label>
                                        </div>
                                    </div>
                                    <div class="col-12" data-translation-pairs>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <label class="form-label mb-0" for="proveedor_pares_servicio_list"><?php esc_html_e( 'Pares de traducción en servicio', 'tradutema-crm' ); ?></label>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-add-translation-pair><?php esc_html_e( 'Añadir par', 'tradutema-crm' ); ?></button>
                                        </div>
                                        <p class="form-text"><?php esc_html_e( 'Indica los idiomas origen y destino que ofrece el proveedor.', 'tradutema-crm' ); ?></p>
                                        <input type="hidden" name="pares_servicio_json" value="<?php echo esc_attr( $current_pairs_json ); ?>" />
                                        <div id="proveedor_pares_servicio_list" data-translation-pairs-list></div>
                                        <template data-translation-pairs-template>
                                            <div class="row g-2 align-items-end mb-2" data-translation-pair-row>
                                                <div class="col-sm-5">
                                                    <select class="form-select" data-translation-pair-source data-placeholder="<?php echo esc_attr__( 'Selecciona idioma origen', 'tradutema-crm' ); ?>"></select>
                                                </div>
                                                <div class="col-sm-5">
                                                    <select class="form-select" data-translation-pair-target data-placeholder="<?php echo esc_attr__( 'Selecciona idioma destino', 'tradutema-crm' ); ?>"></select>
                                                </div>
                                                <div class="col-sm-2 d-grid">
                                                    <button type="button" class="btn btn-outline-danger" data-remove-translation-pair aria-label="<?php echo esc_attr__( 'Eliminar par de traducción', 'tradutema-crm' ); ?>">&times;</button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="col-12">
                                        <label for="proveedor_datos_facturacion" class="form-label"><?php esc_html_e( 'Datos de facturación', 'tradutema-crm' ); ?></label>
                                        <textarea id="proveedor_datos_facturacion" name="datos_facturacion" rows="4" class="form-control"><?php echo esc_textarea( tradutema_array_get( $current, 'datos_facturacion' ) ); ?></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label for="proveedor_direccion_recogida" class="form-label"><?php esc_html_e( 'Dirección de recogida de traducción', 'tradutema-crm' ); ?></label>
                                        <textarea id="proveedor_direccion_recogida" name="direccion_recogida" rows="4" class="form-control"><?php echo esc_textarea( tradutema_array_get( $current, 'direccion_recogida' ) ); ?></textarea>
                                    </div>

                                    <div class="col-12 d-flex justify-content-end">
                                        <button type="submit" class="btn btn-primary px-4"><?php echo esc_html( $is_editing ? __( 'Actualizar proveedor', 'tradutema-crm' ) : __( 'Crear proveedor', 'tradutema-crm' ) ); ?></button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
        <?php
    }

    /**
     * Renderiza la gestión de plantillas de correo.
     */
    public function render_email_templates() {
        $this->ensure_capability();

        $templates     = $this->get_email_templates();
        $current       = null;
        $current_tmpl  = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;

        if ( $current_tmpl ) {
            $current = $this->get_email_template( $current_tmpl );
            if ( ! $current ) {
                add_settings_error(
                    'tradutema-crm',
                    'missing_template',
                    __( 'La plantilla solicitada no existe.', 'tradutema-crm' ),
                    'error'
                );
            }
        }

        $is_editing    = ! empty( $current );
        $form_title    = $is_editing ? __( 'Editar plantilla', 'tradutema-crm' ) : __( 'Nueva plantilla', 'tradutema-crm' );
        $placeholders  = array(
            '{{order_id}}',
            '{{customer_name}}',
            '{{customer_email}}',
            '{{order_total}}',
            '{{comentarios_cliente}}',
            '{{estado_operacional}}',
            '{{fecha_prevista}}',
            '{{tipo_envio}}',
            '{{comentario_interno}}',
            '{{comentario_linguistico}}',
            '{{idioma_origen}}',
            '{{idioma_destino}}',
            '{{num_paginas}}',
            '{{proveedor.nombre_comercial}}',
            '{{proveedor.email}}',
            '{{proveedor.direccion_recogida}}',
            '{{gdrive_link_full_folder}}',
            '{{gdrive_link_source}}',
            '{{gdrive_link_work}}',
            '{{gdrive_link_translation}}',
            '{{gdrive_link_To_Client}}',
            '{{Upload_To_Client}}',
            '{{fecha_real_entrega_proveedor}}',
            '{{direccion_entrega_papel}}',
        );
        $operational_statuses = tradutema_crm_operational_statuses();
        $current_template_status = '';
        $current_recipients      = '{{customer_email}}';

        if ( $is_editing ) {
            $raw_status = (string) tradutema_array_get( $current, 'estado_operacional', '' );

            if ( '' !== $raw_status ) {
                $current_template_status = $this->normalize_operational_status_value( $raw_status );
            }

            $stored_recipients = (string) tradutema_array_get( $current, 'destinatarios', '' );

            if ( '' === $stored_recipients ) {
                $legacy_recipients = (string) tradutema_array_get( $current, 'para', '' );

                if ( '' !== $legacy_recipients ) {
                    $stored_recipients = $legacy_recipients;
                }
            }

            if ( '' !== $stored_recipients ) {
                $current_recipients = $stored_recipients;
            }
        }
        $current_page  = tradutema_crm_current_admin_page();
        $navigation    = tradutema_crm_admin_nav_items();
        $current_user  = wp_get_current_user();
        $brand_message = sprintf(
            /* translators: %s: user display name */
            esc_html__( 'Sesión iniciada como %s', 'tradutema-crm' ),
            esc_html( $current_user->display_name )
        );
        $logout_url   = wp_logout_url( admin_url() );

        ?>
        <div class="tradutema-crm-shell">
            <aside id="tradutema-crm-sidebar" class="tradutema-crm-sidebar">
                <div>
                    <div class="tradutema-crm-brand"><?php esc_html_e( 'Tradutema CRM', 'tradutema-crm' ); ?></div>
                    <p class="tradutema-crm-user">
                        <?php echo esc_html( $brand_message ); ?>
                        <a class="tradutema-crm-logout" href="<?php echo esc_url( $logout_url ); ?>">
                            <?php esc_html_e( 'Cerrar sesión', 'tradutema-crm' ); ?>
                        </a>
                    </p>
                </div>
                <nav class="tradutema-crm-nav nav flex-column" aria-label="<?php esc_attr_e( 'Secciones del CRM', 'tradutema-crm' ); ?>">
                    <?php foreach ( $navigation as $item ) :
                        $is_active = $current_page === $item['page'];
                        ?>
                        <a class="nav-link<?php echo $is_active ? ' active' : ''; ?>" <?php echo $is_active ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item['page'] ) ); ?>">
                            <?php echo esc_html( $item['label'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </aside>
            <main class="tradutema-crm-main">
                <div class="tradutema-crm-header">
                    <div class="tradutema-crm-header-bar">
                        <div class="tradutema-crm-header-group">
                            <h1><?php esc_html_e( 'Plantillas de email', 'tradutema-crm' ); ?></h1>
                            <p><?php esc_html_e( 'Crea mensajes reutilizables, activa o desactiva plantillas y mantén consistencia en tus comunicaciones.', 'tradutema-crm' ); ?></p>
                        </div>
                        <div class="tradutema-crm-header-actions">
                            <button type="button" class="tradutema-crm-sidebar-toggle" aria-controls="tradutema-crm-sidebar" aria-expanded="true" aria-label="<?php echo esc_attr__( 'Ocultar menú', 'tradutema-crm' ); ?>" data-label-expanded="<?php echo esc_attr__( 'Ocultar menú', 'tradutema-crm' ); ?>" data-label-collapsed="<?php echo esc_attr__( 'Mostrar menú', 'tradutema-crm' ); ?>">
                                <span class="tradutema-crm-sidebar-toggle-icon" aria-hidden="true"></span>
                                <span class="tradutema-crm-sidebar-toggle-label"><?php esc_html_e( 'Ocultar menú', 'tradutema-crm' ); ?></span>
                            </button>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=tradutema-crm-plantillas' ) ); ?>" class="btn btn-outline-primary">
                                <?php esc_html_e( 'Añadir nueva', 'tradutema-crm' ); ?>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-12 col-xl-6">
                        <div class="card tradutema-crm-card h-100">
                            <div class="card-header">
                                <?php esc_html_e( 'Plantillas disponibles', 'tradutema-crm' ); ?>
                            </div>
                            <div class="card-body">
                                <div class="tradutema-crm-table-wrapper">
                                    <table class="table table-striped table-hover align-middle tradutema-datatable" data-page-length="15" data-empty-message="<?php echo esc_attr__( 'Todavía no has creado ninguna plantilla.', 'tradutema-crm' ); ?>">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e( 'Nombre', 'tradutema-crm' ); ?></th>
                                                <th><?php esc_html_e( 'Asunto', 'tradutema-crm' ); ?></th>
                                                <th><?php esc_html_e( 'Estado', 'tradutema-crm' ); ?></th>
                                                <th><?php esc_html_e( 'Actualizada', 'tradutema-crm' ); ?></th>
                                                <th class="text-end"><?php esc_html_e( 'Acciones', 'tradutema-crm' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ( ! empty( $templates ) ) : ?>
                                                <?php foreach ( $templates as $template ) : ?>
                                                    <tr>
                                                        <td><?php echo esc_html( $template['nombre'] ); ?></td>
                                                        <td><?php echo esc_html( $template['asunto'] ); ?></td>
                                                        <td><?php echo $template['activo'] ? esc_html__( 'Activa', 'tradutema-crm' ) : esc_html__( 'Inactiva', 'tradutema-crm' ); ?></td>
                                                        <td><?php echo esc_html( tradutema_crm_format_date( tradutema_array_get( $template, 'updated_at', tradutema_array_get( $template, 'created_at' ) ) ) ); ?></td>
                                                        <td class="text-end">
                                                            <div class="d-flex justify-content-end gap-2">
                                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'tradutema-crm-plantillas', 'template_id' => $template['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Editar', 'tradutema-crm' ); ?></a>
                                                                <form method="post" onsubmit="return confirm('<?php echo esc_js( __( '¿Quieres eliminar esta plantilla?', 'tradutema-crm' ) ); ?>');">
                                                                    <?php wp_nonce_field( 'tradutema_crm_delete_email_template' ); ?>
                                                                    <input type="hidden" name="tradutema_crm_action" value="delete_email_template" />
                                                                    <input type="hidden" name="template_id" value="<?php echo esc_attr( $template['id'] ); ?>" />
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?php esc_html_e( 'Eliminar', 'tradutema-crm' ); ?></button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-xl-6">
                        <div class="card tradutema-crm-card h-100">
                            <div class="card-header">
                                <?php echo esc_html( $form_title ); ?>
                            </div>
                            <div class="card-body">
                                <p class="text-muted mb-4">
                                    <?php esc_html_e( 'Puedes utilizar las siguientes variables en el asunto y cuerpo:', 'tradutema-crm' ); ?>
                                </p>
                                <div class="mb-3">
                                    <?php foreach ( $placeholders as $placeholder ) : ?>
                                        <span class="tradutema-crm-placeholder-badge"><?php echo esc_html( $placeholder ); ?></span>
                                    <?php endforeach; ?>
                                </div>

                                <form method="post" class="row g-3">
                                    <?php wp_nonce_field( 'tradutema_crm_save_email_template' ); ?>
                                    <input type="hidden" name="tradutema_crm_action" value="save_email_template" />
                                    <input type="hidden" name="template_id" value="<?php echo esc_attr( tradutema_array_get( $current, 'id' ) ); ?>" />

                                    <div class="col-12">
                                        <label for="template_nombre" class="form-label"><?php esc_html_e( 'Nombre interno', 'tradutema-crm' ); ?></label>
                                        <input type="text" class="form-control" id="template_nombre" name="nombre" value="<?php echo esc_attr( tradutema_array_get( $current, 'nombre' ) ); ?>" required />
                                    </div>
                                    <div class="col-12">
                                        <label for="template_asunto" class="form-label"><?php esc_html_e( 'Asunto', 'tradutema-crm' ); ?></label>
                                        <input type="text" class="form-control" id="template_asunto" name="asunto" value="<?php echo esc_attr( tradutema_array_get( $current, 'asunto' ) ); ?>" required />
                                    </div>
                                    <div class="col-12">
                                        <label for="template_destinatarios" class="form-label"><?php esc_html_e( 'Para (To)', 'tradutema-crm' ); ?></label>
                                        <input type="text" class="form-control" id="template_destinatarios" name="destinatarios" value="<?php echo esc_attr( $current_recipients ); ?>" required />
                                        <div class="form-text"><?php esc_html_e( 'Introduce emails separados por comas o saltos de línea. Puedes usar variables como {{customer_email}}.', 'tradutema-crm' ); ?></div>
                                    </div>
                                    <div class="col-12">
                                        <label for="template_estado_operacional" class="form-label"><?php esc_html_e( 'Cambio automático a estado operacional', 'tradutema-crm' ); ?></label>
                                        <select id="template_estado_operacional" name="estado_operacional" class="form-select">
                                            <option value=""><?php esc_html_e( 'Ninguno', 'tradutema-crm' ); ?></option>
                                            <?php foreach ( $operational_statuses as $status_key => $status_label ) : ?>
                                                <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $current_template_status, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label for="template_cuerpo" class="form-label"><?php esc_html_e( 'Contenido HTML', 'tradutema-crm' ); ?></label>
                                        <textarea id="template_cuerpo" name="cuerpo_html" rows="8" class="form-control" required><?php echo esc_textarea( tradutema_array_get( $current, 'cuerpo_html' ) ); ?></textarea>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input type="checkbox" name="activo" value="1" id="template_activo" class="form-check-input" <?php checked( (int) tradutema_array_get( $current, 'activo', 1 ), 1 ); ?> />
                                            <label class="form-check-label" for="template_activo"><?php esc_html_e( 'Plantilla activa', 'tradutema-crm' ); ?></label>
                                        </div>
                                    </div>

                                    <div class="col-12 d-flex justify-content-end">
                                        <button type="submit" class="btn btn-primary px-4"><?php echo esc_html( $is_editing ? __( 'Actualizar plantilla', 'tradutema-crm' ) : __( 'Crear plantilla', 'tradutema-crm' ) ); ?></button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
        <?php
    }

    /**
     * Asegura que el usuario actual tiene permisos.
     */
    private function ensure_capability() {
        if ( ! current_user_can( 'manage_tradutema_crm' ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'tradutema-crm' ) );
        }
    }

    /**
     * Obtiene los filtros aplicados a la lista de pedidos.
     *
     * @return array
     */
    private function get_order_filters() {
        $filters = array(
            'payment_status'     => '',
            'estado_operacional' => '',
            'proveedor_id'       => '',
            'date_start'         => '',
            'date_end'           => '',
            'per_page'           => '',
            'filter_fecha_solicitud' => '',
            'filter_fecha_real'      => '',
        );

        $input = wp_unslash( $_GET );

        foreach ( $filters as $key => $default ) {
            if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
                if ( 'per_page' === $key ) {
                    $value = trim( (string) $input[ $key ] );

                    if ( '' === $value ) {
                        $filters[ $key ] = '';
                    } else {
                        $filters[ $key ] = max( 1, min( 200, absint( $value ) ) );
                    }
                } else {
                    $filters[ $key ] = sanitize_text_field( $input[ $key ] );
                }
            }
        }

        if ( '' !== $filters['estado_operacional'] ) {
            $filters['estado_operacional'] = $this->normalize_operational_status_value( $filters['estado_operacional'] );
        }

        return $filters;
    }

    /**
     * Obtiene el ID de pedido correspondiente al límite del último mes.
     *
     * @return int
     */
    private function get_recent_orders_threshold_id() {
        static $threshold_id = null;

        if ( null !== $threshold_id ) {
            return $threshold_id;
        }

        global $wpdb;

        $threshold_id = 0;

        $timezone_string = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';

        if ( empty( $timezone_string ) ) {
            $timezone_string = 'UTC';
        }

        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( $timezone_string );
        $date     = new \DateTime( 'now', $timezone );

        $date->modify( '-1 month' );

        $threshold_datetime = $date->format( 'Y-m-d H:i:s' );

        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND post_date <= %s ORDER BY post_date DESC LIMIT 1",
            'shop_order',
            $threshold_datetime
        );

        $result = $wpdb->get_var( $sql );

        if ( $result ) {
            $threshold_id = (int) $result;
        }

        return $threshold_id;
    }

    /**
     * Obtiene los pedidos del CRM aplicando los filtros proporcionados.
     *
     * @param array $filters Filtros obtenidos mediante {@see get_order_filters()}.
     * @return array[] Lista de pedidos listos para mostrar.
     */
    private function get_orders( array $filters ) {
        global $wpdb;

        $table_posts    = $wpdb->posts;
        $table_order    = $wpdb->prefix . 'ttm_order_meta';
        $where          = array(
            "p.post_type = %s",
            "p.post_status NOT IN ('trash','auto-draft')",
        );
        $params         = array( 'shop_order' );

        $date_filters_active = ! empty( $filters['date_start'] ) || ! empty( $filters['date_end'] );

        if ( ! $date_filters_active ) {
            $threshold_id = $this->get_recent_orders_threshold_id();

            if ( $threshold_id > 0 ) {
                $where[]  = 'p.ID >= %d';
                $params[] = $threshold_id;
            }
        }

        if ( ! empty( $filters['payment_status'] ) ) {
            $where[]  = 'p.post_status = %s';
            $params[] = $filters['payment_status'];
        }

        if ( ! empty( $filters['estado_operacional'] ) ) {
            $where[]  = 'COALESCE(om.estado_operacional, %s) = %s';
            $params[] = 'recibido';
            $params[] = $filters['estado_operacional'];
        }

        if ( ! empty( $filters['proveedor_id'] ) ) {
            if ( 'none' === $filters['proveedor_id'] ) {
                $where[] = '(om.proveedor_id IS NULL OR om.proveedor_id = 0)';
            } else {
                $where[]  = 'om.proveedor_id = %d';
                $params[] = absint( $filters['proveedor_id'] );
            }
        }

        if ( ! empty( $filters['date_start'] ) ) {
            $where[]  = 'p.post_date >= %s';
            $params[] = $filters['date_start'] . ' 00:00:00';
        }

        if ( ! empty( $filters['date_end'] ) ) {
            $where[]  = 'p.post_date <= %s';
            $params[] = $filters['date_end'] . ' 23:59:59';
        }

        $where_clause = 'WHERE ' . implode( ' AND ', $where );

        $limit = absint( $filters['per_page'] );

        $sql = "SELECT p.ID
            FROM {$table_posts} p
            LEFT JOIN {$table_order} om ON om.order_id = p.ID
            {$where_clause}
            ORDER BY p.post_date DESC";

        if ( $limit > 0 ) {
            $sql      .= ' LIMIT %d';
            $params[]  = max( 1, min( 200, $limit ) );
        }

        $prepared = $wpdb->prepare( $sql, $params );
        $order_ids = $wpdb->get_col( $prepared );

        if ( empty( $order_ids ) ) {
            return array();
        }

        $proveedores_index = $this->get_proveedores_indexed();
        $orders            = array();

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                continue;
            }

            $meta            = $this->get_order_meta( $order_id );
            $status_key      = 'wc-' . $order->get_status();
            $customer_note   = $order->get_customer_note();
            $customer_name   = $order->get_billing_first_name();
            $payment_method  = $order->get_payment_method_title();
            $payment_status  = wc_get_order_status_name( $order->get_status() );
            $paper_required  = $this->order_requires_paper_delivery( $order, $meta );
            $fecha_solicitud = $this->find_order_item_meta_value( $order, array( 'Fecha' ) );
            $hora_solicitud  = $this->find_order_item_meta_value( $order, array( 'Hora' ) );
            list( $fecha_solicitud_valor, $fecha_solicitud_con_hora ) = $this->prepare_datetime_value( $fecha_solicitud, $hora_solicitud );
            $fecha_solicitud_timestamp                               = $this->get_datetime_timestamp( $fecha_solicitud_valor );
            $idioma_origen   = $this->find_order_item_meta_value( $order, array( '¿En qué idioma está el documento?', 'Idioma origen', 'Idioma del documento' ) );
            $idioma_destino  = $this->find_order_item_meta_value( $order, array( '¿A qué idioma quieres traducirlo?', 'Idioma destino' ) );
            $paginas         = $this->find_order_item_meta_value( $order, array( '¿Cuántas páginas tiene?', 'Número de páginas', 'Paginas' ) );
            $direccion_envio = $this->build_shipping_address_for_display( $order, $paper_required );

            $reference = $this->resolve_order_reference( $order, $meta );
            $origin    = $this->determine_order_origin( $order, $reference );

            $post_date       = get_post_field( 'post_date', $order_id );
            $display_date    = $this->format_display_date( $post_date, true, false );
            $timestamp       = $this->get_datetime_timestamp( $post_date );
            $sortable_date   = null !== $timestamp ? (string) $timestamp : '';
            $order_date_set  = $this->format_display_date_parts( $post_date, true, false );

            $fecha_real_valor     = tradutema_array_get( $meta, 'fecha_real_entrega_pdf', '' );
            $fecha_real_timestamp = $this->get_datetime_timestamp( $fecha_real_valor );
            $fecha_real_parts     = $this->format_display_date_parts( $fecha_real_valor, true, false );

            $edit_url = get_edit_post_link( $order_id, '' );

            if ( empty( $edit_url ) ) {
                $edit_url = admin_url( sprintf( 'post.php?post=%d&action=edit', $order_id ) );
            }

            $orders[] = array(
                'id'                     => $order_id,
                'number'                 => $order->get_order_number(),
                'date'                   => $display_date,
                'date_order'             => $sortable_date,
                'date_lines'             => $order_date_set,
                'woo_id'                 => $order_id,
                'customer'               => $customer_name,
                'customer_user_id'       => $order->get_customer_id(),
                'email'                  => $order->get_billing_email(),
                'total'                  => $order->get_total(),
                'currency'               => $order->get_currency(),
                'status'                 => $payment_status,
                'status_key'             => $status_key,
                'payment_method'         => $payment_method,
                'customer_note'          => $customer_note,
                'comentario_tradutema'   => tradutema_array_get( $meta, 'comentario_interno', '' ),
                'phone'                  => $order->get_billing_phone(),
                'referencia'             => $reference,
                'paper'                  => $paper_required,
                'paper_label'            => $paper_required ? __( 'Sí', 'tradutema-crm' ) : __( 'No', 'tradutema-crm' ),
                'fecha_solicitud'        => $fecha_solicitud_valor,
                'fecha_solicitud_has_time' => $fecha_solicitud_con_hora,
                'fecha_solicitud_order'  => null !== $fecha_solicitud_timestamp ? (string) $fecha_solicitud_timestamp : '',
                'fecha_solicitud_lines'  => $this->format_display_date_parts( $fecha_solicitud_valor, ! empty( $fecha_solicitud_con_hora ), false ),
                'fecha_real'             => $fecha_real_valor,
                'fecha_real_order'       => null !== $fecha_real_timestamp ? (string) $fecha_real_timestamp : '',
                'fecha_real_lines'       => $fecha_real_parts,
                'proveedor'              => $this->resolve_proveedor_name( $meta, $proveedores_index ),
                'proveedor_id'           => isset( $meta['proveedor_id'] ) ? absint( $meta['proveedor_id'] ) : 0,
                'origen_pedido'          => $origin,
                'estado_operacional_key' => tradutema_array_get( $meta, 'estado_operacional', 'recibido' ),
                'meta'                   => $meta,
                'idioma_origen'          => $idioma_origen,
                'idioma_destino'         => $idioma_destino,
                'paginas'                => $paginas,
                'direccion_envio'        => $direccion_envio,
                'direccion_envio_preview' => '' !== $direccion_envio ? wp_html_excerpt( $direccion_envio, 10, '...' ) : '',
                'edit_url'               => $edit_url,
                'gdrive_url'             => get_post_meta( $order_id, '_ciwc_drive_folder_url', true ),
            );
        }

        $filter_fecha_solicitud = $filters['filter_fecha_solicitud'];
        $filter_fecha_real      = $filters['filter_fecha_real'];

        if ( '' !== $filter_fecha_solicitud || '' !== $filter_fecha_real ) {
            $orders = array_values(
                array_filter(
                    $orders,
                    function ( $order ) use ( $filter_fecha_solicitud, $filter_fecha_real ) {
                        if ( '' !== $filter_fecha_solicitud ) {
                            $matches_solicitud = $this->order_matches_partial_date(
                                $filter_fecha_solicitud,
                                tradutema_array_get( $order, 'fecha_solicitud_lines', array() ),
                                tradutema_array_get( $order, 'fecha_solicitud', '' )
                            );

                            if ( ! $matches_solicitud ) {
                                return false;
                            }
                        }

                        if ( '' !== $filter_fecha_real ) {
                            $matches_real = $this->order_matches_partial_date(
                                $filter_fecha_real,
                                tradutema_array_get( $order, 'fecha_real_lines', array() ),
                                tradutema_array_get( $order, 'fecha_real', '' )
                            );

                            if ( ! $matches_real ) {
                                return false;
                            }
                        }

                        return true;
                    }
                )
            );
        }

        return $orders;
    }

    /**
     * Comprueba si una fecha de pedido coincide parcialmente con un filtro.
     *
     * @param string $filter_value  Valor parcial introducido por el usuario (por ejemplo, 15/12).
     * @param array  $date_parts    Partes de fecha generadas para mostrar en el listado.
     * @param string $raw_value     Valor original almacenado.
     * @return bool
     */
    private function order_matches_partial_date( $filter_value, $date_parts, $raw_value ) {
        $filter_value = trim( (string) $filter_value );

        if ( '' === $filter_value ) {
            return true;
        }

        $filter = $this->parse_filter_date_value( $filter_value );
        $candidates = array();

        if ( is_array( $date_parts ) ) {
            $candidates[] = tradutema_array_get( $date_parts, 'date', '' );
            $candidates[] = tradutema_array_get( $date_parts, 'time', '' );
        }

        $candidates[] = $raw_value;

        foreach ( $candidates as $candidate ) {
            $candidate = trim( (string) $candidate );

            if ( '' === $candidate ) {
                continue;
            }

            if ( $filter && $this->date_filter_matches_candidate( $filter, $candidate ) ) {
                return true;
            }

            if ( ! $filter ) {
                $normalized_candidate = str_replace( array( '-', '.' ), '/', $candidate );

                if ( false !== stripos( $normalized_candidate, $filter_value ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Convierte un valor de filtro a componentes de fecha.
     *
     * @param string $value Valor introducido.
     * @return array|null
     */
    private function parse_filter_date_value( $value ) {
        $value = trim( (string) $value );

        if ( '' === $value ) {
            return null;
        }

        $normalized = str_replace( array( '.', '-', ' ' ), '/', $value );

        if ( preg_match( '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $normalized, $matches ) ) {
            return array(
                'day'        => (int) $matches[3],
                'month'      => (int) $matches[2],
                'year'       => (int) $matches[1],
                'year_digits'=> 4,
            );
        }

        if ( preg_match( '/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?$/', $normalized, $matches ) ) {
            $year_digits = isset( $matches[3] ) ? strlen( $matches[3] ) : 0;

            return array(
                'day'        => (int) $matches[1],
                'month'      => (int) $matches[2],
                'year'       => $year_digits ? (int) $matches[3] : null,
                'year_digits'=> $year_digits,
            );
        }

        $timestamp = $this->get_datetime_timestamp( $value );

        if ( null === $timestamp ) {
            return null;
        }

        return array(
            'day'        => (int) wp_date( 'j', $timestamp ),
            'month'      => (int) wp_date( 'n', $timestamp ),
            'year'       => (int) wp_date( 'Y', $timestamp ),
            'year_digits'=> 4,
        );
    }

    /**
     * Comprueba si un candidato coincide con un filtro de fecha.
     *
     * @param array  $filter    Componentes del filtro.
     * @param string $candidate Valor a comprobar.
     * @return bool
     */
    private function date_filter_matches_candidate( array $filter, $candidate ) {
        $timestamp = $this->get_datetime_timestamp( $candidate );

        if ( null !== $timestamp ) {
            $candidate_day   = (int) wp_date( 'j', $timestamp );
            $candidate_month = (int) wp_date( 'n', $timestamp );
            $candidate_year  = (int) wp_date( 'Y', $timestamp );
        } else {
            $candidate_parts = $this->parse_filter_date_value( $candidate );

            if ( ! $candidate_parts ) {
                return false;
            }

            $candidate_day   = (int) $candidate_parts['day'];
            $candidate_month = (int) $candidate_parts['month'];
            $candidate_year  = isset( $candidate_parts['year'] ) ? (int) $candidate_parts['year'] : 0;
        }

        if ( $candidate_day !== (int) $filter['day'] || $candidate_month !== (int) $filter['month'] ) {
            return false;
        }

        if ( empty( $filter['year_digits'] ) || empty( $filter['year'] ) ) {
            return true;
        }

        if ( 2 === (int) $filter['year_digits'] ) {
            return ( $candidate_year % 100 ) === ( (int) $filter['year'] % 100 );
        }

        return $candidate_year === (int) $filter['year'];
    }

    /**
     * Devuelve la información de un pedido concreto.
     *
     * @param int $order_id ID del pedido.
     * @return array|WP_Error
     */
    private function get_order_details( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return new WP_Error( 'missing_order', __( 'El pedido indicado no existe.', 'tradutema-crm' ) );
        }

        $meta        = $this->get_order_meta( $order_id );
        $proveedores = $this->get_proveedores_indexed();
        $proveedor   = $this->resolve_proveedor_name( $meta, $proveedores );

        return array(
            'order'      => $order,
            'meta'       => $meta,
            'proveedor'  => $proveedor,
            'gdrive_url' => get_post_meta( $order_id, '_ciwc_drive_folder_url', true ),
        );
    }

    /**
     * Obtiene el meta personalizado de un pedido.
     *
     * @param int $order_id ID del pedido.
     * @return array
     */
    private function get_order_meta( $order_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'ttm_order_meta';
        $meta      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", $order_id ), ARRAY_A );
        $defaults  = array(
            'estado_operacional'     => 'recibido',
            'comentario_interno'     => '',
            'comentario_linguistico' => '',
            'referencia'             => '',
            'envio_papel'            => 0,
            'fecha_prevista_entrega' => '',
            'hora_prevista_entrega'  => '',
            'fecha_real_entrega_pdf' => '',
            'proveedor_id'           => null,
            'origen_pedido'          => '',
            'idioma_origen'          => '',
            'idioma_destino'         => '',
            'num_paginas'            => '',
            'tarifa_aplicada'        => '',
        );

        if ( ! $meta ) {
            $meta = $defaults;
        } else {
            $meta = wp_parse_args( $meta, $defaults );
        }

        $meta['estado_operacional'] = $this->normalize_operational_status_value( tradutema_array_get( $meta, 'estado_operacional', 'recibido' ) );

        return $meta;
    }

    /**
     * Guarda los metadatos personalizados del pedido.
     *
     * @param int   $order_id ID del pedido.
     * @param array $data     Datos a guardar.
     */
    private function save_order_meta( $order_id, array $data ) {
        global $wpdb;

        $table  = $wpdb->prefix . 'ttm_order_meta';
        $fields = array(
            'proveedor_id'          => isset( $data['proveedor_id'] ) ? absint( $data['proveedor_id'] ) ?: null : null,
            'comentario_interno'    => wp_kses_post( tradutema_array_get( $data, 'comentario_interno' ) ),
            'comentario_linguistico' => wp_kses_post( tradutema_array_get( $data, 'comentario_linguistico' ) ),
            'referencia'            => sanitize_text_field( tradutema_array_get( $data, 'referencia' ) ),
            'envio_papel'           => ! empty( $data['envio_papel'] ) ? 1 : 0,
            'estado_operacional'    => $this->normalize_operational_status_value( tradutema_array_get( $data, 'estado_operacional', 'recibido' ) ),
            'fecha_prevista_entrega'=> $this->null_if_empty( tradutema_array_get( $data, 'fecha_prevista_entrega' ) ),
            'hora_prevista_entrega' => $this->null_if_empty( tradutema_array_get( $data, 'hora_prevista_entrega' ) ),
            'fecha_real_entrega_pdf'=> $this->normalize_datetime_local_input( tradutema_array_get( $data, 'fecha_real_entrega_pdf' ) ),
        );

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d", $order_id ) );

        if ( $existing ) {
            $wpdb->update( $table, $fields, array( 'order_id' => $order_id ) );
        } else {
            $fields['order_id'] = $order_id;
            $wpdb->insert( $table, $fields );
        }
    }

    /**
     * Actualiza únicamente el estado operacional de un pedido.
     *
     * @param int    $order_id ID del pedido.
     * @param string $status   Estado operacional objetivo.
     */
    private function update_order_operational_status( $order_id, $status ) {
        global $wpdb;

        $table  = $wpdb->prefix . 'ttm_order_meta';
        $status = $this->normalize_operational_status_value( $status );
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d", $order_id ) );

        if ( $exists ) {
            $wpdb->update( $table, array( 'estado_operacional' => $status ), array( 'order_id' => $order_id ) );
            return;
        }

        $wpdb->insert(
            $table,
            array(
                'order_id'           => $order_id,
                'estado_operacional' => $status,
            )
        );
    }

    /**
     * Obtiene el historial de acciones del pedido.
     *
     * @param int $order_id ID del pedido.
     * @return array<int, array<string, mixed>>
     */
    private function get_order_logs( $order_id ) {
        global $wpdb;

        $order_id = absint( $order_id );

        if ( $order_id <= 0 ) {
            return array();
        }

        $table   = $wpdb->prefix . 'ttm_logs';
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, tipo, detalle, payload, created_at FROM {$table} WHERE order_id = %d ORDER BY created_at DESC, id DESC LIMIT 200",
                $order_id
            ),
            ARRAY_A
        );

        if ( empty( $results ) ) {
            return array();
        }

        $users_cache = array();
        $entries     = array();

        foreach ( $results as $row ) {
            $type          = sanitize_key( tradutema_array_get( $row, 'tipo', '' ) );
            $detail        = (string) tradutema_array_get( $row, 'detalle', '' );
            $created_at    = (string) tradutema_array_get( $row, 'created_at', '' );
            $created_label = '' !== $created_at ? $this->format_display_date( $created_at, true ) : '';
            $user_id       = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
            $user_label    = __( 'Sistema', 'tradutema-crm' );

            if ( $user_id > 0 ) {
                if ( ! array_key_exists( $user_id, $users_cache ) ) {
                    $user = get_user_by( 'id', $user_id );
                    $users_cache[ $user_id ] = $user instanceof WP_User ? $user->display_name : '';
                }

                if ( '' !== $users_cache[ $user_id ] ) {
                    $user_label = $users_cache[ $user_id ];
                } else {
                    $user_label = sprintf( __( 'Usuario #%d', 'tradutema-crm' ), $user_id );
                }
            }

            $payload     = array();
            $extra_lines = array();
            $raw_payload = tradutema_array_get( $row, 'payload', '' );

            if ( '' !== $raw_payload ) {
                $decoded = json_decode( $raw_payload, true );

                if ( is_array( $decoded ) ) {
                    $payload     = $decoded;
                    $extra_lines = $this->format_log_payload_summary( $payload );
                }
            }

            $entries[] = array(
                'type'          => $type,
                'type_label'    => $this->get_log_type_label( $type ),
                'detail'        => $detail,
                'user_label'    => $user_label,
                'created_at'    => $created_at,
                'created_label' => $created_label,
                'payload'       => $payload,
                'extra_lines'   => $extra_lines,
            );
        }

        return $entries;
    }

    /**
     * Registra un evento en el historial del pedido.
     *
     * @param int    $order_id ID del pedido.
     * @param string $type     Tipo de acción.
     * @param string $detail   Descripción corta.
     * @param array  $payload  Datos adicionales opcionales.
     */
    private function log_order_event( $order_id, $type, $detail, array $payload = array() ) {
        global $wpdb;

        $order_id = absint( $order_id );

        if ( $order_id <= 0 ) {
            return;
        }

        $table   = $wpdb->prefix . 'ttm_logs';
        $type    = sanitize_key( $type );
        $detail  = $this->normalize_log_detail( $detail, $this->get_log_type_label( $type ) );
        $payload = $this->encode_log_payload( $payload );

        $data   = array(
            'order_id' => $order_id,
            'tipo'     => $type,
            'detalle'  => $detail,
        );
        $format = array( '%d', '%s', '%s' );

        $user_id = get_current_user_id();

        if ( $user_id > 0 ) {
            $data['user_id'] = $user_id;
            $format[]        = '%d';
        }

        if ( null !== $payload ) {
            $data['payload'] = $payload;
            $format[]        = '%s';
        }

        $wpdb->insert( $table, $data, $format );
    }

    /**
     * Normaliza la descripción del evento para almacenarla.
     *
     * @param string $detail   Descripción recibida.
     * @param string $fallback Texto alternativo.
     * @return string
     */
    private function normalize_log_detail( $detail, $fallback = '' ) {
        $detail = trim( wp_strip_all_tags( (string) $detail ) );

        if ( '' === $detail ) {
            $detail = trim( (string) $fallback );
        }

        if ( '' === $detail ) {
            $detail = __( 'Acción del pedido', 'tradutema-crm' );
        }

        if ( function_exists( 'mb_substr' ) ) {
            $detail = mb_substr( $detail, 0, 255 );
        } else {
            $detail = substr( $detail, 0, 255 );
        }

        return $detail;
    }

    /**
     * Codifica los datos adicionales para almacenarlos en base de datos.
     *
     * @param array $payload Datos adicionales.
     * @return string|null
     */
    private function encode_log_payload( array $payload ) {
        if ( empty( $payload ) ) {
            return null;
        }

        $encoded = wp_json_encode( $payload );

        if ( false === $encoded ) {
            return null;
        }

        return $encoded;
    }

    /**
     * Devuelve una etiqueta legible para el tipo de log.
     *
     * @param string $type Tipo de log.
     * @return string
     */
    private function get_log_type_label( $type ) {
        $type = sanitize_key( $type );

        $map = array(
            'email'               => __( 'Email', 'tradutema-crm' ),
            'estado_operacional'  => __( 'Estado operacional', 'tradutema-crm' ),
            'order_update'        => __( 'Datos del pedido', 'tradutema-crm' ),
        );

        if ( isset( $map[ $type ] ) ) {
            return $map[ $type ];
        }

        if ( '' === $type ) {
            return __( 'Acción', 'tradutema-crm' );
        }

        return ucwords( str_replace( '_', ' ', $type ) );
    }

    /**
     * Prepara un resumen legible del payload de un log.
     *
     * @param array $payload Datos asociados.
     * @return array<int, string>
     */
    private function format_log_payload_summary( array $payload ) {
        $lines = array();

        if ( isset( $payload['changes'] ) && is_array( $payload['changes'] ) ) {
            foreach ( $payload['changes'] as $change ) {
                $label    = (string) tradutema_array_get( $change, 'label', '' );
                $previous = (string) tradutema_array_get( $change, 'previous', '' );
                $current  = (string) tradutema_array_get( $change, 'current', '' );

                if ( '' === $label ) {
                    continue;
                }

                $lines[] = sprintf(
                    '%1$s: %2$s → %3$s',
                    $label,
                    '' !== $previous ? $previous : '—',
                    '' !== $current ? $current : '—'
                );
            }
        }

        if ( isset( $payload['recipients'] ) && is_array( $payload['recipients'] ) && ! empty( $payload['recipients'] ) ) {
            $emails = array();

            foreach ( $payload['recipients'] as $recipient ) {
                $recipient = sanitize_email( (string) $recipient );

                if ( '' !== $recipient ) {
                    $emails[] = $recipient;
                }
            }

            if ( ! empty( $emails ) ) {
                $lines[] = sprintf( __( 'Destinatarios: %s', 'tradutema-crm' ), implode( ', ', $emails ) );
            }
        }

        if ( isset( $payload['subject'] ) ) {
            $subject = trim( wp_strip_all_tags( (string) $payload['subject'] ) );

            if ( '' !== $subject ) {
                $lines[] = sprintf( __( 'Asunto: %s', 'tradutema-crm' ), $subject );
            }
        }

        if ( isset( $payload['current_status'], $payload['previous_status'] ) && is_array( $payload['current_status'] ) && is_array( $payload['previous_status'] ) ) {
            $previous_label = (string) tradutema_array_get( $payload['previous_status'], 'label', '' );
            $current_label  = (string) tradutema_array_get( $payload['current_status'], 'label', '' );

            if ( '' !== $current_label ) {
                $lines[] = sprintf(
                    __( 'Estado: %1$s → %2$s', 'tradutema-crm' ),
                    '' !== $previous_label ? $previous_label : __( 'Sin definir', 'tradutema-crm' ),
                    $current_label
                );
            }
        }

        return $lines;
    }

    /**
     * Obtiene los cambios relevantes tras guardar el pedido.
     *
     * @param array $before Valores anteriores.
     * @param array $after  Valores actuales.
     * @return array<int, array<string, string>>
     */
    private function describe_order_meta_changes( array $before, array $after ) {
        $fields = array(
            'estado_operacional'    => __( 'Estado operacional', 'tradutema-crm' ),
            'proveedor_id'          => __( 'Proveedor', 'tradutema-crm' ),
            'referencia'            => __( 'Referencia interna', 'tradutema-crm' ),
            'comentario_interno'    => __( 'Comentario interno', 'tradutema-crm' ),
            'comentario_linguistico' => __( 'Comentario lingüístico', 'tradutema-crm' ),
            'envio_papel'           => __( 'Envío en papel', 'tradutema-crm' ),
            'fecha_prevista_entrega'=> __( 'Fecha prevista de entrega', 'tradutema-crm' ),
            'hora_prevista_entrega' => __( 'Hora prevista de entrega', 'tradutema-crm' ),
            'fecha_real_entrega_pdf'=> __( 'Fecha real de entrega al proveedor', 'tradutema-crm' ),
        );

        $providers = $this->get_proveedores_indexed();
        $changes   = array();

        foreach ( $fields as $field => $label ) {
            $previous = tradutema_array_get( $before, $field, null );
            $current  = tradutema_array_get( $after, $field, null );

            if ( 'proveedor_id' === $field ) {
                $previous = $previous ? absint( $previous ) : 0;
                $current  = $current ? absint( $current ) : 0;
            }

            if ( 'envio_papel' === $field ) {
                $previous = (int) (bool) $previous;
                $current  = (int) (bool) $current;
            }

            if ( 'estado_operacional' === $field ) {
                $previous = '' !== $previous ? $this->normalize_operational_status_value( $previous ) : '';
                $current  = '' !== $current ? $this->normalize_operational_status_value( $current ) : '';
            }

            if ( $previous == $current ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
                continue;
            }

            $changes[] = array(
                'field'    => $field,
                'label'    => $label,
                'previous' => $this->format_order_meta_value_for_log( $field, $previous, $providers ),
                'current'  => $this->format_order_meta_value_for_log( $field, $current, $providers ),
            );
        }

        return $changes;
    }

    /**
     * Formatea un valor del pedido para mostrarlo en el historial.
     *
     * @param string $field     Campo evaluado.
     * @param mixed  $value     Valor almacenado.
     * @param array  $providers Lista de proveedores indexados.
     * @return string
     */
    private function format_order_meta_value_for_log( $field, $value, array $providers ) {
        if ( null === $value || '' === $value ) {
            return __( 'Sin definir', 'tradutema-crm' );
        }

        switch ( $field ) {
            case 'estado_operacional':
                return $this->get_operational_status_label( $value );
            case 'proveedor_id':
                $provider_id = absint( $value );

                if ( $provider_id <= 0 ) {
                    return __( 'Sin asignar', 'tradutema-crm' );
                }

                if ( isset( $providers[ $provider_id ]['nombre_comercial'] ) ) {
                    return $providers[ $provider_id ]['nombre_comercial'];
                }

                return sprintf( __( 'Proveedor #%d', 'tradutema-crm' ), $provider_id );
            case 'comentario_interno':
            case 'comentario_linguistico':
                return wp_html_excerpt( wp_strip_all_tags( (string) $value ), 80, '…' );
            case 'envio_papel':
                return (int) $value ? __( 'Sí', 'tradutema-crm' ) : __( 'No', 'tradutema-crm' );
            case 'fecha_prevista_entrega':
                return $this->format_display_date( (string) $value, false );
            case 'fecha_real_entrega_pdf':
                return $this->format_display_date( (string) $value, true );
        }

        return (string) $value;
    }

    /**
     * Devuelve la etiqueta traducida de un estado operacional.
     *
     * @param string $status_key Estado a formatear.
     * @return string
     */
    private function get_operational_status_label( $status_key ) {
        $status_key = $this->normalize_operational_status_value( $status_key );
        $statuses   = tradutema_crm_operational_statuses();

        if ( isset( $statuses[ $status_key ] ) ) {
            return $statuses[ $status_key ];
        }

        return ucwords( str_replace( '_', ' ', $status_key ) );
    }

    /**
     * Maneja el guardado de un proveedor.
     */
    private function handle_save_proveedor() {
        check_admin_referer( 'tradutema_crm_save_proveedor' );

        $pares_raw  = wp_unslash( tradutema_array_get( $_POST, 'pares_servicio_json', '' ) );
        $pares_data = $this->decode_translation_pairs( $pares_raw );
        $pares_json = wp_json_encode( $pares_data );
        if ( false === $pares_json ) {
            $pares_json = '[]';
        }

        $data = array(
            'id'                 => isset( $_POST['proveedor_id'] ) ? absint( $_POST['proveedor_id'] ) : 0,
            'nombre_comercial'   => sanitize_text_field( wp_unslash( tradutema_array_get( $_POST, 'nombre_comercial' ) ) ),
            'email'              => sanitize_email( tradutema_array_get( $_POST, 'email' ) ),
            'telefono'           => sanitize_text_field( wp_unslash( tradutema_array_get( $_POST, 'telefono' ) ) ),
            'comentarios'        => wp_kses_post( wp_unslash( tradutema_array_get( $_POST, 'comentarios' ) ) ),
            'tarifa_minima_text' => sanitize_text_field( wp_unslash( tradutema_array_get( $_POST, 'tarifa_minima_text' ) ) ),
            'tarifa_interno'     => sanitize_text_field( wp_unslash( tradutema_array_get( $_POST, 'tarifa_interno' ) ) ),
            'pares_servicio'     => $pares_json,
            'datos_facturacion'  => wp_kses_post( wp_unslash( tradutema_array_get( $_POST, 'datos_facturacion' ) ) ),
            'direccion_recogida' => wp_kses_post( wp_unslash( tradutema_array_get( $_POST, 'direccion_recogida' ) ) ),
            'interno'            => isset( $_POST['interno'] ) ? 1 : 0,
        );

        if ( empty( $data['nombre_comercial'] ) ) {
            add_settings_error(
                'tradutema-crm',
                'missing_nombre',
                __( 'Debes indicar un nombre comercial.', 'tradutema-crm' ),
                'error'
            );
            $this->redirect_back( 'tradutema-crm-proveedores' );
        }

        $saved = $this->save_proveedor( $data );

        if ( $saved ) {
            add_settings_error(
                'tradutema-crm',
                'proveedor_saved',
                __( 'Proveedor guardado correctamente.', 'tradutema-crm' ),
                'updated'
            );
        } else {
            add_settings_error(
                'tradutema-crm',
                'proveedor_not_saved',
                __( 'No se pudo guardar el proveedor.', 'tradutema-crm' ),
                'error'
            );
        }

        $this->redirect_back( 'tradutema-crm-proveedores' );
    }

    /**
     * Maneja el borrado de un proveedor.
     */
    private function handle_delete_proveedor() {
        check_admin_referer( 'tradutema_crm_delete_proveedor' );

        $proveedor_id = isset( $_POST['proveedor_id'] ) ? absint( $_POST['proveedor_id'] ) : 0;

        if ( ! $proveedor_id ) {
            $this->redirect_back( 'tradutema-crm-proveedores' );
        }

        if ( $this->proveedor_has_orders( $proveedor_id ) ) {
            add_settings_error(
                'tradutema-crm',
                'proveedor_has_orders',
                __( 'No se puede eliminar un proveedor con pedidos asignados.', 'tradutema-crm' ),
                'error'
            );
            $this->redirect_back( 'tradutema-crm-proveedores' );
        }

        $deleted = $this->delete_proveedor( $proveedor_id );

        if ( $deleted ) {
            add_settings_error(
                'tradutema-crm',
                'proveedor_deleted',
                __( 'Proveedor eliminado.', 'tradutema-crm' ),
                'updated'
            );
        } else {
            add_settings_error(
                'tradutema-crm',
                'proveedor_not_deleted',
                __( 'No se pudo eliminar el proveedor.', 'tradutema-crm' ),
                'error'
            );
        }

        $this->redirect_back( 'tradutema-crm-proveedores' );
    }

    /**
     * Maneja el guardado de plantillas de correo.
     */
    private function handle_save_email_template() {
        check_admin_referer( 'tradutema_crm_save_email_template' );

        $raw_recipients = wp_unslash( tradutema_array_get( $_POST, 'destinatarios' ) );
        $recipients     = $this->normalize_email_template_recipients( $raw_recipients );
        $raw_status     = wp_unslash( tradutema_array_get( $_POST, 'estado_operacional', '' ) );
        $estado_operacional = '';

        if ( '' !== trim( (string) $raw_status ) ) {
            $estado_operacional = $this->normalize_operational_status_value( $raw_status );
        }

        if ( '' === $recipients ) {
            $recipients = '{{customer_email}}';
        }

        $data = array(
            'id'      => isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0,
            'nombre'  => sanitize_text_field( wp_unslash( tradutema_array_get( $_POST, 'nombre' ) ) ),
            'asunto'  => sanitize_text_field( wp_unslash( tradutema_array_get( $_POST, 'asunto' ) ) ),
            'destinatarios' => $recipients,
            'cuerpo'  => wp_kses_post( wp_unslash( tradutema_array_get( $_POST, 'cuerpo_html' ) ) ),
            'activo'  => isset( $_POST['activo'] ) ? 1 : 0,
            'estado_operacional' => $estado_operacional,
        );

        if ( empty( $data['nombre'] ) || empty( $data['asunto'] ) ) {
            add_settings_error(
                'tradutema-crm',
                'template_missing_fields',
                __( 'Debes indicar un nombre y un asunto para la plantilla.', 'tradutema-crm' ),
                'error'
            );
            $this->redirect_back( 'tradutema-crm-plantillas' );
        }

        $saved = $this->save_email_template( $data );

        if ( $saved ) {
            add_settings_error(
                'tradutema-crm',
                'template_saved',
                __( 'Plantilla guardada correctamente.', 'tradutema-crm' ),
                'updated'
            );
        } else {
            add_settings_error(
                'tradutema-crm',
                'template_not_saved',
                __( 'No se pudo guardar la plantilla.', 'tradutema-crm' ),
                'error'
            );
        }

        $this->redirect_back( 'tradutema-crm-plantillas' );
    }

    /**
     * Normaliza los destinatarios introducidos para una plantilla de email.
     *
     * Acepta direcciones separadas por comas, saltos de línea o punto y coma y
     * mantiene tanto emails válidos como placeholders del estilo {{placeholder}}.
     *
     * @param string $raw_recipients Cadena original introducida por el usuario.
     * @return string Lista de destinatarios lista para almacenar.
     */
    private function normalize_email_template_recipients( $raw_recipients ) {
        $raw_recipients = (string) $raw_recipients;

        if ( '' === trim( $raw_recipients ) ) {
            return '';
        }

        $raw_recipients = str_replace( array( "\r\n", "\r", ';' ), "\n", $raw_recipients );
        $candidates      = preg_split( '/[\n,]+/', $raw_recipients );
        $normalized      = array();

        foreach ( (array) $candidates as $candidate ) {
            $candidate = trim( (string) $candidate );

            if ( '' === $candidate ) {
                continue;
            }

            if ( preg_match( '/^\{\{[A-Za-z0-9_.-]+\}\}$/', $candidate ) ) {
                $normalized[] = $candidate;
                continue;
            }

            $email = sanitize_email( $candidate );

            if ( '' !== $email ) {
                $normalized[] = $email;
            }
        }

        if ( empty( $normalized ) ) {
            return '';
        }

        $normalized = array_values( array_unique( $normalized ) );

        return implode( "\n", $normalized );
    }

    /**
     * Maneja el borrado de plantillas de correo.
     */
    private function handle_delete_email_template() {
        check_admin_referer( 'tradutema_crm_delete_email_template' );

        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;

        if ( ! $template_id ) {
            $this->redirect_back( 'tradutema-crm-plantillas' );
        }

        $deleted = $this->delete_email_template( $template_id );

        if ( $deleted ) {
            add_settings_error(
                'tradutema-crm',
                'template_deleted',
                __( 'Plantilla eliminada.', 'tradutema-crm' ),
                'updated'
            );
        } else {
            add_settings_error(
                'tradutema-crm',
                'template_not_deleted',
                __( 'No se pudo eliminar la plantilla.', 'tradutema-crm' ),
                'error'
            );
        }

        $this->redirect_back( 'tradutema-crm-plantillas' );
    }

    /**
     * Maneja la actualización de datos personalizados del pedido.
     */
    private function handle_update_order() {
        check_admin_referer( 'tradutema_crm_update_order' );

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

        if ( ! $order_id ) {
            $this->redirect_back( 'tradutema-crm' );
        }

        $this->process_order_update_from_request( $order_id );

        add_settings_error(
            'tradutema-crm',
            'order_updated',
            __( 'Datos del pedido actualizados correctamente.', 'tradutema-crm' ),
            'updated'
        );

        $redirect = add_query_arg(
            array(
                'page'     => self::MANAGE_ORDER_PAGE,
                'order_id' => $order_id,
            ),
            admin_url( 'admin.php' )
        );

        $this->store_notices_for_redirect();

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Envía una plantilla de correo asociada a un pedido.
     */
    private function handle_send_email_template() {
        check_admin_referer( 'tradutema_crm_send_email_template' );

        $order_id    = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;

        if ( ! $order_id || ! $template_id ) {
            add_settings_error(
                'tradutema-crm',
                'email_missing_data',
                __( 'No se pudo preparar el envío de la plantilla seleccionada.', 'tradutema-crm' ),
                'error'
            );
            $this->redirect_to_manage_order( $order_id );
        }

        $template = $this->get_email_template( $template_id );

        if ( empty( $template ) || empty( $template['activo'] ) ) {
            add_settings_error(
                'tradutema-crm',
                'email_template_missing',
                __( 'La plantilla seleccionada no está disponible.', 'tradutema-crm' ),
                'error'
            );
            $this->redirect_to_manage_order( $order_id );
        }

        $was_order_updated = $this->maybe_update_order_before_email( $order_id );

        $order_details = $this->get_order_details( $order_id );

        if ( is_wp_error( $order_details ) || empty( $order_details['order'] ) || ! ( $order_details['order'] instanceof WC_Order ) ) {
            add_settings_error(
                'tradutema-crm',
                'email_order_missing',
                __( 'No se pudo cargar el pedido asociado.', 'tradutema-crm' ),
                'error'
            );
            $this->redirect_to_manage_order( $order_id );
        }

        $order = $order_details['order'];
        $meta  = isset( $order_details['meta'] ) && is_array( $order_details['meta'] ) ? $order_details['meta'] : array();
        $previous_operational_status = '';

        if ( isset( $meta['estado_operacional'] ) && '' !== $meta['estado_operacional'] ) {
            $previous_operational_status = $this->normalize_operational_status_value( $meta['estado_operacional'] );
        }

        $provider = null;
        if ( ! empty( $meta['proveedor_id'] ) ) {
            $provider = $this->get_proveedor( absint( $meta['proveedor_id'] ) );
        }

        $replacements = $this->prepare_email_placeholders( $order, $meta, $provider );

        $subject = $this->replace_email_placeholders( (string) tradutema_array_get( $template, 'asunto', '' ), $replacements );
        $content = $this->replace_email_placeholders( (string) tradutema_array_get( $template, 'cuerpo_html', '' ), $replacements );

        $subject = apply_filters( 'tradutema_crm_email_subject', $subject, $template, $order, $meta, $replacements );
        $content = apply_filters( 'tradutema_crm_email_content', $content, $template, $order, $meta, $replacements );

        $recipients        = array();
        $order_email       = sanitize_email( $order->get_billing_email() );
        $raw_template_to   = trim( (string) tradutema_array_get( $template, 'destinatarios', '' ) );

        if ( '' !== $raw_template_to ) {
            $resolved_recipients = $this->replace_email_placeholders( $raw_template_to, $replacements );
            $resolved_recipients = str_replace( array( "\r\n", "\r", ';' ), "\n", $resolved_recipients );
            $candidates          = preg_split( '/[\n,]+/', $resolved_recipients );

            foreach ( (array) $candidates as $candidate ) {
                $candidate = sanitize_email( trim( (string) $candidate ) );

                if ( $candidate ) {
                    $recipients[] = $candidate;
                }
            }
        }

        if ( empty( $recipients ) && $order_email ) {
            $recipients[] = $order_email;
        }

        $recipients = apply_filters( 'tradutema_crm_email_recipients', $recipients, $order, $template, $meta );
        $recipients = array_filter( array_map( 'sanitize_email', (array) $recipients ) );
        $recipients = array_values( array_unique( $recipients ) );

        if ( empty( $recipients ) ) {
            add_settings_error(
                'tradutema-crm',
                'email_recipient_missing',
                __( 'No hay destinatarios válidos para el envío.', 'tradutema-crm' ),
                'error'
            );
            $this->redirect_to_manage_order( $order_id );
        }

        $headers     = array( 'Content-Type: text/html; charset=UTF-8' );
        $headers     = apply_filters( 'tradutema_crm_email_headers', $headers, $order, $template, $meta );
        $attachments = apply_filters( 'tradutema_crm_email_attachments', array(), $order, $template, $meta );

        $sent = $this->send_email_via_solid_mail( $recipients, $subject, $content, $headers, $attachments );

        if ( $sent ) {
            $template_name = sanitize_text_field( tradutema_array_get( $template, 'nombre', '' ) );
            $this->log_order_event(
                $order_id,
                'email',
                sprintf(
                    /* translators: 1: email template name, 2: recipient list */
                    __( 'Plantilla "%1$s" enviada a %2$s.', 'tradutema-crm' ),
                    $template_name,
                    implode( ', ', $recipients )
                ),
                array(
                    'template_id'   => (int) tradutema_array_get( $template, 'id', 0 ),
                    'template_name' => $template_name,
                    'subject'       => $subject,
                    'recipients'    => $recipients,
                )
            );

            $target_operational_status = (string) tradutema_array_get( $template, 'estado_operacional', '' );

            if ( '' !== $target_operational_status ) {
                $target_operational_status = $this->normalize_operational_status_value( $target_operational_status );
                $this->update_order_operational_status( $order_id, $target_operational_status );

                if ( $previous_operational_status !== $target_operational_status ) {
                    $previous_label = '' !== $previous_operational_status ? $this->get_operational_status_label( $previous_operational_status ) : __( 'Sin definir', 'tradutema-crm' );
                    $current_label  = $this->get_operational_status_label( $target_operational_status );

                    $this->log_order_event(
                        $order_id,
                        'estado_operacional',
                        sprintf(
                            /* translators: %s: operational status label */
                            __( 'Estado operacional actualizado automáticamente a %s.', 'tradutema-crm' ),
                            $current_label
                        ),
                        array(
                            'previous_status' => array(
                                'key'   => '' !== $previous_operational_status ? $previous_operational_status : null,
                                'label' => $previous_label,
                            ),
                            'current_status'  => array(
                                'key'   => $target_operational_status,
                                'label' => $current_label,
                            ),
                            'source'         => 'email_template',
                            'template_id'    => (int) tradutema_array_get( $template, 'id', 0 ),
                        )
                    );
                }
            }

            add_settings_error(
                'tradutema-crm',
                'email_sent',
                sprintf(
                    /* translators: 1: email template name, 2: recipient list */
                    __( 'La plantilla "%1$s" se envió correctamente a %2$s.', 'tradutema-crm' ),
                    $template_name,
                    implode( ', ', $recipients )
                ),
                'updated'
            );
        } else {
            add_settings_error(
                'tradutema-crm',
                'email_send_error',
                __( 'Se produjo un error al enviar el email. Inténtalo de nuevo más tarde.', 'tradutema-crm' ),
                'error'
            );
        }

        $this->redirect_to_manage_order( $order_id );
    }

    /**
     * Recupera los datos enviados desde el formulario de pedido listos para guardar.
     *
     * @return array
     */
    private function get_order_update_data_from_request() {
        return array(
            'proveedor_id'            => isset( $_POST['proveedor_id'] ) ? absint( $_POST['proveedor_id'] ) : 0,
            'comentario_interno'      => wp_kses_post( wp_unslash( tradutema_array_get( $_POST, 'comentario_interno' ) ) ),
            'comentario_linguistico'  => wp_kses_post( wp_unslash( tradutema_array_get( $_POST, 'comentario_linguistico' ) ) ),
            'referencia'              => sanitize_text_field( wp_unslash( tradutema_array_get( $_POST, 'referencia' ) ) ),
            'envio_papel'             => isset( $_POST['envio_papel'] ) && '1' === (string) wp_unslash( $_POST['envio_papel'] ) ? 1 : 0,
            'estado_operacional'      => sanitize_text_field( wp_unslash( tradutema_array_get( $_POST, 'estado_operacional', 'recibido' ) ) ),
            'fecha_prevista_entrega'  => sanitize_text_field( wp_unslash( tradutema_array_get( $_POST, 'fecha_prevista_entrega' ) ) ),
            'hora_prevista_entrega'   => sanitize_text_field( wp_unslash( tradutema_array_get( $_POST, 'hora_prevista_entrega' ) ) ),
            'fecha_real_entrega_pdf'  => wp_unslash( tradutema_array_get( $_POST, 'fecha_real_entrega_pdf' ) ),
        );
    }

    /**
     * Guarda y registra los cambios de un pedido a partir de la petición actual.
     *
     * @param int $order_id ID del pedido.
     * @return array{
     *   changes: array,
     *   detail_message: string,
     * }
     */
    private function process_order_update_from_request( $order_id ) {
        $previous_meta = $this->get_order_meta( $order_id );
        $data          = $this->get_order_update_data_from_request();

        $this->save_order_meta( $order_id, $data );

        $updated_meta    = $this->get_order_meta( $order_id );
        $changes         = $this->describe_order_meta_changes( $previous_meta, $updated_meta );
        $changed_labels  = wp_list_pluck( $changes, 'label' );
        $detail_message  = __( 'Datos del pedido actualizados desde el panel.', 'tradutema-crm' );

        if ( ! empty( $changed_labels ) ) {
            $detail_message = sprintf(
                /* translators: %s: comma separated list of updated fields */
                __( 'Datos del pedido actualizados: %s.', 'tradutema-crm' ),
                implode( ', ', $changed_labels )
            );
        } elseif ( ! empty( $_POST ) ) {
            $detail_message = __( 'Formulario de pedido guardado sin cambios.', 'tradutema-crm' );
        }

        $payload = array();

        if ( ! empty( $changes ) ) {
            $payload['changes'] = $changes;
        }

        $this->log_order_event( $order_id, 'order_update', $detail_message, $payload );

        return array(
            'changes'        => $changes,
            'detail_message' => $detail_message,
        );
    }

    /**
     * Actualiza el pedido antes de enviar una plantilla de correo si la petición es válida.
     *
     * @param int $order_id ID del pedido.
     * @return array|null Resultado de la actualización o null si no se procesó.
     */
    private function maybe_update_order_before_email( $order_id ) {
        if ( empty( $_POST['update_order_nonce'] ) ) {
            return null;
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['update_order_nonce'] ) );

        if ( ! wp_verify_nonce( $nonce, 'tradutema_crm_update_order' ) ) {
            return null;
        }

        $update_result = $this->process_order_update_from_request( $order_id );

        add_settings_error(
            'tradutema-crm',
            'order_updated_before_email',
            __( 'Datos del pedido guardados antes de enviar la plantilla.', 'tradutema-crm' ),
            'updated'
        );

        return $update_result;
    }

    /**
     * Guarda un proveedor.
     *
     * @param array $data Datos del proveedor.
     * @return int|false
     */
    private function save_proveedor( array $data ) {
        global $wpdb;

        $this->ensure_proveedor_extended_fields();

        $table = $wpdb->prefix . 'ttm_proveedores';
        $fields = array(
            'nombre_comercial'   => $data['nombre_comercial'],
            'email'              => $data['email'],
            'telefono'           => $data['telefono'],
            'comentarios'        => $data['comentarios'],
            'tarifa_minima_text' => '' !== $data['tarifa_minima_text'] ? $data['tarifa_minima_text'] : null,
            'tarifa_interno'     => '' !== $data['tarifa_interno'] ? $data['tarifa_interno'] : null,
            'pares_servicio'     => ( ! empty( $data['pares_servicio'] ) && '[]' !== $data['pares_servicio'] ) ? $data['pares_servicio'] : null,
            'datos_facturacion'  => $this->normalize_long_text_field( $data['datos_facturacion'] ),
            'direccion_recogida' => $this->normalize_long_text_field( $data['direccion_recogida'] ),
            'interno'            => ! empty( $data['interno'] ) ? 1 : 0,
        );

        if ( ! empty( $data['id'] ) ) {
            $updated = $wpdb->update( $table, $fields, array( 'id' => absint( $data['id'] ) ) );
            if ( false !== $updated ) {
                $this->cached_proveedores = null;
            }

            return false === $updated ? false : absint( $data['id'] );
        }

        $inserted = $wpdb->insert( $table, $fields );

        if ( $inserted ) {
            $this->cached_proveedores = null;
        }

        return $inserted ? $wpdb->insert_id : false;
    }

    /**
     * Elimina un proveedor.
     *
     * @param int $proveedor_id ID del proveedor.
     * @return bool
     */
    private function delete_proveedor( $proveedor_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'ttm_proveedores';

        $deleted = (bool) $wpdb->delete( $table, array( 'id' => $proveedor_id ) );

        if ( $deleted ) {
            $this->cached_proveedores = null;
        }

        return $deleted;
    }

    /**
     * Obtiene la lista de proveedores ordenados alfabéticamente.
     *
     * @return array
     */
    private function get_proveedores() {
        global $wpdb;

        if ( null !== $this->cached_proveedores ) {
            return $this->cached_proveedores;
        }

        $this->ensure_proveedor_extended_fields();

        $table = $wpdb->prefix . 'ttm_proveedores';

        $this->cached_proveedores = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY nombre_comercial ASC", ARRAY_A );

        return $this->cached_proveedores;
    }

    /**
     * Obtiene la lista de proveedores indexada por ID.
     *
     * @return array
     */
    private function get_proveedores_indexed() {
        $proveedores = $this->get_proveedores();
        $indexed     = array();

        foreach ( $proveedores as $proveedor ) {
            $indexed[ $proveedor['id'] ] = $proveedor;
        }

        return $indexed;
    }

    /**
     * Devuelve un proveedor concreto.
     *
     * @param int $proveedor_id ID del proveedor.
     * @return array|null
     */
    private function get_proveedor( $proveedor_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'ttm_proveedores';

        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $proveedor_id ), ARRAY_A );
    }

    /**
     * Devuelve el número de pedidos asociados a cada proveedor.
     *
     * @return array<int, int>
     */
    private function get_proveedores_order_counts() {
        global $wpdb;

        $table   = $wpdb->prefix . 'ttm_order_meta';
        $results = $wpdb->get_results( "SELECT proveedor_id, COUNT(*) AS total FROM {$table} WHERE proveedor_id IS NOT NULL AND proveedor_id > 0 GROUP BY proveedor_id", ARRAY_A );

        $counts = array();

        foreach ( $results as $row ) {
            $proveedor_id = isset( $row['proveedor_id'] ) ? (int) $row['proveedor_id'] : 0;

            if ( $proveedor_id <= 0 ) {
                continue;
            }

            $counts[ $proveedor_id ] = isset( $row['total'] ) ? (int) $row['total'] : 0;
        }

        return $counts;
    }

    /**
     * Comprueba si un proveedor tiene pedidos asociados.
     *
     * @param int              $proveedor_id  ID del proveedor.
     * @param array<int, int>|null $usage_counts Conteo de pedidos por proveedor.
     * @return bool
     */
    private function proveedor_has_orders( $proveedor_id, ?array $usage_counts = null ) {
        $proveedor_id = absint( $proveedor_id );

        if ( $proveedor_id <= 0 ) {
            return false;
        }

        if ( null === $usage_counts ) {
            $usage_counts = $this->get_proveedores_order_counts();
        }

        return isset( $usage_counts[ $proveedor_id ] ) && $usage_counts[ $proveedor_id ] > 0;
    }

    /**
     * Decodifica y normaliza la lista de pares de traducción.
     *
     * @param mixed $value Valor almacenado en base de datos o enviado desde el formulario.
     * @return array<int, array{source:string,target:string}>
     */
    private function decode_translation_pairs( $value ) {
        if ( empty( $value ) ) {
            return array();
        }

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
        } elseif ( is_array( $value ) ) {
            $decoded = $value;
        } else {
            $decoded = array();
        }

        if ( ! is_array( $decoded ) ) {
            return array();
        }

        $normalized = array();

        foreach ( $decoded as $pair ) {
            if ( ! is_array( $pair ) ) {
                continue;
            }

            $source = isset( $pair['source'] ) ? sanitize_text_field( $pair['source'] ) : '';
            $target = isset( $pair['target'] ) ? sanitize_text_field( $pair['target'] ) : '';

            if ( '' === $source && '' === $target ) {
                continue;
            }

            $normalized[] = array(
                'source' => $source,
                'target' => $target,
            );
        }

        return $normalized;
    }

    /**
     * Comprueba si un proveedor ofrece un par de idiomas concreto.
     *
     * @param array  $proveedor  Datos del proveedor.
     * @param string $origin     Idioma de origen.
     * @param string $destination Idioma de destino.
     * @return bool
     */
    private function proveedor_supports_language_pair( array $proveedor, $origin, $destination ) {
        $origin      = trim( (string) $origin );
        $destination = trim( (string) $destination );

        if ( '' === $origin || '' === $destination ) {
            return false;
        }

        $pairs = $this->decode_translation_pairs( tradutema_array_get( $proveedor, 'pares_servicio', array() ) );

        foreach ( $pairs as $pair ) {
            $pair_origin      = trim( (string) tradutema_array_get( $pair, 'source', '' ) );
            $pair_destination = trim( (string) tradutema_array_get( $pair, 'target', '' ) );

            if ( '' === $pair_origin || '' === $pair_destination ) {
                continue;
            }

            if ( 0 === strcasecmp( $origin, $pair_origin ) && 0 === strcasecmp( $destination, $pair_destination ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filtra proveedores en función de un par de idiomas.
     *
     * @param array  $proveedores Lista completa de proveedores.
     * @param string $origin      Idioma de origen.
     * @param string $destination Idioma de destino.
     * @return array
     */
    private function filter_proveedores_by_language_pair( array $proveedores, $origin, $destination ) {
        $origin      = trim( (string) $origin );
        $destination = trim( (string) $destination );

        if ( '' === $origin || '' === $destination ) {
            return $proveedores;
        }

        $filtered = array();

        foreach ( $proveedores as $proveedor ) {
            if ( $this->proveedor_supports_language_pair( $proveedor, $origin, $destination ) ) {
                $filtered[] = $proveedor;
            }
        }

        return $filtered;
    }

    /**
     * Formatea los pares de traducción para mostrarlos en la tabla.
     *
     * @param array<int, array{source:string,target:string}> $pairs Lista de pares.
     * @return string
     */
    private function format_translation_pairs_summary( array $pairs ) {
        if ( empty( $pairs ) ) {
            return '';
        }

        $formatted = array();

        foreach ( $pairs as $pair ) {
            $source = isset( $pair['source'] ) ? $pair['source'] : '';
            $target = isset( $pair['target'] ) ? $pair['target'] : '';

            if ( '' !== $source && '' !== $target ) {
                /* translators: %1$s: source language. %2$s: target language. */
                $formatted[] = sprintf( __( '%1$s → %2$s', 'tradutema-crm' ), $source, $target );
            } elseif ( '' !== $source || '' !== $target ) {
                $formatted[] = '' !== $source ? $source : $target;
            }
        }

        return implode( ', ', $formatted );
    }

    /**
     * Normaliza campos de texto largo para su almacenamiento en base de datos.
     *
     * @param string $value Valor original.
     * @return string|null
     */
    private function normalize_long_text_field( $value ) {
        if ( ! is_string( $value ) ) {
            $value = '';
        }

        if ( '' === trim( wp_strip_all_tags( $value ) ) ) {
            return null;
        }

        return $value;
    }

    /**
     * Asegura que las columnas personalizadas del proveedor existan en la base de datos.
     */
    private function ensure_proveedor_extended_fields() {
        global $wpdb;

        $table   = $wpdb->prefix . 'ttm_proveedores';
        $columns = array(
            'tarifa_minima_text' => 'TEXT NULL',
            'tarifa_interno'     => 'TEXT NULL',
            'interno'            => 'TINYINT(1) NOT NULL DEFAULT 0',
            'pares_servicio'     => 'LONGTEXT NULL',
            'datos_facturacion'  => 'LONGTEXT NULL',
            'direccion_recogida' => 'LONGTEXT NULL',
        );

        foreach ( $columns as $column => $definition ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

            if ( ! $exists ) {
                $wpdb->query( "ALTER TABLE {$table} ADD {$column} {$definition}" );
            }
        }
    }

    /**
     * Asegura que la columna de destinatarios existe en las plantillas de email.
     */
    private function ensure_email_template_recipients_field() {
        global $wpdb;

        $table   = $wpdb->prefix . 'ttm_email_templates';
        $columns = array(
            'destinatarios'     => 'TEXT NULL',
            'estado_operacional' => 'VARCHAR(30) NULL',
        );

        foreach ( $columns as $column => $definition ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

            if ( ! $exists ) {
                $wpdb->query( "ALTER TABLE {$table} ADD {$column} {$definition}" );
            }
        }

        $legacy_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'para' ) );

        if ( $legacy_column ) {
            $wpdb->query( "UPDATE {$table} SET destinatarios = para WHERE (destinatarios IS NULL OR destinatarios = '') AND (para IS NOT NULL AND para <> '')" );
        }
    }

    /**
     * Resuelve el nombre del proveedor asociado a un pedido.
     *
     * @param array $meta               Metadatos del pedido.
     * @param array $proveedores_index  Lista de proveedores indexados.
     * @return string
     */
    private function resolve_proveedor_name( array $meta, array $proveedores_index ) {
        if ( empty( $meta['proveedor_id'] ) ) {
            return '';
        }

        $proveedor_id = absint( $meta['proveedor_id'] );

        return isset( $proveedores_index[ $proveedor_id ] ) ? $proveedores_index[ $proveedor_id ]['nombre_comercial'] : '';
    }

    /**
     * Prepara los valores disponibles para las plantillas de correo.
     *
     * @param WC_Order   $order    Pedido de WooCommerce.
     * @param array      $meta     Datos internos del pedido.
     * @param array|null $provider Datos del proveedor asociado.
     * @return array<string, string>
     */
    private function prepare_email_placeholders( WC_Order $order, array $meta, ?array $provider ) {
        $customer_name = trim( $order->get_formatted_billing_full_name() );

        if ( '' === $customer_name ) {
            $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        }

        if ( '' === $customer_name ) {
            $customer_name = trim( $order->get_billing_company() );
        }

        $statuses    = tradutema_crm_operational_statuses();
        $status_key  = isset( $meta['estado_operacional'] ) ? $meta['estado_operacional'] : 'recibido';
        $status_label = isset( $statuses[ $status_key ] ) ? $statuses[ $status_key ] : $status_key;

        $date_value = trim( (string) tradutema_array_get( $meta, 'fecha_prevista_entrega', '' ) );
        $time_value = trim( (string) tradutema_array_get( $meta, 'hora_prevista_entrega', '' ) );

        if ( '' === $date_value ) {
            $date_value = $this->find_order_item_meta_value( $order, array( 'Fecha', 'Fecha de entrega', 'Fecha de Entrega' ) );
        }

        if ( '' === $time_value ) {
            $time_value = $this->find_order_item_meta_value( $order, array( 'Hora', 'Hora de entrega', 'Hora de Entrega' ) );
        }

        list( $expected_raw_value, $expected_has_time ) = $this->prepare_datetime_value( $date_value, $time_value );
        $expected_date = '';

        if ( '' !== $expected_raw_value ) {
            if ( $expected_has_time ) {
                $timestamp = $this->get_datetime_timestamp( $expected_raw_value );

                if ( null !== $timestamp ) {
                    $expected_date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
                } else {
                    $expected_date = trim( $expected_raw_value );
                }
            } else {
                $expected_date = $this->format_display_date( $expected_raw_value, false );
            }
        }

        $idioma_origen = trim( (string) tradutema_array_get( $meta, 'idioma_origen', '' ) );

        if ( '' === $idioma_origen ) {
            $idioma_origen = $this->find_order_item_meta_value(
                $order,
                array( '¿En qué idioma está el documento?', 'Idioma origen', 'Idioma del documento' )
            );
        }

        $idioma_destino = trim( (string) tradutema_array_get( $meta, 'idioma_destino', '' ) );

        if ( '' === $idioma_destino ) {
            $idioma_destino = $this->find_order_item_meta_value(
                $order,
                array( '¿A qué idioma quieres traducirlo?', 'Idioma destino' )
            );
        }

        $provider_name            = '';
        $provider_email           = '';
        $provider_pickup_address  = '';

        if ( is_array( $provider ) ) {
            $provider_name  = trim( (string) tradutema_array_get( $provider, 'nombre_comercial', '' ) );
            $provider_email = sanitize_email( tradutema_array_get( $provider, 'email', '' ) );
            $provider_pickup_address = wp_strip_all_tags( (string) tradutema_array_get( $provider, 'direccion_recogida', '' ) );
        }

        $pages_value = trim( (string) tradutema_array_get( $meta, 'num_paginas', '' ) );

        if ( '' === $pages_value ) {
            $pages_value = $this->find_order_item_meta_value(
                $order,
                array( '¿Cuántas páginas tiene?', 'Número de páginas', 'Paginas' )
            );
        }

        $pages_value = wp_strip_all_tags( (string) $pages_value );

        $real_delivery_value = trim( (string) tradutema_array_get( $meta, 'fecha_real_entrega_pdf', '' ) );
        $real_delivery_label = '' !== $real_delivery_value
            ? $this->format_display_date( $real_delivery_value, true )
            : '';

        $internal_comment   = wp_strip_all_tags( (string) tradutema_array_get( $meta, 'comentario_interno', '' ) );
        $customer_comments  = wp_strip_all_tags( (string) $order->get_customer_note() );
        $order_email  = sanitize_email( $order->get_billing_email() );
        $drive_links  = $this->resolve_order_drive_links( $order );
        $upload_link  = $this->generate_upload_to_client_link( $order );
        $paper_required = $this->order_requires_paper_delivery( $order, $meta );
        $paper_shipping_address = $paper_required
            ? $this->build_shipping_address_for_display( $order, $paper_required )
            : '';

        $replacements = array(
            '{{order_id}}'                   => (string) $order->get_order_number(),
            '{{customer_name}}'              => $customer_name,
            '{{customer_email}}'             => $order_email ? $order_email : '',
            '{{order_total}}'                => wp_strip_all_tags( $order->get_formatted_order_total() ),
            '{{comentarios_cliente}}'        => $customer_comments,
            '{{estado_operacional}}'         => $status_label,
            '{{fecha_prevista}}'             => $expected_date,
            '{{tipo_envio}}'                 => $this->resolve_order_shipping_type( $order, $meta ),
            '{{comentario_interno}}'         => $internal_comment,
            '{{comentario_linguistico}}'     => wp_strip_all_tags( (string) tradutema_array_get( $meta, 'comentario_linguistico', '' ) ),
            '{{idioma_origen}}'              => $idioma_origen,
            '{{idioma_destino}}'             => $idioma_destino,
            '{{num_paginas}}'                => $pages_value,
            '{{proveedor.nombre_comercial}}' => $provider_name,
            '{{proveedor.email}}'            => $provider_email,
            '{{proveedor.direccion_recogida}}' => $provider_pickup_address,
            '{{gdrive_link_full_folder}}'    => isset( $drive_links['full_folder'] ) ? $drive_links['full_folder'] : '',
            '{{gdrive_link_source}}'         => isset( $drive_links['source'] ) ? $drive_links['source'] : '',
            '{{gdrive_link_work}}'           => isset( $drive_links['work'] ) ? $drive_links['work'] : '',
            '{{gdrive_link_translation}}'    => isset( $drive_links['translation'] ) ? $drive_links['translation'] : '',
            '{{gdrive_link_To_Client}}'      => isset( $drive_links['to_client'] ) ? $drive_links['to_client'] : '',
            '{{Upload_To_Client}}'           => $upload_link,
            '{{fecha_real_entrega_proveedor}}' => $real_delivery_label,
            '{{direccion_entrega_papel}}'    => $paper_shipping_address,
        );

        $replacements = array_map(
            static function ( $value ) {
                return is_scalar( $value ) ? (string) $value : '';
            },
            $replacements
        );

        return apply_filters( 'tradutema_crm_email_placeholders', $replacements, $order, $meta, $provider );
    }

    /**
     * Genera un enlace tokenizado para que el proveedor suba ficheros finales.
     *
     * @param WC_Order $order Pedido de WooCommerce.
     * @return string
     */
    private function generate_upload_to_client_link( WC_Order $order ) {
        $order_id = $order->get_id();

        if ( ! $order_id ) {
            return '';
        }

        $token = $this->create_provider_upload_token( $order );

        if ( is_wp_error( $token ) ) {
            return '';
        }

        $url = add_query_arg(
            array( self::PROVIDER_UPLOAD_QUERY_VAR => $token ),
            home_url( '/' )
        );

        return $url ? $url : '';
    }

    /**
     * Crea y almacena el token asociado a la subida de ficheros para un pedido.
     *
     * @param WC_Order $order Pedido de WooCommerce.
     * @return string|WP_Error
     */
    private function create_provider_upload_token( WC_Order $order ) {
        $order_id = $order->get_id();

        if ( ! $order_id ) {
            return new WP_Error( 'tradutema_upload_invalid_order', __( 'No se pudo generar el enlace de subida para este pedido.', 'tradutema-crm' ) );
        }

        $attempt     = 0;
        $max_attempt = 5;
        $created     = false;
        $token       = '';

        while ( $attempt < $max_attempt && ! $created ) {
            $attempt++;
            $token      = wp_generate_password( 32, false, false );
            $option_key = $this->get_provider_upload_option_key( $token );

            $created = add_option(
                $option_key,
                array(
                    'order_id'   => $order_id,
                    'created_at' => current_time( 'timestamp' ),
                    'used'       => false,
                ),
                '',
                'no'
            );
        }

        if ( ! $created || '' === $token ) {
            return new WP_Error( 'tradutema_upload_token_failed', __( 'No se pudo generar el enlace de subida en estos momentos. Vuelve a intentarlo más tarde.', 'tradutema-crm' ) );
        }

        $history = $this->get_provider_upload_history( $order_id );

        $history[ $token ] = array(
            'created_at' => current_time( 'timestamp' ),
            'used_at'    => null,
        );

        $this->store_provider_upload_history( $order_id, $history );

        return $token;
    }

    /**
     * Devuelve la clave de opción usada para almacenar datos de un token.
     *
     * @param string $token Token generado.
     * @return string
     */
    private function get_provider_upload_option_key( $token ) {
        return self::PROVIDER_UPLOAD_OPTION_PREFIX . $token;
    }

    /**
     * Resuelve la información asociada a un token de subida.
     *
     * @param string $token Token recibido.
     * @return array|WP_Error
     */
    private function resolve_provider_upload_token( $token ) {
        $token = trim( (string) $token );

        if ( '' === $token ) {
            return new WP_Error( 'tradutema_upload_token_invalid', __( 'El enlace proporcionado no es válido.', 'tradutema-crm' ) );
        }

        $option_key = $this->get_provider_upload_option_key( $token );
        $data       = get_option( $option_key, null );

        if ( null === $data || ! is_array( $data ) || empty( $data['order_id'] ) ) {
            return new WP_Error( 'tradutema_upload_token_missing', __( 'El enlace proporcionado no es válido o ya no está disponible.', 'tradutema-crm' ) );
        }

        $data['token'] = $token;

        return $data;
    }

    /**
     * Marca un token como utilizado e informa del resultado en el pedido.
     *
     * @param int   $order_id       ID del pedido.
     * @param string $token         Token utilizado.
     * @param array  $uploaded_files Archivos subidos correctamente.
     */
    private function mark_provider_upload_token_as_used( $order_id, $token, array $uploaded_files ) {
        $option_key = $this->get_provider_upload_option_key( $token );
        $data       = get_option( $option_key, null );
        $now        = current_time( 'timestamp' );

        if ( is_array( $data ) ) {
            $data['used']    = true;
            $data['used_at'] = $now;
            $data['files']   = array();

            foreach ( $uploaded_files as $file ) {
                if ( isset( $file['name'] ) ) {
                    $data['files'][] = (string) $file['name'];
                }
            }

            update_option( $option_key, $data );
        }

        $history = $this->get_provider_upload_history( $order_id );

        if ( isset( $history[ $token ] ) ) {
            $history[ $token ]['used_at'] = $now;
        } else {
            $history[ $token ] = array(
                'created_at' => $now,
                'used_at'    => $now,
            );
        }

        $this->store_provider_upload_history( $order_id, $history );
    }

    /**
     * Devuelve la clave de opción usada para historiales de enlaces de subida.
     *
     * @param int $order_id ID del pedido.
     * @return string
     */
    private function get_provider_upload_history_option_key( $order_id ) {
        return 'tradutema_crm_upload_history_' . absint( $order_id );
    }

    /**
     * Recupera el historial de enlaces de subida sin alterar datos del pedido.
     *
     * @param int $order_id ID del pedido.
     * @return array
     */
    private function get_provider_upload_history( $order_id ) {
        $option_key = $this->get_provider_upload_history_option_key( $order_id );
        $history    = get_option( $option_key, null );

        if ( null === $history ) {
            $legacy_history = get_post_meta( $order_id, '_tradutema_crm_upload_tokens', true );
            $history        = is_array( $legacy_history ) ? $legacy_history : array();
        }

        return is_array( $history ) ? $history : array();
    }

    /**
     * Guarda el historial de enlaces de subida en opciones para no modificar WooCommerce.
     *
     * @param int   $order_id ID del pedido.
     * @param array $history  Historial de tokens.
     */
    private function store_provider_upload_history( $order_id, array $history ) {
        $option_key = $this->get_provider_upload_history_option_key( $order_id );
        update_option( $option_key, $history, false );
    }

    /**
     * Procesa el formulario enviado por el proveedor.
     *
     * @param WC_Order $order      Pedido asociado.
     * @param string   $token      Token recibido.
     * @param array    $token_data Datos del token almacenados.
     * @return array<int, array<string,string>>|WP_Error
     */
    private function handle_provider_upload_submission( WC_Order $order, $token, array $token_data ) {
        $meta     = $this->get_order_meta( $order->get_id() );
        $provider = array();

        if ( ! empty( $meta['proveedor_id'] ) ) {
            $provider = $this->get_proveedor( absint( $meta['proveedor_id'] ) );

            if ( ! is_array( $provider ) ) {
                $provider = array();
            }
        }

        $is_internal_provider = ! empty( $provider['interno'] );

        $nonce = isset( $_POST['tradutema_upload_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tradutema_upload_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! wp_verify_nonce( $nonce, 'tradutema_upload_' . $token ) ) {
            return new WP_Error( 'tradutema_upload_nonce', __( 'No se pudo validar el formulario enviado. Recarga la página e inténtalo de nuevo.', 'tradutema-crm' ) );
        }

        if ( $is_internal_provider ) {
            $this->mark_provider_upload_token_as_used( $order->get_id(), $token, array() );
            $this->process_internal_provider_upload( $order, $meta );

            $this->log_order_event(
                $order->get_id(),
                'internal_provider_completion',
                __( 'El proveedor interno confirmó la finalización mediante el enlace seguro.', 'tradutema-crm' ),
                array(
                    'token' => $token,
                )
            );

            return array();
        }

        $files_input = isset( $_FILES['tradutema_files'] ) ? $_FILES['tradutema_files'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $prepared    = $this->prepare_provider_upload_files( $files_input );

        if ( is_wp_error( $prepared ) ) {
            return $prepared;
        }

        if ( empty( $prepared ) ) {
            return new WP_Error( 'tradutema_upload_empty', __( 'Selecciona al menos un fichero para subir.', 'tradutema-crm' ) );
        }

        $target_folder_key = $is_internal_provider ? self::TRANSLATION_FOLDER_KEY : self::TO_CLIENT_FOLDER_KEY;

        $uploaded = $this->upload_provider_files_to_drive( $order->get_id(), $prepared, $target_folder_key );

        if ( is_wp_error( $uploaded ) ) {
            return $uploaded;
        }

        $this->mark_provider_upload_token_as_used( $order->get_id(), $token, $uploaded );

        $file_names = array();

        foreach ( $uploaded as $file ) {
            if ( isset( $file['name'] ) && '' !== $file['name'] ) {
                $file_names[] = $file['name'];
            }
        }

        $this->log_order_event(
            $order->get_id(),
            'files_upload',
            __( 'El proveedor subió los ficheros finales mediante el enlace seguro.', 'tradutema-crm' ),
            array(
                'token' => $token,
                'files' => $file_names,
            )
        );

        $this->handle_provider_upload_side_effects( $order, $meta, $provider, $is_internal_provider );

        return $uploaded;
    }

    /**
     * Ejecuta las acciones posteriores a una subida de ficheros por parte del proveedor.
     *
     * @param WC_Order $order                Pedido asociado.
     * @param array    $meta                 Metadatos del pedido.
     * @param array    $provider             Datos del proveedor asignado.
     * @param bool     $is_internal_provider Si el proveedor es interno.
     */
    private function handle_provider_upload_side_effects( WC_Order $order, array $meta, array $provider, $is_internal_provider ) {
        if ( $is_internal_provider ) {
            $this->process_internal_provider_upload( $order, $meta );
            return;
        }

        $this->process_external_provider_upload( $order, $meta );
    }

    /**
     * Envuelve el contenido de un email con la plantilla corporativa de Tradutema.
     *
     * @param string $body_content Contenido principal del mensaje.
     * @param string $title        Título para la etiqueta `<title>`.
     *
     * @return string
     */
    private function build_branded_email_layout( $body_content, $title = '' ) {
        $email_title = '' !== trim( $title ) ? wp_strip_all_tags( $title ) : __( 'Cotización de pedido', 'tradutema-crm' );
        $content     = trim( (string) $body_content );

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo esc_html( $email_title ); ?></title>
</head>
<body style="margin:0; padding:0; background:#f5f7fb; font-family:Arial,sans-serif;">
  <!-- CABECERA -->
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#007BFF">
    <tr>
      <td align="center" style="padding:20px 0">
        <img src="https://dev.tradutema.com/wp-content/uploads/2025/07/logo-tradutema-cabecera.png" alt="Tradutema" width="200" style="display:block" />
      </td>
    </tr>
  </table>
  <!-- CONTENIDO -->
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:6px;overflow:hidden">
    <tr>
      <td style="padding:25px;font-size:15px;line-height:1.6">
        <?php echo wp_kses_post( $content ); ?>
      </td>
    </tr>
  </table>
  <!-- PIE LEGAL LOPD -->
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:15px auto 30px">
    <tr>
      <td style="font-size:11px;color:#555;text-align:center;line-height:1.4">
        <?php echo esc_html__( 'Este mensaje y sus archivos adjuntos son confidenciales y dirigidos exclusivamente a su destinatario.', 'tradutema-crm' ); ?>
        <?php echo esc_html__( 'Si ha recibido este correo por error, por favor notifíquelo y elimínelo de inmediato.', 'tradutema-crm' ); ?><br /><br />
        <?php echo esc_html__( 'Conforme a la normativa de protección de datos, usted puede ejercer sus derechos de acceso, rectificación, supresión, portabilidad y limitación dirigiéndose a:', 'tradutema-crm' ); ?>
        <strong>info@tradutema.com</strong>.
      </td>
    </tr>
  </table>
</body>
</html>
        <?php

        return ob_get_clean();
    }

    /**
     * Aplica las transiciones necesarias cuando el proveedor asignado es interno.
     *
     * @param WC_Order $order Pedido asociado.
     * @param array    $meta  Metadatos del pedido.
     */
    private function process_internal_provider_upload( WC_Order $order, array $meta ) {
        $order_id        = $order->get_id();
        $previous_status = $this->normalize_operational_status_value( tradutema_array_get( $meta, 'estado_operacional', 'recibido' ) );

        $this->update_order_operational_status( $order_id, 'traducido' );

        if ( 'traducido' !== $previous_status ) {
            $previous_label = '' !== $previous_status ? $this->get_operational_status_label( $previous_status ) : __( 'Sin definir', 'tradutema-crm' );
            $current_label  = $this->get_operational_status_label( 'traducido' );

            $this->log_order_event(
                $order_id,
                'estado_operacional',
                sprintf(
                    /* translators: %s: operational status label */
                    __( 'Estado operacional actualizado automáticamente a %s.', 'tradutema-crm' ),
                    $current_label
                ),
                array(
                    'previous_status' => array(
                        'key'   => '' !== $previous_status ? $previous_status : null,
                        'label' => $previous_label,
                    ),
                    'current_status'  => array(
                        'key'   => 'traducido',
                        'label' => $current_label,
                    ),
                )
            );
        }

        $admin_email = sanitize_email( get_option( 'admin_email' ) );
        $manage_url  = add_query_arg(
            array(
                'page'     => self::MANAGE_ORDER_PAGE,
                'order_id' => $order_id,
            ),
            admin_url( 'admin.php' )
        );

        if ( $admin_email ) {
            $subject = sprintf(
                /* translators: %s: order number. */
                __( 'Traducción finalizada por proveedor interno para el pedido %s', 'tradutema-crm' ),
                $order->get_order_number()
            );
            $message_content = sprintf(
                '<p>%s</p><p><a href="%s">%s</a></p>',
                esc_html__( 'El proveedor interno ha marcado la traducción como finalizada.', 'tradutema-crm' ),
                esc_url( $manage_url ),
                esc_html__( 'Gestionar pedido en el CRM', 'tradutema-crm' )
            );
            $message = $this->build_branded_email_layout( $message_content, $subject );

            $sent = $this->send_email_via_solid_mail( $admin_email, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );

            if ( $sent ) {
                $this->log_order_event(
                    $order_id,
                    'email',
                    __( 'Notificación de finalización interna enviada al administrador.', 'tradutema-crm' ),
                    array(
                        'subject'    => $subject,
                        'recipients' => array( $admin_email ),
                        'manage_url' => $manage_url,
                    )
                );
            }
        }
    }

    /**
     * Aplica las transiciones necesarias cuando el proveedor asignado es externo.
     *
     * @param WC_Order $order Pedido asociado.
     * @param array    $meta  Metadatos del pedido.
     */
    private function process_external_provider_upload( WC_Order $order, array $meta ) {
        $order_id        = $order->get_id();
        $previous_status = $this->normalize_operational_status_value( tradutema_array_get( $meta, 'estado_operacional', 'recibido' ) );
        $has_paper       = $this->order_requires_paper_delivery( $order, $meta );
        $target_status   = $has_paper ? 'en_espera_validacion_cliente' : 'entregado';

        $this->update_order_operational_status( $order_id, $target_status );

        if ( $target_status !== $previous_status ) {
            $previous_label = '' !== $previous_status ? $this->get_operational_status_label( $previous_status ) : __( 'Sin definir', 'tradutema-crm' );
            $current_label  = $this->get_operational_status_label( $target_status );

            $this->log_order_event(
                $order_id,
                'estado_operacional',
                sprintf(
                    /* translators: %s: operational status label */
                    __( 'Estado operacional actualizado automáticamente a %s.', 'tradutema-crm' ),
                    $current_label
                ),
                array(
                    'previous_status' => array(
                        'key'   => '' !== $previous_status ? $previous_status : null,
                        'label' => $previous_label,
                    ),
                    'current_status'  => array(
                        'key'   => $target_status,
                        'label' => $current_label,
                    ),
                )
            );
        }

        $folder_details = $this->get_order_drive_subfolder_details( $order_id, self::TO_CLIENT_FOLDER_KEY );
        $folder_id      = isset( $folder_details['id'] ) ? $folder_details['id'] : '';
        $folder_url     = isset( $folder_details['url'] ) ? $folder_details['url'] : '';
        $token          = $this->get_google_drive_access_token();
        $folder_link    = $this->build_drive_share_link( $folder_id, $folder_url, $token );

        $admin_email  = sanitize_email( get_option( 'admin_email' ) );
        $client_email = sanitize_email( $order->get_billing_email() );
        $recipients   = array_values( array_filter( array_unique( array( $client_email, $admin_email ) ) ) );

        if ( ! empty( $recipients ) ) {
            $subject = sprintf(
                /* translators: %s: order number. */
                __( 'Traducción finalizada para el pedido %s', 'tradutema-crm' ),
                $order->get_order_number()
            );

            $message  = sprintf( '<p>%s</p>', esc_html__( 'La traducción ya está terminada.', 'tradutema-crm' ) );
            $message .= sprintf(
                '<p>%s %s</p>',
                esc_html__( 'Puedes acceder a los ficheros en', 'tradutema-crm' ),
                $folder_link ? '<a href="' . esc_url( $folder_link ) . '">' . esc_html( $folder_link ) . '</a>' : esc_html__( 'la carpeta del cliente en Google Drive.', 'tradutema-crm' )
            );

            $message = $this->build_branded_email_layout( $message, $subject );

            $sent = $this->send_email_via_solid_mail( $recipients, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );

            if ( $sent ) {
                $this->log_order_event(
                    $order_id,
                    'email',
                    __( 'Notificación de entrega enviada al cliente y administrador.', 'tradutema-crm' ),
                    array(
                        'subject'     => $subject,
                        'recipients'  => $recipients,
                        'folder_link' => $folder_link,
                    )
                );
            }
        }
    }

    /**
     * Normaliza la estructura de ficheros recibida desde el formulario.
     *
     * @param array $raw_files Datos de $_FILES.
     * @return array<int, array<string,string|int>>|WP_Error
     */
    private function prepare_provider_upload_files( $raw_files ) {
        if ( empty( $raw_files ) || ! isset( $raw_files['name'] ) ) {
            return array();
        }

        $files = array();

        if ( is_array( $raw_files['name'] ) ) {
            foreach ( $raw_files['name'] as $index => $name ) {
                $error = isset( $raw_files['error'][ $index ] ) ? (int) $raw_files['error'][ $index ] : UPLOAD_ERR_NO_FILE;

                if ( UPLOAD_ERR_NO_FILE === $error ) {
                    continue;
                }

                if ( UPLOAD_ERR_OK !== $error ) {
                    return new WP_Error( 'tradutema_upload_error', $this->describe_upload_error( $error ) );
                }

                $tmp_name = isset( $raw_files['tmp_name'][ $index ] ) ? $raw_files['tmp_name'][ $index ] : '';

                if ( '' === $tmp_name || ! file_exists( $tmp_name ) ) {
                    return new WP_Error( 'tradutema_upload_missing_tmp', __( 'No se pudo procesar uno de los ficheros subidos.', 'tradutema-crm' ) );
                }

                $sanitized_name = sanitize_file_name( $name );

                if ( '' === $sanitized_name ) {
                    $sanitized_name = 'documento-' . ( $index + 1 );
                }

                $files[] = array(
                    'name'     => $sanitized_name,
                    'tmp_name' => $tmp_name,
                    'type'     => isset( $raw_files['type'][ $index ] ) ? (string) $raw_files['type'][ $index ] : '',
                    'size'     => isset( $raw_files['size'][ $index ] ) ? (int) $raw_files['size'][ $index ] : 0,
                );
            }
        } else {
            $error = isset( $raw_files['error'] ) ? (int) $raw_files['error'] : UPLOAD_ERR_NO_FILE;

            if ( UPLOAD_ERR_NO_FILE === $error ) {
                return array();
            }

            if ( UPLOAD_ERR_OK !== $error ) {
                return new WP_Error( 'tradutema_upload_error', $this->describe_upload_error( $error ) );
            }

            $tmp_name = isset( $raw_files['tmp_name'] ) ? $raw_files['tmp_name'] : '';

            if ( '' === $tmp_name || ! file_exists( $tmp_name ) ) {
                return new WP_Error( 'tradutema_upload_missing_tmp', __( 'No se pudo procesar el fichero subido.', 'tradutema-crm' ) );
            }

            $name = sanitize_file_name( $raw_files['name'] );

            if ( '' === $name ) {
                $name = 'documento';
            }

            $files[] = array(
                'name'     => $name,
                'tmp_name' => $tmp_name,
                'type'     => isset( $raw_files['type'] ) ? (string) $raw_files['type'] : '',
                'size'     => isset( $raw_files['size'] ) ? (int) $raw_files['size'] : 0,
            );
        }

        return $files;
    }

    /**
     * Devuelve un mensaje legible para un código de error de subida.
     *
     * @param int $error_code Código de error de PHP.
     * @return string
     */
    private function describe_upload_error( $error_code ) {
        switch ( $error_code ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __( 'El fichero supera el tamaño máximo permitido.', 'tradutema-crm' );
            case UPLOAD_ERR_PARTIAL:
                return __( 'La subida se interrumpió. Inténtalo de nuevo.', 'tradutema-crm' );
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
            case UPLOAD_ERR_EXTENSION:
            default:
                return __( 'No se pudo completar la subida del fichero. Inténtalo de nuevo.', 'tradutema-crm' );
        }
    }

    /**
     * Sube los ficheros proporcionados a la carpeta indicada del pedido.
     *
     * @param int    $order_id   ID del pedido.
     * @param array  $files      Ficheros normalizados.
     * @param string $folder_key Clave de la carpeta de destino.
     * @return array<int, array<string,string>>|WP_Error
     */
    private function upload_provider_files_to_drive( $order_id, array $files, $folder_key = self::TO_CLIENT_FOLDER_KEY ) {
        $folder_details = $this->get_order_drive_subfolder_details( $order_id, $folder_key );
        $folder_id      = isset( $folder_details['id'] ) ? trim( (string) $folder_details['id'] ) : '';

        if ( '' === $folder_id ) {
            return new WP_Error(
                'tradutema_upload_missing_folder',
                sprintf(
                    /* translators: %s: Google Drive folder key. */
                    __( 'No se encontró la carpeta %s del pedido en Google Drive.', 'tradutema-crm' ),
                    sanitize_text_field( $folder_key )
                )
            );
        }

        $token = $this->get_google_drive_access_token();

        if ( '' === $token ) {
            return new WP_Error( 'tradutema_upload_missing_token', __( 'No se pudo acceder a Google Drive en estos momentos. Inténtalo más tarde.', 'tradutema-crm' ) );
        }

        $uploaded = array();

        foreach ( $files as $file ) {
            $file_name = isset( $file['name'] ) ? (string) $file['name'] : 'documento';
            $tmp_name  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

            if ( '' === $tmp_name || ! file_exists( $tmp_name ) ) {
                return new WP_Error( 'tradutema_upload_missing_tmp', __( 'No se pudo procesar uno de los ficheros subidos.', 'tradutema-crm' ) );
            }

            $mime_type = isset( $file['type'] ) ? (string) $file['type'] : '';

            if ( '' === $mime_type ) {
                $filetype = wp_check_filetype_and_ext( $tmp_name, $file_name );

                if ( $filetype && ! empty( $filetype['type'] ) ) {
                    $mime_type = $filetype['type'];
                } else {
                    $mime_type = 'application/octet-stream';
                }
            }

            $result = $this->perform_drive_file_upload( $folder_id, $tmp_name, $file_name, $mime_type, $token );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $uploaded[] = array(
                'id'   => isset( $result['id'] ) ? (string) $result['id'] : '',
                'name' => $file_name,
                'link' => isset( $result['webViewLink'] ) ? (string) $result['webViewLink'] : '',
            );
        }

        return $uploaded;
    }

    /**
     * Realiza la petición HTTP a la API de Google Drive para subir un fichero.
     *
     * @param string $folder_id Identificador de la carpeta destino.
     * @param string $file_path Ruta temporal del fichero.
     * @param string $file_name Nombre del fichero.
     * @param string $mime_type Tipo MIME detectado.
     * @param string $token     Token de acceso a Google Drive.
     * @return array<string,mixed>|WP_Error
     */
    private function perform_drive_file_upload( $folder_id, $file_path, $file_name, $mime_type, $token ) {
        $boundary   = wp_generate_password( 24, false, false );
        $metadata   = wp_json_encode(
            array(
                'name'    => $file_name,
                'parents' => array( $folder_id ),
            )
        );
        $file_bytes = @file_get_contents( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ( false === $metadata || false === $file_bytes ) {
            return new WP_Error( 'tradutema_upload_read_error', sprintf( __( 'No se pudo preparar el fichero %s para subirlo a Google Drive.', 'tradutema-crm' ), $file_name ) );
        }

        $body = implode(
            "\r\n",
            array(
                '--' . $boundary,
                'Content-Type: application/json; charset=UTF-8',
                '',
                (string) $metadata,
                '--' . $boundary,
                'Content-Type: ' . $mime_type,
                '',
                $file_bytes,
                '--' . $boundary . '--',
                '',
            )
        );

        $response = wp_remote_post(
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'multipart/related; boundary=' . $boundary,
                ),
                'body'    => $body,
                'timeout' => 60,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'tradutema_upload_request_error', __( 'No se pudo completar la conexión con Google Drive.', 'tradutema-crm' ) );
        }

        $status = wp_remote_retrieve_response_code( $response );

        if ( $status < 200 || $status >= 300 ) {
            $message = __( 'La API de Google Drive devolvió un error al subir el fichero.', 'tradutema-crm' );
            $body    = wp_remote_retrieve_body( $response );

            if ( $body ) {
                $decoded = json_decode( $body, true );

                if ( isset( $decoded['error']['message'] ) ) {
                    $message = sprintf( '%s (%s)', $message, $decoded['error']['message'] );
                }
            }

            return new WP_Error( 'tradutema_upload_api_error', $message );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'tradutema_upload_invalid_response', __( 'No se pudo interpretar la respuesta de Google Drive.', 'tradutema-crm' ) );
        }

        return $data;
    }

    /**
     * Recupera los detalles de la subcarpeta solicitada desde los metadatos del pedido.
     *
     * @param int    $order_id   ID del pedido.
     * @param string $folder_key Clave de la subcarpeta.
     * @return array<string,string>
     */
    private function get_order_drive_subfolder_details( $order_id, $folder_key ) {
        $raw_subfolders = get_post_meta( $order_id, '_ciwc_drive_subfolders', true );
        $subfolders     = is_array( $raw_subfolders ) ? $raw_subfolders : maybe_unserialize( $raw_subfolders );

        if ( ! is_array( $subfolders ) ) {
            $subfolders = array();
        }

        return $this->find_drive_subfolder_details( $subfolders, $folder_key );
    }

    /**
     * Renderiza la plantilla pública de subida de ficheros.
     *
     * @param WC_Order|null $order          Pedido asociado.
     * @param string        $token          Token recibido.
     * @param array         $errors               Mensajes de error.
     * @param bool          $success              Estado de éxito.
     * @param array         $uploaded_files       Archivos subidos.
     * @param array         $token_data           Datos adicionales del token.
     * @param bool          $is_internal_provider Indica si el proveedor es interno.
     */
    private function render_provider_upload_template( $order, $token, array $errors, $success, array $uploaded_files, array $token_data, $is_internal_provider ) {
        nocache_headers();
        status_header( 200 );

        $order_number = $order ? $order->get_order_number() : '';
        $title        = $order
            ? sprintf(
                $is_internal_provider
                    ? __( 'Confirma la finalización de la traducción jurada para el pedido %s', 'tradutema-crm' )
                    : __( 'Suba los ficheros finales de traducción jurada para el pedido %s', 'tradutema-crm' ),
                $order_number
            )
            : ( $is_internal_provider ? __( 'Confirmación de traducción proveedor', 'tradutema-crm' ) : __( 'Subida de ficheros proveedor', 'tradutema-crm' ) );
        $site_title   = get_bloginfo( 'name' );
        $full_title   = trim( $site_title . ' - ' . $title );
        $action_url   = add_query_arg( array() );

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo esc_html( $full_title ); ?></title>
    <?php wp_head(); ?>
    <style>
        body.tradutema-crm-provider-upload {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
        }

        .tradutema-crm-provider-upload__wrapper {
            max-width: 600px;
            margin: 4rem auto;
            background: #ffffff;
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 20px 45px rgba(23, 43, 77, 0.1);
        }

        .tradutema-crm-provider-upload__title {
            font-size: 1.75rem;
            margin-top: 0;
            margin-bottom: 1.5rem;
            color: #1e293b;
            text-align: center;
        }

        .tradutema-crm-provider-upload__notice {
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }

        .tradutema-crm-provider-upload__notice--error {
            background: rgba(220, 38, 38, 0.1);
            color: #991b1b;
        }

        .tradutema-crm-provider-upload__notice--success {
            background: rgba(22, 163, 74, 0.12);
            color: #166534;
        }

        .tradutema-crm-provider-upload__form label {
            display: block;
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.75rem;
        }

        .tradutema-crm-provider-upload__form input[type="file"] {
            width: 100%;
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid #cbd5f5;
            background: #f8fafc;
        }

        .tradutema-crm-provider-upload__form button {
            width: 100%;
            padding: 0.85rem 1.5rem;
            margin-top: 1.5rem;
            background: #1d4ed8;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease-in-out;
        }

        .tradutema-crm-provider-upload__form button:hover,
        .tradutema-crm-provider-upload__form button:focus {
            background: #1e40af;
        }

        .tradutema-crm-provider-upload__footer {
            margin-top: 2rem;
            font-size: 0.85rem;
            color: #64748b;
            text-align: center;
        }

        .tradutema-crm-provider-upload__files {
            margin: 1.25rem 0 0 0;
            padding-left: 1.25rem;
            color: #334155;
        }
    </style>
</head>
<body <?php body_class( 'tradutema-crm-provider-upload' ); ?>>
<?php do_action( 'wp_body_open' ); ?>
<main class="tradutema-crm-provider-upload__wrapper" role="main">
    <h1 class="tradutema-crm-provider-upload__title"><?php echo esc_html( $title ); ?></h1>

    <?php if ( ! empty( $errors ) ) : ?>
        <div class="tradutema-crm-provider-upload__notice tradutema-crm-provider-upload__notice--error">
            <ul>
                <?php foreach ( $errors as $error_message ) : ?>
                    <li><?php echo esc_html( $error_message ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ( $success ) : ?>
        <div class="tradutema-crm-provider-upload__notice tradutema-crm-provider-upload__notice--success">
            <?php if ( $is_internal_provider ) : ?>
                <p><?php esc_html_e( 'Hemos registrado la traducción como finalizada y hemos avisado al administrador.', 'tradutema-crm' ); ?></p>
            <?php else : ?>
                <p><?php esc_html_e( 'Hemos recibido tus ficheros correctamente. ¡Muchas gracias!', 'tradutema-crm' ); ?></p>
                <?php if ( ! empty( $uploaded_files ) ) : ?>
                    <ul class="tradutema-crm-provider-upload__files">
                        <?php foreach ( $uploaded_files as $file ) : ?>
                            <li><?php echo esc_html( isset( $file['name'] ) ? $file['name'] : '' ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( ! $success && empty( $token_data['used'] ) ) : ?>
        <form class="tradutema-crm-provider-upload__form" method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data">
            <?php if ( $is_internal_provider ) : ?>
                <p><?php esc_html_e( 'Pulsa el botón para marcar la traducción como entregada y notificar al administrador.', 'tradutema-crm' ); ?></p>
            <?php else : ?>
                <label for="tradutema_files"><?php esc_html_e( 'Selecciona los ficheros finales que deseas entregar:', 'tradutema-crm' ); ?></label>
                <input type="file" id="tradutema_files" name="tradutema_files[]" multiple="multiple" required />
            <?php endif; ?>
            <?php wp_nonce_field( 'tradutema_upload_' . $token, 'tradutema_upload_nonce' ); ?>
            <button type="submit"><?php echo esc_html( $is_internal_provider ? __( 'Marcar como traducido', 'tradutema-crm' ) : __( 'Guardar y enviar', 'tradutema-crm' ) ); ?></button>
        </form>
    <?php endif; ?>

    <?php if ( ! empty( $token_data['used'] ) && ! $success ) : ?>
        <div class="tradutema-crm-provider-upload__notice tradutema-crm-provider-upload__notice--success">
            <p><?php esc_html_e( 'Este enlace ya ha sido utilizado. Si necesitas realizar cambios adicionales, contacta con tu gestor habitual.', 'tradutema-crm' ); ?></p>
        </div>
    <?php endif; ?>

    <div class="tradutema-crm-provider-upload__footer">
        <?php echo esc_html( sprintf( __( 'Tradutema · Pedido %s', 'tradutema-crm' ), $order_number ? $order_number : __( 'no disponible', 'tradutema-crm' ) ) ); ?>
    </div>
</main>
<?php wp_footer(); ?>
</body>
</html>
<?php
    }

    /**
     * Determina el tipo de envío mostrado para un pedido.
     *
     * @param WC_Order $order Pedido de WooCommerce.
     * @param array    $meta  Metadatos del pedido.
     * @return string
     */
    private function resolve_order_shipping_type( WC_Order $order, array $meta ) {
        $delivery_preference = $this->find_order_item_meta_value(
            $order,
            array( 'Recibir, PDF, o Delivery', 'Recibir PDF o delivery', 'Formato de entrega', '¿Cómo quieres recibir la traducción?' )
        );

        if ( '' !== $delivery_preference ) {
            return $delivery_preference;
        }

        $shipping_titles = array();

        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            $title = method_exists( $shipping_item, 'get_method_title' ) ? $shipping_item->get_method_title() : $shipping_item->get_name();

            if ( $title ) {
                $shipping_titles[] = $title;
            }
        }

        if ( ! empty( $shipping_titles ) ) {
            return implode( ', ', array_unique( $shipping_titles ) );
        }

        $requires_paper = $this->order_requires_paper_delivery( $order, $meta );

        return $requires_paper
            ? __( 'Entrega con envío en papel', 'tradutema-crm' )
            : __( 'PDF únicamente (gratis)', 'tradutema-crm' );
    }

    /**
     * Obtiene los enlaces compartidos de Google Drive asociados a un pedido.
     *
     * @param WC_Order $order Pedido de WooCommerce.
     * @return array<string,string>
     */
    private function resolve_order_drive_links( WC_Order $order ) {
        $order_id   = $order->get_id();
        $folder_id  = trim( (string) get_post_meta( $order_id, '_ciwc_drive_folder_id', true ) );
        $folder_url = trim( (string) get_post_meta( $order_id, '_ciwc_drive_folder_url', true ) );

        $raw_subfolders = get_post_meta( $order_id, '_ciwc_drive_subfolders', true );
        $subfolders     = is_array( $raw_subfolders ) ? $raw_subfolders : maybe_unserialize( $raw_subfolders );

        if ( ! is_array( $subfolders ) ) {
            $subfolders = array();
        }

        $token        = $this->get_google_drive_access_token();
        $drive_links  = array();
        $folder_links = array(
            'full_folder' => $folder_id ? $this->build_drive_share_link( $folder_id, $folder_url, $token ) : '',
            'source'      => $this->maybe_build_drive_subfolder_link( $subfolders, '01-Source', $token ),
            'work'        => $this->maybe_build_drive_subfolder_link( $subfolders, '02-Work', $token ),
            'translation' => $this->maybe_build_drive_subfolder_link( $subfolders, '03-Translation', $token ),
            'to_client'   => $this->maybe_build_drive_subfolder_link( $subfolders, '04-ToClient', $token ),
        );

        foreach ( $folder_links as $key => $value ) {
            if ( is_string( $value ) && '' !== $value ) {
                $drive_links[ $key ] = $value;
            }
        }

        return $drive_links;
    }

    /**
     * Construye un enlace compartido de Google Drive para una subcarpeta conocida.
     *
     * @param array<string,string> $subfolders  Listado de subcarpetas del pedido.
     * @param string               $folder_key  Clave de la subcarpeta a buscar.
     * @param string               $token       Token de acceso de Google Drive.
     * @return string
     */
    private function maybe_build_drive_subfolder_link( array $subfolders, $folder_key, $token ) {
        $details   = $this->find_drive_subfolder_details( $subfolders, $folder_key );
        $folder_id = isset( $details['id'] ) ? $details['id'] : '';
        $folder_url = isset( $details['url'] ) ? $details['url'] : '';

        if ( '' === $folder_id && '' === $folder_url ) {
            return '';
        }

        return $this->build_drive_share_link( $folder_id, $folder_url, $token );
    }

    /**
     * Localiza los detalles de una subcarpeta concreta dentro del listado almacenado.
     *
     * @param array<string,mixed> $subfolders Lista de subcarpetas.
     * @param string              $folder_key Clave a localizar.
     * @return array<string,string>
     */
    private function find_drive_subfolder_details( array $subfolders, $folder_key ) {
        $folder_id  = '';
        $folder_url = '';

        foreach ( $subfolders as $key => $value ) {
            if ( $folder_key !== $key ) {
                continue;
            }

            if ( is_array( $value ) ) {
                if ( isset( $value['id'] ) ) {
                    $folder_id = trim( (string) $value['id'] );
                } elseif ( isset( $value['folder_id'] ) ) {
                    $folder_id = trim( (string) $value['folder_id'] );
                }

                if ( isset( $value['url'] ) ) {
                    $folder_url = trim( (string) $value['url'] );
                } elseif ( isset( $value['link'] ) ) {
                    $folder_url = trim( (string) $value['link'] );
                } elseif ( isset( $value['webViewLink'] ) ) {
                    $folder_url = trim( (string) $value['webViewLink'] );
                } elseif ( isset( $value['web_view_link'] ) ) {
                    $folder_url = trim( (string) $value['web_view_link'] );
                }
            } else {
                $maybe_value = trim( (string) $value );

                if ( false !== strpos( $maybe_value, 'http://' ) || false !== strpos( $maybe_value, 'https://' ) ) {
                    $folder_url = $maybe_value;
                } else {
                    $folder_id = $maybe_value;
                }
            }

            break;
        }

        return array(
            'id'  => $folder_id,
            'url' => $folder_url,
        );
    }

    /**
     * Crea (si es necesario) un enlace compartido de Google Drive accesible para cualquiera con el enlace.
     *
     * @param string $folder_id  Identificador de la carpeta en Google Drive.
     * @param string $fallback   URL alternativa a devolver si no es posible generar el enlace.
     * @param string $token      Token de acceso de Google Drive.
     * @return string
     */
    private function build_drive_share_link( $folder_id, $fallback, $token ) {
        $folder_id = trim( (string) $folder_id );
        $fallback  = trim( (string) $fallback );

        if ( '' === $folder_id && '' === $fallback ) {
            return '';
        }

        $share_link = '';

        if ( '' !== $folder_id && '' !== $token ) {
            $this->ensure_drive_permission( $folder_id, $token );
            $share_link = $this->request_drive_share_link( $folder_id, $token );
        }

        if ( '' === $share_link && '' !== $fallback ) {
            $is_matching_folder = ( '' === $folder_id || false !== strpos( $fallback, $folder_id ) );

            if ( $is_matching_folder ) {
                $share_link = add_query_arg( 'usp', 'share_link', $fallback );
            }
        }

        if ( '' === $share_link && '' !== $folder_id ) {
            $share_link = sprintf( 'https://drive.google.com/drive/folders/%s?usp=share_link', rawurlencode( $folder_id ) );
        }

        return $share_link;
    }

    /**
     * Solicita a la API de Google Drive que permita el acceso público a una carpeta.
     *
     * @param string $folder_id Identificador de la carpeta.
     * @param string $token     Token de acceso de Google Drive.
     * @return void
     */
private function ensure_drive_permission( $folder_id, $token ) {
    $folder_id = trim( (string) $folder_id );
    $token     = trim( (string) $token );

    if ( '' === $folder_id || '' === $token ) {
        return;
    }

    if ( isset( $this->drive_permission_cache[ $folder_id ] ) ) {
        return;
    }

    // 1️⃣ Permiso anyone → reader (ACL)
    $endpoint_perm = sprintf(
        'https://www.googleapis.com/drive/v3/files/%s/permissions?supportsAllDrives=true&sendNotificationEmail=false',
        rawurlencode( $folder_id )
    );

    wp_remote_post( $endpoint_perm, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ),
        'body' => json_encode(array(
            'role' => 'reader',
            'type' => 'anyone'
        )),
        'timeout' => 10,
    ));

    // 2️⃣ Cambiar visibilidad interna de Drive
    $endpoint_visibility = sprintf(
        'https://www.googleapis.com/drive/v3/files/%s?supportsAllDrives=true',
        rawurlencode( $folder_id )
    );

    wp_remote_request( $endpoint_visibility, array(
        'method' => 'PATCH',
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ),
        'body' => json_encode(array(
            'permissionDetails' => array(
                array(
                    'role' => 'reader',
                    'type' => 'anyone'
                )
            )
        )),
        'timeout' => 10,
    ));

    $this->drive_permission_cache[ $folder_id ] = true;
}



    /**
     * Recupera el enlace compartible de una carpeta desde la API de Google Drive.
     *
     * @param string $folder_id Identificador de la carpeta.
     * @param string $token     Token de acceso de Google Drive.
     * @return string Enlace compartido o cadena vacía si no se pudo obtener.
     */
    private function request_drive_share_link( $folder_id, $token ) {
        $folder_id = trim( (string) $folder_id );
        $token     = trim( (string) $token );

        if ( '' === $folder_id || '' === $token ) {
            return '';
        }

        if ( array_key_exists( $folder_id, $this->drive_share_link_cache ) ) {
            return $this->drive_share_link_cache[ $folder_id ];
        }

        $endpoint = sprintf(
            'https://www.googleapis.com/drive/v3/files/%s?supportsAllDrives=true&fields=webViewLink,webContentLink,alternateLink',
            rawurlencode( $folder_id )
        );

        $response = wp_remote_get(
            $endpoint,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                ),
                'timeout' => 10,
            )
        );

        $share_link = '';

        if ( ! is_wp_error( $response ) ) {
            $status = wp_remote_retrieve_response_code( $response );

            if ( 200 === $status ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );

                if ( is_array( $body ) ) {
                    if ( isset( $body['webViewLink'] ) ) {
                        $share_link = trim( (string) $body['webViewLink'] );
                    } elseif ( isset( $body['webContentLink'] ) ) {
                        $share_link = trim( (string) $body['webContentLink'] );
                    } elseif ( isset( $body['alternateLink'] ) ) {
                        $share_link = trim( (string) $body['alternateLink'] );
                    }
                }
            }
        }

        if ( '' !== $share_link ) {
            $share_link = remove_query_arg( 'usp', $share_link );
            $share_link = add_query_arg( 'usp', 'share_link', $share_link );
        }

        $this->drive_share_link_cache[ $folder_id ] = $share_link;

        return $share_link;
    }

    /**
     * Intenta localizar y actualizar un permiso existente si la creación falla.
     *
     * @param string $folder_id Identificador de la carpeta.
     * @param string $token     Token de acceso.
     * @return void
     */
    private function maybe_update_drive_permission( $folder_id, $token ) {
        $endpoint = sprintf( 'https://www.googleapis.com/drive/v3/files/%s/permissions?supportsAllDrives=true', rawurlencode( $folder_id ) );

        $response = wp_remote_get(
            $endpoint,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            return;
        }

        $status = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $status ) {
            return;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['permissions'] ) || ! is_array( $body['permissions'] ) ) {
            return;
        }

        foreach ( $body['permissions'] as $permission ) {
            if ( isset( $permission['id'], $permission['type'] ) && 'anyone' === $permission['type'] ) {
                $permission_id = trim( (string) $permission['id'] );

                if ( '' === $permission_id ) {
                    continue;
                }

                $this->update_drive_permission( $folder_id, $permission_id, $token );
                break;
            }
        }
    }

    /**
     * Actualiza un permiso existente para que cualquier persona con el enlace pueda ver la carpeta.
     *
     * @param string $folder_id     Identificador de la carpeta.
     * @param string $permission_id Identificador del permiso.
     * @param string $token         Token de acceso.
     * @return void
     */
    private function update_drive_permission( $folder_id, $permission_id, $token ) {
        $endpoint = sprintf(
            'https://www.googleapis.com/drive/v3/files/%1$s/permissions/%2$s?supportsAllDrives=true&transferOwnership=false',
            rawurlencode( $folder_id ),
            rawurlencode( $permission_id )
        );

        $body = wp_json_encode(
            array(
                'role'               => 'reader',
                'type'               => 'anyone',
                'allowFileDiscovery' => false,
            )
        );

        wp_remote_request(
            $endpoint,
            array(
                'method'  => 'PATCH',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => $body,
                'timeout' => 10,
            )
        );
    }

    /**
     * Obtiene el token de acceso a Google Drive desde el otro plugin.
     *
     * @return string
     */
    private function get_google_drive_access_token() {
        $token = apply_filters( 'tradutema_crm_google_drive_access_token', '' );

        if ( is_string( $token ) && '' !== $token ) {
            return $token;
        }

        if ( isset( $GLOBALS['ciwc_du_main'] ) && is_object( $GLOBALS['ciwc_du_main'] ) && method_exists( $GLOBALS['ciwc_du_main'], 'get_access_token' ) ) {
            $maybe_token = $GLOBALS['ciwc_du_main']->get_access_token();

            if ( is_string( $maybe_token ) && '' !== $maybe_token ) {
                return $maybe_token;
            }
        }

        $candidate_functions = array(
            'get_access_token',
        );

        foreach ( $candidate_functions as $function ) {
            if ( ! is_callable( $function ) ) {
                continue;
            }

            $maybe_token = call_user_func( $function );

            if ( is_string( $maybe_token ) && '' !== $maybe_token ) {
                return $maybe_token;
            }
        }

        $candidate_classes = array(
            'CIWC_Drive_Uploader',
            '\\CIWC_Drive_Uploader',
            '\\CIWC_Google_Drive',
            '\\CIWC_Google_Drive_Client',
            '\\CIWC\\Google_Drive',
            '\\CIWC\\Drive\\Google_Drive',
        );

        foreach ( $candidate_classes as $class ) {
            if ( ! class_exists( $class ) ) {
                continue;
            }

            $instance = null;

            if ( method_exists( $class, 'instance' ) ) {
                $instance = $class::instance();
            } elseif ( method_exists( $class, 'get_instance' ) ) {
                $instance = $class::get_instance();
            } elseif ( method_exists( $class, 'getInstance' ) ) {
                $instance = $class::getInstance();
            } elseif ( method_exists( $class, 'get_client' ) ) {
                $instance = $class::get_client();
            } elseif ( method_exists( $class, 'get' ) ) {
                $instance = $class::get();
            } elseif ( method_exists( $class, 'singleton' ) ) {
                $instance = $class::singleton();
            } elseif ( method_exists( $class, 'get_client_instance' ) ) {
                $instance = $class::get_client_instance();
            }

            if ( ! $instance ) {
                if ( method_exists( $class, 'get_access_token' ) ) {
                    $maybe_token = call_user_func( array( $class, 'get_access_token' ) );

                    if ( is_string( $maybe_token ) && '' !== $maybe_token ) {
                        return $maybe_token;
                    }
                }

                continue;
            }

            if ( method_exists( $instance, 'get_access_token' ) ) {
                $maybe_token = $instance->get_access_token();

                if ( is_string( $maybe_token ) && '' !== $maybe_token ) {
                    return $maybe_token;
                }
            }
        }

        return '';
    }

    /**
     * Reemplaza los placeholders definidos en el contenido de una plantilla.
     *
     * @param string               $content       Contenido original.
     * @param array<string,string> $replacements  Valores para sustituir.
     * @return string
     */
    private function replace_email_placeholders( $content, array $replacements ) {
        $content = (string) $content;

        if ( '' === $content ) {
            return '';
        }

        $search  = array_keys( $replacements );
        $replace = array_values( $replacements );

        return str_replace( $search, $replace, $content );
    }

    /**
     * Normaliza la referencia mostrada en la tabla.
     *
     * @param WC_Order $order Pedido de WooCommerce.
     * @param array    $meta  Metadatos internos.
     * @return string
     */
    private function resolve_order_reference( WC_Order $order, array $meta ) {
        $reference = trim( (string) tradutema_array_get( $meta, 'referencia', '' ) );

        if ( '' !== $reference ) {
            return $reference;
        }

        $type_hint = 'normal';

        foreach ( $order->get_items() as $item ) {
            $normalized_name = $this->normalize_meta_key( $item->get_name() );

            if ( false !== strpos( $normalized_name, 'cotizacion' ) || false !== strpos( $normalized_name, 'cotizaci' ) ) {
                $type_hint = 'cotizacion';
                break;
            }

            if ( false !== strpos( $normalized_name, 'recargo' ) || false !== strpos( $normalized_name, 'pago adicional' ) ) {
                $type_hint = 'recargo';
            }
        }

        if ( 'cotizacion' === $type_hint ) {
            return __( 'PEDIDO COTIZACIÓN', 'tradutema-crm' );
        }

        if ( 'recargo' === $type_hint ) {
            $surcharge_reference = trim( (string) $this->find_order_item_meta_value( $order, array( 'Número de pedido', 'Numero de pedido' ) ) );

            if ( '' !== $surcharge_reference ) {
                return $surcharge_reference;
            }
        }

        $original_id = 0;

        $parent_id = $order->get_parent_id();
        if ( $parent_id ) {
            $original_id = absint( $parent_id );
        }

        if ( ! $original_id ) {
            $additional_keys = array(
                '_ttm_original_order_id',
                '_original_order_id',
                '_parent_order_id',
                '_wc_deposits_parent_order_id',
                '_order_parent_id',
            );

            foreach ( $additional_keys as $meta_key ) {
                $candidate = $order->get_meta( $meta_key, true );

                if ( $candidate ) {
                    $original_id = absint( $candidate );

                    if ( $original_id ) {
                        break;
                    }
                }
            }
        }

        if ( ! $original_id ) {
            $item_original = $this->find_order_item_meta_value( $order, array( 'Pedido original', 'ID pedido original', 'Pedido asociado' ) );

            if ( $item_original && is_numeric( $item_original ) ) {
                $original_id = absint( $item_original );
            }
        }

        if ( $original_id ) {
            return (string) $original_id;
        }

        if ( 'recargo' === $type_hint ) {
            return __( 'RECARGO', 'tradutema-crm' );
        }

        return '';
    }

    /**
     * Determina el origen del pedido para mostrarlo en la tabla principal.
     *
     * @param WC_Order $order      Pedido de WooCommerce.
     * @param string   $reference  Referencia asociada al pedido.
     * @return string Uno de WOO, ADI o COT.
     */
    private function determine_order_origin( WC_Order $order, $reference ) {
        $cotizacion_target = $this->normalize_meta_key( 'Cotización Personalizada Tradutema' );

        foreach ( $order->get_items() as $item ) {
            $item_name = $this->normalize_meta_key( $item->get_name() );

            if ( '' !== $item_name && false !== strpos( $item_name, $cotizacion_target ) ) {
                return 'COT';
            }
        }

        if ( '' !== trim( (string) $reference ) ) {
            return 'ADI';
        }

        return 'WOO';
    }

    /**
     * Determina si el pedido requiere envío en papel.
     *
     * @param WC_Order $order Pedido de WooCommerce.
     * @param array    $meta  Metadatos internos.
     * @return bool
     */
    private function order_requires_paper_delivery( WC_Order $order, array $meta ) {
        $translation_delivery_pref = $this->find_order_item_meta_value( $order, array( '¿Cómo quieres recibir la traducción?' ) );

        if ( '' !== $translation_delivery_pref ) {
            return false !== stripos( $translation_delivery_pref, 'papel' );
        }

        if ( ! empty( $meta['envio_papel'] ) ) {
            return true;
        }

        foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
            $name = $shipping_item->get_name();

            if ( $name && false !== stripos( $name, 'papel' ) ) {
                return true;
            }
        }

        $delivery_pref = $this->find_order_item_meta_value( $order, array( 'Recibir, PDF, o Delivery', 'Recibir PDF o delivery', 'Formato de entrega' ) );

        if ( $delivery_pref && false !== stripos( $delivery_pref, 'papel' ) ) {
            return true;
        }

        return false;
    }

    /**
     * Construye la dirección de envío mostrada en el listado principal.
     *
     * @param WC_Order $order         Pedido de WooCommerce.
     * @param bool     $paper_required Indicador de si el pedido requiere papel.
     * @return string
     */
    private function build_shipping_address_for_display( WC_Order $order, $paper_required ) {
        if ( ! $paper_required ) {
            return '';
        }

        $parts = array(
            trim( (string) $order->get_shipping_first_name() ),
            trim( (string) $order->get_shipping_address_1() ),
            trim( (string) $order->get_shipping_postcode() ),
            trim( (string) $order->get_shipping_city() ),
            trim( (string) $order->get_shipping_country() ),
        );

        $parts = array_values( array_filter( $parts, static function ( $value ) {
            return '' !== $value;
        } ) );

        if ( empty( $parts ) ) {
            return '';
        }

        return implode( ', ', $parts );
    }

    /**
     * Busca un metadato concreto dentro de los items del pedido.
     *
     * @param WC_Order $order  Pedido de WooCommerce.
     * @param array    $labels Posibles etiquetas del metadato.
     * @return string
     */
    private function find_order_item_meta_value( WC_Order $order, array $labels ) {
        if ( empty( $labels ) ) {
            return '';
        }

        $normalized_labels = array();

        foreach ( $labels as $label ) {
            $normalized_labels[] = $this->normalize_meta_key( $label );
        }

        foreach ( $order->get_items() as $item ) {
            foreach ( $item->get_meta_data() as $meta ) {
                $meta_data = $meta->get_data();
                $key       = $this->normalize_meta_key( tradutema_array_get( $meta_data, 'key' ) );

                if ( in_array( $key, $normalized_labels, true ) ) {
                    $value = tradutema_array_get( $meta_data, 'value' );

                    if ( is_scalar( $value ) ) {
                        return (string) $value;
                    }

                    if ( is_array( $value ) ) {
                        $value = array_filter( array_map( 'strval', $value ) );

                        if ( ! empty( $value ) ) {
                            return implode( ', ', $value );
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * Normaliza una clave de metadato para comparaciones.
     *
     * @param string $key Clave original.
     * @return string
     */
    private function normalize_meta_key( $key ) {
        $key = wp_strip_all_tags( (string) $key );
        $key = remove_accents( $key );
        $key = strtolower( $key );
        $key = preg_replace( '/[^a-z0-9]+/i', ' ', $key );

        return trim( preg_replace( '/\s+/', ' ', $key ) );
    }

    /**
     * Combina valores de fecha y hora para mostrarlos en el listado.
     *
     * @param string $date_value Fecha original.
     * @param string $time_value Hora original.
     * @return array Array con el valor combinado y un indicador de si incluye hora.
     */
    private function prepare_datetime_value( $date_value, $time_value ) {
        $date_value = trim( (string) $date_value );
        $time_value = trim( (string) $time_value );

        if ( '' === $date_value && '' === $time_value ) {
            return array( '', false );
        }

        $date_has_time = (bool) preg_match( '/\d{1,2}:\d{2}/', $date_value );

        if ( '' !== $time_value ) {
            $time_fragment     = $time_value;
            $has_explicit_time = false;

            if ( preg_match( '/(\d{1,2}:\d{2}(?::\d{2})?)/', $time_value, $matches ) ) {
                $time_fragment     = $matches[1];
                $has_explicit_time = true;
            }

            if ( '' === $date_value ) {
                return array( trim( $time_fragment ), $has_explicit_time );
            }

            if ( ! $date_has_time ) {
                $date_value    = trim( $date_value . ' ' . $time_fragment );
                $date_has_time = $has_explicit_time ? true : $date_has_time;
            }

            if ( $has_explicit_time ) {
                return array( trim( $date_value ), true );
            }

            return array( trim( $date_value ), $date_has_time );
        }

        return array( trim( $date_value ), $date_has_time );
    }

    /**
     * Convierte una fecha recibida en un timestamp para ordenación.
     *
     * @param string $value Fecha en formato texto.
     * @return int|null
     */
    private function get_datetime_timestamp( $value ) {
        if ( null === $value ) {
            return null;
        }

        $value = trim( (string) $value );

        if ( '' === $value ) {
            return null;
        }

        $known_formats = array(
            'd/m/Y H:i',
            'd/m/y H:i',
            'd/m/Y',
            'd/m/y',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'Y-m-d H:i:s T',
            'Y-m-d\TH:i',
        );

        foreach ( $known_formats as $format ) {
            $datetime = date_create_from_format( $format, $value, wp_timezone() );

            if ( $datetime instanceof \DateTime ) {
                return $datetime->getTimestamp();
            }
        }

        $timestamp = strtotime( $value );

        if ( false === $timestamp ) {
            return null;
        }

        return $timestamp;
    }

    /**
     * Formatea una fecha para mostrarla en el listado.
     *
     * @param string $value        Fecha en formato texto.
     * @param bool   $include_time Si debe incluir la hora.
     * @param bool   $include_year Si debe incluir el año.
     * @return string
     */
    private function format_display_date( $value, $include_time = false, $include_year = true ) {
        $value = trim( (string) $value );

        if ( '' === $value ) {
            return '';
        }

        $timestamp = $this->get_datetime_timestamp( $value );

        if ( null === $timestamp ) {
            return $value;
        }

        $format = 'd/m';

        if ( $include_year ) {
            $format .= '/y';
        }

        if ( $include_time ) {
            $format .= ' H:i';
        }

        return wp_date( $format, $timestamp );
    }

    /**
     * Devuelve la fecha preparada en dos líneas (fecha y hora) para el listado.
     *
     * @param string $value        Fecha en formato texto.
     * @param bool   $include_time Si debe incluir la hora.
     * @param bool   $include_year Si debe incluir el año.
     * @return array
     */
    private function format_display_date_parts( $value, $include_time = false, $include_year = true ) {
        $value = trim( (string) $value );

        if ( '' === $value ) {
            return array(
                'date' => '',
                'time' => '',
            );
        }

        $timestamp = $this->get_datetime_timestamp( $value );

        if ( null === $timestamp ) {
            return array(
                'date' => $value,
                'time' => '',
            );
        }

        $date_format = $include_year ? 'd/m/y' : 'd/m';
        $time_format = $include_time ? 'H:i' : '';

        return array(
            'date' => wp_date( $date_format, $timestamp ),
            'time' => '' !== $time_format ? wp_date( $time_format, $timestamp ) : '',
        );
    }

    /**
     * Obtiene los estilos aplicables a una etiqueta de estado de pago.
     *
     * @param string $status_key Clave del estado de WooCommerce.
     * @return string
     */
    private function get_payment_status_badge_style( $status_key ) {
        $status_key = strtolower( (string) $status_key );

        $default_style = array(
            'background' => '#e5e5e5',
            'color'      => '#454545',
        );

        $styles = array(
            'wc-pending'         => $default_style,
            'wc-pending-payment' => $default_style,
            'wc-processing'      => array(
                'background' => '#c6e1c6',
                'color'      => '#2c4700',
            ),
            'wc-on-hold'         => array(
                'background' => '#f8dda7',
                'color'      => '#573b00',
            ),
            'wc-completed'       => array(
                'background' => '#c8d7e1',
                'color'      => '#003d66',
            ),
            'wc-cancelled'       => $default_style,
            'wc-refunded'        => $default_style,
            'wc-failed'          => array(
                'background' => '#eba3a3',
                'color'      => '#570000',
            ),
            'wc-draft'           => $default_style,
            'wc-checkout-draft'  => $default_style,
        );

        $style = isset( $styles[ $status_key ] ) ? $styles[ $status_key ] : $default_style;

        return sprintf( 'background-color: %1$s; color: %2$s;', $style['background'], $style['color'] );
    }

    /**
     * Obtiene los estilos aplicables a una etiqueta de estado operacional.
     *
     * @param string $status_key Clave del estado operacional.
     * @return string
     */
    private function get_operational_status_badge_style( $status_key ) {
        $status_key = strtolower( (string) $status_key );

        $default_style = array(
            'background' => '#e5e5e5',
            'color'      => '#454545',
        );

        $styles = array(
            'recibido'                     => array(
                'background' => '#d9eaf7',
                'color'      => '#004a80',
            ),
            'en_espera_tasacion'           => array(
                'background' => '#f8dda7',
                'color'      => '#573b00',
            ),
            'asignado_en_curso'            => array(
                'background' => '#c6e1c6',
                'color'      => '#2c4700',
            ),
            'traducido'                    => array(
                'background' => '#dcd6f7',
                'color'      => '#2f2370',
            ),
            'en_espera_validacion_cliente' => array(
                'background' => '#ffe6cc',
                'color'      => '#663300',
            ),
            'entregado'                    => array(
                'background' => '#c8d7e1',
                'color'      => '#003d66',
            ),
        );

        $style = isset( $styles[ $status_key ] ) ? $styles[ $status_key ] : $default_style;

        return sprintf( 'background-color: %1$s; color: %2$s;', $style['background'], $style['color'] );
    }

    /**
     * Formatea una pareja de idiomas.
     *
     * @param string $origin      Idioma de origen.
     * @param string $destination Idioma de destino.
     * @return string
     */
    private function format_language_pair( $origin, $destination ) {
        $origin      = $this->get_iso_language_code( $origin );
        $destination = $this->get_iso_language_code( $destination );

        if ( $origin && $destination ) {
            return sprintf( '%s → %s', $origin, $destination );
        }

        if ( $origin ) {
            return $origin;
        }

        return $destination;
    }

    /**
     * Obtiene el código ISO-639-1 de un idioma a partir de su nombre en español o código.
     *
     * @param string $language Idioma a normalizar.
     * @return string
     */
    private function get_iso_language_code( $language ) {
        $language = trim( (string) $language );

        if ( '' === $language ) {
            return '';
        }

        $language_upper = strtoupper( $language );

        if ( preg_match( '/^[A-Z]{2}$/', $language_upper ) ) {
            return $language_upper;
        }

        $normalized = strtolower( remove_accents( $language ) );

        $map = array(
            'aleman'      => 'DE',
            'arabe'       => 'AR',
            'bulgaro'     => 'BG',
            'catalan'     => 'CA',
            'checo'       => 'CS',
            'chino'       => 'ZH',
            'eslovaco'    => 'SK',
            'esloveno'    => 'SL',
            'espanol'     => 'ES',
            'castellano'  => 'ES',
            'euskera'     => 'EU',
            'vasco'       => 'EU',
            'fines'       => 'FI',
            'frances'     => 'FR',
            'gallego'     => 'GL',
            'georgiano'   => 'KA',
            'griego'      => 'EL',
            'hebreo'      => 'HE',
            'hindi'       => 'HI',
            'holandes'    => 'NL',
            'hungaro'     => 'HU',
            'ingles'      => 'EN',
            'italiano'    => 'IT',
            'lituano'     => 'LT',
            'neerlandes'  => 'NL',
            'noruego'     => 'NO',
            'polaco'      => 'PL',
            'portugues'   => 'PT',
            'rumano'      => 'RO',
            'ruso'        => 'RU',
            'serbio'      => 'SR',
            'sueco'       => 'SV',
            'tagalo'      => 'TL',
            'turco'       => 'TR',
            'ucraniano'   => 'UK',
        );

        if ( isset( $map[ $normalized ] ) ) {
            return $map[ $normalized ];
        }

        return $language_upper;
    }

    /**
     * Convierte la fecha almacenada para utilizarla en un campo datetime-local.
     *
     * @param string $value Fecha almacenada en base de datos.
     * @return string
     */
    private function format_datetime_for_input( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        $value   = trim( (string) $value );
        $value   = str_replace( 'T', ' ', $value );
        $formats = array(
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'd/m/Y H:i',
            'd/m/Y',
        );
        $tz      = wp_timezone();

        foreach ( $formats as $format ) {
            $datetime = date_create_from_format( $format, $value, $tz );

            if ( $datetime instanceof \DateTime ) {
                return $datetime->format( 'Y-m-d\TH:i' );
            }
        }

        $timestamp = strtotime( $value );

        if ( ! $timestamp ) {
            return '';
        }

        $datetime = new \DateTime( '@' . $timestamp );
        $datetime->setTimezone( $tz );

        return $datetime->format( 'Y-m-d\TH:i' );
    }

    /**
     * Normaliza un valor recibido desde un campo datetime-local.
     *
     * @param string $value Valor original.
     * @return string|null
     */
    private function normalize_datetime_local_input( $value ) {
        if ( empty( $value ) ) {
            return null;
        }

        $value = trim( (string) $value );

        if ( '' === $value ) {
            return null;
        }

        $value   = str_replace( 'T', ' ', $value );
        $formats = array( 'Y-m-d H:i', 'Y-m-d H:i:s' );
        $tz      = wp_timezone();

        foreach ( $formats as $format ) {
            $datetime = date_create_from_format( $format, $value, $tz );

            if ( $datetime instanceof \DateTime ) {
                return $datetime->format( 'Y-m-d H:i:s' );
            }
        }

        $timestamp = strtotime( $value );

        if ( ! $timestamp ) {
            return sanitize_text_field( $value );
        }

        return wp_date( 'Y-m-d H:i:s', $timestamp );
    }

    /**
     * Normaliza un estado operacional para asegurar valores soportados.
     *
     * @param string $status Estado recibido.
     * @return string
     */
    private function normalize_operational_status_value( $status ) {
        $status   = sanitize_key( $status );
        $allowed  = array_keys( tradutema_crm_operational_statuses() );

        if ( in_array( $status, $allowed, true ) ) {
            return $status;
        }

        switch ( $status ) {
            case 'nuevo':
                return 'recibido';
            case 'en_produccion':
            case 'en_curso':
                return 'asignado_en_curso';
            case 'incidencia':
            case 'cancelado':
            case 'esperando_validacion':
            case 'esperando_validacion_cliente':
            case 'en_espera_validacion':
            case 'espera_validacion_cliente':
                return 'en_espera_validacion_cliente';
            case 'en_espera_tasacion':
            case 'espera_tasacion':
            case 'esperando_tasacion':
                return 'en_espera_tasacion';
        }

        return 'recibido';
    }

    /**
     * Guarda una plantilla de correo.
     *
     * @param array $data Datos de la plantilla.
     * @return int|false
     */
    private function save_email_template( array $data ) {
        global $wpdb;

        $this->ensure_email_template_recipients_field();

        $table = $wpdb->prefix . 'ttm_email_templates';
        $fields = array(
            'nombre'      => $data['nombre'],
            'asunto'      => $data['asunto'],
            'destinatarios' => $data['destinatarios'],
            'cuerpo_html' => $data['cuerpo'],
            'activo'      => $data['activo'],
            'estado_operacional' => '' !== $data['estado_operacional'] ? $data['estado_operacional'] : null,
        );

        if ( ! empty( $data['id'] ) ) {
            $updated = $wpdb->update( $table, $fields, array( 'id' => absint( $data['id'] ) ) );
            return false === $updated ? false : absint( $data['id'] );
        }

        $inserted = $wpdb->insert( $table, $fields );

        return $inserted ? $wpdb->insert_id : false;
    }

    /**
     * Elimina una plantilla de correo.
     *
     * @param int $template_id ID de la plantilla.
     * @return bool
     */
    private function delete_email_template( $template_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'ttm_email_templates';

        return (bool) $wpdb->delete( $table, array( 'id' => $template_id ) );
    }

    /**
     * Obtiene todas las plantillas disponibles.
     *
     * @return array
     */
    private function get_email_templates() {
        global $wpdb;

        $this->ensure_email_template_recipients_field();

        $table = $wpdb->prefix . 'ttm_email_templates';

        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY nombre ASC", ARRAY_A );
    }

    /**
     * Obtiene únicamente las plantillas activas para el envío desde pedidos.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_active_email_templates() {
        global $wpdb;

        $this->ensure_email_template_recipients_field();

        $table = $wpdb->prefix . 'ttm_email_templates';

        return $wpdb->get_results( "SELECT * FROM {$table} WHERE activo = 1 ORDER BY nombre ASC", ARRAY_A );
    }

    /**
     * Obtiene una plantilla de correo concreta.
     *
     * @param int $template_id ID de la plantilla.
     * @return array|null
     */
    private function get_email_template( $template_id ) {
        global $wpdb;

        $this->ensure_email_template_recipients_field();

        $table = $wpdb->prefix . 'ttm_email_templates';

        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $template_id ), ARRAY_A );
    }

    /**
     * Envía un correo usando Solid Mail si está disponible, con `wp_mail` como respaldo.
     *
     * Solid Mail registra los envíos cuando se usan sus funciones internas, por lo que
     * intentamos varias posibles APIs antes de recurrir a `wp_mail`.
     *
     * @param string|array $to          Destinatario(s).
     * @param string       $subject     Asunto.
     * @param string       $message     Cuerpo del mensaje.
     * @param string|array $headers     Cabeceras.
     * @param array        $attachments Adjuntos.
     *
     * @return bool True si el envío fue exitoso.
     */
    private function send_email_via_solid_mail( $to, $subject, $message, $headers = array(), $attachments = array() ) {
        $headers     = $this->ensure_bcc_header( $headers );
        $attachments = is_array( $attachments ) ? $attachments : array( $attachments );

        if ( function_exists( 'solid_mail_send' ) ) {
            $sent = solid_mail_send( $to, $subject, $message, $headers, $attachments );

            if ( $this->is_successful_solid_mail_result( $sent ) ) {
                return true;
            }
        }

        if ( function_exists( 'solid_mail' ) ) {
            $sent = solid_mail( $to, $subject, $message, $headers, $attachments );

            if ( $this->is_successful_solid_mail_result( $sent ) ) {
                return true;
            }
        }

        if ( class_exists( '\\SolidMail\\Mailer' ) ) {
            if ( method_exists( '\\SolidMail\\Mailer', 'send' ) ) {
                $sent = \SolidMail\Mailer::send( $to, $subject, $message, $headers, $attachments );

                if ( $this->is_successful_solid_mail_result( $sent ) ) {
                    return true;
                }
            }

            if ( method_exists( '\\SolidMail\\Mailer', 'send_wp_mail' ) ) {
                $sent = \SolidMail\Mailer::send_wp_mail( $to, $subject, $message, $headers, $attachments );

                if ( $this->is_successful_solid_mail_result( $sent ) ) {
                    return true;
                }
            }
        }

        if ( class_exists( '\\SolidWP\\Mail\\Mail' ) ) {
            if ( method_exists( '\\SolidWP\\Mail\\Mail', 'send' ) ) {
                $sent = \SolidWP\Mail\Mail::send( $to, $subject, $message, $headers, $attachments );

                if ( $this->is_successful_solid_mail_result( $sent ) ) {
                    return true;
                }
            }

            if ( method_exists( '\\SolidWP\\Mail\\Mail', 'send_wp_mail' ) ) {
                $sent = \SolidWP\Mail\Mail::send_wp_mail( $to, $subject, $message, $headers, $attachments );

                if ( $this->is_successful_solid_mail_result( $sent ) ) {
                    return true;
                }
            }
        }

        return wp_mail( $to, $subject, $message, $headers, $attachments );
    }

    /**
     * Evalúa si la respuesta de Solid Mail indica un envío exitoso.
     *
     * @param mixed $result Resultado devuelto por Solid Mail.
     * @return bool
     */
    private function is_successful_solid_mail_result( $result ) {
        if ( is_wp_error( $result ) ) {
            return false;
        }

        return true === $result || 1 === $result;
    }

    /**
     * Asegura que todos los envíos incluyan una copia oculta al correo definido.
     *
     * @param string|array $headers Cabeceras actuales.
     * @return array Cabeceras con la copia oculta agregada.
     */
    private function ensure_bcc_header( $headers ) {
        $bcc_email = sanitize_email( self::BCC_EMAIL );

        if ( ! $bcc_email ) {
            return (array) $headers;
        }

        if ( ! is_array( $headers ) ) {
            $headers = array_filter( array_map( 'trim', preg_split( '/\r?\n/', (string) $headers ) ) );
        }

        $normalized_headers = array();
        $has_bcc            = false;

        foreach ( $headers as $key => $header ) {
            // Respeta las cabeceras ya definidas como string, array o clave asociativa.
            if ( is_string( $key ) && 0 === stripos( $key, 'bcc' ) ) {
                $has_bcc = true;

                if ( is_array( $header ) ) {
                    foreach ( $header as $bcc_value ) {
                        $normalized_headers[] = 'Bcc: ' . trim( (string) $bcc_value );
                    }
                } else {
                    $normalized_headers[] = 'Bcc: ' . trim( (string) $header );
                }

                continue;
            }

            if ( is_array( $header ) ) {
                foreach ( $header as $header_line ) {
                    $normalized_headers[] = trim( (string) $header_line );
                }
            } else {
                $header_line = trim( (string) $header );

                if ( 0 === stripos( $header_line, 'bcc:' ) ) {
                    $has_bcc = true;
                }

                if ( '' !== $header_line ) {
                    $normalized_headers[] = $header_line;
                }
            }
        }

        if ( ! $has_bcc ) {
            $normalized_headers[] = 'Bcc: ' . $bcc_email;
        }

        return $normalized_headers;
    }

    /**
     * Convierte un valor vacío en null para la base de datos.
     *
     * @param string $value Valor original.
     * @return string|null
     */
    private function null_if_empty( $value ) {
        $value = sanitize_text_field( $value );

        return '' === $value ? null : $value;
    }

    /**
     * Redirige a la pantalla de gestión de pedidos conservando los avisos.
     *
     * @param int $order_id ID del pedido.
     */
    private function redirect_to_manage_order( $order_id ) {
        $args = array(
            'page' => self::MANAGE_ORDER_PAGE,
        );

        if ( $order_id > 0 ) {
            $args['order_id'] = $order_id;
        }

        $redirect = add_query_arg( $args, admin_url( 'admin.php' ) );

        $this->store_notices_for_redirect();
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Redirige de vuelta a la página indicada.
     *
     * @param string $page_slug Slug de la página de administración.
     */
    private function redirect_back( $page_slug ) {
        $redirect = add_query_arg( array( 'page' => $page_slug ), admin_url( 'admin.php' ) );
        $this->store_notices_for_redirect();
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Guarda los avisos actuales para mostrarlos tras una redirección.
     */
    private function store_notices_for_redirect() {
        $notices = get_settings_errors( 'tradutema-crm' );

        if ( empty( $notices ) ) {
            return;
        }

        set_transient( 'tradutema_crm_notices', $notices, 30 );
    }
}

// Compatibilidad hacia atrás con el cargador original.
class_alias( Core::class, 'Tradutema_CRM_Core' );
