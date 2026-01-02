<?php

use CodeIgniter\Router\RouteCollection;
use Config\Services;

/**
 * @var RouteCollection $routes
 */
$routes = Services::routes();

// ----------------------------------------------------
// Settings base
// ----------------------------------------------------
$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Auth');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
// $routes->setAutoRoute(false); // recomendado dejarlo false

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

    $routes->get('sync', 'DashboardController::sync');

    $routes->get('detalles/(:num)', 'DashboardController::detalles/$1');
    $routes->post('subirImagenProducto', 'DashboardController::subirImagenProducto');

    $routes->get('ping', 'DashboardController::ping');
    $routes->get('usuarios-estado', 'DashboardController::usuariosEstado');
});

// ----------------------------------------------------
// API (AJAX / JSON)
// ----------------------------------------------------
$routes->group('api', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->post('estado/guardar', 'EstadoController::guardar');
    $routes->post('estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');
    $routes->get('confirmados', 'Confirmados::filter');
});

// ----------------------------------------------------
// SHOPIFY
// ----------------------------------------------------
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

// ----------------------------------------------------
// CONFIRMADOS (VISTA)
// ----------------------------------------------------
$routes->group('confirmados', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'Confirmados::index');
    $routes->get('filter', 'Confirmados::filter');
});

// ----------------------------------------------------
// PEDIDOS (VISTA LEGACY)
// ----------------------------------------------------
$routes->group('pedidos', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'PedidosController::index');
    $routes->get('filter', 'PedidosController::filter');
    $routes->post('cambiar-estado', 'PedidosController::cambiarEstado');
});

// ----------------------------------------------------
// PRODUCCION
// ----------------------------------------------------
$routes->group('produccion', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/', 'Produccion::index');
    $routes->get('filter', 'Produccion::filter');
});


$routes->get('rtest', function () {
    return 'OK ROUTES';
});
