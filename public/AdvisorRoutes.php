<?php

use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;


/* ADVISOR ROUTES */
$app->group('/api/advisor',  function (RouteCollectorProxy $group) use ($pdo) {

    $group->get('/adviseelist', function ($request, $response) use ($pdo) {
        return $response;
    });

    // $group->get('/adviseereport', function ($request, $response) {
    //     return $response;
    // });

    // ✅ Use $group->get() instead of $app->get()



    $group->get('/adviseereport', function ($request, $response) use ($pdo) {

        try {
            // First get all student data from main database with weighted calculations
            $stmt = $pdo->prepare("
                SELECT
                  u.name AS student_name,
                  u.matric_number AS student_id,
                  c.course_name,
                  c.semester,
                  c.year,
                  SUM(CASE WHEN a.type = 'assignment' THEN (m.mark_obtained * a.weight / 100) ELSE 0 END) AS assignment,
                  SUM(CASE WHEN a.type = 'quiz' THEN (m.mark_obtained * a.weight / 100) ELSE 0 END) AS quiz,
                  SUM(CASE WHEN a.type = 'lab' THEN (m.mark_obtained * a.weight / 100) ELSE 0 END) AS lab,
                  SUM(CASE WHEN a.type = 'exercise' THEN (m.mark_obtained * a.weight / 100) ELSE 0 END) AS exercise,
                  SUM(CASE WHEN a.type = 'test' THEN (m.mark_obtained * a.weight / 100) ELSE 0 END) AS midterm,
                  SUM(m.mark_obtained * a.weight / 100) AS total_without_final,
                  (
                    SELECT fe.final_mark
                    FROM final_exams fe
                    WHERE fe.student_id = u.id AND fe.course_id = c.id
                    LIMIT 1
                  ) AS final
                FROM
                  marks m
                JOIN assessments a ON m.assessment_id = a.id
                JOIN courses c ON a.course_id = c.id
                JOIN users u ON m.student_id = u.id
                WHERE a.type != 'final'
                GROUP BY
                  u.id,
                  c.course_name,
                  c.semester,
                  c.year
                ORDER BY
                  u.name,
                  c.year DESC,
                  c.semester DESC,
                  c.course_name
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate total and status
            foreach ($results as &$result) {
                $result['total'] = $result['total_without_final'] + ($result['final'] ?? 0);
                if ($result['total'] >= 80) {
                    $result['status'] = 'Good Standing';
                } elseif ($result['total'] >= 60) {
                    $result['status'] = 'Warning';
                } else {
                    $result['status'] = 'Probation';
                }
                unset($result['total_without_final']);
            }

            $response->getBody()->write(json_encode($results, JSON_PRETTY_PRINT));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (PDOException $e) {
            $error = ['error' => 'Query failed: ' . $e->getMessage()];
            $response->getBody()->write(json_encode($error));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
})->add($authMiddleware);
