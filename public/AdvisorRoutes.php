<?php

use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;


/* ADVISOR ROUTES */
$app->group('/api/advisor',  function (RouteCollectorProxy $group) use ($pdo) {

    $group->get('/adviseelist', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query("
            SELECT
              u.name AS student_name,
              u.matric_number AS student_id,
              'Software Engineering' AS program,
              ROUND((
                (
                  COALESCE((
                    SELECT AVG(m2.mark_obtained)
                    FROM marks m2
                    JOIN assessments a2 ON m2.assessment_id = a2.id
                    WHERE m2.student_id = u.id
                  ), 0)
                  +
                  COALESCE((
                    SELECT fe.final_mark
                    FROM final_exams fe
                    WHERE fe.student_id = u.id
                    LIMIT 1
                  ), 0)
                ) / 2
              ), 2) AS cgpa,
              CASE
                WHEN ((
                  COALESCE((
                    SELECT AVG(m2.mark_obtained)
                    FROM marks m2
                    JOIN assessments a2 ON m2.assessment_id = a2.id
                    WHERE m2.student_id = u.id
                  ), 0)
                  +
                  COALESCE((
                    SELECT fe.final_mark
                    FROM final_exams fe
                    WHERE fe.student_id = u.id
                    LIMIT 1
                  ), 0)
                ) / 2) >= 80 THEN 'Good Standing'
                WHEN ((
                  COALESCE((
                    SELECT AVG(m2.mark_obtained)
                    FROM marks m2
                    JOIN assessments a2 ON m2.assessment_id = a2.id
                    WHERE m2.student_id = u.id
                  ), 0)
                  +
                  COALESCE((
                    SELECT fe.final_mark
                    FROM final_exams fe
                    WHERE fe.student_id = u.id
                    LIMIT 1
                  ), 0)
                ) / 2) >= 60 THEN 'Warning'
                ELSE 'Probation'
              END AS status
            FROM
              users u
            WHERE u.role = 'student'
            ORDER BY
              u.name;
        ");
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($students));
        return $response->withHeader('Content-Type', 'application/json');
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

    $group->get('/notes', function ($request, $response) use ($pdo) {
        $params = $request->getQueryParams();
        $student_id = $params['student_id'] ?? null;
        $course_id = $params['course_id'] ?? null;

        if (!$student_id) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'student_id is required']));
        }

        $sql = "SELECT * FROM advisor_notes WHERE student_id = ?";
        $args = [$student_id];
        if ($course_id) {
            $sql .= " AND course_id = ?";
            $args[] = $course_id;
        }
        $sql .= " ORDER BY created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($notes));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->post('/notes', function ($request, $response) use ($pdo) {
        $data = json_decode($request->getBody()->getContents(), true);

        $advisor_id = $data['advisor_id'] ?? null;
        $student_id = $data['student_id'] ?? null;
        $course_id = $data['course_id'] ?? null;
        $note = $data['note'] ?? null;
        $meeting_date = $data['meeting_date'] ?? null;

        if (!$advisor_id || !$student_id || !$note) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'advisor_id, student_id, and note are required']));
        }

        $stmt = $pdo->prepare("INSERT INTO advisor_notes (advisor_id, student_id, course_id, note, meeting_date, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$advisor_id, $student_id, $course_id, $note, $meeting_date]);

        $response->getBody()->write(json_encode(['success' => true, 'id' => $pdo->lastInsertId()]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // POST insert advisor note by matric number and course id
    $group->post('/notes-by-matric/{matric_number}/{course_id}', function ($request, $response, $args) use ($pdo) {
        session_start();
        $$advisor_id = 1; // hardcode your advisor user id

        if (!$advisor_id) {
            $response->getBody()->write(json_encode(['error' => 'Not authenticated']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }
        $matric_number = $args['matric_number'];
        $course_id = $args['course_id'];
        $data = json_decode($request->getBody()->getContents(), true);
        $note = $data['note'] ?? null;
        $meeting_date = $data['meeting_date'] ?? null;

        if (!$note || !$meeting_date) {
            $response->getBody()->write(json_encode(['error' => 'note and meeting_date are required']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Get student ID from matric number
        $stmt = $pdo->prepare("SELECT id FROM users WHERE matric_number = ? AND role = 'student'");
        $stmt->execute([$matric_number]);
        $student = $stmt->fetch();
        if (!$student) {
            $response->getBody()->write(json_encode(['error' => 'Student with given matric number not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
        $student_id = $student['id'];

        // Insert advisor note
        $stmt = $pdo->prepare("INSERT INTO advisor_notes (advisor_id, student_id, course_id, note, meeting_date, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$advisor_id, $student_id, $course_id, $note, $meeting_date]);

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });

   // Get all courses a student has participated in (based on marks)
$group->get('/student-courses', function ($request, $response) use ($pdo) {
  $params = $request->getQueryParams();
  $student_id = $params['student_id'] ?? null;

  if (!$student_id) {
      return $response
          ->withHeader('Content-Type', 'application/json')
          ->withStatus(400)
          ->write(json_encode(['error' => 'student_id is required']));
  }

  try {
      $stmt = $pdo->prepare("
          SELECT DISTINCT
              c.id,
              c.course_name,
              c.course_code,
              c.semester,
              c.year
          FROM
              marks m
          INNER JOIN
              assessments a ON m.assessment_id = a.id
          INNER JOIN
              courses c ON a.course_id = c.id
          WHERE
              m.student_id = ?
          ORDER BY
              c.year DESC,
              c.semester DESC,
              c.course_code
      ");

      $stmt->execute([$student_id]);
      $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

      return $response
          ->withHeader('Content-Type', 'application/json')
          ->write(json_encode($courses));
  } catch (PDOException $e) {
      return $response
          ->withHeader('Content-Type', 'application/json')
          ->withStatus(500)
          ->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
  }
});

})->add($authMiddleware);
