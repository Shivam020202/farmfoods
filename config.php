<?php

function env($key, $default = null) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

const SUPABASE_URL = 'SUPABASE_URL';
const SUPABASE_ANON_KEY = 'SUPABASE_ANON_KEY';
const SUPABASE_SERVICE_ROLE_KEY = 'SUPABASE_SERVICE_ROLE_KEY';

function supabaseConfig(): array {
    return [
        'url' => env('SUPABASE_URL', ''),
        'anon_key' => env('SUPABASE_ANON_KEY', ''),
        'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY', ''),
    ];
}

function requireSupabaseConfig(): array {
    $cfg = supabaseConfig();
    if ($cfg['url'] === '' || $cfg['service_role_key'] === '') {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Supabase environment variables are not configured. Create a .env file or set SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY.',
            'config' => $cfg,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    return $cfg;
}
