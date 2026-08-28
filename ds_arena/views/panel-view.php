<?php
/* Panel de entrada de DigiSports Arena. */

use arena\controllers\arenaController;

$insArena = new arenaController();

$tituloVista = 'Panel';
$vistaActual = 'panel';

$resumen = $insArena->resumen();
$saldos  = $insArena->saldosPendientes();
$sedes   = $insArena->sedesAlquiler();

require_once __DIR__ . "/inc/layout-top.php";
?>

<div class="row">
    <?php foreach ($resumen as $r): ?>
        <div class="col-12 col-sm-6 col-lg-3">
            <div class="info-box">
                <span class="info-box-icon bg-<?php echo $r['color']; ?> shadow-sm">
                    <i class="<?php echo $r['icono']; ?>" aria-hidden="true"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text"><?php echo htmlspecialchars($r['etiqueta'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="info-box-number"><?php echo (int)$r['valor']; ?></span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row">
    <div class="col-lg-7 mb-3">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Reservas con saldo pendiente</h3>
                <a href="<?php echo APP_URL; ?>reservaList/" class="ds-link">Ver reservas →</a>
            </div>
            <div class="card-body p-0">
                <?php if (!$saldos): ?>
                    <p class="text-center text-muted py-4 mb-0">No hay abonos pendientes.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Reserva</th>
                                    <th>Cliente</th>
                                    <th>Fecha</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">Abonado</th>
                                    <th class="text-end">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($saldos as $s): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($s['reserva_codigo'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                    <td><?php echo htmlspecialchars($s['cliente_nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo $s['reserva_fecha']; ?></td>
                                    <td class="text-end">$<?php echo number_format((float)$s['reserva_total'], 2); ?></td>
                                    <td class="text-end text-success">$<?php echo number_format((float)$s['reserva_abonado'], 2); ?></td>
                                    <td class="text-end text-danger"><strong>$<?php echo number_format((float)$s['reserva_saldo'], 2); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5 mb-3">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Sedes con alquiler</h3></div>
            <div class="card-body">
                <?php if (!$sedes): ?>
                    <div class="aviso-superadmin">
                        <i class="fas fa-info-circle fa-lg mt-1"></i>
                        <div>
                            <strong>Ninguna sede ofrece alquiler todavía.</strong><br>
                            Marque las sedes como <em>Alquiler</em> o <em>Formativa y Alquiler</em>
                            para que Arena pueda administrarlas.
                        </div>
                    </div>
                <?php else: ?>
                    <ul class="ds-list">
                        <?php foreach ($sedes as $s): ?>
                            <li>
                                <span class="ds-dot ds-dot--success"></span>
                                <span>
                                    <span class="ds-item__title"><?php echo htmlspecialchars($s['sede_nombre'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="ds-item__meta">
                                        <?php echo $s['sede_tipoingreso'] === 'STA' ? 'Sólo alquiler' : 'Formativa y alquiler'; ?>
                                    </span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . "/inc/layout-bottom.php"; ?>
