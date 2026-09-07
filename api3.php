<?php
/**
 * ============================================================================
 * api.php — GỘP 3 FILE GỐC THÀNH 1 API DUY NHẤT (PHP >= 8.0)
 * ----------------------------------------------------------------------------
 * Nguồn gốc:
 *   [M1] funpass.py  : Tạo Funpass (proxy US) -> CPI (proxy VN) -> sync LDPlayer
 *   [M2] khoga.py    : Flow tương tác: tạo Funpass -> CPI -> sync (auto/manual)
 *   [M3] muathe.js   : Đăng nhập LDPlayer, số dư, SKU, mua/săn thẻ (AES-192-ECB)
 *
 * Chế độ chạy:
 *   - WEB : mở api.php trên Apache/Nginx + PHP-FPM (UI + JSON API ?action=...)
 *   - CLI : php api.php  (menu nâng cao, auto + thủ công)
 *
 * Proxy: từng chức năng có cờ √/× trong proxy_config.json (sửa được trên web/CLI)
 * Extension cần: curl, openssl, json (gd + tesseract chỉ cần cho auto-captcha)
 * ============================================================================
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);
@ini_set('display_errors', '0');   // tránh notice lọt vào JSON làm hỏng response API
@set_time_limit(0);
@ignore_user_abort(true);

const APP_KEY_LD  = "cbe324bcbd1663f709079baf25ed640d";
const APP_KEY_FP  = "ML6SbuPorNznmglHmJ3KIL1VV3UDChb1";
const APP_SECRET  = "cbe324bcbd1663f709079baf25ed640d";   // muathe.js
const H5_KEY      = "hPnLE8rJUmystSxAolZHsQ==";           // muathe.js

const BASE_LD    = "https://usersdk.ldmnq.com";
const BASE_TEMP  = "https://api.internal.temp-mail.io/api/v3";
const PAYSDK_URL = "https://paysdk.ldmnq.com";
const CPH_URL    = "https://cph.funpg.net";                // có thể chết DNS — get_wallet_info có fallback
const API_APP    = "https://api-app.ldplayer.net";
const BASE_URL   = "https://api-app.easyfun.gg";           // muathe.js
const LDCODE_URL = "https://api.ldcode.gg";                // muathe.js
const ITEM_URL   = "https://api-item.ldplayer.net";        // muathe.js
const LOGIN_URL  = "https://user.ldplayer.net/user/login"; // muathe.js
// Ví Funpass: KHÔNG gọi cph.funpg.net (DNS chết → "Could not resolve host").
// Thứ tự: paysdk → api-app → (im lặng trả 0; điểm thật lấy từ transfer_detail).
const WALLET_FALLBACKS = [
    "https://paysdk.ldmnq.com/client/coin/user/my",
    "https://api-app.ldplayer.net/user/coin/my",
];

const HEADERS_TEMP = [
    "Application-Name"    => "web",
    "Application-Version" => "4.0.0",
    "X-CORS-Header"       => "iaWg3pchvFx48fY",
    "User-Agent"          => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "Content-Type"        => "application/json",
    "Origin"              => "https://temp-mail.io",
    "Referer"             => "https://temp-mail.io/",
];

const DIR_DATA      = __DIR__;
const FILE_CONFIG   = DIR_DATA . "/config.json";        // phiên đăng nhập muathe (giống muathe.js)
const FILE_CARDS    = DIR_DATA . "/cards.txt";          // giống muathe.js
const FILE_PROXY    = DIR_DATA . "/proxy.txt";          // proxy US (giống funpass.py)
const FILE_PROXY_VN = DIR_DATA . "/proxy_vn.txt";       // proxy VN (giống funpass.py)
const FILE_PROXYCFG = DIR_DATA . "/proxy_config.json";  // cờ √/× từng chức năng
const FILE_ACCOUNTS = DIR_DATA . "/accounts.json";      // tài khoản đã lưu (mục "Lưu Tài Khoản")
const FILE_RUNLOGS  = DIR_DATA . "/run_logs.json";      // lịch sử log các lần chạy (xem lại trên web)
const FILE_MODULES  = DIR_DATA . "/custom_modules.json"; // danh sách module PHP độc lập (tab dưới Proxy)
const MIMI_AUTH_COOKIE = 'mimi_user';
const MIMI_AUTH_TTL = 2592000; // 30 ngày trên cùng trình duyệt Chrome
const MIMI_DEVELOPER = 'MimiVip01';
const MIMI_AUTH_USERS = ['MimiVip01', 'Apimimi01', 'Apimimi05', 'Apimimi10'];

/* ============================================================================
 * NHẬN DIỆN NGƯỜI DÙNG MIMI
 * --------------------------------------------------------------------------
 * Xác minh theo tên như người dùng yêu cầu. Cookie chỉ là vé phiên; không dùng
 * User-Agent để giả làm khóa bảo mật vì Chrome có thể thay đổi thông tin đó.
 * Dữ liệu của mỗi tên được đặt trong .mimi_users/<ten>/.
 * ==========================================================================*/
function mimi_auth_secret(): string {
    $env = trim((string)(getenv('MIMI_AUTH_SECRET') ?: ''));
    return $env !== '' ? $env : hash('sha256', __FILE__ . '|' . PHP_VERSION);
}
function mimi_b64url_encode(string $v): string { return rtrim(strtr(base64_encode($v), '+/', '-_'), '='); }
function mimi_b64url_decode(string $v): string|false { return base64_decode(strtr($v . str_repeat('=', (4 - strlen($v) % 4) % 4), '-_', '+/'), true); }
function mimi_auth_token(string $name, int $expires): string {
    $body = $name . '|' . $expires;
    return mimi_b64url_encode($body . '|' . hash_hmac('sha256', $body, mimi_auth_secret()));
}
function mimi_auth_set(string $name): void {
    $expires = time() + MIMI_AUTH_TTL;
    setcookie(MIMI_AUTH_COOKIE, mimi_auth_token($name, $expires), [
        'expires' => $expires, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    $GLOBALS['MIMI_USER'] = $name;
}
function mimi_auth_clear(): void {
    setcookie(MIMI_AUTH_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
    unset($GLOBALS['MIMI_USER']);
}
function mimi_auth_current(): ?string {
    $cached = $GLOBALS['MIMI_USER'] ?? null;
    if (is_string($cached) && in_array($cached, MIMI_AUTH_USERS, true)) return $cached;
    $raw = (string)($_COOKIE[MIMI_AUTH_COOKIE] ?? '');
    $decoded = $raw !== '' ? mimi_b64url_decode($raw) : false;
    if ($decoded === false) return null;
    $parts = explode('|', $decoded);
    if (count($parts) !== 3) return null;
    [$name, $expires, $sig] = $parts;
    $expires = (int)$expires;
    $body = $name . '|' . $expires;
    if (!in_array($name, MIMI_AUTH_USERS, true) || $expires < time() || !hash_equals(hash_hmac('sha256', $body, mimi_auth_secret()), $sig)) return null;
    $GLOBALS['MIMI_USER'] = $name;
    return $name;
}
function mimi_is_developer(): bool { return mimi_auth_current() === MIMI_DEVELOPER; }
function mimi_canonical_user(?string $value): ?string {
    $value = trim((string)$value);
    foreach (MIMI_AUTH_USERS as $name) if (strcasecmp($name, $value) === 0) return $name;
    return null;
}
function mimi_set_target_user(?string $value): void {
    unset($GLOBALS['MIMI_TARGET_USER']);
    $value = trim((string)$value);
    if ($value === '' || !mimi_is_developer()) return;
    $target = mimi_canonical_user($value);
    if ($target === null) throw new RuntimeException('Tài khoản mục tiêu không hợp lệ');
    $GLOBALS['MIMI_TARGET_USER'] = $target;
}
function mimi_active_data_user(): ?string {
    $current = mimi_auth_current();
    if (!$current) return null;
    $target = $GLOBALS['MIMI_TARGET_USER'] ?? null;
    return (mimi_is_developer() && is_string($target) && in_array($target, MIMI_AUTH_USERS, true)) ? $target : $current;
}
function mimi_user_dir(): string {
    $name = mimi_active_data_user();
    if (!$name) throw new RuntimeException('Chưa xác minh tài khoản Mimi');
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name) ?: 'user');
    $dir = DIR_DATA . '/.mimi_users/' . $slug;
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); @chmod($dir, 0775); }
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Không tạo được vùng dữ liệu riêng cho tài khoản Mimi');
    return $dir;
}
function mimi_guest_dir(): string {
    $raw = (string)($_COOKIE['mimi_guest'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $raw)) {
        try { $raw = bin2hex(random_bytes(16)); } catch (Throwable $e) { $raw = md5(uniqid('', true)); }
        setcookie('mimi_guest', $raw, ['expires' => time() + 2592000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
        // PHP chỉ cập nhật header Set-Cookie; ghi lại vào request hiện tại để các
        // lần gọi mimi_data_file() tiếp theo trong cùng request dùng cùng thư mục.
        $_COOKIE['mimi_guest'] = $raw;
    }
    $dir = DIR_DATA . '/.mimi_guests/' . $raw;
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); @chmod($dir, 0775); }
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Không tạo được vùng dữ liệu khách');
    return $dir;
}
function mimi_data_file(string $basename, string $fallback): string {
    $user = mimi_auth_current();
    if ($user) return mimi_user_dir() . '/' . basename($basename);
    if (!IS_CLI) return mimi_guest_dir() . '/' . basename($basename);
    return $fallback;
}
function mimi_with_lock(string $name, callable $callback): mixed {
    $lockPath = mimi_data_file($name . '.lock', DIR_DATA . '/.' . $name . '.lock');
    $dir = dirname($lockPath);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $handle = @fopen($lockPath, 'c');
    if ($handle === false) throw new RuntimeException('Không mở được khóa dữ liệu ' . $name);
    try {
        if (!@flock($handle, LOCK_EX)) throw new RuntimeException('Không khóa được dữ liệu ' . $name);
        try { return $callback(); }
        finally { @flock($handle, LOCK_UN); }
    } finally { @fclose($handle); }
}

/* ============================================================================
 * RUNTIME / LOGGING
 * ==========================================================================*/
const IS_CLI = (PHP_SAPI === 'cli');

$GLOBALS['LOGS'] = [];
$GLOBALS['STREAM'] = false;   // true = stream NDJSON về trình duyệt (console web hiện trực tiếp từng dòng đang chạy)
function logmsg(string $msg): void {
    $line = $msg;
    $GLOBALS['LOGS'][] = $line;
    if (IS_CLI) { fwrite(STDERR, $line . PHP_EOL); }
    // Đẩy ngay từng dòng log về trình duyệt → console hiển thị quá trình code đang làm,
    // không phải chờ chạy xong mới thấy.
    if (!empty($GLOBALS['STREAM'])) {
        echo json_encode(['type' => 'log', 'line' => $line], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @ob_flush(); @flush();
    }
}

/* ============================================================================
 * TIỆN ÍCH CHUNG (port từ Python/JS)
 * ==========================================================================*/
function uuid_hex(): string { // uuid.uuid4().hex
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return bin2hex($d);
}
function uuid_str(): string { // str(uuid.uuid4())
    $h = uuid_hex();
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
/** get_local_time() — bản gốc .py dùng giờ local của máy (GMT+7).
 *  Pin cứng Asia/Ho_Chi_Minh để server UTC không bị lệch 7 tiếng (sign/timestamp bị từ chối). */
function get_local_time(): int {
    return (int)(new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('YmdHis');
}

function ld_pwd_hash(string $password): string { return md5('"'.$password.'"'); }
function fp_pwd_hash(string $password): string { return md5($password); }

function generate_random_password(int $length = 10): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i=0;$i<$length;$i++) $out .= $chars[random_int(0, strlen($chars)-1)];
    return $out;
}

function normalize_android_id(?string $value): string {
    $id = preg_replace('/\s+/', '', trim((string)$value)) ?? '';
    if ($id === '') return '';
    if (!preg_match('/^[a-f0-9]{8,64}$/i', $id)) {
        throw new InvalidArgumentException('Android ID phải là chuỗi hex từ 8 đến 64 ký tự, ví dụ: 795be0a3a0a2edca');
    }
    return strtolower($id);
}
function generate_device_identifiers(?string $android_id = null): array {
    $guid = normalize_android_id($android_id) ?: uuid_hex();
    $device_id_header = "{$guid},Samsung,SM-G9980,{$guid},0XE78GMI9B37NZEJETHI5656MYNOYLNO";
    $mid_body = "{$guid},Samsung,SM-G9980,{$guid}";
    return [$device_id_header, $mid_body];
}

/** json.dumps(..., separators=(',',':')) / JSON.stringify compact, giữ thứ tự key */
function jcompact(array $arr): string {
    return json_encode($arr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/* ============================================================================
 * HTTP HELPER (cURL, hỗ trợ proxy, verify=false giống code gốc)
 * ==========================================================================*/
/** Pool cURL handle theo proxy: tái sử dụng kết nối (keep-alive) giữa các request
 *  cùng proxy — bỏ handshake TCP+TLS lặp lại, request nhanh hơn rõ rệt khi
 *  chạy vòng lặp (snipe thẻ, CPI, polling OTP). Byte gửi/nhận giữ nguyên 100%. */
function http_handle(?string $proxyUrl) {
    $key = $proxyUrl ?? '';
    $ch = $GLOBALS['CURL_POOL'][$key] ?? null;
    if ($ch instanceof CurlHandle) { curl_reset($ch); }
    else { $ch = curl_init(); $GLOBALS['CURL_POOL'][$key] = $ch; }
    return $ch;
}

function safe_upstream_rate_limit(string $url): void {
    static $localLast = [];
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: 'default'));
    // Khoảng nghỉ tối thiểu để giảm burst; đây là bảo vệ ổn định, không phải cơ chế né kiểm tra.
    $gap = str_contains($host, 'temp-mail') ? 0.35 : 0.25;
    $path = sys_get_temp_dir() . '/mimi-upstream-rate.json';
    $lockPath = sys_get_temp_dir() . '/mimi-upstream-rate.lock';
    $lock = @fopen($lockPath, 'c+');
    if ($lock && @flock($lock, LOCK_EX)) {
        $state = [];
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) $state = $decoded;
        $last = (float)($state[$host] ?? 0);
        $wait = $gap - (microtime(true) - $last);
        if ($wait > 0) usleep((int)round($wait * 1000000));
        $state[$host] = microtime(true);
        $fp = @fopen($path, 'c+');
        if ($fp) { @flock($fp, LOCK_EX); @ftruncate($fp, 0); @fwrite($fp, json_encode($state)); @fflush($fp); @flock($fp, LOCK_UN); @fclose($fp); }
        @flock($lock, LOCK_UN); @fclose($lock);
        return;
    }
    if ($lock) @fclose($lock);
    $last = (float)($localLast[$host] ?? 0);
    $wait = $gap - (microtime(true) - $last);
    if ($wait > 0) usleep((int)round($wait * 1000000));
    $localLast[$host] = microtime(true);
}
function proxy_transport_error(string $message): bool {
    return (bool)preg_match('/proxy|connect|tunnel|timed out|connection refused|could not connect|connection reset/i', $message);
}
function upstream_risk_error(string $message): bool {
    return (bool)preg_match('/90001|security risk|tạm dừng thao tác vì tài khoản hoặc thiết bị/i', $message);
}
function proxy_curl_type(string $proxyUrl): int {
    $scheme = strtolower((string)(parse_url($proxyUrl, PHP_URL_SCHEME) ?: 'http'));
    return match ($scheme) {
        'socks4' => CURLPROXY_SOCKS4,
        'socks5' => CURLPROXY_SOCKS5,
        'socks5h' => CURLPROXY_SOCKS5_HOSTNAME,
        default => CURLPROXY_HTTP,
    };
}
/**
 * Pool connection dùng chung cho toàn bộ upstream HTTP.
 * Lưu ý: PHP-FPM/built-in server kết thúc object sau mỗi request; keep-alive
 * giúp tái sử dụng socket trong cùng một action/request, không thể tạo socket
 * vĩnh viễn xuyên nhiều request nếu không dùng worker lâu sống như Swoole/RoadRunner.
 */
final class ApiConnectionPool {
    public function send(string $endpointKey, string $method, string $url, array $headers = [], ?string $body = null, ?string $proxyUrl = null, int $timeout = 30, bool $logTraffic = true): array {
        return http_request_legacy($method, $url, $headers, $body, $proxyUrl, $timeout, $logTraffic);
    }
}
$GLOBALS['API_CONNECTION_POOL'] ??= new ApiConnectionPool();

function http_request_legacy(string $method, string $url, array $headers = [], ?string $body = null, ?string $proxyUrl = null, int $timeout = 30, bool $logTraffic = true): array {
    safe_upstream_rate_limit($url);
    $ch = http_handle($proxyUrl);
    curl_setopt($ch, CURLOPT_URL, $url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
    // Không ghi traffic nhạy cảm (ví dụ API key AI) vào lịch sử log.
    if ($logTraffic) {
        logmsg("  [HTTP] >> {$method} {$url}" . ($proxyUrl ? "   (qua proxy)" : "   (trực tiếp, không proxy)"));
        if ($headers) logmsg("  [HTTP] >> Headers: " . jcompact($headers));
        if ($body !== null && $body !== '') logmsg("  [HTTP] >> Body: " . $body);
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
        CURLOPT_ENCODING       => '', // gzip/deflate/br
        CURLOPT_TCP_NODELAY    => true, // gửi gói nhỏ ngay, không chờ gom (Nagle)
        CURLOPT_NOSIGNAL       => true,
        CURLOPT_FRESH_CONNECT  => false,
        CURLOPT_FORBID_REUSE   => false,
    ]);
    if (defined('CURLOPT_TCP_KEEPALIVE')) {
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        if (defined('CURLOPT_TCP_KEEPIDLE')) curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 30);
        if (defined('CURLOPT_TCP_KEEPINTVL')) curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 10);
    }
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($proxyUrl) {
        $proxyType = proxy_curl_type($proxyUrl);
        curl_setopt_array($ch, [
            CURLOPT_PROXY => $proxyUrl,
            CURLOPT_PROXYTYPE => $proxyType,
            // Proxy HTTP/HTTPS cần CONNECT khi URL đích là HTTPS; SOCKS không dùng cờ này.
            CURLOPT_HTTPPROXYTUNNEL => ($proxyType === CURLPROXY_HTTP),
        ]);
    }
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    // [LOG ĐẦY ĐỦ] Ghi lại response nhận về (cắt bớt nếu quá dài, vd: ảnh captcha base64)
    if ($resp === false) {
        if ($logTraffic) logmsg("  [HTTP] << Status: {$status} | cURL {$errno} | LỖI: {$err}");
        if ($proxyUrl && proxy_transport_error($err)) {
            if ($logTraffic) logmsg('  [PROXY] Kết nối HTTPS qua proxy thất bại; proxy sẽ bị loại khỏi vòng hiện tại.');
        }
    } else {
        $respLog = (string)$resp;
        if (strlen($respLog) > 4000) $respLog = substr($respLog, 0, 4000) . " …[đã cắt bớt " . (strlen((string)$resp) - 4000) . " ký tự]";
        if ($logTraffic) logmsg("  [HTTP] << Status: {$status} | Response: " . $respLog);
        $risk = json_decode((string)$resp, true);
        if (is_array($risk) && upstream_risk_error((string)($risk['code'] ?? '') . ' ' . (string)($risk['msg'] ?? ''))) {
            logmsg('  [SECURITY] Máy chủ trả mã 90001; dừng luồng, không tự thử lại.');
            throw new RuntimeException('Mã 90001: máy chủ tạm dừng thao tác vì tài khoản hoặc thiết bị bị đánh giá rủi ro.');
        }
    }
    // KHÔNG curl_close: giữ handle trong pool để tái sử dụng kết nối (keep-alive)
    // Đánh dấu sức khỏe proxy: lỗi kết nối → bad (×), dùng được → good (tái sử dụng)
    if ($proxyUrl) {
        $info = $GLOBALS['PROXY_URL2RAW'][$proxyUrl] ?? null;
        if ($info) {
            if ($resp === false) proxy_mark_bad($info['raw'], $info['pool']);
            else proxy_mark_good($info['raw'], $info['pool']);
        }
    }
    if ($resp === false) {
        if ($proxyUrl && proxy_transport_error($err)) {
            throw new RuntimeException("HTTP lỗi proxy CONNECT (cURL {$errno}): {$err}");
        }
        throw new RuntimeException("HTTP lỗi: {$err}");
    }
    return [$status, (string)$resp];
}

/** Compatibility wrapper: toàn bộ caller cũ tự động đi qua connection pool. */
function http_request(string $method, string $url, array $headers = [], ?string $body = null, ?string $proxyUrl = null, int $timeout = 30): array {
    $endpointKey = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: 'upstream'));
    return $GLOBALS['API_CONNECTION_POOL']->send($endpointKey, $method, $url, $headers, $body, $proxyUrl, $timeout);
}

function log_json_response(string $label, int $status, string $raw): ?array {
    logmsg("  [JSON LOG] {$label} - HTTP Status: {$status}");
    $data = json_decode($raw, true);
    if (is_array($data)) {
        logmsg("  [JSON LOG] {$label} - Response JSON: " . jcompact($data));
        return $data;
    }
    logmsg("  [JSON LOG] {$label} - Response Text: " . $raw);
    return null;
}

/* ============================================================================
 * PROXY MANAGER — cờ √/× từng chức năng (lưu proxy_config.json)
 * ==========================================================================*/
/** Danh sách chức năng hỗ trợ bật/tắt proxy (mặc định như bên dưới) */
const PROXY_FEATURES = [
    // Module funpass/khoga
    'temp_email'        => ['label' => 'Tạo email ảo (temp-mail)',        'default' => true,  'pool' => 'us'],
    'send_otp'          => ['label' => 'Gửi OTP',                         'default' => true,  'pool' => 'us'],
    'register_funpass'  => ['label' => 'Đăng ký Funpass',                 'default' => true,  'pool' => 'us'],
    'register_ldplayer' => ['label' => 'Đăng ký LDPlayer mới',            'default' => true,  'pool' => 'us'],
    'login_funpass'     => ['label' => 'Đăng nhập Funpass',               'default' => true,  'pool' => 'us'],
    'login_ldplayer'    => ['label' => 'Đăng nhập LDPlayer',              'default' => true,  'pool' => 'us'],
    'cpi_list'          => ['label' => 'Lấy danh sách game CPI',          'default' => true,  'pool' => 'vn'],
    'cpi_claim'         => ['label' => 'Nhận thưởng CPI',                 'default' => true,  'pool' => 'vn'],
    'wallet'            => ['label' => 'Xem ví Funpass',                  'default' => false, 'pool' => 'us'],
    'transfer'          => ['label' => 'Chuyển điểm (detail/execute)',    'default' => true,  'pool' => 'us'],
    // Module muathe
    'mt_login'          => ['label' => '[Mua thẻ] Đăng nhập LDPlayer',    'default' => false, 'pool' => 'us'],
    'mt_common'         => ['label' => '[Mua thẻ] Số dư / đơn hàng',      'default' => false, 'pool' => 'us'],
    'mt_item'           => ['label' => '[Mua thẻ] Danh sách SKU',         'default' => false, 'pool' => 'us'],
    'mt_order'          => ['label' => '[Mua thẻ] Tạo đơn / săn thẻ',     'default' => false, 'pool' => 'us'],
];

/** Cache đọc file theo mtime trong phạm vi 1 tiến trình/request:
 *  tránh đọc + json_decode lặp lại hàng chục lần mỗi action (snipe/CPI/polling),
 *  vẫn tự đọc lại khi file bị sửa từ bên ngoài (mtime đổi). */
function fcache_get(string $key, string $file, callable $loader) {
    $mt = is_file($file) ? (int)@filemtime($file) : -1;
    $c = $GLOBALS['FCACHE'][$key] ?? null;
    if ($c !== null && $c[0] === $mt) return $c[1];
    $data = $loader();
    $GLOBALS['FCACHE'][$key] = [$mt, $data];
    return $data;
}
function fcache_forget(string $key): void { unset($GLOBALS['FCACHE'][$key]); }

function proxy_load_flags(): array {
    $file = mimi_data_file('proxy_config.json', FILE_PROXYCFG); $key = 'flags:' . $file;
    return fcache_get($key, $file, function () use ($file) {
        $flags = [];
        foreach (PROXY_FEATURES as $k => $meta) $flags[$k] = $meta['default'];
        if (is_file($file)) {
            $saved = json_decode((string)file_get_contents($file), true);
            if (is_array($saved)) foreach ($flags as $k => $_) if (array_key_exists($k, $saved)) $flags[$k] = (bool)$saved[$k];
        }
        return $flags;
    });
}
function proxy_save_flags(array $flags): void {
    $file = mimi_data_file('proxy_config.json', FILE_PROXYCFG); $key = 'flags:' . $file;
    $cur = proxy_load_flags();
    foreach ($cur as $k => $_) if (array_key_exists($k, $flags)) $cur[$k] = (bool)$flags[$k];
    file_put_contents($file, json_encode($cur, JSON_PRETTY_PRINT), LOCK_EX);
    fcache_forget($key);
}

function proxy_load_list(string $file): array {
    return fcache_get('list:' . $file, $file, function () use ($file) {
        if (!is_file($file)) return [];
        return array_values(array_filter(array_map('trim', file($file) ?: []), fn($l) => $l !== ''));
    });
}

/** Kiểm tra dạng IP:PORT thuần, ví dụ 42.112.34.34:3434. */
function proxy_is_ipv4_port(string $host, string $port): bool {
    return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && ctype_digit($port) && (int)$port >= 1 && (int)$port <= 65535;
}

/** Chuẩn hóa một dòng proxy để lưu: IP:PORT được giữ ở dạng rõ ràng; các dạng auth/URL cũ vẫn tương thích. */
function proxy_normalize_entry(string $proxy_str): ?string {
    $proxy_str = trim($proxy_str);
    if ($proxy_str === '') return null;
    if (preg_match('/^((?:\\d{1,3}\\.){3}\\d{1,3}):(\\d{1,5})$/', $proxy_str, $m)) {
        if (!proxy_is_ipv4_port($m[1], $m[2])) return null;
        return $m[1] . ':' . (string)(int)$m[2];
    }
    return parse_proxy($proxy_str) !== null ? $proxy_str : null;
}

/** Chuẩn hóa danh sách nhiều dòng; dòng sai được báo rõ thay vì lưu rồi mới lỗi khi chạy. */
function proxy_normalize_text(string $text): array {
    $out = [];
    foreach (preg_split('/\\R/u', $text) ?: [] as $lineNo => $line) {
        $line = trim($line);
        if ($line === '') continue;
        $normalized = proxy_normalize_entry($line);
        if ($normalized === null) {
            throw new RuntimeException('Proxy dòng ' . ((int)$lineNo + 1) . ' không hợp lệ. Dùng IP:PORT, ví dụ 42.112.34.34:3434.');
        }
        if (!in_array($normalized, $out, true)) $out[] = $normalized;
    }
    return $out;
}

/** 'host:port:user:pass' -> 'http://user:pass@host:port' (giống parse_proxy của funpass.py)
 *  MỞ RỘNG thêm các dạng phổ biến:
 *    - http(s)://user:pass@host:port , socks4://..., socks5://..., socks5h://...   (giữ nguyên)
 *    - user:pass@host:port
 *    - host:port:user:pass   (dạng gốc funpass.py)
 *    - user:pass:host:port   (một số nhà cung cấp proxy dùng)
 *    - host:port             (không auth)
 */
function parse_proxy(string $proxy_str): ?string {
    $proxy_str = trim($proxy_str);
    if ($proxy_str === '') return null;
    if (preg_match('#^(https?|socks4|socks5|socks5h)://#i', $proxy_str)) return $proxy_str;
    // Chỉ mã hóa credential khi chứa ký tự làm vỡ URL (@ ? / # khoảng trắng);
    // credential thường giữ nguyên như code gốc.
    $enc = fn(string $s): string => preg_match('#[@/?# ]#', $s) ? rawurlencode($s) : $s;
    if (strpos($proxy_str, '@') !== false) {                 // user:pass@host:port
        $at = strrpos($proxy_str, '@');                      // tách tại '@' cuối (pass có thể chứa '@')
        $auth = substr($proxy_str, 0, $at);
        $hostport = substr($proxy_str, $at + 1);
        $c = strpos($auth, ':');
        if ($c !== false) $auth = $enc(substr($auth, 0, $c)) . ':' . $enc(substr($auth, $c + 1));
        return 'http://' . $auth . '@' . $hostport;
    }
    $parts = explode(':', $proxy_str);
    if (count($parts) === 4) {
        if (is_numeric($parts[1])) { [$host, $port, $user, $pwd] = $parts; }
        elseif (is_numeric($parts[3])) { [$user, $pwd, $host, $port] = $parts; }
        else { [$host, $port, $user, $pwd] = $parts; }
        return 'http://' . $enc($user) . ':' . $enc($pwd) . "@{$host}:{$port}";
    }
    if (count($parts) === 2 && proxy_is_ipv4_port($parts[0], $parts[1])) {
        return "http://{$parts[0]}:" . (string)(int)$parts[1]; // IP:PORT không user/pass
    }
    // Vẫn hỗ trợ hostname:port không auth để không phá cấu hình cũ.
    if (count($parts) === 2 && !preg_match('/^\\d+(?:\\.\\d+){3}$/', $parts[0]) && preg_match('/^[A-Za-z0-9.-]+$/', $parts[0]) && ctype_digit($parts[1]) && (int)$parts[1] >= 1 && (int)$parts[1] <= 65535) {
        return "http://{$parts[0]}:{$parts[1]}";
    }
    return null;
}

/* ----------------------------------------------------------------------------
 * QUẢN LÝ TRẠNG THÁI PROXY (proxy_state.json)
 *  - Proxy đang dùng mà hoạt động bình thường → giữ nguyên, dùng lại (sticky).
 *  - Proxy lỗi kết nối → đánh dấu "bad" (hiển thị × đỏ trong tab Proxy),
 *    loại khỏi vòng sử dụng, tự chuyển sang proxy kế tiếp.
 *  - Bị rate-limit (code 1019/1020) → chỉ chuyển sang proxy kế, KHÔNG đánh dấu bad.
 * ----------------------------------------------------------------------------*/
const FILE_PROXYSTATE = DIR_DATA . "/proxy_state.json";
$GLOBALS['PROXY_URL2RAW'] = [];   // map proxy URL đã parse -> ['raw'=>..., 'pool'=>...]

function pstate_load(): array {
    $file = mimi_data_file('proxy_state.json', FILE_PROXYSTATE); $key = 'pstate:' . $file;
    return fcache_get($key, $file, function () use ($file) {
        if (is_file($file)) {
            $d = json_decode((string)file_get_contents($file), true);
            if (is_array($d)) {
                $d += ['cur' => [], 'bad' => ['us' => [], 'vn' => []], 'last' => ['us' => null, 'vn' => null], 'use_count' => ['us' => 0, 'vn' => 0], 'run_count' => 0];
                $d['bad'] += ['us' => [], 'vn' => []];
                $d['last'] = ($d['last'] ?? []) + ['us' => null, 'vn' => null];
                $d['use_count'] = ($d['use_count'] ?? []) + ['us' => 0, 'vn' => 0];
                return $d;
            }
        }
        return ['cur' => [], 'bad' => ['us' => [], 'vn' => []], 'last' => ['us' => null, 'vn' => null], 'use_count' => ['us' => 0, 'vn' => 0], 'run_count' => 0];
    });
}
function pstate_save(array $st): void {
    $file = mimi_data_file('proxy_state.json', FILE_PROXYSTATE); $key = 'pstate:' . $file;
    file_put_contents($file, json_encode($st, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    fcache_forget($key);
}

/**
 * Chọn proxy pool: xoay chéo US/VN (1US↔2VN, 2US↔1VN…),
 * không trùng proxy đã dùng lần trước trong cùng pool,
 * sau mỗi 2 lần chạy chức năng (run_count) cho phép tái sử dụng lại toàn bộ.
 */
function proxy_pick_sticky(array $list, string $pool): ?string {
    if (!$list) return null;
    $st = pstate_load();
    $st += ['last' => ['us' => null, 'vn' => null], 'use_count' => ['us' => 0, 'vn' => 0], 'run_count' => 0];
    $st['last'] += ['us' => null, 'vn' => null];
    $st['use_count'] += ['us' => 0, 'vn' => 0];

    // Sau 2 lần chạy chức năng bất kỳ → reset "last" để tái sử dụng
    if ((int)($st['run_count'] ?? 0) >= 2) {
        $st['last'] = ['us' => null, 'vn' => null];
        $st['use_count'] = ['us' => 0, 'vn' => 0];
        $st['run_count'] = 0;
        pstate_save($st);
        logmsg("    [PROXY] Đã qua 2 lần chạy → cho phép tái sử dụng lại proxy");
    }

    $bad = $st['bad'][$pool] ?? [];
    $good = array_values(array_filter($list, fn($p) => !in_array($p, $bad, true)));
    if (!$good) {
        // Không tự reset proxy lỗi: tránh lặp vô hạn cùng IP khi CONNECT timeout.
        logmsg("    [PROXY] Không còn proxy khả dụng trong pool={$pool}; hãy kiểm tra proxy hoặc bấm reset trạng thái sau khi thay proxy.");
        return null;
    }
    $n = count($good);
    $i = (int)($st['cur'][$pool] ?? 0);
    if ($i < 0) $i = 0;

    // Xoay chéo: pool VN lệch index so với US (offset = floor(n/2) hoặc 1)
    $offset = 0;
    if ($pool === 'vn' && $n > 1) {
        $offset = max(1, intdiv($n, 2));
        $usCur = (int)($st['cur']['us'] ?? 0);
        $i = $usCur + $offset;
    }

    // Tránh trùng proxy vừa dùng lần trước trong cùng pool
    $last = $st['last'][$pool] ?? null;
    $pick = null;
    for ($try = 0; $try < $n; $try++) {
        $cand = $good[($i + $try) % $n];
        if ($last === null || $cand !== $last || $n === 1) {
            $pick = $cand;
            $i = ($i + $try) % $n;
            break;
        }
    }
    if ($pick === null) $pick = $good[$i % $n];

    $st['cur'][$pool] = $i;
    $st['last'][$pool] = $pick;
    $st['use_count'][$pool] = (int)($st['use_count'][$pool] ?? 0) + 1;
    pstate_save($st);

    $url = parse_proxy($pick);
    if ($url !== null) {
        $GLOBALS['PROXY_URL2RAW'][$url] = ['raw' => $pick, 'pool' => $pool];
    } else {
        logmsg("    [PROXY][!] Không parse được proxy '{$pick}' (pool={$pool}) — định dạng IP:PORT, ví dụ 42.112.34.34:3434, hoặc proxy có xác thực");
        $st['cur'][$pool] = $i + 1;
        pstate_save($st);
        $alt = $good[($i + 1) % $n] ?? null;
        if ($alt && $alt !== $pick) {
            $url2 = parse_proxy($alt);
            if ($url2 !== null) {
                $GLOBALS['PROXY_URL2RAW'][$url2] = ['raw' => $alt, 'pool' => $pool];
                logmsg("    [PROXY] Dùng proxy thay thế: {$alt}");
                return $alt;
            }
        }
        return null;
    }
    logmsg("    [PROXY] Chọn pool={$pool} idx={$i}/{$n}: " . preg_replace('/:[^:@]+@/', ':***@', $pick));
    return $pick;
}

/** Tăng bộ đếm "đã chạy chức năng" — gọi sau mỗi action dùng proxy để sau 2 lần được tái sử dụng */
function proxy_note_run(): void {
    $st = pstate_load();
    $st['run_count'] = (int)($st['run_count'] ?? 0) + 1;
    pstate_save($st);
}

/** Chuyển con trỏ sang proxy kế tiếp (không đánh dấu bad) — dùng khi bị rate-limit */
function proxy_rotate_next(?string $pool = null): void {
    $st = pstate_load();
    foreach (($pool !== null ? [$pool] : ['us', 'vn']) as $p) {
        $st['cur'][$p] = (int)($st['cur'][$p] ?? 0) + 1;
        // Xóa last để lần pick tiếp theo không bị kẹt
        if (isset($st['last'][$p])) $st['last'][$p] = null;
    }
    pstate_save($st);
}

/** Đánh dấu proxy lỗi (không tái sử dụng) + tự chuyển sang proxy kế tiếp.
 *  Gộp đánh dấu + rotate chung 1 lần đọc/ghi file (trước đây 3 lần I/O). */
function proxy_mark_bad(string $raw, string $pool): void {
    $st = pstate_load();
    $bad = $st['bad'][$pool] ?? [];
    $isNew = !in_array($raw, $bad, true);
    if ($isNew) {
        $bad[] = $raw;
        $st['bad'][$pool] = $bad;
    }
    $st['cur'][$pool] = (int)($st['cur'][$pool] ?? 0) + 1;   // như proxy_rotate_next($pool)
    pstate_save($st);
    if ($isNew) logmsg("    [PROXY] × Đánh dấu lỗi, loại khỏi vòng dùng: {$raw}");
}

/** Proxy dùng lại thành công → gỡ dấu lỗi (nếu từng bị đánh dấu) */
function proxy_mark_good(string $raw, string $pool): void {
    $st = pstate_load();
    $bad = $st['bad'][$pool] ?? [];
    if (in_array($raw, $bad, true)) {
        $st['bad'][$pool] = array_values(array_diff($bad, [$raw]));
        pstate_save($st);
    }
}

/** Chuyển proxy US -> VN bằng thay 'country-us' -> 'country-vn' (giống get_vn_proxy_from_us) */
function get_vn_proxy_from_us(string $proxy_str): string {
    if (stripos($proxy_str, 'country-us') !== false) return str_ireplace('country-us', 'country-vn', $proxy_str);
    return $proxy_str;
}

/* ----------------------------------------------------------------------------
 * OVERRIDE PROXY THEO MỤC — nút "Áp Dụng Proxy" ở đầu mỗi giao diện (web).
 * Chỉ ảnh hưởng request hiện tại, KHÔNG ghi đè proxy_config.json.
 * $GLOBALS['PROXY_OVERRIDE']: null = theo file config | array feature => bool
 * ----------------------------------------------------------------------------*/
$GLOBALS['PROXY_OVERRIDE'] = null;

/** scope 'fp' = mọi chức năng Funpass/Khoga; 'mt' = mọi chức năng Mua thẻ */
function proxy_apply_scope(string $scope, bool $on): void {
    $map = [];
    foreach (PROXY_FEATURES as $k => $_m) {
        $isMt = str_starts_with($k, 'mt_');
        if (($scope === 'mt' && $isMt) || ($scope === 'fp' && !$isMt)) $map[$k] = $on;
    }
    $GLOBALS['PROXY_OVERRIDE'] = $map;
}

/** Cờ √/× hiệu lực của 1 chức năng = proxy_config.json + override "Áp Dụng Proxy" của request hiện tại.
 *  Dùng để quyết định TẮT proxy hoàn toàn (không gửi request nào qua proxy) khi người dùng tắt. */
function proxy_feature_enabled(string $feature): bool {
    $flags = proxy_load_flags();
    $ov = $GLOBALS['PROXY_OVERRIDE'] ?? null;
    if (is_array($ov) && array_key_exists($feature, $ov)) $flags[$feature] = (bool)$ov[$feature];
    return !empty($flags[$feature]);
}

/**
 * Lấy proxy URL cho 1 chức năng theo cờ √/×.
 * $forceProxy: khi auto-loop funpass truyền proxy cụ thể của chu kỳ.
 */
function proxy_for(string $feature, ?string $forceProxy = null): ?string {
    $flags = proxy_load_flags();
    $ov = $GLOBALS['PROXY_OVERRIDE'] ?? null;
    if (is_array($ov) && array_key_exists($feature, $ov)) $flags[$feature] = (bool)$ov[$feature];
    if (empty($flags[$feature])) return null; // × -> không dùng proxy
    if ($forceProxy) return parse_proxy($forceProxy);
    $pool = PROXY_FEATURES[$feature]['pool'] ?? 'us';
    if ($pool === 'vn') {
        $vn = proxy_load_list(FILE_PROXY_VN);
        if (!$vn) { // tự chuyển US -> VN giống code gốc
            $us = proxy_load_list(FILE_PROXY);
            $vn = array_map('get_vn_proxy_from_us', $us);
        }
        $pick = proxy_pick_sticky($vn, 'vn');
        return $pick !== null ? parse_proxy($pick) : null;
    }
    $us = proxy_load_list(FILE_PROXY);
    $pick = proxy_pick_sticky($us, 'us');
    return $pick !== null ? parse_proxy($pick) : null;
}

/* ============================================================================
 * LƯU TÀI KHOẢN — accounts.json: [{email, password, type: ldplayer|funpass, saved, created_at}]
 * ==========================================================================*/
function acc_load(): array {
    $file = mimi_data_file('accounts.json', FILE_ACCOUNTS); $key = 'accounts:' . $file;
    return fcache_get($key, $file, function () use ($file) {
        if (!is_file($file)) return [];
        $d = json_decode((string)file_get_contents($file), true);
        if (!is_array($d)) return [];
        $changed = false;
        foreach ($d as &$row) {
            if (is_array($row) && empty($row['created_at']) && !empty($row['saved'])) { $row['created_at'] = (string)$row['saved']; $changed = true; }
        }
        unset($row);
        if ($changed) { try { acc_save_all(array_values($d)); } catch (Throwable $e) {} }
        return array_values($d);
    });
}
function saved_android_id_for_email(string $email, string $type = ''): string {
    $email = strtolower(trim($email));
    if ($email === '') return '';
    foreach (acc_load() as $a) {
        if ($type !== '' && ($a['type'] ?? '') !== $type) continue;
        if (strcasecmp(trim((string)($a['email'] ?? '')), $email) !== 0) continue;
        $id = (string)($a['androidid'] ?? ($a['android_id'] ?? ($a['androidId'] ?? '')));
        if ($id !== '') return $id;
    }
    return '';
}
function stable_android_id_for_login(string $email, string $type, ?string $requested = null): string {
    $saved = saved_android_id_for_email($email, $type);
    if ($saved !== '') return normalize_android_id($saved);
    $provided = normalize_android_id($requested);
    if ($provided !== '') return $provided;
    throw new InvalidArgumentException('Tài khoản chưa có Android ID ổn định. Hãy chọn đúng tài khoản đã lưu hoặc nhập Android ID của hồ sơ thiết bị.');
}
function acc_save_all(array $accs): void {
    $file = mimi_data_file('accounts.json', FILE_ACCOUNTS);
    // Kiểm tra quyền ghi thư mục của tài khoản Mimi (tránh lỗi khi nhiều user ghi cùng lúc)
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_writable($dir)) {
        // Thử nới quyền thư mục nếu có thể (một số host cho phép)
        @chmod($dir, 0775);
        if (!is_writable($dir)) {
            throw new RuntimeException("Không ghi được accounts.json — thư mục chứa api.php không có quyền ghi (is_writable=false). Hãy chmod 775 hoặc chown user chạy PHP cho: " . $dir);
        }
    }
    // Nếu file đã tồn tại nhưng không writable → thử chmod
    if (is_file($file) && !is_writable($file)) {
        @chmod($file, 0664);
    }
    $json = json_encode(array_values($accs), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $ok = @file_put_contents($file, $json, LOCK_EX);
    if ($ok === false) {
        // Fallback: ghi qua temp rồi rename (một số FS hạn chế LOCK_EX)
        $tmp = $file . '.tmp.' . getmypid();
        $ok2 = @file_put_contents($tmp, $json);
        if ($ok2 !== false && @rename($tmp, $file)) {
            @chmod($file, 0664);
            fcache_forget('accounts:' . $file);
            return;
        }
        @unlink($tmp);
        throw new RuntimeException("Không ghi được accounts.json — kiểm tra quyền ghi của thư mục chứa api.php (" . $dir . ")");
    }
    @chmod($file, 0664);
    fcache_forget('accounts:' . $file);
}
/** Thêm/cập nhật 1 tài khoản (khớp theo email + type).
 *  $extra: uid, token, androidid, temp_token, serverTimeOffset, created_at — lưu kèm để mục Tài Khoản hiển thị đủ MẪU. */
function acc_upsert(string $email, string $password, string $type, array $extra = []): array {
    return mimi_with_lock('accounts', fn() => acc_upsert_unlocked($email, $password, $type, $extra));
}
function acc_upsert_unlocked(string $email, string $password, string $type, array $extra = []): array {
    $email = trim($email);
    if ($email === '') throw new RuntimeException("Email không được để trống");
    if (!in_array($type, ['ldplayer', 'funpass'], true)) $type = 'ldplayer';
    $accs = acc_load();
    $now = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('d/m/Y H:i:s');
    $uid = isset($extra['uid']) ? (string)$extra['uid'] : '';
    $row = ['email' => $email, 'password' => $password, 'type' => $type, 'saved' => $now, 'created_at' => $now];
    foreach (['uid','token','androidid','temp_token','serverTimeOffset','device_id_header','created_at'] as $k) {
        if (array_key_exists($k, $extra)) $row[$k] = $extra[$k];
    }
    // Khớp trong CÙNG type: ưu tiên uid, sau đó email — không cho 2 mail/uid trùng trong 1 loại
    $updated = false;
    foreach ($accs as &$a) {
        if (($a['type'] ?? '') !== $type) continue; // tuyệt đối không đụng loại kia
        $sameUid = ($uid !== '' && isset($a['uid']) && (string)$a['uid'] === $uid);
        $sameEmail = (strcasecmp((string)($a['email'] ?? ''), $email) === 0);
        if ($sameUid || $sameEmail) {
            // Giữ type cũ; cập nhật field
            $createdAt = (string)($a['created_at'] ?? ($a['saved'] ?? $now));
            $a = array_merge($a, $row);
            $a['type'] = $type;
            $a['saved'] = $now;
            $a['created_at'] = $createdAt;
            $updated = true;
            break;
        }
    }
    unset($a);
    if (!$updated) $accs[] = $row;
    // Lọc sạch: trong cùng type, mỗi email / uid chỉ 1 dòng (giữ dòng mới nhất)
    $seenEmail = []; $seenUid = []; $clean = [];
    for ($i = count($accs) - 1; $i >= 0; $i--) {
        $a = $accs[$i];
        $t = $a['type'] ?? 'ldplayer';
        $em = strtolower(trim((string)($a['email'] ?? '')));
        $u = (string)($a['uid'] ?? '');
        $keyE = $t . '|e|' . $em;
        $keyU = $t . '|u|' . $u;
        if ($em !== '' && isset($seenEmail[$keyE])) continue;
        if ($u !== '' && isset($seenUid[$keyU])) continue;
        if ($em !== '') $seenEmail[$keyE] = true;
        if ($u !== '') $seenUid[$keyU] = true;
        $clean[] = $a;
    }
    $accs = array_reverse($clean);
    acc_save_all($accs);
    return $accs;
}
function sign_in_marker_file(): string {
    return mimi_data_file('.signin_called.json', DIR_DATA . '/.signin_called.json');
}
function sign_in_marker_key(array $account): string {
    $uid = trim((string)($account['uid'] ?? ($account['userId'] ?? '')));
    $email = strtolower(trim((string)($account['email'] ?? ($account['username'] ?? ''))));
    return hash('sha256', ($uid !== '' ? 'uid:' . $uid : 'email:' . $email));
}
function sign_in_markers(): array {
    $file = sign_in_marker_file();
    if (!is_file($file)) return [];
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
function sign_in_mark_called(array $account, array $result): void {
    $file = sign_in_marker_file();
    $markers = sign_in_markers();
    $markers[sign_in_marker_key($account)] = [
        'called_at' => (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('d/m/Y H:i:s'),
        'status' => (string)($result['status'] ?? 'False'),
        'response' => (string)($result['response'] ?? ''),
        'code' => $result['code'] ?? null,
    ];
    @file_put_contents($file, json_encode($markers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($file, 0660);
}
function sign_in_headers(array $st, string $bodyStr = ''): array {
    $timestamp = (string)(int)(microtime(true) * 1000);
    $requestId = generate_request_id();
    return [
        'host' => 'api.ldcode.gg', 'language' => 'vn', 'version-name' => '1.0.3',
        'time_zone' => '7', 'requestid' => $requestId, 'client' => 'android',
        'uid' => (string)($st['uid'] ?? ''), 'unionappid' => '666600006',
        'ticket' => $requestId, 'channel' => 'google',
        'user-agent' => 'Mozilla/5.0 (Linux; Android 15; Pixel 4 XL Build/BP1A.250505.005; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/150.0.7871.183 Mobile Safari/537.36 Easyfun',
        'content-type' => 'application/json', 'version-code' => '147', 'accept' => '*/*',
        'macid' => (string)($st['androidid'] ?? ''), 'timestamp' => $timestamp,
        'server-ts-offset' => (string)($st['serverTimeOffset'] ?? 0), 'token' => (string)($st['token'] ?? ''),
        'appid' => '2003', 'xx-ld-tags' => 'source:ldplayer-app-h5',
        'origin' => 'https://h5-app.ldplayer.net', 'x-requested-with' => 'net.ldplayer.gamepal',
        'referer' => 'https://h5-app.ldplayer.net/', 'sign' => build_sign(APP_SECRET, (int)$timestamp, $requestId, $bodyStr),
    ];
}
function sign_in_once(array $account, ?string $proxyForce = null): array {
    $uid = trim((string)($account['uid'] ?? ($account['userId'] ?? '')));
    $token = trim((string)($account['token'] ?? ''));
    $android = trim((string)($account['androidid'] ?? ($account['androidId'] ?? ($account['android_id'] ?? ''))));
    if ($uid === '' || $token === '') return [];
    $markers = sign_in_markers();
    $key = sign_in_marker_key($account);
    if (isset($markers[$key])) {
        $previous = $markers[$key];
        return ['status' => 'Same', 'called' => false, 'cached' => true, 'code' => $previous['code'] ?? null, 'response' => (string)($previous['response'] ?? '')];
    }
    $st = ['uid' => $uid, 'token' => $token, 'androidid' => $android, 'serverTimeOffset' => (int)($account['serverTimeOffset'] ?? 0)];
    $proxy = proxy_for('login_ldplayer', $proxyForce);
    try {
        [$status, $raw] = http_request('POST', LDCODE_URL . '/my/safe/signIn', sign_in_headers($st, ''), '', $proxy, 30);
        $decoded = json_decode($raw, true);
        $response = is_array($decoded) ? (string)($decoded['data'] ?? '') : '';
        $ok = $status === 200 && is_array($decoded) && (int)($decoded['code'] ?? 0) === 200 && !empty($decoded['ok']) && $response !== '';
        $result = ['status' => $ok ? 'True' : 'False', 'called' => true, 'code' => $decoded['code'] ?? $status, 'response' => $response];
        sign_in_mark_called($account, $result);
        return $result;
    } catch (Throwable $e) {
        $result = ['status' => 'False', 'called' => true, 'response' => '', 'error' => $e->getMessage()];
        sign_in_mark_called($account, $result);
        return $result;
    }
}
function sign_in_accounts_for_action(string $action, array $in, array $res): array {
    $out = [];
    $add = static function ($value) use (&$out): void {
        if (is_array($value) && !empty($value['uid']) && !empty($value['token'])) $out[] = $value;
    };
    $d = is_array($res['data'] ?? null) ? $res['data'] : [];
    $add($d['funpass'] ?? null); $add($d['ldplayer'] ?? null); $add($d);
    if (is_array($res['session'] ?? null)) $add($res['session']);
    $add(['uid' => $in['uid'] ?? ($in['funpassUid'] ?? ''), 'token' => $in['token'] ?? ($in['funpassToken'] ?? ''), 'androidid' => $in['androidid'] ?? ($in['android_id'] ?? '')]);
    $add(['uid' => $in['ldUid'] ?? '', 'token' => $in['ldToken'] ?? '', 'androidid' => $in['androidid'] ?? ($in['android_id'] ?? '')]);
    foreach (['uid','token','androidid','android_id','email','username','password','serverTimeOffset'] as $key) {
        if (array_key_exists($key, $in)) $d[$key] = $in[$key];
    }
    $add($d);
    return $out;
}
function preflight_sign_in(string $action, array $in): ?array {
    if (in_array($action, ['ping','proxy_get','auth_status','acc_list','acc_save','acc_delete','runlog_list','runlog_clear','proxy_save','proxy_add','proxy_reset_state','ai_chat','ai_context','auth_login','auth_logout'], true)) return null;
    $accounts = sign_in_accounts_for_action($action, $in, []);
    if (str_starts_with($action, 'mt_')) {
        $st = mt_state();
        if (!empty($st['uid']) && !empty($st['token'])) $accounts[] = $st;
    }
    $accounts = array_values(array_reduce($accounts, function(array $carry, array $item): array {
        $key = sign_in_marker_key($item); $carry[$key] = $item; return $carry;
    }, []));
    if (!$accounts) return null;
    $results = [];
    foreach ($accounts as $account) {
        $result = sign_in_once($account, is_string($in['proxy'] ?? null) ? $in['proxy'] : null);
        if ($result) $results[] = ['uid'=>(string)($account['uid'] ?? ''), 'username'=>(string)($account['email'] ?? ($account['username'] ?? '')), 'signIn'=>$result];
    }
    return count($results) === 1 ? $results[0]['signIn'] : $results;
}
function attach_sign_in_result(string $action, array $in, array &$res): void {
    if (!$res || in_array($action, ['ping','proxy_get','auth_status','acc_list','acc_save','acc_delete','runlog_list','runlog_clear','proxy_save','proxy_add','proxy_reset_state','ai_chat','ai_context'], true)) return;
    $accounts = sign_in_accounts_for_action($action, $in, $res);
    if (!$accounts) return;
    $results = [];
    foreach ($accounts as $account) {
        $result = sign_in_once($account, is_string($in['proxy'] ?? null) ? $in['proxy'] : null);
        if ($result) $results[] = ['uid' => (string)($account['uid'] ?? ''), 'username' => (string)($account['email'] ?? ($account['username'] ?? '')), 'signIn' => $result];
    }
    if ($results) $res['signIn'] = count($results) === 1 ? $results[0]['signIn'] : $results;
}

function acc_update_coin(string $email, $coin): void {
    $email = trim($email); if ($email === '' || $coin === null || $coin === '') return;
    mimi_with_lock('accounts', function() use ($email, $coin): void {
        $accs = acc_load(); $changed = false;
        foreach ($accs as &$a) {
            if (strcasecmp((string)($a['email'] ?? ($a['username'] ?? '')), $email) !== 0) continue;
            $a['coin'] = is_numeric($coin) ? (int)$coin : (string)$coin; $a['balance'] = $a['coin']; $changed = true; break;
        }
        unset($a); if ($changed) acc_save_all($accs);
    });
}
function acc_delete(int $idx): array {
    return mimi_with_lock('accounts', fn() => acc_delete_unlocked($idx));
}
function acc_delete_unlocked(int $idx): array {
    $accs = acc_load();
    if (isset($accs[$idx])) array_splice($accs, $idx, 1);
    acc_save_all($accs);
    return $accs;
}

/** TỰ NHẬN DẠNG tài khoản từ response sau mỗi lần chạy rồi ghi vào mục Tài Khoản (accounts.json).
 *  CHỈ LƯU KHI TẠO TÀI KHOẢN MỚI (create), KHÔNG lưu khi chỉ login / xem số dư.
 *  Flow full: chỉ lưu Funpass mới tạo; LDPlayer chỉ lưu khi mode=auto (tạo mới cùng email). */
function acc_autodetect_save(string $action, array $res): array {
    if (empty($res['ok'])) return [];
    $d = $res['data'] ?? null;
    if (!is_array($d)) return [];
    $cands = [];
    $saved = [];   // [ [type, accArray], ... ]
    switch (true) {
        // Chỉ create — bỏ login
        case $action === 'fp_create_funpass':
            $cands[] = ['funpass', $d]; break;
        case $action === 'fp_create_ldplayer':
            $cands[] = ['ldplayer', $d]; break;
        // Flow: Funpass luôn là mới tạo; LDPlayer chỉ khi có password (tạo mới)
        case in_array($action, ['flow_khoga_full', 'flow_funpass_once'], true):
            if (is_array($d['funpass'] ?? null)) {
                $cands[] = ['funpass', $d['funpass']];
            }
            if (is_array($d['ldplayer'] ?? null)) {
                $ld = $d['ldplayer'];
                if (!empty($ld['password'])) {
                    $cands[] = ['ldplayer', $ld];
                }
            }
            break;
        default:
            return []; // login / mt_login / action khác → KHÔNG tự lưu
    }
    foreach ($cands as [$type, $a]) {
        $email = trim((string)($a['email'] ?? ($a['username'] ?? '')));
        $uid   = (string)($a['uid'] ?? '');
        $token = (string)($a['token'] ?? '');
        if ($email === '' || $uid === '' || $token === '') continue;
        try {
            acc_upsert($email, (string)($a['password'] ?? ''), $type, [
                'uid'              => $uid,
                'token'            => $token,
                'androidid'        => (string)($a['androidid'] ?? ($a['androidId'] ?? ($a['android_id'] ?? ''))),
                'temp_token'       => (string)($a['temp_token'] ?? ($a['tempToken'] ?? ($a['shortToken'] ?? ($a['short_token'] ?? '')))),
                'serverTimeOffset' => (int)($a['serverTimeOffset'] ?? 0),
            ]);
            foreach (acc_load() as $stored) {
                if (($stored['type'] ?? '') === $type && strcasecmp((string)($stored['email'] ?? ''), $email) === 0) { $saved[] = $stored; break; }
            }
            logmsg("  [TÀI KHOẢN] Đã tự lưu tài khoản MỚI vào mục Tài Khoản ({$type}): {$email} | UID: {$uid}");
        } catch (Throwable $e) { /* lỗi lưu acc không được làm hỏng action chính */ }
    }
    return $saved;
}

/* ============================================================================
 * LỊCH SỬ LOG — run_logs.json: tối đa 20 phiên {time, action, label, ok, logs}
 * Mỗi lần chạy 1 chức năng = 1 phiên log; quá 20 → xoá phiên CŨ NHẤT rồi ghi phiên mới (FIFO).
 * ==========================================================================*/
/**
 * Ghi lịch sử log theo PHIÊN:
 * - Cùng action liên tiếp (vd: tạo Funpass ×20) → gộp 1 phiên, count tăng, logs nối thêm.
 * - flow_funpass_once / flow_khoga_full liên tiếp → gộp 1 phiên (2 chu kỳ = 1 phiên).
 * - Đổi action khác → mở phiên mới.
 * Tối đa 20 phiên (FIFO).
 */
function runlog_add(string $action, string $label, bool $ok, array $logs): void {
    mimi_with_lock('run_logs', fn() => runlog_add_unlocked($action, $label, $ok, $logs));
}
function runlog_add_unlocked(string $action, string $label, bool $ok, array $logs): void {
    if ($action === '' || in_array($action, ['ping','proxy_get','proxy_add','mt_session','acc_list','acc_save','acc_delete','runlog_list','runlog_clear','proxy_save','proxy_reset_state','create_empty_file','modules_list','modules_remove','ai_context','ai_chat','mimi_memory_get','mimi_memory_save','mimi_chats_get','mimi_chats_save','mimi_models_get','mimi_model_add','mimi_model_delete','mimi_chats_clear'], true)) return;
    $now = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('d/m/Y H:i:s');
    $lbl = $label !== '' ? $label : $action;
    $file = mimi_data_file('run_logs.json', FILE_RUNLOGS);
    $all = [];
    if (is_file($file)) {
        $d = json_decode((string)file_get_contents($file), true);
        if (is_array($d)) $all = $d;
    }
    $last = !empty($all) ? $all[count($all) - 1] : null;
    // Gộp nếu cùng action với phiên cuối
    if ($last && ($last['action'] ?? '') === $action) {
        $cnt = (int)($last['count'] ?? 1) + 1;
        $last['count'] = $cnt;
        $last['time'] = $now; // cập nhật thời gian lần mới nhất
        $last['ok'] = $ok && !empty($last['ok']); // cả phiên ok chỉ khi mọi lần đều ok
        $last['label'] = $lbl . ' ×' . $cnt;
        // Nối log (giới hạn kích thước: giữ tối đa ~800 dòng/phiên)
        $merged = array_merge(array_values($last['logs'] ?? []), array_values($logs));
        if (count($merged) > 800) $merged = array_slice($merged, -800);
        $last['logs'] = $merged;
        $all[count($all) - 1] = $last;
    } else {
        $all[] = [
            'time'   => $now,
            'action' => $action,
            'label'  => $lbl,
            'ok'     => $ok,
            'count'  => 1,
            'logs'   => array_values($logs),
        ];
    }
    if (count($all) > 20) $all = array_slice($all, -20);
    file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    fcache_forget('runlogs:' . $file);
}
function runlog_load(): array {
    $file = mimi_data_file('run_logs.json', FILE_RUNLOGS); $key = 'runlogs:' . $file;
    return fcache_get($key, $file, function () use ($file) {
        if (!is_file($file)) return [];
        $d = json_decode((string)file_get_contents($file), true);
        return is_array($d) ? array_values($d) : [];
    });
}

/** Module PHP độc lập (tab dưới Proxy) — chỉ metadata; api.php không đọc nội dung file module */
function modules_load(): array {
    $file = mimi_data_file('custom_modules.json', FILE_MODULES); $key = 'modules:' . $file;
    return fcache_get($key, $file, function () use ($file) {
    if (!is_file($file)) return [];
    $d = json_decode((string)file_get_contents($file), true);
    if (!is_array($d)) return [];
    // Chỉ giữ module còn file trên đĩa
    $out = [];
    foreach ($d as $m) {
        if (!is_array($m) || empty($m['file'])) continue;
        $fn = basename((string)$m['file']);
        if (is_file(mimi_data_file($fn, DIR_DATA . '/' . $fn))) {
            $m['file'] = $fn;
            $out[] = $m;
        }
    }
    return array_values($out);
    });
}
function modules_save(array $mods): void {
    $file = mimi_data_file('custom_modules.json', FILE_MODULES);
    $dir = dirname($file); if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode(array_values($mods), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $ok = @file_put_contents($file, $json, LOCK_EX);
    if ($ok === false) {
        $tmp = $file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json) !== false && @rename($tmp, $file)) {
            @chmod($file, 0664);
            fcache_forget('modules:' . $file);
            return;
        }
        @unlink($tmp);
        throw new RuntimeException('Không ghi được custom_modules.json — kiểm tra quyền ghi thư mục');
    }
    @chmod($file, 0664);
    fcache_forget('modules:' . $file);
}
function module_normalize_filename(string $raw): string {
    // Tên file từ điện thoại có thể chứa đường dẫn Windows, khoảng trắng, ngoặc hoặc Unicode.
    // Chuẩn hóa về tên file hợp lệ thay vì từ chối ngay khiến upload báo “Tên file không hợp lệ”.
    $raw = str_replace('\\', '/', trim($raw));
    $name = basename($raw);
    $name = preg_replace('/[^\\p{L}\\p{N}_.-]+/u', '_', $name) ?? '';
    $name = trim($name, " .-_");
    if ($name === '' || $name === '.' || $name === '..') throw new RuntimeException('Tên file không hợp lệ');
    if (!str_ends_with(strtolower($name), '.php')) $name .= '.php';
    if (!preg_match('/^[\\p{L}\\p{N}_.-]+$/u', $name)) throw new RuntimeException('Tên file PHP không hợp lệ');
    $reserved = ['api.php','api3.php','config.json','accounts.json','custom_modules.json','proxy_config.json','proxy_state.json','run_logs.json','cards.txt','proxy.txt','proxy_vn.txt'];
    if (in_array(strtolower($name), $reserved, true) || strtolower($name) === strtolower(basename(__FILE__))) {
        throw new RuntimeException("Không được dùng tên file hệ thống: {$name}");
    }
    return $name;
}
function module_register(string $name, string $label): array {
    $label = trim($label) ?: pathinfo($name, PATHINFO_FILENAME);
    $mods = array_values(array_filter(modules_load(), fn($m) => ($m['file'] ?? '') !== $name));
    $mods[] = ['file' => $name, 'label' => $label, 'created' => (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('d/m/Y H:i:s')];
    modules_save($mods);
    return $mods;
}

/* ============================================================================
 * SỐ HỌC 64-BIT (port LCG BigInt của muathe.js — KHÔNG cần GMP)
 * JS: s = (s * 6364136223846793005 + 1442695040888963407) & (2^64-1)
 * Biểu diễn: mảng 4 limb 16-bit little-endian.
 * ==========================================================================*/
const LCG_A = [0x7F2D, 0x4C95, 0xF42D, 0x5851]; // 6364136223846793005
const LCG_C = [0x814F, 0xF767, 0x7B7E, 0x1405]; // 1442695040888963407

function u64_from_int(int $v): array {
    $limbs = [];
    for ($i=0;$i<4;$i++) { $limbs[$i] = $v & 0xFFFF; $v = intdiv($v, 0x10000); }
    return $limbs;
}
function u64_lcg(array $s): array {
    // nhân 4 limb (mod 2^64)
    $r = [0,0,0,0];
    for ($i=0;$i<4;$i++) {
        $carry = 0;
        for ($j=0;$j<4-$i;$j++) {
            $t = $r[$i+$j] + $s[$i]*LCG_A[$j] + $carry;
            $r[$i+$j] = $t & 0xFFFF;
            $carry = intdiv($t, 0x10000);
        }
    }
    // cộng C
    $carry = 0;
    for ($i=0;$i<4;$i++) {
        $t = $r[$i] + LCG_C[$i] + $carry;
        $r[$i] = $t & 0xFFFF;
        $carry = intdiv($t, 0x10000);
    }
    return $r;
}

/** scrambleKey(key, timestamp) — port nguyên bản từ muathe.js */
function scramble_key(string $key, int $timestamp): string {
    $r = str_split($key);
    $n = count($r);
    $s = u64_from_int($timestamp);
    for ($i = $n - 1; $i > 0; $i--) {
        $s = u64_lcg($s);
        $hi = ($s[3] << 16) | $s[2];               // s >> 32
        $idx = $hi % ($i + 1);
        $tmp = $r[$i]; $r[$i] = $r[$idx]; $r[$idx] = $tmp;
    }
    return implode('', $r);
}

/** encryptData(dataStr, timestamp, keyStr) — AES-192-ECB, PKCS7 (mặc định) */
function encrypt_data(string $dataStr, int $timestamp, string $keyStr): string {
    $key = scramble_key($keyStr, $timestamp); // 24 ký tự -> 24 byte = key 192-bit
    $raw = openssl_encrypt($dataStr, 'AES-192-ECB', $key, OPENSSL_RAW_DATA);
    if ($raw === false) throw new RuntimeException('AES encrypt thất bại (cần extension openssl)');
    return base64_encode($raw);
}

/** decryptData(encryptedData, timestamp, keyStr) */
function decrypt_data(string $encryptedData, int $timestamp, string $keyStr): ?string {
    $key = scramble_key($keyStr, $timestamp);
    $raw = openssl_decrypt(base64_decode($encryptedData), 'AES-192-ECB', $key, OPENSSL_RAW_DATA);
    return $raw === false ? null : $raw;
}

/** buildSign(appKey, timestamp, requestId, bodyStr) — muathe.js */
function build_sign(string $appKey, int $timestamp, string $requestId, string $bodyStr): string {
    $scrambled = scramble_key($appKey, $timestamp);
    return md5($scrambled . $timestamp . $requestId . $bodyStr);
}

/** generateRequestId() — muathe.js: 3 nhóm hex (5 ký tự) nối bằng '-' */
function generate_request_id(): string {
    $t = fn() => dechex(random_int(0x10000, 0x1FFFF));
    return $t() . '-' . $t() . '-' . $t();
}
/** transfer req_id — python: uuid4().hex[:5] x3 nối '-' */
function gen_transfer_req_id(): string {
    return substr(uuid_hex(),0,5).'-'.substr(uuid_hex(),0,5).'-'.substr(uuid_hex(),0,5);
}
/** generateLoginTimestamp() — muathe.js: YmdHis theo GMT+7 */
function generate_login_timestamp(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('YmdHis');
}
/** generateDeviceId() — muathe.js: randomBytes(18).hex */
function generate_device_id(?string $android_id = null): string { return normalize_android_id($android_id) ?: bin2hex(random_bytes(18)); }

/* ============================================================================
 * CAPTCHA OCR — tương đương auto_solve_captcha (pytesseract)
 * Phương án: tiền xử lý bằng GD (xám + ngưỡng 140) rồi gọi binary `tesseract`.
 * Nếu server không có tesseract/GD -> trả '' và flow sẽ hỏi nhập tay.
 * ==========================================================================*/
function tesseract_available(): bool {
    static $ok = null;
    if ($ok === null) {
        $out = @shell_exec('command -v tesseract 2>/dev/null');
        $ok = is_string($out) && trim($out) !== '';
    }
    return $ok;
}

function auto_solve_captcha(string $b64_image): string {
    // Tái hiện đúng pipeline của funpass.py/khoga.py:
    //   PIL open -> convert('L') -> point(255 nếu >140 else 0) -> tesseract --oem 3 --psm 7 + whitelist
    // Tối ưu thêm: phóng to ảnh 3x (captcha 180x80 quá nhỏ tesseract hay đọc sai),
    // thử lần lượt nhiều PSM (7 giống gốc, rồi 8/13/6) lấy kết quả hợp lệ đầu tiên.
    if (!tesseract_available()) {
        logmsg("    [!] Server KHÔNG có tesseract → không tự giải được captcha, sẽ hỏi nhập tay.");
        logmsg("        Cài bằng: apt install tesseract-ocr   (hoặc: yum install tesseract)");
        return '';
    }
    $imgBytes = base64_decode($b64_image);
    if ($imgBytes === false) return '';
    $tmpIn  = tempnam(sys_get_temp_dir(), 'cap') . '.png';
    $tmpOut = tempnam(sys_get_temp_dir(), 'capo');
    try {
        $prepared = false;
        if (extension_loaded('gd')) {
            $im = @imagecreatefromstring($imgBytes);
            if ($im !== false) {
                $w = imagesx($im); $h = imagesy($im);
                $scale = 3; // phóng to giúp tesseract nhận diện tốt hơn (không đổi thuật toán gốc)
                $gray = imagecreatetruecolor($w * $scale, $h * $scale);
                imagecopyresampled($gray, $im, 0, 0, 0, 0, $w * $scale, $h * $scale, $w, $h);
                // convert('L') + point(p > 140 ? 255 : 0) — giống hệt bản .py
                for ($y = 0; $y < $h * $scale; $y++) for ($x = 0; $x < $w * $scale; $x++) {
                    $rgb = imagecolorat($gray, $x, $y);
                    $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                    $lum = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
                    $c = ($lum > 140) ? imagecolorallocate($gray, 255, 255, 255) : imagecolorallocate($gray, 0, 0, 0);
                    imagesetpixel($gray, $x, $y, $c);
                }
                imagepng($gray, $tmpIn);
                imagedestroy($gray); imagedestroy($im);
                $prepared = true;
            }
        }
        if (!$prepared) file_put_contents($tmpIn, $imgBytes);

        // --oem 3 -c tessedit_char_whitelist=... giống custom_config của bản .py
        $whitelist = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        foreach ([7, 8, 13, 6] as $psm) {   // psm 7 là của bản gốc; 8/13/6 là fallback tăng tỉ lệ đọc đúng
            $cmd = 'tesseract ' . escapeshellarg($tmpIn) . ' ' . escapeshellarg($tmpOut)
                 . ' --oem 3 --psm ' . $psm . ' -c tessedit_char_whitelist=' . $whitelist . ' 2>/dev/null';
            @shell_exec($cmd);
            $txt = is_file($tmpOut . '.txt') ? (string)file_get_contents($tmpOut . '.txt') : '';
            $txt = str_replace([' ', "\n", "\r"], '', trim($txt));   // strip().replace(" ","").replace("\n","") như gốc
            if ($txt !== '') {
                logmsg("    [OCR] psm={$psm} → '{$txt}'");
                return $txt;
            }
        }
        logmsg("    [!] OCR không đọc được ký tự nào");
        return '';
    } catch (Throwable $e) {
        logmsg("    [!] Lỗi OCR: " . $e->getMessage());
        return '';
    } finally {
        @unlink($tmpIn); @unlink($tmpOut); @unlink($tmpOut . '.txt');
    }
}

/* ############################################################################
 * MODULE 1 + 2: FUNPASS.PY / KHOGA.PY  (Python -> PHP, giữ nguyên logic)
 * ##########################################################################*/

function build_fp_request(int $ts, string $req_id, array $body, string $device_id_header, ?string $captcha_id = null, ?string $captcha_data = null): array {
    $raw_body = jcompact($body);
    $sign_str = "{$raw_body};{$ts};{$req_id};{$device_id_header};" . APP_KEY_FP;
    $sign = strtoupper(md5($sign_str));
    $headers = [
        "protocol_version" => "1",
        "sdk_platform"     => "Android",
        "sdk_version"      => "1.0.6",
        "app_id"           => "6668",
        "ext_app_id"       => "666800001",
        "channel_id"       => "10100",
        "sub_channel_id"   => "10101",
        "device_id"        => $device_id_header,
        "request_id"       => $req_id,
        "timestamp"        => (string)$ts,
        "language_code"    => "en-US",
        "time_zone"        => "7",
        "sign"             => $sign,
        "content-type"     => "application/json;charset=utf-8",
        "accept-encoding"  => "gzip",
        "user-agent"       => "okhttp/4.11.0",
    ];
    if ($captcha_id && $captcha_data) {
        $headers["captcha_id"] = $captcha_id;
        $headers["captcha_data"] = $captcha_data;
    }
    return [$headers, $raw_body];
}

function build_ld_request(int $ts, string $req_id, array $body, string $device_id_header, ?string $captcha_id = null, ?string $captcha_data = null): array {
    $raw_body = jcompact($body);
    $sign_str = "{$raw_body};{$ts};{$req_id};{$device_id_header};" . APP_KEY_LD;
    $sign = strtoupper(md5($sign_str));
    $headers = [
        "protocol_version" => "1",
        "sdk_platform"     => "Android",
        "sdk_version"      => "3.1.3",
        "app_id"           => "6666",
        "ext_app_id"       => "100031",
        "channel_id"       => "100000",
        "sub_channel_id"   => "100001",
        "device_id"        => $device_id_header,
        "request_id"       => $req_id,
        "timestamp"        => (string)$ts,
        "language_code"    => "en-US",
        "time_zone"        => "7",
        "sign"             => $sign,
        "content-type"     => "application/json;charset=utf-8",
        "accept-encoding"  => "gzip",
        "user-agent"       => "okhttp/4.12.0",
    ];
    if ($captcha_id && $captcha_data) {
        $headers["captcha_id"] = $captcha_id;
        $headers["captcha_data"] = $captcha_data;
    }
    return [$headers, $raw_body];
}

function build_paysdk_headers(array $body, string $ts, string $req_id, string $device_id_header): array {
    $raw_body = jcompact($body);
    $sign_str = "{$raw_body};{$ts};{$req_id};{$device_id_header};" . APP_KEY_FP;
    $sign = strtoupper(md5($sign_str));
    $headers = [
        "sdk_version"      => "1.0.12",
        "protocol_version" => "1",
        "sdk_platform"     => "Android",
        "app_id"           => "6668",
        "channel_id"       => "10100",
        "sub_channel_id"   => "10101",
        "device_id"        => $device_id_header,
        "request_id"       => $req_id,
        "timestamp"        => $ts,
        "language_code"    => "en-US",
        "time_zone"        => "7",
        "Sign"             => $sign,
        "content-type"     => "application/json;charset=utf-8",
        "accept-encoding"  => "gzip",
        "user-agent"       => "okhttp/4.11.0",
    ];
    return [$headers, $raw_body];
}

/** create_temp_email(proxy_dict) — feature: temp_email */
function create_temp_email(?string $proxyForce = null): array {
    logmsg("  Tạo Email ảo...");
    $proxy = proxy_for('temp_email', $proxyForce);
    $tries = 0;
    while ($tries++ < 15) {
        try {
            [$st, $raw] = http_request('POST', BASE_TEMP . "/email/new", HEADERS_TEMP,
                jcompact(["min_name_length" => 10, "max_name_length" => 10]), $proxy, 30);
            if ($st === 200) {
                $data = json_decode($raw, true);
                $email = $data["email"] ?? null;
                $token = $data["token"] ?? null;
                if ($email && (str_ends_with($email, "@gmeenramy.com") || str_ends_with($email, "@olipii.com"))) {
                    logmsg("      [!] Bỏ qua: {$email}");
                    continue;
                }
                if ($email && $token) {
                    logmsg("      OK {$email}");
                    logmsg("      Temp token nhận về: {$token}");
                    return [$email, $token];
                }
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            logmsg("      [!] Lỗi tạo email: " . $msg);
            if (upstream_risk_error($msg)) throw $e;
            if ($proxy !== null && proxy_transport_error($msg)) {
                // Không lặp cùng proxy hỏng. Nếu flow chỉ định proxy cụ thể thì dừng rõ ràng.
                if ($proxyForce !== null) {
                    throw new RuntimeException('Proxy được chỉ định không tạo được đường hầm HTTPS CONNECT. Kiểm tra proxy còn hoạt động, đúng IP:PORT và có đúng loại/xác thực.');
                }
                $nextProxy = proxy_for('temp_email');
                if ($nextProxy === null) throw new RuntimeException('Không còn proxy khả dụng để tạo email.');
                $proxy = $nextProxy;
                logmsg('      [PROXY] Đã chuyển sang proxy khác sau lỗi CONNECT; không thử lại proxy cũ.');
                continue;
            }
            sleep(2);
            continue;
        }
        throw new RuntimeException("Không thể tạo email (HTTP {$st})");
    }
    throw new RuntimeException("Không thể tạo email sau {$tries} lần thử");
}

/** request_otp(...) — feature: send_otp */
function request_otp(string $email, string $device_id_header, string $mid_body, bool $is_funpass = true, ?string $proxyForce = null): bool {
    logmsg("  Gửi OTP...");
    $proxy = proxy_for('send_otp', $proxyForce);
    $ts = get_local_time();
    $req_id = uuid_hex();

    if ($is_funpass) {
        $body = [
            "email" => $email, "sendType" => "reg", "appId" => "6668",
            "languageCode" => "en-US", "timeZone" => "Asia/Ho_Chi_Minh", "mid" => $mid_body,
        ];
        [$headers, $payload] = build_fp_request($ts, $req_id, $body, $device_id_header);
    } else {
        $body = [
            "appId" => "6666", "sendType" => "reg", "timeZone" => "Asia/Saigon",
            "mid" => $mid_body, "languageCode" => "en-US", "email" => $email, "subAppId" => "100031",
        ];
        [$headers, $payload] = build_ld_request($ts, $req_id, $body, $device_id_header);
    }
    // Bị rate-limit (1019: chờ 1 phút / 1020: chờ 1 giờ) → đổi proxy kế tiếp rồi thử lại, tối đa 3 lần
    for ($retry = 1; $retry <= 3; $retry++) {
        [$st, $raw] = http_request('POST', BASE_LD . "/user/sendEmail", $headers, $payload, $proxy, 30);
        $result = log_json_response("sendEmail", $st, $raw);
        if ($st === 200 && $result && ($result["code"] ?? null) === 200) {
            logmsg("      OK Đã gửi OTP tới {$email}");
            return true;
        }
        $code = is_array($result) ? ($result["code"] ?? null) : null;
        if (($code === 1019 || $code === 1020) && $retry < 3) {
            logmsg("      [!] Bị giới hạn tần suất (code {$code}) → chuyển sang proxy kế tiếp, thử lại ({$retry}/2)...");
            proxy_rotate_next('us');
            $proxy = proxy_for('send_otp', $proxyForce);
            sleep(2);
            continue;
        }
        throw new RuntimeException("Gửi OTP thất bại: " . jcompact($result ?? ["raw" => $raw]));
    }
    throw new RuntimeException("Gửi OTP thất bại sau nhiều lần thử");
}

/** get_otp_from_inbox(...) — polling, filter funpass/ldplayer (dùng chung proxy temp_email) */
function get_otp_from_inbox(string $email, string $token, ?string $filter_type = null, int $timeout = 90, ?string $proxyForce = null, array $resumeContext = []): string {
    $filter_name = $filter_type === "funpass" ? "Funpass" : ($filter_type === "ldplayer" ? "LDPlayer" : "tất cả");
    logmsg("  Chờ OTP từ {$filter_name}...");
    // Web dùng deadline monotonic để không bị kéo dài bởi time() làm tròn giây.
    $manualDeadline = !IS_CLI ? microtime(true) + 6.0 : null;
    $proxy = proxy_for('temp_email', $proxyForce);
    $startedAt = microtime(true);
    $start = time();
    $seen = [];
    while ($manualDeadline !== null ? microtime(true) < $manualDeadline : time() - $start < $timeout) {
        if ($manualDeadline !== null && microtime(true) >= $manualDeadline) {
            throw new OtpRequiredException($resumeContext + ['email' => $email, 'temp_token' => $token, 'type' => $filter_type], $filter_name);
        }
        $headers = HEADERS_TEMP + ["Authorization" => "Bearer {$token}"];
        try {
            // Không để một lần CONNECT chặn quá lâu rồi làm trễ hộp OTP web.
            $pollTimeout = $manualDeadline === null ? 30 : max(1, min(1, (int)ceil($manualDeadline - microtime(true))));
            [$st, $raw] = http_request('GET', BASE_TEMP . "/email/{$email}/messages", $headers, null, $proxy, $pollTimeout);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (upstream_risk_error($msg)) throw $e;
            if ($proxy !== null && proxy_transport_error($msg)) {
                if ($proxyForce !== null) {
                    throw new RuntimeException('Proxy được chỉ định bị lỗi HTTPS CONNECT khi đọc hộp thư.');
                }
                $nextProxy = proxy_for('temp_email');
                if ($nextProxy !== null) $proxy = $nextProxy;
                else throw new RuntimeException('Không còn proxy khả dụng để đọc hộp thư.');
            }
            if (IS_CLI) fwrite(STDOUT, ".");
            sleep(3);
            continue;
        }
        if ($st === 200) {
            $msgs = json_decode($raw, true);
            if (is_array($msgs) && (isset($msgs[0]) || $msgs === [])) { /* danh sách trực tiếp */ }
            elseif (is_array($msgs)) { $msgs = $msgs["messages"] ?? []; }
            else { $msgs = []; }
            foreach ($msgs as $msg) {
                $mid = $msg["id"] ?? null;
                if ($mid === null || isset($seen[$mid])) continue;
                $seen[$mid] = true;
                $full_content = json_encode($msg, JSON_UNESCAPED_UNICODE);
                $subject = $msg["subject"] ?? "";
                if ($filter_type === "funpass") {
                    if (strpos($subject, "EasyFun") === false && strpos($subject, "Account Verification") === false) continue;
                    if (strpos($subject, "LDPlayer") !== false) continue;
                } elseif ($filter_type === "ldplayer") {
                    if (strpos($subject, "LDPlayer") === false && strpos($subject, "Account Verification") === false) continue;
                    if (strpos($subject, "EasyFun") !== false) continue;
                }
                if (preg_match('/\b(\d{6})\b/', $full_content, $m)) {
                    logmsg("      OK OTP: {$m[1]} (subject: {$subject})");
                    return $m[1];
                }
            }
        }
        if ($manualDeadline !== null && microtime(true) >= $manualDeadline) {
            throw new OtpRequiredException($resumeContext + ['email' => $email, 'temp_token' => $token, 'type' => $filter_type], $filter_name);
        }
        if (IS_CLI) fwrite(STDOUT, ".");
        if ($manualDeadline === null) {
            sleep(3);
        } else {
            $remain = $manualDeadline - microtime(true);
            if ($remain > 0) usleep((int)min(200000, max(20000, round($remain * 1000000))));
        }
    }
    if ($manualDeadline !== null) {
        throw new OtpRequiredException($resumeContext + ['email' => $email, 'temp_token' => $token, 'type' => $filter_type], $filter_name);
    }
    throw new RuntimeException("Không nhận được OTP từ {$filter_name}");
}

/** login_funpass(email, password) — feature: login_funpass */
function login_funpass(string $email, string $password_raw, ?string $proxyForce = null, ?string $android_id = null): array {
    logmsg("  Đăng nhập Funpass...");
    $proxy = proxy_for('login_funpass', $proxyForce);
    $password_hash = fp_pwd_hash($password_raw);
    $android_id = stable_android_id_for_login($email, 'funpass', $android_id);
    [$device_id_header, $mid_body] = generate_device_identifiers($android_id);
    $ts = get_local_time();
    $req_id = uuid_hex();
    $body = [
        "loginMode" => "USERNAME", "auth" => $password_hash, "userName" => $email,
        "appId" => "6668", "mid" => $mid_body, "mainChannelId" => "10100",
        "subChannelId" => "10101", "code" => "", "isCoolingOffPeriod" => false,
    ];
    [$headers, $payload] = build_fp_request($ts, $req_id, $body, $device_id_header);
    $headers["channel_id"] = "10100";
    $headers["sub_channel_id"] = "10101";
    [$st, $raw] = http_request('POST', BASE_LD . "/user/login", $headers, $payload, $proxy, 30);
    $data = log_json_response("funpass login", $st, $raw);
    if ($st === 200 && $data && ($data["code"] ?? null) === 200 && !empty($data["data"])) {
        $u = $data["data"];
        logmsg("  OK Đăng nhập Funpass thành công! UID: {$u['uid']}");
        return [
            "uid" => $u["uid"] ?? null, "token" => $u["token"] ?? null,
            "email" => $email, "password" => $password_raw,
            "device_id_header" => $device_id_header,
            "android_id" => explode(",", $device_id_header)[0],
        ];
    }
    throw new RuntimeException("Đăng nhập Funpass thất bại: " . jcompact($data ?? ["raw" => $raw]));
}

/** login_ldplayer(email, password) — feature: login_ldplayer (bản funpass/khoga) */
function login_ldplayer(string $email, string $password_raw, ?string $proxyForce = null, ?string $android_id = null): array {
    logmsg("  Đăng nhập LDPlayer...");
    $proxy = proxy_for('login_ldplayer', $proxyForce);
    $password_hash = ld_pwd_hash($password_raw);
    $android_id = stable_android_id_for_login($email, 'ldplayer', $android_id);
    [$device_id_header, $mid_body] = generate_device_identifiers($android_id);
    $ts = get_local_time();
    $req_id = uuid_hex();
    $body = [
        "loginMode" => "USERNAME", "email" => $email, "username" => $email,
        "auth" => $password_hash, "pwd" => $password_hash, "isCoolingOffPeriod" => "false",
        "mainChannelId" => "100000", "subChannelId" => "100001",
        "phoneBrand" => "Samsung", "phoneModel" => "SM-G9980",
        "appId" => "6666", "subAppId" => "100031", "timeZone" => "Asia/Saigon", "mid" => $mid_body,
    ];
    [$headers, $payload] = build_ld_request($ts, $req_id, $body, $device_id_header);
    [$st, $raw] = http_request('POST', BASE_LD . "/user/login", $headers, $payload, $proxy, 30);
    $data = log_json_response("ldplayer login", $st, $raw);
    if ($st === 200 && $data && ($data["code"] ?? null) === 200 && !empty($data["data"])) {
        $u = $data["data"];
        logmsg("  OK Đăng nhập LDPlayer thành công! UID: {$u['uid']}");
        return [
            "uid" => $u["uid"] ?? null, "token" => $u["token"] ?? null,
            "email" => $email, "password" => $password_raw,
            "device_id_header" => $device_id_header,
            "android_id" => explode(",", $device_id_header)[0],
        ];
    }
    throw new RuntimeException("Đăng nhập LDPlayer thất bại: " . jcompact($data ?? ["raw" => $raw]));
}

/**
 * create_funpass_account() — feature: register_funpass
 * $manualCaptcha = ['captcha_id'=>..., 'captcha_data'=>...] khi nhập tay từ web/CLI.
 * Nếu server trả captcha mà không giải được tự động -> ném CaptchaRequiredException.
 */
class CaptchaRequiredException extends RuntimeException {
    public string $captchaId; public string $captchaImage; public array $context;
    public function __construct(string $captchaId, string $captchaImage, array $context = []) {
        parent::__construct("Yêu cầu nhập captcha thủ công");
        $this->captchaId = $captchaId; $this->captchaImage = $captchaImage; $this->context = $context;
    }
}
class OtpRequiredException extends RuntimeException {
    public array $context;
    public string $stage;
    public function __construct(array $context = [], string $stage = 'OTP') {
        parent::__construct("Chưa nhận được OTP sau thời gian chờ");
        $this->context = $context;
        $this->stage = $stage;
    }
}
function validate_manual_otp(mixed $value): string {
    $otp = preg_replace('/\s+/', '', trim((string)$value)) ?? '';
    if (!preg_match('/^\d{6}$/', $otp)) {
        throw new InvalidArgumentException('OTP phải gồm đúng 6 chữ số.');
    }
    return $otp;
}

function create_funpass_account(?string $proxyForce = null, ?array $manualCaptcha = null, ?array $prevCtx = null, ?string $android_id = null): array {
    logmsg("[TẠO FUNPASS]");
    $proxy = proxy_for('register_funpass', $proxyForce);
    // Cho phép dùng lại context khi resubmit captcha từ web
    if ($prevCtx && !empty($prevCtx['email'])) {
        $email = $prevCtx['email']; $temp_token = $prevCtx['temp_token'] ?? '';
        $device_id_header = $prevCtx['device_id_header']; $mid_body = $prevCtx['mid_body'];
        $password_raw = $prevCtx['password']; $otp = validate_manual_otp($prevCtx['otp'] ?? '');
    } else {
        [$device_id_header, $mid_body] = generate_device_identifiers($android_id);
        $password_raw = generate_random_password(10);
        [$email, $temp_token] = create_temp_email($proxyForce);
        request_otp($email, $device_id_header, $mid_body, true, $proxyForce);
        $otpContext = ['email' => $email, 'temp_token' => $temp_token, 'device_id_header' => $device_id_header, 'mid_body' => $mid_body, 'password' => $password_raw, 'type' => 'funpass'];
        $otp = get_otp_from_inbox($email, $temp_token, "funpass", 90, $proxyForce, $otpContext);
    }

    logmsg("  Đăng ký Funpass...");
    $ts = get_local_time();
    $req_id = uuid_hex();
    $password_hash = fp_pwd_hash($password_raw);
    $body = [
        "loginMode" => "EMAIL", "email" => $email, "auth" => $otp, "pwd" => $password_hash,
        "code" => "", "isCoolingOffPeriod" => "false", "mainChannelId" => "10100",
        "subChannelId" => "10101", "phoneBrand" => "Samsung", "phoneModel" => "SM-G9980",
        "appId" => "6668", "timeZone" => "Asia/Ho_Chi_Minh", "mid" => $mid_body,
    ];

    $captcha_id = $manualCaptcha['captcha_id'] ?? null;
    $captcha_data = $manualCaptcha['captcha_data'] ?? null;
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        [$headers, $payload] = build_fp_request($ts, $req_id, $body, $device_id_header, $captcha_id, $captcha_data);
        [$st, $raw] = http_request('POST', BASE_LD . "/user/login", $headers, $payload, $proxy, 30);
        $data = log_json_response("fp register", $st, $raw);
        $code = $data["code"] ?? null;
        if ($code === 200 && !empty($data["data"])) {
            $u = $data["data"];
            logmsg("      OK UID: {$u['uid']}");
            return [
                "email" => $email, "password" => $password_raw,
                "uid" => $u["uid"], "token" => $u["token"],
                "short_token" => (string)($u["shortToken"] ?? ""),
                "android_id" => explode(",", $device_id_header)[0],
                "device_id_header" => $device_id_header,
                "temp_token" => $temp_token,
            ];
        } elseif ($code === 1001) {
            $info = $data["data"] ?? [];
            $captcha_id = $info["captchaId"] ?? null;
            $b64 = $info["captchaData"] ?? "";
            logmsg("      [!] Captcha (thử {$attempt}/5)...");
            $captcha_data = auto_solve_captcha($b64);
            if ($captcha_data === '') {
                // không giải được tự động -> hỏi người dùng
                throw new CaptchaRequiredException((string)$captcha_id, (string)$b64, [
                    'email' => $email, 'temp_token' => $temp_token,
                    'device_id_header' => $device_id_header, 'mid_body' => $mid_body,
                    'password' => $password_raw, 'otp' => $otp, 'type' => 'funpass',
                ]);
            }
            continue;
        } else {
            throw new RuntimeException("Đăng ký thất bại: " . jcompact($data ?? ["raw" => $raw]));
        }
    }
    throw new RuntimeException("Vượt quá số lần thử Captcha");
}

/** create_ldplayer_account(email, temp_token) — feature: register_ldplayer */
function create_ldplayer_account(string $email, string $temp_token, ?string $proxyForce = null, ?array $manualCaptcha = null, ?array $prevCtx = null, ?string $android_id = null, ?array $flowFunpass = null): array {
    logmsg("[TẠO LDPLAYER]");
    $proxy = proxy_for('register_ldplayer', $proxyForce);
    if ($prevCtx && !empty($prevCtx['email'])) {
        $email = $prevCtx['email']; $temp_token = $prevCtx['temp_token'] ?? '';
        $device_id_header = $prevCtx['device_id_header']; $mid_body = $prevCtx['mid_body'];
        $password_raw = $prevCtx['password']; $otp = validate_manual_otp($prevCtx['otp'] ?? '');
    } else {
        $password_raw = generate_random_password(10);
        [$device_id_header, $mid_body] = generate_device_identifiers($android_id);
        request_otp($email, $device_id_header, $mid_body, false, $proxyForce);
        $otpContext = ['email' => $email, 'temp_token' => $temp_token, 'device_id_header' => $device_id_header, 'mid_body' => $mid_body, 'password' => $password_raw, 'type' => 'ldplayer'];
        if (is_array($flowFunpass) && !empty($flowFunpass['uid']) && !empty($flowFunpass['token'])) $otpContext['funpass_account'] = $flowFunpass;
        $otp = get_otp_from_inbox($email, $temp_token, "ldplayer", 90, $proxyForce, $otpContext);
    }

    logmsg("  Đăng ký LDPlayer...");
    $ts = get_local_time();
    $req_id = uuid_hex();
    $password_hash = ld_pwd_hash($password_raw);
    $body = [
        "mainChannelId" => "100000", "auth" => $otp, "timeZone" => "Asia/Saigon",
        "mid" => $mid_body, "loginMode" => "EMAIL", "isCoolingOffPeriod" => "false",
        "phoneModel" => "SM-G9980", "phoneBrand" => "Samsung", "appId" => "6666",
        "pwd" => $password_hash, "subChannelId" => "100001", "email" => $email, "subAppId" => "100031",
    ];

    $captcha_id = $manualCaptcha['captcha_id'] ?? null;
    $captcha_data = $manualCaptcha['captcha_data'] ?? null;
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        [$headers, $payload] = build_ld_request($ts, $req_id, $body, $device_id_header, $captcha_id, $captcha_data);
        [$st, $raw] = http_request('POST', BASE_LD . "/user/login", $headers, $payload, $proxy, 30);
        $data = log_json_response("ld register", $st, $raw);
        $code = $data["code"] ?? null;
        if ($code === 200 && !empty($data["data"])) {
            $u = $data["data"];
            logmsg("      OK UID: {$u['uid']}");
            return [
                "email" => $email, "password" => $password_raw,
                "uid" => $u["uid"], "token" => $u["token"],
                "short_token" => (string)($u["shortToken"] ?? ""),
                "android_id" => explode(",", $device_id_header)[0],
                "device_id_header" => $device_id_header,
                "temp_token" => $temp_token,
            ];
        } elseif ($code === 1001) {
            $info = $data["data"] ?? [];
            $captcha_id = $info["captchaId"] ?? null;
            $b64 = $info["captchaData"] ?? "";
            logmsg("      [!] Captcha (thử {$attempt}/5)...");
            $captcha_data = auto_solve_captcha($b64);
            if ($captcha_data === '') {
                throw new CaptchaRequiredException((string)$captcha_id, (string)$b64, [
                    'email' => $email, 'temp_token' => $temp_token,
                    'device_id_header' => $device_id_header, 'mid_body' => $mid_body,
                    'password' => $password_raw, 'otp' => $otp, 'type' => 'ldplayer',
                ]);
            }
            continue;
        } else {
            throw new RuntimeException("Đăng ký LDPlayer thất bại: " . jcompact($data ?? ["raw" => $raw]));
        }
    }
    throw new RuntimeException("Vượt quá số lần thử Captcha LDPlayer");
}

/** get_cpi_ad_list() — feature: cpi_list */
function get_cpi_ad_list(string $uid, string $device_id_header, string $android_id, string $advert_id, ?string $proxyForce = null): array {
    logmsg("  Lấy danh sách game CPI...");
    $proxy = proxy_for('cpi_list', $proxyForce);
    $ts = (string)get_local_time();
    $req_id = uuid_hex();
    $body = ["uniqueId" => $advert_id, "extUniqueId" => (string)$uid, "deviceId" => $android_id];
    [$headers, $raw_body] = build_paysdk_headers($body, $ts, $req_id, $device_id_header);
    [$st, $raw] = http_request('POST', PAYSDK_URL . "/client/cpi/ad/list", $headers, $raw_body, $proxy, 30);
    $data = log_json_response("cpi/ad/list", $st, $raw);
    if ($st === 200 && $data && ($data["code"] ?? null) === 200) {
        $ads = $data["data"]["ads"] ?? [];
        logmsg("  Tìm thấy " . count($ads) . " game");
        return $ads;
    }
    return [];
}

/** claim_cpi_direct() — feature: cpi_claim
 *  Giảm rủi ro detect "direct claim": delay ngẫu nhiên trước claim, log rõ lỗi server.
 *  (Server có thể kiểm playtime/install thật — claim thẳng vẫn có tỷ lệ fail/ban.) */
function claim_cpi_direct(string $package_name, string $uid, string $device_id_header, string $android_id, string $advert_id, ?string $proxyForce = null): array {
    $proxy = proxy_for('cpi_claim', $proxyForce);
    // Delay 1–2.5s trước mỗi claim (theo yêu cầu)
    usleep(random_int(1000000, 2500000));
    $ts = (string)get_local_time();
    $req_id = uuid_hex();
    $body = [
        "uniqueId" => $advert_id, "extUniqueId" => (string)$uid, "deviceId" => $android_id,
        "partner" => "appsflyer", "regionCode" => "vn", "packageName" => $package_name,
    ];
    [$headers, $raw_body] = build_paysdk_headers($body, $ts, $req_id, $device_id_header);
    [$st, $raw] = http_request('POST', PAYSDK_URL . "/client/cpi/award/receive", $headers, $raw_body, $proxy, 30);
    $data = log_json_response("cpi/award/receive", $st, $raw);
    if ($st === 200 && $data && ($data["code"] ?? null) === 200) {
        $balance = $data["data"]["balance"] ?? null;
        return [true, $balance ? (int)$balance : null];
    }
    // Rate-limit / risk → xoay proxy (không đánh bad)
    $code = (int)($data["code"] ?? 0);
    if (in_array($code, [1019, 1020, 429, 403], true)) {
        logmsg("    [CPI] Risk/rate-limit code={$code} → xoay proxy");
        proxy_rotate_next('vn');
    }
    return [false, null];
}

/** get_wallet_info() — feature: wallet
 *  KHÔNG gọi cph.funpg.net (DNS chết). Thử paysdk/api-app; fail → 0.
 *  Điểm thật để transfer lấy từ transfer_detail (sourceDiamond). */
function get_wallet_info(string $uid, string $token, ?string $proxyForce = null): int {
    logmsg("  Lấy thông tin ví...");
    if ($uid === '' || $token === '') {
        logmsg("  [!] Thiếu uid/token — số dư = 0");
        return 0;
    }
    $proxy = proxy_for('wallet', $proxyForce);
    $headers = ["user-agent" => "okhttp/4.11.0", "content-type" => "application/json"];
    // Không gọi cph.funpg.net (DNS chết). Chỉ thử endpoint còn sống.
    foreach (WALLET_FALLBACKS as $i => $base) {
        $url = $base . (str_contains($base, '?') ? '&' : '?') . "uid=" . urlencode($uid) . "&token=" . urlencode($token);
        try {
            [$st, $raw] = http_request('POST', $url, $headers, null, $proxy, 15);
            $data = null;
            if ($st === 200 && $raw !== '') {
                $data = json_decode($raw, true);
            }
            if (is_array($data)) {
                $code = $data["code"] ?? null;
                if ($code === 0 || $code === 200) {
                    $coin = $data["data"]["usableCoin"] ?? $data["data"]["balance"] ?? $data["data"]["coin"] ?? $data["data"]["num"] ?? null;
                    if ($coin !== null) {
                        logmsg("  Số dư ví: " . (int)$coin . " điểm");
                        return (int)$coin;
                    }
                }
            }
            // HTTP lỗi / code lạ → thử endpoint tiếp (không spam log DNS)
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (upstream_risk_error($msg)) throw $e;
            // Ẩn lỗi DNS chết / resolve host — không đẩy ra UI
            if (stripos($msg, 'Could not resolve host') !== false || stripos($msg, 'resolve host') !== false) {
                continue;
            }
            logmsg("  [!] Ví endpoint #" . ($i + 1) . " lỗi: " . $msg);
            continue;
        }
    }
    logmsg("  Số dư ví: không lấy được (endpoint cũ chết) — coi 0; điểm thật xem transfer_detail");
    return 0;
}

/** run_cpi_tasks(funpass_acc) — giống code gốc */
function run_cpi_tasks(array $funpass_acc, ?string $proxyForce = null): int {
    logmsg("============================================================");
    logmsg("  LÀM NHIỆM VỤ CPI");
    logmsg("============================================================");
    $uid = (string)$funpass_acc["uid"];
    $android_id = $funpass_acc["android_id"];
    $advert_id = uuid_str();
    $device_id_header = $funpass_acc["device_id_header"];

    $ads = get_cpi_ad_list($uid, $device_id_header, $android_id, $advert_id, $proxyForce);
    if (!$ads) { logmsg("  Không có game CPI!"); $GLOBALS['CPI_STATS'] = ['done' => 0, 'total' => 0, 'points' => 0]; return 0; }

    $success_count = 0; $total_reward = 0;
    $n = count($ads);
    foreach ($ads as $idx => $ad) {
        $i = $idx + 1;
        $package_name = $ad["packageName"] ?? "";
        $award = (int)($ad["awardAmount"] ?? 30);
        logmsg("  [{$i}/{$n}] " . ($ad["titleName"] ?? ""));
        [$success, $reward] = claim_cpi_direct($package_name, $uid, $device_id_header, $android_id, $advert_id, $proxyForce);
        if ($success) {
            $success_count++;
            $reward = $reward ?: $award + 10;
            $total_reward += $reward;
            logmsg("    OK +{$reward} điểm");
        } else {
            logmsg("    Thất bại (có thể server kiểm playtime/install thật)");
        }
        // Delay 2–3.5s giữa các game
        if ($i < $n) {
            $gap = random_int(2000, 3500);
            usleep($gap * 1000);
        }
    }
    logmsg("  Hoàn thành: {$success_count}/{$n} game");
    logmsg("  Tổng điểm: {$total_reward}");
    // Chờ 2–4.5s settle trước transfer
    if ($success_count > 0) {
        $waitMs = random_int(2000, 4500);
        logmsg("  Chờ " . round($waitMs / 1000, 1) . "s để điểm CPI settle...");
        usleep($waitMs * 1000);
    }
    $GLOBALS['CPI_STATS'] = ['done' => $success_count, 'total' => $n, 'points' => $total_reward];
    return $total_reward;
}

/** 4 dòng tóm tắt cuối flow — đúng 100% mẫu spec:
 *  Hoàn thành: x/y game | Tổng điểm: n | số dư hiện tại: n điểm | Đã kiếm được: n điểm */
function panel_summary_lines(?array $cpi, int $balance_after, int $balance_before = 0): array {
    $sum = [];
    if (is_array($cpi)) {
        $sum[] = "Hoàn thành: {$cpi['done']}/{$cpi['total']} game";
        $sum[] = "Tổng điểm: {$cpi['points']}";
    }
    $sum[] = "số dư hiện tại: {$balance_after} điểm";
    $sum[] = "Đã kiếm được: " . ($balance_after - $balance_before) . " điểm";
    return $sum;
}

/** In khối kết quả tổng hợp cuối console; không hiển thị temp token/secret nội bộ.
 *  + các dòng tóm tắt CPI ngay bên dưới. */
function log_result_block(array $acc, array $sum): void {
    $block = [
        'uid'              => (string)($acc['uid'] ?? ''),
        'token'            => (string)($acc['token'] ?? ''),
        'androidid'        => (string)($acc['android_id'] ?? ($acc['androidid'] ?? '')),
        'username'         => (string)($acc['email'] ?? ($acc['username'] ?? '')),
        'password'         => (string)($acc['password'] ?? ''),
        'serverTimeOffset' => 0,
    ];
    $j = json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $j = preg_replace('/^ {4}/m', '  ', (string)$j);   // JSON_PRETTY_PRINT của PHP thụt 4 spaces → mẫu dùng 2 spaces
    foreach (explode("\n", (string)$j) as $ln) logmsg($ln);
    foreach ($sum as $ln) logmsg($ln);
}

/** transfer_execute() — feature: transfer
 *  Trước khi execute: gọi detail để kiểm tra sourceDiamond > 0 và isExecute=false.
 *  Tránh lỗi 500 "no resources to transfer" (điểm = 0 / đã chuyển / chưa settle sau CPI). */
function transfer_execute(string $funpass_uid, string $funpass_token, string $ldplayer_uid, string $ldplayer_token, ?string $proxyForce = null): ?array {
    logmsg("  Chuyển điểm từ Funpass sang LDPlayer...");
    // 1) Kiểm tra tài nguyên trước
    $detail = transfer_detail($funpass_uid, $funpass_token, $ldplayer_uid, $ldplayer_token, $proxyForce);
    $src = (int)($detail["sourceDiamond"] ?? 0);
    $already = (bool)($detail["isExecute"] ?? false);
    if ($already) {
        logmsg("  [!] Điểm đã được chuyển trước đó (isExecute=true) — bỏ qua execute.");
        return $detail;
    }
    if ($src <= 0) {
        logmsg("  [!] Không có điểm để chuyển (sourceDiamond={$src}). Có thể CPI chưa settle — chờ 2–4.5s rồi thử lại 1 lần.");
        usleep(random_int(2000, 4500) * 1000);
        $detail = transfer_detail($funpass_uid, $funpass_token, $ldplayer_uid, $ldplayer_token, $proxyForce);
        $src = (int)($detail["sourceDiamond"] ?? 0);
        if ($src <= 0) {
            logmsg("  [!] Vẫn không có điểm sau khi chờ — bỏ qua transfer (tránh 500 no resources).");
            return null;
        }
    }
    // 2) Execute
    $proxy = proxy_for('transfer', $proxyForce);
    $ts = (string)(int)(microtime(true) * 1000);
    $req_id = gen_transfer_req_id();
    $body = ["funpassUid" => $funpass_uid, "funpassToken" => $funpass_token];
    $headers = [
        "content-type" => "application/json", "uid" => $ldplayer_uid, "token" => $ldplayer_token,
        "client" => "android", "unionappid" => "666600005", "requestid" => $req_id, "timestamp" => $ts,
    ];
    [$st, $raw] = http_request('POST', API_APP . "/user/transfer/execute", $headers, jcompact($body), $proxy, 30);
    $data = log_json_response("transfer execute", $st, $raw);
    if ($st === 200 && $data && ($data["code"] ?? null) === 200) {
        logmsg("  OK CHUYỂN ĐIỂM THÀNH CÔNG!");
        return $data["data"] ?? [];
    }
    $msg = (string)($data["msg"] ?? $data["message"] ?? "");
    if (stripos($msg, "no resources") !== false || stripos($msg, "resource") !== false) {
        logmsg("  [!] Server báo không có tài nguyên chuyển — điểm có thể chưa settle hoặc đã chuyển. Bỏ qua.");
    } else {
        logmsg("  Chuyển điểm thất bại: " . jcompact($data ?? ["raw" => $raw]));
    }
    return null;
}

/** transfer_detail() — feature: transfer */
function transfer_detail(string $funpass_uid, string $funpass_token, string $ldplayer_uid, string $ldplayer_token, ?string $proxyForce = null): ?array {
    $proxy = proxy_for('transfer', $proxyForce);
    $ts = (string)(int)(microtime(true) * 1000);
    $req_id = gen_transfer_req_id();
    $body = ["funpassUid" => $funpass_uid, "funpassToken" => $funpass_token];
    $headers = [
        "content-type" => "application/json", "uid" => $ldplayer_uid, "token" => $ldplayer_token,
        "client" => "android", "unionappid" => "666600005", "requestid" => $req_id, "timestamp" => $ts,
    ];
    [$st, $raw] = http_request('POST', API_APP . "/user/transfer/detail", $headers, jcompact($body), $proxy, 30);
    $data = log_json_response("transfer detail", $st, $raw);
    if ($st === 200 && $data && ($data["code"] ?? null) === 200) {
        $t = $data["data"] ?? [];
        logmsg("  Điểm nguồn (Funpass): " . ($t["sourceDiamond"] ?? 0));
        logmsg("  Điểm đích (LDPlayer): " . ($t["targetScore"] ?? 0));
        logmsg("  Đã thực hiện: " . json_encode($t["isExecute"] ?? false));
        return $t;
    }
    return null;
}

/* ----------------------------------------------------------------------------
 * FLOW KHOGA.PY — 1 chu kỳ đầy đủ (auto hoặc manual LDPlayer), giống hàm run()
 * $opts: ['ld_mode'=>'auto'|'existing', 'ld_email'=>?, 'ld_password'=>?,
 *         'confirm_transfer'=>bool]
 * ----------------------------------------------------------------------------*/
function flow_khoga_full(array $opts = []): array {
    $ld_mode = $opts['ld_mode'] ?? 'auto';
    $result = [];
    // Khi resume OTP LDPlayer, dùng lại FunPass đã hoàn tất trong context thay vì tạo lại.
    $resumeLdFunpass = (($opts['ctx']['type'] ?? '') === 'ldplayer' && is_array($opts['ctx']['funpass_account'] ?? null)) ? $opts['ctx']['funpass_account'] : null;
    // Giữ cùng proxy US cho các bước tạo/login của cùng một flow; không đổi proxy giữa FunPass và LDPlayer.
    $flowProxy = proxy_for('register_funpass');
    if ($flowProxy !== null) logmsg('[FLOW] Giữ cùng proxy US cho bước tạo FunPass và LDPlayer.');

    logmsg("[BƯỚC 1] Tạo tài khoản Funpass...");
    // Chỉ dùng lại ctx/captcha cho Funpass khi captcha/OTP đến từ bước Funpass.
    $fpCaptcha = (($opts['ctx']['type'] ?? 'funpass') === 'funpass') ? ($opts['captcha'] ?? null) : null;
    $fpCtx     = (($opts['ctx']['type'] ?? 'funpass') === 'funpass') ? ($opts['ctx'] ?? null) : null;
    $android_id = $opts['android_id'] ?? ($opts['androidid'] ?? null);
    $diagnosticSku = !empty($opts['check_sku']);
    $funpass_acc = $resumeLdFunpass ?: create_funpass_account($flowProxy, $fpCaptcha, $fpCtx, $android_id);
    logmsg("  OK Funpass: {$funpass_acc['email']} | UID: {$funpass_acc['uid']}");
    $result['funpass'] = $funpass_acc;

    if ($diagnosticSku) {
        logmsg('[BƯỚC 2] Chế độ kiểm tra SKU: bỏ qua CPI và sync, không phát sinh giao dịch.');
        $balance_before = 0;
        $earned = 0;
        $balance_after = 0;
        $result['balance_before'] = 0;
        $result['earned'] = 0;
        $result['balance_after'] = 0;
        $GLOBALS['CPI_STATS'] = ['done' => 0, 'total' => 0, 'points' => 0];
    } else {
        logmsg("[BƯỚC 2] Kiểm tra số dư trước khi làm nhiệm vụ...");
        $balance_before = get_wallet_info((string)$funpass_acc["uid"], $funpass_acc["token"]);
        logmsg("  Số dư hiện tại: {$balance_before} điểm");
        $result['balance_before'] = $balance_before;

        logmsg("[BƯỚC 3] Làm nhiệm vụ CPI...");
        $earned = run_cpi_tasks($funpass_acc);
        $result['earned'] = $earned;

        logmsg("[BƯỚC 4] Kiểm tra số dư sau khi làm nhiệm vụ...");
        $balance_after = get_wallet_info((string)$funpass_acc["uid"], $funpass_acc["token"]);
        // Nếu ví DNS fail (0) nhưng CPI báo có điểm → vẫn tiếp tục sync
        if ($balance_after <= 0 && $earned > 0) {
            logmsg("  Ví trả 0 nhưng CPI earned={$earned} → vẫn thử sync (dùng transfer_detail làm nguồn chính)");
            $balance_after = $earned;
        }
        logmsg("  Số dư hiện tại: {$balance_after} điểm");
        logmsg("  Đã kiếm được: " . ($balance_after - $balance_before) . " điểm");
        $result['balance_after'] = $balance_after;
    }

    // Tóm tắt kết quả — đúng 100% mẫu spec (hiện ở CUỐI console + hộp kết quả dưới console)
    $sum = panel_summary_lines($GLOBALS['CPI_STATS'] ?? null, $balance_after, $balance_before);
    $result['panel_summary'] = $sum;

    if (!$diagnosticSku && $balance_after <= 0 && $earned <= 0) {
        logmsg("  Tài khoản không có điểm để sync!");
        $result['synced'] = false;
        log_result_block($funpass_acc, $sum);
        return $result;
    }

    logmsg("[BƯỚC 5] Sync LDPlayer ({$ld_mode})...");
    // Bắt buộc có LD nhận điểm rõ ràng — không hard-code để tránh ban hàng loạt
    $ldCaptcha = (($opts['ctx']['type'] ?? '') === 'ldplayer') ? ($opts['captcha'] ?? null) : null;
    $ldCtx     = (($opts['ctx']['type'] ?? '') === 'ldplayer') ? ($opts['ctx'] ?? null) : null;
    if ($ld_mode === 'auto') {
        $ldplayer_acc = create_ldplayer_account($funpass_acc["email"], $funpass_acc["temp_token"], $flowProxy, $ldCaptcha, $ldCtx, $android_id, $funpass_acc);
    } else {
        $ldEmail = trim((string)($opts['ld_email'] ?? ''));
        $ldPass  = (string)($opts['ld_password'] ?? '');
        if ($ldEmail === '' || $ldPass === '') {
            throw new RuntimeException("Cần nhập email/password LDPlayer nhận điểm (ld_mode=existing). Không dùng hard-code để tránh ban hàng loạt.");
        }
        $ldplayer_acc = login_ldplayer($ldEmail, $ldPass, $flowProxy, $android_id);
    }
    $result['ldplayer'] = $ldplayer_acc;

    // Chẩn đoán tùy chọn: đăng nhập module SKU và chỉ đọc danh sách SKU, không tạo đơn/mua thẻ.
    if (!empty($opts['check_sku'])) {
        logmsg('[BƯỚC 6] Kiểm tra LDPlayer qua đăng nhập SKU (chỉ đọc)...');
        $skuLogin = mt_login((string)$ldplayer_acc['email'], (string)$ldplayer_acc['password'], $android_id, $flowProxy);
        if (empty($skuLogin['ok'])) {
            $result['sku_check'] = ['ok' => false, 'code' => $skuLogin['code'] ?? null, 'login_ok' => false, 'sku_count' => 0];
            logmsg('[SKU] Đăng nhập kiểm tra thất bại; không gọi danh sách SKU tiếp.');
            log_result_block($funpass_acc, $sum);
            return $result;
        }
        mt_sync_time($flowProxy);
        $skuList = mt_get_all_item_list($flowProxy);
        $result['sku_check'] = ['ok' => true, 'code' => $skuLogin['code'] ?? 200, 'login_ok' => true, 'sku_count' => count($skuList)];
        logmsg('[SKU] Đăng nhập hợp lệ; đọc được ' . count($skuList) . ' SKU. Không thực hiện mua thẻ.');
        if ($diagnosticSku) {
            $result['synced'] = false;
            log_result_block($funpass_acc, $sum);
            return $result;
        }
    }

    logmsg("[BƯỚC 7] Sync điểm sang LDPlayer...");
    $detail = transfer_detail((string)$funpass_acc["uid"], $funpass_acc["token"],
                              (string)$ldplayer_acc["uid"], $ldplayer_acc["token"]);
    $result['transfer_detail'] = $detail;

    if ($opts['confirm_transfer'] ?? true) {
        $exec = transfer_execute((string)$funpass_acc["uid"], $funpass_acc["token"],
                                 (string)$ldplayer_acc["uid"], $ldplayer_acc["token"]);
        $result['transfer_execute'] = $exec;
        $result['synced'] = $exec !== null;
        if ($exec !== null) logmsg("  OK ĐÃ SYNC THÀNH CÔNG!");
    } else {
        $result['synced'] = false;
    }

    // Khối kết quả tổng hợp CUỐI console — đúng 100% mẫu spec (JSON tài khoản + 4 dòng tóm tắt)
    log_result_block($funpass_acc, $sum);
    logmsg("  LDPlayer: {$ldplayer_acc['email']} | Pass: {$ldplayer_acc['password']} | UID: {$ldplayer_acc['uid']}");
    return $result;
}

/* ----------------------------------------------------------------------------
 * FLOW FUNPASS.PY — main_loop(): 1 chu kỳ theo proxy (auto-loop)
 * $opts: ['proxy_index'=>int, 'ld_email'=>..., 'ld_password'=>...]
 * ----------------------------------------------------------------------------*/
function flow_funpass_once(array $opts = []): array {
    $proxy_list = proxy_load_list(FILE_PROXY);
    $vn_list = proxy_load_list(FILE_PROXY_VN);
    // Proxy TẮT (nút "Áp Dụng Proxy" = × hoặc cờ chức năng = ×) → chạy TRỰC TIẾP, không bắt buộc proxy.txt
    $proxyOn = proxy_feature_enabled('register_funpass');
    $idx = 0; $proxy_us = null; $proxy_vn = null;
    if ($proxyOn) {
        if (!$proxy_list) throw new RuntimeException("Proxy đang BẬT nhưng không tìm thấy proxy.txt hoặc file rỗng (tắt proxy để chạy trực tiếp)");
        $idx = (int)($opts['proxy_index'] ?? 0) % count($proxy_list);
        $proxy_us = $proxy_list[$idx];
        logmsg("[+] Bắt đầu với proxy US #" . ($idx + 1) . ": {$proxy_us}");

        if ($vn_list && $idx < count($vn_list)) {
            $proxy_vn = $vn_list[$idx];
            logmsg("    Dùng proxy VN từ file: {$proxy_vn}");
        } else {
            $proxy_vn = get_vn_proxy_from_us($proxy_us);
            logmsg($proxy_vn === $proxy_us
                ? "    [!] Không thể chuyển sang VN, dùng proxy US cho CPI."
                : "    Đã chuyển sang proxy VN: {$proxy_vn}");
        }
    } else {
        logmsg("[+] Proxy đang TẮT → gửi API trực tiếp, không qua proxy.");
    }

    // 1. Tạo Funpass dùng proxy US
    $android_id = $opts['android_id'] ?? ($opts['androidid'] ?? null);
    $funpass_acc = create_funpass_account($proxy_us, $opts['captcha'] ?? null, $opts['ctx'] ?? null, $android_id);
    logmsg("  OK Funpass: {$funpass_acc['email']} | UID: {$funpass_acc['uid']}");

    // 2. Làm CPI dùng proxy VN
    $earned = run_cpi_tasks($funpass_acc, $proxy_vn);
    logmsg("  Đã kiếm được {$earned} điểm.");

    // 2b. Kiểm tra ví sau CPI (tài khoản mới tạo → số dư trước = 0) — phục vụ khối tóm tắt cuối console
    $balance_after = get_wallet_info((string)$funpass_acc["uid"], $funpass_acc["token"], $proxy_vn);
    if ($balance_after <= 0 && $earned > 0) {
        logmsg("  Ví trả 0 nhưng CPI earned={$earned} → vẫn thử sync");
        $balance_after = $earned;
    }
    logmsg("  Số dư ví hiện tại: {$balance_after} điểm");

    // 3. Đăng nhập LDPlayer nhận điểm (BẮT BUỘC truyền từ UI/CLI — không hard-code)
    $ldEmail = trim((string)($opts['ld_email'] ?? ''));
    $ldPass  = (string)($opts['ld_password'] ?? '');
    if ($ldEmail === '' || $ldPass === '') {
        throw new RuntimeException("Cần nhập email/password LDPlayer nhận điểm. Không hard-code để tránh ban hàng loạt.");
    }
    $ldplayer_acc = login_ldplayer($ldEmail, $ldPass, $proxy_us, $android_id);
    logmsg("  OK LDPlayer UID: {$ldplayer_acc['uid']}");

    // 4. Sync điểm dùng proxy US (transfer_execute đã tự check sourceDiamond + retry)
    $detail = transfer_detail((string)$funpass_acc["uid"], $funpass_acc["token"],
                              (string)$ldplayer_acc["uid"], $ldplayer_acc["token"], $proxy_us);
    $exec = transfer_execute((string)$funpass_acc["uid"], $funpass_acc["token"],
                             (string)$ldplayer_acc["uid"], $ldplayer_acc["token"], $proxy_us);
    // Khối kết quả tổng hợp CUỐI console — đúng 100% mẫu spec (JSON tài khoản + 4 dòng tóm tắt)
    $sum = panel_summary_lines($GLOBALS['CPI_STATS'] ?? null, $balance_after, 0);
    log_result_block($funpass_acc, $sum);
    return [
        'funpass' => $funpass_acc, 'ldplayer' => $ldplayer_acc,
        'earned' => $earned, 'balance_after' => $balance_after,
        'transfer_detail' => $detail, 'transfer_execute' => $exec,
        'next_proxy_index' => $proxyOn ? $idx + 1 : 0,
        'panel_summary' => $sum,
    ];
}

/* ############################################################################
 * MODULE 3: MUATHE.JS  (Node.js -> PHP, giữ nguyên logic)
 * ##########################################################################*/

function mt_slot_key(?string $value = null): string {
    $raw = trim((string)($value ?? ($GLOBALS['MIMI_MT_SLOT'] ?? '')));
    if ($raw === '') return '';
    $raw = strtolower($raw);
    // Chỉ dùng email/khóa dạng an toàn làm tên slot, không đưa dữ liệu tùy ý vào đường dẫn.
    return substr(hash('sha256', $raw), 0, 40);
}
function mt_slot_file(?string $slot = null): string {
    $key = mt_slot_key($slot);
    if ($key === '') return mimi_data_file('config.json', FILE_CONFIG);
    return mimi_data_file('mt_session_' . $key . '.json', DIR_DATA . '/mt_session_' . $key . '.json');
}
function mt_load_config(): array {
    $file = mt_slot_file();
    if (is_file($file)) {
        $d = json_decode((string)@file_get_contents($file), true);
        if (is_array($d)) return $d;
    }
    // Tương thích phiên cũ: chỉ dùng config.json khi chưa chọn slot.
    if (mt_slot_key() === '') {
        $legacy = mimi_data_file('config.json', FILE_CONFIG);
        if ($legacy !== $file && is_file($legacy)) {
            $d = json_decode((string)@file_get_contents($legacy), true);
            if (is_array($d)) return $d;
        }
    }
    return [];
}
function mt_save_config(array $data): void {
    $file = mt_slot_file();
    $dir = dirname($file); if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($file, $json, LOCK_EX) === false) throw new RuntimeException('Không lưu được phiên Mua Thẻ riêng của tài khoản');
}
/** Trạng thái phiên Mua Thẻ đang chọn: uid/token/androidid/serverTimeOffset */
function mt_state(): array {
    $c = mt_load_config();
    return [
        'uid'              => (string)($c['uid'] ?? ''),
        'token'            => (string)($c['token'] ?? ''),
        'androidid'        => (string)($c['androidid'] ?? ($c['androidId'] ?? '')),
        'username'         => (string)($c['username'] ?? ''),
        'serverTimeOffset' => (int)($c['serverTimeOffset'] ?? 0),
        'slot'             => mt_slot_key(),
    ];
}
function mt_save_state(array $st): void {
    $c = mt_load_config();
    foreach (['uid','token','androidid','username','serverTimeOffset'] as $k) {
        if (array_key_exists($k, $st)) $c[$k] = $st[$k];
    }
    mt_save_config($c);
}

/** saveCardToFile — muathe.js */
function save_card_to_file(array $cardInfo): void {
    $timeStr = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('d/m/Y H:i:s');
    $line = "[{$timeStr}] Mã Đơn: " . ($cardInfo['orderNo'] ?? 'N/A')
          . " | SKU: " . ($cardInfo['skuName'] ?? ($cardInfo['skuId'] ?? 'N/A'))
          . " | Thẻ: {$cardInfo['cardNumber']} | Mật khẩu: {$cardInfo['password']} | Hạn: {$cardInfo['expireDate']}\n";
    file_put_contents(mimi_data_file('cards.txt', FILE_CARDS), $line, FILE_APPEND | LOCK_EX);
    logmsg("  Đã tự động lưu thông tin thẻ vào cards.txt");
}

/** getCommonHeaders — muathe.js (api-app.easyfun.gg) */
function mt_common_headers(array $st, string $bodyStr): array {
    $timestamp = (string)(int)(microtime(true) * 1000);
    $requestId = generate_request_id();
    $sign = build_sign(APP_SECRET, (int)$timestamp, $requestId, $bodyStr ?: '{}');
    return [
        'host' => 'api-app.easyfun.gg',
        'sec-ch-ua' => '"Chromium";v="124", "Android WebView";v="124", "Not-A.Brand";v="99"',
        'language' => 'vn', 'version-name' => '0900012401', 'time_zone' => '7',
        'requestid' => $requestId, 'client' => 'mnq', 'uid' => $st['uid'],
        'unionappid' => '666600005', 'ticket' => $requestId, 'channel' => 'official',
        'sec-ch-ua-platform' => '"Android"', 'sec-ch-ua-mobile' => '?1',
        'user-agent' => 'Mozilla/5.0 (Linux; Android 12; SM-A536E Build/V417IR; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/110.0.5481.154 Mobile Safari/537.36 Easyfun',
        'content-type' => 'application/json', 'version-code' => '139', 'accept' => 'application/json',
        'macid' => $st['androidid'], 'timestamp' => $timestamp,
        'server-ts-offset' => (string)$st['serverTimeOffset'], 'token' => $st['token'],
        'xx-ld-tags' => 'source:ldplayer-app-h5', 'origin' => 'https://h5-app.ldplayer.net',
        'x-requested-with' => 'com.ldy.funpass', 'sec-fetch-site' => 'cross-site',
        'sec-fetch-mode' => 'cors', 'sec-fetch-dest' => 'empty',
        'referer' => 'https://h5-app.ldplayer.net/', 'accept-encoding' => 'gzip, deflate, br, zstd',
        'accept-language' => 'vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7', 'priority' => 'u=1, i',
        'sign' => $sign,
    ];
}

/** getLdcodeHeaders — muathe.js (api.ldcode.gg) */
function mt_ldcode_headers(array $st, string $bodyStr): array {
    $timestamp = (string)(int)(microtime(true) * 1000);
    $requestId = generate_request_id();
    $sign = build_sign(APP_SECRET, (int)$timestamp, $requestId, $bodyStr ?: '{}');
    return [
        'host' => 'api.ldcode.gg',
        'language' => 'vn', 'version-name' => '0900012401', 'time_zone' => '7',
        'requestid' => $requestId, 'client' => 'mnq', 'uid' => $st['uid'],
        'unionappid' => '666600005', 'ticket' => $requestId, 'channel' => 'official',
        'user-agent' => 'Mozilla/5.0 (Linux; Android 12; SM-A536E Build/V417IR; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/110.0.5481.154 Mobile Safari/537.36 Easyfun',
        'content-type' => 'application/json', 'version-code' => '139', 'accept' => 'application/json',
        'macid' => $st['androidid'], 'timestamp' => $timestamp,
        'server-ts-offset' => (string)$st['serverTimeOffset'], 'token' => $st['token'],
        'xx-ld-tags' => 'source:ldplayer-app-h5', 'origin' => 'https://h5-app.ldplayer.net',
        'x-requested-with' => 'com.ldy.funpass', 'sec-fetch-site' => 'cross-site',
        'sec-fetch-mode' => 'cors', 'sec-fetch-dest' => 'empty',
        'referer' => 'https://h5-app.ldplayer.net/', 'accept-encoding' => 'gzip, deflate, br, zstd',
        'accept-language' => 'vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7', 'priority' => 'u=1, i',
        'sign' => $sign,
    ];
}

/** getItemHeaders — muathe.js (api-item.ldplayer.net) */
function mt_item_headers(array $st, string $bodyStr): array {
    $timestamp = (string)(int)(microtime(true) * 1000);
    $requestId = generate_request_id();
    $sign = build_sign(APP_SECRET, (int)$timestamp, $requestId, $bodyStr ?: '{}');
    return [
        'host' => 'api-item.ldplayer.net',
        'accept' => 'application/json',
        'accept-language' => 'vi-VN,vi;q=0.9,fr-FR;q=0.8,fr;q=0.7,en-US;q=0.6,en;q=0.5',
        'channel' => 'ldstore', 'client' => 'mnq', 'content-type' => 'application/json',
        'language' => 'vn', 'origin' => 'https://h5-app.ldplayer.net',
        'referer' => 'https://h5-app.ldplayer.net/', 'requestid' => $requestId,
        'sec-ch-ua' => '"Chromium";v="137", "Not/A)Brand";v="24"',
        'sec-ch-ua-mobile' => '?1', 'sec-ch-ua-platform' => '"Android"',
        'sec-fetch-dest' => 'empty', 'sec-fetch-mode' => 'cors', 'sec-fetch-site' => 'same-site',
        'server-ts-offset' => (string)$st['serverTimeOffset'], 'ticket' => $requestId,
        'time_zone' => '7', 'timestamp' => $timestamp, 'token' => $st['token'], 'uid' => $st['uid'],
        'user-agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
        'version-code' => '1', 'xx-ld-tags' => 'source:ldplayer-app-h5-ldstore',
        'sign' => $sign,
    ];
}

/** getCreateOrderHeaders — muathe.js (ký bằng H5_KEY) */
function mt_create_order_headers(array $st, string $bodyStr, string $timestamp, string $requestId): array {
    $sign = build_sign(H5_KEY, (int)$timestamp, $requestId, $bodyStr);
    return [
        'host' => 'api-app.ldplayer.net',
        'sec-ch-ua' => '"Not/A)Brand";v="8", "Chromium";v="126", "Android WebView";v="126"',
        'language' => 'vn', 'version-name' => '8.2.4', 'time_zone' => '7',
        'requestid' => $requestId, 'client' => 'android', 'uid' => $st['uid'],
        'unionappid' => '666600005', 'ticket' => $requestId, 'channel' => 'official',
        'sign' => $sign, 'sec-ch-ua-platform' => '"Android"', 'sec-ch-ua-mobile' => '?1',
        'user-agent' => 'Mozilla/5.0 (Linux; Android 13; SM-F916B Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.6478.71 Mobile Safari/537.36 Easyfun',
        'content-type' => 'application/json', 'version-code' => '139', 'accept' => 'application/json',
        'macid' => $st['androidid'], 'timestamp' => $timestamp,
        'server-ts-offset' => (string)$st['serverTimeOffset'], 'appid' => '2003', 'token' => $st['token'],
        'xx-ld-tags' => 'source:ldplayer-app-h5', 'origin' => 'https://h5-app.ldplayer.net',
        'x-requested-with' => 'com.ldy.funpass', 'sec-fetch-site' => 'same-site',
        'sec-fetch-mode' => 'cors', 'sec-fetch-dest' => 'empty',
        'referer' => 'https://h5-app.ldplayer.net/', 'accept-encoding' => 'gzip, deflate, br, zstd',
        'accept-language' => 'vi-VI,vi-VN;q=0.9,vi;q=0.8,en-US;q=0.7,en;q=0.6',
        'priority' => 'u=1, i', 'content-length' => (string)strlen($bodyStr),
    ];
}

/** login(username, password) — muathe.js -> user.ldplayer.net — feature: mt_login
 *  Luôn trả về mảng ['ok','uid','token','androidId','username','serverTimeOffset','code','raw']:
 *  - ok = true khi code = 200 và có uid + token (đúng như muathe.js).
 *  - Kể cả khi thất bại (ok = false) vẫn trả về uid/token/raw đọc được từ response
 *    để bảng "Tài Khoản / Dữ Liệu Mới Nhất" hiển thị đầy đủ thay vì khung rỗng. */
function mt_login(string $username, string $password, ?string $android_id = null, ?string $proxyForce = null): array {
    $proxy = proxy_for('mt_login', $proxyForce);
    $loginTs = generate_login_timestamp();
    $loginReqId = bin2hex(random_bytes(36));
    $android_id = stable_android_id_for_login($username, 'ldplayer', $android_id);
    $androidId = generate_device_id($android_id);
    $brand = "Google"; $model = "Pixel 4";
    $googleId = ""; $sid = "0RPFI8Y854E72SDG8XCXFF8NBDY0UPDQ";

    $modelEnc = str_replace(' ', '+', $model);
    $deviceId = "{$androidId},{$brand},{$modelEnc},{$googleId},{$sid}";
    $mid = "{$androidId},{$brand},{$modelEnc},{$googleId}";

    $loginBody = [
        "loginMode" => "USERNAME", "email" => $username, "username" => $username,
        "pwd" => md5($password), "auth" => md5('"' . $password . '"'),
        "isCoolingOffPeriod" => "false", "mainChannelId" => "91000", "subChannelId" => "91006",
        "phoneBrand" => $brand, "phoneModel" => $modelEnc, "appId" => "6666",
        "subAppId" => "666600005", "timeZone" => "Asia/Ho_Chi_Minh", "mid" => $mid,
    ];
    $bodyJson = jcompact($loginBody);
    $loginSign = strtoupper(md5(implode(';', [$bodyJson, $loginTs, $loginReqId, $deviceId, APP_SECRET])));

    logmsg('Đăng nhập:');
    logmsg('TIMESTAMP: ' . $loginTs);
    logmsg('REQUEST_ID: ' . $loginReqId);
    logmsg('DEVICE_ID: ' . $deviceId);
    logmsg('SIGN: ' . $loginSign);

    $loginHeaders = [
        "host" => "user.ldplayer.net", "sdk_version" => "1.1.3", "protocol_version" => "1",
        "sdk_platform" => "Android", "app_id" => "6666", "channel_id" => "91000",
        "sub_channel_id" => "91006", "device_id" => $deviceId, "request_id" => $loginReqId,
        "timestamp" => $loginTs, "language_code" => "vi-VN", "time_zone" => "7",
        "ext_app_id" => "666600005", "sign" => $loginSign,
        "content-type" => "application/json;charset=utf-8", "user-agent" => "okhttp/4.12.0",
        "accept-encoding" => "gzip",
    ];

    [$st, $raw] = http_request('POST', LOGIN_URL, $loginHeaders, $bodyJson, $proxy, 30);
    logmsg('Trạng thái: ' . $st);
    logmsg('Phản hồi: ' . $raw);
    $responseData = json_decode($raw, true);

    // Bóc uid/token từ mọi vị trí có thể trong response — kể cả khi code != 200
    $code  = is_array($responseData) ? ($responseData['code'] ?? null) : null;
    $token = null; $uid = null;
    if (is_array($responseData)) {
        $dataArr = (isset($responseData['data']) && is_array($responseData['data'])) ? $responseData['data'] : [];
        $token = $dataArr['token'] ?? ($responseData['token'] ?? null);
        $uid   = $dataArr['uid'] ?? ($dataArr['userId'] ?? ($responseData['uid'] ?? null));
    }
    $ok = ($code === 200 && $token && $uid);
    $out = [
        'ok'               => $ok,
        'uid'              => $uid !== null ? (string)$uid : '',
        'token'            => $token !== null ? (string)$token : '',
        'androidId'        => $androidId,
        'username'         => $username,
        'password'         => $password,
        'serverTimeOffset' => mt_state()['serverTimeOffset'],
        'code'             => $code,
        'data'             => $ok ? ($responseData['data'] ?? null) : null,
        'raw'              => $responseData,
    ];
    if ($token && $uid) {
        logmsg('Đăng nhập thành công!');
        logmsg('Token: ' . $out['token']);
        logmsg('UID: ' . $out['uid']);
        logmsg('Android ID: ' . $androidId);
        mt_save_state(['uid' => $out['uid'], 'token' => $out['token'], 'androidid' => $androidId, 'username' => $username]);
        if (!$ok) logmsg('[!] Lưu ý: response code = ' . json_encode($code) . ' nhưng vẫn có uid/token → đã hiển thị và lưu phiên.');
        return $out;
    }
    logmsg('Đăng nhập thất bại với mã: ' . json_encode($code));
    logmsg('Thông báo: ' . json_encode(is_array($responseData) ? ($responseData['msg'] ?? ($responseData['message'] ?? ($responseData['msgTitle'] ?? null))) : null, JSON_UNESCAPED_UNICODE));
    return $out;
}

/** syncTime() — muathe.js */
function mt_sync_time(?string $proxyForce = null): int {
    try {
        [$st, $raw] = http_request('GET', "https://appcenter.ldplayer.net/ntp/time", [], null, proxy_for('mt_common', $proxyForce), 15);
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['data'])) {
            $offset = (int)$data['data'] - (int)(microtime(true) * 1000);
            mt_save_state(['serverTimeOffset' => $offset]);
            logmsg('Chênh lệch thời gian server (ms): ' . $offset);
            return $offset;
        }
    } catch (Throwable $e) {
        if (upstream_risk_error($e->getMessage())) throw $e;
        /* lỗi mạng thường có thể dùng thời gian local */
    }
    logmsg('Không thể đồng bộ thời gian, sử dụng thời gian local');
    mt_save_state(['serverTimeOffset' => 0]);
    return 0;
}

/** getBalance() — muathe.js — feature: mt_common */
function mt_get_balance(): ?int {
    $st = mt_state();
    $bodyStr = '{}';
    $headers = mt_ldcode_headers($st, $bodyStr);
    logmsg('Kiểm tra số dư:');
    [$code, $raw] = http_request('POST', LDCODE_URL . '/my/coin?coinCode=COIN_CODE_BONUS_POINTS', $headers, $bodyStr, proxy_for('mt_common'), 30);
    logmsg('Trạng thái: ' . $code);
    logmsg('Phản hồi: ' . $raw);
    $data = json_decode($raw, true);
    if (is_array($data) && ($data['code'] ?? null) === 200 && isset($data['data']['num'])) {
        logmsg("Số dư: {$data['data']['num']} điểm");
        return (int)$data['data']['num'];
    }
    return null;
}

/** getAllItemList() — muathe.js — feature: mt_item */
function mt_get_all_item_list(?string $proxyForce = null): array {
    $st = mt_state();
    $allItemIds = range(46, 55);
    $items = array_map(fn($id) => ['itemId' => $id, 'itemType' => 1], $allItemIds);
    $body = ['items' => $items];
    $bodyStr = jcompact($body);
    $headers = mt_item_headers($st, $bodyStr);
    logmsg('Lấy danh sách tất cả SKU:');
    [$code, $raw] = http_request('POST', ITEM_URL . '/sku/list', $headers, $bodyStr, proxy_for('mt_item', $proxyForce), 30);
    logmsg('Trạng thái: ' . $code);
    $data = json_decode($raw, true);
    $allSkus = [];
    if (is_array($data) && ($data['code'] ?? null) === 200 && !empty($data['data'])) {
        foreach ($data['data'] as $item) {
            if (!empty($item['skus']) && is_array($item['skus'])) {
                foreach ($item['skus'] as $sku) {
                    $allSkus[] = [
                        'skuId' => $sku['skuId'] ?? null,
                        'name' => $sku['displayName'] ?? ($sku['itemName'] ?? null),
                        'price' => $sku['price'] ?? null,
                        'score' => $sku['score'] ?? ($sku['originalScore'] ?? null),
                        'type' => $sku['type'] ?? null,
                        'stockPct' => $sku['stockPct'] ?? null,
                        'cover' => $sku['cover'] ?? null,
                        'description' => $sku['description'] ?? null,
                        'itemId' => $item['itemId'] ?? null,
                        'itemName' => $item['itemName'] ?? null,
                    ];
                }
            }
        }
        logmsg("Tìm thấy " . count($allSkus) . " SKU");
        foreach ($allSkus as $i => $sku) {
            logmsg(($i+1) . ". SKU ID: {$sku['skuId']} | {$sku['name']} | {$sku['score']} điểm | Kho: {$sku['stockPct']}%");
        }
    }
    return $allSkus;
}

/** getGiftList(page, size) — muathe.js — feature: mt_common */
function mt_get_gift_list(int $page = 1, int $size = 20): ?array {
    $st = mt_state();
    $body = ['page' => ['current' => $page, 'size' => $size]];
    $bodyStr = jcompact($body);
    $headers = mt_common_headers($st, $bodyStr);
    logmsg("Lấy danh sách thẻ đã mua: trang {$page}, số lượng {$size}");
    [$code, $raw] = http_request('POST', BASE_URL . '/gift/order/pageList', $headers, $bodyStr, proxy_for('mt_common'), 30);
    logmsg('Trạng thái: ' . $code);
    logmsg('Phản hồi: ' . $raw);
    return json_decode($raw, true);
}

/** getGiftDetail(orderNo) — muathe.js — feature: mt_common */
function mt_get_gift_detail(string $orderNo): ?array {
    $st = mt_state();
    $body = ['orderNo' => $orderNo];
    $bodyStr = jcompact($body);
    $headers = mt_common_headers($st, $bodyStr);
    logmsg('Lấy chi tiết thẻ: ' . $orderNo);
    [$code, $raw] = http_request('POST', BASE_URL . '/gift/order/detail', $headers, $bodyStr, proxy_for('mt_common'), 30);
    logmsg('Trạng thái: ' . $code);
    logmsg('Phản hồi: ' . $raw);
    $data = json_decode($raw, true);
    if (is_array($data) && ($data['code'] ?? null) === 200 && !empty($data['data'])) {
        $detail = $data['data'];
        $goods = $detail['extra']['skuGoodsVOS'] ?? [];
        if (count($goods) > 0) {
            $card = $goods[0];
            $expireDate = (new DateTime('@' . (int)($card['expireTimestamp'] ?? 0)))
                ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i:s'); // giờ VN như toLocaleString('vi-VN')
            logmsg("Thông tin thẻ: {$card['config']['cardNumber']} | {$card['config']['password']} | Hết hạn: {$expireDate}");
            save_card_to_file([
                'orderNo' => $detail['orderNo'] ?? $orderNo,
                'skuId' => $detail['skuId'] ?? null,
                'skuName' => $detail['goodsName'] ?? null,
                'cardNumber' => $card['config']['cardNumber'] ?? '',
                'password' => $card['config']['password'] ?? '',
                'expireDate' => $expireDate,
            ]);
        }
        return $data;
    }
    return null;
}

/** createGiftOrder(skuId, num) — muathe.js — feature: mt_order (AES-192-ECB) */
function mt_create_gift_order(int $skuId, int $num = 1): ?array {
    $st = mt_state();
    $timestamp = (string)((int)(microtime(true) * 1000) + (int)$st['serverTimeOffset']);
    $requestId = generate_request_id();

    $payload = ['orderType' => 'GIFT_EXCHANGE', 'skuId' => $skuId, 'num' => $num];
    $payloadStr = jcompact($payload);
    $encryptedData = encrypt_data($payloadStr, (int)$timestamp, H5_KEY);
    $body = ['data' => $encryptedData];
    $bodyStr = jcompact($body);
    $headers = mt_create_order_headers($st, $bodyStr, $timestamp, $requestId);

    logmsg('Tạo đơn hàng:');
    logmsg('SKU_ID: ' . $skuId);
    logmsg('Số lượng: ' . $num);
    logmsg('Timestamp request (ms): ' . $timestamp);
    logmsg('Server time offset: ' . $st['serverTimeOffset']);
    logmsg('Payload: ' . $payloadStr);
    logmsg('Dữ liệu mã hóa: ' . $encryptedData);

    [$code, $raw] = http_request('POST', 'https://api-app.ldplayer.net/gift/order/create', $headers, $bodyStr, proxy_for('mt_order'), 30);
    logmsg('Trạng thái: ' . $code);
    logmsg('Phản hồi: ' . $raw);
    $resp = json_decode($raw, true);

    if (is_array($resp) && ($resp['code'] ?? null) === 200 && !empty($resp['data'])) {
        $responseTimestamp = (string)((int)($resp['time'] ?? 0) * 1000);
        logmsg('Timestamp giải mã (ms): ' . $responseTimestamp);
        $decrypted = decrypt_data((string)$resp['data'], (int)$responseTimestamp, H5_KEY);
        if ($decrypted !== null) {
            $orderData = json_decode($decrypted, true);
            if (is_array($orderData)) {
                logmsg('Tạo đơn hàng thành công!');
                logmsg('Mã đơn hàng: ' . ($orderData['orderNo'] ?? ''));
                logmsg('Số điểm: ' . ($orderData['actualPayAmount'] ?? ''));
                $goods = $orderData['extra']['skuGoodsVOS'] ?? [];
                if (count($goods) > 0) {
                    $card = $goods[0];
                    $expireDate = (new DateTime('@' . (int)($card['expireTimestamp'] ?? 0)))
                        ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i:s');
                    logmsg("Thông tin thẻ: {$card['config']['cardNumber']} | {$card['config']['password']} | Hết hạn: {$expireDate}");
                    save_card_to_file([
                        'orderNo' => $orderData['orderNo'] ?? '',
                        'skuId' => $skuId,
                        'skuName' => $orderData['goodsName'] ?? (string)$skuId,
                        'cardNumber' => $card['config']['cardNumber'] ?? '',
                        'password' => $card['config']['password'] ?? '',
                        'expireDate' => $expireDate,
                    ]);
                }
                $resp['_decrypted'] = $orderData;
                return $resp;
            }
            logmsg('Lỗi parse dữ liệu giải mã: ' . $decrypted);
        } else {
            logmsg('Không thể giải mã dữ liệu response');
        }
    } else {
        logmsg('Lỗi: ' . json_encode($resp['msg'] ?? ($resp['message'] ?? null), JSON_UNESCAPED_UNICODE));
        logmsg('Mã lỗi: ' . json_encode($resp['code'] ?? null));
    }
    return is_array($resp) ? $resp : null;
}

/** buyGiftAuto() — muathe.js: chọn SKU tốt nhất <= số dư, còn hàng.
 *  $confirm=false -> chỉ trả về SKU đề xuất; $confirm=true -> mua luôn. */
function mt_buy_gift_auto(bool $confirm = false): ?array {
    logmsg('Đang lấy danh sách SKU...');
    $allSkus = mt_get_all_item_list();
    if (!$allSkus) { logmsg('Không lấy được danh sách SKU'); return null; }

    logmsg('Đang lấy số dư...');
    $balance = mt_get_balance();
    if ($balance === null) { logmsg('Không lấy được số dư'); return null; }
    logmsg("Số dư hiện tại: {$balance} điểm");

    $available = array_values(array_filter($allSkus, fn($s) =>
        (int)$s['score'] <= $balance && (int)$s['stockPct'] > 0));
    usort($available, fn($a, $b) => (int)$b['score'] <=> (int)$a['score']);

    if (!$available) { logmsg("Không có SKU nào phù hợp với số dư {$balance} điểm"); return null; }
    $best = $available[0];
    logmsg("Chọn SKU tốt nhất: {$best['skuId']} - {$best['name']} ({$best['score']} điểm, còn lại: " . ($balance - (int)$best['score']) . ")");

    if (!$confirm) {
        return ['preview' => true, 'balance' => $balance, 'bestSku' => $best, 'available' => $available];
    }
    logmsg('Đang tiến hành mua...');
    $buyResult = mt_create_gift_order((int)$best['skuId'], 1);
    if ($buyResult && ($buyResult['code'] ?? null) === 200) {
        logmsg('Mua thành công!');
        $newBalance = mt_get_balance();
        logmsg("Số dư còn lại: {$newBalance} điểm");
        return ['preview' => false, 'result' => $buyResult, 'balance' => $newBalance, 'bestSku' => $best];
    }
    logmsg('Mua thất bại!');
    return ['preview' => false, 'result' => $buyResult, 'bestSku' => $best];
}

/** snipeGiftOrder(skuId, num, delayMs, maxAttempts) — muathe.js */
function mt_snipe_gift_order(int $skuId, int $num = 1, int $delayMs = 1000, int $maxAttempts = 100): ?array {
    logmsg("Bắt đầu Sniper Mode cho SKU {$skuId} | Delay: {$delayMs}ms | Tối đa: {$maxAttempts} lần");
    for ($i = 1; $i <= $maxAttempts; $i++) {
        logmsg("[Lần {$i}/{$maxAttempts}] Đang thử tạo đơn hàng SKU {$skuId}...");
        $res = mt_create_gift_order($skuId, $num);
        if ($res && ($res['code'] ?? null) === 200) {
            logmsg("SĂN THÀNH CÔNG! lần thử thứ {$i}.");
            return $res;
        }
        if ($i < $maxAttempts) usleep($delayMs * 1000);
    }
    logmsg("Đã thử {$maxAttempts} lần nhưng không săn thành công.");
    return null;
}

/** getAvailableSkus() — muathe.js (/gift/sku/list trên easyfun) */
function mt_get_available_skus(): ?array {
    $st = mt_state();
    $bodyStr = '{}';
    $headers = mt_common_headers($st, $bodyStr);
    logmsg('Lấy danh sách SKU có sẵn:');
    [$code, $raw] = http_request('POST', BASE_URL . '/gift/sku/list', $headers, $bodyStr, proxy_for('mt_common'), 30);
    logmsg('Trạng thái: ' . $code);
    logmsg('Phản hồi: ' . $raw);
    return json_decode($raw, true);
}

/* ############################################################################
 * PHẦN 4: ROUTER — WEB (JSON API + UI) & CLI
 * ##########################################################################*/

/** Danh mục action dùng làm ngữ cảnh cho trợ lý AI ở giao diện. */
function ai_action_catalog(): array {
    return [
        'ping'=>'kiểm tra kết nối', 'proxy_get'=>'đọc cấu hình proxy', 'proxy_save'=>'lưu cấu hình proxy', 'proxy_add'=>'thêm một proxy vào pool Mỹ hoặc Việt Nam',
        'proxy_reset_state'=>'reset trạng thái proxy',
        'fp_create_funpass'=>'tạo Funpass', 'fp_create_ldplayer'=>'tạo LDPlayer',
        'fp_login_funpass'=>'đăng nhập Funpass', 'fp_login_ldplayer'=>'đăng nhập LDPlayer',
        'fp_wallet'=>'xem ví Funpass', 'fp_cpi_ads'=>'lấy danh sách game CPI', 'fp_cpi_run'=>'chạy nhiệm vụ CPI',
        'fp_transfer_detail'=>'xem chi tiết chuyển điểm', 'fp_transfer_execute'=>'thực hiện chuyển điểm',
        'flow_khoga_full'=>'chạy Khoga full flow', 'flow_funpass_once'=>'chạy một chu kỳ Funpass',
        'mt_login'=>'đăng nhập LDPlayer cho module mua thẻ', 'mt_session'=>'đọc phiên mua thẻ',
        'mt_sync'=>'đồng bộ thời gian', 'mt_logout'=>'xóa phiên mua thẻ', 'mt_balance'=>'xem số dư',
        'mt_skus'=>'xem tất cả SKU', 'mt_available_skus'=>'xem SKU có sẵn', 'mt_gift_list'=>'xem thẻ đã mua',
        'mt_gift_detail'=>'xem chi tiết đơn hàng', 'mt_create_order'=>'tạo đơn theo SKU',
        'mt_buy_auto'=>'mua thẻ tự động', 'mt_snipe'=>'săn thẻ theo SKU',
        'acc_list'=>'đọc danh sách tài khoản', 'acc_save'=>'lưu tài khoản', 'acc_delete'=>'xóa tài khoản',
        'runlog_list'=>'đọc lịch sử log', 'runlog_clear'=>'xóa lịch sử log',
        'create_empty_file'=>'tạo module PHP rỗng', 'module_upload'=>'thêm file PHP', 'module_delete'=>'xóa file PHP thật', 'modules_list'=>'đọc danh sách module', 'modules_remove'=>'gỡ module khỏi menu', 'mimi_chats_get'=>'xem lịch sử trò chuyện', 'mimi_chats_save'=>'lưu lịch sử trò chuyện', 'mimi_chats_clear'=>'xóa toàn bộ lịch sử trò chuyện', 'mimi_models_get'=>'xem model AI', 'mimi_model_add'=>'thêm model AI (Developer)', 'mimi_model_delete'=>'xóa model AI (Developer)'
    ];
}

/** Tạo ngữ cảnh code vừa đủ cho AI; khóa/secret trong source được thay bằng [REDACTED]. */
function ai_code_context(bool $includeSource = true): array {
    static $cache = [];
    $cacheKey = $includeSource ? 'full' : 'compact';
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $source = (string)@file_get_contents(__FILE__);
    $source = preg_replace('/(const\\s+(?:APP_KEY_LD|APP_KEY_FP|APP_SECRET|H5_KEY)\\s*=\\s*)["\\\'][^"\\\']*["\\\']/', '$1"[REDACTED]"', $source) ?? $source;
    $source = preg_replace('/(const\\s+(?:BASE_LD|BASE_TEMP|PAYSDK_URL|CPH_URL|API_APP|BASE_URL|LDCODE_URL|ITEM_URL|LOGIN_URL)\\s*=\\s*)["\\\'][^"\\\']*["\\\']/', '$1"[URL]"', $source) ?? $source;
    preg_match_all('/function\\s+([A-Za-z0-9_]+)\\s*\\(([^)]*)\\)/', $source, $matches, PREG_SET_ORDER);
    $functions = [];
    foreach ($matches as $m) $functions[] = $m[1] . '(' . trim(preg_replace('/\\s+/', ' ', $m[2])) . ')';
    $catalog = ai_action_catalog();
    $actionText = [];
    foreach ($catalog as $name => $label) $actionText[] = $name . ' — ' . $label;
    $friendlyGroups = [
        'Kết nối và hệ thống' => 'kiểm tra kết nối, xem trạng thái hoạt động và xem lịch sử thao tác',
        'Proxy' => 'xem, thêm và quản lý proxy Mỹ hoặc Việt Nam',
        'FunPass và LDPlayer' => 'tạo tài khoản, đăng nhập, xem ví, chạy CPI và chuyển điểm',
        'Khoga Flow' => 'chạy quy trình Khoga đầy đủ hoặc một chu kỳ FunPass',
        'Mua thẻ' => 'đăng nhập, xem số dư, xem sản phẩm, tạo đơn và mua thẻ tự động',
        'Tài khoản đã lưu' => 'xem, lưu và xóa thông tin tài khoản đã tạo',
        'File PHP riêng' => 'thêm và mở file PHP riêng; chỉ Developer được xóa hoặc gỡ file',
        'Bộ nhớ Mimi' => 'lưu bộ nhớ và xem lại các cuộc trò chuyện của tài khoản'
    ];
    $friendlyText = [];
    foreach ($friendlyGroups as $group => $description) $friendlyText[] = $group . ': ' . $description;
    $context = "FILE: api.php (mã PHP đơn file, PHP >= 8.0)\\n";
    $context .= "CHỨC NĂNG GIẢI THÍCH CHO NGƯỜI DÙNG (dùng phần này khi họ hỏi có gì):\\n- " . implode("\\n- ", $friendlyText) . "\\n\\n";
    $context .= "MÃ ACTION NỘI BỘ (chỉ dùng để tạo marker thực thi, tuyệt đối không chép danh sách này vào câu trả lời trừ khi người dùng hỏi rõ về lập trình):\\n- " . implode("\\n- ", $actionText) . "\\n\\n";
    $context .= "DANH SÁCH HÀM:\\n- " . implode("\\n- ", array_values(array_unique($functions))) . "\\n";
    if ($includeSource) {
        $context .= "\\nMÃ NGUỒN ĐÃ CHE GIÁ TRỊ NHẠY CẢM:\\n" . $source;
        if (strlen($context) > 90000) $context = substr($context, 0, 90000) . "\\n[Đã cắt phần cuối vì giới hạn ngữ cảnh]";
    } else {
        $context .= "\\nChỉ gửi mã nguồn đầy đủ khi người dùng hỏi trực tiếp về code, hàm, lỗi hoặc yêu cầu sửa api.php.";
    }
    return $cache[$cacheKey] = ['context' => $context, 'actions' => $catalog, 'file' => basename(__FILE__), 'size' => strlen($source)];
}

/** Đọc cấu hình AI miễn phí; khóa API không bao giờ gửi xuống trình duyệt. */
function ai_config_load(): array {
    $cfg = is_file(DIR_DATA . '/ai_config.php') ? (array)@include DIR_DATA . '/ai_config.php' : [];
    $primary = (string)(getenv('GEMINI_API_KEY') ?: ($cfg['api_key'] ?? ''));
    $fallbacks = is_array($cfg['fallback_api_keys'] ?? null) ? $cfg['fallback_api_keys'] : [];
    return $cfg + [
        'api_key' => $primary,
        'fallback_api_keys' => $fallbacks,
        'model' => 'gemini-2.5-flash-lite',
        'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
        'temperature' => 0.65,
        'max_output_tokens' => 640,
    ];
}
const FILE_MIMI_MODELS = DIR_DATA . '/mimi_models.json';

function mimi_models_load(bool $public = false): array {
    $file = FILE_MIMI_MODELS; $key = 'mimi_models:' . $file;
    $models = fcache_get($key, $file, function () use ($file): array {
        if (!is_file($file)) return [];
        $saved = json_decode((string)@file_get_contents($file), true);
        if (!is_array($saved)) return [];
        $out = [];
        foreach ($saved as $m) {
            if (!is_array($m) || !preg_match('/^custom_[a-f0-9]{24}$/', (string)($m['id'] ?? ''))) continue;
            $label = trim((string)($m['label'] ?? ''));
            $model = trim((string)($m['model'] ?? ''));
            $keyValue = trim((string)($m['api_key'] ?? ''));
            if ($label === '' || $model === '' || $keyValue === '') continue;
            $out[] = ['id'=>(string)$m['id'], 'label'=>substr($label, 0, 80), 'model'=>$model, 'api_key'=>$keyValue, 'created_at'=>(string)($m['created_at'] ?? '')];
        }
        return $out;
    });
    if (!$public) return $models;
    return array_map(fn($m) => ['id'=>$m['id'], 'label'=>$m['label'], 'model'=>$m['model'], 'created_at'=>$m['created_at']], $models);
}
function mimi_models_save(array $models): void {
    $json = json_encode(array_values($models), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents(FILE_MIMI_MODELS, $json, LOCK_EX) === false) throw new RuntimeException('Không lưu được danh sách model Mimi');
    @chmod(FILE_MIMI_MODELS, 0660); fcache_forget('mimi_models:' . FILE_MIMI_MODELS);
}
function mimi_model_add(string $label, string $model, string $apiKey): array {
    if (!mimi_is_developer()) throw new RuntimeException('Chỉ MimiVip01 mới được thêm model AI.');
    $label = trim($label); $model = trim($model); $apiKey = trim($apiKey);
    if ($label === '' || strlen($label) > 80) throw new InvalidArgumentException('Tên model phải từ 1 đến 80 ký tự.');
    if (!preg_match('/^[A-Za-z0-9._:-]{1,120}$/', $model)) throw new InvalidArgumentException('Mã model không hợp lệ.');
    if ($apiKey === '' || strlen($apiKey) > 512 || preg_match('/\s/', $apiKey)) throw new InvalidArgumentException('API key không hợp lệ.');
    $models = mimi_models_load();
    foreach ($models as $m) if (strcasecmp((string)$m['label'], $label) === 0 || strcasecmp((string)$m['model'], $model) === 0) throw new RuntimeException('Model này đã tồn tại.');
    $id = 'custom_' . substr(hash('sha256', $label . '|' . $model . '|' . bin2hex(random_bytes(16))), 0, 24);
    $models[] = ['id'=>$id, 'label'=>$label, 'model'=>$model, 'api_key'=>$apiKey, 'created_at'=>(new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('d/m/Y H:i:s')];
    mimi_models_save($models); return ['id'=>$id, 'label'=>$label, 'model'=>$model, 'created_at'=>$models[array_key_last($models)]['created_at']];
}
function mimi_model_delete(string $id): array {
    if (!mimi_is_developer()) throw new RuntimeException('Chỉ MimiVip01 mới được xóa model AI.');
    if (!preg_match('/^custom_[a-f0-9]{24}$/', $id)) throw new InvalidArgumentException('Model tùy chỉnh không hợp lệ.');
    $models = mimi_models_load(); $next = array_values(array_filter($models, fn($m) => ($m['id'] ?? '') !== $id));
    if (count($next) === count($models)) throw new RuntimeException('Không tìm thấy model AI.');
    mimi_models_save($next); return mimi_models_load(true);
}
function mimi_model_find(string $id): ?array {
    foreach (mimi_models_load() as $m) if (($m['id'] ?? '') === $id) return $m;
    return null;
}

function mimi_api_keys_for_user(array $cfg): array {
    $user = mimi_auth_current() ?: 'Apimimi01';
    $map = is_array($cfg['user_api_keys'] ?? null) ? $cfg['user_api_keys'] : [];
    $selected = trim((string)($map[$user] ?? ''));
    if ($selected === '') $selected = trim((string)($cfg['api_key'] ?? ''));
    $keys = $selected !== '' ? [$selected] : [];
    foreach ((array)($cfg['fallback_api_keys'] ?? []) as $key) { $key = trim((string)$key); if ($key !== '' && !in_array($key, $keys, true)) $keys[] = $key; }
    return $keys;
}

/** Gọi Gemini generateContent bằng REST, dùng free tier của Google AI Studio. */
function ai_gemini_chat(array $messages, ?string $requestedModel = null): array {
    $cfg = ai_config_load();
    $requestedModel = trim((string)($requestedModel ?? ''));
    $custom = $requestedModel !== '' ? mimi_model_find($requestedModel) : null;
    if (str_starts_with($requestedModel, 'custom_') && (!mimi_is_developer() || !$custom)) throw new RuntimeException('Model tùy chỉnh không tồn tại hoặc chưa được cấp quyền.');
    if ($custom) {
        $keys = [$custom['api_key']];
        $model = trim((string)$custom['model']);
    } else {
        $keys = array_values(array_filter(mimi_api_keys_for_user($cfg), fn($key) => $key !== 'DAN_GEMINI_API_KEY_VAO_DAY'));
        $model = trim((string)($requestedModel ?: ($cfg['model'] ?? 'gemini-2.5-flash-lite')));
    }
    if (!$keys) throw new RuntimeException('Chưa cấu hình khóa AI cho tài khoản Mimi này.');
    $model = preg_replace('/[^A-Za-z0-9._-]/', '', $model) ?: 'gemini-2.5-flash-lite';
    $system = (string)($messages['_system'] ?? '');
    unset($messages['_system']);
    $contents = [];
    foreach (array_slice($messages, -10) as $m) {
        if (!is_array($m)) continue;
        $text = trim((string)($m['content'] ?? ''));
        if ($text === '') continue;
        $text = substr($text, 0, 6000);
        $role = (($m['role'] ?? 'user') === 'assistant') ? 'model' : 'user';
        $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
    }
    if (!$contents) throw new RuntimeException('Tin nhắn AI đang trống.');
    $body = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => (float)($cfg['temperature'] ?? 0.2),
            'maxOutputTokens' => (int)($cfg['max_output_tokens'] ?? 640),
        ],
    ];
    $endpoint = sprintf((string)$cfg['endpoint'], rawurlencode($model));
    $lastError = 'không xác định';
    foreach ($keys as $key) {
        try {
            [$status, $raw] = $GLOBALS['API_CONNECTION_POOL']->send('ai_gemini_' . $model, 'POST', $endpoint,
                ['Content-Type' => 'application/json', 'x-goog-api-key' => $key],
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null, 35, false);
        } catch (Throwable $e) { $lastError = 'không kết nối được: ' . $e->getMessage(); continue; }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) { $lastError = 'trả về JSON không hợp lệ'; continue; }
        if ($status >= 400) { $lastError = (string)($data['error']['message'] ?? ('HTTP ' . $status)); continue; }
        $answer = (string)($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if ($answer === '') { $lastError = 'không có nội dung trả lời'; continue; }
        return ['answer' => $answer, 'model' => $model, 'provider' => 'Mimi AI', 'usage' => $data['usageMetadata'] ?? null];
    }
    throw new RuntimeException('Các khóa AI hiện không kết nối được: ' . $lastError);
}

function json_out(array $data, int $httpCode = 200): void {
    if (!IS_CLI) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function run_action_impl(string $action, array $in): array {
    $GLOBALS['LOGS'] = [];
    try {
        switch ($action) {
            case 'ping': {
                return ['ok' => true, 'pong' => time(), 'php' => PHP_VERSION, 'logs' => $GLOBALS['LOGS']];
            }
            case 'ai_context': {
                $ctx = ai_code_context();
                return ['ok' => true, 'context' => $ctx['context'], 'actions' => $ctx['actions'], 'file' => $ctx['file'], 'size' => $ctx['size'], 'logs' => $GLOBALS['LOGS']];
            }
            case 'mimi_memory_get': {
                $file = mimi_data_file('mimi_memory.json', DIR_DATA . '/mimi_memory.json');
                $saved = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
                $messages = [];
                foreach (is_array($saved) ? $saved : [] as $m) {
                    if (!is_array($m) || !in_array(($m['role'] ?? ''), ['user','assistant'], true)) continue;
                    $content = trim((string)($m['content'] ?? ''));
                    if ($content !== '') $messages[] = ['role' => $m['role'], 'content' => substr($content, 0, 12000)];
                }
                return ['ok' => true, 'messages' => array_slice($messages, -40), 'logs' => $GLOBALS['LOGS']];
            }
            case 'mimi_memory_save': {
                $items = is_array($in['messages'] ?? null) ? $in['messages'] : [];
                $messages = [];
                foreach (array_slice($items, -40) as $m) {
                    if (!is_array($m) || !in_array(($m['role'] ?? ''), ['user','assistant'], true)) continue;
                    $content = trim((string)($m['content'] ?? ''));
                    if ($content !== '') $messages[] = ['role' => $m['role'], 'content' => substr($content, 0, 12000)];
                }
                $file = mimi_data_file('mimi_memory.json', DIR_DATA . '/mimi_memory.json');
                $dir = dirname($file); if (!is_dir($dir)) @mkdir($dir, 0775, true);
                if (@file_put_contents($file, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) throw new RuntimeException('Không lưu được bộ nhớ Mimi');
                return ['ok' => true, 'count' => count($messages), 'logs' => $GLOBALS['LOGS']];
            }
            case 'mimi_chats_get': {
                if (!mimi_auth_current()) return ['ok' => true, 'sessions' => [], 'guest' => true, 'logs' => $GLOBALS['LOGS']];
                $file = mimi_data_file('mimi_chats.json', DIR_DATA . '/mimi_chats.json');
                $saved = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
                $sessions = [];
                foreach (is_array($saved) ? $saved : [] as $s) {
                    if (!is_array($s) || empty($s['id']) || !is_array($s['messages'] ?? null)) continue;
                    $messages = [];
                    foreach (array_slice($s['messages'], -40) as $m) {
                        if (!is_array($m) || !in_array(($m['role'] ?? ''), ['user','assistant'], true)) continue;
                        $content = trim((string)($m['content'] ?? ''));
                        if ($content !== '') $messages[] = ['role' => $m['role'], 'content' => substr($content, 0, 12000)];
                    }
                    if ($messages) $sessions[] = ['id' => substr((string)$s['id'], 0, 80), 'title' => substr(trim((string)($s['title'] ?? 'Cuộc trò chuyện')), 0, 160), 'created_at' => (string)($s['created_at'] ?? ''), 'updated_at' => (string)($s['updated_at'] ?? $s['created_at'] ?? ''), 'messages' => $messages];
                }
                usort($sessions, fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
                return ['ok' => true, 'sessions' => array_slice($sessions, 0, 30), 'logs' => $GLOBALS['LOGS']];
            }
            case 'mimi_chats_save': {
                if (!mimi_auth_current()) return ['ok' => true, 'saved' => false, 'guest' => true, 'logs' => $GLOBALS['LOGS']];
                $items = is_array($in['sessions'] ?? null) ? $in['sessions'] : [];
                $sessions = [];
                foreach (array_slice($items, 0, 30) as $s) {
                    if (!is_array($s) || trim((string)($s['id'] ?? '')) === '' || !is_array($s['messages'] ?? null)) continue;
                    $messages = [];
                    foreach (array_slice($s['messages'], -40) as $m) {
                        if (!is_array($m) || !in_array(($m['role'] ?? ''), ['user','assistant'], true)) continue;
                        $content = trim((string)($m['content'] ?? ''));
                        if ($content !== '') $messages[] = ['role' => $m['role'], 'content' => substr($content, 0, 12000)];
                    }
                    if (!$messages) continue;
                    $sessions[] = ['id' => substr((string)$s['id'], 0, 80), 'title' => substr(trim((string)($s['title'] ?? 'Cuộc trò chuyện')), 0, 160), 'created_at' => substr((string)($s['created_at'] ?? ''), 0, 40), 'updated_at' => substr((string)($s['updated_at'] ?? ''), 0, 40), 'messages' => $messages];
                }
                $file = mimi_data_file('mimi_chats.json', DIR_DATA . '/mimi_chats.json');
                $dir = dirname($file); if (!is_dir($dir)) @mkdir($dir, 0775, true);
                if (@file_put_contents($file, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) throw new RuntimeException('Không lưu được lịch sử trò chuyện Mimi');
                return ['ok' => true, 'saved' => true, 'count' => count($sessions), 'logs' => $GLOBALS['LOGS']];
            }
            case 'mimi_chats_clear': {
                if (!mimi_auth_current()) return ['ok' => true, 'cleared' => false, 'guest' => true, 'logs' => $GLOBALS['LOGS']];
                mimi_with_lock('mimi_chats', function (): void {
                    $file = mimi_data_file('mimi_chats.json', DIR_DATA . '/mimi_chats.json');
                    $dir = dirname($file); if (!is_dir($dir)) @mkdir($dir, 0775, true);
                    if (@file_put_contents($file, '[]', LOCK_EX) === false) throw new RuntimeException('Không xóa được lịch sử trò chuyện Mimi');
                });
                return ['ok' => true, 'cleared' => true, 'count' => 0, 'logs' => $GLOBALS['LOGS']];
            }
            case 'mimi_models_get': {
                $developer = mimi_is_developer();
                return ['ok' => true, 'models' => $developer ? mimi_models_load(true) : [], 'developer' => $developer, 'logs' => $GLOBALS['LOGS']];
            }
            case 'mimi_model_add': {
                $added = mimi_model_add((string)($in['label'] ?? ''), (string)($in['model'] ?? ''), (string)($in['api_key'] ?? ''));
                return ['ok' => true, 'model' => $added, 'models' => mimi_models_load(true), 'logs' => $GLOBALS['LOGS']];
            }
            case 'mimi_model_delete': {
                $models = mimi_model_delete((string)($in['id'] ?? ''));
                return ['ok' => true, 'models' => $models, 'logs' => $GLOBALS['LOGS']];
            }
            case 'ai_chat': {
                $messages = is_array($in['messages'] ?? null) ? $in['messages'] : [];
                $lastUserText = '';
                foreach (array_reverse($messages) as $candidate) { if (is_array($candidate) && ($candidate['role'] ?? '') === 'user') { $lastUserText = trim((string)($candidate['content'] ?? '')); break; } }
                $needsSource = (bool)preg_match('/api\\.php|code|hàm|function|lỗi|sửa|thêm|xóa file|module|đoạn mã|bug/i', $lastUserText);
                $ctx = ai_code_context($needsSource);
                $messages['_system'] = "Bạn là một trợ lý AI thân thiện đang hỗ trợ người mới dùng web. Hãy nói chuyện tự nhiên như một người hướng dẫn thật, không nói kiểu máy móc. Chỉ dùng tiếng Việt phổ thông, câu ngắn, từ dễ hiểu. Trả lời đúng trọng tâm trước, sau đó mới thêm một câu giải thích nếu cần. Không lặp lại nguyên câu hỏi của người dùng. Không dùng emoji, ký tự trang trí, chữ Trung Quốc, chữ Nhật, chữ Hàn, tiếng Ả Rập, ký hiệu toán học hoặc định dạng rối. Có thể dùng một chút cảm xúc bằng từ ngữ tự nhiên như: Ừ, mình hiểu rồi; Được, mình làm cùng bạn; Chỗ này cần chú ý; Xong rồi. Không được nói quá thân mật, không tâng bốc và không giả vờ đã làm nếu chưa chạy thật.\n\nKhi người dùng hỏi kiến thức, trả lời tự nhiên theo thứ tự: nói ngay đáp án, rồi mới nói cách làm nếu cần. Không bắt buộc dùng tiêu đề hoặc khuôn mẫu cứng. Nếu người dùng mới bắt đầu, giải thích từ chuyên môn ngay lần đầu bằng một câu đời thường. Nếu người dùng đang bực hoặc báo lỗi, trước hết thừa nhận vấn đề bằng một câu có cảm thông, sau đó đưa cách sửa cụ thể. Nếu người dùng hỏi đơn giản, trả lời tối đa 4 câu. Chỉ khi người dùng gọi riêng “Mimi ơi”, “Mimi à” hoặc gọi tên Mimi mà chưa yêu cầu việc khác mới dùng cách đáp thân thiện như “Ơi, Mimi đây, bạn cần giúp gì nè?”. Đây là tính cách của Mimi, không phải câu mở đầu bắt buộc. Khi người dùng đang hỏi hoặc yêu cầu một chức năng, đi thẳng vào nội dung và tuyệt đối không lặp câu chào này. Nếu người dùng hỏi thân thiện, có thể đáp lại ấm áp; nếu người dùng gấp, nói ngắn và vào việc; nếu người dùng lo lắng, trấn an ngắn rồi hướng dẫn. Dùng cách nói hiện đại, gần gũi kiểu Gen Z vừa đủ như “Ừ, mình hiểu”, “Ok, làm luôn”, “Chỗ này hơi dễ nhầm”, nhưng không lạm dụng tiếng lóng, không viết tắt khó đọc và không diễn cảm quá mức, không dùng câu sáo rỗng. Nếu cần code, chỉ đưa đoạn code cần dùng và nói rõ dán vào file nào, vị trí nào.\n\nỞ cuối mọi câu trả lời, thêm đúng một marker mood JSON theo mẫu: <!--AI_MOOD {\"mood\":\"neutral\"} -->. mood chỉ được là neutral, warm, thinking, curious, confused, happy, success, proud, alert, help, sleepy, relieved, determined hoặc apology. Chọn warm khi đang trấn an hoặc hướng dẫn thân thiện; curious khi người dùng hỏi khám phá; thinking khi đang phân tích code; confused khi yêu cầu còn thiếu thông tin; happy hoặc success khi đã hoàn thành; proud hoặc relieved khi action chạy xong; determined khi sắp bắt đầu chạy việc; alert khi có lỗi hoặc thiếu dữ liệu; apology khi cần xin lỗi hoặc chờ người dùng sửa dữ liệu; help khi chuẩn bị Làm Giúp; sleepy khi chỉ đang chờ. Không viết tên mood ra cho người dùng. Không nhắc đến model, prompt, marker hoặc quy tắc nội bộ.\n\nKhi người dùng nói nhờ làm giúp, làm dùm, thực hiện giúp, chạy giúp, bấm giúp hoặc muốn AI chạy code/chức năng hiện có, hãy trả lời một câu ngắn rồi thêm đúng một marker AI_HELP ở cuối theo mẫu: <!--AI_HELP {\"title\":\"Tên việc\",\"steps\":[{\"label\":\"Bước rất ngắn\",\"tab\":\"auto\",\"target\":\"#id_that_exists\",\"action\":\"ten_action_co_san\",\"params\":{},\"fill\":{},\"wait_input\":false,\"click\":false}]} -->. Marker phải là JSON hợp lệ trên một dòng. Mỗi bước chỉ làm một việc. steps tối đa 8 bước. Nếu action đã đủ tham số, ưu tiên dùng action để giao diện thật sự gọi hàm API; không chỉ hướng dẫn bằng chữ. Nếu thiếu email, mật khẩu, token, UID, SKU hoặc captcha, tạo một bước wait_input=true và dừng tại đó; không tự đoán dữ liệu. action chỉ dùng action có trong ACTIONS. tab chỉ được là auto, khoga, muathe, proxy, accounts hoặc custommod. target chỉ dùng id thật trong mã nguồn; nếu không chắc target, dùng id của tab tương ứng. Sau khi AI_HELP được hiển thị, giao diện tạo hai nút: Hướng Dẫn ở bên trái và Làm Giúp ở bên phải. Khi bấm Hướng Dẫn, panel chat Mimi thu nhỏ lại thành một thanh hướng dẫn nổi. Nếu người dùng đang ở sai mục và đang dùng giao diện điện thoại, hãy để giao diện tự mở nút menu ba gạch rồi thanh hướng dẫn đánh dấu nút mục cần chọn; nếu người dùng đã ở đúng mục thì bỏ qua bước menu và chọn mục. Sau đó thanh tự cuộn tới đúng ô hoặc nút, đánh dấu vị trí và hiển thị nội dung Bước x/y cùng nút Bước tiếp theo. Hướng Dẫn chỉ chỉ đường, không chạy action và không tự điền dữ liệu. Khi bấm Làm Giúp, chạy lần lượt từng bước trong chat, cập nhật trạng thái Bước hiện tại, giữ Mimi trên màn hình và không tự điều hướng người dùng sang tab khác. Không ép mọi action xuống Console; chỉ action kiểm tra/log mới cần ghi Console, còn proxy, tài khoản, thêm, sửa hoặc xóa xử lý nền và báo kết quả ngay trong chat. Nếu người dùng yêu cầu lặp cùng một chức năng từ 2 đến 5 lần, thêm batch_count đúng số lượng vào AI_HELP cùng action đó; hệ thống sẽ chạy các lần cùng lúc và trả kết quả từng lần. Với yêu cầu tạo từ 2 đến 5 tài khoản Funpass, chỉ dùng action fp_create_funpass, hệ thống sẽ kiểm tra đủ proxy US dùng được và phân bổ một proxy khác nhau cho từng tài khoản; nếu thiếu proxy thì không chạy và báo rõ cần bổ sung bao nhiêu. Không tạo AI_ACTION cùng lúc với AI_HELP.\n\nNếu người dùng muốn AI tự viết hoặc sửa code, hãy phân biệt rõ: AI chỉ sinh code trong khung chat, còn Làm Giúp có thể truy cập, thêm, cập nhật hoặc xóa dữ liệu bằng các action đã có trong api.php khi người dùng yêu cầu rõ. Không tự bịa action, không chạy eval hoặc code ngoài danh sách ACTIONS, không nói rằng AI đã ghi file nếu chưa có action ghi file. Với thao tác xóa, nêu rõ đối tượng sắp xóa trong một câu ngắn trước khi chạy và dùng module_delete khi xóa file PHP module; không dùng modules_remove nếu người dùng muốn xóa file thật. Sau action tạo tài khoản thành công, giao diện sẽ tự gửi tiếp thông tin email và mật khẩu nếu server trả về dữ liệu tài khoản, nên câu trả lời trước action chỉ cần ngắn gọn.

Nếu người dùng chọn nút + và gửi file PHP, giao diện sẽ hỏi xác nhận ngay trong Mimi: “Bạn muốn thêm một mục mới với chức năng PHP này?”. Chỉ khi người dùng bấm Xác nhận thêm mới gọi module_upload; không tự upload khi chưa xác nhận. File hợp lệ sẽ được đăng ký thành mục dưới Funpass Auto và xuất hiện trong danh sách File PHP đã thêm ở mục Tài Khoản. Nếu người dùng muốn xóa file PHP, hỏi xác nhận rõ tên file rồi tạo AI_HELP với module_delete; api.php/api3.php và các file hệ thống không được xóa.

Nếu người dùng muốn thêm proxy Mỹ hoặc Việt Nam, hãy hỏi chuỗi proxy cụ thể nếu họ chưa gửi; chuỗi thường có dạng IP:PORT, ví dụ 42.112.34.34:3434; nếu có xác thực thì có thể dùng host:port:user:pass hoặc user:pass@host:port. Giải thích ngắn gọn: proxy US thường dùng cho tạo Funpass/email, proxy VN thường dùng cho CPI hoặc tác vụ khu vực Việt Nam; không tự đổi US thành VN nếu người dùng chưa yêu cầu. Khi đã có chuỗi rõ ràng, tạo AI_HELP gồm một bước đến tab proxy, target đúng pool và action proxy_add với params {pool:'us' hoặc 'vn', proxy:'chuỗi proxy'}. Không tự bịa proxy.

Nếu người dùng hỏi tài khoản vừa tạo ở đâu, hãy nói ngắn gọn rằng có thể xem nhanh email và mật khẩu trong chat. Nếu họ hỏi chi tiết hoặc muốn mở mục tài khoản, để giao diện xử lý điều hướng và đánh dấu dòng email; không tự in token hoặc dữ liệu nhạy cảm dài trong câu trả lời.\n\nNGỮ CẢNH MÃ NGUỒN api.php:\n" . $ctx['context'];
                $messages['_system'] .= "\\n\\nQUY TẮC DỄ HIỂU: Khi người dùng hỏi ‘có những chức năng gì’, ‘liệt kê chức năng’, ‘api làm được gì’ hoặc câu tương tự, chỉ trả lời bằng tên nhóm và mô tả đời thường trong phần CHỨC NĂNG GIẢI THÍCH CHO NGƯỜI DÙNG. Không liệt kê mã như ping, fp_create_funpass, mt_buy_auto, mimi_chats_get hoặc danh sách ACTIONS. Chỉ nêu mã kỹ thuật khi người dùng nói rõ họ là người viết code và muốn xem tên action/API. Khi không chắc người dùng có biết lập trình hay không, ưu tiên lời giải thích đời thường và hỏi họ muốn hướng dẫn thao tác nào.\\n\\nPHONG CÁCH MỞ RỘNG: Mimi là một cô trợ lý Gen Z nữ có cá tính nhẹ, thông minh và biết lắng nghe. Có thể trả lời các câu hỏi ngoài phạm vi api.php như kiến thức phổ thông, học tập, công nghệ, viết nội dung, đời sống và trò chuyện thường ngày. Không tự nhận biết điều không chắc; nói rõ khi cần kiểm tra thêm. Không trả lời theo một mẫu cố định: thay đổi cách mở đầu, nhịp câu và ví dụ tùy ngữ cảnh, nhưng luôn rõ ràng, lịch sự và không lạm dụng tiếng lóng. Nếu người dùng hỏi nghiêm túc thì trả lời nghiêm túc; nếu hỏi vui thì có thể dí dỏm vừa phải. Không lặp lại cùng một câu trả lời giữa các lượt nếu câu hỏi khác nhau. Tuyệt đối không bịa rằng đã gọi API, sửa file hoặc hoàn thành việc khi chưa có action thành công.\\n";
                $roleHint = mimi_is_developer() ? "\nBạn đang phục vụ Developer MimiVip01. Developer có thể quản lý account, log, proxy và module của Apimimi01, Apimimi05 hoặc Apimimi10 khi người dùng nêu rõ tên mục tiêu. Nếu request có tên tài khoản mục tiêu, đưa _target_user vào params của từng step; không đưa khóa API hoặc cookie vào câu trả lời.\n" : "\nNgười dùng hiện tại chỉ được thao tác trong vùng dữ liệu của chính mình. Không tạo _target_user và không hướng dẫn vượt quyền.\n";
                $messages['_system'] .= "\n\nQUY TẮC ANDROID ID: Nếu người dùng cung cấp Android ID hoặc nói rõ muốn dùng Android ID nào, hãy giữ nguyên chuỗi hex đó và đưa vào params với khóa android_id cho action tạo/đăng nhập FunPass, LDPlayer, flow Khoga hoặc Mua Thẻ. Không tự đoán Android ID và không đưa IMEI, IMSI, SN, MAC hay Google Advertising ID vào params nếu người dùng không yêu cầu rõ. Nếu người dùng chỉ chọn một tài khoản đã lưu, dùng androidid đã lưu của tài khoản đó khi tạo bước đăng nhập.\n";
                $messages['_system'] .= $roleHint;
                $r = ai_gemini_chat($messages, (string)($in['model'] ?? ''));
                return ['ok' => true, 'answer' => $r['answer'], 'model' => $r['model'], 'provider' => $r['provider'], 'usage' => $r['usage'], 'logs' => $GLOBALS['LOGS']];
            }
            /* ---------- PROXY CONFIG ---------- */
            case 'proxy_get': {
                $flags = proxy_load_flags();
                $features = [];
                foreach (PROXY_FEATURES as $k => $m) {
                    $features[] = ['key' => $k, 'label' => $m['label'], 'pool' => $m['pool'], 'enabled' => $flags[$k]];
                }
                $pst = pstate_load();
                $usList = proxy_load_list(FILE_PROXY);
                $vnList = proxy_load_list(FILE_PROXY_VN);
                $badUs = $pst['bad']['us'] ?? [];
                $badVn = $pst['bad']['vn'] ?? [];
                $goodUs = array_values(array_filter($usList, fn($p) => !in_array($p, $badUs, true)));
                $goodVn = array_values(array_filter($vnList, fn($p) => !in_array($p, $badVn, true)));
                return ['ok' => true, 'features' => $features,
                        'proxy_us' => $usList,
                        'proxy_vn' => $vnList,
                        'bad_us' => array_values($badUs),
                        'bad_vn' => array_values($badVn),
                        'cur_us' => $goodUs ? $goodUs[(int)($pst['cur']['us'] ?? 0) % count($goodUs)] : null,
                        'cur_vn' => $goodVn ? $goodVn[(int)($pst['cur']['vn'] ?? 0) % count($goodVn)] : null,
                        'logs' => $GLOBALS['LOGS']];
            }
            case 'proxy_add': {
                $pool = strtolower(trim((string)($in['pool'] ?? $in['country'] ?? '')));
                if (in_array($pool, ['my','mỹ','usa','us','america','united states'], true)) $pool = 'us';
                if (in_array($pool, ['vn','việt nam','viet nam','vietnam'], true)) $pool = 'vn';
                if (!in_array($pool, ['us','vn'], true)) throw new RuntimeException('Pool proxy phải là us (Mỹ) hoặc vn (Việt Nam)');
                $proxy = trim((string)($in['proxy'] ?? $in['value'] ?? ''));
                if ($proxy === '') throw new RuntimeException('Cần gửi proxy dạng IP:PORT, ví dụ 42.112.34.34:3434');
                $normalizedProxy = proxy_normalize_entry($proxy);
                if ($normalizedProxy === null) throw new RuntimeException('Proxy không hợp lệ. Dùng IP:PORT, ví dụ 42.112.34.34:3434');
                $proxy = $normalizedProxy;
                $file = $pool === 'vn' ? FILE_PROXY_VN : FILE_PROXY;
                $list = proxy_load_list($file);
                if (!in_array($proxy, $list, true)) {
                    file_put_contents($file, ($list ? implode("\n", $list) . "\n" : '') . $proxy . "\n", LOCK_EX);
                    fcache_forget('list:' . $file);
                }
                return ['ok' => true, 'pool' => $pool, 'proxy' => $proxy, 'msg' => 'Đã thêm proxy vào pool ' . ($pool === 'us' ? 'Mỹ' : 'Việt Nam'), 'logs' => $GLOBALS['LOGS']];
            }
            case 'proxy_reset_state': {
                pstate_save(['cur' => [], 'bad' => ['us' => [], 'vn' => []]]);
                return ['ok' => true, 'msg' => 'Đã xóa toàn bộ dấu × (trạng thái lỗi) của proxy', 'logs' => $GLOBALS['LOGS']];
            }
            case 'proxy_save': {
                proxy_save_flags($in['flags'] ?? []);
                if (isset($in['proxy_us'])) { $usProxyLines = proxy_normalize_text((string)$in['proxy_us']); file_put_contents(FILE_PROXY, $usProxyLines ? implode("\n", $usProxyLines) . "\n" : '', LOCK_EX); fcache_forget('list:' . FILE_PROXY); }
                if (isset($in['proxy_vn'])) { $vnProxyLines = proxy_normalize_text((string)$in['proxy_vn']); file_put_contents(FILE_PROXY_VN, $vnProxyLines ? implode("\n", $vnProxyLines) . "\n" : '', LOCK_EX); fcache_forget('list:' . FILE_PROXY_VN); }
                // Đồng bộ trạng thái sticky với danh sách mới: bỏ dấu × của proxy đã xóa, kẹp con trỏ vào proxy còn dùng được
                $pst = pstate_load();
                foreach (['us' => FILE_PROXY, 'vn' => FILE_PROXY_VN] as $pool => $f) {
                    $list = proxy_load_list($f);
                    $pst['bad'][$pool] = array_values(array_intersect((array)($pst['bad'][$pool] ?? []), $list));
                    $good = array_values(array_diff($list, $pst['bad'][$pool]));
                    if (!$list) { $pst['cur'][$pool] = 0; continue; }
                    if (!$good) { $pst['bad'][$pool] = []; $good = $list; } // tất cả bị × → reset để tránh kẹt
                    $cur = (int)($pst['cur'][$pool] ?? 0);
                    $pst['cur'][$pool] = $cur % count($good);
                }
                pstate_save($pst);
                return ['ok' => true, 'msg' => 'Đã lưu cấu hình proxy', 'logs' => $GLOBALS['LOGS']];
            }

            /* ---------- MODULE FUNPASS / KHOGA ---------- */
            case 'fp_create_funpass': {
                $acc = create_funpass_account($in['proxy'] ?? null, $in['captcha'] ?? null, $in['ctx'] ?? null, $in['android_id'] ?? ($in['androidid'] ?? null));
                return ['ok' => true, 'data' => $acc, 'logs' => $GLOBALS['LOGS']];
            }
            case 'fp_create_ldplayer': {
                $acc = create_ldplayer_account((string)($in['email'] ?? ''), (string)($in['temp_token'] ?? ''),
                    $in['proxy'] ?? null, $in['captcha'] ?? null, $in['ctx'] ?? null, $in['android_id'] ?? ($in['androidid'] ?? null));
                return ['ok' => true, 'data' => $acc, 'logs' => $GLOBALS['LOGS']];
            }
            case 'fp_login_funpass': {
                $acc = login_funpass((string)($in['email'] ?? ''), (string)($in['password'] ?? ''), $in['proxy'] ?? null, $in['android_id'] ?? ($in['androidid'] ?? null));
                return ['ok' => true, 'data' => $acc, 'logs' => $GLOBALS['LOGS']];
            }
            case 'fp_login_ldplayer': {
                $acc = login_ldplayer((string)($in['email'] ?? ''), (string)($in['password'] ?? ''), $in['proxy'] ?? null, $in['android_id'] ?? ($in['androidid'] ?? null));
                return ['ok' => true, 'data' => $acc, 'logs' => $GLOBALS['LOGS']];
            }
            case 'fp_wallet': {
                $bal = get_wallet_info((string)($in['uid'] ?? ''), (string)($in['token'] ?? ''), $in['proxy'] ?? null);
                return ['ok' => true, 'balance' => $bal, 'logs' => $GLOBALS['LOGS']];
            }
            case 'fp_cpi_ads': {
                $acc = $in['account'] ?? [];
                $ads = get_cpi_ad_list((string)$acc['uid'], $acc['device_id_header'], $acc['android_id'], uuid_str(), $in['proxy'] ?? null);
                return ['ok' => true, 'ads' => $ads, 'logs' => $GLOBALS['LOGS']];
            }
            case 'fp_cpi_run': {
                $earned = run_cpi_tasks($in['account'] ?? [], $in['proxy'] ?? null);
                return ['ok' => true, 'earned' => $earned, 'logs' => $GLOBALS['LOGS']];
            }
            case 'fp_transfer_detail': {
                $d = transfer_detail((string)$in['funpassUid'], (string)$in['funpassToken'], (string)$in['ldUid'], (string)$in['ldToken'], $in['proxy'] ?? null);
                return ['ok' => $d !== null, 'data' => $d, 'logs' => $GLOBALS['LOGS']];
            }
            case 'fp_transfer_execute': {
                $d = transfer_execute((string)$in['funpassUid'], (string)$in['funpassToken'], (string)$in['ldUid'], (string)$in['ldToken'], $in['proxy'] ?? null);
                return ['ok' => $d !== null, 'data' => $d, 'logs' => $GLOBALS['LOGS']];
            }
            case 'flow_khoga_full': {
                $r = flow_khoga_full($in);
                return ['ok' => true, 'data' => $r, 'logs' => $GLOBALS['LOGS']];
            }
            case 'flow_funpass_once': {
                $r = flow_funpass_once($in);
                return ['ok' => true, 'data' => $r, 'logs' => $GLOBALS['LOGS']];
            }

            /* ---------- MODULE MUATHE ---------- */
            case 'mt_login': {
                $r = mt_login((string)($in['username'] ?? ''), (string)($in['password'] ?? ''), $in['android_id'] ?? ($in['androidid'] ?? null));
                if (!empty($r['ok'])) mt_sync_time();
                $res = ['ok' => !empty($r['ok']), 'data' => $r, 'logs' => $GLOBALS['LOGS']];
                if (empty($r['ok'])) $res['error'] = 'Đăng nhập thất bại (code: ' . json_encode($r['code'] ?? null) . ')';
                return $res;
            }
            case 'mt_session': {
                return ['ok' => true, 'data' => mt_state(), 'logs' => $GLOBALS['LOGS']];
            }
            case 'mt_sync': {
                // syncTime() — muathe.js: GET appcenter.ldplayer.net/ntp/time -> serverTimeOffset
                $off = mt_sync_time();
                return ['ok' => true, 'data' => mt_state(), 'offset' => $off, 'logs' => $GLOBALS['LOGS']];
            }
            case 'mt_logout': {
                mt_save_config([]);
                return ['ok' => true, 'msg' => 'Đã xóa phiên đăng nhập', 'logs' => $GLOBALS['LOGS']];
            }
            case 'mt_balance': {
                $b = mt_get_balance();
                return ['ok' => $b !== null, 'balance' => $b, 'logs' => $GLOBALS['LOGS']];
            }
            case 'mt_skus': {
                return ['ok' => true, 'skus' => mt_get_all_item_list(), 'logs' => $GLOBALS['LOGS']];
            }
            case 'mt_available_skus': {
                return ['ok' => true, 'data' => mt_get_available_skus(), 'logs' => $GLOBALS['LOGS']];
            }
            case 'mt_gift_list': {
                $d = mt_get_gift_list((int)($in['page'] ?? 1), (int)($in['size'] ?? 10));
                return ['ok' => $d !== null, 'data' => $d, 'logs' => $GLOBALS['LOGS']];
            }
            case 'mt_gift_detail': {
                $d = mt_get_gift_detail((string)($in['orderNo'] ?? ''));
                return ['ok' => $d !== null, 'data' => $d, 'logs' => $GLOBALS['LOGS']];
            }
            case 'mt_create_order': {
                $d = mt_create_gift_order((int)($in['skuId'] ?? 0), (int)($in['num'] ?? 1));
                return ['ok' => $d !== null && ($d['code'] ?? null) === 200, 'data' => $d, 'logs' => $GLOBALS['LOGS']];
            }
            case 'mt_buy_auto': {
                $d = mt_buy_gift_auto((bool)($in['confirm'] ?? false));
                return ['ok' => $d !== null, 'data' => $d, 'logs' => $GLOBALS['LOGS']];
            }
            case 'mt_snipe': {
                $d = mt_snipe_gift_order((int)($in['skuId'] ?? 0), (int)($in['num'] ?? 1),
                    (int)($in['delayMs'] ?? 1000), (int)($in['maxAttempts'] ?? 100));
                return ['ok' => $d !== null, 'data' => $d, 'logs' => $GLOBALS['LOGS']];
            }
            /* ---------- LƯU TÀI KHOẢN ---------- */
            case 'acc_list': {
                return ['ok' => true, 'accounts' => acc_load(), 'logs' => $GLOBALS['LOGS']];
            }
            case 'acc_save': {
                // Nhận cả uid/token/androidid/temp_token/serverTimeOffset khi lưu từ bảng nhận dạng dưới console
                $extra = [];
                foreach (['uid','token','androidid','temp_token','serverTimeOffset','created_at'] as $k) {
                    if (isset($in[$k]) && $in[$k] !== '') $extra[$k] = $in[$k];
                }
                $email = (string)($in['email'] ?? '');
                $password = (string)($in['password'] ?? '');
                $type = (string)($in['type'] ?? 'ldplayer');
                // Ô JSON duy nhất ở mục Tài Khoản: dán nguyên khối theo MẪU → parse và điền đủ field.
                // Quy tắc nhận diện loại khi type = auto: có temp_token (chuỗi không rỗng) → Funpass,
                // ngược lại → LDPlayer (tài khoản Funpass luôn đi kèm temp_token của email ảo temp-mail.io).
                $rawJson = trim((string)($in['json'] ?? ''));
                if ($rawJson !== '') {
                    $pj = json_decode($rawJson, true);
                    if (!is_array($pj)) throw new RuntimeException("JSON không hợp lệ — kiểm tra lại dấu ngoặc/dấu phẩy của đoạn đã dán");
                    $username = trim((string)($pj['username'] ?? ($pj['email'] ?? '')));
                    if ($username === '') throw new RuntimeException("JSON thiếu trường \"username\" (mail)");
                    $email    = $username;
                    $password = (string)($pj['password'] ?? '');
                    if ($type === 'auto' || $type === '') {
                        $tt = trim((string)($pj['temp_token'] ?? ''));
                        $type = ($tt !== '') ? 'funpass' : 'ldplayer';
                    }
                    foreach (['uid','token','androidid','temp_token','serverTimeOffset','created_at'] as $k) {
                        if (isset($pj[$k]) && $pj[$k] !== '') $extra[$k] = ($k === 'serverTimeOffset') ? (int)$pj[$k] : (string)$pj[$k];
                    }
                    if (isset($pj['device_id_header']) && $pj['device_id_header'] !== '') $extra['device_id_header'] = (string)$pj['device_id_header'];
                }
                $accs = acc_upsert($email, $password, $type, $extra);
                logmsg("  [TÀI KHOẢN] Đã lưu: " . trim($email) . " (" . $type . ")");
                return ['ok' => true, 'msg' => 'Đã lưu tài khoản', 'accounts' => $accs, 'logs' => $GLOBALS['LOGS']];
            }
            case 'acc_delete': {
                $accs = acc_delete((int)($in['idx'] ?? -1));
                return ['ok' => true, 'msg' => 'Đã xóa tài khoản', 'accounts' => $accs, 'logs' => $GLOBALS['LOGS']];
            }

            /* ---------- LỊCH SỬ LOG ---------- */
            case 'runlog_list': {
                return ['ok' => true, 'history' => array_reverse(runlog_load()), 'logs' => $GLOBALS['LOGS']];
            }
            case 'runlog_clear': {
                $file = mimi_data_file('run_logs.json', FILE_RUNLOGS);
                mimi_with_lock('run_logs_clear', function () use ($file): void {
                    if (@file_put_contents($file, '[]', LOCK_EX) === false) throw new RuntimeException('Không xóa được lịch sử log');
                    fcache_forget('runlogs:' . $file);
                });
                return ['ok' => true, 'msg' => 'Đã xóa lịch sử log', 'logs' => $GLOBALS['LOGS']];
            }

            /* ---------- UPLOAD FILE PHP TỪ MIMI ---------- */
            case 'module_upload': {
                $up = $_FILES['module'] ?? null;
                $name = '';
                $tmp = '';
                if (is_array($up)) {
                    if (($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload file thất bại (mã ' . (int)($up['error'] ?? -1) . ')');
                    $name = module_normalize_filename((string)($up['name'] ?? ''));
                    $size = (int)($up['size'] ?? 0);
                    if ($size <= 0 || $size > 2 * 1024 * 1024) throw new RuntimeException('File PHP phải lớn hơn 0 và không quá 2 MB');
                    $tmp = (string)($up['tmp_name'] ?? '');
                    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('File upload không hợp lệ');
                } else {
                    // Node CLI bridge cannot populate PHP's $_FILES; accept the same file
                    // as bounded base64 while preserving the existing module workflow.
                    $name = module_normalize_filename((string)($in['module_name'] ?? ''));
                    $encoded = (string)($in['module_base64'] ?? '');
                    if ($name === '' || $encoded === '') throw new RuntimeException('Chưa nhận được file PHP');
                    $raw = base64_decode($encoded, true);
                    if ($raw === false || strlen($raw) <= 0 || strlen($raw) > 2 * 1024 * 1024) throw new RuntimeException('File PHP phải lớn hơn 0 và không quá 2 MB');
                    $tmp = tempnam(sys_get_temp_dir(), 'mimi-upload-');
                    if ($tmp === false || @file_put_contents($tmp, $raw, LOCK_EX) === false) throw new RuntimeException('Không thể đọc file PHP');
                    register_shutdown_function(static function () use ($tmp): void { @unlink($tmp); });
                }
                $path = mimi_data_file($name, DIR_DATA . '/' . $name);
                $userDir = dirname($path);
                if (is_file($path)) throw new RuntimeException("File {$name} đã tồn tại, không ghi đè file cũ");
                if (!is_writable($userDir)) { @chmod($userDir, 0775); }
                $saved = is_array($up) ? @move_uploaded_file($tmp, $path) : @copy($tmp, $path);
                if (!is_writable($userDir) || !$saved) throw new RuntimeException('Không thể lưu file PHP vào thư mục riêng của tài khoản Mimi');
                @chmod($path, 0664);
                try { $mods = module_register($name, (string)($in['label'] ?? '')); }
                catch (Throwable $e) { @unlink($path); throw $e; }
                logmsg("  [FILE] Đã upload module: {$name}");
                return ['ok' => true, 'msg' => "Đã thêm module {$name}", 'data' => ['filename' => $name, 'label' => (string)($in['label'] ?? pathinfo($name, PATHINFO_FILENAME)), 'modules' => $mods], 'logs' => $GLOBALS['LOGS']];
            }
            /* ---------- TẠO FILE CHỨC NĂNG MỚI (rỗng) ---------- */
            case 'create_empty_file': {
                $rawName = trim((string)($in['filename'] ?? ''));
                $label = trim((string)($in['label'] ?? ''));
                if ($label === '') {
                    return ['ok' => false, 'error' => 'Cần đặt tên mục (hiển thị trên menu)', 'logs' => $GLOBALS['LOGS']];
                }
                // Nếu AI chỉ nhận được tên mục mà chưa có tên file, tự sinh filename từ nhãn.
                if ($rawName === '') $rawName = $label;
                $name = module_normalize_filename($rawName);
                $path = mimi_data_file($name, DIR_DATA . '/' . $name);
                $createdNew = false;
                if (!is_file($path)) {
                    $userDir = dirname($path);
                    if (!is_writable($userDir)) {
                        @chmod($userDir, 0775);
                        if (!is_writable($userDir)) {
                            return ['ok' => false, 'error' => 'Thư mục không có quyền ghi', 'logs' => $GLOBALS['LOGS']];
                        }
                    }
                    // Stub tối giản (không heredoc phức tạp) — user tự thêm code sau
                    $stub = "<?php\n/** Module độc lập: " . str_replace(['*/', "\n"], ['', ' '], $label) . " — file {$name}\n * api.php chính không đọc/sửa file này.\n */\n"
                        . "?><!DOCTYPE html><html lang=\"vi\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
                        . "<title>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</title>"
                        . "<style>body{font-family:system-ui,sans-serif;background:#0b1020;color:#e8ecff;margin:0;padding:24px}"
                        . ".box{max-width:720px;margin:40px auto;padding:24px;border:1px solid #2a3555;border-radius:14px;background:#121a33}"
                        . "h1{font-size:18px;margin:0 0 10px}p{color:#9aa3c7;font-size:14px;line-height:1.55}</style></head><body>"
                        . "<div class=\"box\"><h1>📦 " . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</h1>"
                        . "<p>Module độc lập (<code>" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</code>).</p>"
                        . "<p>Mở file trong thư mục chứa api.php để thêm giao diện &amp; code. api.php không can thiệp nội dung này.</p>"
                        . "</div></body></html>\n";
                    $ok = @file_put_contents($path, $stub, LOCK_EX);
                    if ($ok === false) {
                        return ['ok' => false, 'error' => "Không tạo được file {$name}", 'logs' => $GLOBALS['LOGS']];
                    }
                    @chmod($path, 0664);
                    $createdNew = true;
                    logmsg("  [FILE] Đã tạo file: {$path}");
                } else {
                    // File đã có trên đĩa → chỉ đăng ký/cập nhật mục menu (không ghi đè nội dung user đã sửa)
                    logmsg("  [FILE] File đã tồn tại — chỉ đăng ký mục menu: {$name}");
                }
                // Đăng ký / cập nhật mục menu (custom_modules.json)
                try {
                    $mods = modules_load();
                    $mods = array_values(array_filter($mods, fn($m) => ($m['file'] ?? '') !== $name));
                    $mods[] = [
                        'file'  => $name,
                        'label' => $label,
                        'created' => (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('d/m/Y H:i:s'),
                    ];
                    modules_save($mods);
                } catch (Throwable $e) {
                    return [
                        'ok' => false,
                        'error' => 'File ' . ($createdNew ? 'đã tạo' : 'đã có') . " ({$name}) nhưng không ghi được menu: " . $e->getMessage(),
                        'data' => ['filename' => $name, 'label' => $label, 'path' => $path],
                        'logs' => $GLOBALS['LOGS'],
                    ];
                }
                logmsg("  [FILE] Mục menu «{$label}» → dưới Proxy");
                return [
                    'ok' => true,
                    'msg' => ($createdNew ? "Đã tạo file {$name}" : "File {$name} đã có") . " + mục «{$label}» (dưới Proxy). Sửa nội dung file trong thư mục nếu cần.",
                    'data' => ['path' => $path, 'filename' => $name, 'label' => $label, 'modules' => $mods, 'created_new' => $createdNew],
                    'logs' => $GLOBALS['LOGS'],
                ];
            }
            case 'modules_list': {
                return ['ok' => true, 'modules' => modules_load(), 'logs' => $GLOBALS['LOGS']];
            }
            case 'modules_remove': {
                // Tương thích nút cũ: chỉ gỡ khỏi menu, không xóa file.
                $name = basename((string)($in['filename'] ?? ''));
                $mods = array_values(array_filter(modules_load(), fn($m) => ($m['file'] ?? '') !== $name));
                modules_save($mods);
                return ['ok' => true, 'msg' => "Đã gỡ «{$name}» khỏi menu", 'modules' => $mods, 'logs' => $GLOBALS['LOGS']];
            }
            case 'module_delete': {
                $name = module_normalize_filename((string)($in['filename'] ?? ''));
                $path = mimi_data_file($name, DIR_DATA . '/' . $name);
                if (!is_file($path)) throw new RuntimeException("Không tìm thấy file module {$name}");
                if (!@unlink($path)) throw new RuntimeException("Không thể xóa file module {$name}");
                $mods = array_values(array_filter(modules_load(), fn($m) => ($m['file'] ?? '') !== $name));
                modules_save($mods);
                logmsg("  [FILE] Đã xóa module: {$name}");
                return ['ok' => true, 'msg' => "Đã xóa file {$name} và gỡ khỏi menu", 'modules' => $mods, 'logs' => $GLOBALS['LOGS']];
            }
            default:
                return ['ok' => false, 'error' => "Action không tồn tại: {$action}", 'logs' => $GLOBALS['LOGS']];
        }
    } catch (OtpRequiredException $e) {
        return ['ok' => false, 'otp_required' => true, 'otp_stage' => $e->stage,
                'email' => (string)($e->context['email'] ?? ''), 'ctx' => $e->context, 'logs' => $GLOBALS['LOGS']];
    } catch (CaptchaRequiredException $e) {
        return ['ok' => false, 'need_captcha' => true, 'captcha_id' => $e->captchaId,
                'captcha_image' => $e->captchaImage, 'ctx' => $e->context, 'logs' => $GLOBALS['LOGS']];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'logs' => $GLOBALS['LOGS']];
    }
}

/** Bọc run_action_impl: áp override proxy theo mục + ghi lịch sử log sau mỗi lần chạy */
function run_action(string $action, array $in): array {
    // Mỗi phiên Mua Thẻ dùng một file slot riêng theo email, tránh ghi đè UID/token/Android ID khi chạy song song.
    unset($GLOBALS['MIMI_MT_SLOT']);
    $mtAccountEmail = '';
    if (str_starts_with($action, 'mt_')) {
        $mtSlot = trim((string)($in['_mt_slot'] ?? $in['mt_account_email'] ?? (($action === 'mt_login') ? ($in['username'] ?? '') : '')));
        $mtAccountEmail = $mtSlot;
        if ($mtSlot !== '') $GLOBALS['MIMI_MT_SLOT'] = strtolower($mtSlot);
        unset($in['_mt_slot'], $in['mt_account_email']);
    }
    // Nút "Áp Dụng Proxy" ở đầu mỗi mục — UI gửi _proxy_override: {scope: 'fp'|'mt', on: bool}
    $ov = $in['_proxy_override'] ?? null;
    $requestedTarget = trim((string)($in['_target_user'] ?? $in['target_user'] ?? ''));
    unset($in['_target_user'], $in['target_user']);
    if ($requestedTarget !== '') {
        if (!mimi_is_developer()) return ['ok' => false, 'error' => 'Chỉ MimiVip01 mới được quản lý dữ liệu tài khoản khác.'];
        try { mimi_set_target_user($requestedTarget); } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }
    if (in_array($action, ['module_upload', 'create_empty_file', 'module_delete', 'modules_remove'], true) && !mimi_is_developer()) {
        return ['ok' => false, 'error' => 'Chỉ tài khoản Developer MimiVip01 mới được thêm, tạo, xóa hoặc gỡ file PHP.'];
    }
    $ov = $in['_proxy_override'] ?? null;
    if (is_array($ov) && isset($ov['scope'])) proxy_apply_scope((string)$ov['scope'], !empty($ov['on']));
    $label = (string)($in['_label'] ?? '');
    $preSignIn = preflight_sign_in($action, $in);
    $res = run_action_impl($action, $in);
    // Tài khoản đã có token được xác minh trước action; tài khoản vừa tạo được xác minh sau action.
    attach_sign_in_result($action, $in, $res);
    if ($preSignIn !== null && !array_key_exists('signIn', $res)) $res['signIn'] = $preSignIn;
    if (str_starts_with($action, 'mt_')) {
        $coinData = is_array($res['data'] ?? null) ? $res['data'] : $res;
        $coin = $coinData['balance'] ?? null;
        if ($coin !== null) acc_update_coin($mtAccountEmail, $coin);
    }
    // Mua Thẻ dùng tài khoản đã có sẵn; không ghi lại accounts.json sau mỗi lần đăng nhập/chạy,
    // tránh tốn thời gian và tránh tạo bản ghi trùng. Các action FunPass/LDPlayer khác vẫn tự lưu như cũ.
    $createdAccounts = str_starts_with($action, 'mt_') ? [] : acc_autodetect_save($action, $res);
    if ($createdAccounts) $res['created_accounts'] = $createdAccounts;
    $res['logs'] = $GLOBALS['LOGS'];   // cập nhật lại logs để gồm cả dòng "[TÀI KHOẢN] Đã tự lưu..."
    // Gắn phiên mua thẻ hiện tại {uid, token, androidid, username, serverTimeOffset} vào
    // mọi response mt_* — bảng "Tài Khoản / Dữ Liệu Mới Nhất" luôn có dữ liệu để hiển thị,
    // kể cả khi action vừa chạy báo lỗi (ok=false).
    if (str_starts_with($action, 'mt_') && !array_key_exists('session', $res)) {
        $res['session'] = mt_state();
    }
    // Mỗi lần chạy chức năng (không phải ping/list) → đếm để proxy tái sử dụng sau 2 lần
    $skipNote = in_array($action, ['ping','proxy_get','proxy_add','mt_session','acc_list','acc_save','acc_delete','runlog_list','runlog_clear','proxy_save','proxy_reset_state','create_empty_file','module_upload','module_delete','modules_list','modules_remove','ai_context','ai_chat'], true);
    if (!$skipNote && !empty($GLOBALS['PROXY_OVERRIDE'])) {
        // Chỉ đếm khi proxy đang bật (override hoặc cờ)
        proxy_note_run();
    } elseif (!$skipNote) {
        // Kiểm tra xem có feature nào bật proxy không
        $flags = proxy_load_flags();
        $anyOn = false;
        foreach ($flags as $v) { if ($v) { $anyOn = true; break; } }
        if ($anyOn) proxy_note_run();
    }
    runlog_add($action, $label, !empty($res['ok']), $GLOBALS['LOGS']);
    return $res;
}

/** Chạy action ở chế độ STREAM NDJSON: mỗi logmsg() đẩy ngay 1 dòng {"type":"log"} về
 *  trình duyệt → console web thấy quá trình đang chạy trực tiếp, không chờ chạy xong.
 *  Kết thúc bằng frame {"type":"done","result":...} (giống hệt response JSON thường). */
function stream_action(string $action, array $in): void {
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    http_response_code(200);
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Accel-Buffering: no');   // tắt buffer của nginx/proxy trung gian
    $GLOBALS['STREAM'] = true;
    echo json_encode(['type' => 'start', 'action' => $action], JSON_UNESCAPED_UNICODE) . "\n";
    @ob_flush(); @flush();
    try {
        $res = run_action($action, $in);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'error' => $e->getMessage(), 'logs' => $GLOBALS['LOGS']];
    }
    echo json_encode(['type' => 'done', 'result' => $res], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @ob_flush(); @flush();
    exit;
}

/* ============================================================================
 * CLI MODE — php api.php
 * ==========================================================================*/
function cli_ask(string $q): string {
    fwrite(STDOUT, $q);
    $l = fgets(STDIN);
    return $l === false ? '' : trim($l);
}

function cli_proxy_config_menu(): void {
    while (true) {
        $flags = proxy_load_flags();
        print("\n===== CẤU HÌNH PROXY (√ = dùng proxy / × = không) =====\n");
        $keys = array_keys(PROXY_FEATURES);
        foreach ($keys as $i => $k) {
            $mark = $flags[$k] ? '√' : '×';
            $pool = PROXY_FEATURES[$k]['pool'];
            printf("  %2d. [%s] %-38s (pool: %s)\n", $i + 1, $mark, PROXY_FEATURES[$k]['label'], $pool);
        }
        $us = proxy_load_list(FILE_PROXY); $vn = proxy_load_list(FILE_PROXY_VN);
        printf("  --- proxy.txt (US): %d dòng | proxy_vn.txt (VN): %d dòng ---\n", count($us), count($vn));
        print("  e. Sửa danh sách proxy US | v. Sửa proxy VN | 0. Quay lại\n");
        $c = cli_ask("Chọn số để bật/tắt, hoặc lệnh: ");
        if ($c === '0') return;
        if ($c === 'e' || $c === 'v') {
            $file = $c === 'e' ? FILE_PROXY : FILE_PROXY_VN;
            print("Nhập danh sách proxy (mỗi dòng IP:PORT, ví dụ 42.112.34.34:3434), kết thúc bằng dòng trống:\n");
            $lines = [];
            while (true) { $l = cli_ask(''); if ($l === '') break; $lines[] = $l; }
            file_put_contents($file, implode("\n", $lines) . "\n", LOCK_EX);
            fcache_forget('list:' . $file);
            print("Đã lưu " . count($lines) . " proxy.\n");
            continue;
        }
        $n = (int)$c;
        if ($n >= 1 && $n <= count($keys)) {
            $k = $keys[$n - 1];
            $flags[$k] = !$flags[$k];
            proxy_save_flags($flags);
            printf("  -> %s: %s\n", PROXY_FEATURES[$k]['label'], $flags[$k] ? '√' : '×');
        }
    }
}

function cli_handle_captcha(CaptchaRequiredException $e, string $actionType): array {
    print("\n[!] Server yêu cầu CAPTCHA. captcha_id: {$e->captchaId}\n");
    $imgFile = sys_get_temp_dir() . '/captcha_' . $e->captchaId . '.png';
    file_put_contents($imgFile, base64_decode($e->captchaImage));
    print("    Ảnh captcha đã lưu: {$imgFile}  (mở lên xem rồi nhập mã)\n");
    $code = cli_ask("    Nhập mã captcha: ");
    return ['captcha' => ['captcha_id' => $e->captchaId, 'captcha_data' => $code], 'ctx' => $e->context];
}

function cli_main(): void {
    print("=" . str_repeat("=", 59) . "\n");
    print("  API.PHP — FUNPASS + KHOGA + MUATHE (CLI nâng cao)\n");
    print("=" . str_repeat("=", 59) . "\n");
    while (true) {
        print("\n--- MENU CHÍNH ---\n");
        print("  1. [Auto] Funpass loop theo proxy (giống funpass.py)\n");
        print("  2. [Khoga] Tạo Funpass -> CPI -> Sync (tự động/thủ công)\n");
        print("  3. [Mua thẻ] Menu muathe.js (đăng nhập, số dư, SKU, mua/săn thẻ)\n");
        print("  4. Cấu hình proxy từng chức năng (√/×)\n");
        print("  0. Thoát\n");
        $c = cli_ask("Chọn: ");

        if ($c === '0') { print("Tạm biệt!\n"); return; }

        if ($c === '4') { cli_proxy_config_menu(); continue; }

        if ($c === '1') {
            $ldEmail = cli_ask("Email LDPlayer cố định (nhận điểm): ");
            $ldPass  = cli_ask("Mật khẩu LDPlayer: ");
            $rounds  = (int)(cli_ask("Số vòng lặp (0 = vô hạn): ") ?: '0');
            $idx = 0; $done = 0;
            while (true) {
                try {
                    $r = flow_funpass_once(['proxy_index' => $idx, 'ld_email' => $ldEmail, 'ld_password' => $ldPass]);
                    $idx = $r['next_proxy_index'];
                } catch (CaptchaRequiredException $e) {
                    $extra = cli_handle_captcha($e, 'funpass');
                    try {
                        $r = flow_funpass_once(['proxy_index' => $idx, 'ld_email' => $ldEmail, 'ld_password' => $ldPass] + $extra);
                        $idx = $r['next_proxy_index'];
                    } catch (Throwable $e2) { print("[!] Lỗi: {$e2->getMessage()}\n"); $idx++; }
                } catch (Throwable $e) { print("[!] Lỗi: {$e->getMessage()}\n"); $idx++; }
                $done++;
                if ($rounds > 0 && $done >= $rounds) break;
                sleep(random_int(2, 5));
            }
            continue;
        }

        if ($c === '2') {
            print("  a. Sync tự động (tạo LDPlayer mới cùng email)\n");
            print("  b. Sync thủ công (đăng nhập LDPlayer có sẵn)\n");
            $mode = cli_ask("Chọn (a/b): ");
            $opts = ['ld_mode' => $mode === 'b' ? 'existing' : 'auto'];
            if ($mode === 'b') {
                $opts['ld_email'] = cli_ask("Email LDPlayer: ");
                $opts['ld_password'] = cli_ask("Mật khẩu LDPlayer: ");
            }
            $opts['confirm_transfer'] = strtolower(cli_ask("Tự động xác nhận chuyển điểm? (y/n): ")) === 'y';
            try {
                flow_khoga_full($opts);
            } catch (CaptchaRequiredException $e) {
                $extra = cli_handle_captcha($e, 'khoga');
                try { flow_khoga_full($opts + $extra); }
                catch (Throwable $e2) { print("[!] Lỗi: {$e2->getMessage()}\n"); }
            } catch (Throwable $e) { print("[!] Lỗi: {$e->getMessage()}\n"); }
            continue;
        }

        if ($c === '3') {
            // Khôi phục phiên giống muathe.js
            $st = mt_state();
            if ($st['uid'] && $st['token'] && $st['androidid']) {
                print("Tìm thấy phiên đã lưu: UID {$st['uid']} ({$st['username']})\n");
                if (strtolower(cli_ask("Dùng lại phiên này? (Y/n): ")) === 'n') mt_save_config([]);
            }
            $st = mt_state();
            if (!$st['uid'] || !$st['token']) {
                $u = cli_ask("Tài khoản/Email LDPlayer: ");
                $p = cli_ask("Mật khẩu: ");
                $lr = mt_login($u, $p);
                if (empty($lr['ok'])) { print("Đăng nhập thất bại.\n"); continue; }
                mt_sync_time();
            }
            while (true) {
                print("\n--- MENU MUA THẺ ---\n");
                print("  1. Xem số dư điểm\n  2. Xem danh sách tất cả SKU\n  3. Mua thẻ tự động\n");
                print("  4. Săn thẻ (Sniper)\n  5. Tạo đơn theo SKU ID\n  6. Xem danh sách thẻ đã mua\n");
                print("  7. Xem chi tiết thẻ theo mã đơn\n  8. Đăng xuất / xóa phiên\n  9. Quay lại menu chính\n");
                $m = cli_ask("Chọn (1-9): ");
                try {
                    if ($m === '1') mt_get_balance();
                    elseif ($m === '2') mt_get_all_item_list();
                    elseif ($m === '3') {
                        $prev = mt_buy_gift_auto(false);
                        if ($prev && !empty($prev['bestSku'])) {
                            if (strtolower(cli_ask("Mua SKU {$prev['bestSku']['skuId']} ({$prev['bestSku']['score']} điểm)? (y/n): ")) === 'y')
                                mt_buy_gift_auto(true);
                        }
                    }
                    elseif ($m === '4') {
                        $sku = (int)cli_ask("SKU ID cần săn: ");
                        $num = (int)(cli_ask("Số lượng (mặc định 1): ") ?: '1');
                        $dl  = (int)(cli_ask("Delay ms (mặc định 1000): ") ?: '1000');
                        $max = (int)(cli_ask("Số lần thử tối đa (mặc định 100): ") ?: '100');
                        mt_snipe_gift_order($sku, $num, $dl, $max);
                    }
                    elseif ($m === '5') {
                        $sku = (int)cli_ask("SKU ID: ");
                        $num = (int)(cli_ask("Số lượng (mặc định 1): ") ?: '1');
                        mt_create_gift_order($sku, $num);
                    }
                    elseif ($m === '6') {
                        $pg = (int)(cli_ask("Trang (mặc định 1): ") ?: '1');
                        $sz = (int)(cli_ask("Số lượng (mặc định 10): ") ?: '10');
                        mt_get_gift_list($pg, $sz);
                    }
                    elseif ($m === '7') mt_get_gift_detail(cli_ask("Mã đơn hàng: "));
                    elseif ($m === '8') { mt_save_config([]); print("Đã xóa phiên.\n"); break; }
                    elseif ($m === '9') break;
                } catch (Throwable $e) { print("[!] Lỗi: {$e->getMessage()}\n"); }
            }
            continue;
        }
    }
}

/* ============================================================================
 * WEB MODE
 * ==========================================================================*/
if (!IS_CLI) {
    $action = (string)($_REQUEST['action'] ?? '');
    unset($GLOBALS['MIMI_TARGET_USER']);
    $user = mimi_auth_current();
    if ($action === 'github_sync') {
        if ($user !== MIMI_DEVELOPER) json_out(['ok' => false, 'error' => 'Chỉ MimiVip01 mới được đồng bộ source từ GitHub.'], 403);
        $root = __DIR__; $pullOut = []; $pullCode = 0;
        @exec('git -C ' . escapeshellarg($root) . ' pull --ff-only origin main 2>&1', $pullOut, $pullCode);
        if ($pullCode !== 0) json_out(['ok' => false, 'error' => implode("\n", array_slice($pullOut, -30))], 500);
        $buildOut = []; $buildCode = 0;
        @exec('cd ' . escapeshellarg($root) . ' && pnpm build 2>&1', $buildOut, $buildCode);
        if ($buildCode !== 0) json_out(['ok' => false, 'error' => implode("\n", array_slice($buildOut, -30))], 500);
        $commit = trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD'));
        json_out(['ok' => true, 'commit' => $commit, 'reload_required' => true]);
    }
    if ($action === 'auth_login') {
        $raw = file_get_contents('php://input'); $in = $raw ? (json_decode($raw, true) ?: []) : $_POST;
        $name = mimi_canonical_user((string)($in['name'] ?? ''));
        if ($name === null) json_out(['ok' => false, 'error' => 'Tên tài khoản Mimi không hợp lệ'], 401);
        mimi_auth_set($name); json_out(['ok' => true, 'user' => $name, 'developer' => $name === MIMI_DEVELOPER]);
    }
    if ($action === 'auth_logout') { mimi_auth_clear(); json_out(['ok' => true]); }
    if ($action === 'auth_status') json_out(['ok' => true, 'authenticated' => $user !== null, 'user' => $user, 'developer' => $user === MIMI_DEVELOPER, 'available_users' => MIMI_AUTH_USERS]);
    if (!empty($_REQUEST['target_user'])) {
        if (!$user || !mimi_is_developer()) json_out(['ok' => false, 'error' => 'Chỉ MimiVip01 mới được mở dữ liệu tài khoản khác.'], 403);
        try { mimi_set_target_user((string)$_REQUEST['target_user']); } catch (Throwable $e) { json_out(['ok' => false, 'error' => $e->getMessage()], 403); }
    }
    if (!empty($_REQUEST['module'])) {
        try {
            $name = module_normalize_filename((string)$_REQUEST['module']);
            $allowed = array_filter(modules_load(), fn($m) => ($m['file'] ?? '') === $name);
            $path = mimi_data_file($name, DIR_DATA . '/' . $name);
            if (!$allowed || !is_file($path)) throw new RuntimeException('Không tìm thấy module PHP của tài khoản này');
            header('Content-Type: text/html; charset=utf-8');
            $renderModule = static function (string $modulePath): string {
                ob_start();
                try {
                    include $modulePath;
                    return (string)ob_get_clean();
                } catch (Throwable $e) {
                    while (ob_get_level() > 0) @ob_end_clean();
                    throw $e;
                }
            };
            // Chạy file PHP module để phần dưới hiển thị đúng giao diện do module tự tạo.
            $moduleHtml = $renderModule($path);
            $menuUrl = htmlspecialchars((string)($_SERVER['SCRIPT_NAME'] ?? basename(__FILE__)), ENT_QUOTES, 'UTF-8');
            $moduleTitle = htmlspecialchars((string)($allowed[array_key_first($allowed)]['label'] ?? pathinfo($name, PATHINFO_FILENAME)), ENT_QUOTES, 'UTF-8');
            $moduleShell = '<style id="mimi-module-shell-style">html,body{margin:0;min-height:100%;overflow-x:hidden;padding-top:84px}#mimiModuleToolbar{position:fixed;top:0;left:0;right:0;height:84px;z-index:2147483001;display:flex;align-items:center;justify-content:flex-start;gap:10px;padding:14px 28px;pointer-events:none;background:linear-gradient(180deg,rgba(8,12,28,.97),rgba(8,12,28,.72))}#mimiModuleBrand{display:flex;align-items:center;gap:9px;min-width:0;color:#eef2ff;font:800 27px/1 system-ui,sans-serif;letter-spacing:-.02em}#mimiModuleBrand span{font-size:36px;line-height:.8;color:#6d8cff;text-shadow:0 0 14px rgba(109,140,255,.5);transform:rotate(-18deg)}#mimiModuleBrand b{background:linear-gradient(90deg,#6d8cff,#a37dff);-webkit-background-clip:text;background-clip:text;color:transparent;white-space:nowrap}#mimiModuleModes{display:flex;align-items:center;gap:6px;margin-left:auto;pointer-events:auto}#mimiModuleModes button{width:40px;height:40px;border:1px solid #3b4b83;border-radius:11px;background:#202b56;color:#fff;font:18px/1 system-ui,sans-serif;cursor:pointer;box-shadow:0 8px 24px #0005}#mimiModuleModes button:hover{filter:brightness(1.15)}#mimiModuleMenu{position:relative;top:auto;left:auto;pointer-events:auto;z-index:2147483000;width:52px;height:52px;border:1px solid #3b4b83;border-radius:16px;background:linear-gradient(145deg,#1c2b58,#29316c);color:#fff;font:700 27px/1 system-ui,sans-serif;cursor:pointer;box-shadow:0 8px 30px #0007}#mimiModuleMenu:hover{filter:brightness(1.15)}#mimiModuleDrawer{position:fixed;top:0;left:0;z-index:2147482999;width:min(290px,86vw);height:100vh;padding:86px 14px 18px;box-sizing:border-box;background:#10172f;color:#eef2ff;transform:translateX(-105%);transition:transform .22s ease;box-shadow:12px 0 40px #0008;font:14px/1.5 system-ui,sans-serif}#mimiModuleDrawer.open{transform:none}#mimiModuleDrawer h2{font-size:15px;margin:0 0 10px}#mimiModuleDrawer a{display:block;padding:11px 12px;margin:6px 0;border:1px solid #2e3c72;border-radius:10px;color:#eef2ff;text-decoration:none;background:#19234a}#mimiModuleDrawer a:hover{background:#29366d}#mimiModuleMenuClose{position:absolute;top:16px;right:14px;border:0;background:none;color:#fff;font-size:24px;cursor:pointer}#mimiModuleShade{display:none;position:fixed;inset:0;z-index:2147482998;background:#0008}#mimiModuleShade.open{display:block}body.mimi-module-mobile{max-width:480px;margin:0 auto}body.mimi-module-desktop{max-width:none}</style><div id="mimiModuleToolbar"><button id="mimiModuleMenu" type="button" aria-label="Mở menu chuyển mục" title="Mở menu chuyển mục">☰</button><div id="mimiModuleBrand"><span>⚡</span><b>FunPass API</b></div><div id="mimiModuleModes"><button type="button" title="Chế độ điện thoại" aria-label="Chế độ điện thoại" onclick="document.body.classList.add(&quot;mimi-module-mobile&quot;);document.body.classList.remove(&quot;mimi-module-desktop&quot;)">📱</button><button type="button" title="Chế độ máy tính" aria-label="Chế độ máy tính" onclick="document.body.classList.add(&quot;mimi-module-desktop&quot;);document.body.classList.remove(&quot;mimi-module-mobile&quot;)">💻</button></div></div><div id="mimiModuleShade"></div><aside id="mimiModuleDrawer" aria-label="Menu chuyển mục"><button id="mimiModuleMenuClose" type="button" aria-label="Đóng menu">×</button><h2>Chuyển mục</h2><a href="'.$menuUrl.'?section=auto">🤖 Funpass Auto</a><a href="'.$menuUrl.'?section=khoga">🧩 Khoga Flow</a><a href="'.$menuUrl.'?section=muathe">💳 Mua Thẻ</a><a href="'.$menuUrl.'?section=proxy">🌐 Proxy</a><a href="'.$menuUrl.'?section=accounts">👤 Tài Khoản</a></aside><script>(function(){var b=document.getElementById("mimiModuleMenu"),d=document.getElementById("mimiModuleDrawer"),s=document.getElementById("mimiModuleShade"),c=document.getElementById("mimiModuleMenuClose");function close(){d.classList.remove("open");s.classList.remove("open")}b.onclick=function(){d.classList.add("open");s.classList.add("open")};c.onclick=close;s.onclick=close;d.querySelectorAll("a").forEach(function(a){a.addEventListener("click",close)})})();</script>';
            $closePos = stripos($moduleHtml, '</body>');
            if ($closePos === false) $moduleHtml .= $moduleShell;
            else $moduleHtml = substr($moduleHtml, 0, $closePos) . $moduleShell . substr($moduleHtml, $closePos);
            header('Content-Type: text/html; charset=utf-8');
            echo $moduleHtml; exit;
        } catch (Throwable $e) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo $e->getMessage(); exit; }
    }
    if ($action !== '') {
        // Chỉ endpoint đọc dữ liệu được gọi bằng GET; thao tác ghi/đăng nhập
        // phải dùng POST để tránh kích hoạt ngoài ý muốn qua link hoặc form cross-site.
        $readOnlyActions = ['ping','proxy_get','mt_session','acc_list','runlog_list','modules_list','mimi_models_get'];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !in_array($action, $readOnlyActions, true)) {
            json_out(['ok' => false, 'error' => 'Action này yêu cầu phương thức POST.'], 405);
        }
        // API dùng same-origin cookie; không mở CORS wildcard cho endpoint có dữ liệu phiên.
        $in = [];
        if (!empty($_FILES) || str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
            $in = array_merge($_REQUEST, $_POST);
        } elseif (in_array($action, ['proxy_get','mt_session','acc_list','runlog_list','modules_list','mimi_models_get'], true)) {
            $in = $_REQUEST;
        } else {
            $raw = file_get_contents('php://input');
            $in = $raw ? (json_decode($raw, true) ?: []) : [];
            $in = array_merge($_REQUEST, $in);
        }
        if (!empty($_REQUEST['stream'])) stream_action($action, $in);   // console live (NDJSON)
        $res = run_action($action, $in);
        json_out($res);
    }
    render_ui();
    exit;
}

/* ============================================================================
 * WEB UI
 * ==========================================================================*/
function render_login(): void {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'LOGIN'
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Đăng nhập Mimi</title><style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b1020;color:#eef2ff;font:15px/1.5 system-ui,Segoe UI,sans-serif;padding:18px}.box{width:min(420px,100%);padding:24px;border:1px solid #354575;border-radius:20px;background:linear-gradient(145deg,#151d3e,#10162c);box-shadow:0 20px 70px #0008}.logo{display:flex;align-items:center;gap:10px;margin-bottom:18px}.logo b{font-size:20px}.pet{width:54px;height:54px;border-radius:16px;object-fit:contain;background:#252c60}.muted{color:#aeb8da;font-size:13px}.users{display:grid;gap:9px;margin-top:16px}.user{width:100%;padding:12px 14px;border:1px solid #46558b;border-radius:11px;background:#1b2650;color:#fff;text-align:left;font-size:14px;cursor:pointer}.user:hover{background:#2b3b76;border-color:#9b8dff}.status{min-height:22px;margin-top:12px;color:#ffc1cd}.note{margin-top:18px;color:#8996bd;font-size:11px}
</style></head><body><main class="box"><div class="logo"><img class="pet" src="assets/mimi_ready.png" alt="Mimi"><div><b>Mimi Code AI</b><div class="muted">Chọn tài khoản để vào api.php</div></div></div><div class="muted">Chrome đã đăng nhập sẽ vào thẳng. Chrome mới cần chọn một tên bên dưới.</div><div class="users"><button class="user" data-name="Apimimi01">Apimimi01 · Tài khoản 1</button><button class="user" data-name="Apimimi05">Apimimi05 · Tài khoản 2</button><button class="user" data-name="Apimimi10">Apimimi10 · Tài khoản 3</button></div><div class="status" id="status"></div><div class="note">Cách xác minh này dùng tên tài khoản theo yêu cầu, không dùng mật khẩu. Vì vậy ai biết tên cũng có thể chọn tài khoản tương ứng.</div><script>document.querySelectorAll('.user').forEach(b=>b.onclick=async()=>{document.querySelectorAll('.user').forEach(x=>x.disabled=true);const s=document.getElementById('status');s.textContent='Đang vào Mimi...';try{const r=await fetch('?action=auth_login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:b.dataset.name})});const j=await r.json();if(!j.ok)throw new Error(j.error||'Không đăng nhập được');location.reload()}catch(e){s.textContent=e.message;document.querySelectorAll('.user').forEach(x=>x.disabled=false)}});</script></main></body></html>
LOGIN;
}
function render_ui(): void {
    header('Content-Type: text/html; charset=utf-8');
    // NOWDOC (<<<'HTML'): KHÔNG nội suy biến PHP -> JS trong trang không thể vỡ
    // vì dữ liệu server. Danh sách tính năng proxy được JS tự tải qua action=proxy_get.
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FunPass API — Control Panel</title>
<style>
:root{--bg:#061329;--card:#0d213d;--card2:#112b4d;--line:#245384;--txt:#edf7ff;--dim:#91afd0;--acc:#32b9f4;--ok:#45d6a1;--err:#ff6b82;--warn:#ffd166}
*{box-sizing:border-box;margin:0;padding:0}
html,body{overflow-x:hidden}
body{background:radial-gradient(1100px 620px at 78% -15%,#123d68 0%,#091c37 46%,var(--bg) 78%);color:var(--txt);font:14px/1.5 'Segoe UI',system-ui,sans-serif;min-height:100vh}.manual-note{padding:8px 24px;border-bottom:1px solid var(--line);background:rgba(50,185,244,.08);color:var(--dim);font-size:12px}.manual-note b{color:var(--txt)}
header{padding:18px 24px;border-bottom:1px solid rgba(70,151,207,.34);display:flex;align-items:center;gap:12px;position:sticky;top:0;background:rgba(5,18,38,.9);backdrop-filter:blur(10px);z-index:10}
header h1{font-size:18px;background:linear-gradient(90deg,#5b8cff,#a06bff);-webkit-background-clip:text;background-clip:text;color:transparent}
.badge{font-size:11px;color:var(--dim);border:1px solid var(--line);padding:2px 8px;border-radius:99px}
.wrap{display:flex;min-height:calc(100vh - 60px)}
nav{width:210px;padding:16px 10px;border-right:1px solid rgba(70,151,207,.28);flex-shrink:0;background:rgba(6,19,41,.28)}
nav button{display:block;width:100%;text-align:left;background:none;border:1px solid transparent;color:var(--dim);padding:10px 14px;border-radius:10px;cursor:pointer;font-size:13.5px;margin-bottom:4px;transition:.2s}
nav button:hover{background:rgba(31,91,137,.28);color:var(--txt);border-color:rgba(70,151,207,.35)}
nav button.active{background:linear-gradient(90deg,rgba(38,139,202,.38),rgba(37,78,133,.28));color:var(--txt);border:1px solid #318dcc;box-shadow:0 0 18px rgba(37,153,221,.14)}
main{flex:1;padding:20px 24px;max-width:1100px;min-width:0}
.tab{display:none;animation:fade .3s ease}
body.custom-module-open nav{display:none}
body.custom-module-open .wrap{display:block;min-height:calc(100vh - 60px)}
body.custom-module-open main{max-width:none;padding:0 24px 24px}
body.custom-module-open .tab:not(#tab-custommod){display:none!important}
body.custom-module-open #tab-custommod{display:block!important;animation:none}
.custom-mod-shell{min-width:0}
.custom-mod-toolbar{display:flex;align-items:center;gap:10px;min-height:58px;padding:10px 0;border-bottom:1px solid var(--line)}
.custom-mod-icon{display:grid;place-items:center;width:38px;height:38px;flex:0 0 38px;border:1px solid var(--line);border-radius:12px;background:linear-gradient(145deg,rgba(91,140,255,.24),rgba(160,107,255,.2));font-size:20px}
.custom-mod-copy{min-width:0;display:flex;flex-direction:column;gap:1px}.custom-mod-copy b{font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.custom-mod-copy small{color:var(--dim);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.custom-mod-actions{margin-left:auto;display:flex;gap:6px;flex:0 0 auto}.custom-mod-action{width:32px;height:32px;padding:0;border:1px solid var(--line);border-radius:9px;background:var(--card2);color:var(--txt);font-size:16px;cursor:pointer}.custom-mod-action:hover{background:var(--line);filter:brightness(1.12)}.custom-mod-action.danger{color:#ffb3bd;border-color:rgba(255,91,110,.45)}
body.custom-module-open #customModFrame{display:block;width:100%;min-height:calc(100vh - 140px);border:1px solid var(--line);border-radius:12px;background:#0b1020}
@media(max-width:650px){body.custom-module-open main{padding:0 12px 14px}.custom-mod-toolbar{min-height:54px}.custom-mod-copy{padding-right:2px}.custom-mod-copy b{font-size:14px}.custom-mod-action{width:31px;height:31px}}

.tab.active{display:block}
@keyframes fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.card{background:linear-gradient(145deg,rgba(14,38,69,.96),rgba(9,27,51,.96));border:1px solid rgba(57,135,190,.62);border-radius:16px;padding:18px;margin-bottom:16px;box-shadow:0 12px 34px rgba(0,8,23,.22)}
.card h3{font-size:15px;margin-bottom:10px;color:#cdd7ff}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
label{display:block;font-size:12px;color:var(--dim);margin:8px 0 4px}
input,select,textarea{width:100%;background:#081a33;border:1px solid #285985;color:var(--txt);border-radius:10px;padding:9px 11px;font-size:13px;outline:none;transition:.15s}
input:focus,textarea:focus,select:focus{border-color:var(--acc);box-shadow:0 0 0 3px rgba(50,185,244,.18)}
textarea{min-height:90px;resize:vertical;font-family:ui-monospace,monospace;font-size:12px}
.btn{background:linear-gradient(90deg,#1595d2,#2d6fca);border:1px solid rgba(111,213,255,.35);color:#fff;padding:9px 18px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;transition:.2s;margin:6px 6px 0 0;position:relative;overflow:hidden;min-height:36px;line-height:1.3}
.btn:hover{transform:translateY(-1px);border-color:#76dcff;box-shadow:0 7px 20px rgba(24,157,222,.28)}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
@keyframes buttonPress{0%{transform:translateY(0) scale(1)}45%{transform:translateY(2px) scale(.985)}100%{transform:translateY(0) scale(1)}}
button:active,.btn:active,#burger:active,.modebtn:active,.custom-mod-action:active,.accpick:active{animation:buttonPress .18s ease-out both;filter:brightness(.97)}
@media(prefers-reduced-motion:reduce){button:active,.btn:active,#burger:active,.modebtn:active,.custom-mod-action:active,.accpick:active{animation:none;transform:none;filter:none}}
.btn.ghost{background:rgba(19,56,92,.7);border:1px solid #376e9d;color:#d9eeff}
.btn.ghost:hover{background:#1a4b75;border-color:var(--acc);color:#fff}
.btn.ok{background:linear-gradient(90deg,#18ae82,#45d6a1);border-color:rgba(126,255,213,.38)}
.btn.danger{background:linear-gradient(90deg,#d6455b,#ff5b6e)}
.khoga-action-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:10px}.khoga-action-grid .btn{width:100%;margin:0}.khoga-action-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.khoga-action-row + .khoga-action-row{margin-top:8px}.mt-result{margin-top:14px;padding:12px;border:1px solid var(--line);border-radius:12px;background:#0d1430;min-height:42px}.mt-result-title{font-weight:700;color:#dce4ff;margin-bottom:8px}.mt-result-muted{color:var(--dim);font-size:12px}.mt-result-table{width:100%;border-collapse:collapse;font-size:12px}.mt-result-table th,.mt-result-table td{padding:7px 6px;border-bottom:1px solid rgba(151,169,236,.16);text-align:left;vertical-align:top}.mt-result-table th{color:#aebbea;font-weight:600}.mt-result-table td{color:#eef2ff}.mt-result-table tr:last-child td{border-bottom:0}.mt-result-card{padding:9px 10px;margin-top:8px;border:1px solid #3b4b83;border-radius:9px;background:#151e3b}.mt-result-card b{color:#cfd8ff}.mt-result-code{display:inline-block;color:#ffc94d;word-break:break-all}.mt-result-actions{display:flex;flex-wrap:wrap;gap:6px}.mt-result-actions .btn{margin:0;padding:6px 10px;font-size:11px;min-height:30px}
/* Nút icon nhỏ (copy/save/toggle) dễ nhìn hơn */
h3 .btn.ghost, h3 button.btn{min-width:32px;min-height:28px;font-size:13px;padding:4px 10px;opacity:1}
.histcnt{color:var(--acc);font-weight:700;font-size:11px;margin-left:4px}
.console-head{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:8px 12px;margin-bottom:10px;min-width:0}.console-head h3{flex:1 1 260px;min-width:0;margin:0;line-height:1.35}.console-head h3 .small{font-weight:400}.console-tools{display:flex;align-items:flex-start;justify-content:flex-end;gap:6px;flex:0 0 auto}.console-tools .btn{float:none;margin:0;min-height:30px;padding:4px 10px;font-size:11px;white-space:nowrap;width:auto}
#console{display:block;width:100%;min-width:0;background:#04101f;border:1px solid #245b89;border-radius:14px;height:340px;overflow-y:auto;overflow-x:auto;padding:14px;font-family:ui-monospace,'Cascadia Code',monospace;font-size:12px;white-space:pre;box-shadow:inset 0 0 24px rgba(0,8,20,.35)}
#console .logline{width:max-content;min-width:100%}
#console::-webkit-scrollbar,textarea::-webkit-scrollbar,.plist::-webkit-scrollbar{width:8px;height:8px}
#console::-webkit-scrollbar-thumb,textarea::-webkit-scrollbar-thumb,.plist::-webkit-scrollbar-thumb{background:var(--line);border-radius:8px}
#console::-webkit-scrollbar-thumb:hover{background:var(--acc)}
#console::-webkit-scrollbar-track,textarea::-webkit-scrollbar-track,.plist::-webkit-scrollbar-track{background:transparent}
.logline{animation:slidein .2s ease;border-left:2px solid transparent;padding-left:8px;margin-bottom:1px}
@keyframes slidein{from{opacity:0;transform:translateX(-6px)}to{opacity:1;transform:none}}
.logline.ok{color:var(--ok)}.logline.err{color:var(--err)}.logline.warn{color:var(--warn)}
.logline.det{display:none}
#console.showdet .logline.det{display:block;opacity:.7;white-space:pre-wrap}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:-2px;margin-right:6px}
@keyframes spin{to{transform:rotate(360deg)}}
#statusbar{position:sticky;bottom:0;background:rgba(5,18,38,.94);border-top:1px solid rgba(70,151,207,.4);padding:8px 24px;font-size:12px;color:var(--dim);display:flex;gap:16px;align-items:center;backdrop-filter:blur(10px)}
.dot{width:8px;height:8px;border-radius:50%;background:var(--dim);display:inline-block}
.dot.run{background:var(--ok);animation:pulse 1s infinite}
@keyframes pulse{50%{opacity:.4}}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line)}
th{color:var(--dim);font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px}
tr:hover td{background:var(--card2)}
.sw{position:relative;width:42px;height:22px;flex-shrink:0;cursor:pointer}
.sw input{opacity:0;width:0;height:0}
.sl{position:absolute;inset:0;background:#0d1430;border:1px solid var(--line);border-radius:99px;transition:.2s}
.sl:before{content:"";position:absolute;width:16px;height:16px;left:2px;top:2px;background:var(--dim);border-radius:50%;transition:.2s}
.sw input:checked+.sl{background:rgba(55,214,122,.25);border-color:var(--ok)}
.sw input:checked+.sl:before{transform:translateX(20px);background:var(--ok)}
.frow{display:flex;align-items:center;gap:12px;padding:9px 10px;border-bottom:1px solid var(--line)}
.frow .lbl{flex:1}.frow .mark{font-family:monospace;width:18px;text-align:center}
.frow .mark.on{color:var(--ok)}.frow .mark.off{color:var(--err)}
.pooltag{font-size:10.5px;color:var(--dim);border:1px solid var(--line);border-radius:6px;padding:1px 6px}
.plist{max-height:160px;overflow-y:auto}
pre.json{background:#04101f;border:1px solid #245b89;border-radius:12px;padding:12px;overflow:auto;max-height:280px;font-size:11.5px;min-width:0;max-width:100%}
.capbox{display:none;margin-top:12px;padding:14px;border:1px dashed var(--warn);border-radius:12px;background:rgba(255,201,77,.06)}
.capbox img{max-width:240px;border-radius:8px;border:1px solid var(--line);display:block;margin-bottom:10px;background:#fff}.capbox.shared-cap{margin-top:0}.capbox.shared-cap .cap-title{display:flex;align-items:center;gap:8px;margin-bottom:6px}.capbox.shared-cap .cap-row{display:flex;align-items:center;gap:8px;width:100%;min-width:0}.capbox.shared-cap .cap-row input{flex:1 1 auto;width:auto;min-width:0}.capbox.shared-cap .cap-row .btn{flex:0 0 auto;width:auto;margin:0;white-space:nowrap}@media(max-width:560px){.capbox.shared-cap .cap-row{align-items:stretch}.capbox.shared-cap .cap-row input{width:auto!important;flex:1 1 auto}.capbox.shared-cap .cap-row .btn{width:auto!important;flex:0 0 auto;display:inline-flex;padding-left:12px;padding-right:12px}}
h2.sec{font-size:16px;margin:4px 0 14px;color:#cdd7ff}
.small{font-size:12px;color:var(--dim)}
/* ==== Chế độ giao diện 📱/💻 + drawer ☰ ==== */
#burger{display:none;background:var(--card2);border:1px solid var(--line);color:var(--txt);font-size:18px;border-radius:9px;padding:6px 11px;cursor:pointer}
.modesw{margin-left:auto;display:flex;gap:6px}
.modebtn{background:var(--card2);border:1px solid #3a4a7a;color:#cdd7ff;font-size:15px;border-radius:9px;padding:6px 11px;cursor:pointer;transition:.15s;min-width:40px;min-height:36px}
.modebtn:hover{border-color:var(--acc);color:#fff}
.modebtn.active{background:linear-gradient(90deg,rgba(91,140,255,.35),rgba(160,107,255,.25));color:#fff;border-color:var(--acc)}
#overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:19}
body.mob #burger{display:block}
body.mob nav{position:sticky;top:0;left:auto;bottom:auto;width:auto;z-index:9;display:flex;align-items:center;gap:6px;overflow-x:auto;overflow-y:hidden;background:rgba(13,20,48,.96);border-right:0;border-bottom:1px solid var(--line);padding:7px 10px;transform:none;transition:max-height .18s ease,opacity .18s ease;scrollbar-width:none}
body.mob nav::-webkit-scrollbar{display:none}
body.mob nav.open{transform:none}
body.mob.nav-collapsed nav{display:none}
body.mob nav button{flex:0 0 auto;width:auto;min-width:max-content;margin:0;padding:8px 11px;border:1px solid transparent;border-radius:10px;font-size:12px;white-space:nowrap;opacity:1}
body.mob nav button.active{border-color:var(--line)}
body.mob #overlay{display:none!important}
body.mob .grid{grid-template-columns:1fr}
body.mob .wrap{display:block;min-height:0}
body.mob main{padding:10px 12px;max-width:none}
body.mob .card{padding:12px}
body.mob .console-head{gap:7px}.console-head h3 .small{display:inline}body.mob .console-head h3{font-size:14px;line-height:1.3}.console-tools{gap:4px}.console-tools .btn{display:inline-flex;width:auto;min-height:30px;padding:4px 8px;margin:0}@media(max-width:560px){body.mob .console-tools{width:100%;justify-content:flex-end}}
@keyframes navItemIn{from{opacity:0;transform:translateX(-10px)}to{opacity:1;transform:none}}
body.mob nav.nav-animate button{animation:navItemIn .28s ease both;animation-delay:calc(var(--nav-i,0) * .2s)}
body.mob .btn{display:block;width:100%;margin:8px 0 0;padding:9px 12px;font-size:12.5px;float:none;box-sizing:border-box}
body.mob header{padding:10px 12px;flex-wrap:nowrap;gap:8px}
body.mob header h1{font-size:16px;white-space:nowrap}
body.mob header .badge{display:none}
/* Căn chỉnh nút mobile: tránh lệch float / withpick / modebtn / nút nhỏ góc */
body.mob .withpick{flex-wrap:nowrap} body.mob .accitem{flex-wrap:wrap} body.mob .mod-actions{width:100%;margin-left:0} body.mob .mod-actions .btn{flex:1}
body.mob .withpick .accpick{width:40px;height:40px;flex-shrink:0;margin:0}
body.mob .modebtn{padding:8px 12px}
body.mob h3 .btn, body.mob h3 button.btn{float:none;display:inline-block;width:auto;margin:0 0 0 6px;padding:4px 10px;vertical-align:middle}
body.mob #ldBtnRow{display:flex;flex-direction:column;gap:0}
body.mob #ldBtnRow .btn{width:100%}
body.mob .pxbar{flex-wrap:wrap;gap:8px}
body.mob #stateCard h3{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
body.mob #stateCard h3 .btn{margin:0}
/* ==== Nút Áp Dụng Proxy đầu mỗi mục ==== */
.pxbar{display:flex;align-items:center;gap:10px;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px 14px;margin-bottom:14px;flex-wrap:wrap}
.pxbar .plbl{font-size:13px;font-weight:600;color:#cdd7ff}
.pxbar .pstate{font-size:11.5px;color:var(--dim)}
.pxbar .pstate.on{color:var(--ok)}
/* ==== Nút + chọn tài khoản đã lưu ==== */
.withpick{display:flex;gap:6px;align-items:center}
.withpick input{flex:1}
.accpick{flex-shrink:0;width:36px;height:38px;border-radius:8px;border:1px solid var(--acc);background:rgba(91,140,255,.22);color:#9ec1ff;font-size:18px;font-weight:700;cursor:pointer;transition:.15s}
.accpick:hover{background:rgba(91,140,255,.45);color:#fff;border-color:#7ab8ff}
.accmodal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:30;align-items:center;justify-content:center;padding:18px}
.accmodal.show{display:flex}
.accmodal .box{background:var(--card);border:1px solid var(--line);border-radius:14px;max-width:440px;width:100%;max-height:80vh;display:flex;flex-direction:column}
.accmodal .head{padding:12px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:8px;flex-wrap:nowrap}
.accmodal .head b{flex:1;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.accmodal .head select{flex-shrink:1;min-width:90px;width:auto}
.accmodal .listc{overflow-y:auto;padding:8px}
.accitem{display:flex;align-items:center;gap:8px;padding:9px 10px;border:1px solid var(--line);border-radius:10px;margin-bottom:6px;cursor:pointer;transition:.15s}
.accitem:hover{background:var(--card2);border-color:var(--acc)}
.accitem .em{flex:1;font-size:13px;overflow:hidden;text-overflow:ellipsis}
.accitem .tag{font-size:10px;border:1px solid var(--line);border-radius:6px;padding:1px 7px;color:var(--dim);white-space:nowrap}.mod-actions{display:flex;align-items:center;justify-content:flex-end;gap:6px;margin-left:auto;flex:0 0 auto}.mod-actions .btn{margin:0;min-height:30px;padding:5px 9px;font-size:11px;line-height:1;white-space:nowrap}
.accitem .tag.ld{color:#7ab8ff;border-color:#2c4a80}
.mt-select-panel{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.mt-select-panel .btn{margin:0}.mt-selected-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-top:10px}.mt-selected-list:empty,.mt-result:empty,.mt-sku-mini:empty,.mt-run-status:empty{display:none}.mt-selected-row{display:flex;align-items:center;gap:8px;min-width:0;padding:8px 10px;border:1px solid var(--line);border-radius:9px;background:#101936}.mt-selected-row .mt-email{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.mt-selected-row .mt-bal{color:#ffc94d;font-size:12px;white-space:nowrap}.mt-selected-row .mt-mark{font-weight:800;width:18px;text-align:center}.mt-selected-row.ok .mt-mark{color:var(--ok)}.mt-selected-row.bad .mt-mark{color:var(--err)}.mt-selected-row.run .mt-mark{color:var(--warn)}.mt-picked{background:rgba(55,214,122,.17)!important;border-color:var(--ok)!important}.mt-account-modal .box{max-width:520px}.mt-account-modal .head{align-items:flex-start}.mt-account-modal .head small{display:block;color:var(--dim);font-size:11px;margin-top:3px}.mt-account-list{overflow-y:auto;padding:8px;max-height:58vh}.mt-account-option{display:flex;align-items:center;gap:9px;padding:10px;border:1px solid var(--line);border-radius:10px;margin-bottom:7px;cursor:pointer;transition:.15s}.mt-account-option:hover{border-color:var(--acc);background:var(--card2)}.mt-account-option.selected{background:rgba(55,214,122,.18);border-color:var(--ok)}.mt-account-option .mt-option-email{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.mt-account-option .mt-option-bal{font-size:11px;color:var(--dim);white-space:nowrap}.mt-account-option .mt-option-mark{width:20px;text-align:center;font-weight:800;color:var(--dim)}.mt-account-option.selected .mt-option-mark{color:var(--ok)}.mt-account-option.failed .mt-option-mark{color:var(--err)}.mt-picker-footer{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:10px 8px 2px}.mt-picker-footer .btn{margin:0}.mt-run-status{font-size:12px;color:var(--dim);margin-top:8px}.mt-run-status.ok{color:var(--ok)}.mt-run-status.bad{color:var(--err)}.mt-ok{color:var(--ok);font-weight:700}.mt-bad{color:var(--err);font-weight:700}
body.mob .mt-selected-list{grid-template-columns:1fr}.mt-account-modal .head{flex-wrap:wrap}.mt-account-modal .head b{white-space:normal}
.accitem .tag.fp{color:#c89bff;border-color:#4a2c80}
/* ==== Loading phía trên Console ==== */
#loadingBar{display:none;align-items:center;gap:10px;background:rgba(91,140,255,.12);border:1px solid var(--acc);border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#cdd7ff}
#loadingBar.show{display:flex}
/* ==== Lịch Sử Log ==== */
.histitem{display:flex;align-items:center;gap:10px;padding:9px 10px;border:1px solid var(--line);border-radius:10px;margin-bottom:6px;cursor:pointer;transition:.15s}
.histitem:hover{background:var(--card2)}
.histitem .tm{font-size:11px;color:var(--dim);white-space:nowrap}
.histitem .lb{flex:1;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.histitem .st{font-size:11px;padding:1px 8px;border-radius:99px}
.histitem .st.ok{color:var(--ok);border:1px solid var(--ok)}
.histitem .st.bad{color:var(--err);border:1px solid var(--err)}
.histempty{font-size:12px;color:var(--dim);padding:8px 4px}
#lastData{min-width:0;max-width:100%}
#stateCard .acctitle{color:var(--acc);font-size:12px;margin:8px 0 2px}
.mt-hide-common{display:none !important}.collapse-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}.collapse-head .collapse-btn{margin:0;padding:3px 10px;font-size:12px}.compact-panel{display:none;overflow:hidden}
.capmeta{font-size:12px;color:var(--dim);margin:4px 0;word-break:break-all}
.capmeta code{color:var(--warn);user-select:all}
.capb64{margin:4px 0}
.capb64 summary{font-size:12px;color:var(--dim);cursor:pointer}
.capb64 textarea{width:100%;height:56px;font-size:10px;font-family:monospace;background:var(--bg);color:var(--dim);border:1px solid var(--line);border-radius:8px;padding:6px;resize:vertical}
.otpbox{border-color:var(--warn);background:rgba(255,201,71,.08)}.otpbox h3{display:flex;align-items:center;gap:8px}.otpbox .otprow{display:flex;align-items:center;gap:8px;width:100%;min-width:0}.otpbox .otp-cells{display:flex;flex:1 1 auto;min-width:0;gap:6px}.otpbox .otp-cell{width:auto;flex:1 1 0;min-width:0;padding:9px 2px;text-align:center;font-weight:700;font-size:17px;letter-spacing:0}.otpbox .otprow .btn{flex:0 0 auto;width:auto;display:inline-flex;margin:0;white-space:nowrap}.otpbox .otphint{font-size:12px;color:var(--dim);margin:2px 0 9px}@media(max-width:560px){.otpbox .otprow{align-items:stretch}.otpbox .otp-cells{gap:4px}.otpbox .otp-cell{font-size:16px;padding-left:1px;padding-right:1px}.otpbox .otprow .btn{width:auto!important;flex:0 0 auto;display:inline-flex;padding-left:12px;padding-right:12px}}
/* Áp dụng chế độ đã lưu trước lần paint đầu tiên để không nháy giao diện */
html.pre-mob #burger{display:block}html.pre-mob nav{position:sticky;top:0;left:auto;bottom:auto;width:auto;z-index:9;display:flex;align-items:center;gap:6px;overflow-x:auto;overflow-y:hidden;background:rgba(13,20,48,.96);border-right:0;border-bottom:1px solid var(--line);padding:7px 10px;transform:none;scrollbar-width:none}html.pre-mob nav::-webkit-scrollbar{display:none}html.pre-mob.nav-collapsed nav{display:none}html.pre-mob #overlay{display:none!important}html.pre-mob .wrap{display:block;min-height:0}html.pre-mob main{padding:10px 12px;max-width:none}html.pre-mob .grid{grid-template-columns:1fr}html.pre-mob .card{padding:12px}html.pre-mob header{padding:10px 12px;flex-wrap:nowrap;gap:8px}html.pre-mob header h1{font-size:16px;white-space:nowrap}html.pre-mob header .badge{display:none}
/* ==== Danh sách proxy có đánh dấu trạng thái ==== */
.prow{display:flex;align-items:center;gap:6px;margin-bottom:5px}
.prow .pmark{width:20px;text-align:center;font-weight:700;flex-shrink:0;font-size:13px}
.prow .pmark.bad{color:var(--err)}
.prow .pmark.cur{color:var(--ok)}
.prow .pmark.unknown{color:var(--dim)}
.prow input{flex:1;font-family:ui-monospace,monospace;font-size:12px;padding:6px 9px}
.prow .pdel{flex-shrink:0;background:none;border:none;color:var(--err);cursor:pointer;font-size:14px;padding:4px 6px;border-radius:6px}
.prow .pdel:hover{background:rgba(255,91,110,.15)}
.pcurinfo{font-size:11.5px;color:var(--dim);margin:2px 0 8px}
.pcurinfo b{color:var(--ok);font-weight:600}
</style>
<script>(function(){try{var m=localStorage.getItem('fp_view_mode');if(m==='mob'){document.documentElement.classList.add('pre-mob');document.documentElement.dataset.viewMode='mob';}else if(m==='desk'){document.documentElement.dataset.viewMode='desk';}}catch(e){}})();</script>
</head>
<body>
<header>
  <button id="burger" title="Menu" aria-label="Mở menu">☰</button>
  <h1>⚡ FunPass API</h1><span class="badge">funpass.py + khoga.py + muathe.js</span><span class="badge">PHP ≥ 8.0</span>
  <div class="modesw">
    <button class="modebtn" id="modeMob" title="Chế độ Điện thoại" onclick="setViewMode('mob')">📱</button>
    <button class="modebtn active" id="modeDesk" title="Chế độ Máy tính" onclick="setViewMode('desk')">💻</button>
  </div>
</header>
<div id="overlay" onclick="closeDrawer()"></div>
  <div id="apibanner" style="display:none;background:rgba(255,91,110,.12);border-bottom:1px solid var(--err);color:#ffb3bd;padding:10px 24px;font-size:13px">
  ⛔ <b>Không gọi được API PHP.</b> Giao diện vẫn chuyển tab được, nhưng mọi nút chức năng sẽ không chạy.
  Nguyên nhân thường gặp: file đang được mở như trang tĩnh thay vì chạy qua PHP — hãy chạy <code>php -S 0.0.0.0:8000 api.php</code>
  hoặc đặt file vào host có PHP ≥ 8.0 (extension curl + openssl) rồi mở lại. <span id="apibanner_err" class="small"></span>
</div>
<div class="wrap">
<nav>
  <button id="navAuto" class="active" data-tab="auto">🤖 Funpass Auto</button>
  <!-- Module PHP độc lập do người dùng upload — nằm ngay dưới Funpass Auto -->
  <div id="navCustomMods"></div>
  <button id="navKhoga" data-tab="khoga">🧩 Khoga Flow</button>
  <button id="navMuathe" data-tab="muathe">💳 Mua Thẻ</button>
  <button id="navProxy" data-tab="proxy">🌐 Proxy (√/×)</button>
  <button id="navAccounts" data-tab="accounts">👤 Tài Khoản</button>
</nav>
<main>
  <!-- ============ TAB AUTO (funpass.py) ============ -->
  <div class="tab active" id="tab-auto">
    <h2 class="sec">Funpass Auto</h2>
    <div class="pxbar">
      <label class="sw"><input type="checkbox" id="px_auto" checked onchange="pxChanged('px_auto')"><span class="sl"></span></label>
      <span class="plbl">Áp Dụng Proxy</span>
      <span class="pstate on" id="px_auto_st">Đang bật</span>
    </div>
    <div class="card">
      <h3>Tài khoản LDPlayer cố định (nhận điểm)</h3>
      <div class="grid">
        <div><label>Email LDPlayer</label>
          <div class="withpick"><input id="a_ld_email" placeholder="email@example.com"><button type="button" class="accpick" title="Chọn tài khoản LDPlayer đã lưu" onclick="openAccPicker({a_ld_email:'email',a_ld_pass:'password',a_android_id:'androidid'},'ldplayer')">+</button></div></div>
        <div><label>Mật khẩu</label><input id="a_ld_pass" type="password"></div>
        <div><label>Android ID </label><input id="a_android_id" placeholder="Nhập Android ID" spellcheck="false"></div>
        <div><label>Proxy bắt đầu</label><input id="a_proxy_idx" type="number" value="0" min="0"></div>
      </div>
      <button class="btn" onclick="funpassOnce()">▶ Chạy 1 chu kỳ</button>
      <label style="margin-top:10px"><input type="checkbox" id="a_loop" style="width:auto"> Lặp liên tục</label>
      <div class="capbox" id="cap_auto">
        <b style="color:var(--warn)">Nhập captcha thủ công</b>
        <div class="small">Server không giải được tự động. Xem ảnh và nhập mã:</div>
        <div class="capmeta">captcha_id: <code id="cap_auto_id"></code></div>
        <img id="cap_auto_img" alt="captcha">
        <details class="capb64"><summary>captcha_data (base64)</summary><textarea id="cap_auto_b64" readonly spellcheck="false"></textarea></details>
        <input id="cap_auto_code" placeholder="Mã captcha">
        <button class="btn ok" onclick="funpassOnce(true)">Gửi captcha & chạy tiếp</button>
      </div>
    </div>
    <!-- Bảng Đăng Nhập / Đăng Ký LDPlayer (xuất hiện sau khi Funpass + nhiệm vụ xong) -->
    <div class="card" id="ldStepCard" style="display:none">
      <h3>Đăng nhập / Đăng ký LDPlayer (bước chuyển điểm)</h3>
      <div id="ldBtnRow">
        <button class="btn" id="btnLdLogin" onclick="showLdForm('login')">Đăng Nhập</button>
        <button class="btn ok" id="btnLdReg" onclick="showLdForm('reg')">Đăng Ký</button>
      </div>
      <div id="ldLoginForm" style="display:none;margin-top:12px;border:1px solid var(--line);border-radius:10px;padding:12px">
        <b>Đăng Nhập Tài Khoản Ldplayer</b>
        <div class="grid" style="margin-top:8px">
          <div><label>Gmail</label><input id="ldf_login_email" placeholder="email@example.com"></div>
          <div><label>Password</label><input id="ldf_login_pass" type="password"></div>
          <div><label>Android ID </label><input id="ldf_login_android_id" placeholder="Android ID đã lưu" spellcheck="false"></div>
        </div>
        <button class="btn" onclick="doLdLogin()">Đăng Nhập</button>
        <button class="btn ghost" onclick="exitLdForm()">Exit</button>
      </div>
      <div id="ldRegForm" style="display:none;margin-top:12px;border:1px solid var(--line);border-radius:10px;padding:12px">
        <b>Đăng Ký Tài Khoản Ldplayer</b>
        <div class="grid" style="margin-top:8px">
          <div><label>Gmail</label><input id="ldf_reg_email" placeholder="Email"></div>
          <div><label>Password</label><input id="ldf_reg_pass" placeholder="Mật khẩu"></div>
          <div><label>Android ID </label><input id="ldf_reg_android_id" placeholder="Android ID đã lưu" spellcheck="false"></div>
        </div>
        <button class="btn ok" onclick="doLdRegister()">Xác Nhận</button>
        <button class="btn ghost" onclick="exitLdForm()">Exit</button>
      </div>
    </div>
  </div>

  <!-- ============ TAB KHOGA ============ -->
  <div class="tab" id="tab-khoga">
    <h2 class="sec">Khoga Flow</h2>
    <div class="pxbar">
      <label class="sw"><input type="checkbox" id="px_khoga" checked onchange="pxChanged('px_khoga')"><span class="sl"></span></label>
      <span class="plbl">Áp Dụng Proxy</span>
      <span class="pstate on" id="px_khoga_st">Đang bật</span>
    </div>
    <div class="card">
      <h3>Chuyển điểm</h3>
      <div class="grid">
        <div><label>Chế độ</label>
          <select id="k_mode"><option value="auto">Tự động (tạo LDPlayer mới cùng email)</option><option value="existing">Thủ công (đăng nhập LDPlayer có sẵn)</option></select></div>
        <div><label>Email LDPlayer</label>
          <div class="withpick"><input id="k_ld_email"><button type="button" class="accpick" title="Chọn tài khoản LDPlayer đã lưu" onclick="openAccPicker({k_ld_email:'email',k_ld_pass:'password',k_android_id:'androidid'},'ldplayer')">+</button></div></div>
        <div><label>Mật khẩu</label><input id="k_ld_pass" type="password"></div>
        <div><label>Android ID </label><input id="k_android_id" placeholder="ID dùng cho flow này" spellcheck="false"></div>
      </div>
      <label><input type="checkbox" id="k_confirm" checked style="width:auto"> Tự động chuyển điểm</label><br>
      <button id="k_flow_run" class="btn" onclick="khogaFull()">▶ Chạy full flow</button>
      <div class="capbox" id="cap_khoga">
        <b style="color:var(--warn)">Nhập captcha thủ công</b>
        <div class="capmeta">captcha_id: <code id="cap_khoga_id"></code></div>
        <img id="cap_khoga_img" alt="captcha">
        <details class="capb64"><summary>captcha_data (base64)</summary><textarea id="cap_khoga_b64" readonly spellcheck="false"></textarea></details>
        <input id="cap_khoga_code" placeholder="Mã captcha">
        <button class="btn ok" onclick="khogaFull(true)">Gửi captcha & chạy tiếp</button>
      </div>
    </div>
    <div class="card">
      <h3>🔐 Đăng nhập / Tạo tài khoản <span class="small">— 1 bảng chung Funpass &amp; LDPlayer</span></h3>
      <div class="grid">
        <div><label>Gmail</label>
          <div class="withpick"><input id="m_email" placeholder="email@example.com"><button type="button" class="accpick" title="Chọn tài khoản đã lưu" onclick="openAccPicker({m_email:'email',m_pass:'password',m_temp_token:'temp_token',m_android_id:'androidid'},'')">+</button></div></div>
        <div><label>Password</label><input id="m_pass" type="password" placeholder="mật khẩu"></div>
        <div><label>Temp Token <span class="small">(chỉ cần khi Tạo LDPlayer mới)</span></label>
          <input id="m_temp_token" placeholder="Nhập temp token"></div>
        <div><label>Android ID</label>
          <input id="m_android_id" placeholder="Nhập Android ID" spellcheck="false"></div>
      </div>
      <div class="khoga-action-grid">
        <div class="khoga-action-row">
          <button id="m_create_funpass" class="btn" onclick="manualAction('fp_create_funpass',{android_id:v('m_android_id')})">✨ Tạo Funpass mới</button>
          <button id="m_create_ldplayer" class="btn ok" onclick="createLdManual()">✨ Tạo LDPlayer</button>
        </div>
        <div class="khoga-action-row">
          <button id="m_login_funpass" class="btn ghost" onclick="manualAction('fp_login_funpass',{email:v('m_email'),password:v('m_pass'),android_id:v('m_android_id')})">🔑 Đăng nhập Funpass</button>
          <button id="m_login_ldplayer" class="btn ghost" onclick="manualAction('fp_login_ldplayer',{email:v('m_email'),password:v('m_pass'),android_id:v('m_android_id')})">🔑 Đăng nhập LDPlayer</button>
        </div>
      </div>
      <button class="btn ghost" onclick="fillTempToken()">⤵ Điền từ Funpass vừa tạo</button>
      <!-- ẩn field cũ để JS cũ không lỗi -->
      <input type="hidden" id="m_ld_email">
      <hr style="border-color:var(--line);margin:14px 0">
      <h3 class="collapse-head"><span>🎮 CPI thủ công</span><button type="button" class="btn ghost collapse-btn" onclick="toggleCompactPanel('khogaCpi')">↓</button></h3>
      <div id="khogaCpi" class="compact-panel">
      <button class="btn ghost" onclick="cpiManual(true)">📋 Xem game CPI</button>
      <button class="btn" onclick="cpiManual(false)">▶ Chạy CPI</button>
      <div class="capbox" id="cap_manual">
        <b style="color:var(--warn)">Nhập captcha thủ công</b>
        <div class="capmeta">captcha_id: <code id="cap_manual_id"></code></div>
        <img id="cap_manual_img" alt="captcha">
        <details class="capb64"><summary>captcha_data (base64)</summary><textarea id="cap_manual_b64" readonly spellcheck="false"></textarea></details>
        <input id="cap_manual_code" placeholder="Mã captcha">
        <button class="btn ok" onclick="manualCaptchaRetry()">Gửi captcha &amp; chạy tiếp</button>
      </div>
      </div>
      <hr style="border-color:var(--line);margin:14px 0">
      <h3 class="collapse-head"><span>💸 Chuyển điểm</span><button type="button" class="btn ghost collapse-btn" onclick="toggleCompactPanel('khogaTransfer')">↓</button></h3>
      <div id="khogaTransfer" class="compact-panel">
      <div class="grid">
        <div><label>Funpass UID</label>
          <div class="withpick"><input id="t_fpuid"><button type="button" class="accpick" title="Chọn Funpass" onclick="openAccPicker({t_fpuid:'uid',t_fptoken:'token'},'funpass')">+</button></div></div>
        <div><label>Funpass Token</label><input id="t_fptoken"></div>
        <div><label>LDPlayer UID</label>
          <div class="withpick"><input id="t_lduid"><button type="button" class="accpick" title="Chọn LDPlayer" onclick="openAccPicker({t_lduid:'uid',t_ldtoken:'token'},'ldplayer')">+</button></div></div>
        <div><label>LDPlayer Token</label><input id="t_ldtoken"></div>
      </div>
      <button class="btn ghost" onclick="call('fp_wallet',{uid:v('t_fpuid'),token:v('t_fptoken')})">💰 Xem ví</button>
      <button class="btn ghost" onclick="call('fp_transfer_detail',{funpassUid:v('t_fpuid'),funpassToken:v('t_fptoken'),ldUid:v('t_lduid'),ldToken:v('t_ldtoken')})">📄 Transfer Detail</button>
      <button class="btn danger" onclick="if(confirm('Chuyển điểm thật?'))call('fp_transfer_execute',{funpassUid:v('t_fpuid'),funpassToken:v('t_fptoken'),ldUid:v('t_lduid'),ldToken:v('t_ldtoken')})">⚡ Transfer Execute</button>
      </div>
    </div>
  </div>

  <!-- ============ TAB MUATHE ============ -->
  <div class="tab" id="tab-muathe">
    <h2 class="sec">Mua Thẻ</h2>
    <div class="pxbar">
      <label class="sw"><input type="checkbox" id="px_muathe" onchange="pxChanged('px_muathe')"><span class="sl"></span></label>
      <span class="plbl">Áp Dụng Proxy</span>
      <span class="pstate" id="px_muathe_st">× Chạy trực tiếp, không qua proxy</span>
    </div>
    <div class="card">
      <h3>Phiên đăng nhập nhiều tài khoản <span id="mt_sess" class="small"></span></h3>
      <div class="mt-select-panel">
        <button type="button" class="btn ghost" onclick="openMtaAccountPicker()">＋ Chọn tài khoản</button>
        <span id="mtSelectCount" class="small"></span>
      </div>
      <div id="mtSelectedAccounts" class="mt-selected-list"></div>
      <div id="mtRunStatus" class="mt-run-status"></div>
      <input id="mt_user" type="hidden"><input id="mt_pass" type="hidden"><input id="mt_android_id" type="hidden">
      <button class="btn" onclick="mtLoginSelected()">🔑 Đăng nhập các tài khoản đã chọn</button>
      <button class="btn ghost" onclick="mtLogoutSelected()">🚪 Đăng xuất các phiên</button>
    </div>
    <div class="card">
      <h3>Chức năng</h3>
      <button class="btn ghost" onclick="mtSyncSelected()">⏱️ Sync thời gian</button>
      <button class="btn ghost" onclick="mtBalanceSelected()">💰 Xem số dư</button>
      <button class="btn ghost" onclick="mtSkusSelected()">📦 Xem tất cả SKU</button>
      <button class="btn" onclick="buyAuto()">🛒 Mua thẻ tự động</button>
      <hr style="border-color:var(--line);margin:12px 0">
      <div class="grid">
        <div><label>SKU ID</label><input id="mt_sku" type="number"></div>
        <div><label>Số lượng</label><input id="mt_num" type="number" value="1" min="1"></div>
        <div><label>Delay (ms)</label><input id="mt_delay" type="number" value="1000"></div>
        <div><label>Số lần thử tối đa</label><input id="mt_max" type="number" value="100"></div>
      </div>
      <button class="btn danger" onclick="if(confirm('Bắt đầu săn thẻ cho các tài khoản đã chọn?'))mtSnipeSelected()">🎯 Săn thẻ (Sniper)</button>
      <button class="btn ghost" onclick="mtCreateOrderSelected()">📝 Tạo đơn theo SKU</button>
      <hr style="border-color:var(--line);margin:12px 0">
      <div class="grid">
        <div><label>Trang</label><input id="mt_page" type="number" value="1"></div>
        <div><label>Số lượng/trang</label><input id="mt_size" type="number" value="10"></div>
        <div><label>Mã đơn hàng</label><input id="mt_order"></div>
      </div>
      <button class="btn ghost" onclick="mtGiftListSelected()">🎁 Thẻ đã mua</button>
      <button class="btn ghost" onclick="mtGiftDetailSelected()">🔎 Chi tiết đơn</button>
      <button class="btn ghost" title="SKU có sẵn trên easyfun" onclick="mtAvailableSkusSelected()">📋 SKU có sẵn</button>
      <div id="mtSkuMini" class="mt-result mt-sku-mini"></div>
      <div id="mtResult" class="mt-result"></div>
    </div>
  </div>

  <!-- ============ TAB MODULE ĐỘC LẬP (iframe — api.php không can thiệp nội dung) ============ -->
  <div class="tab" id="tab-custommod">
    <div class="custom-mod-shell">
      <div class="custom-mod-toolbar">
        <div class="custom-mod-icon">📦</div>
        <div class="custom-mod-copy"><b id="customModTitle">Module PHP</b><small id="customModFile">File độc lập · chỉ hiển thị trong mục này</small></div>
        <div class="custom-mod-actions">
          <button class="custom-mod-action" type="button" title="Quay về Funpass Auto" aria-label="Quay về Funpass Auto" onclick="closeCustomModule()">←</button>
          <button class="custom-mod-action danger custom-mod-delete" type="button" title="Xóa file PHP này" aria-label="Xóa file PHP này" onclick="deleteCurrentCustomModule()">🗑</button>
        </div>
      </div>
      <iframe id="customModFrame" title="Nội dung file PHP module"></iframe>
    </div>
  </div>

  <!-- ============ TAB PROXY ============ -->
  <div class="tab" id="tab-proxy">
    <h2 class="sec">Cấu hình Proxy</h2>
    <div class="card">
      <h3>Bật/tắt proxy từng chức năng</h3>
      <div id="featList"></div>
    </div>
    <div class="card">
      <h3>Danh sách proxy</h3>
      <div class="small" style="margin-bottom:10px">✓ Hoạt động &nbsp;|&nbsp; <span style="color:var(--err)">× Lỗi</span> &nbsp;|&nbsp; • Chưa dùng</div>
      <div class="grid">
        <div>
          <label>Proxy US</label>
          <div class="pcurinfo" id="p_us_cur"></div>
          <div id="p_us_list"></div>
          <button class="btn ghost" style="padding:5px 12px;font-size:12px" onclick="addProxyRow('us','')">+ Thêm proxy US</button>
        </div>
        <div>
          <label>Proxy Việt Nam</label>
          <div class="pcurinfo" id="p_vn_cur"></div>
          <div id="p_vn_list"></div>
          <button class="btn ghost" style="padding:5px 12px;font-size:12px" onclick="addProxyRow('vn','')">+ Thêm proxy VN</button>
        </div>
      </div>
      <button class="btn ok" onclick="saveProxy()">💾 Lưu cấu hình proxy</button>
      <button class="btn danger" onclick="resetProxyState()">↺ Xóa dấu × (reset trạng thái lỗi)</button>
    </div>
  </div>

  <!-- ============ TAB TÀI KHOẢN ============ -->
  <div class="tab" id="tab-accounts">
    <h2 class="sec">Tài Khoản</h2>
    <div class="card">
      <h3>💾 Lưu thủ công <span class="small">— dán/nhập trực tiếp JSON tài khoản (đúng MẪU)</span>
        <button class="btn ghost" style="float:right;padding:3px 10px;font-size:12px" title="Dán từ clipboard" onclick="pasteToSvJson()">📋 Dán</button>
      </h3>
      <label>JSON tài khoản</label>
      <textarea id="sv_json" spellcheck="false" style="min-height:150px" placeholder='{
  "uid": "string",
  "token": "string",
  "androidid": "string",
  "username": "string",
  "password": "string",
  "temp_token": "string",
  "serverTimeOffset": 0
}'></textarea>
      <div class="grid" style="margin-top:4px">
        <div><label>Lưu vào danh mục</label>
          <select id="sv_type"><option value="auto">Tự nhận diện (có temp_token → Funpass, không có → LDPlayer)</option><option value="funpass">Funpass</option><option value="ldplayer">LDPlayer</option></select></div>
      </div>
      <button class="btn ok" onclick="saveAccount()">💾 Lưu</button>
    </div>
    <div class="card">
      <h3>🟣 Tài khoản Funpass <span class="small" id="accCntFp"></span>
        <button class="btn ghost" id="accTglFp" style="float:right;padding:3px 10px;font-size:12px" title="Thu gọn / Mở rộng" onclick="toggleAccCat('fp')">↑</button></h3>
      <div id="accListFp"></div>
    </div>
    <div class="card">
      <h3>🔵 Tài khoản LDPlayer <span class="small" id="accCntLd"></span>
        <button class="btn ghost" id="accTglLd" style="float:right;padding:3px 10px;font-size:12px" title="Thu gọn / Mở rộng" onclick="toggleAccCat('ld')">↑</button></h3>
      <div id="accListLd"></div>
    </div>
    <!-- Danh sách file PHP độc lập → menu dưới Funpass Auto và danh sách trong Tài Khoản -->
    <div class="card">
      <h3>📦 File PHP đã thêm</h3>
      <div id="modListBox" style="margin-top:12px"></div>
    </div>
  </div>

  <!-- ============ OTP THỦ CÔNG + LOADING + CONSOLE ============ -->
  <div class="card otpbox" id="otpBox" style="display:none">
    <h3>🔐 Nhập mã OTP</h3>
    <div class="otphint" id="otpHint">Chưa nhận được OTP tự động. Hãy kiểm tra email rồi nhập đúng 6 chữ số.</div>
    <div class="otprow"><div class="otp-cells" id="otpCells" role="group" aria-label="Mã OTP 6 số"><input class="otp-cell" inputmode="numeric" autocomplete="one-time-code" maxlength="1" aria-label="Số OTP 1"><input class="otp-cell" inputmode="numeric" maxlength="1" aria-label="Số OTP 2"><input class="otp-cell" inputmode="numeric" maxlength="1" aria-label="Số OTP 3"><input class="otp-cell" inputmode="numeric" maxlength="1" aria-label="Số OTP 4"><input class="otp-cell" inputmode="numeric" maxlength="1" aria-label="Số OTP 5"><input class="otp-cell" inputmode="numeric" maxlength="1" aria-label="Số OTP 6"></div><button class="btn ok" type="button" id="otpSubmitBtn" onclick="submitOtp()">Xác nhận</button></div>
  </div>
  <div class="card capbox shared-cap" id="capSharedBox" style="display:none">
    <div class="cap-title"><b style="color:var(--warn)">🧩 Nhập captcha thủ công</b></div>
    <div class="small" id="capSharedHint">Xem ảnh captcha rồi nhập mã bên dưới.</div>
    <div class="capmeta">captcha_id: <code id="capSharedId"></code></div>
    <img id="capSharedImg" alt="Ảnh captcha cần nhập">
    <div class="cap-row"><input id="capSharedCode" autocomplete="off" spellcheck="false" placeholder="Mã captcha" aria-label="Mã captcha"><button class="btn ok" type="button" id="capSharedSubmitBtn" onclick="submitCaptcha()">Xác nhận</button></div>
  </div>
  <div id="loadingBar"><span class="spinner"></span><span id="loadingTxt">⏳ Đang chạy...</span></div>
  <div class="card">
    <div class="console-head">
      <h3>Console</h3>
      <div class="console-tools"><button class="btn ghost" id="detToggle" onclick="toggleDetail()">Chi tiết: Ẩn</button><button class="btn ghost" onclick="document.getElementById('console').innerHTML=''">Xóa</button></div>
    </div>
    <div id="console"></div>
  </div>
  <!-- Hộp kết quả: tài khoản đúng MẪU + tóm tắt flow — nằm giữa Console và Lịch Sử Log -->
  <div class="card" id="stateCard">
    <h3>📋 Tài Khoản / Dữ Liệu Mới Nhất <span class="small" id="stateFrom"></span>
      <button class="btn ghost" id="btnCopyPanel" style="float:right;padding:3px 10px;font-size:11px;margin-left:4px" title="Copy toàn bộ nội dung bảng" onclick="copyPanelData()">📋 Copy</button>
      <button class="btn ok" id="btnSavePanel" style="float:right;padding:3px 10px;font-size:11px;display:none" title="Lưu tài khoản đang hiển thị vào mục Tài Khoản" onclick="savePanelAccounts()">💾 Lưu Tài Khoản</button>
    </h3>
    <div id="lastData" style="max-height:420px;overflow:auto">
      <div class="acctitle">Tài Khoản Funpass:</div>
      <pre class="json" id="panelFp">{
  "uid": "",
  "token": "",
  "androidid": "",
  "username": "",
  "password": "",
  "temp_token": "",
  "serverTimeOffset": 0
}</pre>
      <div class="acctitle">Tài Khoản Ldplayer:</div>
      <pre class="json" id="panelLd">{
  "uid": "",
  "token": "",
  "androidid": "",
  "username": "",
  "password": "",
  "temp_token": "",
  "serverTimeOffset": 0
}</pre>
    </div>
  </div>
  <div class="card">
    <h3>🕘 Lịch Sử Log
      <button class="btn ghost" style="float:right;padding:3px 10px;font-size:12px" title="Xóa lịch sử" onclick="clearHistory()">🗑️</button>
      <button class="btn ghost" id="histToggle" style="float:right;padding:3px 10px;font-size:12px;margin-right:6px" title="Mở rộng / Thu gọn" onclick="toggleHistory()">▸</button>
    </h3>
    <div id="histList" style="display:none"><div class="histempty">Chưa có lần chạy nào.</div></div>
  </div>
</main>
</div>
<div id="statusbar"><span class="dot" id="sdot"></span><span id="stext">Sẵn sàng</span></div>

<!-- Modal chọn tài khoản đã lưu -->
<div class="accmodal" id="accModal">
  <div class="box">
    <div class="head"><b>Chọn tài khoản đã lưu</b>
      <select id="accFilter" style="width:auto" onchange="renderAccPicker()">
        <option value="">Tất cả</option><option value="ldplayer">Ldplayer</option><option value="funpass">Funpass</option>
      </select>
      <button class="btn ghost" style="padding:4px 12px;margin:0" onclick="closeAccPicker()">✕</button>
    </div>
    <div class="listc" id="accPickList"></div>
  </div>
</div>
<!-- Bộ chọn nhiều tài khoản chỉ dành cho phiên Mua Thẻ -->
<div class="accmodal mt-account-modal" id="mtAccountModal">
  <div class="box">
    <div class="head"><div style="flex:1;min-width:0"><b>Chọn tài khoản Mua Thẻ</b><small>Chọn không giới hạn tài khoản. Nền xanh là đang chọn.</small></div><button class="btn ghost" style="padding:4px 12px;margin:0" onclick="closeMtaAccountPicker()">✕</button></div>
    <div class="mt-account-list" id="mtAccountPickList"></div>
    <div class="mt-picker-footer"><span id="mtPickerCount" class="small">0/∞ tài khoản</span><button class="btn ok" type="button" onclick="applyMtaAccountPicker()">✓ Xác Nhận</button></div>
  </div>
</div>

<script>
// ===== Chế độ giao diện 📱/💻 (lưu localStorage) =====
let viewMode = localStorage.getItem('fp_view_mode') || 'desk';
function setViewMode(m){
  viewMode = m; localStorage.setItem('fp_view_mode', m);
  document.documentElement.classList.toggle('pre-mob', m === 'mob');
  document.documentElement.dataset.viewMode = m;
  document.body.classList.toggle('mob', m === 'mob');
  document.getElementById('modeMob').classList.toggle('active', m === 'mob');
  document.getElementById('modeDesk').classList.toggle('active', m !== 'mob');
  if (m === 'mob') document.body.classList.remove('nav-collapsed');
  if (m !== 'mob') closeDrawer();
}
function openDrawer(){
  const n=document.querySelector('nav');
  document.body.classList.remove('nav-collapsed');
  if (n) {
    n.classList.remove('nav-animate');
    void n.offsetWidth;
    n.classList.add('nav-animate');
    window.setTimeout(() => n.classList.remove('nav-animate'), 1500);
  }
}
function closeDrawer(){
  const n=document.querySelector('nav'); if(n) n.classList.remove('open');
  const o=document.getElementById('overlay'); if(o) o.classList.remove('show');
}
function collapseNav(){
  document.body.classList.add('nav-collapsed');
  const n=document.querySelector('nav'); if(n) n.classList.remove('nav-animate');
}

function toggleCompactPanel(id){ const el=document.getElementById(id); if(!el)return; const open=getComputedStyle(el).display==='none'; el.style.display=open?'block':'none'; const btn=el.previousElementSibling?.querySelector('.collapse-btn'); if(btn)btn.textContent=open?'↑':'↓'; }
// ===== Khởi tạo tab TRƯỚC, độc lập: chuyển mục luôn hoạt động kể cả khi API lỗi =====
(function initTabs(){
  const burger = document.getElementById('burger');
  const nav = document.querySelector('nav');
  document.querySelectorAll('nav button').forEach((b,i) => {
    b.style.setProperty('--nav-i', i);
    b.onclick = () => {
    if (b.dataset.tab !== 'custommod') document.body.classList.remove('custom-module-open');
    document.querySelectorAll('nav button').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
    b.classList.add('active');
    const t = document.getElementById('tab-'+b.dataset.tab);
    if (t) t.classList.add('active');
    const common = document.getElementById('stateCard');
    if (common) common.classList.toggle('mt-hide-common', ['muathe','proxy','accounts'].includes(b.dataset.tab));
    const consoleCard = document.getElementById('console')?.closest('.card'); if (consoleCard) consoleCard.classList.toggle('mt-hide-common', ['proxy','accounts'].includes(b.dataset.tab));
    if (!document.body.classList.contains('mob')) closeDrawer();
    if (b.dataset.tab === 'muathe' && typeof refreshSess === 'function') refreshSess();
    };
  });
  if (burger) burger.onclick = () => {
    document.body.classList.contains('nav-collapsed') ? openDrawer() : collapseNav();
  };
  if (nav && document.body.classList.contains('mob')) openDrawer();
  const section = new URLSearchParams(location.search).get('section');
  if (section && /^(auto|khoga|muathe|proxy|accounts)$/.test(section)) {
    const target = document.querySelector(`nav button[data-tab="${section}"]`);
    if (target) target.click();
  }
})();

// ===== Tên hiển thị của chức năng (dùng cho Loading + Lịch Sử Log) =====
const ACTION_LABELS = {
  ping:'Kiểm tra kết nối', proxy_get:'Tải cấu hình proxy', proxy_save:'Lưu cấu hình proxy',
  fp_create_funpass:'Tạo Funpass mới', fp_create_ldplayer:'Tạo LDPlayer mới',
  fp_login_funpass:'Đăng nhập Funpass', fp_login_ldplayer:'Đăng nhập LDPlayer',
  fp_wallet:'Xem ví Funpass', fp_cpi_ads:'Lấy danh sách game CPI', fp_cpi_run:'Chạy nhiệm vụ CPI',
  fp_transfer_detail:'Transfer Detail', fp_transfer_execute:'Transfer Execute',
  flow_khoga_full:'Khoga Full Flow', flow_funpass_once:'Funpass Auto (1 chu kỳ)',
  mt_login:'[Mua thẻ] Đăng nhập LDPlayer', mt_session:'Kiểm tra phiên đăng nhập', mt_logout:'Đăng xuất / xóa phiên',
  mt_sync:'Sync thời gian server',
  mt_balance:'Xem số dư', mt_skus:'Xem tất cả SKU', mt_available_skus:'SKU có sẵn',
  mt_gift_list:'Xem thẻ đã mua', mt_gift_detail:'Xem chi tiết đơn', mt_create_order:'Tạo đơn theo SKU',
  mt_buy_auto:'Mua thẻ tự động', mt_snipe:'Săn thẻ (Sniper)',
  acc_list:'Tải danh sách tài khoản', acc_save:'Lưu tài khoản', acc_delete:'Xóa tài khoản',
  runlog_list:'Tải lịch sử log', runlog_clear:'Xóa lịch sử log', proxy_reset_state:'Reset trạng thái proxy',
  create_empty_file:'Tạo module PHP độc lập', module_upload:'Thêm file PHP', module_delete:'Xóa file PHP', modules_list:'Danh sách module', modules_remove:'Gỡ module khỏi menu'
};
const labelOf = a => ACTION_LABELS[a] || a;

let FEATURES = [];   // được tải từ server qua action=proxy_get (không còn nhúng PHP vào JS)
const v = id => { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
let busy = false, loopTimer = null, proxyIdx = 0;
let capCtx = {auto:null, khoga:null};
let sharedCaptchaPending = null;
let otpPending = null; // {action,data,ctx,fromMimi,showConsole} — chỉ giữ trong bộ nhớ tab
let otpPromptTimer = null, otpEarlyCode = '', otpEarlyShown = false;
const OTP_WATCH_ACTIONS = ['fp_create_funpass','fp_create_ldplayer','flow_khoga_full','flow_funpass_once'];
function startOtpWatch(action, data, fromMimi, showConsole){
  if (OTP_WATCH_ACTIONS.indexOf(action) < 0) return;
  if (data && data.ctx && data.ctx.otp) return; // resume đã có OTP, không mở prompt giả
  clearTimeout(otpPromptTimer); otpEarlyCode = ''; otpEarlyShown = false;
  otpPromptTimer = setTimeout(() => {
    otpEarlyShown = true;
    const box = document.getElementById('otpBox'), submit = document.getElementById('otpSubmitBtn'), hint = document.getElementById('otpHint');
    if (box) box.style.display = 'block';
    if (hint) hint.textContent = 'Đang chờ server xác nhận phiên OTP...';
    setOtpInputValue(otpEarlyCode);
    if (submit) { submit.disabled = true; submit.title = 'Đang chờ server xác nhận phiên OTP'; }
  }, 6000);
}
function stopOtpWatch(){ if (otpPromptTimer){ clearTimeout(otpPromptTimer); otpPromptTimer = null; } }
function otpInputValue(){ return Array.from(document.querySelectorAll('#otpCells .otp-cell')).map(el => (el.value || '').replace(/\D/g,'').slice(0,1)).join(''); }
function setOtpInputValue(value){
  const digits = String(value || '').replace(/\D/g,'').slice(0,6);
  document.querySelectorAll('#otpCells .otp-cell').forEach((el,i) => { el.value = digits[i] || ''; });
}
function setupOtpInputs(){
  const cells = Array.from(document.querySelectorAll('#otpCells .otp-cell'));
  cells.forEach((el,i) => {
    el.addEventListener('input', () => {
      el.value = (el.value || '').replace(/\D/g,'').slice(0,1);
      otpEarlyCode = otpInputValue();
      if (el.value && cells[i+1]) cells[i+1].focus();
    });
    el.addEventListener('keydown', ev => {
      if (ev.key === 'Backspace' && !el.value && cells[i-1]) cells[i-1].focus();
      if (ev.key === 'ArrowLeft' && cells[i-1]) { ev.preventDefault(); cells[i-1].focus(); }
      if (ev.key === 'ArrowRight' && cells[i+1]) { ev.preventDefault(); cells[i+1].focus(); }
    });
    el.addEventListener('paste', ev => {
      const text = (ev.clipboardData || window.clipboardData).getData('text');
      const digits = String(text || '').replace(/\D/g,'').slice(0,6);
      if (!digits) return;
      ev.preventDefault(); setOtpInputValue(digits); otpEarlyCode = otpInputValue();
      const target = cells[Math.min(digits.length,6)-1]; if (target) target.focus();
    });
  });
}
function showOtpBox(j, action, data, fromMimi, showConsole){
  hideCaptchaBox();
  stopOtpWatch();
  otpPending = {action, data:Object.assign({}, data || {}), ctx:(j && j.ctx) || {}, fromMimi:!!fromMimi, showConsole:!!showConsole};
  const box = document.getElementById('otpBox');
  const hint = document.getElementById('otpHint');
  if (hint) hint.textContent = 'Chưa nhận được mã sau 6 giây. Kiểm tra email ' + (j && j.email ? j.email : '') + ' rồi nhập đúng 6 chữ số.';
  if (box) box.style.display = 'block';
  setOtpInputValue(otpEarlyCode);
  const submit = document.getElementById('otpSubmitBtn');
  if (submit) { submit.disabled = false; submit.title = ''; }
  log(['[OTP] Đang chờ bạn nhập mã xác nhận thủ công.']);
}
function hideOtpBox(){
  stopOtpWatch();
  otpEarlyCode = '';
  const box = document.getElementById('otpBox');
  if (box) box.style.display = 'none';
  setOtpInputValue('');
  otpPending = null;
}
async function submitOtp(){
  if (!otpPending) return;
  const submitBtn = document.getElementById('otpSubmitBtn');
  if (submitBtn && submitBtn.disabled) return;
  const otp = otpInputValue();
  otpEarlyCode = otp;
  if (!/^\d{6}$/.test(otp)){
    const hint = document.getElementById('otpHint');
    if (hint) hint.textContent = 'Mã OTP phải gồm đúng 6 chữ số.';
    log(['[OTP] Mã OTP phải gồm đúng 6 chữ số.']);
    return;
  }
  const pending = otpPending;
  const data = Object.assign({}, pending.data, {ctx:Object.assign({}, pending.ctx, {otp})});
  if (submitBtn) submitBtn.disabled = true;
  hideOtpBox();
  log(['[OTP] Đã nhận mã thủ công, tiếp tục đúng bước đang chờ...']);
  await call(pending.action, data, {fromMimi:pending.fromMimi, showConsole:pending.showConsole});
  if (submitBtn) submitBtn.disabled = false;
}
function apiDown(msg){
  const b = document.getElementById('apibanner');
  if (b){ b.style.display = 'block'; document.getElementById('apibanner_err').textContent = msg ? '('+msg+')' : ''; }
}

function setStatus(run, txt){
  document.getElementById('sdot').className = 'dot' + (run?' run':'');
  document.getElementById('stext').innerHTML = (run?'<span class="spinner"></span>':'') + txt;
}
// ===== Loading phía trên Console =====
function showLoading(label){
  document.getElementById('loadingTxt').textContent = '⏳ Đang chạy: ' + label;
  document.getElementById('loadingBar').classList.add('show');
}
function hideLoading(){ document.getElementById('loadingBar').classList.remove('show'); }

// Console chỉ hiện TÓM TẮT tiến trình; dòng chi tiết kỹ thuật ([HTTP] headers/body/response,
// [JSON LOG]) bị ẩn — bấm "Chi tiết: Hiện" để xem, Lịch Sử Log luôn lưu đầy đủ.
let showDet = localStorage.getItem('fp_showdet') === '1';
let logScrollFrame = 0;
function isDetailLine(l){ return /^\s*\[(HTTP|JSON LOG)\]/.test(l); }
function applyDet(){
  const c = document.getElementById('console');
  if (c) c.classList.toggle('showdet', showDet);
  const b = document.getElementById('detToggle');
  if (b) b.textContent = showDet ? 'Chi tiết: Hiện' : 'Chi tiết: Ẩn';
}
function toggleDetail(){ showDet = !showDet; localStorage.setItem('fp_showdet', showDet?'1':'0'); applyDet(); }
function log(lines, forceAll){
  const c = document.getElementById('console');
  (lines||[]).forEach(l => {
    const d = document.createElement('div');
    const det = !forceAll && isDetailLine(l);
    d.className = 'logline' + (det?' det':'') + (/\bOK\b|thành công|SĂN THÀNH CÔNG/i.test(l)?' ok':( /lỗi|thất bại|\[!\]/i.test(l)?' err':''));
    d.textContent = l;
    c.appendChild(d);
  });
  if (!logScrollFrame) logScrollFrame = requestAnimationFrame(() => { logScrollFrame = 0; c.scrollTop = c.scrollHeight; });
}

// ===== Nút "Áp Dụng Proxy" đầu mỗi mục (lưu localStorage) =====
const PX_IDS = ['px_auto','px_khoga','px_muathe'];
function pxInit(){
  PX_IDS.forEach(id => {
    const saved = localStorage.getItem('fp_' + id);
    const def = (id === 'px_muathe') ? '0' : '1';   // khớp cờ mặc định server (mt_* mặc định ×)
    document.getElementById(id).checked = (saved === null) ? def === '1' : saved === '1';
    pxChanged(id, false);
  });
}
function pxChanged(id, save){
  if (save === undefined) save = true;
  const on = document.getElementById(id).checked;
  const st = document.getElementById(id + '_st');
  st.textContent = on ? 'Đang bật' : 'Đang tắt';
  st.className = 'pstate' + (on ? ' on' : '');
  if (save) localStorage.setItem('fp_' + id, on ? '1' : '0');
}
// Gắn cờ proxy của mục hiện tại vào request (server chỉ áp cho request đó)
function proxyOverrideFor(action){
  if (['ping','proxy_get','proxy_save','acc_list','acc_save','acc_delete','runlog_list','runlog_clear','mt_session'].indexOf(action) >= 0) return null;
  if (action.indexOf('mt_') === 0) return {scope:'mt', on: document.getElementById('px_muathe').checked};
  const tab = document.querySelector('nav button.active');
  const t = tab ? tab.dataset.tab : 'auto';
  const id = (t === 'khoga') ? 'px_khoga' : 'px_auto';
  return {scope:'fp', on: document.getElementById(id).checked};
}

// Echo dòng gửi đi giống chạy .py/termux (che mật khẩu/token; chỉ hiện ở console, không lưu lịch sử)
function maskParams(o){
  const m = {};
  for (const k in o){
    if (k.startsWith('_')) continue;
    const val = o[k];
    m[k] = /pass|pwd|auth|token/i.test(k) && typeof val === 'string' && val ? '***' :
           (val && typeof val === 'object' ? maskParams(val) : val);
  }
  return m;
}
// Đọc stream NDJSON từ server: mỗi frame {"type":"log"} hiện NGAY lên console
// → người dùng thấy quy trình code đang làm trực tiếp, không phải chờ chạy xong.
async function readStream(r, showConsole = true, otpWatch = null){
  const reader = r.body.getReader();
  const dec = new TextDecoder();
  let buf = '', j = null;
  for(;;){
    const rd = await reader.read();
    if (rd.done) break;
    buf += dec.decode(rd.value, {stream:true});
    let p;
    while ((p = buf.indexOf('\n')) >= 0){
      const s = buf.slice(0, p).trim(); buf = buf.slice(p + 1);
      if (!s) continue;
      let f; try{ f = JSON.parse(s); }catch(e){ continue; }
      if (f.type === 'log' && typeof f.line === 'string') {
        if (otpWatch && /Đã gửi OTP tới|Chờ OTP từ/i.test(f.line)) startOtpWatch(otpWatch.action, otpWatch.data, otpWatch.fromMimi, otpWatch.showConsole);
        if (showConsole) log([f.line]);
      } else if (f.type === 'done') j = f.result;
    }
  }
  return j;
}
const ACC_REFRESH_ACTIONS = ['fp_create_funpass','fp_create_ldplayer','flow_khoga_full','flow_funpass_once'];
const HISTORY_SKIP_ACTIONS = ['ping','proxy_get','proxy_add','mt_session','acc_list','acc_save','acc_delete','runlog_list','runlog_clear','proxy_save','proxy_reset_state','create_empty_file','module_upload','module_delete','modules_list','modules_remove','ai_context','ai_chat'];
async function call(action, data, options){
  const fromMimi = !!(options && options.fromMimi);
  if (window.fpRiskBlockedUntil && Date.now() < window.fpRiskBlockedUntil) {
    const blocked = {ok:false, risk_blocked:true, error:'Máy chủ đang tạm dừng thao tác sau mã 90001. Không tự thử lại.'};
    if (!fromMimi) log(['[SECURITY] ' + blocked.error]);
    return blocked;
  }
  const showConsole = !fromMimi || !!(options && options.showConsole);
  if (busy){ if (showConsole) log(['[UI] Đang bận, chờ tác vụ trước xong...']); return null; }
  busy = true;
  const label = labelOf(action);
  setStatus(true, 'Đang chạy: ' + label + '...');
  if (showConsole) showLoading(label);
  try{
    const body = Object.assign({}, data || {});
    const ov = proxyOverrideFor(action);
    if (ov) body._proxy_override = ov;
    body._label = label;
    const sent = maskParams(body);
    if (showConsole) log(['[>] ' + action + (Object.keys(sent).length ? ' — ' + JSON.stringify(sent) : '')]);
    const streamQuery = showConsole ? '&stream=1' : '';
    const r = await fetch(location.pathname + '?action=' + action + streamQuery, {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':streamQuery ? 'application/x-ndjson, application/json' : 'application/json'},
      credentials:'same-origin',
      cache:'no-store',
      body: JSON.stringify(body)
    });
    const ct = (r.headers.get('content-type')||'').toLowerCase();
    let j = null;
    if (ct.includes('x-ndjson') && r.body && r.body.getReader){
      j = await readStream(r, showConsole, {action, data:body, fromMimi, showConsole}); // bắt mốc gửi OTP từ log stream
    } else if (ct.includes('application/json')){
      j = await r.json();                 // server không stream được → hiện log 1 lần như cũ
      if (showConsole) log(j.logs||[]);
    } else {
      await r.text().catch(()=> '');
      apiDown('HTTP ' + r.status + ' — server không trả JSON');
      if (showConsole) log(['[UI] Server không trả JSON (HTTP ' + r.status + '). File PHP có đang được thực thi không?']);
      setStatus(false, 'API không phản hồi JSON');
      return null;
    }
    if (!j){ stopOtpWatch(); if (showConsole) log(['[UI] Không nhận được kết quả từ server']); setStatus(false,'Lỗi kết quả'); return null; }
    if (j.otp_required){
      showOtpBox(j, action, body, fromMimi, showConsole);
      setStatus(false, 'Chờ nhập OTP');
      if (showConsole) log(['[UI] Hãy nhập OTP trong ô phía trên Console rồi bấm Xác nhận.']);
    } else if (j.need_captcha){
      const which = action === 'flow_funpass_once' ? 'auto' : (action === 'flow_khoga_full' ? 'khoga' : 'manual');
      showCaptcha(which, j, {action, data:body, fromMimi, showConsole});
      setStatus(false, 'Chờ nhập captcha');
    } else if (otpPending && otpPending.action === action){
      hideOtpBox();
    }
    if (showConsole && j.msg) log(['[API] ' + j.msg]);
    if (showConsole && j.need_captcha){ log(['[UI] ⚠ Cần nhập captcha thủ công']); }
    else if (showConsole && !j.ok && j.error) log(['[UI] Lỗi: ' + j.error]);
    if (!j.otp_required && !j.need_captcha) { stopOtpWatch(); setStatus(false, j.ok ? 'Xong: ' + label : 'Lỗi: ' + (j.error||'cần captcha')); }
    renderLastData(j, label, action);
    if (action === 'mt_skus') renderMuaTheResult(j, action, 'mtSkuMini');
    else if (['mt_available_skus','mt_gift_list','mt_gift_detail','mt_create_order','mt_buy_auto','mt_snipe'].includes(action)) renderMuaTheResult(j, action, 'mtResult');
    if (!HISTORY_SKIP_ACTIONS.includes(action)) {
      historyDirty = true;
      const histList = document.getElementById('histList');
      if (histList && histList.style.display !== 'none') refreshHistory();
    }   // chỉ tải lại khi lịch sử đang mở
    // Server đã tự nhận dạng + lưu tài khoản thành công → làm mới mục Tài Khoản
    if (showConsole && j.ok) {
      if (fromMimi) log(['[Mimi] Đã xong: ' + label + (window.aiHelpUserRequest ? ' — yêu cầu: ' + window.aiHelpUserRequest : '')]);
      else log(['[Xong] Đã xong: ' + label]);
    }
    if (j.ok && ACC_REFRESH_ACTIONS.indexOf(action) >= 0) loadAccounts(true);
    if (j.ok && action === 'proxy_add' && typeof loadProxy === 'function') loadProxy();
    return j;
  }catch(e){
    const em = e && e.message ? e.message : 'Request lỗi';
    const risk = /90001|security risk|tạm dừng thao tác/i.test(em);
    if (risk) window.fpRiskBlockedUntil = Date.now() + 10 * 60 * 1000;
    apiDown(em); if (showConsole) log([risk ? '[SECURITY] ' + em : '[UI] Request lỗi: ' + em]); setStatus(false, risk ? 'Tạm dừng an toàn' : 'Request lỗi');
    return {ok:false, risk_blocked:risk, error:em};
  }
  finally{ stopOtpWatch(); busy = false; if (showConsole) hideLoading(); }
}
const escapeHtml = s => s.replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

// Gọi API nền (không chiếm cờ busy, không hiện loading) — dedupe request cùng action
const quietInflight = new Map();
function callQuiet(action){
  if (quietInflight.has(action)) return quietInflight.get(action);
  const p = (async () => {
    try{
      const r = await fetch(location.pathname + '?action=' + encodeURIComponent(action), {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      const ct = (r.headers.get('content-type')||'').toLowerCase();
      if (!ct.includes('application/json')){ apiDown('HTTP ' + r.status); return null; }
      return await r.json();
    }catch(e){ apiDown(e.message); return null; }
  })();
  quietInflight.set(action, p);
  p.finally(() => { if (quietInflight.get(action) === p) quietInflight.delete(action); }).catch(() => {});
  return p;
}

// ===== Bảng "Tài Khoản / Dữ Liệu Mới Nhất" (dưới Console, trên Lịch Sử Log) =====
let lastAccounts = {};   // {funpass: {...}, ldplayer: {...}} — phục vụ "⤵ Điền temp token vừa tạo"
// Chuẩn hóa về ĐÚNG MẪU: {uid, token, androidid, username, password, temp_token, serverTimeOffset}
// username = mail dùng đăng ký, password = mật khẩu vừa đặt — tự nhận dạng từ response tạo/đăng nhập
function normAccount(a){
  const devHdr = (typeof a.device_id_header === 'string') ? a.device_id_header.split(',')[0] : '';
  return {
    uid: a.uid ?? a.userId ?? '',
    token: a.token ?? '',
    androidid: a.androidid ?? a.androidId ?? a.android_id ?? devHdr ?? '',
    username: a.username ?? a.userName ?? a.email ?? '',
    password: a.password ?? '',
    temp_token: a.temp_token ?? a.tempToken ?? a.shortToken ?? a.short_token ?? '',
    serverTimeOffset: a.serverTimeOffset ?? 0,
    created_at: a.created_at ?? a.saved ?? ''
  };
}
// Tìm các block account (có uid/token) trong response; trả về mảng {title, acc}
// Quét cả: data.funpass / data.ldplayer / data / data.data / data.raw(.data) / session / top-level
// → response dạng account LUÔN được hiển thị, kể cả khi action báo lỗi (ok=false).
// CHỈ nhận response đúng MẪU tài khoản khi code === 200 (j.ok = true).
// Response dạng khác (lỗi OTP, captcha, SKU, số dư, lỗi đăng nhập...) → KHÔNG hiển thị.
function findAccounts(j, action){
  const found = [];
  if (!j || !j.ok) return found;   // code !== 200 / lỗi → không nhận, không hiển thị
  const hasTok = a => a && typeof a === 'object' && (a.uid || a.userId) && a.token;
  const push = (title, a) => { if (hasTok(a)) found.push({title, acc: normAccount(a)}); };
  const d = (j.data && typeof j.data === 'object') ? j.data : null;
  // Ưu tiên phân loại theo ACTION (tránh LDPlayer nhảy sang Funpass vì có temp_token)
  const isLdAct = ['fp_create_ldplayer','fp_login_ldplayer','mt_login'].indexOf(action) >= 0;
  const isFpAct = ['fp_create_funpass','fp_login_funpass'].indexOf(action) >= 0;
  if (d){
    if (d.funpass) push('Funpass', d.funpass);
    if (d.ldplayer) push('LDPlayer', d.ldplayer);
    if (!found.length && d.ok !== false) {
      if (isLdAct) push('LDPlayer', d);
      else if (isFpAct) push('Funpass', d);
      else push('', d);
    }
    if (!found.length && d.data && typeof d.data === 'object') {
      if (isLdAct) push('LDPlayer', d.data);
      else if (isFpAct) push('Funpass', d.data);
      else push('', d.data);
    }
  }
  // Phiên mua thẻ hiện tại (server gắn kèm mọi response mt_* thành công)
  if (!found.length && j.session && typeof j.session === 'object') push('Phiên Mua Thẻ', j.session);
  return found;
}
// Client KHÔNG tự lưu nữa khi server đã acc_autodetect_save — tránh lưu trùng / sai loại.
// Chỉ dùng nút "Lưu Tài Khoản" thủ công trên bảng.
function panelAutoSave(accs, action){
  /* disabled: server đã tự lưu đúng type; client auto-save gây trùng Funpass/LDPlayer */
  return;
}
function renderLastData(j, label, action){
  const ld = document.getElementById('lastData');
  const from = document.getElementById('stateFrom');
  if (!ld) return;
  const accs = findAccounts(j, action);
  const d = (j.data && typeof j.data === 'object') ? j.data : {};
  const sum = Array.isArray(d.panel_summary) ? d.panel_summary : [];
  if (!accs.length && !sum.length) return;   // không khớp MẪU → giữ nguyên bảng, KHÔNG hiển thị response dạng khác
  if (from) from.textContent = '— từ: ' + label;
  // 2 bảng riêng Funpass / Ldplayer — phân loại theo title + action (KHÔNG dựa temp_token)
  let fpAcc = null, ldAcc = null;
  const isLdAct = ['fp_create_ldplayer','fp_login_ldplayer','mt_login'].indexOf(action) >= 0;
  const isFpAct = ['fp_create_funpass','fp_login_funpass'].indexOf(action) >= 0;
  accs.forEach(b => {
    if (b.title === 'Funpass') fpAcc = b.acc;
    else if (b.title === 'LDPlayer' || b.title === 'Phiên Mua Thẻ') ldAcc = b.acc;
    else if (isLdAct) ldAcc = b.acc;
    else if (isFpAct) fpAcc = b.acc;
    // Không đoán lung tung — tránh Funpass/LDPlayer nhảy chéo panel
  });
  if (d.funpass) fpAcc = normAccount(d.funpass);
  if (d.ldplayer) ldAcc = normAccount(d.ldplayer);
  // Action tạo/login đơn lẻ: data chính đúng loại
  if (isLdAct && !ldAcc && d.uid && d.token) ldAcc = normAccount(d);
  if (isFpAct && !fpAcc && d.uid && d.token) fpAcc = normAccount(d);
  // Flow full: đã lấy từ d.funpass / d.ldplayer
  if (action === 'flow_khoga_full' || action === 'flow_funpass_once') {
    if (d.funpass) fpAcc = normAccount(d.funpass);
    if (d.ldplayer) ldAcc = normAccount(d.ldplayer);
  }
  const elFp = document.getElementById('panelFp');
  const elLd = document.getElementById('panelLd');
  if (elFp || elLd) {
    // Chỉ cập nhật panel tương ứng — không xóa panel còn lại
    if (elFp && fpAcc) elFp.textContent = JSON.stringify(fpAcc, null, 2) + (sum.length && !ldAcc ? '\n' + sum.join('\n') : '');
    if (elLd && ldAcc) elLd.textContent = JSON.stringify(ldAcc, null, 2) + (sum.length && !fpAcc ? '\n' + sum.join('\n') : '');
    const btnSave = document.getElementById('btnSavePanel');
    if (btnSave) btnSave.style.display = (fpAcc || ldAcc || (elFp && elFp.textContent.indexOf('"uid"')>=0)) ? '' : 'none';
  } else {
    ld.innerHTML = accs.map((b, idx) => {
      const title = (accs.length > 1 && b.title) ? '<div class="acctitle">▼ ' + escapeHtml(b.title) + '</div>' : '';
      const body = JSON.stringify(b.acc, null, 2) + ((idx === 0 && sum.length) ? '\n' + sum.join('\n') : '');
      return title + '<pre class="json">' + escapeHtml(body) + '</pre>';
    }).join('')
    + (!accs.length && sum.length ? '<pre class="json">' + escapeHtml(sum.join('\n')) + '</pre>' : '');
  }
  // Server đã tự lưu — client không auto-save nữa (tránh trùng)
  // Ghi nhớ lastAccounts theo đúng loại
  if (d.funpass) lastAccounts.funpass = d.funpass;
  else if (isFpAct && fpAcc) lastAccounts.funpass = Object.assign({email: fpAcc.username}, fpAcc);
  else if (!isLdAct && (d.temp_token || d.tempToken) && d.uid) lastAccounts.funpass = d;
  if (d.ldplayer) lastAccounts.ldplayer = d.ldplayer;
  if (fpAcc && isFpAct) lastAccounts.funpass = Object.assign({}, lastAccounts.funpass || {}, fpAcc);
  if (ldAcc) lastAccounts.ldplayer = Object.assign({}, lastAccounts.ldplayer || {}, ldAcc);
}
// Copy toàn bộ nội dung bảng Thông Tin Tài Khoản
function copyPanelData(){
  const elFp = document.getElementById('panelFp');
  const elLd = document.getElementById('panelLd');
  let txt = '';
  if (elFp) txt += 'Tài Khoản Funpass:\n' + elFp.textContent + '\n\n';
  if (elLd) txt += 'Tài Khoản Ldplayer:\n' + elLd.textContent;
  if (!txt) {
    const ld = document.getElementById('lastData');
    if (ld) txt = ld.innerText || ld.textContent || '';
  }
  if (!txt.trim()) { log(['[UI] Bảng trống, không có gì để copy']); return; }
  copyText(txt, document.getElementById('btnCopyPanel'));
}
// Lưu tài khoản đang hiển thị trên bảng xuống mục Tài Khoản
async function savePanelAccounts(){
  const jobs = [];
  const pushSave = (acc, type) => {
    if (!acc || !acc.username || !acc.uid || !acc.token) return;
    if (String(acc.uid).trim() === '' || String(acc.token).trim() === '') return;
    jobs.push(fetch(location.pathname + '?action=acc_save', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({email:acc.username, password:acc.password||'', type:type,
        uid:acc.uid, token:acc.token, androidid:acc.androidid||'', temp_token:acc.temp_token||'',
        serverTimeOffset:acc.serverTimeOffset||0})
    }).then(r => r.json()).catch(() => null));
  };
  try {
    const elFp = document.getElementById('panelFp');
    const elLd = document.getElementById('panelLd');
    // Chỉ lưu panel có dữ liệu thật (uid không rỗng)
    if (elFp) {
      const raw = elFp.textContent.replace(/\n[^\{\[].*$/s,'');
      const a = JSON.parse(raw);
      if (a && a.uid) pushSave(a, 'funpass');
    }
    if (elLd) {
      const raw = elLd.textContent.replace(/\n[^\{\[].*$/s,'');
      const a = JSON.parse(raw);
      if (a && a.uid) pushSave(a, 'ldplayer');
    }
  } catch(e) { log(['[UI] Parse panel lỗi: ' + e.message]); }
  if (!jobs.length) { log(['[UI] Không có tài khoản hợp lệ để lưu']); return; }
  const rs = await Promise.all(jobs);
  const n = rs.filter(x => x && x.ok).length;
  log(['[UI] Đã lưu ' + n + ' tài khoản từ bảng → mục 👤 Tài Khoản (đúng loại Funpass/LDPlayer)']);
  loadAccounts(true);
}

function hideCaptchaBox(){
  const shared = document.getElementById('capSharedBox');
  if (shared) shared.style.display = 'none';
  document.querySelectorAll('.capbox:not(#capSharedBox)').forEach(el => { el.style.display = 'none'; });
  sharedCaptchaPending = null;
}
function showCaptcha(which, j, pendingMeta = null){
  hideOtpBox();
  hideCaptchaBox();
  capCtx[which] = {captcha_id:j.captcha_id, ctx:j.ctx};
  sharedCaptchaPending = {which, captcha_id:j.captcha_id || '', ctx:j.ctx || {}, action:pendingMeta?.action || j.action || '', data:pendingMeta?.data || {}, fromMimi:!!pendingMeta?.fromMimi, showConsole:pendingMeta?.showConsole !== false};
  const box = document.getElementById('capSharedBox');
  const img = document.getElementById('capSharedImg');
  const idEl = document.getElementById('capSharedId');
  const code = document.getElementById('capSharedCode');
  const hint = document.getElementById('capSharedHint');
  if (box) box.style.display = 'block';
  if (img) img.src = 'data:image/png;base64,' + (j.captcha_image || '');
  if (idEl) idEl.textContent = j.captcha_id || '';
  if (code) { code.value = ''; code.setCustomValidity(''); }
  const submit = document.getElementById('capSharedSubmitBtn');
  if (submit) { submit.disabled = false; submit.title = ''; }
  if (hint) hint.textContent = 'OTP đã xong. Xem ảnh captcha rồi nhập mã để tiếp tục.';
}
async function submitCaptcha(){
  if (!sharedCaptchaPending) return;
  const code = (document.getElementById('capSharedCode')?.value || '').trim();
  if (!code){
    const hint = document.getElementById('capSharedHint');
    if (hint) hint.textContent = 'Hãy nhập mã captcha trước khi xác nhận.';
    return;
  }
  const pending = sharedCaptchaPending;
  const d = Object.assign({}, pending.data || {});
  d.captcha = {captcha_id: pending.captcha_id, captcha_data: code};
  d.ctx = pending.ctx;
  const btn = document.getElementById('capSharedSubmitBtn');
  if (btn) btn.disabled = true;
  hideCaptchaBox();
  const j = await call(pending.action, d, {fromMimi:pending.fromMimi, showConsole:pending.showConsole});
  if (j && j.need_captcha){
    showCaptcha(pending.which, Object.assign({}, j, {action:pending.action}), pending);
  } else if (btn) btn.disabled = false;
}

// ===== Công cụ lẻ: tạo LDPlayer bằng email+tempToken / CPI thủ công — có hỗ trợ captcha tay (cap_manual) =====
let manualPending = null;   // {action, data} — khi server trả need_captcha cho 1 action lẻ
async function manualAction(action, data){
  // Luồng này chỉ dành cho nút người dùng tự bấm; không phải action của Mimi.
  const j = await call(action, data || {}, {fromMimi:false});
  if (!j) return null;
  if (j.otp_required) return j;
  if (j.need_captcha){
    manualPending = {action, data: data || {}};
    return j; // call() đã mở panel captcha chung và lưu context resume
  }
  hideCaptchaBox();
  manualPending = null;
  return j;
}
async function manualCaptchaRetry(){ return submitCaptcha(); }
// Tạo LDPlayer dạng nhập email + temp token (create_ldplayer_account(email, temp_token) trong .py)
async function createLdManual(){
  // Dùng chung 1 bảng: Gmail + Temp Token
  let email = v('m_email') || v('m_ld_email');
  const tt = v('m_temp_token');
  if (!email || !tt){ log(['[UI] Nhập đủ Gmail + Temp Token (hoặc bấm "⤵ Điền từ Funpass vừa tạo").']); return; }
  const hidden = document.getElementById('m_ld_email');
  if (hidden) hidden.value = email;
  const androidId = v('m_android_id') || (lastAccounts.funpass && (lastAccounts.funpass.android_id || lastAccounts.funpass.androidid)) || '';
  await manualAction('fp_create_ldplayer', {email, temp_token: tt, android_id: androidId});
}
// Điền lại email + temp_token của account Funpass vừa tạo xong
function fillTempToken(){
  const a = lastAccounts.funpass;
  if (!a || !(a.temp_token || a.tempToken)){ log(['[UI] Chưa có tài khoản Funpass nào vừa tạo (chưa có temp token).']); return; }
  const email = a.email || a.username || '';
  if (document.getElementById('m_email')) document.getElementById('m_email').value = email;
  if (document.getElementById('m_ld_email')) document.getElementById('m_ld_email').value = email;
  document.getElementById('m_temp_token').value = a.temp_token || a.tempToken || '';
  if (a.password && document.getElementById('m_pass')) document.getElementById('m_pass').value = a.password;
  if (a.android_id || a.androidid) document.getElementById('m_android_id').value = a.android_id || a.androidid;
  log(['[UI] Đã điền Gmail + Temp Token của ' + email + ' vào form.']);
}
// CPI thủ công: chọn tài khoản Funpass đã lưu (nút + cạnh ô Email) → đăng nhập → xem ads / chạy CPI
async function cpiManual(onlyList){
  const email = v('m_email'), pass = v('m_pass');
  if (!email || !pass){ log(['[UI] Chọn/nhập tài khoản Funpass ở ô Email + Mật khẩu phía trên trước.']); return; }
  const lj = await manualAction('fp_login_funpass', {email, password: pass});
  if (!lj || !lj.ok || !lj.data){ log(['[UI] Đăng nhập Funpass thất bại → không chạy CPI.']); return; }
  const acc = lj.data;   // {uid, token, email, password, device_id_header, android_id}
  if (onlyList){
    await manualAction('fp_cpi_ads', {account: acc});
  } else {
    const rj = await manualAction('fp_cpi_run', {account: acc});
    if (rj && rj.ok) await manualAction('fp_wallet', {uid: acc.uid, token: acc.token});
  }
}

async function funpassOnce(withCaptcha){
  if (!withCaptcha) proxyIdx = Math.max(0, parseInt(v('a_proxy_idx') || '0', 10) || 0);
  const data = {proxy_index: proxyIdx, ld_email: v('a_ld_email'), ld_password: v('a_ld_pass'), android_id: v('a_android_id')};
  if (withCaptcha && capCtx.auto){
    data.captcha = {captcha_id: capCtx.auto.captcha_id, captcha_data: v('cap_auto_code')};
    data.ctx = capCtx.auto.ctx;
  }
  const j = await call('flow_funpass_once', data);
  if (!j) return;
  if (j.otp_required) return;
  if (j.need_captcha){ return; } // call() đã mở panel captcha chung

  document.getElementById('cap_auto').style.display='none'; capCtx.auto=null;
  if (j.ok && j.data && j.data.next_proxy_index !== undefined) proxyIdx = j.data.next_proxy_index;
  else proxyIdx++;
  document.getElementById('a_proxy_idx').value = proxyIdx;
  // Sau khi Funpass + nhiệm vụ xong → hiện bảng Đăng Nhập / Đăng Ký LDPlayer
  if (j.ok && j.data && (j.data.funpass || lastAccounts.funpass)) {
    showLdStepCard();
  }
  if (document.getElementById('a_loop').checked) loopTimer = setTimeout(()=>funpassOnce(false), 2500);
}
// ===== Bảng Đăng Nhập / Đăng Ký LDPlayer (Funpass Auto) =====
function showLdStepCard(){
  const card = document.getElementById('ldStepCard');
  if (card) card.style.display = 'block';
  exitLdForm();
  // Prefill reg form từ Funpass vừa tạo
  const a = lastAccounts.funpass;
  if (a) {
    const em = document.getElementById('ldf_reg_email');
    if (em && !em.value) em.value = a.email || a.username || '';
    const aid = a.android_id || a.androidid || '';
    ['a_android_id','ldf_login_android_id','ldf_reg_android_id'].forEach(id => { const el = document.getElementById(id); if (el && !el.value && aid) el.value = aid; });
  }
}
function showLdForm(which){
  document.getElementById('ldBtnRow').style.display = 'none';
  document.getElementById('ldLoginForm').style.display = (which === 'login') ? 'block' : 'none';
  document.getElementById('ldRegForm').style.display = (which === 'reg') ? 'block' : 'none';
  if (which === 'reg') {
    const a = lastAccounts.funpass;
    const em = document.getElementById('ldf_reg_email');
    const pw = document.getElementById('ldf_reg_pass');
    if (a && em && !em.value) em.value = a.email || a.username || '';
    if (a) { const aid = a.android_id || a.androidid || ''; const ai = document.getElementById('ldf_reg_android_id'); if (ai && !ai.value && aid) ai.value = aid; }
    if (pw && !pw.value) pw.value = ''; // để trống → server/JS random
  }
}
function exitLdForm(){
  document.getElementById('ldBtnRow').style.display = '';
  document.getElementById('ldLoginForm').style.display = 'none';
  document.getElementById('ldRegForm').style.display = 'none';
}
async function doLdLogin(){
  const email = v('ldf_login_email'), pass = v('ldf_login_pass');
  if (!email || !pass){ log(['[UI] Nhập Gmail + Password để đăng nhập LDPlayer']); return; }
  const androidId = v('ldf_login_android_id') || v('a_android_id') || (lastAccounts.ldplayer && (lastAccounts.ldplayer.android_id || lastAccounts.ldplayer.androidid)) || '';
  const j = await call('fp_login_ldplayer', {email, password: pass, android_id: androidId});
  if (j && j.ok) {
    log(['[UI] Đăng nhập LDPlayer thành công — có thể chuyển điểm nếu đã có Funpass']);
    // Tự điền vào ô LD cố định
    document.getElementById('a_ld_email').value = email;
    document.getElementById('a_ld_pass').value = pass;
  }
}
async function doLdRegister(){
  let email = v('ldf_reg_email'), pass = v('ldf_reg_pass');
  const a = lastAccounts.funpass;
  // Nếu trống → lấy từ Funpass + random password + temp_token
  if (!email && a) email = a.email || a.username || '';
  if (!pass) {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    pass = '';
    for (let i=0;i<10;i++) pass += chars[Math.floor(Math.random()*chars.length)];
    document.getElementById('ldf_reg_pass').value = pass;
  }
  const tempToken = (a && (a.temp_token || a.tempToken)) || '';
  if (!email){ log(['[UI] Không có Gmail (cần tạo Funpass trước hoặc nhập tay)']); return; }
  if (!tempToken){ log(['[UI] Không có temp_token từ Funpass — đăng ký LDPlayer cần temp_token. Hãy chạy tạo Funpass trước.']); return; }
  log(['[UI] Đăng ký LDPlayer: ' + email + ' (temp_token từ Funpass)']);
  const androidId = v('ldf_reg_android_id') || v('a_android_id') || (a && (a.android_id || a.androidid)) || '';
  const j = await call('fp_create_ldplayer', {email, temp_token: tempToken, password: pass, android_id: androidId});
  if (j && j.ok) {
    log(['[UI] Đăng ký LDPlayer thành công']);
    document.getElementById('a_ld_email').value = email;
    document.getElementById('a_ld_pass').value = pass;
  }
}

async function khogaFull(withCaptcha){
  const data = {ld_mode: v('k_mode'), ld_email: v('k_ld_email'), ld_password: v('k_ld_pass'), android_id: v('k_android_id'),
                confirm_transfer: document.getElementById('k_confirm').checked};
  if (withCaptcha && capCtx.khoga){
    data.captcha = {captcha_id: capCtx.khoga.captcha_id, captcha_data: v('cap_khoga_code')};
    data.ctx = capCtx.khoga.ctx;
  }
  const j = await call('flow_khoga_full', data);
  if (!j) return;
  if (j.otp_required) return;
  if (j.need_captcha){ return; } // call() đã mở panel captcha chung

  document.getElementById('cap_khoga').style.display='none'; capCtx.khoga=null;
  if (j.ok && j.data){
    if (j.data.funpass){ document.getElementById('t_fpuid').value=j.data.funpass.uid; document.getElementById('t_fptoken').value=j.data.funpass.token; }
    if (j.data.ldplayer){ document.getElementById('t_lduid').value=j.data.ldplayer.uid; document.getElementById('t_ldtoken').value=j.data.ldplayer.token; }
  }
}

function mtText(value, fallback = '') { return value === undefined || value === null || value === '' ? fallback : String(value); }
function mtSkuList(value, out = [], seen = new Set()) {
  if (!value || typeof value !== 'object' || out.length >= 80) return out;
  if (Array.isArray(value)) { value.forEach(x => mtSkuList(x, out, seen)); return out; }
  const id = value.skuId ?? value.sku_id ?? value.id;
  const name = value.displayName ?? value.itemName ?? value.name ?? value.goodsName;
  const score = value.score ?? value.originalScore ?? value.price;
  if (id !== undefined && (name !== undefined || score !== undefined) && !seen.has(String(id))) {
    seen.add(String(id)); out.push({skuId:id, name:name ?? ('SKU ' + id), score:score ?? '', stockPct:value.stockPct ?? value.stock ?? ''});
  }
  Object.values(value).forEach(x => { if (x && typeof x === 'object') mtSkuList(x, out, seen); });
  return out;
}
function mtCardList(value, out = [], seen = new Set()) {
  if (!value || typeof value !== 'object' || out.length >= 20) return out;
  if (Array.isArray(value)) { value.forEach(x => mtCardList(x, out, seen)); return out; }
  const cfg = value.config && typeof value.config === 'object' ? value.config : value;
  const number = cfg.cardNumber ?? cfg.card_no ?? cfg.card;
  const password = cfg.password ?? cfg.passwd ?? cfg.cardPassword;
  if (number || password) {
    const key = String(number || '') + '|' + String(password || '');
    if (!seen.has(key)) { seen.add(key); out.push({number:number || '', password:password || '', expire:value.expireDate || (value.expireTimestamp ? new Date(Number(value.expireTimestamp) * 1000).toLocaleString('vi-VN') : '')}); }
  }
  Object.values(value).forEach(x => { if (x && typeof x === 'object') mtCardList(x, out, seen); });
  return out;
}
function mtOrderInfo(value, out = [], seen = new Set()) {
  if (!value || typeof value !== 'object' || out.length >= 10) return out;
  if (Array.isArray(value)) { value.forEach(x => mtOrderInfo(x, out, seen)); return out; }
  if (value.orderNo || value.order_no || value.goodsName || value.actualPayAmount !== undefined) {
    const row = {orderNo:value.orderNo || value.order_no || '', goods:value.goodsName || value.name || '', paid:value.actualPayAmount ?? value.payAmount ?? ''};
    const key = String(row.orderNo || '') + '|' + String(row.goods || '') + '|' + String(row.paid || '');
    if ((row.orderNo || row.goods || row.paid !== '') && !seen.has(key)) { seen.add(key); out.push(row); }
  }
  Object.values(value).forEach(x => { if (x && typeof x === 'object') mtOrderInfo(x, out, seen); });
  return out;
}
function mtSetSku(skuId) { const el = document.getElementById('mt_sku'); if (el) { el.value = skuId; el.scrollIntoView({behavior:'smooth', block:'center'}); el.focus({preventScroll:true}); } }
function renderMuaTheResult(j, action, targetId = 'mtResult') {
  const box = document.getElementById(targetId); if (!box) return;
  if (!j) return;
  if (!j.ok) { box.innerHTML = '<div class="mt-result-title">Mua Thẻ chưa thực hiện được</div><div class="ai-error">' + escapeHtml(mtText(j.error, 'Server chưa trả kết quả hợp lệ.')) + '</div>'; return; }
  let html = '', rows = [], cards = [], orders = [];
  if (action === 'mt_balance') {
    html = '<div class="mt-result-title">💰 Số dư hiện tại</div><div style="font-size:22px;font-weight:800;color:#ffc94d">' + escapeHtml(mtText(j.balance, 'Chưa đọc được số dư')) + ' điểm</div>';
  } else if (action === 'mt_skus' || action === 'mt_available_skus') {
    rows = mtSkuList(action === 'mt_skus' ? j.skus : j.data);
    html = '<div class="mt-result-title">📦 Danh sách sản phẩm (' + rows.length + ')</div>';
    html += rows.length ? '<table class="mt-result-table"><thead><tr><th>SKU</th><th>Sản phẩm</th><th>Điểm</th><th>Kho</th><th></th></tr></thead><tbody>' + rows.map(s => '<tr><td>' + escapeHtml(mtText(s.skuId)) + '</td><td>' + escapeHtml(mtText(s.name)) + '</td><td>' + escapeHtml(mtText(s.score,'—')) + '</td><td>' + escapeHtml(mtText(s.stockPct,'—')) + '</td><td><button class="btn ghost" type="button" onclick="mtSetSku(' + JSON.stringify(s.skuId) + ')">Chọn</button></td></tr>').join('') + '</tbody></table>' : '<div class="mt-result-muted">API không trả sản phẩm nào.</div>';
  } else if (action === 'mt_gift_list' || action === 'mt_gift_detail') {
    cards = mtCardList(j.data);
    orders = mtOrderInfo(j.data);
    html = '<div class="mt-result-title">🎁 ' + (action === 'mt_gift_list' ? 'Thẻ đã mua' : 'Chi tiết đơn hàng') + '</div>';
    if (orders.length) html += orders.map(o => '<div class="mt-result-card"><b>Mã đơn:</b> <span class="mt-result-code">' + escapeHtml(mtText(o.orderNo,'—')) + '</span>' + (o.goods ? '<br><b>Sản phẩm:</b> ' + escapeHtml(o.goods) : '') + (o.paid !== '' ? '<br><b>Điểm đã dùng:</b> ' + escapeHtml(String(o.paid)) : '') + '</div>').join('');
    if (cards.length) html += cards.map(c => '<div class="mt-result-card"><b>Mã thẻ:</b> <span class="mt-result-code">' + escapeHtml(String(c.number)) + '</span><br><b>Mật khẩu:</b> <span class="mt-result-code">' + escapeHtml(String(c.password)) + '</span>' + (c.expire ? '<br><b>Hạn:</b> ' + escapeHtml(String(c.expire)) : '') + '</div>').join('');
    if (!orders.length && !cards.length) html += '<div class="mt-result-muted">API chưa trả chi tiết sản phẩm/thẻ cho yêu cầu này.</div>';
  } else if (action === 'mt_create_order' || action === 'mt_buy_auto' || action === 'mt_snipe') {
    const data = j.data || {};
    const orderSource = action === 'mt_buy_auto' ? (data.result || data) : data;
    orders = mtOrderInfo(orderSource);
    cards = mtCardList(orderSource);
    const sku = data.bestSku || {};
    html = '<div class="mt-result-title">' + (action === 'mt_snipe' ? '🎯 Kết quả săn thẻ' : '🛒 Kết quả mua thẻ') + '</div>';
    if (sku.skuId || sku.name) html += '<div class="mt-result-card"><b>Sản phẩm:</b> ' + escapeHtml(mtText(sku.name, 'SKU ' + mtText(sku.skuId))) + '<br><b>SKU:</b> ' + escapeHtml(mtText(sku.skuId,'—')) + (sku.score !== undefined ? '<br><b>Giá:</b> ' + escapeHtml(String(sku.score)) + ' điểm' : '') + '</div>';
    if (orders.length) html += orders.map(o => '<div class="mt-result-card"><b>Mã đơn:</b> <span class="mt-result-code">' + escapeHtml(mtText(o.orderNo,'—')) + '</span>' + (o.paid !== '' ? '<br><b>Điểm đã dùng:</b> ' + escapeHtml(String(o.paid)) : '') + '</div>').join('');
    if (cards.length) html += cards.map(c => '<div class="mt-result-card"><b>Mã thẻ:</b> <span class="mt-result-code">' + escapeHtml(String(c.number)) + '</span><br><b>Mật khẩu:</b> <span class="mt-result-code">' + escapeHtml(String(c.password)) + '</span>' + (c.expire ? '<br><b>Hạn:</b> ' + escapeHtml(String(c.expire)) : '') + '</div>').join('');
    if (data.balance !== undefined) html += '<div class="mt-result-muted" style="margin-top:8px">Số dư còn lại: ' + escapeHtml(String(data.balance)) + ' điểm</div>';
    if (!orders.length && !cards.length && !sku.skuId) html += '<div class="mt-result-muted">Đã nhận phản hồi thành công nhưng API chưa trả mã đơn hoặc thông tin thẻ.</div>';
  } else if (action === 'mt_login') {
    const s = j.data || {}; html = '<div class="mt-result-title">🔐 Phiên Mua Thẻ</div><div class="mt-result-card"><b>Trạng thái:</b> Đã đăng nhập<br><b>UID:</b> ' + escapeHtml(mtText(s.uid,'—')) + '<br><b>Android ID:</b> ' + escapeHtml(mtText(s.androidId || s.androidid,'—')) + '</div>';
  } else if (action === 'mt_sync') {
    html = '<div class="mt-result-title">⏱️ Đã đồng bộ thời gian</div><div class="mt-result-muted">Sai lệch máy chủ: ' + escapeHtml(mtText(j.offset, '0')) + ' ms</div>';
  }
  if (html) box.innerHTML = html;
}
let mtSelectedAccounts = [];
let mtPickerDraft = [];
let mtRunState = {};
const MT_MAX_ACCOUNTS = Infinity;
function mtEmail(a){ return String(a?.email || a?.username || '').trim(); }
function mtKey(a){ return mtEmail(a).toLowerCase(); }
function mtAccountBalance(a){ if(a && a.coin !== undefined && a.coin !== null && a.coin !== '') return a.coin; if(a && a.balance !== undefined && a.balance !== null && a.balance !== '') return a.balance; const s=mtRunState[mtKey(a)]; return s && s.balance !== undefined && s.balance !== null ? s.balance : '—'; }
function mtSelectionStorageKey(){ return 'mimi_mt_selected_' + String(window.mimiUserKey || 'guest'); }
function saveMtSelection(){ try { localStorage.setItem(mtSelectionStorageKey(), JSON.stringify(mtSelectedAccounts.map(mtEmail))); } catch (_) {} }
function mtRunStateStorageKey(){ return mtSelectionStorageKey() + '_status'; }
function saveMtRunState(){ try { const clean = {}; Object.entries(mtRunState).forEach(([k,v]) => { clean[k] = {status:v.status || '', balance:v.balance ?? null}; }); localStorage.setItem(mtRunStateStorageKey(), JSON.stringify(clean)); } catch (_) {} }
function restoreMtRunState(){ try { const raw = JSON.parse(localStorage.getItem(mtRunStateStorageKey()) || '{}'); if (raw && typeof raw === 'object') mtRunState = raw; } catch (_) {} }
function restoreMtSelection(){
  let emails = []; try { emails = JSON.parse(localStorage.getItem(mtSelectionStorageKey()) || '[]'); } catch (_) {}
  if (!Array.isArray(emails)) emails = [];
  restoreMtRunState();
  const map = new Map((typeof ACCOUNTS !== 'undefined' ? ACCOUNTS : []).filter(a => a.type === 'ldplayer' && mtEmail(a)).map(a => [mtKey(a), a]));
  mtSelectedAccounts = emails.map(e => map.get(String(e).toLowerCase())).filter(Boolean).slice(0, MT_MAX_ACCOUNTS);
  renderMtSelectedAccounts();
}
function mtSelected(){ return mtSelectedAccounts.filter(a => a && mtEmail(a)).slice(0, MT_MAX_ACCOUNTS); }
function mtSetRunStatus(account, j, extra = {}){
  const key = mtKey(account); const prev = mtRunState[key] || {};
  const payload = j?.j || j || {};
  const next = Object.assign({}, prev, extra, {status:payload.ok ? 'ok' : 'bad', result:payload});
  if (extra.login) next.loginStatus = payload.ok ? 'ok' : 'bad';
  mtRunState[key] = next;
  const data = payload.data || {};
  const session = payload.session || data.session || {};
  const balance = payload.balance ?? data.balance ?? session.balance;
  if (balance !== undefined && balance !== null && balance !== '') mtRunState[key].balance = balance;
  saveMtRunState(); renderMtSelectedAccounts();
}
function mtStatusMark(account){ const s = mtRunState[mtKey(account)] || {}; const st = s.loginStatus || s.status; return st === 'ok' ? '√' : (st === 'bad' ? '×' : ''); }
function renderMtSelectedAccounts(){
  const box = document.getElementById('mtSelectedAccounts'); const count = document.getElementById('mtSelectCount'); const sess = document.getElementById('mt_sess');
  if (count) count.textContent = mtSelectedAccounts.length ? `${mtSelectedAccounts.length}/${MT_MAX_ACCOUNTS} tài khoản` : '';
  if (sess) sess.textContent = mtSelectedAccounts.length ? `— ${mtSelectedAccounts.length} phiên` : '';
  if (!box) return;
  if (!mtSelectedAccounts.length){ box.innerHTML = ''; return; }
  box.innerHTML = mtSelectedAccounts.map(a => { const st = mtRunState[mtKey(a)]?.status || ''; const cls = st ? ' ' + st : ''; return `<div class="mt-selected-row${cls}"><span class="mt-mark">${mtStatusMark(a)}</span><span class="mt-email" title="Android ID đi theo tài khoản này">${escapeHtml(mtEmail(a))}</span><span class="mt-bal">${escapeHtml(String(mtAccountBalance(a)))} điểm</span></div>`; }).join('');
}
function setMtRunStatus(text, kind = ''){ const el = document.getElementById('mtRunStatus'); if (el){ el.textContent = text; el.className = 'mt-run-status' + (kind ? ' ' + kind : ''); } }
async function mtCallSlot(action, account, data = {}){
  const email = mtEmail(account); if (!email) return {ok:false, error:'Tài khoản thiếu Gmail', account};
  if (window.fpRiskBlockedUntil && Date.now() < window.fpRiskBlockedUntil) return {ok:false, risk_blocked:true, error:'Máy chủ đang tạm dừng thao tác sau mã 90001. Không tự thử lại.', account};
  const body = Object.assign({}, data, {_mt_slot: email, _label: labelOf(action) + ' · ' + email});
  const ov = typeof proxyOverrideFor === 'function' ? proxyOverrideFor(action) : null; if (ov) body._proxy_override = ov;
  try {
    const r = await fetch(location.pathname + '?action=' + encodeURIComponent(action), {method:'POST', headers:{'Content-Type':'application/json', 'Accept':'application/json'}, cache:'no-store', body:JSON.stringify(body)});
    const ct = (r.headers.get('content-type') || '').toLowerCase(); const j = ct.includes('application/json') ? await r.json() : {ok:false,error:'Server không trả JSON (HTTP ' + r.status + ')'};
    const payload = j || {ok:false,error:'Không có phản hồi'};
    const risk = /90001|security risk|tạm dừng thao tác/i.test(String(payload.code || '') + ' ' + String(payload.msg || '') + ' ' + String(payload.error || ''));
    if (risk) window.fpRiskBlockedUntil = Date.now() + 10 * 60 * 1000;
    if (typeof log === 'function') { const lines = Array.isArray(payload.logs) ? payload.logs.slice(-80) : []; if (lines.length) log(lines); if (payload.msg) log(['[API] ' + payload.msg]); if (!payload.ok && payload.error) log([risk ? '[SECURITY] ' + email + ': ' + payload.error : '[UI] Lỗi ' + email + ': ' + payload.error]); else log(['[Mua Thẻ] ' + (labelOf(action) || action) + ' · ' + email + ' · Xong']); }
    return {j:payload, ok:!!payload.ok, risk_blocked:risk, error:payload.error || '', account};
  } catch (e) { return {j:{ok:false,error:e.message || 'Request lỗi'},ok:false,error:e.message || 'Request lỗi',account}; }
}
async function mtEnsureSessions(accounts){
  const settled = await Promise.allSettled(accounts.map(a => mtCallSlot('mt_session', a, {})));
  const needLogin = accounts.filter((a, i) => { const x = settled[i]; return x.status !== 'fulfilled' || !x.value?.j?.data?.uid || !x.value?.j?.data?.token; });
  if (!needLogin.length) return {ok:true,results:[]};
  const loginSettled = await Promise.allSettled(needLogin.map(a => mtCallSlot('mt_login', a, {username:mtEmail(a),password:a.password || '',android_id:a.androidid || a.androidId || a.android_id || ''})));
  const results = loginSettled.map((x, i) => x.status === 'fulfilled' ? x.value : ({ok:false,error:x.reason?.message || 'Đăng nhập lỗi',account:needLogin[i],j:{ok:false,error:x.reason?.message || 'Đăng nhập lỗi'}}));
  results.forEach(x => mtSetRunStatus(x.account, x, {login:true}));
  return {ok:results.every(x => x.ok),results};
}
async function mtMany(action, data = {}, label = 'đang chạy'){
  const accounts = mtSelected(); if (!accounts.length){ setMtRunStatus('Hãy bấm + và chọn ít nhất 1 tài khoản LDPlayer.', 'bad'); return []; }
  const needsSession = !['mt_login','mt_session','mt_logout'].includes(action);
  if (needsSession) {
    setMtRunStatus(`Đang kiểm tra phiên của ${accounts.length} tài khoản...`, 'run');
    const ensured = await mtEnsureSessions(accounts);
    if (!ensured.ok) { setMtRunStatus('Có tài khoản chưa đăng nhập được, kiểm tra dấu × rồi thử lại.', 'bad'); return ensured.results; }
  }
  setMtRunStatus(`Đang ${label} cho ${accounts.length} tài khoản cùng lúc...`, 'run');
  const settled = await Promise.allSettled(accounts.map(a => { const payload = action === 'mt_login' ? Object.assign({username:mtEmail(a),password:a.password || '',android_id:a.androidid || a.androidId || a.android_id || ''}, data || {}) : data; return mtCallSlot(action, a, payload); }));
  const results = settled.map((x, i) => x.status === 'fulfilled' ? x.value : ({ok:false,error:x.reason?.message || 'Request lỗi',account:accounts[i]}));
  results.forEach(x => mtSetRunStatus(x.account, x, {login:action === 'mt_login'}));
  const ok = results.filter(x => x.ok).length; setMtRunStatus(`Đã chạy xong: ${ok}/${results.length} tài khoản thành công.`, ok === results.length ? 'ok' : (ok ? 'run' : 'bad'));
  return results;
}
function mtManyDetail(x){
  const j = x?.j || {}; const d = j.data || {}; const src = d.result || d; const bits = [];
  const sku = d.bestSku || {}; const orderNo = src.orderNo || src.order_no || src._decrypted?.orderNo || '';
  const goods = src.goodsName || src._decrypted?.goodsName || sku.name || '';
  if (goods) bits.push('Sản phẩm: ' + goods); if (sku.skuId) bits.push('SKU: ' + sku.skuId); if (orderNo) bits.push('Mã đơn: ' + orderNo);
  const scan = value => { if (!value || typeof value !== 'object' || bits.some(b => b.startsWith('Mã thẻ:'))) return; if (Array.isArray(value)) return value.forEach(scan); const c = value.config && typeof value.config === 'object' ? value.config : value; if ((c.cardNumber || c.card_no) && (c.password || c.passwd)) { bits.push('Mã thẻ: ' + (c.cardNumber || c.card_no)); bits.push('Mật khẩu: ' + (c.password || c.passwd)); return; } Object.values(value).forEach(scan); };
  scan(src); if (j.balance !== undefined && j.balance !== null) bits.push('Số dư: ' + j.balance + ' điểm'); return bits.join(' · ') || (x.ok ? 'Đã xử lý' : (x.error || 'Thất bại'));
}
async function mtOne(action, data = {}, label = 'đang chạy'){
  const account = mtSelected()[0]; if (!account){ setMtRunStatus('Hãy bấm + và chọn ít nhất 1 tài khoản LDPlayer.', 'bad'); return null; }
  setMtRunStatus(`Đang ${label}...`, 'run');
  const ensured = !['mt_login','mt_session','mt_logout'].includes(action) ? await mtEnsureSessions([account]) : {ok:true};
  if (!ensured.ok) { setMtRunStatus('Tài khoản đại diện chưa đăng nhập được.', 'bad'); return ensured.results?.[0] || null; }
  const x = await mtCallSlot(action, account, data); mtSetRunStatus(account, x, {login:action === 'mt_login'}); setMtRunStatus(x.ok ? `Đã xong: ${label}.` : `Lỗi: ${x.error || label}.`, x.ok ? 'ok' : 'bad'); return x;
}
function mtResultRows(results){ return results.map(x => `<tr><td>${escapeHtml(mtEmail(x.account))}</td><td class="${x.ok ? 'mt-ok' : 'mt-bad'}">${x.ok ? '√ Thành công' : '× ' + escapeHtml(x.error || 'Thất bại')}</td><td>${escapeHtml(mtManyDetail(x))}</td></tr>`).join(''); }
function renderMtManyStatus(results, title){ const box = document.getElementById('mtResult'); if (!box) return; box.innerHTML = `<div class="mt-result-title">${title}</div><table class="mt-result-table"><thead><tr><th>Gmail</th><th>Trạng thái</th><th>Chi tiết</th></tr></thead><tbody>${mtResultRows(results)}</tbody></table>`; }
function mtSkuRows(results){ const map = new Map(); results.forEach(x => { const raw = x.j?.skus !== undefined ? x.j.skus : x.j?.data; mtSkuList(raw || {}).forEach(s => { const key = String(s.skuId) + '|' + String(s.name); if (!map.has(key)) map.set(key, s); }); }); return Array.from(map.values()); }
function mtManyCardRows(results){ const rows = []; results.forEach(x => { const data = x.j?.data; const cards = mtCardList(data); const orders = mtOrderInfo(data); const email = mtEmail(x.account); if (orders.length || cards.length) { orders.forEach(o => rows.push({email,order:o.orderNo || '—',goods:o.goods || '—',card:'—',pass:'—',expire:'—'})); cards.forEach(c => rows.push({email,order:'—',goods:'—',card:c.number || '—',pass:c.password || '—',expire:c.expire || '—'})); } else if (!x.ok) rows.push({email,order:'—',goods:'—',card:'× ' + (x.error || 'Lỗi'),pass:'—',expire:'—'}); }); return rows; }
function renderMtManyCards(results, title){ const box = document.getElementById('mtResult'); if (!box) return; const rows = mtManyCardRows(results); box.innerHTML = `<div class="mt-result-title">${title} (${rows.length})</div>` + (rows.length ? `<table class="mt-result-table"><thead><tr><th>Gmail</th><th>Mã đơn</th><th>Sản phẩm</th><th>Mã thẻ</th><th>Mật khẩu</th><th>Hạn</th></tr></thead><tbody>${rows.map(r => `<tr><td>${escapeHtml(r.email)}</td><td>${escapeHtml(r.order)}</td><td>${escapeHtml(r.goods)}</td><td>${escapeHtml(r.card)}</td><td>${escapeHtml(r.pass)}</td><td>${escapeHtml(r.expire)}</td></tr>`).join('')}</tbody></table>` : '<div class="mt-result-muted">API chưa trả thẻ hoặc chi tiết đơn cho tài khoản đã chọn.</div>'); }
function renderMtSkuBox(results, title, targetId){ const box = document.getElementById(targetId); if (!box) return; const rows = mtSkuRows(results); box.innerHTML = `<div class="mt-result-title">📦 ${title} (${rows.length})</div>` + (rows.length ? `<table class="mt-result-table"><thead><tr><th>SKU</th><th>Tên sản phẩm</th><th>Điểm</th><th>Kho</th><th></th></tr></thead><tbody>${rows.map(s => `<tr><td>${escapeHtml(mtText(s.skuId))}</td><td>${escapeHtml(mtText(s.name))}</td><td>${escapeHtml(mtText(s.score,'—'))}</td><td>${escapeHtml(mtText(s.stockPct,'—'))}</td><td><button class="btn ghost" type="button" onclick="mtSetSku(${JSON.stringify(s.skuId)})">Chọn</button></td></tr>`).join('')}</tbody></table>` : '<div class="mt-result-muted">API không trả danh sách SKU.</div>'); }
function renderMtSkuMany(results, title){ renderMtSkuBox(results, title, 'mtResult'); }
function renderMtSkuMiniMany(results, title){ renderMtSkuBox(results, title, 'mtSkuMini'); }
function mtSetBalances(results){ results.forEach(x => { if (x.ok && x.j?.balance !== undefined) mtRunState[mtKey(x.account)].balance = x.j.balance; }); saveMtRunState(); renderMtSelectedAccounts(); }
async function mtLoginSelected(){
  const results = await mtMany('mt_login', {}, 'đăng nhập');
  const logged = results.filter(x => x.ok);
  if (logged.length) {
    setMtRunStatus(`Đang lấy số dư cho ${logged.length} tài khoản đã đăng nhập...`, 'run');
    const settled = await Promise.allSettled(logged.map(x => mtCallSlot('mt_balance', x.account, {})));
    settled.forEach((s, i) => { const x = s.status === 'fulfilled' ? s.value : null; if (x?.ok && x.j?.balance !== undefined) mtRunState[mtKey(logged[i].account)].balance = x.j.balance; });
    saveMtRunState(); renderMtSelectedAccounts();
  }
  if (results.length) setMtRunStatus(`Đăng nhập xong: ${logged.length}/${results.length} tài khoản thành công.`, logged.length === results.length ? 'ok' : (logged.length ? 'run' : 'bad'));
}
async function mtLogoutSelected(){ const results = await mtMany('mt_logout', {}, 'đăng xuất'); results.forEach(x => { const k = mtKey(x.account); if (x.ok) mtRunState[k] = {}; }); saveMtRunState(); renderMtSelectedAccounts(); if (results.length) setMtRunStatus(`Đăng xuất xong: ${results.filter(x => x.ok).length}/${results.length} phiên.`, ''); }
async function mtSyncSelected(){ const results = await mtMany('mt_sync', {}, 'đồng bộ thời gian'); if (results.length) setMtRunStatus(`Đồng bộ xong: ${results.filter(x => x.ok).length}/${results.length} tài khoản.`, ''); }
async function mtBalanceSelected(){ const all=mtSelected(); const need=all.filter(a=>mtAccountBalance(a)==='—'); if(!need.length){setMtRunStatus('Tất cả tài khoản đã có số coin lưu, không gọi lại API.','ok');return;} const settled=await Promise.allSettled(need.map(a=>mtCallSlot('mt_balance',a,{}))); const results=settled.map((x,i)=>x.status==='fulfilled'?x.value:({ok:false,error:x.reason?.message||'Request lỗi',account:need[i]})); results.forEach(x=>mtSetRunStatus(x.account,x,{})); const ok=results.filter(x=>x.ok).length; setMtRunStatus(`Đã cập nhật số dư: ${ok}/${results.length} tài khoản mới.`,ok===results.length?'ok':'run'); }
async function mtSkusSelected(){ const x = await mtOne('mt_skus', {}, 'tải SKU'); if (x) renderMtSkuBox([x], 'Danh sách SKU', 'mtSkuMini'); }
async function mtAvailableSkusSelected(){ const x = await mtOne('mt_available_skus', {}, 'tải SKU có sẵn'); if (x) renderMtSkuBox([x], '📋 SKU có sẵn', 'mtResult'); }
async function mtCreateOrderSelected(){ const skuId = Number(v('mt_sku') || 0), num = Number(v('mt_num') || 1); if (!skuId) { setMtRunStatus('Hãy chọn hoặc nhập SKU trước.', 'bad'); return; } const results = await mtMany('mt_create_order', {skuId, num}, 'tạo đơn'); if (results.length) renderMtManyStatus(results, '🛒 Kết quả tạo đơn theo SKU'); }
async function mtSnipeSelected(){ const data = {skuId:Number(v('mt_sku') || 0),num:Number(v('mt_num') || 1),delayMs:Number(v('mt_delay') || 1000),maxAttempts:Number(v('mt_max') || 100)}; if (!data.skuId) { setMtRunStatus('Hãy chọn hoặc nhập SKU trước.', 'bad'); return; } const results = await mtMany('mt_snipe', data, 'săn thẻ'); if (results.length) renderMtManyStatus(results, '🎯 Kết quả săn thẻ'); }
async function mtGiftListSelected(){ const results = await mtMany('mt_gift_list', {page:Number(v('mt_page') || 1),size:Number(v('mt_size') || 10)}, 'tải thẻ đã mua'); if (results.length) renderMtManyCards(results, '🎁 Danh sách thẻ đã mua'); }
async function mtGiftDetailSelected(){ const orderNo = String(v('mt_order') || '').trim(); if (!orderNo) { setMtRunStatus('Hãy nhập mã đơn hàng trước.', 'bad'); return; } const results = await mtMany('mt_gift_detail', {orderNo}, 'tải chi tiết đơn'); if (results.length) renderMtManyCards(results, '🔎 Chi tiết đơn hàng'); }
async function buyAuto(){
  const accounts = mtSelected(); if (!accounts.length){ setMtRunStatus('Hãy bấm + và chọn ít nhất 1 tài khoản LDPlayer.', 'bad'); return; }
  setMtRunStatus(`Đang kiểm tra phiên của ${accounts.length} tài khoản...`, 'run');
  const ensured = await mtEnsureSessions(accounts);
  if (!ensured.ok){ setMtRunStatus('Có tài khoản chưa đăng nhập được, kiểm tra dấu × rồi thử lại.', 'bad'); return; }
  setMtRunStatus(`Đang kiểm tra SKU và số dư cho ${accounts.length} tài khoản cùng lúc...`, 'run');
  const settled = await Promise.allSettled(accounts.map(a => mtCallSlot('mt_buy_auto', a, {confirm:false})));
  const previews = settled.map((x, i) => x.status === 'fulfilled' ? x.value : ({ok:false,error:x.reason?.message || 'Request lỗi',account:accounts[i]}));
  previews.forEach(x => mtSetRunStatus(x.account, x, {balance:x.j?.data?.balance}));
  const ready = previews.filter(x => x.ok && x.j?.data?.preview && x.j?.data?.bestSku);
  const rows = previews.map(x => `<tr><td>${escapeHtml(mtEmail(x.account))}</td><td>${x.ok && x.j?.data?.bestSku ? escapeHtml(String(x.j.data.bestSku.name || x.j.data.bestSku.skuId)) : escapeHtml(x.error || 'Không có SKU phù hợp')}</td><td>${x.ok && x.j?.data?.balance !== undefined ? escapeHtml(String(x.j.data.balance)) + ' điểm' : '—'}</td></tr>`).join('');
  const box = document.getElementById('mtResult'); if (box) box.innerHTML = `<div class="mt-result-title">🛒 Sản phẩm đề xuất cho từng tài khoản</div><table class="mt-result-table"><thead><tr><th>Gmail</th><th>Sản phẩm</th><th>Số dư</th></tr></thead><tbody>${rows}</tbody></table>`;
  if (!ready.length) { setMtRunStatus('Không có tài khoản nào đủ điều kiện mua.', 'bad'); return; }
  const first = ready[0].j.data.bestSku; if (!confirm(`Mua đồng thời ${ready.length} tài khoản?\nSản phẩm mẫu: ${first.name || first.skuId} — ${first.score || ''} điểm`)) { setMtRunStatus('Đã hủy mua.', ''); return; }
  setMtRunStatus(`Đang tạo đơn cho ${ready.length} tài khoản cùng lúc...`, 'run');
  const orders = await Promise.allSettled(ready.map(x => mtCallSlot('mt_create_order', x.account, {skuId:Number(x.j.data.bestSku.skuId),num:1})));
  const results = orders.map((x, i) => x.status === 'fulfilled' ? x.value : ({ok:false,error:x.reason?.message || 'Request lỗi',account:ready[i].account}));
  results.forEach(x => mtSetRunStatus(x.account, x, {})); renderMtManyStatus(results, '🛒 Kết quả mua thẻ đồng thời');
  const ok = results.filter(x => x.ok).length; setMtRunStatus(`Đã mua xong: ${ok}/${results.length} tài khoản thành công.`, ok === results.length ? 'ok' : (ok ? 'run' : 'bad'));
}

let mtSessionPromise = null;
async function refreshSess(){
  if (mtSessionPromise) return mtSessionPromise;
  mtSessionPromise = (async () => {
    const accounts = mtSelected(); const el = document.getElementById('mt_sess'); renderMtSelectedAccounts();
    if (!accounts.length){ if (el) el.textContent = ''; return []; }
    const settled = await Promise.allSettled(accounts.map(a => mtCallSlot('mt_session', a, {})));
    const results = settled.map((x, i) => x.status === 'fulfilled' ? x.value : ({ok:false,error:x.reason?.message || 'Request lỗi',account:accounts[i]}));
    results.forEach(x => { if (x.j?.data?.uid) mtSetRunStatus(x.account, Object.assign({}, x.j, {ok:true}), {}); });
    if (el) el.textContent = `— ${results.filter(x => x.j?.data?.uid).length}/${results.length} phiên đã đăng nhập`;
    return results;
  })();
  try { return await mtSessionPromise; } finally { mtSessionPromise = null; }
}

function openMtaAccountPicker(){
  mtPickerDraft = mtSelectedAccounts.slice();
  renderMtaAccountPicker();
  const modal = document.getElementById('mtAccountModal'); if (modal) modal.classList.add('show');
}
function closeMtaAccountPicker(){ const modal = document.getElementById('mtAccountModal'); if (modal) modal.classList.remove('show'); mtPickerDraft = []; }
function renderMtaAccountPicker(){
  const box = document.getElementById('mtAccountPickList'); if (!box) return;
  const list = (typeof ACCOUNTS !== 'undefined' ? ACCOUNTS : []).filter(a => a.type === 'ldplayer' && mtEmail(a));
  const selected = new Set(mtPickerDraft.map(mtKey));
  const count = document.getElementById('mtPickerCount'); if (count) count.textContent = `${selected.size}/${MT_MAX_ACCOUNTS} tài khoản`;
  if (!list.length){ box.innerHTML = '<div class="histempty">Chưa có tài khoản LDPlayer đã lưu để chọn.</div>'; return; }
  box.innerHTML = list.map(a => { const key = mtKey(a), isSelected = selected.has(key), status = mtRunState[key]?.status || ''; const mark = isSelected && status === 'ok' ? '√' : (isSelected && status === 'bad' ? '×' : ''); return `<div class="mt-account-option${isSelected ? ' selected' : ''}${isSelected && status === 'bad' ? ' failed' : ''}" data-mt-key="${escapeHtml(key)}"><span class="mt-option-mark">${mark}</span><span class="mt-option-email">${escapeHtml(mtEmail(a))}</span><span class="mt-option-bal">${escapeHtml(String(mtAccountBalance(a)))} điểm</span></div>`; }).join('');
  box.querySelectorAll('.mt-account-option').forEach(row => row.onclick = () => { const key = row.dataset.mtKey; const account = list.find(a => mtKey(a) === key); const idx = mtPickerDraft.findIndex(a => mtKey(a) === key); if (idx >= 0) mtPickerDraft.splice(idx, 1); else if (account) mtPickerDraft.push(account); renderMtaAccountPicker(); });
}
function applyMtaAccountPicker(){
  mtSelectedAccounts = mtPickerDraft.slice(0, MT_MAX_ACCOUNTS);
  saveMtSelection(); renderMtSelectedAccounts(); closeMtaAccountPicker();
  if (typeof refreshSess === 'function') refreshSess();
  setMtRunStatus(mtSelectedAccounts.length ? `Đã áp dụng ${mtSelectedAccounts.length} tài khoản. Android ID đi theo từng tài khoản.` : 'Đã bỏ chọn tất cả tài khoản.', '');
}

// ===== proxy tab =====
function renderFeatures(){
  const el = document.getElementById('featList');
  el.innerHTML = '';
  FEATURES.forEach(f => {
    const row = document.createElement('div');
    row.className = 'frow';
    row.innerHTML = '<label class="sw"><input type="checkbox" '+(f.enabled?'checked':'')+' data-k="'+f.key+'"><span class="sl"></span></label>'
      + '<span class="mark '+(f.enabled?'on':'off')+'">'+(f.enabled?'√':'×')+'</span>'
      + '<span class="lbl">'+f.label+'</span><span class="pooltag">pool: '+f.pool+'</span>';
    row.querySelector('input').onchange = e => {
      f.enabled = e.target.checked;
      const m = row.querySelector('.mark');
      m.textContent = f.enabled?'√':'×'; m.className = 'mark '+(f.enabled?'on':'off');
    };
    el.appendChild(row);
  });
}
// ===== Danh sách proxy dạng hàng (kèm dấu trạng thái) =====
function addProxyRow(pool, val, state){
  const list = document.getElementById(pool === 'us' ? 'p_us_list' : 'p_vn_list');
  const row = document.createElement('div');
  row.className = 'prow';
  const mark = document.createElement('span');
  mark.className = 'pmark ' + (state === 'bad' ? 'bad' : (state === 'cur' ? 'cur' : 'unknown'));
  mark.textContent = state === 'bad' ? '×' : (state === 'cur' ? '✓' : '•');
  mark.title = state === 'bad' ? 'Proxy lỗi — không tái sử dụng' : (state === 'cur' ? 'Đang dùng, hoạt động tốt' : 'Chưa dùng tới');
  const inp = document.createElement('input');
  inp.value = val || '';
  inp.placeholder = '42.112.34.34:3434';
  inp.spellcheck = false;
  const del = document.createElement('button');
  del.type = 'button'; del.className = 'pdel'; del.textContent = '✕'; del.title = 'Xóa dòng này';
  del.onclick = () => row.remove();
  row.appendChild(mark); row.appendChild(inp); row.appendChild(del);
  list.appendChild(row);
}
function renderProxyList(j){
  const render = (pool, arr, bad, cur) => {
    const box = document.getElementById(pool === 'us' ? 'p_us_list' : 'p_vn_list');
    box.innerHTML = '';
    (arr || []).forEach(p => {
      const state = (bad || []).indexOf(p) >= 0 ? 'bad' : (p === cur ? 'cur' : '');
      addProxyRow(pool, p, state);
    });
    if (!(arr || []).length) addProxyRow(pool, '', '');
    const info = document.getElementById(pool === 'us' ? 'p_us_cur' : 'p_vn_cur');
    info.innerHTML = cur ? ('Đang dùng: <b>' + escapeHtml(cur) + '</b>') : 'Chưa có proxy nào đang dùng';
  };
  render('us', j.proxy_us, j.bad_us, j.cur_us);
  render('vn', j.proxy_vn, j.bad_vn, j.cur_vn);
}
let proxyLoadPromise = null;
async function loadProxy(force = false){
  if (proxyLoadPromise) return proxyLoadPromise;
  proxyLoadPromise = (async () => {
    const j = await callQuiet('proxy_get');
    if (j && j.ok){
      if (Array.isArray(j.features)) FEATURES = j.features;
      renderFeatures();
      renderProxyList(j);
    }
    return j;
  })();
  try { return await proxyLoadPromise; } finally { proxyLoadPromise = null; }
}
async function saveProxy(){
  const flags = {}; FEATURES.forEach(f => flags[f.key] = f.enabled);
  const collect = id => Array.from(document.getElementById(id).querySelectorAll('input'))
    .map(i => i.value.trim()).filter(x => x !== '').join('\n');
  const j = await call('proxy_save', {flags, proxy_us: collect('p_us_list'), proxy_vn: collect('p_vn_list')});
  if (j && j.ok) await loadProxy();   // tải lại để cập nhật dấu ✓/×
}
async function resetProxyState(){
  const j = await call('proxy_reset_state', {});
  if (j && j.ok) await loadProxy();
}

// ===== Lưu Tài Khoản + nút "+" tự điền =====
let ACCOUNTS = [];
let accountsLoadPromise = null, accountsLoadedAt = 0;
async function loadAccounts(force = false){
  if (!force && accountsLoadPromise) return accountsLoadPromise;
  if (!force && accountsLoadedAt && Date.now() - accountsLoadedAt < 900){ renderAccList(); return ACCOUNTS; }
  accountsLoadPromise = (async () => {
    const j = await callQuiet('acc_list');
    if (j && j.ok) { ACCOUNTS = j.accounts || []; accountsLoadedAt = Date.now(); }
    renderAccList();
    if (window.mimiUserKey && typeof restoreMtSelection === 'function') restoreMtSelection();
    return ACCOUNTS;
  })();
  try { return await accountsLoadPromise; } finally { accountsLoadPromise = null; }
}
function copyText(t, btn){
  const done = () => { if (btn){ btn.textContent = '✓ Đã copy'; setTimeout(() => { btn.textContent = '📋 Copy'; }, 1200); } };
  if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(done, () => fallbackCopy(t, done));
  else fallbackCopy(t, done);
}
function fallbackCopy(t, cb){
  const ta = document.createElement('textarea');
  ta.value = t; ta.style.cssText = 'position:fixed;opacity:0';
  document.body.appendChild(ta); ta.select();
  try{ document.execCommand('copy'); }catch(e){}
  ta.remove(); cb();
}
// Mục Tài Khoản: 2 danh mục Funpass / LDPlayer. Nút ↑/↓ ở tiêu đề danh mục = thu gọn/mở rộng
// cả danh sách. Mỗi dòng CHỈ hiển thị mail + mũi tên ↓/↑ nhỏ bên phải: bấm → trượt ra panel
// chi tiết đầy đủ (uid/token/androidid/username/password/temp_token/serverTimeOffset) + Copy + Xóa.
const accCatOpen = {fp: localStorage.getItem('fp_acc_open_fp') !== '0', ld: localStorage.getItem('fp_acc_open_ld') !== '0'};
function toggleAccCat(cat){
  accCatOpen[cat] = !accCatOpen[cat];
  localStorage.setItem('fp_acc_open_' + cat, accCatOpen[cat] ? '1' : '0');
  renderAccList();
}
function renderAccList(){
  const fp = ACCOUNTS.filter(a => a.type === 'funpass');
  const ld = ACCOUNTS.filter(a => a.type !== 'funpass');
  const cntFp = document.getElementById('accCntFp');
  const cntLd = document.getElementById('accCntLd');
  if (cntFp) cntFp.textContent = '— ' + fp.length + ' tài khoản';
  if (cntLd) cntLd.textContent = '— ' + ld.length + ' tài khoản';
  const render = (elId, arr, emptyTxt, cat) => {
    const el = document.getElementById(elId);
    if (!el) return;
    const tgl = document.getElementById(cat === 'fp' ? 'accTglFp' : 'accTglLd');
    if (tgl) tgl.textContent = accCatOpen[cat] ? '↑' : '↓';
    el.style.display = accCatOpen[cat] ? 'block' : 'none';
    if (!arr.length){ el.innerHTML = '<div class="histempty">' + emptyTxt + '</div>'; return; }
    el.innerHTML = '';
    arr.forEach(a => {
      const i = ACCOUNTS.indexOf(a);
      const d = document.createElement('div');
      d.className = 'accitem';
      d.style.cursor = 'default';
      d.style.flexWrap = 'wrap';
      // Thu gọn: chỉ hiển thị mail (username) + mũi tên ↓/↑ nhỏ bên phải
      d.innerHTML = '<span class="em" title="'+escapeHtml(a.email||'')+'">'+escapeHtml(a.email||'')+'</span>'
        + '<span class="small">'+escapeHtml(a.created_at || a.saved || 'Chưa rõ thời gian')+'</span>'
        + '<button class="btn ghost btn-arrow" style="padding:3px 10px;margin:0;font-size:12px" title="Xem / ẩn chi tiết tài khoản">↓</button>';
      // Panel chi tiết trượt ra: đủ MẪU + Copy + Xóa
      const full = normAccount(a);
      if (a.device_id_header) full.device_id_header = a.device_id_header;
      full.type = (a.type === 'funpass') ? 'funpass' : 'ldplayer';
      if (a.saved) full.saved = a.saved;
      if (a.created_at) full.created_at = a.created_at;
      const txt = JSON.stringify(full, null, 2);
      const panel = document.createElement('div');
      panel.style.cssText = 'display:none;flex-basis:100%;overflow:hidden';
      const pre = document.createElement('pre');
      pre.className = 'json';
      pre.style.cssText = 'margin:8px 0 0';
      pre.textContent = txt;
      const bar = document.createElement('div');
      bar.style.cssText = 'margin:6px 0 2px';
      const cp = document.createElement('button');
      cp.className = 'btn ghost'; cp.textContent = '📋 Copy';
      cp.style.cssText = 'padding:4px 12px;font-size:11px;margin:0 6px 0 0';
      cp.onclick = ev => { ev.stopPropagation(); copyText(txt, cp); };
      const edit = document.createElement('button');
      edit.className = 'btn ghost'; edit.textContent = '✎ Sửa'; edit.title = 'Đưa tài khoản vào ô JSON để chỉnh sửa';
      edit.style.cssText = 'padding:4px 12px;font-size:11px;margin:0 6px 0 0';
      edit.onclick = ev => { ev.stopPropagation(); const ta=document.getElementById('sv_json'); if(ta){ ta.value=txt; const type=document.getElementById('sv_type'); if(type) type.value=full.type; ta.scrollIntoView({behavior:'smooth',block:'center'}); ta.focus(); log(['[UI] Đã đưa tài khoản vào ô JSON để chỉnh sửa, sau đó bấm Lưu Tài Khoản.']); } };
      const del = document.createElement('button');
      del.className = 'btn danger'; del.textContent = '🗑️'; del.title = 'Xóa tài khoản';
      del.style.cssText = 'padding:4px 12px;font-size:11px;margin:0';
      del.onclick = ev => { ev.stopPropagation(); delAccount(i); };
      bar.appendChild(cp); bar.appendChild(edit); bar.appendChild(del);
      panel.appendChild(pre); panel.appendChild(bar);
      d.appendChild(panel);
      const arrow = d.querySelector('.btn-arrow');
      arrow.onclick = ev => {
        ev.stopPropagation();
        const open = panel.style.display === 'none';
        panel.style.display = open ? 'block' : 'none';
        arrow.textContent = open ? '↑' : '↓';
      };
      el.appendChild(d);
    });
  };
  render('accListFp', fp, 'Chưa có tài khoản Funpass nào — chạy "Tạo Funpass mới" / flow thành công sẽ tự ghi vào đây.', 'fp');
  render('accListLd', ld, 'Chưa có tài khoản LDPlayer nào — tạo/đăng nhập LDPlayer thành công sẽ tự ghi vào đây.', 'ld');
}
// Lưu từ ô JSON duy nhất: parse đúng MẪU → gửi server, server tự xếp đúng danh mục
async function saveAccount(){
  const raw = v('sv_json');
  if (!raw){ log(['[UI] Dán JSON tài khoản vào ô trước khi lưu']); return; }
  try{ JSON.parse(raw); }catch(e){ log(['[UI] JSON không hợp lệ: ' + e.message]); return; }
  const j = await call('acc_save', {json: raw, type: v('sv_type')});
  if (j && j.ok) document.getElementById('sv_json').value = '';
  await loadAccounts(true);
}
// Nút Dán clipboard vào ô JSON lưu tài khoản
async function pasteToSvJson(){
  const ta = document.getElementById('sv_json');
  if (!ta) return;
  try {
    if (navigator.clipboard && navigator.clipboard.readText) {
      const t = await navigator.clipboard.readText();
      ta.value = t;
      log(['[UI] Đã dán nội dung từ clipboard vào ô JSON']);
    } else {
      ta.focus();
      log(['[UI] Trình duyệt không hỗ trợ đọc clipboard tự động — hãy Ctrl+V thủ công']);
    }
  } catch(e) {
    ta.focus();
    log(['[UI] Không đọc được clipboard (' + e.message + ') — hãy Ctrl+V thủ công']);
  }
}
// Tạo module PHP độc lập: tên mục + tên file → menu dưới Proxy + file trong thư mục
async function createNewFeatureFile(){
  const label = v('new_mod_label');
  const name = v('new_file_name');
  if (!label){ log(['[UI] Nhập Tên mục (hiển thị trên menu, dưới Proxy)']); return; }
  if (!name){ log(['[UI] Nhập tên file (vd: tool_abc.php)']); return; }
  if (!/^[\w.\-]+\.php$/i.test(name) && !/^[\w.\-]+$/.test(name)) {
    log(['[UI] Tên file không hợp lệ (chỉ chữ, số, ., -, _ và nên kết thúc .php)']); return;
  }
  const j = await call('create_empty_file', {filename: name, label: label});
  // File có thể đã tạo dù response lỗi một phần → luôn thử nạp lại menu
  await loadCustomModules();
  if (j && j.ok) {
    log(['[UI] ' + (j.msg || 'Đã tạo module')]);
    document.getElementById('new_mod_label').value = '';
    document.getElementById('new_file_name').value = '';
  } else if (j && j.data && j.data.filename) {
    log(['[UI] File có thể đã có trên đĩa: ' + j.data.filename + ' — đã thử đăng ký menu. ' + (j.error || '')]);
  }
}
// Nạp danh sách module → nút menu dưới Proxy + danh sách trong mục Tài Khoản
let modulesLoadPromise = null;
async function loadCustomModules(){
  if (modulesLoadPromise) return modulesLoadPromise;
  modulesLoadPromise = (async () => {
  const j = await callQuiet('modules_list');
  const mods = (j && j.ok && Array.isArray(j.modules)) ? j.modules : [];
  const canManage = !!window.mimiCanManageModules;
  const nav = document.getElementById('navCustomMods');
  if (nav) {
    nav.innerHTML = '';
    mods.forEach(m => {
      const b = document.createElement('button');
      b.type = 'button';
      b.dataset.tab = 'custommod';
      b.dataset.modFile = m.file || '';
      b.dataset.modLabel = m.label || m.file || '';
      b.textContent = '📦 ' + (m.label || m.file || 'Module');
      b.onclick = () => openCustomModule(m.file, m.label || m.file);
      nav.appendChild(b);
    });
  }
  const box = document.getElementById('modListBox');
  if (box) {
    if (!mods.length) {
      box.innerHTML = '<div class="histempty">Chưa có file PHP nào — gửi file bằng nút + trong Mimi để thêm.</div>';
    } else {
      box.innerHTML = '<div class="small" style="margin-bottom:6px">File PHP đã đăng ký (mục dưới Funpass Auto):</div>';
      mods.forEach(m => {
        const row = document.createElement('div');
        row.className = 'accitem';
        row.style.cursor = 'default';
        row.innerHTML = '<span class="em">📦 ' + escapeHtml(m.label || '') + '</span>'
          + '<span class="small"><code>' + escapeHtml(m.file || '') + '</code></span>';
        const openBtn = document.createElement('button');
        openBtn.className = 'btn ghost'; openBtn.style.cssText = 'padding:3px 10px;font-size:11px;margin:0';
        openBtn.textContent = 'Mở';
        openBtn.onclick = () => openCustomModule(m.file, m.label || m.file);
        const rm = document.createElement('button');
        rm.className = 'btn danger'; rm.style.cssText = 'padding:3px 10px;font-size:11px;margin:0' + (canManage ? '' : ';display:none');
        rm.title = 'Xóa file PHP sau khi xác nhận';
        rm.textContent = '🗑️ Xóa';
        rm.onclick = async () => {
          if (!confirm('Bạn có chắc muốn xóa file PHP «' + (m.label || m.file) + '» khỏi server không? File sẽ bị xóa khỏi đĩa và khỏi menu.')) return;
          const result = await call('module_delete', {filename: m.file});
          if (result && result.ok) await loadCustomModules();
          else if (result && result.error) log(['[UI] Không xóa được file ' + m.file + ': ' + result.error]);
          else log(['[UI] Không nhận được kết quả khi xóa file ' + m.file]);
        };
        const actions = document.createElement('span');
        actions.className = 'mod-actions';
        actions.appendChild(openBtn);
        if (canManage) actions.appendChild(rm);
        row.appendChild(actions);
        box.appendChild(row);
      });
    }
  }
  return mods;
  })();
  try { return await modulesLoadPromise; } finally { modulesLoadPromise = null; }
}
/** Mở module trong tab iframe — hoàn toàn độc lập với api.php */
function closeCustomModule(){
  document.body.classList.remove('custom-module-open');
  const frame = document.getElementById('customModFrame');
  if (frame) frame.src = 'about:blank';
  const auto = document.getElementById('navAuto');
  if (auto) auto.click();
}
let currentCustomModule = {file:'', label:''};
async function deleteCurrentCustomModule(){
  const file = currentCustomModule.file;
  if (!file) { log(['[UI] Chưa chọn module để xóa']); return; }
  if (!confirm('Bạn có chắc muốn xóa file PHP «' + (currentCustomModule.label || file) + '» không? File sẽ bị xóa khỏi server và khỏi menu.')) return;
  const result = await call('module_delete', {filename:file});
  if (result && result.ok) { await loadCustomModules(); closeCustomModule(); }
  else if (result && result.error) log(['[UI] Không xóa được file ' + file + ': ' + result.error]);
  else log(['[UI] Không nhận được kết quả khi xóa file ' + file]);
}
function openCustomModule(file, label){
  if (!file) return;
  currentCustomModule = {file:String(file), label:String(label || file)};
  document.querySelectorAll('nav button').forEach(x => x.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
  // Đánh dấu nút module tương ứng
  document.querySelectorAll('#navCustomMods button').forEach(b => {
    if (b.dataset.modFile === file) b.classList.add('active');
  });
  const tab = document.getElementById('tab-custommod');
  if (tab) tab.classList.add('active');
  document.body.classList.add('custom-module-open');
  const title = document.getElementById('customModTitle');
  const fileLabel = document.getElementById('customModFile');
  if (title) title.textContent = label || file;
  if (fileLabel) fileLabel.textContent = file + ' · file PHP độc lập';
  const frame = document.getElementById('customModFrame');
  const deleteButton = document.querySelector('.custom-mod-delete');
  if (deleteButton) deleteButton.style.display = window.mimiCanManageModules ? 'grid' : 'none';
  const route = new URL(location.href);
  route.search = '';
  route.hash = '';
  route.searchParams.set('module', file);
  route.searchParams.set('_t', Date.now());
  // Module là một trang độc lập: không đặt trong iframe/layout của api3.php.
  location.href = route.toString();
}
async function delAccount(i){
  await call('acc_delete', {idx: i});
  await loadAccounts(true);
}
// Nút "+" cạnh mỗi chức năng: chỉ liệt kê tài khoản ĐÚNG LOẠI chức năng đó cần (dạng tóm tắt,
// chỉ hiển thị mail); chọn xong TỰ ĐIỀN đủ các field theo dữ liệu đã lưu — không cần nhập tay.
// openAccPicker(map, type): map = {id ô nhập: khóa tài khoản}; khóa hỗ trợ:
// email | password | uid | token | androidid | temp_token | serverTimeOffset
let accTarget = null;
function openAccPicker(map, type){
  accTarget = {map: map || {}, type: type || ''};
  const sel = document.getElementById('accFilter');
  if (sel){
    sel.value = accTarget.type;
    sel.style.display = accTarget.type ? 'none' : '';   // đã khóa loại → không cần bộ lọc
  }
  const head = document.querySelector('#accModal .head b');
  if (head) head.textContent = accTarget.type === 'funpass' ? 'Chọn tài khoản Funpass'
    : (accTarget.type === 'ldplayer' ? 'Chọn tài khoản LDPlayer' : 'Chọn tài khoản đã lưu');
  renderAccPicker();
  document.getElementById('accModal').classList.add('show');
}
function closeAccPicker(){ document.getElementById('accModal').classList.remove('show'); accTarget = null; }
function renderAccPicker(){
  const f = accTarget && accTarget.type ? accTarget.type : v('accFilter');
  const el = document.getElementById('accPickList');
  const list = ACCOUNTS.filter(a => !f || a.type === f);
  if (!list.length){
    el.innerHTML = '<div class="histempty">Chưa có tài khoản ' + (f === 'funpass' ? 'Funpass' : (f === 'ldplayer' ? 'LDPlayer' : '')) + ' nào. Xem/lưu ở mục "👤 Tài Khoản".</div>';
    return;
  }
  el.innerHTML = '';
  list.forEach(a => {
    const d = document.createElement('div');
    d.className = 'accitem';
    d.innerHTML = '<span class="em">'+escapeHtml(a.email||'')+'</span>';   // tóm tắt: chỉ mail
    d.onclick = () => {
      if (accTarget){
        for (const id in accTarget.map){
          const inp = document.getElementById(id);
          if (!inp) continue;
          const key = accTarget.map[id];
          const val = a[key] !== undefined && a[key] !== null ? a[key] : '';
          inp.value = String(val);
        }
      }
      closeAccPicker();
    };
    el.appendChild(d);
  });
}

// ===== Lịch Sử Log =====
// ===== Thu gọn / mở rộng Lịch Sử Log (mặc định: đã thu gọn) =====
let historyDirty = true;
function toggleHistory(){
  const el = document.getElementById('histList');
  const btn = document.getElementById('histToggle');
  const collapsed = el.style.display === 'none';
  el.style.display = collapsed ? 'block' : 'none';
  btn.textContent = collapsed ? '▾' : '▸';
  if (collapsed && historyDirty) refreshHistory();
}
let historyLoadPromise = null;
async function refreshHistory(){
  if (historyLoadPromise) return historyLoadPromise;
  historyLoadPromise = (async () => {
    const j = await callQuiet('runlog_list');
    if (!j || !j.ok) return [];
    historyDirty = false;
    const h = j.history || [];
    const el = document.getElementById('histList');
    if (!h.length){ el.innerHTML = '<div class="histempty">Chưa có lần chạy nào.</div>'; return h; }
    el.innerHTML = '';
    h.forEach(it => {
      const d = document.createElement('div');
      d.className = 'histitem';
      const cnt = (it.count && it.count > 1) ? ' <span class="histcnt">×' + it.count + '</span>' : '';
      d.innerHTML = '<span class="tm">'+escapeHtml(it.time||'')+'</span>'
        + '<span class="lb" title="'+escapeHtml(it.label||it.action||'')+'">'+escapeHtml(it.label||it.action||'')+cnt+'</span>'
        + '<span class="st '+(it.ok?'ok':'bad')+'">'+(it.ok?'OK':'Lỗi')+'</span>';
      d.title = 'Bấm để xem lại console của phiên này' + (it.count > 1 ? ' (' + it.count + ' lần)' : '');
      d.onclick = () => {
        const c = document.getElementById('console');
        c.innerHTML = '';
        log(['──── Xem lại: ' + (it.label||it.action) + ' — ' + (it.time||'') + (it.count > 1 ? ' (' + it.count + ' lần)' : '') + ' ────'], true);
        log(it.logs || [], true);
        c.scrollIntoView({behavior:'smooth', block:'center'});
      };
      el.appendChild(d);
    });
    return h;
  })();
  try { return await historyLoadPromise; } finally { historyLoadPromise = null; }
}
async function clearHistory(){
  if (!confirm('Xóa toàn bộ lịch sử log?')) return;
  await call('runlog_clear', {});
  document.getElementById('histList').innerHTML = '<div class="histempty">Chưa có lần chạy nào.</div>';
  historyDirty = false;
}

// ---- Khởi động ----
(async function init(){
  setViewMode(viewMode);
  pxInit();
  applyDet();
  renderFeatures();
  const authPromise = callQuiet('auth_status');
  window.fpAuthInfoPromise = authPromise;
  const [_, __, authInfo] = await Promise.all([loadProxy(), loadAccounts(), authPromise]);
  window.mimiCanManageModules = !!(authInfo && authInfo.ok && authInfo.developer);
  window.mimiUserKey = (authInfo && authInfo.authenticated && authInfo.user) ? authInfo.user : 'guest';
  restoreMtSelection();
  setupOtpInputs();
  const mtActive = !!document.getElementById('tab-muathe')?.classList.contains('active');
  await Promise.all([loadCustomModules(), mtActive ? refreshSess() : Promise.resolve()]);
})();
</script>
<script src="chatgpt.js?v=opt84"></script>
</body>
</html>
HTML;
}

/* CLI entry */
if (IS_CLI && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // PHP CLI không có cookie HTTP; bridge truyền identity qua env để giữ scope.
    $cliMimiUser = trim((string)(getenv('MIMI_USER') ?: ''));
    if ($cliMimiUser !== '' && in_array($cliMimiUser, MIMI_AUTH_USERS, true)) {
        $_COOKIE[MIMI_AUTH_COOKIE] = mimi_auth_token($cliMimiUser, time() + MIMI_AUTH_TTL);
    }
    $cliGuest = trim((string)(getenv('MIMI_GUEST') ?: ''));
    if ($cliGuest !== '') {
        $_COOKIE['mimi_guest'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $cliGuest) ?: '';
    }
    // Cho phép chạy action trực tiếp: php api.php <action> '<json>'
    if (($_SERVER['argc'] ?? 1) > 1) {
        $action = (string)$_SERVER['argv'][1];
        $in = [];
        if (($_SERVER['argc'] ?? 1) > 2) $in = json_decode((string)$_SERVER['argv'][2], true) ?: [];
        $res = run_action($action, $in);
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
        exit($res['ok'] ? 0 : 1);
    }
    cli_main();
}
