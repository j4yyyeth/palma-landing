<?php
ini_set('log_errors', 1);
ini_set('error_log', '../logs/form_errors.log');
error_reporting(E_ALL);

function load_config()
{
    return [
        'data_dir' => '../data/',
        'max_requests_per_hour' => 10,
        'max_name_length' => 50,
        'max_company_length' => 100,
        'max_phone_length' => 20
    ];
}

$config = load_config();

error_log("=== NEW CONTEST SUBMISSION ===");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
error_log("User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Invalid method: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

function check_rate_limit($ip, $config)
{
    $rate_file = $config['data_dir'] . 'rate_limits.json';
    $max_requests = $config['max_requests_per_hour'];
    $time_window = 3600;

    if (!file_exists($config['data_dir'])) {
        if (!mkdir($config['data_dir'], 0755, true)) {
            error_log("Failed to create data directory");
            return false;
        }
    }

    $rates = [];
    if (file_exists($rate_file)) {
        $content = file_get_contents($rate_file);
        if ($content !== false) {
            $rates = json_decode($content, true) ?: [];
        }
    }

    $now = time();
    $user_requests = $rates[$ip] ?? [];

    $user_requests = array_filter($user_requests, function ($time) use ($now, $time_window) {
        return ($now - $time) < $time_window;
    });

    if (count($user_requests) >= $max_requests) {
        error_log("Rate limit exceeded for IP: $ip");
        return false;
    }

    $user_requests[] = $now;
    $rates[$ip] = array_values($user_requests);

    foreach ($rates as $stored_ip => $requests) {
        $rates[$stored_ip] = array_filter($requests, function ($time) use ($now, $time_window) {
            return ($now - $time) < $time_window;
        });

        if (empty($rates[$stored_ip])) {
            unset($rates[$stored_ip]);
        }
    }

    file_put_contents($rate_file, json_encode($rates));
    return true;
}

function validate_name($name, $max_length = 50)
{
    return !empty($name) &&
        strlen($name) <= $max_length &&
        preg_match('/^[a-zA-Z\s\-\'\.]+$/u', trim($name));
}

function validate_company($company, $max_length = 100)
{
    return !empty($company) &&
        strlen($company) <= $max_length &&
        preg_match('/^[a-zA-Z0-9\s\-\'\.&,]+$/u', trim($company));
}

function validate_email($email)
{
    $email = trim($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) &&
        strlen($email) <= 254 &&
        !preg_match('/[<>\r\n]/', $email) &&
        !empty($email);
}

function validate_phone($phone, $max_length = 20)
{
    return !empty($phone) &&
        strlen($phone) <= $max_length &&
        preg_match('/^[\d\s\-\(\)\+\.]+$/', trim($phone));
}

function sanitize_input($input)
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

$user_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!check_rate_limit($user_ip, $config)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please try again in an hour.']);
    exit;
}

$raw_input = file_get_contents('php://input');
if (empty($raw_input)) {
    error_log("Empty input received");
    http_response_code(400);
    echo json_encode(['error' => 'No data received']);
    exit;
}

$input = json_decode($raw_input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}

error_log("Processing contest form submission");

$name = $input['name'] ?? '';
$company = $input['company'] ?? '';
$email = $input['email'] ?? '';
$phone = $input['phone'] ?? '';

error_log("Contest raw data - Name: '$name', Company: '$company', Email: '$email', Phone: '$phone'");

if (!validate_name($name, $config['max_name_length'])) {
    error_log("Contest: Invalid name: " . $name);
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid name (letters, spaces, hyphens, apostrophes only)']);
    exit;
}

if (!validate_company($company, $config['max_company_length'])) {
    error_log("Contest: Invalid company: " . $company);
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid company name']);
    exit;
}

if (!validate_email($email)) {
    error_log("Contest: Invalid email: " . $email);
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid email address']);
    exit;
}

if (!validate_phone($phone, $config['max_phone_length'])) {
    error_log("Contest: Invalid phone: " . $phone);
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid phone number']);
    exit;
}

$name = sanitize_input($name);
$company = sanitize_input($company);
$email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
$phone = sanitize_input($phone);

error_log("Contest validated data - Name: '$name', Company: '$company', Email: '$email', Phone: '$phone'");

$submissions_file = $config['data_dir'] . 'form-submissions.json';

try {
    if (!file_exists($config['data_dir'])) {
        if (!mkdir($config['data_dir'], 0755, true)) {
            error_log("Failed to create data directory");
            throw new Exception("Could not create data directory");
        }
        error_log("Created data directory");
    }

    $submissions = [];
    if (file_exists($submissions_file)) {
        $content = file_get_contents($submissions_file);
        if ($content !== false) {
            $submissions = json_decode($content, true) ?: [];
        }
    }

    foreach ($submissions as $sub) {
        if (strtolower($sub['email']) === strtolower($email)) {
            error_log("Contest: Duplicate email attempt: " . $email);
            http_response_code(400);
            echo json_encode(['error' => 'This email has already been entered in the contest.']);
            exit;
        }
    }

    $submission = [
        'id' => uniqid('contest_', true),
        'name' => $name,
        'company' => $company,
        'email' => $email,
        'phone' => $phone,
        'submitted_at' => date('Y-m-d H:i:s')
    ];

    $submissions[] = $submission;

    if (file_put_contents($submissions_file, json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        error_log("Failed to save contest submission to file");
        throw new Exception("Could not save submission");
    }

    chmod($submissions_file, 0644);

    error_log("Contest submission saved successfully - ID: " . $submission['id'] . ", Name: $name, Email: $email");

    echo json_encode([
        'success' => true,
        'message' => 'Thank you for entering! Your submission has been recorded.',
        'submission_id' => $submission['id']
    ]);
} catch (Exception $e) {
    error_log("Contest submission file error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save submission. Please try again.']);
}

error_log("=== CONTEST SUBMISSION COMPLETED ===\n");
