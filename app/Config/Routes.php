<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Auth');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();

// Si NO usas auto routes, déjalo en false (recomendado)
$routes->setAutoRoute(false);

// =====================================================
// AUTH
// =====================================================
$routes->get('/', 'Auth::index');
$routes->get('auth', 'Auth::index');
$routes->get('auth/login', 'Auth::index');
$routes->post('auth/login', 'Auth::login');
$routes->get('logout', 'Auth::logout');

// =====================================================
// DASHBOARD (PROTEGIDO)
// =====================================================
$routes->group('dashboard', ['filter' => 'auth'], static function (RouteCollection $routes) {

    // Vista principal
    $routes->get('/', 'Dashboard::index');

    // Pedidos Shopify (50 en 50)
    $routes->get('pedidos', 'Dashboard::pedidos');   // ✅ /index.php/dashboard/pedidos
    $routes->get('filter',  'Dashboard::filter');    // fallback JS viejo

    // Detalles y acciones
    $routes->get('detalles/(:num)', 'Dashboard::detalles/$1');
    $routes->post('subirImagenProducto', 'Dashboard::subirImagenProducto');

    // Estado usuarios (tiempo real)
    $routes->get('ping', 'Dashboard::ping');
    $routes->get('usuarios-estado', 'Dashboard::usuariosEstado');
});

// =====================================================
// API (AJAX / JSON)
// =====================================================
$routes->group('api', ['filter' => 'auth'], static function (RouteCollection $routes) {

    $routes->post('estado/guardar', 'EstadoController::guardar');
    $routes->post('estado/etiquetas/guardar', 'Dashboard::guardarEtiquetas');

    $routes->get('confirmados', 'Confirmados::filter');
});

// =====================================================
// SHOPIFY (ADMIN / DEBUG)
// =====================================================
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

// =====================================================
// CONFIRMADOS (VISTA)
// =====================================================
$routes->group('confirmados', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'Confirmados::index');
    $routes->get('filter', 'Confirmados::filter');
});

// =====================================================
// PEDIDOS (VISTA LEGACY)
// =====================================================
$routes->group('pedidos', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'PedidosController::index');
    $routes->get('filter', 'PedidosController::filter');
    $routes->post('cambiar-estado', 'PedidosController::cambiarEstado');
});

// =====================================================
// PRODUCCION
// =====================================================
$routes->group('produccion', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/', 'ProduccionController::index');
});
