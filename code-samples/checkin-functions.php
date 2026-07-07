<?php

/**
 * Retrieves users and their checkin status filtered by location and room.
 *
 * @param string $locationId The location ID to filter by, or 'all' for any location.
 * @param string $roomName The room name to filter by, or 'all' for any room.
 * @return array The filtered list of users with checkin status.
 */
function getFilteredUsers($locationId, $roomName) {
    include __DIR__ . '/../../core/sql/sqlconnect.php';

    $query = "SELECT
            u.id,
            CONCAT(LEFT(u.name, 1), '. ', u.surname) AS full_name,
            u.email,
            l.name AS location,
            COUNT(ch.id) > 0 AS checked,
            r.name AS room
        FROM 
            users u
        LEFT JOIN 
            checks ch ON u.id = ch.user_id AND ch.timeout IS NULL
        LEFT JOIN
            user_courses uc ON u.id = uc.user_id AND uc.end_date IS NULL
        LEFT JOIN
            locations l ON l.id = u.location_id
        LEFT JOIN
            course_classrooms coc ON coc.course_id = u.course_id
        INNER JOIN
            rooms r ON r.id = coc.classroom_id AND r.location_id = l.id
        WHERE u.rea_enabled = 1 AND l.id LIKE ? AND r.name LIKE ?
        GROUP BY u.id;";

    //Done like this cuz duplicate Talenten Expeditie
    $locationPattern = ($locationId === "" || $locationId === "all") ? "%" : $locationId;
    $roomPattern = ($roomName === "" || $roomName === "all") ? "%" : $roomName;

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $locationPattern, $roomPattern);
    $stmt->execute();

    $result = $stmt->get_result();
    $stmt->close();
    $people = [];

    while ($person = $result->fetch_assoc()) {
        $person['checked'] = (bool) $person['checked'];
        array_push($people, $person);
    }

    $conn->close();

    // Find duplicates by full_name
    $nameCount = [];
    foreach ($people as $person) {
        $name = $person['full_name'];
        if (!isset($nameCount[$name])) {
            $nameCount[$name] = 0;
        }
        $nameCount[$name]++;
    }

    // Add number from email to name for duplicates
    foreach ($people as &$person) {
        if ($nameCount[$person['full_name']] > 1 && isset($person['email'])) {
            // Extract number from email (e.g., "john.doe2@domain.com" -> "2")
            if (preg_match('/(\d+)@/', $person['email'], $matches)) {
                $person['full_name'] .= ' (' . $matches[1] . ')';
            }
        }
        // Remove email from result to prevent data leakage
        unset($person['email']);
    }
    unset($person); // Break reference

    return $people;
}

/**
 * Retrieves a single user by ID with their checkin status.
 *
 * @param int $userId The user ID to fetch.
 * @return array|null The user data or null if not found.
 */
function getSingleUser($userId) {
    include __DIR__ . '/../../core/sql/sqlconnect.php';

    $query = "SELECT
            u.id,
            CONCAT(LEFT(u.name, 1), '. ', u.surname) AS full_name,
            l.name AS location,
            COUNT(ch.id) > 0 AS checked,
            r.name AS room
        FROM 
            users u
        LEFT JOIN 
            checks ch ON u.id = ch.user_id AND ch.timeout IS NULL
        LEFT JOIN
            user_courses uc ON u.id = uc.user_id AND uc.end_date IS NULL
        LEFT JOIN
            locations l ON l.id = u.location_id
        LEFT JOIN
            course_classrooms coc ON coc.course_id = u.course_id
        INNER JOIN
            rooms r ON r.id = coc.classroom_id AND r.location_id = l.id
        WHERE 
            u.id = ? 
            AND u.rea_enabled = 1 
            AND u.course_id IS NOT NULL
        GROUP BY 
            u.id;";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows === 0) {
        $conn->close();
        return null;
    }

    $person = $result->fetch_assoc();
    $person['checked'] = (bool) $person['checked'];

    $conn->close();
    return $person;
}