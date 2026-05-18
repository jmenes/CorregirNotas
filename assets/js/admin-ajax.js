/**
 * Control AJAX por lotes para el plugin Corregir Notas SPN
 */

jQuery(document).ready(function($) {
    // Variables de control de estado
    let allUserIds = [];
    let affectedUserIds = new Set(); // Guardar qué usuarios tienen discrepancias
    let currentBatchIndex = 0;
    const batchSize = 10; // Procesar de 10 en 10 usuarios para evitar sobrecarga del servidor

    // Elementos del DOM
    const $btnScan = $('#spn-btn-scan');
    const $btnApply = $('#spn-btn-apply');
    const $statusBadge = $('#spn-status-badge');
    const $progressContainer = $('#spn-progress-container');
    const $progressTitle = $('#spn-progress-title');
    const $progressBar = $('#spn-progress-bar');
    const $progressText = $('#spn-progress-text');
    const $progressSubtext = $('#spn-progress-subtext');
    const $tableBody = $('#spn-audit-table-body');

    // Métricas
    const $statScanned = $('#spn-stat-scanned');
    const $statAttempts = $('#spn-stat-attempts');
    const $statAffected = $('#spn-stat-affected');
    const $statImpact = $('#spn-stat-impact');

    let totalAttemptsEvaluated = 0;
    let totalAffectedDetected = 0;
    let totalImpactSum = 0.0;
    let scannedUsersCount = 0;

    // --- FASE 1: ESCANEAR BASE DE DATOS (SIMULACIÓN / DRY-RUN) ---
    $btnScan.on('click', function(e) {
        e.preventDefault();
        
        // Resetear interfaz y estados
        allUserIds = [];
        affectedUserIds.clear();
        currentBatchIndex = 0;
        totalAttemptsEvaluated = 0;
        totalAffectedDetected = 0;
        totalImpactSum = 0.0;
        scannedUsersCount = 0;

        $statScanned.text('0');
        $statAttempts.text('0');
        $statAffected.text('0');
        $statImpact.text('0.00');

        $btnScan.prop('disabled', true).addClass('spn-btn-disabled');
        $btnApply.prop('disabled', true).addClass('spn-btn-disabled');
        
        $tableBody.html(`
            <tr class="spn-row-empty">
                <td colspan="10">
                    <div class="spn-empty-state">
                        <span class="spinner is-active" style="float:none; margin-bottom:15px;"></span>
                        <p>Analizando la base de datos y localizando alumnos...</p>
                    </div>
                </td>
            </tr>
        `);

        $statusBadge.text('Obteniendo alumnos...');
        $progressContainer.show();
        updateProgressBar(0, 'Iniciando conexión con WordPress...', 'Analizando base de datos...');

        // Llamada AJAX inicial para obtener todos los usuarios a procesar
        $.ajax({
            url: spn_corrector_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'spn_corrector_get_users',
                nonce: spn_corrector_vars.nonce
            },
            success: function(response) {
                if (response.success && response.data.uids.length > 0) {
                    allUserIds = response.data.uids;
                    $statusBadge.text(`Se han cargado ${allUserIds.length} alumnos.`);
                    // Limpiar tabla antes de rellenar
                    $tableBody.empty();
                    // Iniciar el procesamiento por lotes del escaneo
                    scanNextBatch();
                } else {
                    $tableBody.html(`
                        <tr class="spn-row-empty">
                            <td colspan="10">
                                <div class="spn-empty-state">
                                    <span class="dashicons dashicons-yes-alt text-success"></span>
                                    <p>No se encontraron alumnos con tests realizados.</p>
                                </div>
                            </td>
                        </tr>
                    `);
                    resetActionButtons(false);
                    $progressContainer.hide();
                }
            },
            error: function() {
                showTableError('Error crítico al conectar con el servidor para listar usuarios.');
                resetActionButtons(false);
                $progressContainer.hide();
            }
        });
    });

    function scanNextBatch() {
        if (currentBatchIndex >= allUserIds.length) {
            // Escaneo finalizado
            finishScan();
            return;
        }

        const batch = allUserIds.slice(currentBatchIndex, currentBatchIndex + batchSize);
        const percent = Math.round((currentBatchIndex / allUserIds.length) * 100);
        updateProgressBar(
            percent, 
            `Procesando simulación: Alumnos ${currentBatchIndex + 1} a ${Math.min(currentBatchIndex + batch.length, allUserIds.length)} de ${allUserIds.length}`,
            'Analizando discrepancias de notas...'
        );

        $.ajax({
            url: spn_corrector_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'spn_corrector_scan_batch',
                nonce: spn_corrector_vars.nonce,
                user_ids: batch
            },
            success: function(response) {
                if (response.success) {
                    const discrepancies = response.data.discrepancies;
                    
                    // Contabilizar estadísticas de este lote
                    scannedUsersCount += batch.length;
                    $statScanned.text(scannedUsersCount);

                    // Procesar discrepancias encontradas
                    if (discrepancies.length > 0) {
                        discrepancies.forEach(function(item) {
                            totalAffectedDetected++;
                            affectedUserIds.add(item.user_id);
                            
                            const diff = item.score_new - item.score_stored;
                            totalImpactSum += diff;

                            const diffClass = diff < 0 ? 'diff-negative' : 'diff-positive';
                            const diffSign = diff > 0 ? '+' : '';

                            const rowHtml = `
                                <tr class="spn-user-row-${item.user_id}">
                                    <td class="col-email"><strong>${item.name}</strong><br><small style="color:var(--spn-text-muted);">${item.email}</small></td>
                                    <td class="col-test"><span class="dashicons dashicons-welcome-write-paper" style="font-size:16px;vertical-align:middle;margin-right:4px;color:var(--spn-text-muted);"></span>${item.test_title}</td>
                                    <td class="col-attempt">${item.attempt_idx}</td>
                                    <td class="col-date">${item.attempted_at}</td>
                                    <td class="col-answers"><strong>${item.answered_purged}</strong> / ${item.answered_orig}</td>
                                    <td class="col-current-total"><span class="spn-badge-score" style="background:#fee2e2;color:#b91c1c;font-weight:700;">${item.intruders_purged}</span></td>
                                    <td class="col-score"><span class="spn-badge-score" style="background:#f1f5f9;color:#64748b;">${item.score_stored.toFixed(2)}</span></td>
                                    <td class="col-score-new"><span class="spn-badge-score" style="background:#e0f2fe;color:#0284c7;">${item.score_new.toFixed(2)}</span></td>
                                    <td class="col-diff"><span class="spn-badge-diff ${diffClass}">${diffSign}${diff.toFixed(2)}</span></td>
                                    <td class="col-status"><span class="spn-status-pill status-pending spn-row-status-text">Pendiente</span></td>
                                </tr>
                            `;
                            $tableBody.append(rowHtml);
                        });

                        // Actualizar métricas globales en la UI
                        $statAffected.text(totalAffectedDetected);
                        const avgImpact = totalAffectedDetected > 0 ? (totalImpactSum / totalAffectedDetected) : 0.0;
                        const avgImpactSign = avgImpact > 0 ? '+' : '';
                        $statImpact.text(avgImpactSign + avgImpact.toFixed(2));
                    }

                    // Avanzar al siguiente lote
                    currentBatchIndex += batchSize;
                    scanNextBatch();
                } else {
                    showTableError('Error de procesamiento devuelto por el servidor.');
                    resetActionButtons(false);
                }
            },
            error: function() {
                showTableError('Error de red al procesar el lote de escaneo.');
                resetActionButtons(false);
            }
        });
    }

    function finishScan() {
        updateProgressBar(100, 'Escaneo de simulación finalizado', 'Todos los alumnos auditados con éxito.');
        $statScanned.text(allUserIds.length);
        
        // Si no se encontraron discrepancias
        if (totalAffectedDetected === 0) {
            $tableBody.html(`
                <tr class="spn-row-empty">
                    <td colspan="10">
                        <div class="spn-empty-state">
                            <span class="dashicons dashicons-yes-alt text-success" style="color:var(--spn-success); font-size:48px; width:48px; height:48px;"></span>
                            <p style="font-weight:700;color:var(--spn-success);">¡Enhorabuena! No se han detectado discrepancias de notas.</p>
                            <p style="margin-top:5px;font-size:12px;color:var(--spn-text-muted);">Toda la base de datos se encuentra matemáticamente sana y coherente.</p>
                        </div>
                    </td>
                </tr>
            `);
            $statusBadge.text('Simulación completada: 0 discrepancias.');
            resetActionButtons(false);
        } else {
            // Si hay discrepancias, activamos el botón de aplicar
            $statusBadge.text(`Simulación completada: ${totalAffectedDetected} discrepancias en ${affectedUserIds.size} alumnos.`);
            resetActionButtons(true);
        }
    }


    // --- FASE 2: APLICAR CORRECCIONES EN BASE DE DATOS (FIX MODE) ---
    $btnApply.on('click', function(e) {
        e.preventDefault();

        if (affectedUserIds.size === 0) return;

        const confirmMsg = `ATENCIÓN: Se van a corregir permanentemente ${totalAffectedDetected} notas en la base de datos de ${affectedUserIds.size} alumnos.\n\nEsta operación modificará los expedientes históricos.\n\n¿Estás seguro de que deseas continuar?`;
        if (!confirm(confirmMsg)) {
            return;
        }

        // Bloquear interfaz para evitar interrupciones
        $btnScan.prop('disabled', true).addClass('spn-btn-disabled');
        $btnApply.prop('disabled', true).addClass('spn-btn-disabled');
        
        // Convertir el Set de usuarios afectados a un Array para procesar solo a los que realmente requieren parche
        const affectedUsersArray = Array.from(affectedUserIds);
        currentBatchIndex = 0;

        $statusBadge.text('Aplicando correcciones...');
        updateProgressBar(0, 'Iniciando escritura en la Base de Datos...', 'Guardando notas recalculadas...');

        // Iniciar procesamiento por lotes del guardado
        applyNextBatch(affectedUsersArray);
    });

    function applyNextBatch(usersList) {
        if (currentBatchIndex >= usersList.length) {
            // Corrección finalizada
            finishApply();
            return;
        }

        const batch = usersList.slice(currentBatchIndex, currentBatchIndex + batchSize);
        const percent = Math.round((currentBatchIndex / usersList.length) * 100);
        updateProgressBar(
            percent, 
            `Guardando cambios: Alumnos ${currentBatchIndex + 1} a ${Math.min(currentBatchIndex + batch.length, usersList.length)} de ${usersList.length}`,
            'Actualizando metadatos de usuario y purgando transientes...'
        );

        // Cambiar estado en la tabla visualmente a "Guardando..."
        batch.forEach(function(uid) {
            $(`.spn-user-row-${uid} .spn-row-status-text`)
                .removeClass('status-pending')
                .addClass('status-scanning')
                .text('Guardando...');
        });

        $.ajax({
            url: spn_corrector_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'spn_corrector_apply_batch',
                nonce: spn_corrector_vars.nonce,
                user_ids: batch
            },
            success: function(response) {
                if (response.success) {
                    // Actualizar estado en la tabla visualmente a "Corregido"
                    batch.forEach(function(uid) {
                        $(`.spn-user-row-${uid} .spn-row-status-text`)
                            .removeClass('status-scanning')
                            .addClass('status-corrected')
                            .text('Corregido');
                    });

                    // Avanzar al siguiente lote
                    currentBatchIndex += batchSize;
                    applyNextBatch(usersList);
                } else {
                    batch.forEach(function(uid) {
                        $(`.spn-user-row-${uid} .spn-row-status-text`)
                            .removeClass('status-scanning')
                            .addClass('status-error')
                            .text('Error');
                    });
                    $statusBadge.text('Error crítico al aplicar cambios.');
                    resetActionButtons(false);
                }
            },
            error: function() {
                batch.forEach(function(uid) {
                    $(`.spn-user-row-${uid} .spn-row-status-text`)
                        .removeClass('status-scanning')
                        .addClass('status-error')
                        .text('Error');
                });
                $statusBadge.text('Error de red al aplicar cambios.');
                resetActionButtons(false);
            }
        });
    }

    function finishApply() {
        updateProgressBar(100, 'Correcciones completadas con éxito', 'La base de datos se ha saneado y la caché global se ha purgado.');
        $statusBadge.text('Base de datos corregida con éxito.');
        
        // Resetear botones (deshabilitar aplicar porque ya se guardó)
        $btnScan.prop('disabled', false).removeClass('spn-btn-disabled');
        $btnApply.prop('disabled', true).addClass('spn-btn-disabled');
    }

    // --- FUNCIONES DE SOPORTE E INTERFAZ ---

    function updateProgressBar(percentage, title, subtext) {
        $progressTitle.text(title);
        $progressBar.css('width', percentage + '%');
        $progressText.text(percentage + '%');
        $progressSubtext.text(subtext);
    }

    function resetActionButtons(enableApply) {
        $btnScan.prop('disabled', false).removeClass('spn-btn-disabled');
        if (enableApply) {
            $btnApply.prop('disabled', false).removeClass('spn-btn-disabled');
        } else {
            $btnApply.prop('disabled', true).addClass('spn-btn-disabled');
        }
    }

    function showTableError(message) {
        $tableBody.html(`
            <tr class="spn-row-empty">
                <td colspan="10">
                    <div class="spn-empty-state text-danger" style="color:var(--spn-danger);">
                        <span class="dashicons dashicons-dismiss"></span>
                        <p style="font-weight:700;">Ha ocurrido un error durante la operación</p>
                        <p style="margin-top:5px;font-size:12px;color:var(--spn-text-muted);">${message}</p>
                    </div>
                </td>
            </tr>
        `);
    }
});
