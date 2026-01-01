<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// ================================
// AUTH
// ================================
$routes->get('/', 'Auth::index');
$routes->post('auth/login', 'Auth::login');
$routes->get('logout', 'Auth::logout');


// ================================
// DASHBOARD
// ================================
$routes->get('dashboard', 'DashboardController::index');
$routes->get('dashboard/sync', 'DashboardController::sync');
$routes->get('dashboard/filter', 'DashboardController::filter');

$routes->get('dashboard/detalles/(:num)', 'DashboardController::detalles/$1');
$routes->post('dashboard/subirImagenProducto', 'DashboardController::subirImagenProducto');

// Estado usuarios
$routes->get('dashboard/ping', 'DashboardController::ping');
$routes->get('dashboard/usuarios-estado', 'DashboardController::usuariosEstado');
$routes->group('dashboard', ['filter' => 'auth'], function($routes) {
    $routes->get('/', 'Dashboard::index');
    $routes->get('ping', 'Dashboard::ping');
    $routes->get('usuarios-estado', 'Dashboard::usuariosEstado');
    $routes->get('shopify/productos', 'Dashboard::shopifyProductos');
});


// ================================
// API (ESTADO / ETIQUETAS)
// ================================
$routes->post('api/estado/guardar', 'EstadoController::guardar');
$routes->post('api/estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');


// ================================
// SHOPIFY
// ================================
$routes->get('shopify/getOrders', 'ShopifyController::getOrders');
$routes->get('shopify/getAllOrders', 'ShopifyController::getAllOrders');      // si existe ese método
$routes->get('shopify/ordersView', 'ShopifyController::ordersView');

$routes->get('shopify/order/(:num)', 'ShopifyController::getOrder/$1');

$routes->post('shopify/orders/update-tags', 'ShopifyController::updateOrderTags');
$routes->post('shopify/orders/update', 'ShopifyController::updateOrder');

$routes->get('shopify/products', 'ShopifyController::getProducts');
$routes->get('shopify/products/(:num)', 'ShopifyController::getProduct/$1');

$routes->get('shopify/customers', 'ShopifyController::getCustomers');

$routes->get('shopify/test', 'ShopifyController::test');


// ================================
// CONFIRMADOS
// ================================
$routes->get('confirmados', 'Confirmados::index');
$routes->get('confirmados/filter', 'Confirmados::filter');
$routes->get('api/confirmados', 'Confirmados::filter');


// ================================
// PEDIDOS
// ================================

// ✅ Elige UNA ruta principal para /pedidos
$routes->get('pedidos', 'PedidosController::index'); // o listarPedidos, pero solo una

$routes->get('pedidos/filter', 'PedidosController::filter');
$routes->get('api/pedidos', 'PedidosController::filter');

$routes->post('pedidos/cambiar-estado', 'PedidosController::cambiarEstado');
