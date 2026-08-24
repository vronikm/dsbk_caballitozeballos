<?php
/*
| Configuración de carnets: color de cada mes y política de reimpresión.
|
| Vive en Core por el mismo criterio que la facturación: es configuración
| del sistema, no operación diaria. Sólo el superadministrador entra.
|
| El color del mes es una decisión con efectos irreversibles: en cuanto se
| emite el primer carnet de un mes, ese mes queda bloqueado, porque
| cambiarlo dejaría en circulación carnets de un color que ya no coincide.
|
| Los cambios se guardan contra el módulo Basketball, que es donde está la
| lógica de validación y el histórico de carnets emitidos.
*/

use admin\controllers\coreController;

if (!es_superadministrador()) {
    http_response_code(403);
    require_once __DIR__ . "/accesoDenegado-view.php";
    exit();
}

$insCore = new coreController();

$meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
          5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
          9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

$porMes   = $insCore->coloresCarnetPorMes();
$colores  = $insCore->catalogoColoresCarnet();
$politica = $insCore->configuracionCarnet();

$tituloVista = 'Carnets';
$vistaActual = 'carnetConfig';

/* La validación y el guardado siguen en Basketball. */
$ajaxCarnet = DS_BASKETBALL_URL . 'app/ajax/carnetAjax.php';

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$bloqueados = 0;
foreach ($porMes as $m) { if ($m['bloqueado']) { $bloqueados++; } }

require_once __DIR__ . "/inc/layout-top.php";
?>

<div class="aviso-superadmin mb-3">
    <i class="fas fa-shield-alt fa-lg mt-1"></i>
    <div>
        <strong>Sólo el superadministrador.</strong><br>
        Un mes queda bloqueado en cuanto se emite su primer carnet: cambiarle el color
        dejaría en circulación carnets que ya no coinciden con el sistema.
        Imprimir carnets es otra cosa y se concede por rol sobre
        «Carnets del mes» en <a href="<?php echo APP_URL; ?>permisoRol/">Permisos</a>.
    </div>
</div>

<form class="FormularioAjax" method="POST" action="<?php echo $ajaxCarnet; ?>" autocomplete="off">
    <input type="hidden" name="modulo_carnet" value="actualizar_colores">

    <div class="row">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-money-check-alt me-2"></i>Reimpresión</h3>
                </div>
                <div class="card-body">
                    <p class="text-muted" style="font-size:.9rem;">
                        Cuando está activo, reponer un carnet extraviado genera el cobro configurado.
                    </p>

                    <!-- El campo oculto asegura que llegue un 0 si no se marca -->
                    <input type="hidden" name="cobrar_reimpresion" value="0">
                    <?php /* Bootstrap 5 sustituye custom-control/custom-switch
                             por form-check form-switch, y las clases internas
                             pasan a form-check-input y form-check-label.
                             font-weight-bold es ahora fw-bold. */ ?>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" class="form-check-input" role="switch"
                               id="cobrar_reimpresion" name="cobrar_reimpresion" value="1"
                               <?php echo $politica['cobrar'] ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="cobrar_reimpresion">
                            Cobrar la reimpresión
                        </label>
                    </div>

                    <div class="mb-3 mb-0">
                        <label for="valor_reimpresion">Valor del rubro</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control text-end" id="valor_reimpresion"
                                   name="valor_reimpresion" min="0.01" step="0.01" required
                                   value="<?php echo number_format($politica['valor'], 2, '.', ''); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-palette me-2"></i>Estado</h3>
                </div>
                <div class="card-body">
                    <dl class="row mb-0" style="font-size:.9rem;">
                        <dt class="col-7 text-muted font-weight-normal">Meses bloqueados</dt>
                        <dd class="col-5"><strong><?php echo $bloqueados; ?></strong> de 12</dd>
                        <dt class="col-7 text-muted font-weight-normal">Colores en catálogo</dt>
                        <dd class="col-5"><strong><?php echo count($colores); ?></strong></dd>
                    </dl>
                    <?php if (!$colores): ?>
                        <p class="text-warning mb-0 mt-2" style="font-size:.9rem;">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            No hay colores activos en el catálogo.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-calendar-alt me-2"></i>Color por mes</h3>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="width:38%">Mes</th>
                                    <th>Color</th>
                                    <th style="width:80px">Muestra</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($meses as $num => $nombre):
                                $datos    = $porMes[$num];
                                $bloqueado = $datos['bloqueado']; ?>
                                <tr>
                                    <td>
                                        <?php echo $nombre; ?>
                                        <?php if ($bloqueado): ?>
                                            <span class="badge text-bg-warning ms-1">
                                                <i class="fas fa-lock"></i> Bloqueado
                                            </span>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo $datos['total_carnets']; ?> carnet(s) emitido(s)
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <select class="form-control color-mes" data-mes="<?php echo $num; ?>"
                                                name="color_mes[<?php echo $num; ?>]"
                                                <?php echo $bloqueado ? 'disabled' : ''; ?>>
                                            <option value="0" data-color="#FFFFFF">— Sin asignar —</option>
                                            <?php foreach ($colores as $c):
                                                /* Un color sólo puede estar en un mes: se ofrece si
                                                   está libre o si ya es el de este mes. */
                                                $esActual = (int)$c['catcolor_id'] === $datos['color_id'];
                                                $libre    = (int)$c['asignado_a'] === 0 || $esActual; ?>
                                                <option value="<?php echo (int)$c['catcolor_id']; ?>"
                                                        data-color="<?php echo $h($c['catcolor_hex']); ?>"
                                                        <?php echo $esActual ? 'selected' : ''; ?>
                                                        <?php echo $libre ? '' : 'disabled'; ?>>
                                                    <?php echo $h($c['catcolor_nombre']); ?><?php echo $libre ? '' : ' (ya asignado)'; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($bloqueado): ?>
                                            <!-- Un select deshabilitado no se envía: el valor viaja aparte -->
                                            <input type="hidden" name="color_mes[<?php echo $num; ?>]"
                                                   value="<?php echo $datos['color_id']; ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="muestra-color" data-mes="<?php echo $num; ?>"
                                              style="display:block;height:28px;border-radius:var(--ds-radius-sm);
                                                     border:1px solid var(--core-borde);
                                                     background-color:<?php echo $h($datos['color_hex']); ?>"></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php echo ds_acciones_form(APP_URL . 'panel/', ['guardar' => 'Guardar']); ?>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    /* La muestra se pinta con el color que ya viaja en cada <option>:
       no hace falta preguntarle nada al servidor. */
    document.querySelectorAll('.color-mes').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var opcion  = this.options[this.selectedIndex];
            var muestra = document.querySelector('.muestra-color[data-mes="' + this.dataset.mes + '"]');
            if (muestra) {
                muestra.style.backgroundColor = opcion.dataset.color || '#FFFFFF';
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . "/inc/layout-bottom.php"; ?>
