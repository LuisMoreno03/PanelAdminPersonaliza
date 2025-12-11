<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Auth::index');
$routes->post('auth/login', 'Auth::login');
$routes->get('dashboard', 'Dashboard::index');

/** rutas de conexion con shopify */
$routes->get('api/getOrders', 'ShopifyController::getOrders');
$routes->get('dashboard/filter/(:segment)/(:num)', 'DashboardController::filter/$1/$2');

$routes->post('api/estado/guardar', 'DashboardController::guardarEstado');
$routes->post('api/estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');




