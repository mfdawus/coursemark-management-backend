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

    $group->get('/dashboard', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query("
            SELECT
                (SELECT COUNT(*) FROM users WHERE role = 'student') AS total_advisees,
                (SELECT COUNT(*)
                 FROM (
                     SELECT student_id, AVG(gpa) AS cgpa
                     FROM final_exams
                     GROUP BY student_id
                 ) AS gpa_stats
                 WHERE cgpa >= 3.0) AS good_standing,
                (SELECT COUNT(*)
                 FROM (
                     SELECT student_id, AVG(gpa) AS cgpa
                     FROM final_exams
                     GROUP BY student_id
                 ) AS gpa_stats
                 WHERE cgpa >= 2.0 AND cgpa < 3.0) AS warning,
                (SELECT COUNT(*)
                 FROM (
                     SELECT student_id, AVG(gpa) AS cgpa
                     FROM final_exams
                     GROUP BY student_id
                 ) AS gpa_stats
                 WHERE cgpa < 2.0) AS probation,
                (SELECT ROUND(AVG(cgpa), 2)
                 FROM (
                     SELECT AVG(gpa) AS cgpa
                     FROM final_exams
                     GROUP BY student_id
                 ) AS avg_cgpa) AS avg_cgpa
        ");
        $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($metrics));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/cgpa-distribution', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query('
            SELECT
                SUM(CASE WHEN cgpa >= 3.5 THEN 1 ELSE 0 END) AS excellent,
                SUM(CASE WHEN cgpa >= 3.0 AND cgpa < 3.5 THEN 1 ELSE 0 END) AS good,
                SUM(CASE WHEN cgpa >= 2.5 AND cgpa < 3.0 THEN 1 ELSE 0 END) AS average,
                SUM(CASE WHEN cgpa < 2.5 THEN 1 ELSE 0 END) AS low
            FROM (
                SELECT AVG(gpa) AS cgpa
                FROM final_exams
                GROUP BY student_id
            ) AS cgpa_stats
        ');
        $buckets = $stmt->fetch(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($buckets));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/notes', function ($request, $response) use ($pdo) {
        $params = $request->getQueryParams();
        $student_id = $params['student_id'] ?? null; // This is matric number from frontend
        $course_id = $params['course_id'] ?? null;

        if (!$student_id) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'student_id is required']));
        }

        // Look up internal user ID from matric number
        $stmt = $pdo->prepare("SELECT id FROM users WHERE matric_number = ? AND role = 'student'");
        $stmt->execute([$student_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Student not found']));
        }
        $internal_id = $user['id'];

        $sql = "SELECT * FROM advisor_notes WHERE student_id = ?";
        $args = [$internal_id];
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

    $group->delete('/notes/{note_id}', function ($request, $response, $args) use ($pdo) {
        $note_id = $args['note_id'];
        $stmt = $pdo->prepare("DELETE FROM advisor_notes WHERE id = ?");
        $stmt->execute([$note_id]);
        $success = $stmt->rowCount() > 0;
        $response->getBody()->write(json_encode(['success' => $success]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // POST insert advisor note by matric number and course id
    $group->post('/notes-by-matric/{matric_number}/{course_id}', function ($request, $response, $args) use ($pdo) {
        $advisor_id = $_SESSION['user']['id'];

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

        try {
            // Insert advisor note
            $stmt = $pdo->prepare("
            INSERT INTO advisor_notes 
            (advisor_id, student_id, course_id, note, meeting_date, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
            $stmt->execute([$advisor_id, $student_id, $course_id, $note, $meeting_date]);

            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });


    // Get all courses a student has participated in (based on marks)
    $group->get('/student-courses', function ($request, $response) use ($pdo) {
        $params = $request->getQueryParams();
        $matric_no = $params['student_id'] ?? null;

        if (!$matric_no) {
            $response->getBody()->write(json_encode(['error' => 'student_id (matric number) is required']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }

        try {
            // Get the user ID from users table using matric number
            $stmt = $pdo->prepare("SELECT id FROM users WHERE matric_number = ?");
            $stmt->execute([$matric_no]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $response->getBody()->write(json_encode(['error' => 'Student not found']));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(404);
            }

            $user_id = $user['id'];

            // Fetch courses
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

            $stmt->execute([$user_id]);
            $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode($courses));
            return $response
                ->withHeader('Content-Type', 'application/json');
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    });

    $group->get('/courses', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query("SELECT id, course_code, course_name, semester, year FROM courses ORDER BY year DESC, semester DESC, course_code");
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($courses));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/courses/{course_id}/students', function ($request, $response, $args) use ($pdo) {
        $course_id = $args['course_id'];
        $stmt = $pdo->prepare("SELECT u.id, u.name, u.matric_number FROM users u JOIN course_user cu ON cu.user_id = u.id WHERE cu.course_id = ? AND cu.role = 'student' ORDER BY u.name");
        $stmt->execute([$course_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($students));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/rankings', function ($request, $response) use ($pdo) {
        // Get all courses (include semester and year)
        $stmt = $pdo->query("
            SELECT c.id, c.course_code, c.course_name, c.semester, c.year
            FROM courses c
            ORDER BY c.course_code
        ");
        $courses = $stmt->fetchAll();

        $results = [];

        foreach ($courses as $course) {
            $course_id = $course['id'];

            // Get all students' total marks in the course
            $stmt2 = $pdo->prepare("
                SELECT 
                    u.id as student_id,
                    u.name,
                    COALESCE(SUM((m.mark_obtained / a.max_mark) * a.weight), 0) +
                    COALESCE(f.final_mark, 0) AS total_mark
                FROM users u
                JOIN course_user cu ON cu.user_id = u.id
                JOIN assessments a ON a.course_id = cu.course_id
                LEFT JOIN marks m ON m.assessment_id = a.id AND m.student_id = u.id
                LEFT JOIN final_exams f ON f.course_id = cu.course_id AND f.student_id = u.id
                WHERE cu.course_id = ? AND cu.role = 'student'
                GROUP BY u.id, u.name, f.final_mark
                ORDER BY total_mark DESC
            ");
            $stmt2->execute([$course_id]);
            $students = $stmt2->fetchAll();

            // Class average
            $totalScores = array_column($students, 'total_mark');
            $average = count($totalScores) ? array_sum($totalScores) / count($totalScores) : 0;

            $results[] = [
                'course_code' => $course['course_code'],
                'course_name' => $course['course_name'],
                'semester' => $course['semester'],
                'year' => $course['year'],
                'total_students' => count($students),
                'class_average' => round($average, 2),
                'students' => array_map(function ($s) {
                    return [
                        'name' => $s['name'],
                        'total_mark' => round($s['total_mark'], 2)
                    ];
                }, $students)
            ];
        }

        $response->getBody()->write(json_encode($results));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/students-by-course', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query("SELECT c.course_name, COUNT(cu.user_id) AS student_count FROM course_user cu JOIN courses c ON c.id = cu.course_id WHERE cu.role = 'student' GROUP BY c.course_name ORDER BY c.course_name");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/avg-cgpa-by-course', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query('
            SELECT c.course_name, ROUND(AVG(student_cgpa), 2) AS avg_cgpa
            FROM (
                SELECT f.course_id, f.student_id, AVG(f.gpa) AS student_cgpa
                FROM final_exams f
                GROUP BY f.course_id, f.student_id
            ) AS per_student
            JOIN courses c ON c.id = per_student.course_id
            GROUP BY c.course_name
            ORDER BY c.course_name
        ');
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/top-10-students', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query('
            SELECT u.name, u.matric_number, ROUND(AVG(f.gpa), 2) AS cgpa
            FROM users u
            JOIN final_exams f ON f.student_id = u.id
            WHERE u.role = "student"
            GROUP BY u.id, u.name, u.matric_number
            HAVING COUNT(f.gpa) > 0
            ORDER BY cgpa DESC
            LIMIT 10
        ');
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($students));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/at-risk-students', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query('
            SELECT u.name, u.matric_number, ROUND(AVG(f.gpa), 2) AS cgpa
            FROM users u
            JOIN final_exams f ON f.student_id = u.id
            WHERE u.role = "student"
            GROUP BY u.id, u.name, u.matric_number
            HAVING cgpa < 2.0
            ORDER BY cgpa ASC
        ');
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($students));
        return $response->withHeader('Content-Type', 'application/json');
    });
})->add($authMiddleware);
