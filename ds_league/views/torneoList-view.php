<?php
/*
| Torneos de una temporada.
|
| El id de la temporada viene en la URL. Si no viene, se listan todos los
| torneos: la pantalla sirve igual como índice general que como detalle de
| una temporada, y así el menú puede apuntar aquí sin parámetros.
*/

use league\controllers\competenciaController;

$insLeague = new competenciaController();

$temporadaId = (int)(explode('/', (string)($_GET['views'] ?? ''))[1] ?? 0);

$temporadas = $insLeague->temporadas();
$torneos    = $insLeague->torneos($temporadaId);

$temporada = [];
foreach ($temporadas as $t) {
    if ((int)$t['temporada_id'] === $temporadaId) { $temporada = $t; break; }
}

$tituloVista = $temporada ? 'Torneos · ' . $temporada['temporada_nombre'] : 'Torneos';
$vistaActual = 'torneoList';

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

require_once __DIR__ . "/inc/layout-top.php";
?>

<?php if (!$temporadas): ?>
<div class="callout callout-warning">
    <h6 class="mb-1"><i class="fas fa-exclamation-circle mr-2"></i>No hay temporadas</h6>
    <p class="mb-0 text-muted">
        Un torneo pertenece a una temporada.
        <a href="<?php echo APP_URL; ?>temporadaList/">Cree una primero</a>.
    </p>
</div>
<?php else: ?>

<div class="row">
    <div class="col-lg-7 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><i class="fas fa-trophy mr-2"></i>Torneos</h3>
                <?php if ($temporadaId > 0): ?>
                    <a href="<?php echo APP_URL; ?>torneoList/" class="ds-link">Ver todos →</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Torneo</th>
                                <th>Temporada</th>
                                <th>Deporte</th>
                                <th class="text-right">Categorías</th>
                                <th class="ds-tabla-acciones"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$torneos): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">
                                No hay torneos<?php echo $temporadaId > 0 ? ' en esta temporada' : ''; ?>.
                            </td></tr>
                        <?php else: foreach ($torneos as $o): ?>
                            <tr>
                                <td><strong><?php echo $h($o['torneo_nombre']); ?></strong></td>
                                <td class="text-muted"><?php echo $h($o['temporada_nombre']); ?></td>
                                <td class="text-muted"><?php echo $h($o['torneo_deporte']); ?></td>
                                <td class="text-right"><?php echo (int)$o['categorias']; ?></td>
                                <td class="ds-tabla-acciones">
                                    <a href="<?php echo APP_URL; ?>categoriaList/<?php echo (int)$o['torneo_id']; ?>/"
                                       class="btn btn-sm btn-ver" title="Ver categorías">
                                        <i class="fas fa-layer-group"></i>
                                    </a>
                                    <?php if (puede_editar('torneoList')): ?>
                                    <button type="button" class="btn btn-sm btn-actualizar js-editar" title="Editar"
                                            data-fila='<?php echo $h(json_encode([
                                                'torneo_id'          => (int)$o['torneo_id'],
                                                'torneo_nombre'      => $o['torneo_nombre'],
                                                'torneo_temporadaid' => (int)$o['torneo_temporadaid'],
                                                'torneo_deporte'     => $o['torneo_deporte'],
                                            ], JSON_UNESCAPED_UNICODE)); ?>'>
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5 mb-3">
        <?php if (puede_crear('torneoList') || puede_editar('torneoList')): ?>
        <form class="FormularioAjax" method="POST" autocomplete="off"
              action="<?php echo APP_URL; ?>ajax/leagueAjax.php" id="formFila">
            <input type="hidden" name="modulo_league" value="guardarTorneo">
            <input type="hidden" name="torneo_id" id="torneo_id" value="0">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title" id="tituloForm">
                        <i class="fas fa-plus mr-2"></i>Nuevo torneo
                    </h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="torneo_nombre">Nombre</label>
                        <input type="text" name="torneo_nombre" id="torneo_nombre"
                               class="form-control" maxlength="120" required>
                    </div>
                    <div class="form-group">
                        <label for="torneo_temporadaid">Temporada</label>
                        <select name="torneo_temporadaid" id="torneo_temporadaid" class="form-control" required>
                            <?php foreach ($temporadas as $t): ?>
                                <option value="<?php echo (int)$t['temporada_id']; ?>"
                                    <?php echo (int)$t['temporada_id'] === $temporadaId ? 'selected' : ''; ?>>
                                    <?php echo $h($t['temporada_nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label for="torneo_deporte">Deporte</label>
                        <select name="torneo_deporte" id="torneo_deporte" class="form-control">
                            <option value="baloncesto">Baloncesto</option>
                        </select>
                        <small class="form-text text-muted">
                            El campo existe desde el principio para que otro deporte sea
                            configuración y no un cambio de esquema.
                        </small>
                    </div>
                </div>
                <?php echo ds_acciones_form(APP_URL . 'temporadaList/', ['limpiar' => true]); ?>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . "/inc/editor-fila.php"; ?>
<?php require_once __DIR__ . "/inc/layout-bottom.php"; ?>
