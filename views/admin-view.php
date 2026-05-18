<?php
/**
 * Vista de administración para el Corrector de Notas SPN.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap spn-corrector-wrap">
    <!-- Header -->
    <div class="spn-header-card">
        <div class="spn-header-content">
            <span class="spn-badge-category">Herramientas de Saneamiento</span>
            <h1 class="spn-title">Auditoría y Corrección de Notas</h1>
            <p class="spn-subtitle">Recalcula las calificaciones de los alumnos afectadas por la adición o eliminación posterior de preguntas en los tests.</p>
        </div>
        <div class="spn-header-accent"></div>
    </div>

    <!-- Panel informativo de instrucciones (Fases seguras) -->
    <div class="spn-instruction-card">
        <div class="spn-instruction-icon">
            <span class="dashicons dashicons-shield"></span>
        </div>
        <div class="spn-instruction-text">
            <h3>Flujo de trabajo</h3>
            <ol>
                <li><strong>1. Escanear (Dry-Run):</strong> El sistema analizará toda la base de datos por lotes sin modificar nada y detectará discrepancias de notas.</li>
                <li><strong>2. Auditar:</strong> Podrás ver al detalle en la tabla de pre-visualización los alumnos afectados, el test, la nota vieja y la nota corregida.</li>
                <li><strong>3. Aplicar Cambios:</strong> El botón de escritura en base de datos se habilitará solo tras completar el escaneo. Al pulsarlo, se corregirá la base de datos de forma segura.</li>
            </ol>
        </div>
    </div>

    <!-- Tarjetas de Estadísticas (Widgets) -->
    <div class="spn-stats-row">
        <div class="spn-stat-card card-blue">
            <div class="stat-info">
                <span class="stat-label">Alumnos Analizados</span>
                <span class="stat-value" id="spn-stat-scanned">0</span>
            </div>
            <div class="stat-icon">
                <span class="dashicons dashicons-groups"></span>
            </div>
        </div>
        <div class="spn-stat-card card-purple">
            <div class="stat-info">
                <span class="stat-label">Intentos Evaluados</span>
                <span class="stat-value" id="spn-stat-attempts">0</span>
            </div>
            <div class="stat-icon">
                <span class="dashicons dashicons-media-text"></span>
            </div>
        </div>
        <div class="spn-stat-card card-amber">
            <div class="stat-info">
                <span class="stat-label">Intentos Erróneos</span>
                <span class="stat-value text-warning" id="spn-stat-affected">0</span>
            </div>
            <div class="stat-icon">
                <span class="dashicons dashicons-warning"></span>
            </div>
        </div>
        <div class="spn-stat-card card-emerald">
            <div class="stat-info">
                <span class="stat-label">Impacto de Notas</span>
                <span class="stat-value text-success" id="spn-stat-impact">0.00</span>
            </div>
            <div class="stat-icon">
                <span class="dashicons dashicons-chart-line"></span>
            </div>
        </div>
    </div>

    <!-- Controles de Acción -->
    <div class="spn-actions-card">
        <div class="action-buttons">
            <button type="button" class="spn-btn spn-btn-primary" id="spn-btn-scan">
                <span class="dashicons dashicons-search"></span>
                Escanear Base de Datos (Simular)
            </button>
            <button type="button" class="spn-btn spn-btn-danger spn-btn-disabled" id="spn-btn-apply" disabled>
                <span class="dashicons dashicons-saved"></span>
                Aplicar Correcciones en Base de Datos
            </button>
        </div>
        <div class="action-info">
            <span class="spn-badge-info" id="spn-status-badge">Listo para iniciar</span>
        </div>
    </div>

    <!-- Contenedor de Progreso AJAX (Oculto inicialmente) -->
    <div class="spn-progress-card" id="spn-progress-container" style="display: none;">
        <div class="progress-header">
            <h3 id="spn-progress-title">Procesando simulación...</h3>
            <span class="progress-percentage" id="spn-progress-text">0%</span>
        </div>
        <div class="progress-bar-wrapper">
            <div class="progress-bar-fill" id="spn-progress-bar" style="width: 0%;"></div>
        </div>
        <div class="progress-subtext" id="spn-progress-subtext">Cargando lote inicial de alumnos...</div>
    </div>

    <!-- Tabla de Auditoría e Intentos Afectados -->
    <div class="spn-table-card">
        <div class="table-header">
            <h2>Pre-visualización de discrepancias detectadas</h2>
            <div class="table-actions">
                <span class="table-legend">Mostrando solo discrepancias de cálculo</span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="wp-list-table widefat fixed striped table-view-list" id="spn-audit-table">
                <thead>
                    <tr>
                        <th class="col-email">Alumno (Email)</th>
                        <th class="col-test">Test afectado</th>
                        <th class="col-attempt">Nº intento</th>
                        <th class="col-date">Fecha intento</th>
                        <th class="col-answers">Preguntas (Resp. / Previas vs. Act.)</th>
                        <th class="col-score">Nota DB</th>
                        <th class="col-score-new">Nota Real</th>
                        <th class="col-diff">Ajuste</th>
                        <th class="col-status">Estado</th>
                    </tr>
                </thead>
                <tbody id="spn-audit-table-body">
                    <tr class="spn-row-empty">
                        <td colspan="9">
                            <div class="spn-empty-state">
                                <span class="dashicons dashicons-database-search"></span>
                                <p>Presiona <strong>"Escanear base de datos"</strong> para iniciar el diagnóstico.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
