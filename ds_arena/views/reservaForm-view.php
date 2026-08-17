<?php
/*
| Alta y edición de una reserva.
| El importe se calcula por horas × tarifa; el servidor vuelve a calcularlo
| y a comprobar disponibilidad al guardar, así que lo que se muestra aquí
| es una previsualización, no la fuente de verdad.
*/

use arena\controllers\arenaController;

$insArena = new arenaController();

$id      = (int)($_GET['id'] ?? 0);
$reserva = $id > 0 ? $insArena->reserva($id) : null;
$esAlta  = ($reserva === null);

if ($esAlta && !puede_crear('reservaList'))  { require_once __DIR__ . "/accesoDenegado-view.php"; exit(); }
if (!$esAlta && !puede_editar('reservaList')) { require_once __DIR__ . "/accesoDenegado-view.php"; exit(); }

$tituloVista = $esAlta ? 'Nueva reserva' : 'Editar reserva ' . $reserva['reserva_codigo'];
$vistaActual = 'reservaList';

$clientes      = $insArena->clientes();
$instalaciones = $insArena->instalaciones();
$dias          = $insArena->dias();

/* Tarifas y disponibilidad de cada instalación, para la previsualización
   en el navegador. La validación real ocurre en el servidor. */
$datosInst = [];
foreach ($instalaciones as $i) {
    $franjas = [];
    foreach ($insArena->horarios((int)$i['instalacion_id']) as $h) {
        $franjas[] = ['dia'   => (int)$h['horario_dia'],
                      'desde' => substr($h['horario_desde'], 0, 5),
                      'hasta' => substr($h['horario_hasta'], 0, 5)];
    }
    $datosInst[(int)$i['instalacion_id']] = [
        'valorhora' => (float)$i['instalacion_valorhora'],
        'franjas'   => $franjas,
    ];
}

require_once __DIR__ . "/inc/layout-top.php";
?>

<?php if (!$clientes || !$instalaciones): ?>

    <div class="aviso-superadmin">
        <i class="fas fa-info-circle fa-lg mt-1"></i>
        <div>
            <strong>Falta información básica para reservar.</strong><br>
            <?php if (!$instalaciones): ?>
                No hay instalaciones registradas. <a href="<?php echo APP_URL; ?>instalacionList/">Crear una →</a><br>
            <?php endif; ?>
            <?php if (!$clientes): ?>
                No hay clientes registrados. <a href="<?php echo APP_URL; ?>clienteForm/">Crear uno →</a>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?php echo $tituloVista; ?></h3></div>

            <form class="FormularioAjax" method="POST" action="<?php echo APP_URL; ?>ajax/arenaAjax.php">
                <input type="hidden" name="modulo_arena" value="guardarReserva">
                <input type="hidden" name="reserva_id" value="<?php echo $id; ?>">

                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="reserva_clienteid">Cliente <span class="text-danger">*</span></label>
                            <select class="form-control" id="reserva_clienteid" name="reserva_clienteid" required>
                                <option value="">Seleccione…</option>
                                <?php foreach ($clientes as $c): ?>
                                    <option value="<?php echo (int)$c['cliente_id']; ?>"
                                        <?php echo (int)($reserva['reserva_clienteid'] ?? 0) === (int)$c['cliente_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['cliente_nombre'] . ' · ' . $c['cliente_identificacion'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group col-md-6">
                            <label for="reserva_instalacionid">Instalación <span class="text-danger">*</span></label>
                            <select class="form-control" id="reserva_instalacionid" name="reserva_instalacionid" required>
                                <option value="">Seleccione…</option>
                                <?php foreach ($instalaciones as $i): ?>
                                    <option value="<?php echo (int)$i['instalacion_id']; ?>"
                                        <?php echo (int)($reserva['reserva_instalacionid'] ?? 0) === (int)$i['instalacion_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($i['instalacion_codigo'] . ' · ' . $i['instalacion_nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                        — $<?php echo number_format((float)$i['instalacion_valorhora'], 2); ?>/h
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" id="avisoFranjas"></small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="reserva_fecha">Fecha <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="reserva_fecha" name="reserva_fecha" required
                                   value="<?php echo htmlspecialchars((string)($reserva['reserva_fecha'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group col-md-4">
                            <label for="reserva_horainicio">Desde <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="reserva_horainicio" name="reserva_horainicio" required
                                   value="<?php echo substr((string)($reserva['reserva_horainicio'] ?? ''), 0, 5); ?>">
                        </div>

                        <div class="form-group col-md-4">
                            <label for="reserva_horafin">Hasta <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="reserva_horafin" name="reserva_horafin" required
                                   value="<?php echo substr((string)($reserva['reserva_horafin'] ?? ''), 0, 5); ?>">
                        </div>
                    </div>

                    <!-- Previsualización del importe -->
                    <div class="ds-kpi mb-3" id="cajaImporte" style="display:none;">
                        <span class="ds-kpi__icono bg-info text-white"><i class="fas fa-calculator"></i></span>
                        <span>
                            <span class="ds-kpi__valor" id="importeTotal">$0.00</span>
                            <span class="ds-kpi__label" id="importeDetalle"></span>
                        </span>
                    </div>

                    <div class="form-group mb-0">
                        <label for="reserva_observacion">Observación</label>
                        <textarea class="form-control" id="reserva_observacion" name="reserva_observacion"
                                  rows="2" maxlength="250"><?php echo htmlspecialchars((string)($reserva['reserva_observacion'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <?php if (!$esAlta && (float)$reserva['reserva_abonado'] > 0): ?>
                        <div class="aviso-superadmin mt-3">
                            <i class="fas fa-coins fa-lg mt-1"></i>
                            <div>
                                Esta reserva ya tiene <strong>$<?php echo number_format((float)$reserva['reserva_abonado'], 2); ?></strong> abonados.
                                El nuevo importe no puede quedar por debajo de esa cifra.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card-footer d-flex justify-content-between">
                    <a href="<?php echo APP_URL; ?>reservaList/" class="btn btn-secondary">
                        <i class="fas fa-arrow-left mr-1"></i> Volver
                    </a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var datos = <?php echo json_encode($datosInst, JSON_UNESCAPED_UNICODE); ?>;
    var dias  = <?php echo json_encode($dias, JSON_UNESCAPED_UNICODE); ?>;

    var inst   = document.getElementById('reserva_instalacionid');
    var fecha  = document.getElementById('reserva_fecha');
    var desde  = document.getElementById('reserva_horainicio');
    var hasta  = document.getElementById('reserva_horafin');
    var caja   = document.getElementById('cajaImporte');
    var total  = document.getElementById('importeTotal');
    var detalle= document.getElementById('importeDetalle');
    var aviso  = document.getElementById('avisoFranjas');

    /* Día ISO (1=lunes) a partir de una fecha 'YYYY-MM-DD', sin zona horaria. */
    function diaIso(valor) {
        var p = valor.split('-');
        var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])).getUTCDay();
        return d === 0 ? 7 : d;
    }

    function mostrarFranjas() {
        var d = datos[inst.value];
        if (!d) { aviso.textContent = ''; return; }

        if (!d.franjas.length) {
            aviso.innerHTML = '<span class="text-warning">Sin disponibilidad definida. Configúrela en Horarios.</span>';
            return;
        }

        if (!fecha.value) { aviso.textContent = ''; return; }

        var dia = diaIso(fecha.value);
        var del = d.franjas.filter(function (f) { return f.dia === dia; });

        aviso.innerHTML = del.length
            ? 'Disponible el ' + dias[dia].toLowerCase() + ': ' +
              del.map(function (f) { return f.desde + '–' + f.hasta; }).join(', ')
            : '<span class="text-warning">Cerrado el ' + dias[dia].toLowerCase() + '.</span>';
    }

    function calcular() {
        mostrarFranjas();

        var d = datos[inst.value];
        if (!d || !desde.value || !hasta.value || hasta.value <= desde.value) {
            caja.style.display = 'none';
            return;
        }

        var a = desde.value.split(':'), b = hasta.value.split(':');
        var horas = ((+b[0] * 60 + +b[1]) - (+a[0] * 60 + +a[1])) / 60;

        caja.style.display = '';
        total.textContent = '$' + (horas * d.valorhora).toFixed(2);
        detalle.textContent = horas.toFixed(2).replace(/\.00$/, '') +
                              ' h × $' + d.valorhora.toFixed(2) + ' por hora (estimado)';
    }

    [inst, fecha, desde, hasta].forEach(function (c) {
        c.addEventListener('change', calcular);
    });

    calcular();
})();
</script>

<?php endif; ?>

<?php require_once __DIR__ . "/inc/layout-bottom.php"; ?>
