<?php
/*
|--------------------------------------------------------------------------
| Control de tema: claro, oscuro o el del sistema
|--------------------------------------------------------------------------
| Se incluye dentro de la lista de la derecha de la barra superior, junto al
| selector de aplicaciones, de modo que aparece en los cuatro modulos sin
| tocar cada navbar.
|
| TRES OPCIONES, NO UN INTERRUPTOR DE DOS
|
| «Automatico» sigue la preferencia del sistema operativo, que es lo que
| espera quien tiene el movil o el portatil en oscuro por la noche. Un
| interruptor de dos posiciones obliga a acordarse de cambiarlo dos veces al
| dia.
|
| LA LOGICA NO ESTA AQUI
|
| Vive en ds_core/assets/js/tema.js, que se carga en la CABECERA de cada
| vista y sin defer: el tema tiene que aplicarse antes del primer pintado o
| la pagina parpadea de claro a oscuro. Este archivo solo pone el marcado, y
| se comunica con el script por atributos data.
|
| Uso desde un modulo:
|     require_once __DIR__ . "/../../../../ds_core/inc/tema-control.php";
*/
?>

<li class="nav-item dropdown">
    <a class="nav-link" href="#" role="button" data-bs-toggle="dropdown"
       aria-expanded="false" title="Tema de la interfaz" aria-label="Tema de la interfaz">
        <?php /* El icono lo pone tema.js segun lo que se este viendo. */ ?>
        <i class="fas fa-adjust" data-ds-tema-icono></i>
    </a>

    <div class="dropdown-menu dropdown-menu-end">
        <h6 class="dropdown-header">Tema</h6>

        <a class="dropdown-item d-flex align-items-center gap-2"
           href="#" data-ds-tema-opcion="light">
            <i class="fas fa-sun fa-fw"></i> Claro
        </a>

        <a class="dropdown-item d-flex align-items-center gap-2"
           href="#" data-ds-tema-opcion="dark">
            <i class="fas fa-moon fa-fw"></i> Oscuro
        </a>

        <a class="dropdown-item d-flex align-items-center gap-2"
           href="#" data-ds-tema-opcion="auto">
            <i class="fas fa-desktop fa-fw"></i> El del sistema
        </a>
    </div>
</li>
