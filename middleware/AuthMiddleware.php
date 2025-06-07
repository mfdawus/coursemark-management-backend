<?php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;


return function (Request $request, Handler $handler): Response {


    // Check if user is logged in
    if (!isset($_SESSION['user'])) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    // Get user role
    $userRole = $_SESSION['user']['role'] ?? null;

    // Get route path to check which group they're accessing
    $uri = $request->getUri()->getPath();

    // Define access rules based on role
    if (str_starts_with($uri, '/student') && $userRole !== 'student') {
        return unauthorized('Students only');
    }

    if (str_starts_with($uri, '/advisor') && $userRole !== 'advisor') {
        return unauthorized('Advisors only');
    }

    if (str_starts_with($uri, '/admin') && $userRole !== 'admin') {
        return unauthorized('Admins only');
    }

    if (str_starts_with($uri, '/lecturer') && $userRole !== 'lecturer') {
        return unauthorized('Lecturers only');
    }

    // If all checks pass

    function unauthorized($message = 'Unauthorized')
    {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }

    return $handler->handle($request);
};
