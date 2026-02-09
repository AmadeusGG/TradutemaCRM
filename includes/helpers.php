<?php
/**
 * Funciones helper comunes para Tradutema CRM.
 *
 * @package Tradutema_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Obtiene un valor del array de forma segura.
 *
 * @param array  $array   Datos origen.
 * @param string $key     Clave.
 * @param mixed  $default Valor por defecto.
 * @return mixed
 */
function tradutema_array_get( $array, $key, $default = '' ) {
    return isset( $array[ $key ] ) ? $array[ $key ] : $default;
}

/**
 * Devuelve la lista de estados operacionales disponibles.
 *
 * @return array
 */
function tradutema_crm_operational_statuses() {
    return array(
        'recibido'                        => __( '01-Recibido.', 'tradutema-crm' ),
        'en_espera_tasacion'              => __( '02-En espera de tasación.', 'tradutema-crm' ),
        'asignado_en_curso'               => __( '03-Asignado y en curso.', 'tradutema-crm' ),
        'traducido'                       => __( '04-Traducido.', 'tradutema-crm' ),
        'en_espera_validacion_cliente'    => __( '05-En espera validación cliente.', 'tradutema-crm' ),
        'entregado'                       => __( '06-Entregado.', 'tradutema-crm' ),
    );
}

/**
 * Devuelve la lista de orígenes de pedido.
 *
 * @return array
 */
function tradutema_crm_order_origins() {
    return array(
        'woocommerce' => __( 'WooCommerce', 'tradutema-crm' ),
        'cotizacion'  => __( 'Cotización', 'tradutema-crm' ),
        'manual'      => __( 'Manual', 'tradutema-crm' ),
    );
}

/**
 * Formatea fechas para salida.
 *
 * @param string|null $date Fecha.
 * @return string
 */
function tradutema_crm_format_date( $date ) {
    if ( empty( $date ) ) {
        return '';
    }

    $timestamp = strtotime( $date );
    if ( ! $timestamp ) {
        return '';
    }

    return esc_html( date_i18n( get_option( 'date_format' ), $timestamp ) );
}

/**
 * Devuelve la página actual del CRM dentro del escritorio.
 *
 * @return string
 */
function tradutema_crm_current_admin_page() {
    return isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'tradutema-crm'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Opciones de navegación del panel del CRM.
 *
 * @return array<int, array<string, string>>
 */
function tradutema_crm_admin_nav_items() {
    return array(
        array(
            'page'  => 'tradutema-crm',
            'label' => __( 'Dashboard', 'tradutema-crm' ),
        ),
        array(
            'page'  => 'tradutema-crm-proveedores',
            'label' => __( 'Proveedores', 'tradutema-crm' ),
        ),
        array(
            'page'  => 'tradutema-crm-plantillas',
            'label' => __( 'Plantillas de email', 'tradutema-crm' ),
        ),
    );
}
