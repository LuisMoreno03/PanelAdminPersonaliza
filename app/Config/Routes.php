<?php

use CodeIgniter\Router\RouteCollection;
use App\Controllers\DashboardController;

/**
 * @var RouteCollection $routes
 */

$routes->get('/', 'Auth::index');
$routes->post('auth/login', 'Auth::login');

/* DASHBOARD */
$routes->get('dashboard', 'DashboardController::index');
$routes->get('dashboard/filter', 'DashboardController::filter');
$routes->get('dashboard/filter/(:any)', 'DashboardController::filter/$1');
$routes->get('dashboard/sync', 'DashboardController::sync');

/* API */
$routes->post('api/estado/guardar', 'DashboardController::guardarEstado');
$routes->post('api/estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');
