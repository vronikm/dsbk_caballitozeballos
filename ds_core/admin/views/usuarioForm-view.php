<?php
/* Alta y edición de un usuario. */

use admin\controllers\coreController;

$insCore = new coreController();

$id      = (int)($_GET['id'] ?? 0);
$usuario = $id > 0 ? $insCore->usuario($id) : null;
$esAlta  = ($usuario === null);

/* La vista se gobierna con los permisos de usuarioList, que es su menú. */
if ($esAlta && !puede_crear('usuarioList')) {
    require_once __DIR__ . "/accesoDenegado-view.php";
    exit();
}
if (!$esAlta && !puede_editar('usuarioList')) {
    require_once __DIR__ . "/accesoDenegado-view.php";
    exit();
}

$tituloVista = $esAlta ? 'Nuevo usuario' : 'Editar usuario';
$vistaActual = 'usuarioList';

$roles     = $insCore->roles();
$empleados = $insCore->empleadosSinUsuario($id);

/* Permite llegar desde el listado de empleados de Basketball con la
   persona ya elegida: .../usuarioForm/?empleado=12 */
$empleadoPre = (int)($_GET['empleado'] ?? 0);
$empleadoSel = (int)($usuario['usuario_empleadoid'] ?? 0) ?: $empleadoPre;

require_once __DIR__ . "/inc/layout-top.php";
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?php echo $tituloVista; ?></h3>
            </div>

            <form class="FormularioAjax" method="POST" action="<?php echo APP_URL; ?>ajax/coreAjax.php">
                <input type="hidden" name="modulo_core" value="guardarUsuario">
                <input type="hidden" name="usuario_id" value="<?php echo $id; ?>">

                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="usuario_usuario">Usuario <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="usuario_usuario" name="usuario_usuario"
                                   value="<?php echo htmlspecialchars((string)($usuario['usuario_usuario'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                   pattern="[a-zA-Z0-9]{4,20}" maxlength="20" required autocomplete="off">
                            <small class="text-muted">4 a 20 caracteres, solo letras y números.</small>
                        </div>

                        <div class="form-group col-md-6">
                            <label for="usuario_rolid">Rol <span class="text-danger">*</span></label>
                            <select class="form-control" id="usuario_rolid" name="usuario_rolid" required>
                                <option value="">Seleccione…</option>
                                <?php foreach ($roles as $r):
                                    $esSuper = (int)$r['rol_id'] === self_rol_superadmin();
                                    /* Solo un Super Administrador puede otorgar ese rol. */
                                    if ($esSuper && !es_superadministrador()) continue;
                                ?>
                                    <option value="<?php echo (int)$r['rol_id']; ?>"
                                        <?php echo (int)($usuario['usuario_rolid'] ?? 0) === (int)$r['rol_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($r['rol_nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php echo $esSuper ? ' — acceso total' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="usuario_empleadoid">Persona vinculada</label>
                        <select class="form-control" id="usuario_empleadoid" name="usuario_empleadoid">
                            <option value="0">Sin vincular</option>
                            <?php foreach ($empleados as $e): ?>
                                <option value="<?php echo (int)$e['empleado_id']; ?>"
                                    <?php echo $empleadoSel === (int)$e['empleado_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($e['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">De ella se toman la foto y la sede que se muestran en el sistema.</small>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="usuario_clave">
                                Contraseña <?php echo $esAlta ? '<span class="text-danger">*</span>' : ''; ?>
                            </label>
                            <input type="password" class="form-control" id="usuario_clave" name="usuario_clave"
                                   minlength="8" autocomplete="new-password" <?php echo $esAlta ? 'required' : ''; ?>>
                            <small class="text-muted">
                                <?php echo $esAlta
                                    ? 'Mínimo 8 caracteres.'
                                    : 'Déjela vacía para conservar la contraseña actual.'; ?>
                            </small>
                        </div>

                        <div class="form-group col-md-3">
                            <label for="usuario_estado">Estado</label>
                            <select class="form-control" id="usuario_estado" name="usuario_estado">
                                <option value="A" <?php echo ($usuario['usuario_estado'] ?? 'A') === 'A' ? 'selected' : ''; ?>>Activo</option>
                                <option value="I" <?php echo ($usuario['usuario_estado'] ?? '') === 'I' ? 'selected' : ''; ?>>Inactivo</option>
                            </select>
                        </div>

                        <div class="form-group col-md-3">
                            <label for="usuario_tienebloqueo">Bloqueado</label>
                            <select class="form-control" id="usuario_tienebloqueo" name="usuario_tienebloqueo">
                                <option value="N" <?php echo ($usuario['usuario_tienebloqueo'] ?? 'N') === 'N' ? 'selected' : ''; ?>>No</option>
                                <option value="S" <?php echo ($usuario['usuario_tienebloqueo'] ?? '') === 'S' ? 'selected' : ''; ?>>Sí</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-between">
                    <a href="<?php echo APP_URL; ?>usuarioList/" class="btn btn-secondary">
                        <i class="fas fa-arrow-left mr-1"></i> Volver
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save mr-1"></i> Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . "/inc/layout-bottom.php"; ?>
