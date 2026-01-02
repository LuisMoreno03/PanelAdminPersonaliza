<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

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

    // Vista principal
    $routes->get('/', 'DashboardController::index');

    // ✅ PEDIDOS SHOPIFY (50 en 50)
    $routes->get('pedidos', 'DashboardController::pedidos');  // <-- ESTA ES LA QUE TE FALTA
    $routes->get('filter',  'DashboardController::filter');   // fallback (tu JS viejo)

    // Sync si lo usas
    $routes->get('sync', 'DashboardController::sync');

    // Detalles + upload
    $routes->get('detalles/(:num)', 'DashboardController::detalles/$1');
    $routes->post('subirImagenProducto', 'DashboardController::subirImagenProducto');

    // Tiempo real usuarios
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
// SHOPIFY (ADMIN / DEBUG / SERVICIOS)
// ----------------------------------------------------
$routes->group('shopify', ['filter' => 'auth'], static function (RouteCollection $routes) {

    // Orders
    $routes->get('orders', 'ShopifyController::getOrders');
    $routes->get('orders/all', 'ShopifyController::getAllOrders');
    $routes->get('order/(:num)', 'ShopifyController::getOrder/$1');

    $routes->post('orders/update', 'ShopifyController::updateOrder');
    $routes->post('orders/update-tags', 'ShopifyController::updateOrderTags');

    // Products
    $routes->get('products', 'ShopifyController::getProducts');
    $routes->get('products/(:num)', 'ShopifyController::getProduct/$1');

    // Customers
    $routes->get('customers', 'ShopifyController::getCustomers');

    // Test
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
$routes->group('produccion', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('/produccion', 'ProduccionController::index');
});
