<?php
/**
 * Plugin Name: Corregir Notas SPN
 * Description: Herramienta avanzada para escanear, auditar y corregir retroactivamente las notas distorsionadas de los tests de la academia.
 * Version: 1.0.0
 * Author: Antigravity AI
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

// Definir constantes del plugin
define('SPN_CORRECTOR_DIR', plugin_dir_path(__FILE__));
define('SPN_CORRECTOR_URL', plugin_dir_url(__FILE__));

// Requerir la clase lógica central del plugin
require_once SPN_CORRECTOR_DIR . 'includes/class-spn-corrector.php';

// Inicializar la clase correctora
add_action('plugins_loaded', function() {
    SPN_Corrector::get_instance();
});

// Registrar menús de administración de forma defensiva y robusta
add_action('admin_menu', 'spn_corrector_register_admin_menu');
function spn_corrector_register_admin_menu() {
    global $menu;
    $parent_exists = false;
    if (is_array($menu)) {
        foreach ($menu as $item) {
            if (isset($item[2]) && $item[2] === 'up-subscribed-users') {
                $parent_exists = true;
                break;
            }
        }
    }

    $page_title = 'Corregir Notas de Tests';
    $menu_title = 'Corregir Notas';
    $capability = 'manage_options';
    $menu_slug  = 'spn-corregir-notas';
    $callback   = 'spn_corrector_render_admin_page';

    if ($parent_exists) {
        add_submenu_page(
            'up-subscribed-users',
            $page_title,
            $menu_title,
            $capability,
            $menu_slug,
            $callback
        );
    } else {
        add_menu_page(
            $page_title,
            $menu_title,
            $capability,
            $menu_slug,
            $callback,
            'dashicons-welcome-write-paper',
            26
        );
    }
}

/**
 * Encolar estilos CSS y scripts JavaScript AJAX para el dashboard del corrector.
 */
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'Alumnos_page_spn-corregir-notas' && $hook !== 'toplevel_page_spn-corregir-notas' && strpos($hook, 'spn-corregir-notas') === false) {
        return;
    }
    
    // Cargar estilos premium y dependencias
    wp_enqueue_style('spn-corrector-admin-css', SPN_CORRECTOR_URL . 'assets/css/admin-style.css', array(), '1.0.0');
    
    // Cargar script JS de control AJAX
    wp_enqueue_script('spn-corrector-admin-js', SPN_CORRECTOR_URL . 'assets/js/admin-ajax.js', array('jquery'), '1.0.0', true);
    
    // Localizar variables de AJAX
    wp_localize_script('spn-corrector-admin-js', 'spn_corrector_vars', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('spn_corrector_nonce'),
    ));
});

/**
 * Renderizar la página de administración del plugin.
 */
function spn_corrector_render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos suficientes para acceder a esta página.', 'api-spn'));
    }
    
    // Incluir la vista del dashboard
    if (file_exists(SPN_CORRECTOR_DIR . 'views/admin-view.php')) {
        include SPN_CORRECTOR_DIR . 'views/admin-view.php';
    } else {
        echo '<div class="notice notice-error"><p>Error: No se encuentra la vista del panel de administración.</p></div>';
    }
}
