<?php

use CodeIgniter\Router\RouteCollection;
use App\Controllers\DashboardController;

/**
 * @var RouteCollection $routes
 */

$routes->get('/', 'Auth::index');
$routes->post('auth/login', 'Auth::login'); 
$routes->get('logout', 'Auth::logout');


$routes->get('dashboard', 'DashboardController::index');
$routes->get('dashboard/sync', 'DashboardController::sync');
$routes->get('dashboard/filter', 'DashboardController::filter');
$routes->get('shopify/getOrders', 'ShopifyController::getOrders');
$routes->get('shopify/getAllOrders', 'ShopifyController::getAllOrders');
$routes->get('shopify/ordersView', 'ShopifyController::ordersView');

/* API */
$routes->post('api/estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');

$routes->post('shopify/orders/update-tags', 'ShopifyController::updateOrderTags');
$routes->post('shopify/orders/update', 'ShopifyController::updateOrder');

$routes->get('shopify/products', 'ShopifyController::getProducts');
$routes->get('shopify/products/(:num)', 'ShopifyController::getProduct/$1');

$routes->get('shopify/customers', 'ShopifyController::getCustomers');

$routes->get('shopify/test', 'ShopifyController::test');
$routes->get('dashboard/detalles/(:num)', 'DashboardController::detalles/$1');
$routes->post('dashboard/subirImagenProducto', 'DashboardController::subirImagenProducto');

$routes->post('pedidos/cambiar-estado', 'PedidosController::cambiarEstado');

$routes->get('confirmados', 'Confirmados::index');
$routes->get('confirmados/filter', 'Confirmados::filter');
$routes->get('api/confirmados', 'Confirmados::filter');

$routes->post('api/estado/guardar', 'EstadoController::guardar');


$routes->get('dashboard/ping', 'DashboardController::ping');
$routes->get('dashboard/usuarios-estado', 'DashboardController::usuariosEstado');


$routes->get('pedidos', 'PedidosController');
$routes->get('pedidos/filter', 'PedidosController::filter');
$routes->get('api/pedidos', 'PedidosController::filter');
$routes->get('pedidos', 'PedidosController::listarPedidos');
