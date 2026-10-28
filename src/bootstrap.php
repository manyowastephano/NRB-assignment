<?php
declare(strict_types=1);

/**
 * Bootstrap file: loads the .env settings, opens the PDO connection,
 * starts the session and defines the helper functions used by every page.
 */

define('ROOT_PATH', dirname(__DIR__));

// ---------------------------------------------------------------
// 1. Environment settings (.env is never committed to Git)
// ---------------------------------------------------------------
function load_env(string $file): array
{
    $vars = [];
    if (!is_readable($file)) {
        return $vars;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $vars[trim($key)] = trim($value, " \t\"'");
    }
    return $vars;
}

$ENV = load_env(ROOT_PATH . '/.env');
function env(string $key, string $default = ''): string
{
    global $ENV;
    return $ENV[$key] ?? getenv($key) ?: $default;
}

// Never show raw PHP errors to users
ini_set('display_errors', env('APP_DEBUG') === 'true' ? '1' : '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------
// 2. Session (used for flash messages and CSRF tokens)
// ---------------------------------------------------------------
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

// ---------------------------------------------------------------
// 3. Database connection with error handling
// ---------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '3306'),
        env('DB_NAME', 'nrb_db')
    );
    try {
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
        ]);
    } catch (PDOException $ex) {
        error_log('DB connection failed: ' . $ex->getMessage());
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Database error</title>'
           . '<link rel="stylesheet" href="assets/css/style.css"></head><body><main class="container">'
           . '<div class="alert alert-error"><strong>Database connection failed.</strong> '
           . 'Please check the settings in the .env file and make sure MySQL/MariaDB is running.</div>'
           . '</main></body></html>';
        exit;
    }
    return $pdo;
}

// ---------------------------------------------------------------
// 4. Helper functions
// ---------------------------------------------------------------

/** Escape output to prevent XSS */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Flash messages (shown once after a redirect) */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function render_flash(): void
{
    foreach ($_SESSION['flash'] ?? [] as $f) {
        echo '<div class="alert alert-' . e($f['type']) . '">' . e($f['message']) . '</div>';
    }
    unset($_SESSION['flash']);
}

/** CSRF protection for every POST form */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

/** Trimmed POST value (empty string when missing) */
function post(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

/** Value to put back in a form field after a failed submission */
function old(array $data, string $key, string $default = ''): string
{
    return e($data[$key] ?? $default);
}

function selected($current, $value): string
{
    return (string)$current === (string)$value ? ' selected' : '';
}

function checked($current, $value): string
{
    return (string)$current === (string)$value ? ' checked' : '';
}

/** Returns '' when empty so that optional fields are stored as NULL */
function nullable(string $v): ?string
{
    return $v === '' ? null : $v;
}

// ---------------------------------------------------------------
// 5. Validation helpers
// ---------------------------------------------------------------
function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function valid_name(string $n): bool
{
    return (bool)preg_match("/^[\\p{L}][\\p{L}\\s'.\\-]{0,49}$/u", $n);
}

function valid_phone(string $p): bool
{
    return (bool)preg_match('/^\\+?[0-9]{9,13}$/', $p);
}

/** Explains a database constraint error in plain words */
function friendly_db_error(PDOException $ex): string
{
    $code = (int)($ex->errorInfo[1] ?? 0);
    $msg  = $ex->getMessage();
    if ($code === 1062) {                       // duplicate value
        $map = [
            'receipt_no'          => 'This receipt number is already used by another application.',
            'nid_number'          => 'This NID number is already assigned to another person.',
            'citizenship_cert_no' => 'This citizenship certificate number is already registered.',
            'permit_no'           => 'This permit number is already registered.',
            'card_serial_no'      => 'This card serial number already exists.',
            'application_id'      => 'A card has already been issued for this application.',
            'employee_no'         => 'This employee number already exists.',
        ];
        foreach ($map as $key => $text) {
            if (stripos($msg, $key) !== false) {
                return $text;
            }
        }
        return 'A record with the same unique value already exists.';
    }
    if ($code === 1451) {
        return 'This record cannot be deleted because other records depend on it.';
    }
    if ($code === 1452) {
        return 'A selected related record (such as a centre, officer or authority) does not exist.';
    }
    if ($code === 4025 || $code === 3819) {
        return 'The data breaks a database rule (for example an invalid status or date order).';
    }
    error_log('DB error: ' . $msg);
    return 'The database could not save the record. Please check the data and try again.';
}

// ---------------------------------------------------------------
// 6. Lookup lists used in forms
// ---------------------------------------------------------------
function lookup_tas(): array
{
    return db()->query(
        'SELECT t.ta_id, t.ta_name, d.district_name
         FROM traditional_authority t JOIN district d ON d.district_id = t.district_id
         ORDER BY d.district_name, t.ta_name'
    )->fetchAll();
}

function lookup_centres(): array
{
    return db()->query(
        "SELECT c.centre_id, c.centre_name, d.district_name
         FROM registration_centre c JOIN district d ON d.district_id = c.district_id
         WHERE c.status = 'Active' ORDER BY c.centre_name"
    )->fetchAll();
}

function lookup_officers(): array
{
    return db()->query(
        'SELECT officer_id, centre_id, CONCAT(first_name, " ", surname, " (", employee_no, ")") AS label
         FROM officer WHERE employee_no <> "ONLINE-PORTAL" ORDER BY first_name, surname'
    )->fetchAll();
}

function lookup_districts(): array
{
    return db()->query('SELECT district_id, district_name FROM district ORDER BY district_name')->fetchAll();
}

const MARITAL_OPTIONS   = ['Single', 'Married', 'Divorced', 'Widowed'];
const APP_TYPES         = ['New', 'Replacement', 'Renewal'];
const APP_STATUSES      = ['Pending', 'Approved', 'Rejected'];
const CARD_STATUSES     = ['Active', 'Lost', 'Expired', 'Replaced'];
const ADDRESS_TYPES     = ['Permanent', 'Temporary', 'Postal'];
const CITIZEN_BASES     = ['Birth', 'Descent', 'Registration', 'Naturalisation'];


// ---------------------------------------------------------------
// 7. Staff login (officers) and public portal helpers
// ---------------------------------------------------------------
const PORTAL_EMPLOYEE_NO = 'ONLINE-PORTAL';

/** The logged-in officer, or null for the public */
function staff(): ?array
{
    return $_SESSION['staff'] ?? null;
}

/** Protects every staff page: visitors are sent to the login page */
function require_staff(): void
{
    if (!staff()) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
        redirect('login.php');
    }
}

/**
 * Online applications are not captured by a real officer yet, so they are
 * linked to a system account. The reviewing officer replaces it on approval.
 */
function portal_officer_id(PDO $pdo): int
{
    $st = $pdo->prepare('SELECT officer_id FROM officer WHERE employee_no = :e');
    $st->execute([':e' => PORTAL_EMPLOYEE_NO]);
    $id = $st->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $centre = $pdo->query('SELECT centre_id FROM registration_centre ORDER BY centre_id LIMIT 1')->fetchColumn();
    if (!$centre) {
        throw new RuntimeException('No registration centre exists.');
    }
    $pdo->prepare('INSERT INTO officer (employee_no, first_name, surname, job_role, centre_id) VALUES (:e, "Online", "Portal", "System account", :c)')
        ->execute([':e' => PORTAL_EMPLOYEE_NO, ':c' => $centre]);
    return (int)$pdo->lastInsertId();
}

/** Reference number given to an applicant, e.g. NRB-2026-483920 (stored in application.receipt_no) */
function generate_reference(PDO $pdo): string
{
    $st = $pdo->prepare('SELECT 1 FROM application WHERE receipt_no = :r');
    do {
        $ref = sprintf('NRB-%s-%06d', date('Y'), random_int(0, 999999));
        $st->execute([':r' => $ref]);
    } while ($st->fetchColumn());
    return $ref;
}

/** Very small brute-force limiter kept in the session. Returns true when the limit is reached. */
function too_many_attempts(string $key, int $max, int $seconds): bool
{
    $now = time();
    $list = array_filter($_SESSION['attempts'][$key] ?? [], fn($t) => $t > $now - $seconds);
    $_SESSION['attempts'][$key] = $list;
    return count($list) >= $max;
}

function record_attempt(string $key): void
{
    $_SESSION['attempts'][$key][] = time();
}

function is_portal_officer(?string $employeeNo): bool
{
    return $employeeNo === PORTAL_EMPLOYEE_NO;
}
