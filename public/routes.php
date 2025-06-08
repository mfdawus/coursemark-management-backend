<?php

use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;

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
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email AND role != 'student' LIMIT 1");
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

$app->group('/api/lecturer', function (RouteCollectorProxy $group) use ($pdo) {

    $group->get('/analytics', function ($request, $response) use ($pdo) {
        try {
            // Fetch all students
            $stmt = $pdo->prepare("SELECT id, name, matric_number FROM users WHERE role = 'student'");
            $stmt->execute();
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($students)) {
                $payload = [
                    'success' => false,
                    'message' => 'No students found.',
                    'students' => []
                ];
                $response->getBody()->write(json_encode($payload));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $payload = [
                'success' => true,
                'message' => 'Students fetched successfully.',
                'students' => $students
            ];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (PDOException $e) {
            $payload = [
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });


    $group->get('/marks', function ($request, $response) {

        return $response;
    });

    // GET all courses
    $group->get('/courses', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query("SELECT * FROM courses ORDER BY created_at DESC");
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($courses));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // POST: Create course
    $group->post('/courses', function ($request, $response) use ($pdo) {
        $data = $request->getParsedBody();
        $stmt = $pdo->prepare("INSERT INTO courses (course_code, course_name, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$data['course_code'], $data['course_name']]);
        return $response->withJson(['success' => true, 'message' => 'Course added.']);
    });

    // PUT: Update course
    $group->put('/courses/{id}', function ($request, $response, $args) use ($pdo) {
        $id = $args['id'];
        $data = $request->getParsedBody();
        $stmt = $pdo->prepare("UPDATE courses SET course_code = ?, course_name = ? WHERE id = ?");
        $stmt->execute([$data['course_code'], $data['course_name'], $id]);
        return $response->withJson(['success' => true, 'message' => 'Course updated.']);
    });

    // DELETE: Delete course
    $group->delete('/courses/{id}', function ($request, $response, $args) use ($pdo) {
        $id = $args['id'];
        $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
        $stmt->execute([$id]);
        return $response->withJson(['success' => true, 'message' => 'Course deleted.']);
    });

    $group->get('/enrollments', function ($request, Response $response) use ($pdo) {
    $sql = "SELECT course_user.id, users.name AS student_name, courses.course_name
            FROM course_user
            JOIN users ON users.id = course_user.user_id
            JOIN courses ON courses.id = course_user.course_id
            WHERE users.role = 'student'";
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $payload = json_encode($data);

    // Get response body stream and write to it
    $response->getBody()->write($payload);

    // Return response with JSON header
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


    $group->post('/enroll', function ($request, $response) use ($pdo) {
        $data = $request->getParsedBody();
        $stmt = $pdo->prepare("INSERT INTO course_user (user_id, course_id, role, created_at) VALUES (?, ?, 'student', NOW())");
        $stmt->execute([$data['student_id'], $data['course_id']]);
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201)
            ->write(json_encode(['success' => true]));
    });

    $group->put('/enroll/{id}', function ($request, $response, $args) use ($pdo) {
        $data = $request->getParsedBody();
        $stmt = $pdo->prepare("UPDATE course_user SET user_id = ?, course_id = ? WHERE id = ?");
        $stmt->execute([$data['student_id'], $data['course_id'], $args['id']]);
        return $response->withHeader('Content-Type', 'application/json')->write(json_encode(['success' => true]));
    });

    $group->delete('/enroll/{id}', function ($request, $response, $args) use ($pdo) {
        $stmt = $pdo->prepare("DELETE FROM course_user WHERE id = ?");
        $stmt->execute([$args['id']]);
        return $response->withHeader('Content-Type', 'application/json')->write(json_encode(['success' => true]));
    });



    $group->get('/progress', function ($request, $response) {

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
