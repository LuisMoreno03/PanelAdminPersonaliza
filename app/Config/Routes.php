<?php

use CodeIgniter\Router\RouteCollection;
use App\Controllers\DashboardController;

/**
 * @var RouteCollection $routes
 */

$routes->get('/', 'Auth::index');
$routes->post('auth/login', 'Auth::login'); 
$routes->get('/logout', 'AuthController::logout');


$routes->get('dashboard', 'DashboardController::index');
$routes->get('dashboard/sync', 'DashboardController::sync');
$routes->get('dashboard/filter', 'DashboardController::filter');

/* API */
$routes->post('api/estado/guardar', 'DashboardController::guardarEstado');
$routes->post('api/estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');
$routes->get('shopify/orders', 'ShopifyController::getOrders');
$routes->get('shopify/orders/(:num)', 'ShopifyController::getOrder/$1');

$routes->post('shopify/orders/update-tags', 'ShopifyController::updateOrderTags');
$routes->post('shopify/orders/update', 'ShopifyController::updateOrder');

$routes->get('shopify/products', 'ShopifyController::getProducts');
$routes->get('shopify/products/(:num)', 'ShopifyController::getProduct/$1');

$routes->get('shopify/customers', 'ShopifyController::getCustomers');

$routes->get('shopify/test', 'ShopifyController::test');
$routes->get('dashboard/detalles/(:num)', 'DashboardController::detalles/$1');
