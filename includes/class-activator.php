<?php
/**
 * Activador del plugin.
 *
 * @package Tradutema_CRM
 */

namespace Tradutema_CRM;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase encargada de la activación del plugin.
 */
class Activator {

    /**
     * Ejecuta tareas de activación.
     */
    public static function activate() {
        self::register_role();
        self::create_tables();
    }

    /**
     * Registra el rol Gestor Tradutema.
     */
    private static function register_role() {
        add_role(
            'tradutema_manager',
            __( 'Gestor Tradutema', 'tradutema-crm' ),
            array(
                'read'                 => true,
                'manage_tradutema_crm' => true,
                'list_users'           => false,
            )
        );

        $administrator = get_role( 'administrator' );

        if ( $administrator && ! $administrator->has_cap( 'manage_tradutema_crm' ) ) {
            $administrator->add_cap( 'manage_tradutema_crm' );
        }
    }

    /**
     * Crea las tablas personalizadas mediante dbDelta.
     */
    private static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $tables = array();

        $tables[] = "CREATE TABLE {$wpdb->prefix}ttm_order_meta (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            proveedor_id BIGINT UNSIGNED NULL,
            comentario_interno LONGTEXT NULL,
            comentario_linguistico LONGTEXT NULL,
            referencia VARCHAR(190) NULL,
            origen_pedido VARCHAR(20) NOT NULL DEFAULT 'woocommerce',
            envio_papel TINYINT(1) NOT NULL DEFAULT 0,
            fecha_prevista_entrega DATE NULL,
            hora_prevista_entrega TIME NULL,
            fecha_real_entrega_pdf DATETIME NULL,
            idioma_origen VARCHAR(50) NULL,
            idioma_destino VARCHAR(50) NULL,
            num_paginas INT NULL,
            tarifa_aplicada DECIMAL(10,2) NULL,
            estado_operacional VARCHAR(30) NOT NULL DEFAULT 'recibido',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY order_id (order_id),
            PRIMARY KEY (id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}ttm_proveedores (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre_comercial VARCHAR(190) NOT NULL,
            persona_contacto VARCHAR(190) NULL,
            email VARCHAR(190) NULL,
            telefono VARCHAR(50) NULL,
            whatsapp VARCHAR(50) NULL,
            pais VARCHAR(190) NULL,
            ciudad VARCHAR(190) NULL,
            direccion VARCHAR(255) NULL,
            idiomas TEXT NULL,
            pares_idiomas TEXT NULL,
            tarifa_por_palabra DECIMAL(10,4) NULL,
            tarifa_minima DECIMAL(10,2) NULL,
            tarifa_minima_text TEXT NULL,
            tarifa_urgente DECIMAL(10,2) NULL,
            tarifa_repeticiones DECIMAL(10,2) NULL,
            tarifa_maquetacion DECIMAL(10,2) NULL,
            tarifa_interno TEXT NULL,
            interno TINYINT(1) NOT NULL DEFAULT 0,
            disponibilidad VARCHAR(100) NULL,
            horario VARCHAR(190) NULL,
            metodos_pago VARCHAR(190) NULL,
            cuenta_bancaria VARCHAR(190) NULL,
            paypal VARCHAR(190) NULL,
            comentarios LONGTEXT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'activo',
            rating DECIMAL(3,2) NULL,
            fecha_alta DATE NULL,
            fecha_baja DATE NULL,
            nif VARCHAR(50) NULL,
            tipo_servicio VARCHAR(100) NULL,
            web VARCHAR(190) NULL,
            linkedin VARCHAR(190) NULL,
            skype VARCHAR(190) NULL,
            zoom VARCHAR(190) NULL,
            telegram VARCHAR(190) NULL,
            slack VARCHAR(190) NULL,
            documentos LONGTEXT NULL,
            pares_servicio LONGTEXT NULL,
            seguro_responsabilidad VARCHAR(100) NULL,
            certificado_iva VARCHAR(100) NULL,
            firma_contrato TINYINT(1) NOT NULL DEFAULT 0,
            idioma_nativo VARCHAR(100) NULL,
            software_traduccion VARCHAR(190) NULL,
            capacidad_diaria INT NULL,
            descuento_frecuente DECIMAL(5,2) NULL,
            recargo_fin_semana DECIMAL(5,2) NULL,
            notas_facturacion LONGTEXT NULL,
            datos_facturacion LONGTEXT NULL,
            direccion_recogida LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}ttm_email_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(190) NOT NULL,
            asunto VARCHAR(255) NOT NULL,
            destinatarios TEXT NULL,
            cuerpo_html LONGTEXT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            estado_operacional VARCHAR(30) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}ttm_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            tipo VARCHAR(50) NOT NULL,
            detalle VARCHAR(255) NULL,
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }
    }
}

// Alias para mantener compatibilidad con el cargador principal.
class_alias( Activator::class, 'Tradutema_CRM_Activator' );
