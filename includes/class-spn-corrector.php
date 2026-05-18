<?php
/**
 * Clase lógica central del corrector de notas SPN.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPN_Corrector {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Registrar llamadas AJAX de administración
        add_action('wp_ajax_spn_corrector_get_users', array($this, 'ajax_get_users'));
        add_action('wp_ajax_spn_corrector_scan_batch', array($this, 'ajax_scan_batch'));
        add_action('wp_ajax_spn_corrector_apply_batch', array($this, 'ajax_apply_batch'));
    }

    /**
     * Paso 1: Obtener todos los IDs de usuario activos en WordPress para iniciar la barra de progreso.
     */
    public function ajax_get_users() {
        check_ajax_referer('spn_corrector_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }

        // Obtener solo usuarios que tengan intentos de tests
        global $wpdb;
        $uids = $wpdb->get_col("
            SELECT DISTINCT user_id 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'test_attempts'
        ");

        // Si no hay ninguno con metadato directo, devolvemos todos los alumnos de WooCommerce / suscriptores
        if (empty($uids)) {
            $users = get_users(array('fields' => 'ID'));
            $uids = array_map('intval', $users);
        } else {
            $uids = array_map('intval', $uids);
        }

        wp_send_json_success(array(
            'uids' => $uids,
            'total' => count($uids)
        ));
    }

    /**
     * Paso 2: Escanear un lote de usuarios (Dry-Run / Simulación).
     * Calcula discrepancias sin modificar nada.
     */
    public function ajax_scan_batch() {
        check_ajax_referer('spn_corrector_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }

        $user_ids = isset($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : array();
        $discrepancies = array();

        foreach ($user_ids as $uid) {
            $user_data = get_userdata($uid);
            if (!$user_data) continue;
            
            $email = $user_data->user_email;
            $display_name = $user_data->display_name ?: $user_data->user_login;

            $attempts = get_user_meta($uid, 'test_attempts', true);
            if (!is_array($attempts)) continue;

            foreach ($attempts as $test_id) {
                $attempt_history = get_user_meta($uid, 'test_attempt_' . $test_id, true);
                if (!is_array($attempt_history) || empty($attempt_history['attempts'])) continue;

                $test_title = isset($attempt_history['name']) ? $attempt_history['name'] : get_the_title($test_id);

                foreach ($attempt_history['attempts'] as $idx => $att) {
                    $score_stored = isset($att['score']) ? (float)$att['score'] : 0.0;
                    $risked_stored = isset($att['risked_score']) ? (float)$att['risked_score'] : 0.0;

                    // Recalcular nota usando el denominador adaptativo dinámico
                    $recalc = $this->recalculate_attempt_scores($att);
                    if (!$recalc) continue;

                    $score_new = $recalc['score'];
                    $risked_new = $recalc['risked_score'];

                    // Comprobar si existe discrepancia matemática
                    $diff_score = abs($score_stored - $score_new);
                    $diff_risked = abs($risked_stored - $risked_new);

                    if ($diff_score > 0.001 || $diff_risked > 0.001) {
                        $discrepancies[] = array(
                            'user_id' => $uid,
                            'email' => $email,
                            'name' => $display_name,
                            'test_id' => $test_id,
                            'test_title' => html_entity_decode($test_title, ENT_QUOTES, 'UTF-8'),
                            'attempt_idx' => $idx + 1,
                            'attempted_at' => isset($att['attempted_at']) ? $att['attempted_at'] : 'Desconocida',
                            'score_stored' => $score_stored,
                            'risked_stored' => $risked_stored,
                            'score_new' => $score_new,
                            'risked_new' => $risked_new,
                            'total_questions' => isset($att['details']['total_questions']) ? (int)$att['details']['total_questions'] : 0,
                            'answered' => isset($att['details']['total_answered']) ? (int)$att['details']['total_answered'] : 0,
                        );
                    }
                }
            }
        }

        wp_send_json_success(array(
            'discrepancies' => $discrepancies
        ));
    }

    /**
     * Paso 3: Aplicar correcciones reales a un lote de usuarios.
     * Modifica los metadatos de los intentos y limpia la caché.
     */
    public function ajax_apply_batch() {
        check_ajax_referer('spn_corrector_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }

        $user_ids = isset($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : array();
        $corrected_count = 0;

        foreach ($user_ids as $uid) {
            $attempts = get_user_meta($uid, 'test_attempts', true);
            if (!is_array($attempts)) continue;

            $user_modified = false;

            foreach ($attempts as $test_id) {
                $attempt_history = get_user_meta($uid, 'test_attempt_' . $test_id, true);
                if (!is_array($attempt_history) || empty($attempt_history['attempts'])) continue;

                $test_modified = false;

                foreach ($attempt_history['attempts'] as $idx => &$att) {
                    $score_stored = isset($att['score']) ? (float)$att['score'] : 0.0;
                    $risked_stored = isset($att['risked_score']) ? (float)$att['risked_score'] : 0.0;

                    // Recalcular
                    $recalc = $this->recalculate_attempt_scores($att);
                    if (!$recalc) continue;

                    $score_new = $recalc['score'];
                    $risked_new = $recalc['risked_score'];

                    // Comprobar discrepancia
                    if (abs($score_stored - $score_new) > 0.001 || abs($risked_stored - $risked_new) > 0.001) {
                        $att['score'] = $score_new;
                        $att['risked_score'] = $risked_new;
                        $test_modified = true;
                        $user_modified = true;
                        $corrected_count++;
                    }
                }

                if ($test_modified) {
                    update_user_meta($uid, 'test_attempt_' . $test_id, $attempt_history);
                }
            }
        }

        // Limpiar la caché de estadísticas del admin al detectar cualquier modificación
        delete_transient('spn_users_status_cache_v2');

        wp_send_json_success(array(
            'corrected_count' => $corrected_count
        ));
    }

    /**
     * Algoritmo de Recálculo del Denominador Dinámico.
     * @param array $att Intento crudo
     * @return array|false Con claves 'score' y 'risked_score' o false si los datos del intento no son íntegros
     */
    private function recalculate_attempt_scores(array $att) {
        if (!isset($att['details']) || !is_array($att['details'])) {
            return false;
        }

        $details = $att['details'];
        $correct_count = isset($details['correct']) ? (int)$details['correct'] : 0;
        $incorrect_count = isset($details['incorrect']) ? (int)$details['incorrect'] : 0;
        $answered_count = isset($details['answered_ids']) && is_array($details['answered_ids']) ? count($details['answered_ids']) : 0;

        // Si no hay respuestas ni aciertos registrados, no se puede corregir
        if ($answered_count === 0 && $correct_count === 0) {
            return false;
        }

        // Lógica del Denominador Dinámico:
        // Si el admin borró preguntas en WordPress del test, total_questions será inferior a las respuestas que el alumno entregó.
        // Usamos el máximo entre las preguntas del test en vivo guardadas y la cantidad de respuestas entregadas en el intento.
        $total_questions_stored = isset($details['total_questions']) ? (int)$details['total_questions'] : 0;
        $denominator = max($total_questions_stored, $answered_count);
        $denominator = max(1, $denominator); // Evitar división por cero

        // Calcular respuestas arriesgadas y no arriesgadas
        $risked = isset($details['risked']) ? $details['risked'] : null;
        $risked_correct = 0;
        $risked_incorrect = 0;

        if (is_array($risked)) {
            $risked_correct = isset($risked['correct']) ? (int)$risked['correct'] : 0;
            $risked_incorrect = isset($risked['incorrect']) ? (int)$risked['incorrect'] : 0;
        }

        // Nota sin riesgo (descontando las que arriesgó)
        $non_risked_correct = max(0, $correct_count - $risked_correct);
        $non_risked_incorrect = max(0, $incorrect_count - $risked_incorrect);

        $score = ($non_risked_correct - $non_risked_incorrect * 0.5) / $denominator * 10;
        
        // Nota con riesgo (cuenta todos los aciertos y fallos tradicionales)
        $risked_score = ($correct_count - $incorrect_count * 0.5) / $denominator * 10;

        return array(
            'score' => min(10.0, max((float)$score, 0.0)),
            'risked_score' => min(10.0, max((float)$risked_score, 0.0))
        );
    }
}
