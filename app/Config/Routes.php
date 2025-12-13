<?php

use CodeIgniter\Router\RouteCollection;
use App\Controllers\DashboardController;

/**
 * @var RouteCollection $routes
 */

$routes->get('/', 'Auth::index');
$routes->post('auth/login', 'Auth::login');


$routes->get('dashboard', 'DashboardController::index');
$routes->get('dashboard/sync', 'DashboardController::sync');
$routes->get('dashboard/filter', 'DashboardController::filter');

/* API */
$routes->post('api/estado/guardar', 'DashboardController::guardarEstado');
$routes->post('api/estado/etiquetas/guardar', 'DashboardController::guardarEtiquetas');
