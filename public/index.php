<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

// Load .env (you’ll create this in server/)
Dotenv::createImmutable(__DIR__ . '/..')->load();

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// Simple CORS so Vue (on a different port) can call you
$app->add(function ($req, $handler) {
  $res = $handler->handle($req);
  return $res
    ->withHeader('Access-Control-Allow-Origin', '*')
    ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
    ->withHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
});

// Example route
$app->get('/ping', function ($req, $res) {
  $res->getBody()->write(json_encode(['pong' => true]));
  return $res->withHeader('Content-Type', 'application/json');
});

$app->run();
