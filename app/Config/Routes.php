<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'Auth::index');
$routes->post('auth/login', 'Auth::login');
$routes->get('logout', 'Auth::logout');

$routes->group('dashboard', ['filter' => 'auth'], function (RouteCollection $routes) {
    $routes->get('/', 'DashboardController::index');

    $routes->get('pedidos', 'DashboardController::pedidos'); // ✅ /dashboard/pedidos
    $routes->get('filter',  'DashboardController::filter');  // fallback

    $routes->get('detalles/(:num)', 'DashboardController::detalles/$1');
    $routes->post('subirImagenProducto', 'DashboardController::subirImagenProducto');

    $routes->get('ping', 'DashboardController::ping');
    $routes->get('usuarios-estado', 'DashboardController::usuariosEstado');
});

$routes->group('api', ['filter' => 'auth'], function (RouteCollection $routes) {
    $routes->post('estado/guardar', 'EstadoController::guardar');
    $routes->post('estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');
    $routes->get('confirmados', 'Confirmados::filter');
});

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

$routes->group('confirmados', ['filter' => 'auth'], function (RouteCollection $routes) {
    $routes->get('/', 'Confirmados::index');
    $routes->get('filter', 'Confirmados::filter');
});

$routes->group('pedidos', ['filter' => 'auth'], function (RouteCollection $routes) {
    $routes->get('/', 'PedidosController::index');
    $routes->get('filter', 'PedidosController::filter');
    $routes->post('cambiar-estado', 'PedidosController::cambiarEstado');
});

$routes->group('produccion', ['filter' => 'auth'], function (RouteCollection $routes) {
    $routes->get('/', 'ProduccionController::index');
});
