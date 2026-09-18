<?php

/**
 * Server-rendered page routes (see src/Views/ and src/Core/View.php) —
 * distinct from routes/api.php's JSON API. Covers the primary customer
 * journey (post a project -> browse listings -> track quotes/milestones)
 * per planning/03-construction-co-ke/user-flows.md §1; admin/dispute
 * consoles are not built here. Mirrors laundry.co.ke's routes/web.php
 * pattern (see planning/00-portfolio/ui-implementation-plan.md).
 *
 * @var \Construction\Core\Router $router
 */

use Construction\Controllers\PageController;

$page = new PageController();

$router->get('/', [$page, 'home']);
$router->get('/login', [$page, 'loginForm']);
$router->get('/signup', [$page, 'signupForm']);
$router->get('/onboarding', [$page, 'onboardingForm']);
$router->get('/projects/new', [$page, 'projectForm']);
$router->get('/equipment', [$page, 'equipmentIndex']);
$router->get('/crew', [$page, 'crewIndex']);
$router->get('/providers/{id}', [$page, 'providerProfile']);
$router->get('/projects/{id}', [$page, 'projectStatus']);
