<?php

$authMiddleware = require __DIR__ . '/../middleware/AuthMiddleware.php';

require __DIR__ . '/../public/AuthRoutes.php';
require __DIR__ . '/../public/StudentRoutes.php';
require __DIR__ . '/../public/LecturerRoutes.php';
require __DIR__ . '/../public/AdminRoutes.php';
require __DIR__ . '/../public/AdvisorRoutes.php';

/* GET SESSION */

$app->get('/api/session', function ($request, $response) {

    if (!isset($_SESSION['user'])) {
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => 'Not logged in']));
    }

    $user = $_SESSION['user'];

    // Only return safe fields
    $response->getBody()->write(json_encode([
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'matric_number' => $user['matric_number'],
        'role' => $user['role'],
    ]));

    return $response->withHeader('Content-Type', 'application/json');
});
