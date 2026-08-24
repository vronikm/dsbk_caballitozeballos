<?php
/*
| Panel de entrada de DigiSports League.
|
| El módulo está en fase 0: existen los cimientos —estados, transiciones,
| auditoría, permisos estrictos y punto de emisión— pero todavía ninguna
| entidad de competición.
|
| Esta pantalla no finge lo contrario. Muestra el estado real de la
| instalación, porque durante la fase 0 lo único que hay que poder
| responder es «¿quedó bien montado?», y esa pregunta merece una respuesta
| en pantalla y no una consulta a mano contra la base de datos.
*/

use league\controllers\leagueController;

$insLeague = new leagueController();

$tituloVista = 'Panel';
$vistaActual = 'panel';

$diagnostico = $insLeague->diagnostico();
$estadosPartido = $insLeague->estados('partido');

/* Se toma un estado real del catálogo en lugar de escribir 'PROGRAMADO'
   aquí: si alguien renombra el código, esta pantalla lo refleja en vez de
   quedarse mostrando un dato que ya no existe. */
$estadoInicial = '';
foreach ($estadosPartido as $e) {
    if ($e['estado_final'] === 'N') { $estadoInicial = $e['estado_codigo']; break; }
}
$transiciones = $estadoInicial !== ''
    ? $insLeague->transicionesDesde('partido', $estadoInicial)
    : [];

require_once __DIR__ . "/inc/layout-top.php";
?>

<div class="callout" style="border-left-color: var(--ds-league);">
    <h5 class="mb-1"><i class="fas fa-trophy me-2"></i>League está en fase 0</h5>
    <p class="mb-0 text-muted">
        Los cimientos del módulo están montados. Todavía no hay temporadas,
        torneos ni equipos: esas pantallas llegan en la fase 1.
    </p>
</div>

<!-- ==================== Diagnóstico de la instalación ==================== -->
<div class="row">
    <?php foreach ($diagnostico as $clave => $d): ?>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-body d-flex align-items-start">
                    <span class="me-3" style="font-size:1.6rem;line-height:1;
                          color: var(--ds-<?php echo $d['ok'] ? 'success' : 'warning'; ?>);">
                        <i class="fas fa-<?php echo $d['ok'] ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    </span>
                    <div style="min-width:0;">
                        <div class="text-muted"
                             style="font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;font-weight:700;">
                            <?php echo htmlspecialchars($d['etiqueta'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div style="font-size:1.35rem;font-weight:700;line-height:1.25;">
                            <?php echo htmlspecialchars((string)$d['valor'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="text-muted" style="font-size:.85rem;">
                            <?php echo htmlspecialchars($d['detalle'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row">
    <!-- ==================== Estados del partido ==================== -->
    <div class="col-lg-7 mb-3">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title mb-0">Ciclo de vida del partido</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th class="text-center">Terminal</th>
                                <th class="text-center">Cuenta</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($estadosPartido as $e): ?>
                            <tr>
                                <td>
                                    <span class="badge text-bg-<?php
                                        echo ['exito' => 'success', 'aviso' => 'warning',
                                              'peligro' => 'danger', 'info' => 'info'][$e['estado_tono']] ?? 'secondary';
                                    ?>"><?php echo htmlspecialchars($e['estado_nombre'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td class="text-center text-muted">
                                    <?php echo $e['estado_final'] === 'S' ? 'Sí' : '—'; ?>
                                </td>
                                <td class="text-center text-muted">
                                    <?php echo $e['estado_efectivo'] === 'S' ? 'Sí' : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer text-muted" style="font-size:.85rem;">
                <strong>Terminal</strong> significa que no sale ninguna transición.
                <strong>Cuenta</strong>, que el partido entra en la clasificación:
                un walkover es terminal y suma; uno cancelado es terminal y no.
            </div>
        </div>
    </div>

    <!-- ==================== Transiciones legales ==================== -->
    <div class="col-lg-5 mb-3">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title mb-0">
                    Movimientos desde
                    «<?php echo htmlspecialchars($estadoInicial, ENT_QUOTES, 'UTF-8'); ?>»
                </h3>
            </div>
            <div class="card-body">
                <?php if (!$transiciones): ?>
                    <p class="text-muted mb-0">No hay transiciones registradas.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                    <?php foreach ($transiciones as $t): ?>
                        <li class="mb-2 d-flex align-items-baseline">
                            <i class="fas fa-arrow-right me-2 text-muted" style="font-size:.75rem;"></i>
                            <span>
                                <strong><?php echo htmlspecialchars($t['accion'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span class="text-muted">
                                    → <?php echo htmlspecialchars($t['estado_nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php if ($t['exige_motivo'] === 'S'): ?>
                                    <span class="badge text-bg-light border ms-1"
                                          title="No se puede ejecutar sin justificación escrita">exige motivo</span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="card-footer text-muted" style="font-size:.85rem;">
                Lo que no está en esta lista no se puede hacer. La regla vive en
                <code>dsl_estado_transicion</code>, no repartida por los controladores.
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . "/inc/layout-bottom.php"; ?>
