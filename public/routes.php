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
        'name' => $user['name'],
        'email' => $user['email'],
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
$app->group('/api/student', function (RouteCollectorProxy $group) use ($pdo) {

    $group->get('/dashboard', function ($request, $response) use ($pdo) {

        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401)->write('Unauthorized');
        }

        $student_id = $_SESSION['user']['id'];
        $student_name = $_SESSION['user']['name'];

        // 1. Total Enrolled Courses
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total 
            FROM course_user 
            WHERE user_id = ? AND role = 'student'
        ");
        $stmt->execute([$student_id]);
        $totalCourses = (int) $stmt->fetch()['total'];

        // 2. Average, Highest, Lowest Final Marks
        $stmt = $pdo->prepare("
            SELECT 
                ROUND(AVG(final_mark), 2) AS average, 
                MAX(final_mark) AS highest, 
                MIN(final_mark) AS lowest
            FROM final_exams 
            WHERE student_id = ?
        ");
        $stmt->execute([$student_id]);
        $marks = $stmt->fetch();
        $averageMark = (float) $marks['average'] ?? 0;
        $highestMark = (float) $marks['highest'] ?? 0;
        $lowestMark = (float) $marks['lowest'] ?? 0;

        // 3. Pending Remark Requests
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total 
            FROM remark_requests 
            WHERE student_id = ? AND status = 'pending'
        ");
        $stmt->execute([$student_id]);
        $pendingRemarks = (int) $stmt->fetch()['total'];

        // 4. Recent Notifications
        $stmt = $pdo->prepare("
            SELECT id, title, message, created_at 
            FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$student_id]);
        $notifications = $stmt->fetchAll();

        // 5. Enrolled Courses (Details)
        $stmt = $pdo->prepare("
            SELECT c.id, c.course_code, c.course_name, c.semester, c.year
            FROM courses c
            JOIN course_user cu ON cu.course_id = c.id
            WHERE cu.user_id = ? AND cu.role = 'student'
        ");
        $stmt->execute([$student_id]);
        $enrolledCourses = $stmt->fetchAll();

        // 6. Top 3 Courses by Final Mark
        $stmt = $pdo->prepare("
            SELECT fe.course_id, fe.final_mark, c.course_code, c.course_name
            FROM final_exams fe
            JOIN courses c ON c.id = fe.course_id
            WHERE fe.student_id = ?
            ORDER BY fe.final_mark DESC
            LIMIT 3
        ");
        $stmt->execute([$student_id]);
        $topCourses = $stmt->fetchAll();

        // 7. Advisor Notes
        $stmt = $pdo->prepare("
            SELECT id, note, meeting_date 
            FROM advisor_notes 
            WHERE student_id = ? 
            ORDER BY meeting_date DESC 
            LIMIT 5
        ");
        $stmt->execute([$student_id]);
        $advisorNotes = $stmt->fetchAll();

        // 8. Assessment Completion Status (per course)
        $stmt = $pdo->prepare("
            SELECT 
                c.id AS course_id,
                c.course_code,
                c.course_name,
                COUNT(DISTINCT a.id) AS total,
                COUNT(DISTINCT m.id) AS completed
            FROM courses c
            JOIN assessments a ON a.course_id = c.id
            LEFT JOIN marks m ON m.assessment_id = a.id AND m.student_id = ?
            JOIN course_user cu ON cu.course_id = c.id AND cu.user_id = ?
            WHERE cu.role = 'student'
            GROUP BY c.id
        ");
        $stmt->execute([$student_id, $student_id]);
        $assessmentStatus = $stmt->fetchAll();

        // 9. Recent Remark Requests
        $stmt = $pdo->prepare("
            SELECT rr.id, rr.status, a.title AS assessment_title, c.course_code
            FROM remark_requests rr
            JOIN assessments a ON a.id = rr.assessment_id
            JOIN courses c ON c.id = a.course_id
            WHERE rr.student_id = ?
            ORDER BY rr.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$student_id]);
        $recentRemarkRequests = $stmt->fetchAll();

        // Final response
        $data = [
            'studentName' => $student_name,
            'totalCourses' => $totalCourses,
            'averageMark' => $averageMark,
            'highestMark' => $highestMark,
            'lowestMark' => $lowestMark,
            'pendingRemarks' => $pendingRemarks,
            'recentNotifications' => $notifications,
            'enrolledCourses' => $enrolledCourses,
            'topCourses' => $topCourses,
            'advisorNotes' => $advisorNotes,
            'assessmentStatus' => $assessmentStatus,
            'recentRemarkRequests' => $recentRemarkRequests
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/mymarks', function ($request, $response) use ($pdo) {

        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401)->write('Unauthorized');
        }

        $student_id = $_SESSION['user']['id'];

        // Get courses the student is enrolled in
        $stmt = $pdo->prepare("
            SELECT c.id, c.course_code, c.course_name
            FROM courses c
            JOIN course_user cu ON cu.course_id = c.id
            WHERE cu.user_id = ? AND cu.role = 'student'
        ");
        $stmt->execute([$student_id]);
        $courses = $stmt->fetchAll();

        $result = [];

        foreach ($courses as $course) {
            $course_id = $course['id'];

            // Get all assessments for the course
            $stmt = $pdo->prepare("
                SELECT a.id AS assessment_id, a.title, a.type, a.max_mark, a.weight,
                    m.mark_obtained
                FROM assessments a
                LEFT JOIN marks m 
                ON m.assessment_id = a.id AND m.student_id = ?
                WHERE a.course_id = ?
            ");
            $stmt->execute([$student_id, $course_id]);
            $assessments = $stmt->fetchAll();

            $assessment_list = [];
            $weighted_total = 0;

            foreach ($assessments as $a) {
                $mark = $a['mark_obtained'];
                $max = $a['max_mark'];
                $weight = $a['weight'];

                $weighted = null;

                if ($mark !== null && $max > 0 && $weight !== null) {
                    $score = ($mark / $max) * $weight;
                    $weighted = round($score, 2);
                    $weighted_total += $weighted;
                }

                $assessment_list[] = [
                    'assessment_id' => $a['assessment_id'],
                    'title' => $a['title'],
                    'type' => $a['type'],
                    'mark_obtained' => $mark,
                    'max_mark' => $max,
                    'weight' => $weight,
                    'weighted_mark' => $weighted
                ];
            }

            // Get final exam mark for the course (if any)
            $stmt = $pdo->prepare("
                SELECT final_mark
                FROM final_exams
                WHERE student_id = ? AND course_id = ?
            ");
            $stmt->execute([$student_id, $course_id]);
            $final = $stmt->fetch();

            if ($final && $final['final_mark'] !== null) {
                $weighted_total += (float)$final['final_mark']; // or customize based on your weight logic
            }

            $result[] = [
                'course_id' => $course_id,
                'course_code' => $course['course_code'],
                'course_name' => $course['course_name'],
                'assessments' => $assessment_list,
                'final_exam' => [
                    'final_mark' => $final['final_mark'] ?? null
                ],
                'total_weighted' => round($weighted_total, 2)
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    });


    $group->get('/fullbreakdown', function ($request, $response) use ($pdo) {

        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401)->write('Unauthorized');
        }

        $student_id = $_SESSION['user']['id'];

        // 1. Get assessment marks for all courses
        $stmt = $pdo->prepare("
            SELECT 
                c.course_code,
                c.course_name,
                a.title,
                a.type,
                a.max_mark,
                a.weight,
                m.mark_obtained
            FROM course_user cu
            JOIN courses c ON cu.course_id = c.id
            JOIN assessments a ON a.course_id = c.id
            LEFT JOIN marks m ON m.assessment_id = a.id AND m.student_id = ?
            WHERE cu.user_id = ? AND cu.role = 'student'
            ORDER BY c.course_code, a.type
        ");
        $stmt->execute([$student_id, $student_id]);
        $assessments = $stmt->fetchAll();

        $result = [];

        foreach ($assessments as $a) {
            $mark = $a['mark_obtained'];
            $max = $a['max_mark'];
            $weight = $a['weight'];
            $weighted = null;

            if ($mark !== null && $max > 0 && $weight !== null) {
                $score = ($mark / $max) * $weight;
                $weighted = round($score, 2);
            }

            $result[] = [
                'course_code' => $a['course_code'],
                'course_name' => $a['course_name'],
                'type' => $a['type'],
                'title' => $a['title'],
                'mark_obtained' => $mark,
                'max_mark' => $max,
                'weight' => $weight,
                'weighted_mark' => $weighted
            ];
        }

        // 2. Get final exams
        $stmt = $pdo->prepare("
            SELECT 
                c.course_code,
                c.course_name,
                fe.final_mark
            FROM final_exams fe
            JOIN courses c ON c.id = fe.course_id
            WHERE fe.student_id = ?
        ");
        $stmt->execute([$student_id]);
        $finals = $stmt->fetchAll();

        foreach ($finals as $f) {
            $result[] = [
                'course_code' => $f['course_code'],
                'course_name' => $f['course_name'],
                'type' => 'final_exam',
                'title' => 'Final Exam',
                'mark_obtained' => $f['final_mark'],
                'max_mark' => 100,
                'weight' => null,
                'weighted_mark' => $f['final_mark'] // assuming it's already final
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/performance-trend', function ($request, $response) use ($pdo) {

        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401)->write('Unauthorized');
        }

        $student_id = $_SESSION['user']['id'];

        // Get assessments with marks for the student
        $stmt = $pdo->prepare("
            SELECT 
                c.course_code,
                c.course_name,
                a.title AS assessment_title,
                a.type,
                a.created_at,
                a.max_mark,
                m.mark_obtained
            FROM marks m
            JOIN assessments a ON a.id = m.assessment_id
            JOIN courses c ON a.course_id = c.id
            WHERE m.student_id = ?
            ORDER BY a.created_at ASC
        ");
        $stmt->execute([$student_id]);
        $rows = $stmt->fetchAll();

        $result = [];

        foreach ($rows as $r) {
            if ($r['mark_obtained'] !== null && $r['max_mark'] > 0) {
                $percent = ($r['mark_obtained'] / $r['max_mark']) * 100;
                $result[] = [
                    'course_code' => $r['course_code'],
                    'course_name' => $r['course_name'],
                    'assessment_title' => $r['assessment_title'],
                    'type' => $r['type'],
                    'created_at' => $r['created_at'],
                    'score_percent' => round($percent, 2)
                ];
            }
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->post('/whatif', function ($request, $response) use ($pdo) {

        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401)->write('Unauthorized');
        }

        $body = $request->getParsedBody();
        $items = $body['assessments'] ?? [];

        $result = [];
        $totalWeighted = 0;

        foreach ($items as $item) {
            $title = $item['title'];
            $max_mark = $item['max_mark'];
            $mark_obtained = $item['mark_obtained'];
            $weight = $item['weight'];

            $weighted_mark = null;
            if ($max_mark > 0 && $mark_obtained !== null && $weight !== null) {
                $weighted_mark = ($mark_obtained / $max_mark) * $weight;
                $totalWeighted += $weighted_mark;
            }

            $result[] = [
                'title' => $title,
                'max_mark' => $max_mark,
                'mark_obtained' => $mark_obtained,
                'weight' => $weight,
                'weighted_mark' => round($weighted_mark, 2)
            ];
        }

        $response->getBody()->write(json_encode([
            'simulated' => $result,
            'total' => round($totalWeighted, 2)
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/rankings', function ($request, $response) use ($pdo) {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401)->write('Unauthorized');
        }

        $student_id = $_SESSION['user']['id'];

        // Get courses the student is enrolled in
        $stmt = $pdo->prepare("
            SELECT c.id, c.course_code, c.course_name
            FROM courses c
            JOIN course_user cu ON cu.course_id = c.id
            WHERE cu.user_id = ? AND cu.role = 'student'
        ");
        $stmt->execute([$student_id]);
        $courses = $stmt->fetchAll();

        $results = [];

        foreach ($courses as $course) {
            $course_id = $course['id'];

            // Get all students' total marks in the course
            $stmt = $pdo->prepare("
                SELECT 
                    u.id as student_id,
                    u.name,
                    COALESCE(SUM(
                        (m.mark_obtained / a.max_mark) * a.weight
                    ), 0) AS total_mark
                FROM users u
                JOIN course_user cu ON cu.user_id = u.id
                JOIN assessments a ON a.course_id = cu.course_id
                LEFT JOIN marks m ON m.assessment_id = a.id AND m.student_id = u.id
                WHERE cu.course_id = ? AND cu.role = 'student'
                GROUP BY u.id
                ORDER BY total_mark DESC
            ");
            $stmt->execute([$course_id]);
            $students = $stmt->fetchAll();

            // Find current student's rank
            $rank = null;
            foreach ($students as $i => $s) {
                if ($s['student_id'] == $student_id) {
                    $rank = $i + 1;
                    break;
                }
            }

            // Class average
            $totalScores = array_column($students, 'total_mark');
            $average = count($totalScores) ? array_sum($totalScores) / count($totalScores) : 0;

            $results[] = [
                'course_code' => $course['course_code'],
                'course_name' => $course['course_name'],
                'your_rank' => $rank,
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

    $group->get('/remarks', function ($request, $response) use ($pdo) {

        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401);
        }

        $student_id = $_SESSION['user']['id'];

        $stmt = $pdo->prepare("
            SELECT 
                an.id,
                an.note,
                an.meeting_date,
                an.created_at,
                u.name AS advisor_name,
                c.course_code,
                c.course_name
            FROM advisor_notes an
            LEFT JOIN users u ON u.id = an.advisor_id
            LEFT JOIN courses c ON c.id = an.course_id
            WHERE an.student_id = ?
            ORDER BY an.created_at DESC
        ");
        $stmt->execute([$student_id]);
        $notes = $stmt->fetchAll();

        $response->getBody()->write(json_encode($notes));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/notifications', function ($request, $response) use ($pdo) {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401);
        }

        $student_id = $_SESSION['user']['id'];

        $stmt = $pdo->prepare("
            SELECT id, title, message, is_read, created_at 
            FROM notifications 
            WHERE user_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$student_id]);
        $notifications = $stmt->fetchAll();

        $response->getBody()->write(json_encode($notifications));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->post('/remark-request', function ($request, $response) use ($pdo) {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401);
        }

        $student_id = $_SESSION['user']['id'];
        $data = json_decode($request->getBody()->getContents(), true);

        $assessment_id = $data['assessment_id'] ?? null;
        $message = trim($data['message'] ?? '');

        if (!$assessment_id || $message === '') {
            $response->getBody()->write(json_encode([
                'error' => 'Assessment and message are required.'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if request already exists
        $check = $pdo->prepare("SELECT id FROM remark_requests WHERE student_id = ? AND assessment_id = ?");
        $check->execute([$student_id, $assessment_id]);

        if ($check->fetch()) {
            $response->getBody()->write(json_encode([
                'error' => 'You have already submitted a request for this assessment.'
            ]));

            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }

        $stmt = $pdo->prepare("INSERT INTO remark_requests (student_id, assessment_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$student_id, $assessment_id, $message]);

        $response->getBody()->write(json_encode(['message' => 'Recheck request submitted.']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/remark-requests', function ($request, $response) use ($pdo) {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401);
        }

        $student_id = $_SESSION['user']['id'];

        $stmt = $pdo->prepare("
            SELECT 
                rr.id,
                rr.message,
                rr.status,
                rr.created_at,
                a.title AS assessment_title,
                a.type AS assessment_type,
                c.course_code,
                c.course_name
            FROM remark_requests rr
            JOIN assessments a ON rr.assessment_id = a.id
            JOIN courses c ON a.course_id = c.id
            WHERE rr.student_id = ?
            ORDER BY rr.created_at DESC
        ");
        $stmt->execute([$student_id]);
        $requests = $stmt->fetchAll();

        $response->getBody()->write(json_encode($requests));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/assessments', function ($request, $response) use ($pdo) {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401);
        }

        $student_id = $_SESSION['user']['id'];

        $stmt = $pdo->prepare("
            SELECT a.id, a.title, a.type, c.course_code
            FROM assessments a
            JOIN courses c ON c.id = a.course_id
            JOIN course_user cu ON cu.course_id = c.id
            WHERE cu.user_id = ? AND cu.role = 'student'
            ORDER BY c.course_code, a.title
        ");
        $stmt->execute([$student_id]);
        $assessments = $stmt->fetchAll();

        $response->getBody()->write(json_encode($assessments));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/profile', function ($request, $response) use ($pdo) {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
            return $response->withStatus(401);
        }

        $student_id = $_SESSION['user']['id'];

        // Basic profile
        $stmt = $pdo->prepare("SELECT name, email, matric_number, role, created_at FROM users WHERE id = ?");
        $stmt->execute([$student_id]);
        $profile = $stmt->fetch();

        if (!$profile) {
            $response->getBody()->write(json_encode(['error' => 'Profile not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Get enrolled courses
        $stmt2 = $pdo->prepare("
        SELECT c.course_code, c.course_name, c.semester, c.year
        FROM courses c
        JOIN course_user cu ON cu.course_id = c.id
        WHERE cu.user_id = ? AND cu.role = 'student'
    ");
        $stmt2->execute([$student_id]);
        $courses = $stmt2->fetchAll();

        // GPA or total final marks (example placeholder)
        $stmt3 = $pdo->prepare("SELECT AVG(final_mark) as avg_mark FROM final_exams WHERE student_id = ?");
        $stmt3->execute([$student_id]);
        $stats = $stmt3->fetch();

        $profile['courses'] = $courses;
        $profile['average_final_mark'] = $stats['avg_mark'];

        $response->getBody()->write(json_encode($profile));
        return $response->withHeader('Content-Type', 'application/json');
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

    // GET all courses
    $group->get('/courses', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query("SELECT * FROM courses ORDER BY created_at DESC");
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($courses));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // POST: Create course with semester & year
    $group->post('/courses', function ($request, $response) use ($pdo) {
        $data = $request->getParsedBody();
        $stmt = $pdo->prepare("INSERT INTO courses (course_code, course_name, semester, year, created_at)
            VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([
            $data['course_code'],
            $data['course_name'],
            $data['semester'],
            $data['year']
        ]);
        return $response->withJson(['success' => true, 'message' => 'Course added.']);
    });

    // PUT: Update course with semester & year
    $group->put('/courses/{id}', function ($request, $response, $args) use ($pdo) {
        $id = $args['id'];
        $data = $request->getParsedBody();
        $stmt = $pdo->prepare("UPDATE courses 
            SET course_code = ?, course_name = ?, semester = ?, year = ? 
            WHERE id = ?");
        $stmt->execute([
            $data['course_code'],
            $data['course_name'],
            $data['semester'],
            $data['year'],
            $id
        ]);
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
        $student_id = $data['student_id'];
        $course_id = $data['course_id'];

        // Insert into course_user
        $stmt = $pdo->prepare("INSERT IGNORE INTO course_user (user_id, course_id, role, created_at) VALUES (?, ?, 'student', NOW())");
        $stmt->execute([$student_id, $course_id]);

        // Insert into final_exams with default mark 0
        $stmt2 = $pdo->prepare("
        INSERT IGNORE INTO final_exams (course_id, student_id, final_mark, created_at, updated_at)
        VALUES (?, ?, 0, NOW(), NOW())
    ");
        $stmt2->execute([$course_id, $student_id]);

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    $group->put('/enroll/{id}', function ($request, $response, $args) use ($pdo) {
        $data = $request->getParsedBody();
        $id = $args['id'];
        $student_id = $data['student_id'];
        $new_course_id = $data['course_id'];

        // Get current enrollment info
        $stmt = $pdo->prepare("SELECT user_id, course_id FROM course_user WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404)
                ->write(json_encode(['success' => false, 'message' => 'Enrollment not found.']));
        }

        $old_course_id = $current['course_id'];

        // Update enrollment
        $stmt = $pdo->prepare("UPDATE course_user SET user_id = ?, course_id = ? WHERE id = ?");
        $stmt->execute([$student_id, $new_course_id, $id]);

        // Update final_exams course_id only if the course changed
        if ($old_course_id != $new_course_id) {
            $stmt = $pdo->prepare("UPDATE final_exams SET course_id = ?, updated_at = NOW() WHERE student_id = ? AND course_id = ?");
            $stmt->execute([$new_course_id, $student_id, $old_course_id]);
        }

        return $response->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['success' => true, 'message' => 'Enrollment and final exam updated.']));
    });


    $group->delete('/enroll/{id}', function ($request, $response, $args) use ($pdo) {
        $enrollmentId = $args['id'];

        // Get course_id and user_id before deleting
        $stmt = $pdo->prepare("SELECT course_id, user_id FROM course_user WHERE id = ?");
        $stmt->execute([$enrollmentId]);
        $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($enrollment) {
            // Delete from final_exams
            $stmtDelFinal = $pdo->prepare("DELETE FROM final_exams WHERE course_id = ? AND student_id = ?");
            $stmtDelFinal->execute([$enrollment['course_id'], $enrollment['user_id']]);

            // Delete from course_user
            $stmtDelCU = $pdo->prepare("DELETE FROM course_user WHERE id = ?");
            $stmtDelCU->execute([$enrollmentId]);
        }

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });


    // Get all enrolled students in courses
    $group->get('/enrolled-students', function ($request, $response) use ($pdo) {
        $sql = "SELECT 
                    cu.id AS enrollment_id,
                    u.id AS student_id,
                    u.name AS student_name,
                    u.matric_number,
                    c.id AS course_id,
                    c.course_code,
                    c.course_name
                FROM course_user cu
                JOIN users u ON u.id = cu.user_id
                JOIN courses c ON c.id = cu.course_id
                WHERE cu.role = 'student'
                ORDER BY c.course_name, u.name";
        $stmt = $pdo->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $payload = json_encode($data);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    });

    //Marks
    $group->get('/marks', function ($request, $response) use ($pdo) {
        $params = $request->getQueryParams();
        $courseId = $params['course_id'] ?? null;
        $studentId = $params['student_id'] ?? null;

        if (!$courseId || !$studentId) {
            $response->getBody()->write(json_encode(['error' => 'Missing course_id or student_id']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $infoStmt = $pdo->prepare("
                SELECT c.course_name, c.course_code, u.name AS student_name, u.matric_number
                FROM courses c
                CROSS JOIN users u
                WHERE c.id = :course_id AND u.id = :student_id
            ");
            $infoStmt->execute(['course_id' => $courseId, 'student_id' => $studentId]);
            $info = $infoStmt->fetch(PDO::FETCH_ASSOC);

            if (!$info) {
                $response->getBody()->write(json_encode(['error' => 'Course or student not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $marksStmt = $pdo->prepare("
                SELECT a.id AS assessment_id, a.title, a.type, a.max_mark, 
                    COALESCE(m.mark_obtained, 0) AS mark_obtained
                FROM assessments a
                LEFT JOIN marks m ON m.assessment_id = a.id AND m.student_id = :student_id
                WHERE a.course_id = :course_id
            ");
            $marksStmt->execute(['course_id' => $courseId, 'student_id' => $studentId]);
            $marks = $marksStmt->fetchAll(PDO::FETCH_ASSOC);

            $payload = [
                'student' => [
                    'id' => $studentId,
                    'name' => $info['student_name'],
                    'matric_number' => $info['matric_number']
                ],
                'course' => [
                    'id' => $courseId,
                    'name' => $info['course_name'],
                    'code' => $info['course_code']
                ],
                'marks' => $marks
            ];

            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });

    //Get all assessments + marks for a student in a course
    $group->get('/marks/{course_id}/{student_id}', function ($request, $response, $args) use ($pdo) {
        $course_id = $args['course_id'];
        $student_id = $args['student_id'];

        // Get assessments and existing marks
        $sql = "SELECT 
                    a.id AS assessment_id,
                    a.title,
                    a.type,
                    a.max_mark,
                    a.weight,
                    COALESCE(m.mark_obtained, 0) AS mark_obtained
                FROM assessments a
                LEFT JOIN marks m 
                    ON m.assessment_id = a.id AND m.student_id = :student_id
                WHERE a.course_id = :course_id
                ORDER BY a.type, a.title";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['course_id' => $course_id, 'student_id' => $student_id]);
        $assessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($assessments));
        return $response->withHeader('Content-Type', 'application/json');
    });

    //Save mark (upsert)
    $group->post('/marks', function ($request, $response) use ($pdo) {
        $data = $request->getParsedBody();
        $assessment_id = $data['assessment_id'];
        $student_id = $data['student_id'];
        $mark_obtained = $data['mark_obtained'];

        // Check if record exists
        $stmt = $pdo->prepare("SELECT id FROM marks WHERE assessment_id = ? AND student_id = ?");
        $stmt->execute([$assessment_id, $student_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update
            $stmt = $pdo->prepare("UPDATE marks SET mark_obtained = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$mark_obtained, $existing['id']]);
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO marks (assessment_id, student_id, mark_obtained, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
            $stmt->execute([$assessment_id, $student_id, $mark_obtained]);
        }

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ===============================
    // GET all assessments, optionally by course
    $group->get('/assessments[/{course_id}]', function ($request, $response, $args) use ($pdo) {
        $course_id = $args['course_id'] ?? null;

        if ($course_id) {
            $stmt = $pdo->prepare("SELECT a.*, c.course_name FROM assessments a
                JOIN courses c ON c.id = a.course_id
                WHERE a.course_id = ?");
            $stmt->execute([$course_id]);
        } else {
            $stmt = $pdo->query("SELECT a.*, c.course_name FROM assessments a
                JOIN courses c ON c.id = a.course_id");
        }
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ===============================
    // POST: Create assessment with weight check (max 70%)
    $group->post('/assessments', function ($request, $response) use ($pdo) {
        $data = $request->getParsedBody();
        $course_id = $data['course_id'];

        $stmt = $pdo->prepare("SELECT SUM(weight) as total_weight FROM assessments WHERE course_id = ?");
        $stmt->execute([$course_id]);
        $currentWeight = $stmt->fetchColumn() ?: 0;

        if ($currentWeight + $data['weight'] > 70) {
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(400)
                ->write(json_encode(['success' => false, 'message' => 'Total assessment weight exceeds 70%.']));
        }

        $stmt = $pdo->prepare("INSERT INTO assessments 
            (course_id, title, type, max_mark, weight, created_by, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([
            $course_id,
            $data['title'],
            $data['type'],
            $data['max_mark'],
            $data['weight'],
            $data['created_by']
        ]);

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ===============================
    // PUT: Update assessment with weight check (max 70%)
    $group->put('/assessments/{id}', function ($request, $response, $args) use ($pdo) {
        $id = $args['id'];
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("SELECT SUM(weight) as total_weight FROM assessments WHERE course_id = ? AND id != ?");
        $stmt->execute([$data['course_id'], $id]);
        $currentWeight = $stmt->fetchColumn() ?: 0;

        if ($currentWeight + $data['weight'] > 70) {
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(400)
                ->write(json_encode(['success' => false, 'message' => 'Total assessment weight exceeds 70%.']));
        }

        $stmt = $pdo->prepare("UPDATE assessments SET
            course_id = ?, title = ?, type = ?, max_mark = ?, weight = ?, updated_at = NOW()
            WHERE id = ?");
        $stmt->execute([
            $data['course_id'],
            $data['title'],
            $data['type'],
            $data['max_mark'],
            $data['weight'],
            $id
        ]);

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ===============================
    // DELETE: Remove assessment
    $group->delete('/assessments/{id}', function ($request, $response, $args) use ($pdo) {
        $stmt = $pdo->prepare("DELETE FROM assessments WHERE id = ?");
        $stmt->execute([$args['id']]);
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ===============================
    //Get enrolled students + assessments + marks
    $group->get('/course-assignment/{course_id}', function ($request, $response, $args) use ($pdo) {
        $course_id = $args['course_id'];

        // Get students enrolled in this course
        $stmt = $pdo->prepare("
            SELECT users.id AS student_id, users.name, users.matric_number
            FROM course_user
            JOIN users ON users.id = course_user.user_id
            WHERE course_user.course_id = ? AND course_user.role = 'student'
        ");
        $stmt->execute([$course_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get assessments for this course
        $stmt = $pdo->prepare("SELECT id AS assessment_id, title, type FROM assessments WHERE course_id = ?");
        $stmt->execute([$course_id]);
        $assessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get all existing marks for these students & assessments
        $student_ids = array_column($students, 'student_id');
        $assessment_ids = array_column($assessments, 'assessment_id');

        $marks = [];
        if (!empty($student_ids) && !empty($assessment_ids)) {
            $placeholders_students = implode(',', array_fill(0, count($student_ids), '?'));
            $placeholders_assessments = implode(',', array_fill(0, count($assessment_ids), '?'));

            $stmt = $pdo->prepare("
                SELECT assessment_id, student_id
                FROM marks
                WHERE student_id IN ($placeholders_students)
                AND assessment_id IN ($placeholders_assessments)
            ");
            $stmt->execute(array_merge($student_ids, $assessment_ids));
            $marks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Re-map marks for quick lookup
        $marks_lookup = [];
        foreach ($marks as $m) {
            $marks_lookup[$m['student_id']][$m['assessment_id']] = true;
        }

        // Build final response structure
        foreach ($students as &$student) {
            $student['assessments'] = [];
            foreach ($assessments as $ass) {
                $assigned = isset($marks_lookup[$student['student_id']][$ass['assessment_id']]);
                $student['assessments'][] = [
                    'assessment_id' => $ass['assessment_id'],
                    'title' => $ass['title'],
                    'type' => $ass['type'],
                    'assigned' => $assigned
                ];
            }
        }

        $response->getBody()->write(json_encode($students));
        return $response->withHeader('Content-Type', 'application/json');
    });

    //POST: Assign a student to an assessment
    $group->post('/assign-mark', function ($request, $response) use ($pdo) {
        $data = $request->getParsedBody();
        $assessment_id = $data['assessment_id'];
        $student_id = $data['student_id'];

        // Insert only if not already assigned
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO marks (assessment_id, student_id, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
        ");
        $stmt->execute([$assessment_id, $student_id]);

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    //DELETE: Unassign student from assessment
    $group->delete('/assign-mark/{assessment_id}/{student_id}', function ($request, $response, $args) use ($pdo) {
        $assessment_id = $args['assessment_id'];
        $student_id = $args['student_id'];

        $stmt = $pdo->prepare("DELETE FROM marks WHERE assessment_id = ? AND student_id = ?");
        $stmt->execute([$assessment_id, $student_id]);

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // =====================
    // FINAL EXAMS ROUTES
    // =====================

    $group->get('/final-exams', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query("
                SELECT u.id AS student_id, u.name, u.matric_number,
                    c.id AS course_id, c.course_code, c.course_name,
                    fe.final_mark
                FROM final_exams fe
                JOIN users u ON fe.student_id = u.id
                JOIN courses c ON fe.course_id = c.id
                ORDER BY c.course_code, u.name
            ");
        $students = $stmt->fetchAll();
        $response->getBody()->write(json_encode($students));
        return $response->withHeader('Content-Type', 'application/json');
    });


    // ✅ Get a single student's final exam record (name, matric, course, final_mark, and assessments)
    $group->get('/final-exams/{course_id}/{student_id}', function ($request, $response, $args) use ($pdo) {
        $course_id = $args['course_id'];
        $student_id = $args['student_id'];

        // Get course, student and final exam mark
        $stmt = $pdo->prepare("
            SELECT u.name, u.matric_number, c.course_name, fe.final_mark
            FROM users u
            CROSS JOIN courses c
            LEFT JOIN final_exams fe ON fe.student_id = u.id AND fe.course_id = c.id
            WHERE u.id = ? AND c.id = ?
        ");
        $stmt->execute([$student_id, $course_id]);
        $data = $stmt->fetch();

        // Get assessments for the course
        $stmt2 = $pdo->prepare("
            SELECT id AS assessment_id, title, type, max_mark, weight
            FROM assessments
            WHERE course_id = ?
        ");
        $stmt2->execute([$course_id]);
        $assessments = $stmt2->fetchAll();

        $response->getBody()->write(json_encode([
            'course_name' => $data['course_name'] ?? '',
            'name' => $data['name'] ?? '',
            'matric_number' => $data['matric_number'] ?? '',
            'final_mark' => $data['final_mark'] ?? 0,
            'assessments' => $assessments
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ✅ Save or update final mark
    $group->post('/final-exams', function ($request, $response) use ($pdo) {
        $data = $request->getParsedBody();
        $course_id = $data['course_id'];
        $student_id = $data['student_id'];
        $final_mark = $data['final_mark'];
        $final_exam_weight = $data['final_exam_weight']; // e.g. 30

        // Get current total assessment weights
        $stmt = $pdo->prepare("SELECT SUM(weight) as total_weight FROM assessments WHERE course_id = ?");
        $stmt->execute([$course_id]);
        $totalAssessmentWeight = $stmt->fetchColumn() ?: 0;

        if ($totalAssessmentWeight + $final_exam_weight > 100) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Combined weight exceeds 100%. Current assessments weight: ' . $totalAssessmentWeight . '%.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Otherwise upsert as usual
        $stmt = $pdo->prepare("SELECT id FROM final_exams WHERE course_id = ? AND student_id = ?");
        $stmt->execute([$course_id, $student_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE final_exams SET final_mark = ?, updated_at = NOW() WHERE course_id = ? AND student_id = ?");
            $stmt->execute([$final_mark, $course_id, $student_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO final_exams (course_id, student_id, final_mark, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())");
            $stmt->execute([$course_id, $student_id, $final_mark]);
        }

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ✅ Delete final mark
    $group->delete('/final-exams/{course_id}/{student_id}', function ($request, $response, $args) use ($pdo) {
        $course_id = $args['course_id'];
        $student_id = $args['student_id'];

        $stmt = $pdo->prepare("DELETE FROM final_exams WHERE course_id = ? AND student_id = ?");
        $stmt->execute([$course_id, $student_id]);

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // =====================
    // REMARK AND FEEDBACK ROUTES
    // =====================

    //RemarksList
    $group->get('/students-remarks', function ($request, $response) use ($pdo) {
        $stmt = $pdo->query("
        SELECT 
            u.id AS student_id,
            u.name AS student_name,
            u.matric_number,
            c.id AS course_id,
            c.course_code,
            c.course_name,
            fe.final_mark
        FROM final_exams fe
        JOIN users u ON fe.student_id = u.id
        JOIN courses c ON fe.course_id = c.id
        ORDER BY c.course_code, u.name
    ");
        $students = $stmt->fetchAll();
        $response->getBody()->write(json_encode($students));
        return $response->withHeader('Content-Type', 'application/json');
    });



    //RemarkEntry
    $group->get('/remark-requests/{course_id}/{student_id}', function ($request, $response, $args) use ($pdo) {
        $course_id = $args['course_id'];
        $student_id = $args['student_id'];

        // Try to get the remark
        $stmt = $pdo->prepare("
            SELECT rr.id, rr.assessment_id, rr.message, rr.status
            FROM remark_requests rr
            JOIN assessments a ON rr.assessment_id = a.id
            WHERE rr.student_id = ? AND a.course_id = ?
            LIMIT 1
        ");
        $stmt->execute([$student_id, $course_id]);
        $remark = $stmt->fetch();

        $response->getBody()->write(json_encode($remark ?: []));
        return $response->withHeader('Content-Type', 'application/json');
    });

    //POST insert/update remark
    $group->post('/remark-requests/{course_id}/{student_id}', function ($request, $response, $args) use ($pdo) {
        $course_id = $args['course_id'];
        $student_id = $args['student_id'];
        $data = json_decode($request->getBody()->getContents(), true);

        $assessment_id = $data['assessment_id'];
        $message = $data['message'];
        $status = $data['status'];

        // Check if already exists
        $stmt = $pdo->prepare("SELECT id FROM remark_requests WHERE student_id = ? AND assessment_id = ?");
        $stmt->execute([$student_id, $assessment_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE remark_requests SET message = ?, status = ? WHERE id = ?");
            $stmt->execute([$message, $status, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO remark_requests (student_id, assessment_id, message, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$student_id, $assessment_id, $message, $status]);
        }

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    });






    $group->get('/progress', function ($request, $response) {

        return $response;
    });
})->add($authMiddleware);



/* ADMIN ROUTES */
$app->group('/api/admin',  function (RouteCollectorProxy $group) use ($pdo) {

    $group->get('/users', function ($request, $response) use ($pdo) {
        try {
            $stmt = $pdo->query("SELECT id, name, email, matric_number,role, created_at, updated_at FROM users");
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

    $group->put('/users/{id}/details', function ($request, $response, $args) use ($pdo) {
        $id = $args['id'];
        $data = $request->getParsedBody();

        $stmt = $pdo->prepare("UPDATE users SET name = :name, email = :email, matric_number = :matric WHERE id = :id");
        $stmt->execute([
            ':name' => $data['name'],
            ':email' => $data['email'],
            ':matric' => $data['matric'],
            ':id' => $id,
        ]);

        $response->getBody()->write(json_encode(['message' => 'User details updated']));
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
