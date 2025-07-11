<?php

use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;

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
                fe.final_mark, fe.gpa
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

        // Get course, student and final exam mark + GPA
        $stmt = $pdo->prepare("
            SELECT u.name, u.matric_number, c.course_name, fe.final_mark, fe.gpa
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
            'gpa' => $data['gpa'] ?? 0,
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
        $final_exam_weight = $data['final_exam_weight'] ?? 30; // fallback if not passed

        // Calculate GPA based on final_mark
        if ($final_mark >= 85) $gpa = 4.0;
        elseif ($final_mark >= 75) $gpa = 3.5;
        elseif ($final_mark >= 65) $gpa = 3.0;
        elseif ($final_mark >= 55) $gpa = 2.5;
        elseif ($final_mark >= 45) $gpa = 2.0;
        elseif ($final_mark >= 35) $gpa = 1.5;
        else $gpa = 1.0;

        // Check total weight
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

        // Upsert final_exams with gpa
        $stmt = $pdo->prepare("SELECT id FROM final_exams WHERE course_id = ? AND student_id = ?");
        $stmt->execute([$course_id, $student_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE final_exams 
                SET final_mark = ?, gpa = ?, updated_at = NOW() 
                WHERE course_id = ? AND student_id = ?");
            $stmt->execute([$final_mark, $gpa, $course_id, $student_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO final_exams 
                (course_id, student_id, final_mark, gpa, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$course_id, $student_id, $final_mark, $gpa]);
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
            fe.final_mark,
            fe.gpa
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

    //progress overview
    $group->get('/progress', function ($request, $response) use ($pdo) {
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
            COALESCE(SUM(DISTINCT a.weight), 0) + 
                CASE WHEN fe.final_mark IS NOT NULL THEN 30 ELSE 0 END AS total_weight_completed
        FROM course_user cu
        JOIN users u ON cu.user_id = u.id
        JOIN courses c ON cu.course_id = c.id
        LEFT JOIN assessments a ON a.course_id = c.id
        LEFT JOIN marks m ON m.assessment_id = a.id AND m.student_id = u.id
        LEFT JOIN final_exams fe ON fe.course_id = c.id AND fe.student_id = u.id
        LEFT JOIN remark_requests rr ON rr.assessment_id = a.id AND rr.student_id = u.id
        WHERE cu.role = 'student'
        GROUP BY u.id, u.name, u.matric_number, c.id, c.course_name, fe.final_mark, fe.gpa
        ORDER BY c.course_name, u.name
    ";


        $stmt = $pdo->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    });


//analytics
// $group->get('/analyticss', function ($request, $response) use ($pdo) {
//     // Get global totals and averages
//     $sql = "
//         SELECT 
//             COUNT(DISTINCT cu.user_id) AS total_students,
//             COUNT(DISTINCT a.id) AS total_assessments,
//             COUNT(DISTINCT rr.id) AS total_remarks,
//             AVG(total_weight) AS avg_weight_completed,
//             AVG(fe.final_mark) AS avg_final_mark
//         FROM course_user cu
//         JOIN courses c ON cu.course_id = c.id
//         LEFT JOIN assessments a ON a.course_id = c.id
//         LEFT JOIN remark_requests rr ON rr.assessment_id = a.id
//         LEFT JOIN final_exams fe ON fe.course_id = c.id AND fe.student_id = cu.user_id
//         LEFT JOIN (
//             SELECT 
//                 u.id AS student_id,
//                 c.id AS course_id,
//                 COALESCE(SUM(DISTINCT a.weight), 0) + 
//                     CASE WHEN fe.final_mark IS NOT NULL THEN 30 ELSE 0 END AS total_weight
//             FROM course_user cu
//             JOIN users u ON cu.user_id = u.id
//             JOIN courses c ON cu.course_id = c.id
//             LEFT JOIN assessments a ON a.course_id = c.id
//             LEFT JOIN final_exams fe ON fe.course_id = c.id AND fe.student_id = u.id
//             WHERE cu.role = 'student'
//             GROUP BY u.id, c.id, fe.final_mark
//         ) AS subquery ON subquery.student_id = cu.user_id AND subquery.course_id = c.id
//         WHERE cu.role = 'student'
//     ";

//     $stmt = $pdo->query($sql);
//     $summary = $stmt->fetch(PDO::FETCH_ASSOC);

//     // Also prepare a dataset by course for charting
//     $chartSql = "
//         SELECT 
//             c.course_name,
//             COUNT(DISTINCT cu.user_id) AS student_count,
//             AVG(COALESCE(fe.final_mark,0)) AS avg_final_mark,
//             AVG(COALESCE(total_weight,0)) AS avg_total_weight_completed
//         FROM course_user cu
//         JOIN courses c ON cu.course_id = c.id
//         LEFT JOIN (
//             SELECT 
//                 u.id AS student_id,
//                 c.id AS course_id,
//                 COALESCE(SUM(DISTINCT a.weight), 0) + 
//                     CASE WHEN fe.final_mark IS NOT NULL THEN 30 ELSE 0 END AS total_weight
//             FROM course_user cu
//             JOIN users u ON cu.user_id = u.id
//             JOIN courses c ON cu.course_id = c.id
//             LEFT JOIN assessments a ON a.course_id = c.id
//             LEFT JOIN final_exams fe ON fe.course_id = c.id AND fe.student_id = u.id
//             WHERE cu.role = 'student'
//             GROUP BY u.id, c.id, fe.final_mark
//         ) AS subquery ON subquery.student_id = cu.user_id AND subquery.course_id = c.id
//         LEFT JOIN final_exams fe ON fe.course_id = c.id AND fe.student_id = cu.user_id
//         WHERE cu.role = 'student'
//         GROUP BY c.course_name
//         ORDER BY c.course_name
//     ";

//     $chartStmt = $pdo->query($chartSql);
//     $chartData = $chartStmt->fetchAll(PDO::FETCH_ASSOC);

//     $result = [
//         'summary' => $summary,
//         'chartData' => $chartData
//     ];

//     $response->getBody()->write(json_encode($result));
//     return $response->withHeader('Content-Type', 'application/json');
// });

// $app->get('/api/lecturer/analytics', function ($request, $response, $args) use ($db) {
//     // === TOTALS
//     $totalStudents = $db->query("
//         SELECT COUNT(DISTINCT cu.user_id) AS count
//         FROM course_user cu
//         JOIN users u ON u.id = cu.user_id
//         WHERE cu.role = 'student' AND u.matric_number IS NOT NULL
//     ")->fetch()['count'];

//     $totalAssessments = $db->query("SELECT COUNT(*) AS count FROM assessments")->fetch()['count'];
//     $totalRemarks = $db->query("SELECT COUNT(*) AS count FROM remark_requests")->fetch()['count'];

//     // === PIE: students by course
//     $pieData = $db->query("
//         SELECT c.course_name, COUNT(cu.user_id) AS student_count
//         FROM course_user cu
//         JOIN courses c ON c.id = cu.course_id
//         WHERE cu.role = 'student'
//         GROUP BY c.course_name
//     ")->fetchAll();

//     // === BAR: avg completion by course
//     $barData = $db->query("
//         SELECT c.course_name, 
//             COALESCE(AVG((sub.completed_weight + COALESCE(fe.final_weight,0))),0) AS avg_completion
//         FROM courses c
//         LEFT JOIN (
//             SELECT a.course_id, am.student_id,
//                 SUM((a.weight / 100) * (am.mark_obtained / a.max_mark * 100)) AS completed_weight
//             FROM assessments a
//             JOIN assessment_marks am ON am.assessment_id = a.id
//             GROUP BY a.course_id, am.student_id
//         ) sub ON sub.course_id = c.id
//         LEFT JOIN (
//             SELECT f.course_id, f.student_id, (0.3 * f.final_mark) AS final_weight
//             FROM final_exams f
//         ) fe ON fe.course_id = c.id AND fe.student_id = sub.student_id
//         GROUP BY c.course_name
//     ")->fetchAll();

//     // === LINE: avg final marks by course
//     $lineData = $db->query("
//         SELECT c.course_name, COALESCE(AVG(f.final_mark),0) AS avg_final_mark
//         FROM courses c
//         LEFT JOIN final_exams f ON f.course_id = c.id
//         GROUP BY c.course_name
//     ")->fetchAll();

//     // === TOP STUDENTS by completion
//     $topStudents = $db->query("
//         SELECT u.name AS student_name, u.matric_number,
//             COALESCE(SUM((a.weight / 100) * (am.mark_obtained / a.max_mark * 100)),0) AS assessment_weight,
//             COALESCE(MAX(0.3 * f.final_mark),0) AS final_weight,
//             COALESCE(SUM((a.weight / 100) * (am.mark_obtained / a.max_mark * 100)),0) + COALESCE(MAX(0.3 * f.final_mark),0) AS total_completion
//         FROM users u
//         LEFT JOIN course_user cu ON cu.user_id = u.id
//         LEFT JOIN assessments a ON a.course_id = cu.course_id
//         LEFT JOIN assessment_marks am ON am.assessment_id = a.id AND am.student_id = u.id
//         LEFT JOIN final_exams f ON f.student_id = u.id AND f.course_id = cu.course_id
//         WHERE cu.role = 'student'
//         GROUP BY u.id, u.name, u.matric_number
//         ORDER BY total_completion DESC
//         LIMIT 5
//     ")->fetchAll();

//     // === DOUGHNUT: completion brackets
//     $bracketCounts = [
//         '0-25%' => 0,
//         '26-50%' => 0,
//         '51-75%' => 0,
//         '76-100%' => 0
//     ];

//     $allStudents = $db->query("
//         SELECT u.id AS student_id,
//             COALESCE(SUM((a.weight / 100) * (am.mark_obtained / a.max_mark * 100)),0) AS assessment_weight,
//             COALESCE(MAX(0.3 * f.final_mark),0) AS final_weight
//         FROM users u
//         LEFT JOIN course_user cu ON cu.user_id = u.id
//         LEFT JOIN assessments a ON a.course_id = cu.course_id
//         LEFT JOIN assessment_marks am ON am.assessment_id = a.id AND am.student_id = u.id
//         LEFT JOIN final_exams f ON f.student_id = u.id AND f.course_id = cu.course_id
//         WHERE cu.role = 'student'
//         GROUP BY u.id
//     ")->fetchAll();

//     foreach ($allStudents as $s) {
//         $total = ($s['assessment_weight'] ?? 0) + ($s['final_weight'] ?? 0);
//         if ($total <= 25) $bracketCounts['0-25%']++;
//         else if ($total <= 50) $bracketCounts['26-50%']++;
//         else if ($total <= 75) $bracketCounts['51-75%']++;
//         else $bracketCounts['76-100%']++;
//     }

//     // === SUMMARY AVG
//     $totalCompletionSum = 0;
//     foreach ($allStudents as $s) {
//         $totalCompletionSum += ($s['assessment_weight'] ?? 0) + ($s['final_weight'] ?? 0);
//     }
//     $avgCompletion = count($allStudents) ? ($totalCompletionSum / count($allStudents)) : 0;

//     $avgFinalMark = $db->query("SELECT COALESCE(AVG(final_mark),0) AS avg FROM final_exams")->fetch()['avg'];

//     // === RESPONSE JSON
//     $response->getBody()->write(json_encode([
//         "summary" => [
//             "total_students" => intval($totalStudents),
//             "total_assessments" => intval($totalAssessments),
//             "total_remarks" => intval($totalRemarks),
//             "avg_completion" => round($avgCompletion,1),
//             "avg_final_mark" => round($avgFinalMark,1)
//         ],
//         "pieData" => $pieData,
//         "barData" => $barData,
//         "lineData" => $lineData,
//         "topStudents" => $topStudents,
//         "doughnutData" => $bracketCounts
//     ]));

//     return $response->withHeader('Content-Type', 'application/json');
// });

    // Your new analytics route with double 's'
    $group->get('/analyticss', function ($request, $response) use ($pdo) {
    // === TOTALS
    $totalStudents = $pdo->query("
        SELECT COUNT(DISTINCT cu.user_id) AS count
        FROM course_user cu
        JOIN users u ON u.id = cu.user_id
        WHERE cu.role = 'student' AND u.matric_number IS NOT NULL
    ")->fetch()['count'];

    $totalAssessments = $pdo->query("SELECT COUNT(*) AS count FROM assessments")->fetch()['count'];
    $totalRemarks = $pdo->query("SELECT COUNT(*) AS count FROM remark_requests")->fetch()['count'];

    // === PIE: students by course
    $pieDataRaw = $pdo->query("
        SELECT c.course_name, COUNT(cu.user_id) AS student_count
        FROM course_user cu
        JOIN courses c ON c.id = cu.course_id
        WHERE cu.role = 'student'
        GROUP BY c.course_name
    ")->fetchAll();

    $pieData = array_map(function($p) {
        return [
            "course_name" => $p["course_name"],
            "student_count" => intval($p["student_count"])
        ];
    }, $pieDataRaw);

    // === BAR: avg completion by course
    $barDataRaw = $pdo->query("
        SELECT c.course_name, 
            COALESCE(AVG((sub.completed_weight + COALESCE(fe.final_weight,0))),0) AS avg_completion
        FROM courses c
        LEFT JOIN (
            SELECT a.course_id, am.student_id,
                SUM((a.weight / 100) * (am.mark_obtained / a.max_mark * 100)) AS completed_weight
            FROM assessments a
            JOIN marks am ON am.assessment_id = a.id
            GROUP BY a.course_id, am.student_id
        ) sub ON sub.course_id = c.id
        LEFT JOIN (
            SELECT f.course_id, f.student_id, (0.3 * f.final_mark) AS final_weight
            FROM final_exams f
        ) fe ON fe.course_id = c.id AND fe.student_id = sub.student_id
        GROUP BY c.course_name
    ")->fetchAll();

    $barData = array_map(function($b) {
        return [
            "course_name" => $b["course_name"],
            "avg_completion" => floatval($b["avg_completion"])
        ];
    }, $barDataRaw);

    // === LINE: avg final marks by course
    $lineDataRaw = $pdo->query("
        SELECT c.course_name, COALESCE(AVG(f.final_mark),0) AS avg_final_mark
        FROM courses c
        LEFT JOIN final_exams f ON f.course_id = c.id
        GROUP BY c.course_name
    ")->fetchAll();

    $lineData = array_map(function($l) {
        return [
            "course_name" => $l["course_name"],
            "avg_final_mark" => floatval($l["avg_final_mark"])
        ];
    }, $lineDataRaw);

    // === GPA: avg GPA by course
    $gpaDataRaw = $pdo->query("
        SELECT c.course_name, COALESCE(AVG(f.gpa),0) AS avg_gpa
        FROM courses c
        LEFT JOIN final_exams f ON f.course_id = c.id
        GROUP BY c.course_name
    ")->fetchAll();

    $gpaData = array_map(function($g) {
        return [
            "course_name" => $g["course_name"],
            "avg_gpa" => floatval($g["avg_gpa"])
        ];
    }, $gpaDataRaw);

    // === TOP STUDENTS
    $topStudentsRaw = $pdo->query("
        SELECT u.name AS student_name, u.matric_number,
            COALESCE(SUM((a.weight / 100) * (am.mark_obtained / a.max_mark * 100)),0) AS assessment_weight,
            COALESCE(MAX(0.3 * f.final_mark),0) AS final_weight,
            COALESCE(SUM((a.weight / 100) * (am.mark_obtained / a.max_mark * 100)),0) + COALESCE(MAX(0.3 * f.final_mark),0) AS total_completion,
            COALESCE(MAX(f.gpa), 0) AS gpa
        FROM users u
        LEFT JOIN course_user cu ON cu.user_id = u.id
        LEFT JOIN assessments a ON a.course_id = cu.course_id
        LEFT JOIN marks am ON am.assessment_id = a.id AND am.student_id = u.id
        LEFT JOIN final_exams f ON f.student_id = u.id AND f.course_id = cu.course_id
        WHERE cu.role = 'student'
        GROUP BY u.id, u.name, u.matric_number
        ORDER BY total_completion DESC
        LIMIT 5
    ")->fetchAll();

   $topStudents = array_map(function($s) {
    return [
        "student_name" => $s["student_name"],
        "matric_number" => $s["matric_number"],
        "assessment_weight" => floatval($s["assessment_weight"]),
        "final_weight" => floatval($s["final_weight"]),
        "total_completion" => floatval($s["total_completion"]),
        "gpa" => floatval($s["gpa"])
    ];
}, $topStudentsRaw);

    // === DOUGHNUT: brackets
    $bracketCounts = [
        '0-25%' => 0,
        '26-50%' => 0,
        '51-75%' => 0,
        '76-100%' => 0
    ];

    $allStudents = $pdo->query("
        SELECT u.id AS student_id,
            COALESCE(SUM((a.weight / 100) * (am.mark_obtained / a.max_mark * 100)),0) AS assessment_weight,
            COALESCE(MAX(0.3 * f.final_mark),0) AS final_weight
        FROM users u
        LEFT JOIN course_user cu ON cu.user_id = u.id
        LEFT JOIN assessments a ON a.course_id = cu.course_id
        LEFT JOIN marks am ON am.assessment_id = a.id AND am.student_id = u.id
        LEFT JOIN final_exams f ON f.student_id = u.id AND f.course_id = cu.course_id
        WHERE cu.role = 'student'
        GROUP BY u.id
    ")->fetchAll();

    $totalCompletionSum = 0;
    foreach ($allStudents as $s) {
        $total = ($s['assessment_weight'] ?? 0) + ($s['final_weight'] ?? 0);
        $totalCompletionSum += $total;
        if ($total <= 25) $bracketCounts['0-25%']++;
        else if ($total <= 50) $bracketCounts['26-50%']++;
        else if ($total <= 75) $bracketCounts['51-75%']++;
        else $bracketCounts['76-100%']++;
    }

    $avgCompletion = count($allStudents) ? ($totalCompletionSum / count($allStudents)) : 0;
    $avgFinalMark = $pdo->query("SELECT COALESCE(AVG(final_mark),0) AS avg FROM final_exams")->fetch()['avg'];
    $avgGpa = $pdo->query("SELECT COALESCE(AVG(gpa),0) AS avg FROM final_exams")->fetch()['avg'];

    $response->getBody()->write(json_encode([
        "summary" => [
            "total_students" => intval($totalStudents),
            "total_assessments" => intval($totalAssessments),
            "total_remarks" => intval($totalRemarks),
            "avg_completion" => round($avgCompletion,1),
            "avg_final_mark" => round($avgFinalMark,1),
            "avg_gpa" => round($avgGpa,2),
        ],
        "pieData" => $pieData,
        "barData" => $barData,
        "lineData" => $lineData,
        "gpaData" => $gpaData,
        "topStudents" => $topStudents,
        "doughnutData" => $bracketCounts
    ]));

    return $response->withHeader('Content-Type', 'application/json');
});

    // $group->get('/progress', function ($request, $response) {

    //     return $response;
    // });
})->add($authMiddleware);