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
$routes->group('shopify', ['filter' => 'auth'], static function (RouteCollection $routes) {

    $routes->get('orders', 'ShopifyController::getOrders');
    $routes->get('orders/all', 'ShopifyController::getAllOrders');
    $routes->get('order/(:segment)', 'ShopifyController::getOrder/$1');

    $routes->post('orders/update', 'ShopifyController::updateOrder');

    $routes->get('products', 'ShopifyController::getProducts');
    $routes->get('products/(:segment)', 'ShopifyController::getProduct/$1');

    $routes->get('customers', 'ShopifyController::getCustomers');
    $routes->get('test', 'ShopifyController::test');
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

    // subir imágenes (cuadros/llaveros) y auto-cambiar estado a Confirmado si corresponde
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

    // uploads/listado general
    $routes->post('upload-general', 'ProduccionController::uploadGeneral');
    $routes->get('list-general', 'ProduccionController::listGeneral');
    $routes->post('upload-modificada', 'ProduccionController::uploadModificada');

});

/*
|--------------------------------------------------------------------------
| PLACAS (PROTEGIDO)
|--------------------------------------------------------------------------
*/
$routes->group('placas', ['filter' => 'auth'], static function (RouteCollection $routes) {

    $routes->get('/', 'PlacasController::index');
    $routes->get('(:num)/archivos', 'PlacasController::archivos/$1');

    $routes->group('archivos', static function (RouteCollection $routes) {

        $routes->get('listar', 'PlacasArchivosController::listar');
        $routes->get('stats',  'PlacasArchivosController::stats');
        $routes->get('listar-por-dia', 'PlacasArchivosController::listarPorDia');

        $routes->post('subir', 'PlacasArchivosController::subir');
        $routes->post('subir-lote', 'PlacasArchivosController::subirLote');

        $routes->post('renombrar', 'PlacasArchivosController::renombrar');
        $routes->post('eliminar',  'PlacasArchivosController::eliminar');

        $routes->post('lote/renombrar', 'PlacasArchivosController::renombrarLote');

        $routes->get('descargar/(:num)', 'PlacasArchivosController::descargar/$1');

        // DESCARGAR JPG/PNG
        $routes->get('descargar-png/(:num)', 'PlacasArchivosController::descargarPng/$1');
        $routes->get('descargar-jpg/(:num)', 'PlacasArchivosController::descargarJpg/$1');

        $routes->get('descargar-png-lote/(:any)', 'PlacasArchivosController::descargarPngLote/$1');
        $routes->get('descargar-jpg-lote/(:any)', 'PlacasArchivosController::descargarJpgLote/$1');
    });
});

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
$routes->group('', ['namespace' => 'App\Controllers'], function($routes) {
    $routes->get('/', 'UsuariosController::index');
    $routes->get('usuarios', 'Usuarios::index');
    $routes->get('usuarios/(:num)/password', 'Usuarios::password/$1');
    $routes->post('usuarios/(:num)/password', 'Usuarios::updatePassword/$1');

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

  $routes->get('attachment/(:num)', 'SoporteController::attachment/$1');   // ver imagen
});
