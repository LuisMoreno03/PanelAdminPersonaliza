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

    // etiquetas / estados disponibles (🔥 IMPORTANTE)
    $routes->get('etiquetas-disponibles', 'DashboardController::etiquetasDisponibles');

    // guardar estado desde dashboard
    $routes->post('guardar-estado', 'DashboardController::guardarEstadoPedido');

    // detalles pedido
    $routes->get('detalles/(:num)', 'DashboardController::detalles/$1');

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
    $routes->get('estado/historial/(:num)', 'EstadoController::historial/$1');

    // etiquetas pedidos
    $routes->post('estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');

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
    $routes->get('order/(:num)', 'ShopifyController::getOrder/$1');

    $routes->post('orders/update', 'ShopifyController::updateOrder');
    $routes->post('orders/update-tags', 'ShopifyController::updateOrderTags');

    $routes->get('products', 'ShopifyController::getProducts');
    $routes->get('products/(:num)', 'ShopifyController::getProduct/$1');

    $routes->get('customers', 'ShopifyController::getCustomers');
    $routes->get('test', 'ShopifyController::test');
});

/*
|--------------------------------------------------------------------------
| CONFIRMADOS (VISTA)
|--------------------------------------------------------------------------
*/
$routes->group('confirmados', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'Confirmados::index');
    $routes->get('filter', 'Confirmados::filter');
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
        $routes->post('lote/eliminar',  'PlacasArchivosController::eliminarLote');

        $routes->get('descargar/(:num)', 'PlacasArchivosController::descargar/$1');

        // DESCARGAR JPG/PNG
        $routes->get('placas/archivos/descargar-png/(:num)', 'PlacasArchivosController::descargarPng/$1');
        $routes->get('placas/archivos/descargar-jpg/(:num)', 'PlacasArchivosController::descargarJpg/$1');

        $routes->get('placas/archivos/descargar-png-lote/(:any)', 'PlacasArchivosController::descargarPngLote/$1');
        $routes->get('placas/archivos/descargar-jpg-lote/(:any)', 'PlacasArchivosController::descargarJpgLote/$1');

    
       });

});

/*
|--------------------------------------------------------------------------
| USUARIOS (PROTEGIDO)
|--------------------------------------------------------------------------
*/
$routes->group('usuarios', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'Usuario::index');
    $routes->post('crear', 'Usuario::crear');
    $routes->get('(:num)/tags', 'Usuario::tags/$1');
});

/*
|--------------------------------------------------------------------------
| TEST
|--------------------------------------------------------------------------
*/
$routes->get('rtest', static fn () => 'OK ROUTES');
$routes->get('zz-check-routes', static fn () => 'ROUTES_OK_' . date('Y-m-d_H:i:s'));
