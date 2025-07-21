<?php

use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;


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


    $group->get('/progress', function ($request, $response) use ($pdo) {
        $student_id = $_SESSION['user']['id'];

        $sql = "
        SELECT 
            u.id AS student_id,
            u.name AS student_name,
            u.matric_number,
            c.id AS course_id,
            c.course_name,
            COUNT(DISTINCT a.id) AS assessment_count,
            COUNT(DISTINCT m.id) AS marks_count,
            COALESCE(SUM(m.mark_obtained), 0) AS total_marks,
            fe.final_mark,
            fe.gpa,
            COUNT(DISTINCT rr.id) AS remark_count,
            -- Total progress calculation
            (
              COALESCE(SUM(CASE WHEN m.id IS NOT NULL THEN a.weight ELSE 0 END), 0)
              + CASE WHEN fe.final_mark IS NOT NULL THEN 30 ELSE 0 END
            ) AS total_weight_completed
        FROM course_user cu
        JOIN users u ON cu.user_id = u.id
        JOIN courses c ON cu.course_id = c.id
        LEFT JOIN assessments a ON a.course_id = c.id
        LEFT JOIN marks m ON m.assessment_id = a.id AND m.student_id = u.id
        LEFT JOIN final_exams fe ON fe.course_id = c.id AND fe.student_id = u.id
        LEFT JOIN remark_requests rr ON rr.assessment_id = a.id AND rr.student_id = u.id
        WHERE u.id = :student_id
        GROUP BY u.id, u.name, u.matric_number, c.id, c.course_name, fe.final_mark, fe.gpa
        ORDER BY c.course_name, u.name
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['student_id' => $student_id]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    });
})->add($authMiddleware);
