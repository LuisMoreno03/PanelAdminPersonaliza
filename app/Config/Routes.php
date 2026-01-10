<?php

use CodeIgniter\Router\RouteCollection;
use Config\Services;

/**
 * @var RouteCollection $routes
 */
$routes = Services::routes();

$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Auth');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
$routes->setAutoRoute(false);

// ----------------------------------------------------
// AUTH
// ----------------------------------------------------
$routes->get('/', 'Auth::index');
$routes->post('auth/login', 'Auth::login');
$routes->get('logout', 'Auth::logout');

// ----------------------------------------------------
// DASHBOARD (PROTEGIDO)
// ----------------------------------------------------
$routes->group('dashboard', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'DashboardController::index');
    $routes->get('pedidos', 'DashboardController::pedidos');
    $routes->get('filter',  'DashboardController::filter');

    $routes->get('etiquetas-disponibles', 'DashboardController::etiquetasDisponibles');

    $routes->get('ping', 'DashboardController::ping');
    $routes->get('usuarios-estado', 'DashboardController::usuariosEstado');

    $routes->get('detalles/(:num)', 'DashboardController::detalles/$1');
});


$routes->group('api', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->post('_test_post', static function () {
        return json_encode(['ok' => true, 'time' => date('Y-m-d H:i:s')]);
    }); 

    $routes->post('estado/guardar', 'EstadoController::guardar');
    $routes->get('estado/historial/(:num)', 'EstadoController::historial/$1');

    $routes->post('estado/etiquetas/guardar', 'Dashboard::guardarEtiquetas');
    $routes->post('estado_etiquetas/guardar', 'Dashboard::guardarEtiquetas');

    // ✅ subir imagen modificada
    $routes->post('pedidos/imagenes/subir', 'PedidosImagenesController::subir');

    $routes->get('confirmados', 'Confirmados::filter');
});


// ====================================================
// SHOPIFY (PROTEGIDO)
// ====================================================
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

// ====================================================
// CONFIRMADOS (VISTA) (PROTEGIDO)
// ====================================================
$routes->group('confirmados', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'Confirmados::index');
    $routes->get('filter', 'Confirmados::filter');
});

// ====================================================
// PEDIDOS (VISTA LEGACY) (PROTEGIDO)
// ====================================================
$routes->group('pedidos', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'PedidosController::index');
    $routes->get('filter', 'PedidosController::filter');
    $routes->post('cambiar-estado', 'PedidosController::cambiarEstado');
});

// ====================================================
// PRODUCCION (PROTEGIDO)
// ====================================================
$routes->group('produccion', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'ProduccionController::index');
    $routes->get('filter', 'ProduccionController::filter');
});

// ====================================================
// PLACAS (PROTEGIDO)
// ====================================================
$routes->group('placas', ['filter' => 'auth'], static function ($routes) {

  $routes->get('/', 'PlacasController::index');
  $routes->get('(:num)/archivos', 'PlacasController::archivos/$1');

  $routes->group('archivos', static function ($routes) {
      $routes->get('listar', 'PlacasArchivosController::listar');
      $routes->get('stats',  'PlacasArchivosController::stats');

      // ✅ NUEVAS (aquí)
      $routes->get('listar-por-dia', 'PlacasArchivosController::listarPorDia');
      $routes->post('subir-lote', 'PlacasArchivosController::subirLote');

      $routes->post('subir', 'PlacasArchivosController::subir');
      $routes->post('renombrar', 'PlacasArchivosController::renombrar');
      $routes->post('eliminar',  'PlacasArchivosController::eliminar');

      $routes->post('lote/renombrar', 'PlacasArchivosController::renombrarLote');
      $routes->post('lote/eliminar',  'PlacasArchivosController::eliminarLote');

      $routes->get('descargar/(:num)', 'PlacasArchivosController::descargar/$1');
  });

        // ✅ NUEVA: descargar desde el controller correcto
        $routes->get('descargar/(:num)', 'PlacasArchivosController::descargar/$1');
    });


// ----------------------------------------------------
// TEST FUNCIONAL
// ----------------------------------------------------
$routes->get('rtest', static function () { return 'OK ROUTES'; });
$routes->get('zz-check-routes', static function () { return 'ROUTES_OK_' . date('Y-m-d_H:i:s'); });

// ----------------------------------------------------
// usuarios (PROTEGIDO)
// ----------------------------------------------------
$routes->group('usuarios', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'Usuario::index');
    $routes->post('crear', 'Usuario::crear');
    $routes->get('(:num)/tags', 'Usuario::tags/$1');
});
