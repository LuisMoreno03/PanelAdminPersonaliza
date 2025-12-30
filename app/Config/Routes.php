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
$routes->post('api/estado/guardar', 'DashboardController::guardarEstado');
$routes->post('api/estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');

$routes->post('shopify/orders/update-tags', 'ShopifyController::updateOrderTags');
$routes->post('shopify/orders/update', 'ShopifyController::updateOrder');

$routes->get('shopify/products', 'ShopifyController::getProducts');
$routes->get('shopify/products/(:num)', 'ShopifyController::getProduct/$1');

$routes->get('shopify/customers', 'ShopifyController::getCustomers');

$routes->get('shopify/test', 'ShopifyController::test');
$routes->get('dashboard/detalles/(:num)', 'DashboardController::detalles/$1');
$routes->post('dashboard/subirImagenProducto', 'DashboardController::subirImagenProducto');
$routes->get('confirmados', 'DashboardController::indexConfirmados');