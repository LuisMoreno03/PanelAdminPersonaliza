<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// =====================================================
// AUTH
// =====================================================
$routes->get('/', 'Auth::index');
$routes->post('auth/login', 'Auth::login');
$routes->get('logout', 'Auth::logout');


// =====================================================
// DASHBOARD (PROTEGIDO)
// =====================================================
$routes->group('dashboard', ['filter' => 'auth'], function (RouteCollection $routes) {

    // Vista principal
    $routes->get('/', 'DashboardController::index');

    // Sync / filtros
    $routes->get('sync', 'DashboardController::sync');
    $routes->get('filter', 'DashboardController::filter');

    // Detalles y acciones
    $routes->get('detalles/(:num)', 'DashboardController::detalles/$1');
    $routes->post('subirImagenProducto', 'DashboardController::subirImagenProducto');

    // Estado usuarios (tiempo real)
    $routes->get('ping', 'DashboardController::ping');
    $routes->get('usuarios-estado', 'DashboardController::usuariosEstado');

    // Shopify directo desde dashboard
    $routes->get('shopify/productos', 'DashboardController::shopifyProductos');
    $routes->get('pedidos', 'DashboardController::pedidos'); // 50 en 50
});


// =====================================================
// API (AJAX / JSON)
// =====================================================
$routes->group('api', ['filter' => 'auth'], function (RouteCollection $routes) {

    // Estados / etiquetas
    $routes->post('estado/guardar', 'EstadoController::guardar');
    $routes->post('estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');

    // Confirmados
    $routes->get('confirmados', 'Confirmados::filter');

    // Pedidos
    $routes->get('pedidos', 'PedidosController::filter');
});


// =====================================================
// SHOPIFY (ADMIN / DEBUG / SERVICIOS)
// =====================================================
$routes->group('shopify', ['filter' => 'auth'], function (RouteCollection $routes) {

    // Orders
    $routes->get('orders', 'ShopifyController::getOrders');
    $routes->get('orders/all', 'ShopifyController::getAllOrders');
    $routes->get('order/(:num)', 'ShopifyController::getOrder/$1');

    $routes->post('orders/update', 'ShopifyController::updateOrder');
    $routes->post('orders/update-tags', 'ShopifyController::updateOrderTags');

    // Products
    $routes->get('products', 'ShopifyController::getProducts');
    $routes->get('products/(:num)', 'ShopifyController::getProduct/$1');
    $routes->get('dashboard/pedidos', 'Dashboard::pedidos'); // GET ?page_info=

    // Customers
    $routes->get('customers', 'ShopifyController::getCustomers');

    // Test
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
// PEDIDOS (VISTA)
// =====================================================
$routes->group('pedidos', ['filter' => 'auth'], function (RouteCollection $routes) {

    // Página principal
    $routes->get('/', 'PedidosController::index');

    // Filtros
    $routes->get('filter', 'PedidosController::filter');

    // Acciones
    $routes->post('cambiar-estado', 'PedidosController::cambiarEstado');

});


// =====================================================
// PRODUCCION
// =====================================================

$routes->get('produccion', 'ProduccionController::index');
