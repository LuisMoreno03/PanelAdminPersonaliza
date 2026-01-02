<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// =====================================================
// AUTH
// =====================================================
$routes->get('/', 'Auth::index');              // muestra login
$routes->get('auth/login', 'Auth::index');     // ✅ FIX: /auth/login (GET)
$routes->post('auth/login', 'Auth::login');    // procesa login (POST)
$routes->get('logout', 'Auth::logout');


// =====================================================
// DASHBOARD (PROTEGIDO)  ✅ OJO: tu controlador es "Dashboard"
// =====================================================
$routes->group('dashboard', ['filter' => 'auth'], function (RouteCollection $routes) {

    $routes->get('/', 'Dashboard::index');

    // Pedidos Shopify (50 en 50)
    $routes->get('pedidos', 'Dashboard::pedidos'); // ✅ /dashboard/pedidos
    $routes->get('filter',  'Dashboard::filter');  // fallback JS viejo

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
$routes->group('api', ['filter' => 'auth'], function (RouteCollection $routes) {

    $routes->post('estado/guardar', 'EstadoController::guardar');
    $routes->post('estado/etiquetas/guardar', 'Dashboard::guardarEtiquetas');

    $routes->get('confirmados', 'Confirmados::filter');
});


// =====================================================
// SHOPIFY (ADMIN / DEBUG / SERVICIOS)
// =====================================================
$routes->group('shopify', ['filter' => 'auth'], function (RouteCollection $routes) {

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
$routes->group('confirmados', ['filter' => 'auth'], function (RouteCollection $routes) {
    $routes->get('/', 'Confirmados::index');
    $routes->get('filter', 'Confirmados::filter');
});


// =====================================================
// PEDIDOS (VISTA LEGACY)
// =====================================================
$routes->group('pedidos', ['filter' => 'auth'], function (RouteCollection $routes) {
    $routes->get('/', 'PedidosController::index');
    $routes->get('filter', 'PedidosController::filter');
    $routes->post('cambiar-estado', 'PedidosController::cambiarEstado');
});


// =====================================================
// PRODUCCION
// =====================================================
$routes->group('produccion', ['filter' => 'auth'], function (RouteCollection $routes) {
    $routes->get('/', 'ProduccionController::index');
});
