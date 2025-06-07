<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/db.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

session_start();

$pdo = getPDO();
$app = AppFactory::create();
$app->addBodyParsingMiddleware(); // For JSON parsing

require __DIR__ . '/routes.php';

$app->run();