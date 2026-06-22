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
    return __DIR__ . '/../api/demo_bookings.json';
}

function loadFallback() {
    $path = fallbackPath();
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function saveFallback($bookings) {
    file_put_contents(fallbackPath(), json_encode($bookings, JSON_PRETTY_PRINT));
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
    $result = supabaseRequest('GET', 'farm_visit_bookings', [], [
        'select' => 'id,lead_name,phone,email,visit_date,slot_label,attendee_count,status,feedback_message,created_at',
        'order' => 'created_at.desc',
    ]);

    if (!$result['ok'] && isset($result['fallback'])) {
        echo json_encode(['ok' => true, 'body' => loadFallback()]);
        exit;
    }

    echo json_encode($result);
    exit;
}

if ($method === 'POST') {
    $input = parseInput();
    $required = ['lead_name', 'phone', 'visit_date', 'slot_label', 'attendee_count'];
    foreach ($required as $field) {
        if (empty($input[$field]) || trim((string)$input[$field]) === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Missing required field: ' . $field]);
            exit;
        }
    }

    $payload = [
        'lead_name' => trim((string)$input['lead_name']),
        'phone' => trim((string)$input['phone']),
        'email' => trim((string)($input['email'] ?? '')),
        'visit_date' => trim((string)$input['visit_date']),
        'slot_label' => trim((string)$input['slot_label']),
        'slot_time' => trim((string)($input['slot_time'] ?? '')),
        'attendee_count' => (int)$input['attendee_count'],
        'attendee_details' => $input['attendee_details'] ?? [],
        'status' => 'pending',
        'feedback_message' => trim((string)($input['feedback_message'] ?? '')),
        'source' => 'farmmade-frontend',
        'verified_otp' => !empty($input['otp_verified']),
        'created_at' => date('c'),
    ];

    $result = supabaseRequest('POST', 'farm_visit_bookings', $payload);
    if (!$result['ok'] && isset($result['fallback'])) {
        $bookings = loadFallback();
        $booking = array_merge(['id' => 'demo-' . bin2hex(random_bytes(3)), 'created_at' => date('c')], $payload);
        $bookings[] = $booking;
        saveFallback($bookings);
        echo json_encode(['ok' => true, 'message' => 'Booking created successfully.', 'booking' => $booking]);
        exit;
    }

    if (!$result['ok']) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Booking insert failed']);
        exit;
    }

    $created = is_array($result['body']) && isset($result['body'][0]) ? $result['body'][0] : $payload;
    echo json_encode(['ok' => true, 'message' => 'Booking created successfully.', 'booking' => $created]);
    exit;
}

if ($method === 'PATCH') {
    $input = parseInput();
    if (empty($input['id'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Missing booking id.']);
        exit;
    }

    $payload = [];
    if (isset($input['status'])) $payload['status'] = $input['status'];
    if (isset($input['admin_note'])) $payload['admin_note'] = $input['admin_note'];
    if (isset($input['feedback_message'])) $payload['feedback_message'] = $input['feedback_message'];

    $result = supabaseRequest('PATCH', 'farm_visit_bookings?id=eq.' . urlencode($input['id']), $payload);
    if (!$result['ok'] && isset($result['fallback'])) {
        $bookings = loadFallback();
        foreach ($bookings as &$booking) {
            if ($booking['id'] === $input['id']) {
                $booking = array_merge($booking, $payload);
            }
        }
        saveFallback($bookings);
        echo json_encode(['ok' => true, 'message' => 'Booking updated successfully.']);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'Booking updated successfully.']);
    exit;
}

if ($method === 'DELETE') {
    $input = parseInput();
    if (empty($input['id'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Missing booking id.']);
        exit;
    }

    $result = supabaseRequest('DELETE', 'farm_visit_bookings?id=eq.' . urlencode($input['id']));
    if (!$result['ok'] && isset($result['fallback'])) {
        $bookings = loadFallback();
        $bookings = array_values(array_filter($bookings, fn($b) => $b['id'] !== $input['id']));
        saveFallback($bookings);
        echo json_encode(['ok' => true, 'message' => 'Booking deleted successfully.']);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'Booking deleted successfully.']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
