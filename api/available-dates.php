<?php
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

function getSupabaseConfig() {
    return [
        'url' => getenv('SUPABASE_URL') ?: '',
        'anonKey' => getenv('SUPABASE_ANON_KEY') ?: '',
        'serviceRoleKey' => getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '',
    ];
}

function fallbackPath() {
    return __DIR__ . '/demo_available_dates.json';
}

function loadFallback() {
    $path = fallbackPath();
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function saveFallback($dates) {
    file_put_contents(fallbackPath(), json_encode($dates, JSON_PRETTY_PRINT));
}

function supabaseRequest($method, $apiPath, $payload = [], $query = []) {
    $cfg = getSupabaseConfig();
    if (empty($cfg['url']) || empty($cfg['serviceRoleKey'])) {
        return ['ok' => false, 'status' => 503, 'message' => 'Supabase not configured', 'fallback' => true];
    }

    $url = rtrim($cfg['url'], '/') . '/rest/v1/' . ltrim($apiPath, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $cfg['anonKey'],
        'Authorization: Bearer ' . $cfg['serviceRoleKey'],
        'Prefer: return=representation',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($method !== 'GET' && !empty($payload)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = json_decode($response, true);
    if ($body === null) $body = $response;

    return ['ok' => ($status >= 200 && $status < 300), 'status' => $status, 'body' => $body];
}

function parseInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

if ($method === 'GET') {
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;

    $query = [
        'select' => 'visit_date,is_available,max_bookings',
        'order' => 'visit_date.asc',
    ];
    if ($from && $to) {
        $query['visit_date'] = 'and(gte.' . $from . ',lte.' . $to . ')';
    } elseif ($from) {
        $query['visit_date'] = 'gte.' . $from;
    } elseif ($to) {
        $query['visit_date'] = 'lte.' . $to;
    }

    $result = supabaseRequest('GET', 'farm_available_dates', [], $query);

    if (!$result['ok'] && isset($result['fallback'])) {
        $dates = loadFallback();
        if ($from) $dates = array_filter($dates, fn($d) => $d['visit_date'] >= $from);
        if ($to) $dates = array_filter($dates, fn($d) => $d['visit_date'] <= $to);
        echo json_encode(['ok' => true, 'body' => array_values($dates)]);
        exit;
    }

    echo json_encode($result);
    exit;
}

if ($method === 'POST') {
    $input = parseInput();

    // Generate dates from day-of-week rules
    if (isset($input['generate']) && is_array($input['generate']['days'] ?? null)) {
        $g = $input['generate'];
        $days = $g['days'];
        $from = $g['from'] ?? null;
        $to = $g['to'] ?? null;
        $isAvailable = $g['is_available'] ?? true;

        if (!$from || !$to) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'from and to dates are required.']);
            exit;
        }

        $start = new DateTime($from);
        $end = new DateTime($to);
        $datesToInsert = [];
        $current = clone $start;
        while ($current <= $end) {
            if (in_array((int)$current->format('w'), $days)) {
                $datesToInsert[] = [
                    'visit_date' => $current->format('Y-m-d'),
                    'is_available' => $isAvailable,
                    'max_bookings' => 12,
                ];
            }
            $current->modify('+1 day');
        }

        if (count($datesToInsert) === 0) {
            echo json_encode(['ok' => true, 'message' => 'No dates matched the selected days.', 'body' => []]);
            exit;
        }

        // Try Supabase first; fall back to file
        $cfg = getSupabaseConfig();
        $supabaseOk = !empty($cfg['url']) && !empty($cfg['serviceRoleKey']);
        if ($supabaseOk) {
            $result = supabaseRequest('POST', 'farm_available_dates', $datesToInsert);
            if ($result['ok']) {
                echo json_encode(['ok' => true, 'message' => count($datesToInsert) . ' dates generated.', 'body' => $result['body'] ?? $datesToInsert]);
                exit;
            }
        }

        // Fallback: write to JSON
        $existing = loadFallback();
        foreach ($datesToInsert as $entry) {
            $idx = array_search($entry['visit_date'], array_column($existing, 'visit_date'));
            if ($idx !== false) {
                $existing[$idx]['is_available'] = $entry['is_available'];
            } else {
                $existing[] = ['id' => 'demo-ad-' . bin2hex(random_bytes(3)), ...$entry, 'created_at' => date('c')];
            }
        }
        saveFallback($existing);
        echo json_encode(['ok' => true, 'message' => count($datesToInsert) . ' dates generated.', 'body' => $datesToInsert]);
        exit;
    }

    // Bulk update
    if (isset($input['bulk']) && is_array($input['bulk'])) {
        $results = [];
        $existing = loadFallback();
        $supabaseOk = !empty(getSupabaseConfig()['url']) && !empty(getSupabaseConfig()['serviceRoleKey']);

        foreach ($input['bulk'] as $entry) {
            if (empty($entry['visit_date'])) continue;
            $record = [
                'visit_date' => $entry['visit_date'],
                'is_available' => $entry['is_available'] !== false,
                'max_bookings' => $entry['max_bookings'] ?? 12,
            ];
            $results[] = $record;

            if ($supabaseOk) {
                // check if exists
                $check = supabaseRequest('GET', 'farm_available_dates', [], ['select' => 'id', 'visit_date' => 'eq.' . $entry['visit_date']]);
                if ($check['ok'] && is_array($check['body']) && count($check['body']) > 0) {
                    supabaseRequest('PATCH', 'farm_available_dates?visit_date=eq.' . $entry['visit_date'], ['is_available' => $record['is_available']]);
                } else {
                    supabaseRequest('POST', 'farm_available_dates', $record);
                }
            }

            // Always update local fallback
            $idx = array_search($entry['visit_date'], array_column($existing, 'visit_date'));
            if ($idx !== false) {
                $existing[$idx]['is_available'] = $record['is_available'];
            } else {
                $existing[] = ['id' => 'demo-ad-' . bin2hex(random_bytes(3)), ...$record, 'created_at' => date('c')];
            }
        }
        saveFallback($existing);
        echo json_encode(['ok' => true, 'message' => count($results) . ' dates updated.', 'body' => $results]);
        exit;
    }

    // Single toggle
    if (empty($input['visit_date'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'visit_date is required.']);
        exit;
    }

    $payload = [
        'visit_date' => $input['visit_date'],
        'is_available' => $input['is_available'] ?? true,
        'max_bookings' => $input['max_bookings'] ?? 12,
    ];

    $cfg = getSupabaseConfig();
    $supabaseOk = !empty($cfg['url']) && !empty($cfg['serviceRoleKey']);
    $result = null;

    if ($supabaseOk) {
        $check = supabaseRequest('GET', 'farm_available_dates', [], ['select' => 'id', 'visit_date' => 'eq.' . $input['visit_date']]);
        if ($check['ok'] && is_array($check['body']) && count($check['body']) > 0) {
            $result = supabaseRequest('PATCH', 'farm_available_dates?visit_date=eq.' . $input['visit_date'], ['is_available' => $payload['is_available']]);
        } else {
            $result = supabaseRequest('POST', 'farm_available_dates', $payload);
        }
    }

    // Always update local fallback
    $existing = loadFallback();
    $idx = array_search($input['visit_date'], array_column($existing, 'visit_date'));
    if ($idx !== false) {
        $existing[$idx]['is_available'] = $payload['is_available'];
    } else {
        $existing[] = ['id' => 'demo-ad-' . bin2hex(random_bytes(3)), ...$payload, 'created_at' => date('c')];
    }
    saveFallback($existing);

    echo json_encode(['ok' => true, 'message' => 'Date toggled.', 'body' => $payload]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
