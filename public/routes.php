<?php

use Slim\Routing\RouteCollectorProxy;

$authMiddleware = require __DIR__ . '/../middleware/AuthMiddleware.php';


$app->post('/api/login', function ($request, $response) use ($pdo) {
    $data = $request->getParsedBody();
    $matric = trim($data['matric_number'] ?? '');
    $password = $data['password'] ?? '';
    $pin = $data['pin'] ?? '';

    // Validate inputs
    if (!$matric || !$password || !$pin) {
        $payload = ['success' => false, 'message' => 'Missing fields.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')
            ->withStatus(400);
    }

    // Prepare and execute query
    $stmt = $pdo->prepare("SELECT * FROM users WHERE matric_number = :matric LIMIT 1");
    $stmt->execute([':matric' => $matric]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if user exists and verify password
    if (!$user) {
        // User not found
        $payload = ['success' => false, 'message' => 'Invalid credentials.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }

    if (!password_verify($password, $user['password']) || $user['pin'] !== ($pin)) {
        // Password or PIN mismatch
        $payload = ['success' => false, 'message' => 'Invalid credentials.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }

    // Success: user found and password verified
    $payload = [
        'success' => true,
        'matric_number' => $user['matric_number'] ?? '',
        'name' => $user['name'] ?? '',
        'role' => $user['role'] ?? ''
    ];
    //PUT IN SESSION

    $_SESSION['user'] = [
        'id' => $user['id'],
        'matric_number' => $user['matric_number'],
        'role' => $user['role']
    ];

    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});


$app->post('/api/login/staff', function ($request, $response) use ($pdo) {
    $data = $request->getParsedBody();
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    // Validate inputs
    if (!$email || !$password) {
        $payload = ['success' => false, 'message' => 'Missing fields.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')
            ->withStatus(400);
    }

    // Prepare and execute query
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if user exists and verify password
    if (!$user) {
        // User not found
        $payload = ['success' => false, 'message' => 'Invalid credentials.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }

    if (!password_verify($password, $user['password'])) {
        // Password or PIN mismatch
        $payload = ['success' => false, 'message' => 'Invalid credentials.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }

    // Success: user found and password verified
    $payload = [
        'success' => true,
        'name' => $user['name'] ?? '',
        'role' => $user['role'] ?? ''
    ];

    //PUT IN SESSION
    $_SESSION['user'] = [
        'id' => $user['id'],
        'email' => $user['email'],
        'role' => $user['role']
    ];

    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});


$app->post('/api/logout', function ($request, $response) {
    session_unset();
    session_destroy();

    $payload = json_encode([
        'success' => true,
        'message' => 'Logged out'
    ]);

    $response->getBody()->write($payload);

    return $response->withHeader('Content-Type', 'application/json');
});

/* STUDENT ROUTES */
$app->group('/student', function (RouteCollectorProxy $group) {

    $group->get('/compare', function ($request, $response) {

        return $response;
    });

    $group->get('/simulator', function ($request, $response) {

        return $response;
    });
})->add($authMiddleware);




/* LECTURER ROUTES */
$app->group('/lecturer', function (RouteCollectorProxy $group) {


    $group->get('/marks', function ($request, $response) {

        return $response;
    });

    $group->get('/courses', function ($request, $response) {

        return $response;
    });

    $group->get('/progress', function ($request, $response) {

        return $response;
    });

    $group->get('/analytics', function ($request, $response) {

        return $response;
    });
})->add($authMiddleware);



/* ADMIN ROUTES */
$app->group('/admin', function (RouteCollectorProxy $group) {

    $group->get('', function ($request, $response) {

        return $response;
    });
})->add($authMiddleware);


/* ADVISOR ROUTES */
$app->group('/advisor', function (RouteCollectorProxy $group) {

    $group->get('/adviseelist', function ($request, $response) {

        return $response;
    });
    $group->get('/adviseereport', function ($request, $response) {

        return $response;
    });
})->add($authMiddleware);
