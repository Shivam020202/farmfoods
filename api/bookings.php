<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

function supabaseRequest(string $method, string $path, array $payload = [], array $query = []): array {
    $cfg = requireSupabaseConfig();

    $url = rtrim($cfg['url'], '/') . '/rest/v1/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apikey: ' . $cfg['anon_key'],
        'Authorization: Bearer ' . $cfg['service_role_key'],
        'Prefer: return=representation',
    ]);

    if ($method !== 'GET' && $payload) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'status' => 500, 'message' => 'cURL error: ' . $err];
    }

    $decoded = json_decode($response, true);
    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'body' => $decoded,
        'raw' => $response,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $result = supabaseRequest('GET', 'farm_visit_bookings', [], [
        'select' => 'id,lead_name,phone,email,visit_date,slot_label,attendee_count,status,feedback_message,created_at',
        'order' => 'created_at.desc',
    ]);
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $required = ['lead_name', 'phone', 'visit_date', 'slot_label', 'attendee_count'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Missing required field: ' . $field], JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $payload = [
        'lead_name' => trim($input['lead_name']),
        'phone' => trim($input['phone']),
        'email' => trim($input['email'] ?? ''),
        'visit_date' => trim($input['visit_date']),
        'slot_label' => trim($input['slot_label']),
        'slot_time' => trim($input['slot_time'] ?? ''),
        'attendee_count' => (int) $input['attendee_count'],
        'attendee_details' => $input['attendee_details'] ?? [],
        'status' => 'pending',
        'feedback_message' => trim($input['feedback_message'] ?? ''),
        'source' => 'farmmade-frontend',
        'verified_otp' => !empty($input['otp_verified']) ? true : false,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    $result = supabaseRequest('POST', 'farm_visit_bookings', $payload);
    if (!$result['ok']) {
        http_response_code($result['status'] ?: 500);
        echo json_encode(['ok' => false, 'message' => 'Booking insert failed', 'details' => $result], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Booking created successfully.',
        'booking' => $result['body'][0] ?? $payload,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'PATCH') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($input['id'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Missing booking id.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $payload = [];
    if (isset($input['status'])) $payload['status'] = $input['status'];
    if (isset($input['admin_note'])) $payload['admin_note'] = $input['admin_note'];
    if (isset($input['feedback_message'])) $payload['feedback_message'] = $input['feedback_message'];

    $result = supabaseRequest('PATCH', 'farm_visit_bookings?id=eq.' . rawurlencode($input['id']), $payload);
    if (!$result['ok']) {
        http_response_code($result['status'] ?: 500);
        echo json_encode(['ok' => false, 'message' => 'Booking update failed', 'details' => $result], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'Booking updated successfully.', 'booking' => $result['body'][0] ?? null], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($input['id'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Missing booking id.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $result = supabaseRequest('DELETE', 'farm_visit_bookings?id=eq.' . rawurlencode($input['id']));
    if (!$result['ok']) {
        http_response_code($result['status'] ?: 500);
        echo json_encode(['ok' => false, 'message' => 'Booking delete failed', 'details' => $result], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'Booking deleted successfully.'], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
json_encode(['ok' => false, 'message' => 'Method not allowed.']);
