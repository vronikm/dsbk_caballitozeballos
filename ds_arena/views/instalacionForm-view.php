<?php
/* Alta y edición de una instalación (cancha o residencia). */

use arena\controllers\arenaController;

$insArena = new arenaController();

$id      = (int)($_GET['id'] ?? 0);
$inst    = $id > 0 ? $insArena->instalacion($id) : null;
$esAlta  = ($inst === null);

if ($esAlta && !puede_crear('instalacionList')) {
    require_once __DIR__ . "/accesoDenegado-view.php"; exit();
}
if (!$esAlta && !puede_editar('instalacionList')) {
    require_once __DIR__ . "/accesoDenegado-view.php"; exit();
}

$tituloVista = $esAlta ? 'Nueva instalación' : 'Editar instalación';
$vistaActual = 'instalacionList';

$sedes = $insArena->sedesAlquiler();
$pisos = $insArena->tiposPiso();

require_once __DIR__ . "/inc/layout-top.php";
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?php echo $tituloVista; ?></h3></div>

            <form class="FormularioAjax" method="POST" action="<?php echo APP_URL; ?>ajax/arenaAjax.php">
                <input type="hidden" name="modulo_arena" value="guardarInstalacion">
                <input type="hidden" name="instalacion_id" value="<?php echo $id; ?>">

                <div class="card-body">

                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="instalacion_clase">Tipo <span class="text-danger">*</span></label>
                            <select class="form-control" id="instalacion_clase" name="instalacion_clase" required>
                                <option value="C" <?php echo ($inst['instalacion_clase'] ?? 'C') === 'C' ? 'selected' : ''; ?>>Cancha</option>
                                <option value="R" <?php echo ($inst['instalacion_clase'] ?? '') === 'R' ? 'selected' : ''; ?>>Residencia</option>
                            </select>
                        </div>

                        <div class="form-group col-md-8">
                            <label for="instalacion_sedeid">Sede <span class="text-danger">*</span></label>
                            <select class="form-control" id="instalacion_sedeid" name="instalacion_sedeid" required>
                                <option value="">Seleccione…</option>
                                <?php foreach ($sedes as $s): ?>
                                    <option value="<?php echo (int)$s['sede_id']; ?>"
                                        <?php echo (int)($inst['instalacion_sedeid'] ?? 0) === (int)$s['sede_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['sede_nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Sólo aparecen las sedes marcadas como de alquiler.</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="instalacion_codigo">Código <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="instalacion_codigo" name="instalacion_codigo"
                                   maxlength="20" required
                                   value="<?php echo htmlspecialchars((string)($inst['instalacion_codigo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <small class="text-muted">Único dentro de la sede. Ej.: CAN-01</small>
                        </div>

                        <div class="form-group col-md-8">
                            <label for="instalacion_nombre">Nombre <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="instalacion_nombre" name="instalacion_nombre"
                                   maxlength="80" required
                                   value="<?php echo htmlspecialchars((string)($inst['instalacion_nombre'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>

                    <!-- Sólo aplica a canchas -->
                    <div class="form-row" id="camposCancha">
                        <div class="form-group col-md-6">
                            <label for="instalacion_cubierta">Cubierta</label>
                            <select class="form-control" id="instalacion_cubierta" name="instalacion_cubierta">
                                <option value="S" <?php echo ($inst['instalacion_cubierta'] ?? 'S') === 'S' ? 'selected' : ''; ?>>Sí, techada</option>
                                <option value="N" <?php echo ($inst['instalacion_cubierta'] ?? '') === 'N' ? 'selected' : ''; ?>>No, al aire libre</option>
                            </select>
                        </div>

                        <div class="form-group col-md-6">
                            <label for="instalacion_pisoid">Tipo de piso</label>
                            <select class="form-control" id="instalacion_pisoid" name="instalacion_pisoid">
                                <option value="0">Sin especificar</option>
                                <?php foreach ($pisos as $p): ?>
                                    <option value="<?php echo (int)$p['piso_id']; ?>"
                                        <?php echo (int)($inst['instalacion_pisoid'] ?? 0) === (int)$p['piso_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['piso_nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="instalacion_valorhora">Valor por hora (USD) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" class="form-control"
                                   id="instalacion_valorhora" name="instalacion_valorhora" required
                                   value="<?php echo htmlspecialchars((string)($inst['instalacion_valorhora'] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?>">
                            <small class="text-muted">Tarifa base. Puede afinarse por franja horaria.</small>
                        </div>

                        <div class="form-group col-md-4">
                            <label for="instalacion_capacidad">Capacidad</label>
                            <input type="number" min="0" class="form-control"
                                   id="instalacion_capacidad" name="instalacion_capacidad"
                                   value="<?php echo htmlspecialchars((string)($inst['instalacion_capacidad'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <small class="text-muted">Personas o plazas.</small>
                        </div>

                        <div class="form-group col-md-4">
                            <label for="instalacion_estado">Estado</label>
                            <select class="form-control" id="instalacion_estado" name="instalacion_estado">
                                <option value="A" <?php echo ($inst['instalacion_estado'] ?? 'A') === 'A' ? 'selected' : ''; ?>>Activa</option>
                                <option value="I" <?php echo ($inst['instalacion_estado'] ?? '') === 'I' ? 'selected' : ''; ?>>Inactiva</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label for="instalacion_detalle">Descripción</label>
                        <textarea class="form-control" id="instalacion_detalle" name="instalacion_detalle"
                                  rows="2" maxlength="250"><?php echo htmlspecialchars((string)($inst['instalacion_detalle'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-between">
                    <a href="<?php echo APP_URL; ?>instalacionList/" class="btn btn-secondary">
                        <i class="fas fa-arrow-left mr-1"></i> Volver
                    </a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* Cubierta y tipo de piso sólo tienen sentido en canchas. */
(function () {
    var clase  = document.getElementById('instalacion_clase');
    var cancha = document.getElementById('camposCancha');

    function alternar() {
        cancha.style.display = (clase.value === 'C') ? '' : 'none';
    }

    clase.addEventListener('change', alternar);
    alternar();
})();
</script>

<?php require_once __DIR__ . "/inc/layout-bottom.php"; ?>
