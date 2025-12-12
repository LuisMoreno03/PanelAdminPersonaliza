<?php

use CodeIgniter\Router\RouteCollection;
use App\Controllers\DashboardController;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Auth::index');
$routes->post('auth/login', 'Auth::login');
$routes->get('dashboard', 'Dashboard::index');

/** rutas de conexion con shopify */
$routes->get('api/getOrders', 'ShopifyController::getOrders');
$routes->get('dashboard/filter', 'DashboardController::filter');
$routes->get('dashboard/filter/(:any)', 'DashboardController::filter/$1');


$routes->post('api/estado/guardar', 'DashboardController::guardarEstado');
$routes->post('api/estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');
$routes->get('dashboard/sync', 'DashboardController::syncPedidos');


$routes->setAutoRoute(false);



