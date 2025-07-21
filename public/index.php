<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/db.php';

use Slim\Factory\AppFactory;
use Tuupola\Middleware\CorsMiddleware;

session_set_cookie_params([
  'secure' => true,
  'httponly' => true,
  'samesite' => 'None',
]);

session_start();

// Create app
$app = AppFactory::create();

// Database
$pdo = getPDO();

// Add CORS middleware
$app->add(new CorsMiddleware([
    "origin" => ["https://e-klas.site"],  // No localhost here anymore
    "methods" => ["GET", "POST", "PUT", "DELETE", "OPTIONS"],
    "headers.allow" => ["Authorization", "Content-Type"],
    "credentials" => true,
]));


// Add body parsing middleware
$app->addBodyParsingMiddleware();

// Now that $app exists, load routes AFTER this
require __DIR__ . '/../public/routes.php';

// Run app
$app->run();
