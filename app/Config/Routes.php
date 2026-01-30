<?php

use CodeIgniter\Router\RouteCollection;
use Config\Services;

/**
 * @var RouteCollection $routes
 */
$routes = Services::routes();

/*
|--------------------------------------------------------------------------
| Configuración base
|--------------------------------------------------------------------------
*/
$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Auth');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
$routes->setAutoRoute(false);

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/
$routes->get('/', 'Auth::index');
$routes->post('auth/login', 'Auth::login');
$routes->get('logout', 'Auth::logout');


$routes->get('shopify/install', 'ShopifyAuth::install');
$routes->get('shopify/callback', 'ShopifyAuth::callback');

/*
|--------------------------------------------------------------------------
| DASHBOARD (PROTEGIDO)
|--------------------------------------------------------------------------
*/
$routes->group('dashboard', ['filter' => 'auth'], static function (RouteCollection $routes) {

    // vista principal
    $routes->get('/', 'DashboardController::index');

    // pedidos
    $routes->get('pedidos', 'DashboardController::pedidos');
    $routes->get('filter',  'DashboardController::filter');

    // guardar estado desde dashboard
    $routes->post('guardar-estado', 'DashboardController::guardarEstadoPedido');

    // detalles pedido (mejor aceptar IDs como string/segment)
    $routes->get('detalles/(:segment)', 'DashboardController::detalles/$1');
    $routes->post('subir-imagen-modificada', 'DashboardController::subirImagenModificada');

    // presencia / usuarios
    $routes->get('ping', 'DashboardController::ping');
    $routes->get('usuarios-estado', 'DashboardController::usuariosEstado');
});

/*
|--------------------------------------------------------------------------
| API (PROTEGIDO)
|--------------------------------------------------------------------------
*/
$routes->group('api', ['filter' => 'auth'], static function (RouteCollection $routes) {

    // test
    $routes->post('_test_post', static function () {
        return json_encode(['ok' => true, 'time' => date('Y-m-d H:i:s')]);
    });

    // estados pedidos
    $routes->post('estado/guardar', 'EstadoController::guardar');
    $routes->get('estado/historial/(:segment)', 'EstadoController::historial/$1');

    // imágenes pedidos
    $routes->post('pedidos/imagenes/subir', 'PedidosImagenesController::subir');

    // confirmados
    $routes->get('confirmados', 'Confirmados::filter');
});

/*
|--------------------------------------------------------------------------
| SHOPIFY (PROTEGIDO)
|--------------------------------------------------------------------------
*/
$routes->group('shopify', ['filter' => 'auth'], static function ($routes) {

    $routes->get('test', 'ShopifyController::test');

    // Ruta real
    $routes->get('orders', 'ShopifyController::getOrders');

    // Alias por compatibilidad (si tu dashboard llamaba getOrders)
    $routes->get('getOrders', 'ShopifyController::getOrders');

    $routes->get('orders/all', 'ShopifyController::getAllOrders');
    $routes->get('order/(:segment)', 'ShopifyController::getOrder/$1');

    $routes->post('orders/update', 'ShopifyController::updateOrder');

    $routes->get('products', 'ShopifyController::getProducts');
    $routes->get('customers', 'ShopifyController::getCustomers');
});


/*
|--------------------------------------------------------------------------
| CONFIRMACIÓN (PROTEGIDO)
|--------------------------------------------------------------------------
*/
$routes->group('confirmacion', ['filter' => 'auth'], static function (RouteCollection $routes) {

    $routes->get('/', 'ConfirmacionController::index');
    $routes->get('my-queue', 'ConfirmacionController::myQueue');
    $routes->post('pull', 'ConfirmacionController::pull');
    $routes->post('return-all', 'ConfirmacionController::returnAll');
    $routes->post('guardar-nota', 'ConfirmacionController::guardarNota');

    // ✅ endpoint real que usa confirmacion.js
    $routes->post('subir-imagen', 'ConfirmacionController::subirImagen');

    // ✅ alias opcional para mantener /confirmacion/upload
    $routes->post('upload', 'ConfirmacionController::uploadConfirmacion');

    $routes->get('list', 'ConfirmacionController::listFiles');
    $routes->get('detalles/(:segment)', 'ConfirmacionController::detalles/$1');
    $routes->post('guardar-estado', 'ConfirmacionController::guardarEstado');
});



/*
|--------------------------------------------------------------------------
| PEDIDOS LEGACY (PROTEGIDO)
|--------------------------------------------------------------------------
*/
$routes->group('pedidos', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'PedidosController::index');
    $routes->get('filter', 'PedidosController::filter');
    $routes->post('cambiar-estado', 'PedidosController::cambiarEstado');
});

/*
|--------------------------------------------------------------------------
| PRODUCCIÓN (PROTEGIDO)
|--------------------------------------------------------------------------
*/
$routes->group('produccion', ['filter' => 'auth'], static function (RouteCollection $routes) {

    $routes->get('/', 'ProduccionController::index');
    $routes->get('my-queue', 'ProduccionController::myQueue');
    $routes->post('pull', 'ProduccionController::pull');
    $routes->post('return-all', 'ProduccionController::returnAll');
    $routes->post('return-one', 'ProduccionController::returnOne');

    // uploads/listado general
    $routes->post('upload-general', 'ProduccionController::uploadGeneral');
    $routes->get('list-general', 'ProduccionController::listGeneral');
    $routes->post('upload-modificada', 'ProduccionController::uploadModificada');

    // ✅ NUEVO (tu JS lo intenta como fallback)
    $routes->post('set-estado', 'ProduccionController::setEstado');
    
    // ✅ NUEVO (para abrir urls devueltas por list-general)
    $routes->get('file/(:segment)/(:segment)', 'ProduccionController::file/$1/$2');
});



/*
|--------------------------------------------------------------------------
| PLACAS (PROTEGIDO)
|--------------------------------------------------------------------------
*/
// ... tus rutas anteriores
// ✅ Vista principal /placas
$routes->get('placas', 'PlacasController::index');

// ✅ PLACAS API
$routes->group('placas/archivos', static function($routes) {
    $routes->get('listar-por-dia', 'PlacasArchivosController::listarPorDia');
    $routes->get('stats', 'PlacasArchivosController::stats');

    $routes->post('subir-lote', 'PlacasArchivosController::subirLote');
    $routes->post('renombrar', 'PlacasArchivosController::renombrarArchivo');
    $routes->post('eliminar', 'PlacasArchivosController::eliminarArchivo');
    $routes->post('lote/renombrar', 'PlacasArchivosController::renombrarLote');

    $routes->get('placas/archivos/ver/(:num)', 'PlacasArchivos::ver/$1');
    $routes->get('ver/(:num)', 'PlacasArchivosController::ver/$1');
    $routes->get('descargar/(:num)', 'PlacasArchivosController::descargar/$1');

    
});

// ✅ Pedidos por producir (BD interna)
$routes->get('placas/pedidos/por-producir', 'PedidosController::porProducir');

/*
|--------------------------------------------------------------------------
| PEDIDOS A REPETIR (PROTEGIDO)
|--------------------------------------------------------------------------
*/
$routes->group('repetir', [
    'filter'    => 'auth',
    'namespace' => 'App\Controllers',
], static function (RouteCollection $routes) {

    $routes->get('/', 'RepetirController::index');

    // listado/paginado
    $routes->get('pedidos', 'RepetirController::pedidos');
    $routes->get('filter',  'RepetirController::filter');

    // detalles
    $routes->get('detalles/(:segment)', 'RepetirController::detalles/$1');

    // si dashboard.js los usa
    $routes->post('guardar-estado', 'RepetirController::guardarEstadoPedido');
    $routes->get('ping',            'RepetirController::ping');
    $routes->get('usuarios-estado', 'RepetirController::usuariosEstado');
});

/*
|--------------------------------------------------------------------------
| USUARIOS (PROTEGIDO)
|--------------------------------------------------------------------------
*/
$routes->group('usuarios', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'UsuariosController::index');
    $routes->post('cambiar-clave', 'UsuariosController::cambiarClave');

});
/*
|--------------------------------------------------------------------------
| TEST
|--------------------------------------------------------------------------
*/
$routes->get('rtest', static fn () => 'OK ROUTES');
$routes->get('zz-check-routes', static fn () => 'ROUTES_OK_' . date('Y-m-d_H:i:s'));

/*
|--------------------------------------------------------------------------
| CHAT INTERNO (PROTEGIDO)
|--------------------------------------------------------------------------
*/
$routes->group('chat', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'ChatController::index');
    
    $routes->get('chat', 'ChatController::index');
    $routes->get('chat/users', 'ChatController::users');
    $routes->get('chat/messages/(:num)', 'ChatController::messages/$1');
    $routes->post('chat/send', 'ChatController::send');
    $routes->post('chat/ping', 'ChatController::ping');
    

});

/*
|--------------------------------------------------------------------------
| Soporte 
|--------------------------------------------------------------------------
*/
$routes->group('soporte', ['filter' => 'auth'], function($routes) {
  $routes->get('chat', 'SoporteController::chat');

  $routes->get('tickets', 'SoporteController::tickets');
  $routes->get('ticket/(:num)', 'SoporteController::ticket/$1');

  $routes->post('ticket', 'SoporteController::create');                 // crear ticket (produccion)
  $routes->post('ticket/(:num)/message', 'SoporteController::message/$1'); // enviar mensaje

  $routes->post('ticket/(:num)/assign', 'SoporteController::assign/$1');   // aceptar caso (admin)
  $routes->post('ticket/(:num)/status', 'SoporteController::status/$1');   // cambiar estado (admin)
  $routes->get('whoami', 'SoporteController::whoami');

  $routes->get('attachment/(:num)', 'SoporteController::attachment/$1');   // ver imagen
});

$routes->get('montaje', 'MontajeController::index');
$routes->get('montaje/my-queue', 'MontajeController::myQueue');
$routes->post('montaje/pull', 'MontajeController::pull');
$routes->post('montaje/realizado', 'MontajeController::realizado');
$routes->post('montaje/enviar', 'MontajeController::enviar');
$routes->get('montaje/details/(:any)', 'MontajeController::details/$1');
$routes->get('montaje/download/(:any)/(:any)', 'MontajeController::download/$1/$2');
$routes->post('montaje/cargado', 'MontajeController::cargado');
$routes->post('montaje/return-all', 'MontajeController::returnAll');
// compatibilidad
$routes->post('montaje/cargado', 'MontajeController::cargado');


// ✅ Vista Por producir
// ===============================
// POR PRODUCIR
// ===============================
// ===============================
// POR PRODUCIR
// ===============================
$routes->group('porproducir', function($routes) {

    // Vista principal
    $routes->get('/', 'PorProducirController::index');

    // Pull (traer 5 o 10 pedidos en estado "Diseñado")
    $routes->get('pull', 'PorProducirController::pull');

    // Update método de entrega (si cambia a Enviado => estado Enviado y sale de la lista)
    $routes->post('update-metodo', 'PorProducirController::updateMetodo');

});


$routes->get('seguimiento', 'SeguimientoController::index');
$routes->get('seguimiento/resumen', 'SeguimientoController::resumen');
$routes->get('seguimiento/detalle/(:num)', 'SeguimientoController::detalle/$1');
