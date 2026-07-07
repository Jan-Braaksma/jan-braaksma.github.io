<?php
require_once __DIR__ . '/../profiel/dashboard.php';
require_once __DIR__ . '/../aanwezigheid/aanwezigheidfuncties.php';

/**
 * Calculate attendance statistics for a group of users over a date range
 * Processes attendance data to generate summary statistics including presence, tardiness, and absences
 * 
 * @param array $users Array of user data indexed by user ID
 * @param DateTime $start Start date for the statistics period
 * @param DateTime $end End date for the statistics period
 * 
 * @return array Associative array with totals for 'aanwezig', 'telaat', 'geoorloofd', and 'ongeoorloofd'
 */
function calculateStatistics($users, $start, $end) {
    $total_aanwezig = 0;      // aanwezig (op tijd)
    $total_telaat = 0;        // te laat
    $total_geoorloofd = 0;    // ziek + verlof
    $total_ongeoorloofd = 0;  // ongeoorloofd

    foreach ($users as $userId => $user_data) {
        $data = getProfileData($userId, $start, $end);

        $total_aanwezig    += $data['aanwezig'];      // op tijd aanwezig
        $total_telaat      += $data['telaat'];        // te laat aanwezig
        $total_geoorloofd  += $data['ziek'] + $data['verlof'];
        $total_ongeoorloofd+= $data['ongeoorloofd'];
    }

    // For statistics display: subtract telaat from aanwezig to separate them
    // Dashboard counts "te laat" as both aanwezig AND telaat, but for statistics we want them separate
    $total_aanwezig -= $total_telaat;

    // Each day should be in exactly one of these categories:
    $total_days = $total_aanwezig + $total_telaat + $total_geoorloofd + $total_ongeoorloofd;
    // Divide by zero prevention
    if ($total_days == 0) $total_days = 1;

    return [
        'total_aanwezig' => $total_aanwezig,
        'total_telaat' => $total_telaat,
        'total_geoorloofd' => $total_geoorloofd,
        'total_ongeoorloofd' => $total_ongeoorloofd,
        'total_days' => $total_days,
        'aanwezig_pct' => round(($total_aanwezig / $total_days) * 100, 1),
        'telaat_pct' => round(($total_telaat / $total_days) * 100, 1),
        'geoorloofd_pct' => round(($total_geoorloofd / $total_days) * 100, 1),
        'ongeoorloofd_pct' => round(($total_ongeoorloofd / $total_days) * 100, 1)
    ];
}

/**
 * Get comprehensive statistics data in structured format for API usage
 * Returns structured attendance data without HTML rendering, supports filtering by location and class
 * 
 * @param string $loc Location filter ("all" or specific location name)
 * @param string $klas Class/group filter ("all" or specific class name)
 * @param DateTime $start Start date for the statistics period
 * @param DateTime $end End date for the statistics period
 * 
 * @return array Structured array containing statistics data, charts data, and metadata
 */
function getStatisticsData($loc, $klas, $start, $end) {
    require_once __DIR__ . "/../aanwezigheid/aanwezigheidfuncties.php";
    // Check if we need to show multiple rows (when location or class is "all")
    $show_multiple_rows = ($loc === "all" || $klas === "all");

    if ($show_multiple_rows) {
        // Get all available locations and classes for grouping
        require_once __DIR__ . '/../locations/locationfunctions.php';
        require_once __DIR__ . '/../opleidingmanagement/opleidingmanagementfunctions.php';

        $locations = getLocations();
        $courses = getOpleidinglist();
        $rooms = getClassroomsWithTrackingChecks($courses);

        $stats_data = [];
        $overall_totals = [
            'aanwezig' => 0,
            'telaat' => 0, 
            'geoorloofd' => 0,
            'ongeoorloofd' => 0
        ];

        if ($loc === "all" && $klas !== "all") {
            // Group by locations for specific class
            foreach ($locations as $location) {
                $location_name = strtolower($location['name']);
                $users = usersopleiding($klas, $location_name, $start, $end);

                if ($users && count($users) > 0) {
                    $users = array_filter($users, function ($user) {
                        // $user[4] is track_checks
                        return $user[4] === 1;
                    });

                    $stats = calculateStatistics($users, $start, $end);
                    $stats_data[] = [
                        'label' => $location['name'] . " - " . $klas,
                        'stats' => $stats
                    ];

                    $overall_totals['aanwezig'] += $stats['total_aanwezig'];
                    $overall_totals['telaat'] += $stats['total_telaat'];
                    $overall_totals['geoorloofd'] += $stats['total_geoorloofd'];
                    $overall_totals['ongeoorloofd'] += $stats['total_ongeoorloofd'];
                }
            }
        } elseif ($loc !== "all" && $klas === "all") {
            foreach ($rooms as $roomName => $room) {
                $users = usersopleiding(strtolower($roomName), $loc, $start, $end);

                if ($users && count($users) > 0) {
                    $users = array_filter($users, function ($user) {
                        // $user[4] is track_checks
                        return $user[4] === 1;
                    });

                    $stats = calculateStatistics($users, $start, $end);
                    $stats_data[] = [
                        'label' => $loc . " - " . $roomName,
                        'stats' => $stats
                    ];

                    $overall_totals['aanwezig'] += $stats['total_aanwezig'];
                    $overall_totals['telaat'] += $stats['total_telaat'];
                    $overall_totals['geoorloofd'] += $stats['total_geoorloofd'];
                    $overall_totals['ongeoorloofd'] += $stats['total_ongeoorloofd'];
                }
            }
        } else {
            // Both location and class are "all" - group by location-class combinations
            foreach ($locations as $location) {
                $location_name = strtolower($location['name']);
                foreach ($rooms as $roomName => $room) {
                    $users = usersopleiding(strtolower($roomName), $location_name, $start, $end);

                    if ($users && count($users) > 0) {
                        $users = array_filter($users, function ($user) {
                            // $user[4] is track_checks
                            return $user[4] === 1;
                        });

                        $stats = calculateStatistics($users, $start, $end);
                        $stats_data[] = [
                            'label' => $location['name'] . " - " . $roomName,
                            'stats' => $stats
                        ];

                        $overall_totals['aanwezig'] += $stats['total_aanwezig'];
                        $overall_totals['telaat'] += $stats['total_telaat'];
                        $overall_totals['geoorloofd'] += $stats['total_geoorloofd'];
                        $overall_totals['ongeoorloofd'] += $stats['total_ongeoorloofd'];
                    }
                }
            }
        }

        // Calculate percentages for overall totals
        $total_days = $overall_totals['aanwezig'] + $overall_totals['telaat'] + 
                      $overall_totals['geoorloofd'] + $overall_totals['ongeoorloofd'];
        if ($total_days == 0) $total_days = 1;

        $overall_totals['total_aanwezig'] = $overall_totals['aanwezig'];
        $overall_totals['total_telaat'] = $overall_totals['telaat'];
        $overall_totals['total_geoorloofd'] = $overall_totals['geoorloofd'];
        $overall_totals['total_ongeoorloofd'] = $overall_totals['ongeoorloofd'];
        $overall_totals['aanwezig_pct'] = round(($overall_totals['aanwezig'] / $total_days) * 100, 1);
        $overall_totals['telaat_pct'] = round(($overall_totals['telaat'] / $total_days) * 100, 1);
        $overall_totals['geoorloofd_pct'] = round(($overall_totals['geoorloofd'] / $total_days) * 100, 1);
        $overall_totals['ongeoorloofd_pct'] = round(($overall_totals['ongeoorloofd'] / $total_days) * 100, 1);

        return [
            'type' => 'multiple',
            'data' => $stats_data,
            'overall_totals' => $overall_totals
        ];

    } else {
        // Original single row logic
        $users = usersopleiding($klas, $loc, $start, $end);

        if (!$users || count($users) === 0) {
            return [
                'type' => 'error',
                'message' => 'Geen gebruikers gevonden voor deze klas/locatie.'
            ];
        }

        $stats = calculateStatistics($users, $start, $end);
        return [
            'type' => 'single',
            'data' => $stats,
            'label' => $klas
        ];
    }
}

/**
 * Display statistics table for multiple groups/classes
 * Renders HTML table showing attendance statistics for multiple groups with totals
 * 
 * @param array $stats_data Statistics data for individual groups/classes
 * @param array $overall_totals Overall totals across all groups
 * 
 * @return void Outputs HTML directly
 */
function displayMultipleRowStatistics($stats_data, $overall_totals) {
    echo <<<HTML
    <div class='statistics-container'>
        <div class='statistics-table'>
            <table class='mgmt-table'>
                <thead>
                    <tr>
                        <th>Groep</th>
                        <th>Aanwezig</th>
                        <th>Te laat</th>
                        <th>Geoorloofd afwezig</th>
                        <th>Ongeoorloofd afwezig</th>
                    </tr>
                </thead>
                <tbody>
HTML;

    foreach ($stats_data as $row) {
        $label = htmlspecialchars($row['label']);
        echo <<<HTML
                    <tr>
                        <td>{$label}</td>
                        <td>{$row['stats']['aanwezig_pct']}%</td>
                        <td>{$row['stats']['telaat_pct']}%</td>
                        <td>{$row['stats']['geoorloofd_pct']}%</td>
                        <td>{$row['stats']['ongeoorloofd_pct']}%</td>
                    </tr>
                HTML;
    }

    echo <<<HTML
                    <tr class='totals-row' style='font-weight: bold; border-top: 2px solid #333;'>
                        <td>Totaal</td>
                        <td>{$overall_totals['aanwezig_pct']}%</td>
                        <td>{$overall_totals['telaat_pct']}%</td>
                        <td>{$overall_totals['geoorloofd_pct']}%</td>
                        <td>{$overall_totals['ongeoorloofd_pct']}%</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class='chart-container'>
            <div class='pie-chart' 
                data-aanwezig='{$overall_totals['aanwezig_pct']}' 
                data-telaat='{$overall_totals['telaat_pct']}' 
                data-geoorloofd='{$overall_totals['geoorloofd_pct']}' 
                data-ongeoorloofd='{$overall_totals['ongeoorloofd_pct']}'></div>
        </div>
    </div>
HTML;
}

/**
 * Display statistics for a single group/class
 * Renders HTML output showing attendance statistics for one specific group
 * 
 * @param string $klas Class/group name
 * @param array $stats Statistics data array
 * 
 * @return void Outputs HTML directly
 */
function displaySingleRowStatistics($klas, $stats) {
    $klas_escaped = htmlspecialchars($klas);
    
    echo <<<HTML
    <div class='statistics-container'>
        <div class='statistics-table'>
            <table class='mgmt-table'>
                <thead>
                    <tr>
                        <th>Klas</th>
                        <th>Aanwezig (%)</th>
                        <th>Te laat (%)</th>
                        <th>Geoorloofd (%)</th>
                        <th>Ongeoorloofd (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{$klas_escaped}</td>
                        <td>{$stats['total_aanwezig']} ({$stats['aanwezig_pct']}%)</td>
                        <td>{$stats['total_telaat']} ({$stats['telaat_pct']}%)</td>
                        <td>{$stats['total_geoorloofd']} ({$stats['geoorloofd_pct']}%)</td>
                        <td>{$stats['total_ongeoorloofd']} ({$stats['ongeoorloofd_pct']}%)</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class='chart-container'>
            <div class='pie-chart' 
                data-aanwezig='{$stats['aanwezig_pct']}' 
                data-telaat='{$stats['telaat_pct']}' 
                data-geoorloofd='{$stats['geoorloofd_pct']}' 
                data-ongeoorloofd='{$stats['ongeoorloofd_pct']}'></div>
        </div>
    </div>
HTML;
}
