<?php
/* Reservas con su estado de cobro. */

use arena\controllers\arenaController;

$insArena = new arenaController();

$tituloVista = 'Reservas';
$vistaActual = 'reservaList';

$instalaciones = $insArena->instalaciones();

$filtros = [
    'instalacion' => (int)($_GET['instalacion'] ?? 0),
    'estado'      => (string)($_GET['estado'] ?? ''),
    'desde'       => (string)($_GET['desde'] ?? ''),
    'hasta'       => (string)($_GET['hasta'] ?? ''),
    'saldo'       => isset($_GET['saldo']) ? 1 : 0,
];

$reservas = $insArena->reservas($filtros);

$estados = ['P' => 'Pendiente', 'C' => 'Confirmada', 'U' => 'Cumplida', 'X' => 'Cancelada'];
$colores = ['P' => 'warning',   'C' => 'info',       'U' => 'success',  'X' => 'secondary'];

$puedeCrear  = puede_crear('reservaList');
$puedeEditar = puede_editar('reservaList');

$totalGeneral = array_sum(array_map(fn($r) => (float)$r['reserva_total'], $reservas));
$totalSaldo   = array_sum(array_map(fn($r) => (float)$r['reserva_saldo'], $reservas));

require_once __DIR__ . "/inc/layout-top.php";
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center" style="gap:12px;">
        <h3 class="card-title mb-0">
            <?php echo count($reservas); ?> reserva<?php echo count($reservas) === 1 ? '' : 's'; ?>
            <?php if ($totalSaldo > 0): ?>
                <small class="text-danger ms-2">· $<?php echo number_format($totalSaldo, 2); ?> por cobrar</small>
            <?php endif; ?>
        </h3>

        <?php if ($puedeCrear && $instalaciones): ?>
            <a href="<?php echo APP_URL; ?>reservaForm/" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i> Nueva reserva
            </a>
        <?php endif; ?>
    </div>

    <div class="card-body border-bottom">
        <form method="GET" action="<?php echo APP_URL; ?>reservaList/" class="row g-2 align-items-end">
            <div class="col-md-3 mb-2">
                <label class="mb-1 small">Instalación</label>
                <select name="instalacion" class="form-select form-select-sm">
                    <option value="0">Todas</option>
                    <?php foreach ($instalaciones as $i): ?>
                        <option value="<?php echo (int)$i['instalacion_id']; ?>"
                            <?php echo $filtros['instalacion'] === (int)$i['instalacion_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($i['instalacion_codigo'] . ' · ' . $i['instalacion_nombre'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 mb-2">
                <label class="mb-1 small">Estado</label>
                <select name="estado" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($estados as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php echo $filtros['estado'] === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 mb-2">
                <label class="mb-1 small">Desde</label>
                <input type="date" name="desde" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($filtros['desde'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="col-md-2 mb-2">
                <label class="mb-1 small">Hasta</label>
                <input type="date" name="hasta" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($filtros['hasta'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="col-md-3 mb-2 d-flex align-items-center" style="gap:12px;">
                <label class="mb-0 small">
                    <input type="checkbox" name="saldo" value="1" <?php echo $filtros['saldo'] ? 'checked' : ''; ?>>
                    Sólo con saldo
                </label>
                <button type="submit" class="btn btn-sm btn-outline-secondary">Filtrar</button>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Cliente</th>
                        <th>Instalación</th>
                        <th>Cuándo</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Abonado</th>
                        <th class="text-end">Saldo</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$reservas): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No hay reservas que coincidan.</td></tr>
                <?php endif; ?>

                <?php foreach ($reservas as $r):
                    $saldo    = (float)$r['reserva_saldo'];
                    $abonado  = (float)$r['reserva_abonado'];
                    $total    = (float)$r['reserva_total'];
                    $progreso = $total > 0 ? round($abonado / $total * 100) : 0;
                ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($r['reserva_codigo'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                        <td>
                            <strong><?php echo htmlspecialchars($r['cliente_nombre'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <small class="d-block text-muted"><?php echo htmlspecialchars($r['cliente_identificacion'], ENT_QUOTES, 'UTF-8'); ?></small>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($r['instalacion_nombre'], ENT_QUOTES, 'UTF-8'); ?>
                            <small class="d-block text-muted"><?php echo htmlspecialchars((string)$r['sede_nombre'], ENT_QUOTES, 'UTF-8'); ?></small>
                        </td>
                        <td>
                            <?php echo $r['reserva_fecha']; ?>
                            <small class="d-block text-muted">
                                <?php echo substr($r['reserva_horainicio'], 0, 5); ?>–<?php echo substr($r['reserva_horafin'], 0, 5); ?>
                                · <?php echo rtrim(rtrim(number_format((float)$r['reserva_horas'], 2), '0'), '.'); ?> h
                            </small>
                        </td>
                        <td class="text-end">$<?php echo number_format($total, 2); ?></td>
                        <td class="text-end">
                            <span class="text-success">$<?php echo number_format($abonado, 2); ?></span>
                            <?php if ($total > 0): ?>
                                <div class="progress mt-1" style="height:4px;">
                                    <div class="progress-bar bg-success" style="width:<?php echo $progreso; ?>%"></div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($saldo > 0): ?>
                                <strong class="text-danger">$<?php echo number_format($saldo, 2); ?></strong>
                            <?php else: ?>
                                <span class="badge text-bg-success">Pagada</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge text-bg-<?php echo $colores[$r['reserva_estado']] ?? 'secondary'; ?>">
                                <?php echo $estados[$r['reserva_estado']] ?? $r['reserva_estado']; ?>
                            </span>
                        </td>
                        <td class="ds-tabla-acciones">
                            <a href="<?php echo APP_URL; ?>reservaDetalle/?id=<?php echo (int)$r['reserva_id']; ?>"
                               class="btn btn-sm <?php echo $saldo > 0 ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                               title="Abonos">
                                <i class="fas fa-coins"></i><?php echo $saldo > 0 ? ' Cobrar' : ''; ?>
                            </a>

                            <?php if ($puedeEditar && $r['reserva_estado'] !== 'X'): ?>
                                <a href="<?php echo APP_URL; ?>reservaForm/?id=<?php echo (int)$r['reserva_id']; ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Editar"><i class="fas fa-pen"></i></a>

                                <?php if ($r['reserva_estado'] === 'P'): ?>
                                    <form class="FormularioAjax d-inline" method="POST" action="<?php echo APP_URL; ?>ajax/arenaAjax.php">
                                        <input type="hidden" name="modulo_arena" value="cambiarEstadoReserva">
                                        <input type="hidden" name="reserva_id" value="<?php echo (int)$r['reserva_id']; ?>">
                                        <input type="hidden" name="reserva_estado" value="C">
                                        <button type="submit" class="btn btn-sm btn-outline-info" title="Confirmar">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form class="FormularioAjax d-inline" method="POST"
                                      action="<?php echo APP_URL; ?>ajax/arenaAjax.php"
                                      data-confirmar="La reserva quedará cancelada y la franja se liberará.">
                                    <input type="hidden" name="modulo_arena" value="cambiarEstadoReserva">
                                    <input type="hidden" name="reserva_id" value="<?php echo (int)$r['reserva_id']; ?>">
                                    <input type="hidden" name="reserva_estado" value="X">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancelar">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>

                <?php if ($reservas): ?>
                    <tfoot>
                        <tr style="background:var(--core-suave);font-weight:700;">
                            <td colspan="4" class="text-end">Totales</td>
                            <td class="text-end">$<?php echo number_format($totalGeneral, 2); ?></td>
                            <td class="text-end text-success">$<?php echo number_format($totalGeneral - $totalSaldo, 2); ?></td>
                            <td class="text-end text-danger">$<?php echo number_format($totalSaldo, 2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . "/inc/layout-bottom.php"; ?>
