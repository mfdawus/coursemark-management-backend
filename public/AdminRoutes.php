<?php

use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;

/* ADMIN ROUTES */

$app->group('/api/admin',  function (RouteCollectorProxy $group) use ($pdo) {

    $group->get('/users', function ($request, $response) use ($pdo) {
        try {
            $stmt = $pdo->query("SELECT id, name, email, matric_number,role,program,pin, created_at, updated_at FROM users");
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $payload = json_encode(['students' => $students]);

            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json');
        } catch (PDOException $e) {
            $error = ['error' => 'Failed to fetch students', 'details' => $e->getMessage()];
            $response->getBody()->write(json_encode($error));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    $group->post('/users', function ($request, $response) use ($pdo) {

        $data = json_decode($request->getBody()->getContents(), true);

        if (
            empty($data['name']) || empty($data['email']) || empty($data['password']) || empty($data['role'])
        ) {
            $response->getBody()->write(json_encode(['message' => 'Missing fields']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $stmt = $pdo->prepare("INSERT INTO users (name, email, matric_number, program, password, role, pin) VALUES (:name, :email, :matric, :program, :password, :role, :pin)");

        $stmt->execute([
            ':name' => $data['name'],
            ':email' => $data['email'],
            ':matric' => $data['matric'],
            ':program' => $data['program'],
            ':password' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':role' => $data['role'],
            ':pin' => $data['pin'],
        ]);

        $payload = json_encode(['message' => 'User added successfully']);

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    });


    $group->put('/users/{id}/details', function ($request, $response, $args) use ($pdo) {
        $id = $args['id'];
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("UPDATE users 
        SET name = :name, 
            email = :email, 
            matric_number = :matric, 
            program = :program,
            role = :role,
            pin = :pin
        WHERE id = :id");

        $stmt->execute([
            ':name'   => $data['name'],
            ':email'  => $data['email'],
            ':matric' => $data['matric'],
            ':program' => $data['program'],
            ':role'   => $data['role'],
            ':pin'   => $data['pin'],
            ':id'     => $id,
        ]);

        $payload = json_encode(['message' => 'User details updated']);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->put('/users/{id}/password', function ($request, $response, $args) use ($pdo) {
        $id = $args['id'];
        $data = $request->getParsedBody();
        $hashed = password_hash($data['password'], PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
        $stmt->execute([
            ':password' => $hashed,
            ':id' => $id,
        ]);

        $response->getBody()->write(json_encode(['message' => 'Password updated']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->put('/users/{id}/role', function ($request, $response, $args) use ($pdo) {
        $id = $args['id'];
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("UPDATE users SET role = :role WHERE id = :id");
        $stmt->execute([
            ':role' => $data['role'],
            ':id' => $id,
        ]);

        $response->getBody()->write(json_encode(['message' => 'Role updated']));
        return $response->withHeader('Content-Type', 'application/json');
    });
})->add($authMiddleware);
