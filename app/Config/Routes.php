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
    $routes->get('/', 'Dashboard::index');

    $routes->get('pedidos', 'Dashboard::pedidos');
    $routes->get('filter',  'Dashboard::filter');
    // ✅ Etiquetas disponibles para el modal
    $routes->get('dashboard/etiquetas-disponibles', 'Dashboard::etiquetasDisponibles', ['filter' => 'auth']);
    //mostrar etiquetas disponibles 
    $routes->get('etiquetas-disponibles', 'Dashboard::etiquetasDisponibles');
    // ✅ Si tienes un controlador Usuarios para crear:
    $routes->post('usuarios/crear', 'Usuarios::crear', ['filter' => 'auth']);

    // ❌ si NO existe el método sync en Dashboard.php, comenta o elimina
    // $routes->get('sync', 'Dashboard::sync');

    // ✅ Opción B: si estos métodos están en DashboardController (legacy)
    $routes->get('detalles/(:num)', 'DashboardController::detalles/$1');
    $routes->post('subirImagenProducto', 'DashboardController::subirImagenProducto');

    // ✅ usuarios online
    $routes->get('ping', 'Dashboard::ping');
    $routes->get('usuarios-estado', 'Dashboard::usuariosEstado');
});

// ====================================================
// API (AJAX / JSON) (PROTEGIDO)
// ====================================================
$routes->group('api', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->post('estado/guardar', 'EstadoController::guardar');
    $routes->get('estado/historial/(:num)', 'EstadoController::historial/$1');

    // etiquetas (Shopify tags)
    $routes->post('estado/etiquetas/guardar', 'Dashboard::guardarEtiquetas');

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
$routes->group('placas', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'PlacasController::index');

    $routes->get('archivos/listar', 'PlacasArchivosController::listar');
    $routes->get('archivos/stats',  'PlacasArchivosController::stats');
    $routes->post('archivos/subir', 'PlacasArchivosController::subir');

    $routes->post('archivos/renombrar', 'PlacasArchivosController::renombrar');
    $routes->post('archivos/eliminar',  'PlacasArchivosController::eliminar');

    $routes->post('placas/archivos/subir', 'PlacasArchivosController::subir', ['filter' => 'auth']);
    $routes->post('placas/archivos/lote/renombrar', 'PlacasArchivosController::renombrarLote');
    $routes->post('placas/archivos/lote/eliminar', 'PlacasArchivosController::eliminarLote');

});

// ----------------------------------------------------
// TEST FUNCIONAL
// ----------------------------------------------------
$routes->get('rtest', static function () { return 'OK ROUTES'; });
$routes->get('zz-check-routes', static function () { return 'ROUTES_OK_' . date('Y-m-d_H:i:s'); });


// ----------------------------------------------------
// rutas de usuario consulta
// ----------------------------------------------------
$routes->group('usuarios', ['filter' => 'auth'], function($routes) {
    $routes->get('/', 'Usuario::index');
    $routes->post('crear', 'Usuario::crear');
    $routes->get('(:num)/tags', 'Usuario::tags/$1');
});
