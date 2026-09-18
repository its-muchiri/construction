<?php

/**
 * Route table for construction.co.ke. Mirrors
 * planning/03-construction-co-ke/api-endpoints.md. Only the core resource
 * groups are wired here; equipment/crew listing search endpoints are left
 * for feature implementation.
 *
 * @var \Construction\Core\Router $router
 */

use Construction\Controllers\AuthController;
use Construction\Controllers\DisputeController;
use Construction\Controllers\ListingController;
use Construction\Controllers\OnboardingController;
use Construction\Controllers\PaymentController;
use Construction\Controllers\ProjectController;
use Construction\Controllers\ReviewController;
use Construction\Controllers\StoreController;

$auth = new AuthController();
$project = new ProjectController();
$payment = new PaymentController();
$review = new ReviewController();
$dispute = new DisputeController();
$listing = new ListingController();
$store = new StoreController();
$onboarding = new OnboardingController();

// Auth (issues the bearer token every other write endpoint requires)
$router->post('/api/v1/auth/signup', [$auth, 'signup']);
$router->post('/api/v1/auth/login', [$auth, 'login']);
$router->get('/api/v1/auth/me', [$auth, 'me']);

// Bookings / Projects
$router->post('/api/v1/projects', [$project, 'create']);
$router->get('/api/v1/projects', [$project, 'index']);
$router->get('/api/v1/projects/{id}', [$project, 'show']);
$router->post('/api/v1/projects/{id}/quotes', [$project, 'submitQuote']);
$router->patch('/api/v1/projects/{id}/quotes/{quoteId}/accept', [$project, 'acceptQuote']);
$router->patch('/api/v1/projects/{id}/status', [$project, 'updateStatus']);
$router->post('/api/v1/projects/{id}/cancel', [$project, 'cancel']);

// Milestones
$router->get('/api/v1/projects/{id}/milestones', [$project, 'listMilestones']);
$router->post('/api/v1/projects/{id}/milestones/{milestoneId}/request-signoff', [$project, 'requestMilestoneSignoff']);
$router->post('/api/v1/projects/{id}/milestones/{milestoneId}/confirm', [$project, 'confirmMilestone']);

// Condition reports
$router->post('/api/v1/projects/{id}/condition-reports', [$project, 'submitConditionReport']);
$router->get('/api/v1/projects/{id}/condition-reports', [$project, 'getConditionReports']);

// Payments
$router->post('/api/v1/payments/mpesa/stk-push', [$payment, 'stkPush']);
$router->post('/api/v1/payments/mpesa/callback', [$payment, 'mpesaCallback']);
$router->post('/api/v1/payments/card', [$payment, 'card']);
$router->get('/api/v1/providers/me/earnings', [$payment, 'myEarnings']);

// Reviews
$router->post('/api/v1/projects/{id}/review', [$review, 'store']);
$router->get('/api/v1/providers/{id}/reviews', [$review, 'forProvider']);

// Disputes
$router->post('/api/v1/projects/{id}/disputes', [$dispute, 'store']);
$router->get('/api/v1/disputes', [$dispute, 'index']);
$router->patch('/api/v1/disputes/{id}/resolve', [$dispute, 'resolve']);

// Provider onboarding (Tier 3 KYC)
$router->post('/api/v1/providers/onboard', [$onboarding, 'submit']);
$router->get('/api/v1/providers/{id}', [$listing, 'providerProfile']);

// Equipment / crew listings
$router->post('/api/v1/equipment-listings', [$listing, 'createEquipment']);
$router->get('/api/v1/equipment-listings', [$listing, 'searchEquipment']);
$router->post('/api/v1/crew-listings', [$listing, 'createCrew']);
$router->get('/api/v1/crew-listings', [$listing, 'searchCrew']);

// Store
$router->get('/api/v1/store/products', [$store, 'listProducts']);
$router->post('/api/v1/store/orders', [$store, 'createOrder']);
