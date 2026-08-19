<?php
/*
| Conceptos cobrables.
|
| Es un catálogo, no un ENUM: una liga que empieza a cobrar «carné de
| jugador» no debería necesitar una migración para hacerlo. El valor que
| se define aquí es el que se propone al generar la obligación, y se puede
| ajustar caso por caso: una beca o un descuento son la norma, no la
| excepción.
*/

use league\controllers\finanzaController;

$insLeague = new finanzaController();

$tituloVista = 'Conceptos cobrables';
$vistaActual = 'conceptoList';

$conceptos = $insLeague->conceptos();

$ambitos = [
    'INSCRIPCION' => 'Inscripción de un equipo a una categoría',
    'EQUIPO'      => 'Un equipo',
    'PERSONA'     => 'Una persona de la plantilla',
    'PARTIDO'     => 'Un partido',
];

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

require_once __DIR__ . "/inc/layout-top.php";
?>

<div class="row">
    <div class="col-lg-7 mb-3">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-tags mr-2"></i>Conceptos</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Concepto</th>
                                <th>Se aplica a</th>
                                <th class="text-right">Valor sugerido</th>
                                <th class="ds-tabla-acciones"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$conceptos): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">
                                No hay conceptos activos.
                            </td></tr>
                        <?php else: foreach ($conceptos as $c): ?>
                            <tr>
                                <td><code><?php echo $h($c['concepto_codigo']); ?></code></td>
                                <td><?php echo $h($c['concepto_nombre']); ?></td>
                                <td class="text-muted" style="font-size:.85rem;">
                                    <?php echo $h($ambitos[$c['concepto_ambito']] ?? $c['concepto_ambito']); ?>
                                </td>
                                <td class="text-right">
                                    <?php if ((float)$c['concepto_valor'] > 0): ?>
                                        <?php echo number_format((float)$c['concepto_valor'], 2); ?>
                                    <?php else: ?>
                                        <span class="text-muted" title="Se pedirá al generar la obligación">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ds-tabla-acciones">
                                    <?php if (puede_editar('conceptoList')): ?>
                                    <button type="button" class="btn btn-sm btn-actualizar js-editar" title="Editar"
                                            data-fila='<?php echo $h(json_encode([
                                                'concepto_id'     => (int)$c['concepto_id'],
                                                'concepto_codigo' => $c['concepto_codigo'],
                                                'concepto_nombre' => $c['concepto_nombre'],
                                                'concepto_ambito' => $c['concepto_ambito'],
                                                'concepto_valor'  => $c['concepto_valor'],
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
            <div class="card-footer text-muted" style="font-size:.85rem;">
                El valor de aquí es una sugerencia. Al generar una obligación se puede
                cambiar, y ese cambio no toca el catálogo.
            </div>
        </div>
    </div>

    <div class="col-lg-5 mb-3">
        <?php if (puede_crear('conceptoList') || puede_editar('conceptoList')): ?>
        <form class="FormularioAjax" method="POST" autocomplete="off"
              action="<?php echo APP_URL; ?>ajax/leagueAjax.php" id="formFila">
            <input type="hidden" name="modulo_league" value="guardarConcepto">
            <input type="hidden" name="concepto_id" id="concepto_id" value="0">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title" id="tituloForm">
                        <i class="fas fa-plus mr-2"></i>Nuevo concepto
                    </h3>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-5">
                            <label for="concepto_codigo">Código</label>
                            <input type="text" name="concepto_codigo" id="concepto_codigo"
                                   class="form-control text-uppercase" maxlength="20" required
                                   pattern="[A-Za-z0-9_]{2,20}" placeholder="MULTA_TEC">
                            <small class="form-text text-muted">
                                Mayúsculas, números y guión bajo.
                            </small>
                        </div>
                        <div class="form-group col-7">
                            <label for="concepto_nombre">Nombre</label>
                            <input type="text" name="concepto_nombre" id="concepto_nombre"
                                   class="form-control" maxlength="80" required>
                        </div>
                    </div>
                    <div class="form-row mb-0">
                        <div class="form-group col-7 mb-0">
                            <label for="concepto_ambito">Se aplica a</label>
                            <select name="concepto_ambito" id="concepto_ambito" class="form-control">
                                <?php foreach ($ambitos as $cod => $txt): ?>
                                    <option value="<?php echo $cod; ?>"><?php echo $h($txt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-5 mb-0">
                            <label for="concepto_valor">Valor sugerido</label>
                            <input type="number" name="concepto_valor" id="concepto_valor"
                                   class="form-control text-right" step="0.01" min="0" value="0.00">
                        </div>
                    </div>
                </div>
                <?php echo ds_acciones_form('', ['limpiar' => true]); ?>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . "/inc/editor-fila.php"; ?>
<?php require_once __DIR__ . "/inc/layout-bottom.php"; ?>
