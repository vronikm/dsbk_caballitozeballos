<?php

    require_once "./config/app.php";
    require_once "./autoload.php";

    /*---------- Iniciando sesion ----------*/
    require_once "./app/views/inc/session_start.php";

    if(isset($_GET['views'])){
        $url=explode("/", $_GET['views']);
    }else{
        $url=["login"];
    }

    use app\controllers\viewsController;
    use app\controllers\loginController;

    $insLogin = new loginController();

    $viewsController= new viewsController();
    $vista=$viewsController->obtenerVistasControlador($url[0]);

    if($vista=="login" || $vista=="404"){
        # Una ruta inexistente debe responder 404, no 200 con la página de
        # error: los buscadores y las herramientas se guían por el estado.
        if($vista=="404"){ http_response_code(404); }
        require_once "app/views/content/".$vista."-view.php";
    }else{

      # Cerrar sesion #
      if((!isset($_SESSION['usuario']) || $_SESSION['usuario']=="")){
          $insLogin->cerrarSesionControlador();
          exit();
      }

      # Control de acceso, nivel 1: el rol debe tener concedido el modulo #
      if(!usuario_tiene_modulo(DS_MODULO)){
          require_once "app/views/content/accesoDenegado-view.php";
          exit();
      }

      # Control de acceso, nivel 2: permiso de lectura sobre la vista #
      # Impide que un usuario autenticado abra por URL una pantalla que su
      # rol no tiene habilitada. Las vistas que no son item de menu
      # (formularios, PDF, perfiles) no se restringen aqui: heredan el
      # alcance del listado desde el que se abren.
      # Ver usuario_tiene_permiso() en ds_core/inc/seguridad.php
      if(!usuario_tiene_permiso($url[0])){
          require_once "app/views/content/accesoDenegado-view.php";
          exit();
      }

      require_once $vista;

    } 
