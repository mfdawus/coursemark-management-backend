<?php

$authMiddleware = require __DIR__ . '/../middleware/AuthMiddleware.php';

require __DIR__ . '/../public/AuthRoutes.php';
require __DIR__ . '/../public/StudentRoutes.php';
require __DIR__ . '/../public/LecturerRoutes.php';
require __DIR__ . '/../public/AdminRoutes.php';
require __DIR__ . '/../public/AdvisorRoutes.php';

// Example route to get session
$app->get('/api/session', function ($request, $response) {
    if (!isset($_SESSION['user'])) {
        $response->getBody()->write(json_encode(['error' => 'Not logged in']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $user = $_SESSION['user'];

    $response->getBody()->write(json_encode([
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'matric_number' => $user['matric_number'],
        'role' => $user['role'],
    ]));

    return $response->withHeader('Content-Type', 'application/json');
});
