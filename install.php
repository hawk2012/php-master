<?php
declare(strict_types=1);

/**
 * PHP Framework Installer
 *
 * Single-file installer and version inspector for PHP frameworks.
 * Works in CLI and as a protected web application.
 *
 * Requirements: PHP 8.1+
 * Author: Mikhail Gubin
 */

const INSTALLER_VERSION = '1.0.0';
const USER_AGENT = 'PHP-Framework-Installer/1.0';
const PACKAGIST_P2 = 'https://repo.packagist.org/p2/%s.json';
const COMPOSER_INSTALLER_URL = 'https://getcomposer.org/installer';
const COMPOSER_SIGNATURE_URL = 'https://composer.github.io/installer.sig';

final class InstallerException extends RuntimeException
{
}

// ============================================================================
// Configuration
// ============================================================================

function envValue(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function envFlag(string $name, bool $default = false): bool
{
    $value = envValue($name);
    if ($value === null) {
        return $default;
    }
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function baseDir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $configured = envValue('INSTALL_BASE_DIR', __DIR__);
    $real = realpath((string) $configured);
    if ($real === false || !is_dir($real)) {
        throw new InstallerException('Каталог установки не найден: ' . $configured);
    }
    $dir = rtrim(normalizePath($real), '/');
    return $dir;
}

function toolsDir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $candidates = [];
    $configured = envValue('INSTALL_TOOLS_DIR');
    if ($configured !== null) {
        $candidates[] = $configured;
    }
    $candidates[] = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
        . 'php-fw-installer-' . substr(hash('sha256', baseDir()), 0, 12);
    $fallback = baseDir() . '/.installer';
    $candidates[] = $fallback;

    foreach ($candidates as $candidate) {
        try {
            if (!is_dir($candidate)) {
                @mkdir($candidate, 0700, true);
            }
            if (is_dir($candidate) && is_writable($candidate)) {
                $dir = rtrim(normalizePath(realpath($candidate) ?: $candidate), '/');
                if (normalizePath($dir) === normalizePath($fallback)) {
                    @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
                }
                return $dir;
            }
        } catch (Throwable $e) {
            // Try the next candidate.
        }
    }
    throw new InstallerException('Не удалось создать рабочий каталог установщика.');
}

function cacheDir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $dir = toolsDir() . '/cache';
    ensureDir($dir);
    return $dir;
}

function keyFile(): string
{
    return envValue('INSTALL_KEY_FILE', toolsDir() . '/install.key') ?? (toolsDir() . '/install.key');
}

function logFile(): string
{
    return envValue('INSTALL_LOG', toolsDir() . '/install.log') ?? (toolsDir() . '/install.log');
}

function lockFile(): string
{
    return envValue('INSTALL_LOCK_FILE', baseDir() . '/install.lock') ?? (baseDir() . '/install.lock');
}

function installLockFile(): string
{
    return toolsDir() . '/install-process.lock';
}

function caFile(): ?string
{
    $file = envValue('INSTALL_CAFILE');
    return ($file !== null && is_file($file)) ? $file : null;
}

function insecureTls(): bool
{
    return envFlag('INSTALL_INSECURE', false);
}

function cacheTtl(): int
{
    return max(60, (int) (envValue('INSTALL_CACHE_TTL', '900') ?? '900'));
}

function maxScanDepth(): int
{
    return max(1, min(6, (int) (envValue('INSTALL_SCAN_DEPTH', '3') ?? '3')));
}

function installTimeout(): int
{
    return max(300, (int) (envValue('INSTALL_TIMEOUT', '1800') ?? '1800'));
}

// ============================================================================
// Generic helpers
// ============================================================================

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonEncode(mixed $data): string
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return $json === false ? '{"ok":false,"error":"JSON encoding failed"}' : $json;
}

function jsonForScript(mixed $data): string
{
    $json = json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return $json === false ? 'null' : $json;
}

function jsonOut(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo jsonEncode($payload);
    exit;
}

function ensureDir(string $path, int $mode = 0700): void
{
    if (is_dir($path)) {
        return;
    }
    if (!@mkdir($path, $mode, true) && !is_dir($path)) {
        throw new InstallerException('Не удалось создать каталог: ' . $path);
    }
}

function normalizePath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    if (strlen($path) > 1 && !preg_match('#^[A-Za-z]:/$#', $path)) {
        $path = rtrim($path, '/');
    }
    return $path;
}

function isAbsolutePath(string $path): bool
{
    if ($path === '') {
        return false;
    }
    if ($path[0] === '/' || $path[0] === DIRECTORY_SEPARATOR) {
        return true;
    }
    return preg_match('#^[A-Za-z]:.#', $path) === 1
        && ($path[2] === '/' || $path[2] === DIRECTORY_SEPARATOR);
}

/**
 * Приводит путь к абсолютному виду: раскрывает "~", дополняет относительно $base
 * (по умолчанию — базовый каталог установщика) и нормализует разделители.
 */
function resolvePath(string $path, ?string $base = null): string
{
    $path = trim($path);
    $base = $base ?? baseDir();
    $sep = DIRECTORY_SEPARATOR;

    if ($path === '' || $path === '.') {
        return normalizePath(realpath($base) ?: $base);
    }

    if (str_starts_with($path, '~')) {
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            $home = getenv('USERPROFILE');
        }
        if (is_string($home) && $home !== '') {
            $path = rtrim($home, '/' . $sep) . substr($path, 1);
        }
    }

    if (!isAbsolutePath($path)) {
        $path = rtrim($base, '/' . $sep) . '/' . $path;
    }

    $real = realpath($path);
    return normalizePath($real !== false ? $real : $path);
}

function pathIsInside(string $path, string $base): bool
{
    $path = normalizePath($path);
    $base = normalizePath($base);
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $base = strtolower($base);
    }
    return $path === $base || str_starts_with($path, $base . '/');
}

function relativePath(string $path, string $base): string
{
    $path = normalizePath($path);
    $base = normalizePath($base);
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $base = strtolower($base);
    }
    if ($path === $base) {
        return '.';
    }
    if (str_starts_with($path, $base . '/')) {
        return substr($path, strlen($base) + 1);
    }
    return $path;
}

function formatBytes(float|int|null $bytes, int $precision = 1): string
{
    if ($bytes === null || $bytes <= 0) {
        return '0 Б';
    }
    $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    $power = (int) floor(log((float) $bytes, 1024));
    $power = max(0, min($power, count($units) - 1));
    return number_format($bytes / (1024 ** $power), $precision, ',', ' ') . ' ' . $units[$power];
}

function formatDuration(float $seconds): string
{
    if ($seconds < 60) {
        return number_format($seconds, 1, ',', ' ') . ' с';
    }
    $minutes = (int) floor($seconds / 60);
    $rest = (int) round($seconds - $minutes * 60);
    return $minutes . ' мин ' . $rest . ' с';
}

function stripAnsi(string $value): string
{
    $value = preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $value) ?? $value;
    $value = preg_replace('/\x1B\][^\x07]*\x07/', '', $value) ?? $value;
    return $value;
}

function appendLimited(string &$target, string $chunk, int $limit = 2_000_000): void
{
    $length = strlen($target);
    if ($length >= $limit) {
        return;
    }
    $target .= substr($chunk, 0, $limit - $length);
}

function readJsonFile(string $path, int $maxBytes = 8_000_000): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $size = @filesize($path);
    if ($size !== false && $size > $maxBytes) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function writeFileAtomic(string $path, string $contents, int $mode = 0600): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, $mode, true);
    }
    if (!is_dir($dir)) {
        return false;
    }
    $tmp = @tempnam($dir, '.tmp-');
    if ($tmp !== false) {
        $ok = @file_put_contents($tmp, $contents, LOCK_EX) !== false;
        if ($ok) {
            @chmod($tmp, $mode);
            if (@rename($tmp, $path)) {
                return true;
            }
        }
        @unlink($tmp);
    }
    return @file_put_contents($path, $contents, LOCK_EX) !== false;
}

function tailFile(string $path, int $lines = 200): array
{
    if (!is_file($path)) {
        return [];
    }
    $size = @filesize($path);
    if ($size === false || $size === 0) {
        return [];
    }
    $max = 512 * 1024;
    $start = max(0, $size - $max);
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    @fseek($handle, $start);
    $data = @stream_get_contents($handle);
    @fclose($handle);
    if ($data === false || $data === '') {
        return [];
    }
    $rows = preg_split('/\r\n|\r|\n/', $data) ?: [];
    return array_slice($rows, -$lines);
}

function findExecutable(string $name): ?string
{
    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $names = $isWindows ? [$name . '.exe', $name . '.bat', $name . '.cmd', $name] : [$name];
    $paths = [];

    if ($isWindows) {
        $systemRoot = getenv('SystemRoot');
        if (is_string($systemRoot) && $systemRoot !== '') {
            $paths[] = $systemRoot . '\\System32';
            $paths[] = $systemRoot . '\\System32\\WindowsPowerShell\\v1.0';
        }
    } else {
        $paths = ['/usr/local/bin', '/usr/bin', '/bin', '/usr/local/sbin', '/usr/sbin', '/sbin'];
    }

    $pathEnv = getenv('PATH');
    if (is_string($pathEnv) && $pathEnv !== '') {
        foreach (explode(PATH_SEPARATOR, $pathEnv) as $item) {
            if ($item !== '') {
                $paths[] = $item;
            }
        }
    }

    foreach ($names as $candidateName) {
        foreach ($paths as $path) {
            $candidate = rtrim($path, '\\/') . DIRECTORY_SEPARATOR . $candidateName;
            if (is_file($candidate) && ($isWindows || is_executable($candidate))) {
                return $candidate;
            }
        }
    }
    return null;
}

function isHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    if (envFlag('INSTALL_TRUST_PROXY', false)) {
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') {
            return true;
        }
    }
    return false;
}

function requestIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? $ip : 'unknown';
}

function ensureKey(): string
{
    $configured = envValue('INSTALL_KEY');
    if ($configured !== null) {
        return $configured;
    }

    $file = keyFile();
    if (is_file($file)) {
        $key = trim((string) @file_get_contents($file));
        if ($key !== '') {
            return $key;
        }
    }

    $key = bin2hex(random_bytes(24));
    if (!writeFileAtomic($file, $key . PHP_EOL, 0600)) {
        throw new InstallerException(
            'Не удалось создать файл ключа. Задайте ключ через переменную окружения INSTALL_KEY.'
        );
    }
    return $key;
}

function isTty(): bool
{
    if (function_exists('stream_isatty')) {
        return @stream_isatty(STDOUT);
    }
    if (function_exists('posix_isatty')) {
        return @posix_isatty(STDOUT);
    }
    return false;
}

function cliColor(string $text, string $color): string
{
    static $enabled = null;
    if ($enabled === null) {
        $enabled = isTty() && envValue('NO_COLOR') === null;
        if ($enabled && DIRECTORY_SEPARATOR === '\\' && function_exists('sapi_windows_vt100_support')) {
            $enabled = @sapi_windows_vt100_support(STDOUT, true);
        }
    }
    if (!$enabled) {
        return $text;
    }
    $codes = [
        'red' => '31',
        'green' => '32',
        'yellow' => '33',
        'blue' => '34',
        'magenta' => '35',
        'cyan' => '36',
        'gray' => '90',
        'bold' => '1',
    ];
    return "\033[" . ($codes[$color] ?? '0') . 'm' . $text . "\033[0m";
}

function textWidth(string $value): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value, 'UTF-8');
    }
    $count = preg_match_all('/./u', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

function padRight(string $value, int $width): string
{
    return $value . str_repeat(' ', max(0, $width - textWidth($value)));
}

function printTable(array $headers, array $rows): void
{
    $widths = [];
    foreach ($headers as $index => $header) {
        $widths[$index] = textWidth($header);
    }
    foreach ($rows as $row) {
        foreach ($headers as $index => $_) {
            $widths[$index] = max($widths[$index], textWidth((string) ($row[$index] ?? '')));
        }
    }
    $line = [];
    foreach ($headers as $index => $header) {
        $line[] = padRight($header, $widths[$index]);
    }
    echo implode('  ', $line) . PHP_EOL;
    $separator = [];
    foreach ($widths as $width) {
        $separator[] = str_repeat('-', $width);
    }
    echo implode('  ', $separator) . PHP_EOL;
    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $index => $_) {
            $line[] = padRight((string) ($row[$index] ?? ''), $widths[$index]);
        }
        echo implode('  ', $line) . PHP_EOL;
    }
}

function installerLog(string $message, array $context = []): void
{
    try {
        $file = logFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            ensureDir($dir);
        }
        if (is_file($file) && (int) @filesize($file) > 5 * 1024 * 1024) {
            @rename($file, $file . '.1');
        }
        $line = '[' . date('c') . '] ' . $message;
        if ($context !== []) {
            $line .= ' ' . jsonEncode($context);
        }
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // Logging must never break the installer.
    }
}

function deleteDirectory(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    $items = @scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

// ============================================================================
// Process execution
// ============================================================================

function killProcessTree(mixed $process): void
{
    $status = @proc_get_status($process);
    $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;

    if (DIRECTORY_SEPARATOR === '\\' && $pid > 0) {
        if (function_exists('exec')) {
            @exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
        } else {
            @proc_terminate($process, 9);
        }
        return;
    }

    if (function_exists('posix_kill') && $pid > 0) {
        @posix_kill($pid, 9);
    }
    @proc_terminate($process, 9);
}

/**
 * @return array{exit:int,stdout:string,stderr:string,elapsed:float,timed_out:bool}
 */
function runProcess(
    array $command,
    ?callable $onLine = null,
    ?string $cwd = null,
    array $extraEnv = [],
    int $timeout = 1800,
    ?callable $onTick = null,
    bool $rawOutput = false
): array {
    if (!function_exists('proc_open')) {
        throw new InstallerException('Функция proc_open отключена. Установка через Composer невозможна.');
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    foreach ($extraEnv as $key => $value) {
        $env[$key] = (string) $value;
    }

    $process = @proc_open($command, $descriptors, $pipes, $cwd, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new InstallerException('Не удалось запустить процесс: ' . implode(' ', $command));
    }

    @fclose($pipes[0]);
    @stream_set_blocking($pipes[1], false);
    @stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $buffers = ['out' => '', 'err' => ''];
    $start = microtime(true);
    $lastTick = 0.0;
    $timedOut = false;
    $exitCode = -1;

    $emit = static function (string $line, string $stream) use ($onLine, &$stdout, &$stderr): void {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            return;
        }
        $line = stripAnsi($line);
        if (strlen($line) > 4000) {
            $line = substr($line, 0, 4000) . '...';
        }
        if ($stream === 'err') {
            appendLimited($stderr, $line . PHP_EOL);
        } else {
            appendLimited($stdout, $line . PHP_EOL);
        }
        if ($onLine !== null) {
            $onLine($line, $stream);
        }
    };

    $handleChunk = static function (string $chunk, string $stream) use (&$buffers, $emit, $rawOutput, &$stdout, &$stderr): void {
        if ($rawOutput) {
            if ($stream === 'err') {
                appendLimited($stderr, $chunk, 8_000_000);
            } else {
                appendLimited($stdout, $chunk, 8_000_000);
            }
            return;
        }
        $buffers[$stream] .= $chunk;
        while (($position = strpos($buffers[$stream], "\n")) !== false) {
            $line = substr($buffers[$stream], 0, $position);
            $buffers[$stream] = substr($buffers[$stream], $position + 1);
            foreach (preg_split('/\r/', $line) ?: [] as $part) {
                $emit($part, $stream);
            }
        }
        while (($position = strpos($buffers[$stream], "\r")) !== false) {
            $line = substr($buffers[$stream], 0, $position);
            $buffers[$stream] = substr($buffers[$stream], $position + 1);
            $emit($line, $stream);
        }
        if (strlen($buffers[$stream]) > 65536) {
            $emit(substr($buffers[$stream], 0, 65536), $stream);
            $buffers[$stream] = substr($buffers[$stream], 65536);
        }
    };

    while (true) {
        $read = [];
        if (is_resource($pipes[1]) && !feof($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (is_resource($pipes[2]) && !feof($pipes[2])) {
            $read[] = $pipes[2];
        }

        if ($read !== []) {
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 1);
            if (is_int($ready) && $ready > 0) {
                foreach ($read as $pipe) {
                    $chunk = @fread($pipe, 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $handleChunk($chunk, $pipe === $pipes[1] ? 'out' : 'err');
                    }
                }
            }
        } else {
            usleep(100000);
        }

        $status = @proc_get_status($process);
        if (!is_array($status) || empty($status['running'])) {
            $exitCode = is_array($status) ? (int) ($status['exitcode'] ?? -1) : -1;
            break;
        }

        if (microtime(true) - $start > $timeout) {
            $timedOut = true;
            killProcessTree($process);
            break;
        }

        if ($onTick !== null && microtime(true) - $lastTick >= 5) {
            $lastTick = microtime(true);
            $onTick();
        }
    }

    foreach ([1, 2] as $index) {
        if (!is_resource($pipes[$index])) {
            continue;
        }
        $chunk = @stream_get_contents($pipes[$index]);
        if (is_string($chunk) && $chunk !== '') {
            $handleChunk($chunk, $index === 1 ? 'out' : 'err');
        }
        @fclose($pipes[$index]);
    }

    foreach (['out', 'err'] as $stream) {
        if ($buffers[$stream] !== '') {
            $emit($buffers[$stream], $stream);
            $buffers[$stream] = '';
        }
    }

    $closeCode = @proc_close($process);
    if ($exitCode === -1 && is_int($closeCode)) {
        $exitCode = $closeCode;
    }

    return [
        'exit' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'elapsed' => microtime(true) - $start,
        'timed_out' => $timedOut,
    ];
}

// ============================================================================
// HTTP client
// ============================================================================

/**
 * @return array{ok:bool,body:string,status:int,error:string,via:string}
 */
function httpGet(string $url, int $timeout = 25): array
{
    $errors = [];

    if (extension_loaded('curl')) {
        $result = httpGetCurlExtension($url, $timeout);
        if ($result['ok']) {
            return $result;
        }
        $errors[] = 'curl: ' . $result['error'];
    }

    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        if (!str_starts_with($url, 'https://') || extension_loaded('openssl')) {
            $result = httpGetStreams($url, $timeout);
            if ($result['ok']) {
                return $result;
            }
            $errors[] = 'streams: ' . $result['error'];
        }
    }

    $curl = findExecutable('curl');
    if ($curl !== null) {
        $result = httpGetSystemCurl($curl, $url, $timeout);
        if ($result['ok']) {
            return $result;
        }
        $errors[] = 'system curl: ' . $result['error'];
    }

    $wget = findExecutable('wget');
    if ($wget !== null) {
        $result = httpGetWget($wget, $url, $timeout);
        if ($result['ok']) {
            return $result;
        }
        $errors[] = 'wget: ' . $result['error'];
    }

    return [
        'ok' => false,
        'body' => '',
        'status' => 0,
        'error' => $errors !== [] ? implode('; ', $errors) : 'Нет доступного HTTP-клиента',
        'via' => 'none',
    ];
}

/**
 * @return array{ok:bool,body:string,status:int,error:string,via:string}
 */
function httpGetCurlExtension(string $url, int $timeout): array
{
    $handle = @curl_init($url);
    if ($handle === false) {
        return ['ok' => false, 'body' => '', 'status' => 0, 'error' => 'curl_init failed', 'via' => 'curl'];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => !insecureTls(),
        CURLOPT_SSL_VERIFYHOST => insecureTls() ? 0 : 2,
    ];
    if (caFile() !== null) {
        $options[CURLOPT_CAINFO] = caFile();
    }
    @curl_setopt_array($handle, $options);

    $body = @curl_exec($handle);
    $errno = @curl_errno($handle);
    $error = @curl_error($handle);
    $status = (int) @curl_getinfo($handle, CURLINFO_HTTP_CODE);
    @curl_close($handle);

    if ($body !== false && $status >= 200 && $status < 300) {
        return ['ok' => true, 'body' => (string) $body, 'status' => $status, 'error' => '', 'via' => 'curl'];
    }

    return [
        'ok' => false,
        'body' => is_string($body) ? $body : '',
        'status' => $status,
        'error' => $error !== '' ? $error : ('HTTP ' . $status . ($errno !== 0 ? ' / errno ' . $errno : '')),
        'via' => 'curl',
    ];
}

/**
 * @return array{ok:bool,body:string,status:int,error:string,via:string}
 */
function httpGetStreams(string $url, int $timeout): array
{
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => 'User-Agent: ' . USER_AGENT . "\r\nAccept: application/json\r\n",
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
        ],
        'ssl' => [
            'verify_peer' => !insecureTls(),
            'verify_peer_name' => !insecureTls(),
        ],
    ];
    if (caFile() !== null) {
        $options['ssl']['cafile'] = caFile();
    }

    $context = stream_context_create($options);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches)) {
            $status = (int) $matches[1];
            break;
        }
    }

    if ($body !== false && $status >= 200 && $status < 300) {
        return ['ok' => true, 'body' => $body, 'status' => $status, 'error' => '', 'via' => 'streams'];
    }

    $lastError = error_get_last();
    return [
        'ok' => false,
        'body' => is_string($body) ? $body : '',
        'status' => $status,
        'error' => $lastError['message'] ?? ('HTTP ' . $status),
        'via' => 'streams',
    ];
}

/**
 * @return array{ok:bool,body:string,status:int,error:string,via:string}
 */
function httpGetSystemCurl(string $curl, string $url, int $timeout): array
{
    $command = [
        $curl,
        '-sS',
        '-L',
        '--max-time', (string) $timeout,
        '--connect-timeout', '10',
        '--compressed',
        '-A', USER_AGENT,
        '-H', 'Accept: application/json',
    ];
    if (insecureTls()) {
        $command[] = '-k';
    }
    $command[] = '--url';
    $command[] = $url;

    $result = runProcess($command, null, null, [], $timeout + 5, null, true);
    if ($result['exit'] === 0 && $result['stdout'] !== '') {
        return ['ok' => true, 'body' => $result['stdout'], 'status' => 200, 'error' => '', 'via' => 'system-curl'];
    }

    return [
        'ok' => false,
        'body' => '',
        'status' => 0,
        'error' => trim($result['stderr']) !== '' ? trim($result['stderr']) : ('curl exit ' . $result['exit']),
        'via' => 'system-curl',
    ];
}

/**
 * @return array{ok:bool,body:string,status:int,error:string,via:string}
 */
function httpGetWget(string $wget, string $url, int $timeout): array
{
    $command = [$wget, '-qO-', '--timeout=' . $timeout, '--user-agent=' . USER_AGENT];
    if (insecureTls()) {
        $command[] = '--no-check-certificate';
    }
    $command[] = $url;

    $result = runProcess($command, null, null, [], $timeout + 5, null, true);
    if ($result['exit'] === 0 && $result['stdout'] !== '') {
        return ['ok' => true, 'body' => $result['stdout'], 'status' => 200, 'error' => '', 'via' => 'wget'];
    }

    return [
        'ok' => false,
        'body' => '',
        'status' => 0,
        'error' => trim($result['stderr']) !== '' ? trim($result['stderr']) : ('wget exit ' . $result['exit']),
        'via' => 'wget',
    ];
}

/**
 * @return array{ok:bool,data:array,error:string,via:string,status:int}
 */
function httpGetJson(string $url, int $timeout = 25): array
{
    $result = httpGet($url, $timeout);
    if (!$result['ok']) {
        return [
            'ok' => false,
            'data' => [],
            'error' => $result['error'],
            'via' => $result['via'],
            'status' => $result['status'],
        ];
    }

    $data = json_decode($result['body'], true);
    if (!is_array($data)) {
        return [
            'ok' => false,
            'data' => [],
            'error' => 'Некорректный JSON от ' . $url,
            'via' => $result['via'],
            'status' => $result['status'],
        ];
    }

    return [
        'ok' => true,
        'data' => $data,
        'error' => '',
        'via' => $result['via'],
        'status' => $result['status'],
    ];
}

// ============================================================================
// Cache
// ============================================================================

function cacheFile(string $key): string
{
    return cacheDir() . '/' . sha1($key) . '.json';
}

/**
 * @return array{data:array,stale:bool,age:int}|null
 */
function cacheGet(string $key, ?int $ttl = null): ?array
{
    $file = cacheFile($key);
    $payload = readJsonFile($file, 16_000_000);
    if ($payload === null || !isset($payload['time'], $payload['data']) || !is_array($payload['data'])) {
        return null;
    }

    $age = time() - (int) $payload['time'];
    $ttl = $ttl ?? cacheTtl();
    if ($age <= $ttl) {
        return ['data' => $payload['data'], 'stale' => false, 'age' => $age];
    }
    if ($age <= 7 * 86400) {
        return ['data' => $payload['data'], 'stale' => true, 'age' => $age];
    }
    return null;
}

function cachePut(string $key, array $data): void
{
    $payload = ['time' => time(), 'data' => $data];
    writeFileAtomic(cacheFile($key), jsonEncode($payload), 0600);
}

function cacheForget(string $key): void
{
    $file = cacheFile($key);
    if (is_file($file)) {
        @unlink($file);
    }
}

function clearCache(): int
{
    $count = 0;
    foreach (glob(cacheDir() . '/*.json') ?: [] as $file) {
        if (@unlink($file)) {
            $count++;
        }
    }
    return $count;
}

// ============================================================================
// Version helpers
// ============================================================================

function normalizeVersion(string $version): string
{
    $version = trim($version);
    $version = preg_replace('/^[vV]/', '', $version) ?? $version;
    $version = preg_replace('/-p(\d+)$/i', '.$1', $version) ?? $version;
    return $version;
}

function isStableVersion(string $version): bool
{
    $version = preg_replace('/^[vV]/', '', trim($version)) ?? $version;
    return (bool) preg_match('/^\d+(\.\d+){1,3}(-p\d+)?$/i', $version);
}

function compareVersions(string $a, string $b): int
{
    return version_compare(normalizeVersion($a), normalizeVersion($b));
}

function isVersionLike(string $value): bool
{
    return (bool) preg_match('/^v?\d+(\.\d+){0,3}(-p\d+)?$/i', trim($value));
}

// ============================================================================
// Composer-style PHP constraint matching
// ============================================================================

function phpSatisfies(?string $constraint, ?string $phpVersion = null): bool
{
    $constraint = trim((string) $constraint);
    if ($constraint === '' || $constraint === '*' || strtolower($constraint) === 'x') {
        return true;
    }

    $phpVersion = $phpVersion ?? PHP_VERSION;
    $alternatives = preg_split('/\s*\|\|\s*|\s*\|\s*/', $constraint) ?: [];
    foreach ($alternatives as $group) {
        $group = trim($group);
        if ($group !== '' && constraintGroupMatches($group, $phpVersion)) {
            return true;
        }
    }
    return false;
}

function constraintGroupMatches(string $group, string $phpVersion): bool
{
    $group = trim($group);
    if ($group === '') {
        return true;
    }

    if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $group, $matches)
        && isVersionLike($matches[1])
        && isVersionLike($matches[2])
    ) {
        $lower = $matches[1];
        $upper = $matches[2];
        if (compareVersions($phpVersion, $lower) < 0) {
            return false;
        }
        $upperParts = explode('.', normalizeVersion($upper));
        if (count($upperParts) >= 3) {
            return compareVersions($phpVersion, $upper) <= 0;
        }
        return compareVersions($phpVersion, rangeUpperExclusive($upper)) < 0;
    }

    $group = preg_replace('/(>=|<=|>|<|!=|==|=|\^|~)\s+/', '$1', $group) ?? $group;
    $tokens = preg_split('/[\s,]+/', $group, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($tokens === []) {
        return true;
    }

    foreach ($tokens as $token) {
        if (!constraintTokenMatches($token, $phpVersion)) {
            return false;
        }
    }
    return true;
}

function rangeUpperExclusive(string $version): string
{
    $parts = explode('.', normalizeVersion($version));
    $major = (int) ($parts[0] ?? 0);
    $minor = (int) ($parts[1] ?? 0);
    if (count($parts) === 1) {
        return ($major + 1) . '.0.0';
    }
    return $major . '.' . ($minor + 1) . '.0';
}

function constraintTokenMatches(string $token, string $phpVersion): bool
{
    $token = trim($token);
    if ($token === '' || $token === '*' || strtolower($token) === 'x') {
        return true;
    }

    if (preg_match('/^(\^|~)(.+)$/', $token, $matches)) {
        $base = normalizeVersion($matches[2]);
        $parts = array_map('intval', explode('.', $base));
        $major = $parts[0] ?? 0;
        $minor = $parts[1] ?? 0;
        $patch = $parts[2] ?? 0;

        if ($matches[1] === '^') {
            if ($major > 0) {
                $upper = ($major + 1) . '.0.0';
            } elseif ($minor > 0) {
                $upper = '0.' . ($minor + 1) . '.0';
            } else {
                $upper = '0.0.' . ($patch + 1);
            }
        } else {
            if (count($parts) >= 3) {
                $upper = $major . '.' . ($minor + 1) . '.0';
            } else {
                $upper = ($major + 1) . '.0.0';
            }
        }

        return compareVersions($phpVersion, $base) >= 0
            && compareVersions($phpVersion, $upper) < 0;
    }

    if (preg_match('/^(>=|<=|>|<|!=|==|=)(.+)$/', $token, $matches)) {
        $comparison = compareVersions($phpVersion, normalizeVersion($matches[2]));
        return match ($matches[1]) {
            '>=' => $comparison >= 0,
            '<=' => $comparison <= 0,
            '>' => $comparison > 0,
            '<' => $comparison < 0,
            '!=' => $comparison !== 0,
            '==', '=' => $comparison === 0,
            default => true,
        };
    }

    if (str_contains($token, '*') || preg_match('/^[0-9]+(\.[0-9]+)*\.[xX]$/', $token)) {
        $clean = strtolower(str_replace('*', 'x', $token));
        $parts = explode('.', $clean);
        $numeric = [];
        foreach ($parts as $part) {
            if ($part === 'x') {
                break;
            }
            if (!is_numeric($part)) {
                return true;
            }
            $numeric[] = (int) $part;
        }
        if ($numeric === []) {
            return true;
        }

        $count = count($numeric);
        if ($count === 1) {
            $lower = $numeric[0] . '.0.0';
            $upper = ($numeric[0] + 1) . '.0.0';
        } elseif ($count === 2) {
            $lower = $numeric[0] . '.' . $numeric[1] . '.0';
            $upper = $numeric[0] . '.' . ($numeric[1] + 1) . '.0';
        } else {
            $lower = implode('.', $numeric);
            $upperParts = $numeric;
            $last = array_pop($upperParts);
            $upper = implode('.', $upperParts) . '.' . ($last + 1);
        }

        return compareVersions($phpVersion, $lower) >= 0
            && compareVersions($phpVersion, $upper) < 0;
    }

    return compareVersions($phpVersion, normalizeVersion($token)) === 0;
}

// ============================================================================
// Packagist metadata
// ============================================================================

function expandMinifiedMetadata(array $versions): array
{
    $expanded = [];
    $previous = null;
    foreach ($versions as $version) {
        if (!is_array($version)) {
            continue;
        }
        if ($previous !== null) {
            $version = array_merge($previous, $version);
        }
        $previous = $version;
        $expanded[] = $version;
    }
    return $expanded;
}

/**
 * @return array{ok:bool,versions:array,error:string,stale:bool,cached:bool,age:int,via:string,warning:string}
 */
function fetchPackageMetadata(string $package, bool $force = false): array
{
    $key = 'p2:' . strtolower($package);
    if (!$force) {
        $cached = cacheGet($key);
        if ($cached !== null) {
            return [
                'ok' => true,
                'versions' => $cached['data'],
                'error' => '',
                'stale' => $cached['stale'],
                'cached' => true,
                'age' => $cached['age'],
                'via' => 'cache',
                'warning' => $cached['stale'] ? 'Используются устаревшие данные из кэша.' : '',
            ];
        }
    }

    $url = sprintf(PACKAGIST_P2, strtolower($package));
    $response = httpGetJson($url, 30);
    if (!$response['ok']) {
        $stale = cacheGet($key, 7 * 86400);
        if ($stale !== null) {
            return [
                'ok' => true,
                'versions' => $stale['data'],
                'error' => '',
                'stale' => true,
                'cached' => true,
                'age' => $stale['age'],
                'via' => 'cache',
                'warning' => 'Packagist недоступен: ' . $response['error'],
            ];
        }
        return [
            'ok' => false,
            'versions' => [],
            'error' => $response['error'],
            'stale' => false,
            'cached' => false,
            'age' => 0,
            'via' => $response['via'],
            'warning' => '',
        ];
    }

    $data = $response['data'];
    $packageKey = strtolower($package);
    $rawVersions = $data['packages'][$packageKey] ?? $data['packages'][$package] ?? null;
    if (!is_array($rawVersions)) {
        return [
            'ok' => false,
            'versions' => [],
            'error' => 'Пакет не найден в Packagist: ' . $package,
            'stale' => false,
            'cached' => false,
            'age' => 0,
            'via' => $response['via'],
            'warning' => '',
        ];
    }

    $versions = expandMinifiedMetadata($rawVersions);
    cachePut($key, $versions);

    return [
        'ok' => true,
        'versions' => $versions,
        'error' => '',
        'stale' => false,
        'cached' => false,
        'age' => 0,
        'via' => $response['via'],
        'warning' => '',
    ];
}

function platformIssuesFromData(array $version, ?string $phpVersion = null): array
{
    $issues = [];
    $require = is_array($version['require'] ?? null) ? $version['require'] : [];
    $phpRequirement = $require['php'] ?? null;
    $phpVersion = $phpVersion ?? PHP_VERSION;

    if (is_string($phpRequirement) && $phpRequirement !== ''
        && !phpSatisfies($phpRequirement, $phpVersion)
    ) {
        $issues[] = 'Требуется PHP ' . $phpRequirement . ' (установлен ' . $phpVersion . ')';
    }

    foreach ($require as $dependency => $constraint) {
        if (!is_string($dependency) || !str_starts_with($dependency, 'ext-')) {
            continue;
        }
        $extension = substr($dependency, 4);
        if ($extension !== '' && !extension_loaded($extension)) {
            $issues[] = 'Не включено расширение ' . $extension;
        }
    }

    return $issues;
}

/**
 * @return array<int,array<string,mixed>>
 */
function parsePackageVersions(array $rawVersions): array
{
    $seen = [];
    $versions = [];

    foreach ($rawVersions as $version) {
        if (!is_array($version)) {
            continue;
        }
        $pretty = trim((string) ($version['version'] ?? ''));
        if ($pretty === '' || str_starts_with($pretty, 'dev-')) {
            continue;
        }
        $key = strtolower($pretty);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $versions[] = [
            'version' => $pretty,
            'normalized' => normalizeVersion($pretty),
            'stable' => isStableVersion($pretty),
            'time' => isset($version['time']) ? (string) $version['time'] : null,
            'php' => isset($version['require']['php']) ? (string) $version['require']['php'] : null,
            'type' => isset($version['type']) ? (string) $version['type'] : null,
            'issues' => platformIssuesFromData($version),
            'require' => is_array($version['require'] ?? null) ? $version['require'] : [],
        ];
    }

    usort(
        $versions,
        static fn (array $a, array $b): int => compareVersions($b['normalized'], $a['normalized'])
    );

    return $versions;
}

function versionBranchKey(string $version): string
{
    $parts = explode('.', normalizeVersion($version));
    $major = (int) ($parts[0] ?? 0);
    if ($major > 0) {
        return (string) $major;
    }
    return $major . '.' . (int) ($parts[1] ?? 0);
}

/**
 * @return array<int,array<string,mixed>>
 */
function versionBranches(array $versions, int $limit = 12): array
{
    $branches = [];
    foreach ($versions as $version) {
        if (!$version['stable']) {
            continue;
        }
        $key = versionBranchKey($version['version']);
        if (!isset($branches[$key])) {
            $branches[$key] = [
                'branch' => $key,
                'version' => $version['version'],
                'php' => $version['php'],
                'time' => $version['time'],
            ];
        }
    }

    $result = array_values($branches);
    usort(
        $result,
        static fn (array $a, array $b): int => compareVersions($b['version'], $a['version'])
    );
    return array_slice($result, 0, $limit);
}

/**
 * @return array{ok:bool,package:string,error:string,versions:array,latest:?array,latest_compatible:?array,latest_any:?array,branches:array,stale:bool,cached:bool,warning:string,via:string}
 */
function getPackageVersions(string $package, bool $force = false): array
{
    $package = strtolower(trim($package));
    $metadata = fetchPackageMetadata($package, $force);
    if (!$metadata['ok']) {
        return [
            'ok' => false,
            'package' => $package,
            'error' => $metadata['error'],
            'versions' => [],
            'latest' => null,
            'latest_compatible' => null,
            'latest_any' => null,
            'branches' => [],
            'stale' => false,
            'cached' => false,
            'warning' => '',
            'via' => $metadata['via'],
        ];
    }

    $versions = parsePackageVersions($metadata['versions']);
    $latest = null;
    $latestCompatible = null;
    $latestAny = $versions[0] ?? null;

    foreach ($versions as $version) {
        if (!$version['stable']) {
            continue;
        }
        if ($latest === null) {
            $latest = $version;
        }
        if ($latestCompatible === null && phpSatisfies($version['php'], PHP_VERSION)) {
            $latestCompatible = $version;
        }
        if ($latest !== null && $latestCompatible !== null) {
            break;
        }
    }

    if ($latest === null) {
        $latest = $latestAny;
    }
    if ($latestCompatible === null) {
        $latestCompatible = $latest;
    }

    return [
        'ok' => true,
        'package' => $package,
        'error' => '',
        'versions' => $versions,
        'latest' => $latest,
        'latest_compatible' => $latestCompatible,
        'latest_any' => $latestAny,
        'branches' => versionBranches($versions),
        'stale' => $metadata['stale'],
        'cached' => $metadata['cached'],
        'warning' => $metadata['warning'],
        'via' => $metadata['via'],
    ];
}

/**
 * @return array{ok:bool,package:string,error:string,latest:?array,latest_compatible:?array,latest_any:?array,branches:array,versions_count:int,stale:bool,cached:bool,warning:string,updated_at:string}
 */
function getPackageSummary(string $package, bool $force = false): array
{
    $package = strtolower(trim($package));
    $key = 'summary:' . $package;

    if (!$force) {
        $cached = cacheGet($key);
        if ($cached !== null) {
            return array_merge(
                ['ok' => true, 'package' => $package, 'error' => '', 'cached' => true, 'stale' => $cached['stale']],
                $cached['data']
            );
        }
    }

    $full = getPackageVersions($package, $force);
    if (!$full['ok']) {
        return [
            'ok' => false,
            'package' => $package,
            'error' => $full['error'],
            'latest' => null,
            'latest_compatible' => null,
            'latest_any' => null,
            'branches' => [],
            'versions_count' => 0,
            'stale' => false,
            'cached' => false,
            'warning' => '',
            'updated_at' => date('c'),
        ];
    }

    $summary = [
        'latest' => $full['latest'],
        'latest_compatible' => $full['latest_compatible'],
        'latest_any' => $full['latest_any'],
        'branches' => $full['branches'],
        'versions_count' => count($full['versions']),
        'stale' => $full['stale'],
        'warning' => $full['warning'],
        'updated_at' => date('c'),
    ];
    cachePut($key, $summary);

    return array_merge(
        ['ok' => true, 'package' => $package, 'error' => '', 'cached' => false],
        $summary
    );
}

// ============================================================================
// Framework catalog
// ============================================================================

/**
 * @return array<int,array<string,mixed>>
 */
function frameworkCatalog(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [
        [
            'id' => 'laravel',
            'name' => 'Laravel',
            'package' => 'laravel/laravel',
            'core_package' => 'laravel/framework',
            'description' => 'Полнофункциональный фреймворк с Eloquent, Blade, очередями и большой экосистемой.',
            'homepage' => 'https://laravel.com',
            'tags' => ['Full-stack', 'MVC'],
            'color' => '#ff2d20',
            'monogram' => 'L',
            'target' => 'laravel-app',
            'weight' => 100,
        ],
        [
            'id' => 'symfony',
            'name' => 'Symfony',
            'package' => 'symfony/skeleton',
            'core_package' => 'symfony/framework-bundle',
            'description' => 'Модульный фреймворк и набор компонентов для крупных приложений.',
            'homepage' => 'https://symfony.com',
            'tags' => ['Full-stack', 'Components'],
            'color' => '#7b68ee',
            'monogram' => 'S',
            'target' => 'symfony-app',
            'weight' => 95,
            'note' => 'Устанавливается минимальный skeleton; компоненты добавляются через composer require.',
        ],
        [
            'id' => 'codeigniter',
            'name' => 'CodeIgniter 4',
            'package' => 'codeigniter4/appstarter',
            'core_package' => 'codeigniter4/framework',
            'description' => 'Лёгкий MVC-фреймворк с минимальной конфигурацией и высокой скоростью.',
            'homepage' => 'https://codeigniter.com',
            'tags' => ['MVC', 'Lightweight'],
            'color' => '#ef4223',
            'monogram' => 'CI',
            'target' => 'codeigniter-app',
            'weight' => 90,
        ],
        [
            'id' => 'yii2-basic',
            'name' => 'Yii 2 Basic',
            'package' => 'yiisoft/yii2-app-basic',
            'core_package' => 'yiisoft/yii2',
            'description' => 'Быстрый MVC-фреймворк для небольших приложений и внутренних систем.',
            'homepage' => 'https://www.yiiframework.com',
            'tags' => ['MVC', 'Basic'],
            'color' => '#1e6887',
            'monogram' => 'Y2',
            'target' => 'yii2-basic',
            'weight' => 85,
        ],
        [
            'id' => 'yii2-advanced',
            'name' => 'Yii 2 Advanced',
            'package' => 'yiisoft/yii2-app-advanced',
            'core_package' => 'yiisoft/yii2',
            'description' => 'Шаблон Yii 2 с разделением на frontend, backend и console.',
            'homepage' => 'https://www.yiiframework.com',
            'tags' => ['MVC', 'Advanced'],
            'color' => '#1e6887',
            'monogram' => 'Y2',
            'target' => 'yii2-advanced',
            'weight' => 84,
        ],
        [
            'id' => 'yii3',
            'name' => 'Yii 3',
            'package' => 'yiisoft/app',
            'core_package' => 'yiisoft/yii3',
            'description' => 'Современная модульная версия Yii с PSR-совместимыми компонентами.',
            'homepage' => 'https://www.yiiframework.com',
            'tags' => ['MVC', 'PSR'],
            'color' => '#2b7bba',
            'monogram' => 'Y3',
            'target' => 'yii3-app',
            'weight' => 60,
        ],
        [
            'id' => 'cakephp',
            'name' => 'CakePHP',
            'package' => 'cakephp/app',
            'core_package' => 'cakephp/cakephp',
            'description' => 'MVC-фреймворк с соглашениями, ORM и быстрым стартом.',
            'homepage' => 'https://cakephp.org',
            'tags' => ['MVC', 'ORM'],
            'color' => '#d33c43',
            'monogram' => 'C',
            'target' => 'cakephp-app',
            'weight' => 80,
        ],
        [
            'id' => 'slim',
            'name' => 'Slim 4',
            'package' => 'slim/slim-skeleton',
            'core_package' => 'slim/slim',
            'description' => 'Микрофреймворк для REST API, middleware и небольших сервисов.',
            'homepage' => 'https://www.slimframework.com',
            'tags' => ['Micro', 'API'],
            'color' => '#6c5ce7',
            'monogram' => 'SL',
            'target' => 'slim-app',
            'weight' => 75,
        ],
        [
            'id' => 'api-platform',
            'name' => 'API Platform',
            'package' => 'api-platform/api-platform',
            'core_package' => 'api-platform/core',
            'description' => 'API-first фреймворк на Symfony для REST и GraphQL.',
            'homepage' => 'https://api-platform.com',
            'tags' => ['API', 'Symfony'],
            'color' => '#1d9bf0',
            'monogram' => 'AP',
            'target' => 'api-platform-app',
            'weight' => 72,
            'note' => 'Официальный CLI рекомендует отдельный сценарий; create-project устанавливает шаблон.',
        ],
        [
            'id' => 'laminas',
            'name' => 'Laminas MVC',
            'package' => 'laminas/laminas-mvc-skeleton',
            'core_package' => 'laminas/laminas-mvc',
            'description' => 'Корпоративный MVC-фреймворк, бывший Zend Framework.',
            'homepage' => 'https://getlaminas.org',
            'tags' => ['MVC', 'Enterprise'],
            'color' => '#4b8bbe',
            'monogram' => 'LM',
            'target' => 'laminas-app',
            'weight' => 70,
        ],
        [
            'id' => 'mezzio',
            'name' => 'Mezzio',
            'package' => 'mezzio/mezzio-skeleton',
            'core_package' => 'mezzio/mezzio',
            'description' => 'PSR-15 микрофреймворк для middleware-приложений.',
            'homepage' => 'https://docs.mezzio.dev',
            'tags' => ['Micro', 'PSR-15'],
            'color' => '#00a3a3',
            'monogram' => 'MZ',
            'target' => 'mezzio-app',
            'weight' => 65,
        ],
        [
            'id' => 'nette',
            'name' => 'Nette',
            'package' => 'nette/web-project',
            'core_package' => 'nette/application',
            'description' => 'Чешский фреймворк с Latte, Tracy и мощным DI-контейнером.',
            'homepage' => 'https://nette.org',
            'tags' => ['MVC', 'Latte'],
            'color' => '#3484d2',
            'monogram' => 'N',
            'target' => 'nette-app',
            'weight' => 62,
        ],
        [
            'id' => 'spiral',
            'name' => 'Spiral',
            'package' => 'spiral/app',
            'core_package' => 'spiral/framework',
            'description' => 'Фреймворк с long-running воркерами и интеграцией RoadRunner.',
            'homepage' => 'https://spiral.dev',
            'tags' => ['Full-stack', 'RoadRunner'],
            'color' => '#6b4eff',
            'monogram' => 'SP',
            'target' => 'spiral-app',
            'weight' => 58,
        ],
        [
            'id' => 'flight',
            'name' => 'Flight',
            'package' => 'flightphp/skeleton',
            'core_package' => 'flightphp/core',
            'description' => 'Минималистичный микрофреймворк с простым роутингом.',
            'homepage' => 'https://flightphp.com',
            'tags' => ['Micro', 'API'],
            'color' => '#00a86b',
            'monogram' => 'F',
            'target' => 'flight-app',
            'weight' => 55,
        ],
        [
            'id' => 'fatfree',
            'name' => 'Fat-Free',
            'package' => 'bcosca/fatfree',
            'core_package' => 'bcosca/fatfree-core',
            'description' => 'Компактный микрофреймворк для быстрых приложений.',
            'homepage' => 'https://fatfreeframework.com',
            'tags' => ['Micro', 'Lightweight'],
            'color' => '#f5a623',
            'monogram' => 'FF',
            'target' => 'fatfree-app',
            'weight' => 50,
        ],
        [
            'id' => 'drupal',
            'name' => 'Drupal',
            'package' => 'drupal/recommended-project',
            'core_package' => 'drupal/core',
            'description' => 'CMF/CMS для сложных сайтов, порталов и корпоративных решений.',
            'homepage' => 'https://www.drupal.org',
            'tags' => ['CMS', 'CMF'],
            'color' => '#0678be',
            'monogram' => 'D',
            'target' => 'drupal-app',
            'weight' => 45,
        ],
        [
            'id' => 'craft',
            'name' => 'Craft CMS',
            'package' => 'craftcms/craft',
            'core_package' => 'craftcms/cms',
            'description' => 'Коммерческая CMS с гибким управлением контентом.',
            'homepage' => 'https://craftcms.com',
            'tags' => ['CMS'],
            'color' => '#e5422b',
            'monogram' => 'CR',
            'target' => 'craft-app',
            'weight' => 40,
        ],
        [
            'id' => 'statamic',
            'name' => 'Statamic',
            'package' => 'statamic/statamic',
            'core_package' => 'statamic/cms',
            'description' => 'Flat-file CMS на Laravel с современной админкой.',
            'homepage' => 'https://statamic.com',
            'tags' => ['CMS', 'Laravel'],
            'color' => '#3b82f6',
            'monogram' => 'ST',
            'target' => 'statamic-app',
            'weight' => 38,
        ],
        [
            'id' => 'contao',
            'name' => 'Contao',
            'package' => 'contao/managed-edition',
            'core_package' => 'contao/core-bundle',
            'description' => 'CMS с мощным управлением контентом и структурой сайта.',
            'homepage' => 'https://contao.org',
            'tags' => ['CMS'],
            'color' => '#f47c00',
            'monogram' => 'CO',
            'target' => 'contao-app',
            'weight' => 32,
        ],
        [
            'id' => 'typo3',
            'name' => 'TYPO3',
            'package' => 'typo3/cms-base-distribution',
            'core_package' => 'typo3/cms-core',
            'description' => 'Корпоративная CMS для крупных сайтов и многоязычных порталов.',
            'homepage' => 'https://typo3.org',
            'tags' => ['CMS', 'Enterprise'],
            'color' => '#ff8700',
            'monogram' => 'T3',
            'target' => 'typo3-app',
            'weight' => 28,
        ],
        [
            'id' => 'shopware',
            'name' => 'Shopware 6',
            'package' => 'shopware/production',
            'core_package' => 'shopware/core',
            'description' => 'E-commerce платформа для интернет-магазинов и маркетплейсов.',
            'homepage' => 'https://www.shopware.com',
            'tags' => ['E-commerce'],
            'color' => '#189eff',
            'monogram' => 'SW',
            'target' => 'shopware-app',
            'weight' => 22,
            'note' => 'Для запуска потребуются база данных и дополнительные сервисы.',
        ],
        [
            'id' => 'pimcore',
            'name' => 'Pimcore',
            'package' => 'pimcore/skeleton',
            'core_package' => 'pimcore/pimcore',
            'description' => 'PIM/DAM/MDM платформа на Symfony для управления данными.',
            'homepage' => 'https://pimcore.com',
            'tags' => ['PIM', 'Symfony'],
            'color' => '#ff5a00',
            'monogram' => 'PC',
            'target' => 'pimcore-app',
            'weight' => 18,
            'note' => 'Требуются база данных и несколько PHP-расширений.',
        ],
        [
            'id' => 'magento',
            'name' => 'Magento 2',
            'package' => 'magento/project-community-edition',
            'core_package' => 'magento/framework',
            'description' => 'Тяжёлая e-commerce платформа с большим набором модулей.',
            'homepage' => 'https://magento.com',
            'tags' => ['E-commerce', 'Enterprise'],
            'color' => '#f26322',
            'monogram' => 'M2',
            'target' => 'magento-app',
            'weight' => 12,
            'note' => 'Установка может занять 10–30 минут и несколько гигабайт; нужны MySQL/Elasticsearch.',
            'heavy' => true,
        ],
    ];

    usort(
        $catalog,
        static fn (array $a, array $b): int => ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0)
    );
    return $catalog;
}

function findCatalogFramework(string $idOrPackage): ?array
{
    $needle = strtolower(trim($idOrPackage));
    foreach (frameworkCatalog() as $framework) {
        if ($framework['id'] === $needle || strtolower($framework['package']) === $needle) {
            return $framework;
        }
    }
    return null;
}

function findFramework(string $idOrPackage): ?array
{
    $catalog = findCatalogFramework($idOrPackage);
    if ($catalog !== null) {
        return $catalog;
    }

    $package = strtolower(trim($idOrPackage));
    if (!validatePackageName($package)) {
        return null;
    }

    return [
        'id' => 'custom',
        'name' => $package,
        'package' => $package,
        'core_package' => null,
        'description' => 'Произвольный Composer-пакет с типом project или skeleton.',
        'homepage' => 'https://packagist.org/packages/' . $package,
        'tags' => ['Composer'],
        'color' => '#64748b',
        'monogram' => strtoupper(substr($package, 0, 1)),
        'target' => slugify($package),
        'weight' => 0,
        'custom' => true,
    ];
}

function frameworkForCorePackage(string $package): ?array
{
    $map = [
        'laravel/framework' => 'laravel',
        'symfony/framework-bundle' => 'symfony',
        'codeigniter4/framework' => 'codeigniter',
        'yiisoft/yii2' => 'yii2-basic',
        'cakephp/cakephp' => 'cakephp',
        'slim/slim' => 'slim',
        'laminas/laminas-mvc' => 'laminas',
        'mezzio/mezzio' => 'mezzio',
        'nette/application' => 'nette',
        'spiral/framework' => 'spiral',
        'api-platform/core' => 'api-platform',
        'flightphp/core' => 'flight',
        'bcosca/fatfree-core' => 'fatfree',
        'drupal/core' => 'drupal',
        'craftcms/cms' => 'craft',
        'statamic/cms' => 'statamic',
        'contao/core-bundle' => 'contao',
        'typo3/cms-core' => 'typo3',
        'shopware/core' => 'shopware',
        'pimcore/pimcore' => 'pimcore',
        'magento/framework' => 'magento',
    ];

    $package = strtolower(trim($package));
    return isset($map[$package]) ? findCatalogFramework($map[$package]) : null;
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    if (str_contains($value, '/')) {
        $value = substr($value, strrpos($value, '/') + 1);
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
    $value = trim($value, '-');
    return $value !== '' ? $value : 'app';
}

// ============================================================================
// Composer integration
// ============================================================================

function phpCommand(): array
{
    static $command = null;
    if ($command !== null) {
        return $command;
    }

    $binary = PHP_BINARY;
    if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
        $candidates = [
            PHP_BINDIR . DIRECTORY_SEPARATOR . 'php',
            PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe',
            PHP_BINDIR . DIRECTORY_SEPARATOR . 'php-cli',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $binary = $candidate;
                break;
            }
        }
    }

    $command = [$binary, '-d', 'memory_limit=1024M', '-d', 'error_reporting=E_ALL'];
    $extensionDir = (string) ini_get('extension_dir');

    foreach (['openssl', 'curl', 'zip', 'mbstring', 'fileinfo'] as $extension) {
        if (extension_loaded($extension) || $extensionDir === '') {
            continue;
        }
        $dll = rtrim($extensionDir, '\\/') . DIRECTORY_SEPARATOR . 'php_' . $extension . '.dll';
        $so = rtrim($extensionDir, '\\/') . DIRECTORY_SEPARATOR . $extension . '.so';
        if (is_file($dll) || is_file($so)) {
            $command[] = '-d';
            $command[] = 'extension=' . $extension;
        }
    }

    if (caFile() !== null) {
        $command[] = '-d';
        $command[] = 'openssl.cafile=' . caFile();
        $command[] = '-d';
        $command[] = 'curl.cainfo=' . caFile();
    }

    return $command;
}

function findComposer(): ?array
{
    $configured = envValue('COMPOSER_BINARY');
    if ($configured !== null) {
        $path = is_file($configured) ? realpath($configured) : findExecutable($configured);
        if (is_string($path) && $path !== '') {
            return [
                'kind' => str_ends_with(strtolower($path), '.phar') ? 'phar' : 'bin',
                'path' => $path,
            ];
        }
    }

    $candidates = [
        toolsDir() . '/composer.phar',
        baseDir() . '/composer.phar',
        baseDir() . '/bin/composer.phar',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return ['kind' => 'phar', 'path' => $candidate];
        }
    }

    foreach (['composer', 'composer.phar', 'composer.bat'] as $name) {
        $path = findExecutable($name);
        if ($path !== null) {
            return [
                'kind' => str_ends_with(strtolower($path), '.phar') ? 'phar' : 'bin',
                'path' => $path,
            ];
        }
    }

    return null;
}

function composerInvocation(array $composer): array
{
    if ($composer['kind'] === 'phar') {
        return array_merge(phpCommand(), [$composer['path']]);
    }
    if (preg_match('/\.(bat|cmd)$/i', $composer['path'])) {
        return ['cmd', '/c', $composer['path']];
    }
    return [$composer['path']];
}

/**
 * @return array{found:bool,path:?string,kind:?string,version:?string,error:?string,cached:bool}
 */
function composerInfo(bool $refresh = false): array
{
    $key = 'composer-info';
    if (!$refresh) {
        $cached = cacheGet($key, 300);
        if ($cached !== null) {
            return array_merge($cached['data'], ['cached' => true]);
        }
    }

    $composer = findComposer();
    if ($composer === null) {
        $info = [
            'found' => false,
            'path' => null,
            'kind' => null,
            'version' => null,
            'error' => 'Composer не найден',
        ];
        cachePut($key, $info);
        return array_merge($info, ['cached' => false]);
    }

    try {
        $command = array_merge(composerInvocation($composer), ['--version', '--no-ansi']);
        $result = runProcess($command, null, baseDir(), [], 30);
        $output = trim($result['stdout'] . "\n" . $result['stderr']);
        $version = null;
        if (preg_match('/Composer version\s+([^\s]+)/i', $output, $matches)) {
            $version = $matches[1];
        }
        $info = [
            'found' => true,
            'path' => $composer['path'],
            'kind' => $composer['kind'],
            'version' => $version,
            'error' => $result['exit'] === 0 ? null : ($output !== '' ? $output : 'Composer не запускается'),
        ];
    } catch (Throwable $e) {
        $info = [
            'found' => true,
            'path' => $composer['path'],
            'kind' => $composer['kind'],
            'version' => null,
            'error' => $e->getMessage(),
        ];
    }

    cachePut($key, $info);
    return array_merge($info, ['cached' => false]);
}

function downloadComposer(?callable $log = null): string
{
    $directory = toolsDir();
    $setup = $directory . '/composer-setup.php';
    $phar = $directory . '/composer.phar';

    if ($log !== null) {
        $log('Скачивание официального установщика Composer...', 'out');
    }
    $installer = httpGet(COMPOSER_INSTALLER_URL, 60);
    if (!$installer['ok']) {
        throw new InstallerException('Не удалось скачать установщик Composer: ' . $installer['error']);
    }

    $signature = httpGet(COMPOSER_SIGNATURE_URL, 30);
    if (!$signature['ok']) {
        throw new InstallerException('Не удалось получить подпись установщика Composer: ' . $signature['error']);
    }

    $expected = strtolower(trim($signature['body']));
    if (!preg_match('/^[a-f0-9]{96}$/', $expected)) {
        throw new InstallerException('Некорректная подпись установщика Composer.');
    }

    if (@file_put_contents($setup, $installer['body'], LOCK_EX) === false) {
        throw new InstallerException('Не удалось сохранить установщик Composer в ' . $setup);
    }

    $actual = @hash_file('sha384', $setup);
    if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
        @unlink($setup);
        throw new InstallerException('Подпись установщика Composer не совпала. Загрузка отменена.');
    }

    if ($log !== null) {
        $log('Подпись Composer проверена. Установка...', 'out');
    }

    $command = array_merge(
        phpCommand(),
        [$setup, '--install-dir=' . $directory, '--filename=composer.phar', '--quiet']
    );
    $result = runProcess($command, $log, $directory, [], 300);
    @unlink($setup);

    if ($result['exit'] !== 0 || !is_file($phar)) {
        $details = trim($result['stderr'] . "\n" . $result['stdout']);
        throw new InstallerException('Не удалось установить Composer.' . ($details !== '' ? "\n" . $details : ''));
    }

    @chmod($phar, 0755);
    return $phar;
}

/**
 * @return array{found:bool,path:?string,kind:?string,version:?string,error:?string,cached:bool}
 */
function ensureComposer(?callable $log = null): array
{
    $info = composerInfo(true);
    if ($info['found'] && ($info['error'] === null || $info['version'] !== null)) {
        return $info;
    }

    if ($log !== null) {
        $log('Composer не найден. Выполняется автоматическая установка.', 'out');
    }

    downloadComposer($log);
    $info = composerInfo(true);
    if (!$info['found'] || $info['error'] !== null) {
        throw new InstallerException(
            'Composer установлен, но не запускается: ' . ($info['error'] ?? 'неизвестная ошибка')
        );
    }
    return $info;
}

/**
 * @return array{exit:int,stdout:string,stderr:string,elapsed:float,timed_out:bool}
 */
function runComposer(
    array $args,
    ?callable $onLine = null,
    int $timeout = 1800,
    ?callable $onTick = null
): array {
    $composer = ensureComposer($onLine);
    $command = array_merge(composerInvocation($composer), $args);

    $home = toolsDir() . '/composer-home';
    $cache = toolsDir() . '/composer-cache';
    ensureDir($home);
    ensureDir($cache);

    $environment = [
        'COMPOSER_NO_INTERACTION' => '1',
        'COMPOSER_PROCESS_TIMEOUT' => (string) max(300, $timeout),
        'COMPOSER_MEMORY_LIMIT' => '-1',
        'COMPOSER_HOME' => $home,
        'COMPOSER_CACHE_DIR' => $cache,
    ];
    if (caFile() !== null) {
        $environment['COMPOSER_CAFILE'] = caFile();
    }
    if (function_exists('posix_getuid') && @posix_getuid() === 0) {
        $environment['COMPOSER_ALLOW_SUPERUSER'] = '1';
    }

    return runProcess($command, $onLine, baseDir(), $environment, $timeout, $onTick);
}

// ============================================================================
// Project detection
// ============================================================================

function readLockedVersion(string $directory, string $package): ?string
{
    $lock = readJsonFile($directory . '/composer.lock', 16_000_000);
    if ($lock === null) {
        return null;
    }

    $package = strtolower($package);
    foreach (['packages', 'packages-dev'] as $section) {
        if (!is_array($lock[$section] ?? null)) {
            continue;
        }
        foreach ($lock[$section] as $item) {
            if (is_array($item) && strtolower((string) ($item['name'] ?? '')) === $package) {
                $version = (string) ($item['version'] ?? '');
                return $version !== '' ? $version : null;
            }
        }
    }
    return null;
}

function readInstalledJsonVersion(string $directory, string $package): ?string
{
    $installed = readJsonFile($directory . '/vendor/composer/installed.json', 16_000_000);
    if ($installed === null) {
        return null;
    }

    $packages = is_array($installed['packages'] ?? null) ? $installed['packages'] : $installed;
    if (!is_array($packages)) {
        return null;
    }

    $package = strtolower($package);
    foreach ($packages as $item) {
        if (is_array($item) && strtolower((string) ($item['name'] ?? '')) === $package) {
            $version = (string) ($item['version'] ?? '');
            return $version !== '' ? $version : null;
        }
    }
    return null;
}

function readRootPrettyVersion(string $directory): ?string
{
    $file = $directory . '/vendor/composer/installed.php';
    if (!is_file($file)) {
        return null;
    }

    $raw = @file_get_contents($file, false, null, 0, 262144);
    if ($raw === false || $raw === '') {
        return null;
    }

    $rootSection = substr($raw, 0, 5000);
    if (preg_match("/'root'\\s*=>\\s*array\\s*\\((.*?)\\n\\s*\\),\\s*\\n\\s*'versions'/s", $raw, $matches)) {
        $rootSection = $matches[1];
    }

    if (preg_match("/'pretty_version'\\s*=>\\s*'([^']+)'/", $rootSection, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * @return array<string,mixed>|null
 */
function detectProject(string $directory): ?array
{
    $directory = rtrim(normalizePath($directory), '/');
    $composerJson = readJsonFile($directory . '/composer.json');
    if ($composerJson === null) {
        return null;
    }

    $rootName = strtolower(trim((string) ($composerJson['name'] ?? '')));
    $require = [];
    foreach (['require', 'require-dev'] as $section) {
        if (is_array($composerJson[$section] ?? null)) {
            $require += $composerJson[$section];
        }
    }

    $framework = $rootName !== '' ? findCatalogFramework($rootName) : null;
    if ($framework === null) {
        foreach (array_keys($require) as $dependency) {
            $framework = frameworkForCorePackage((string) $dependency);
            if ($framework !== null) {
                break;
            }
        }
    }

    $installed = null;
    $source = null;
    $comparePackage = null;
    $meta = readJsonFile($directory . '/.installer.json');

    if ($framework !== null && is_array($meta)) {
        $metaPackage = strtolower((string) ($meta['package'] ?? ''));
        if ($metaPackage === strtolower($framework['package']) || $metaPackage === $rootName) {
            $installed = (string) ($meta['version'] ?? '');
            $installed = $installed !== '' ? $installed : null;
            if ($installed !== null) {
                $source = 'installer';
                $comparePackage = $framework['package'];
            }
        }
    }

    if ($installed === null && $framework !== null) {
        $locked = readLockedVersion($directory, $framework['package']);
        if ($locked !== null) {
            $installed = $locked;
            $source = 'composer.lock';
            $comparePackage = $framework['package'];
        }
    }

    if ($installed === null && $framework !== null && $rootName === strtolower($framework['package'])) {
        $rootVersion = readRootPrettyVersion($directory);
        if ($rootVersion !== null) {
            $installed = $rootVersion;
            $source = 'installed.php';
            $comparePackage = $framework['package'];
        }
    }

    if ($installed === null && $framework !== null && !empty($framework['core_package'])) {
        $coreVersion = readLockedVersion($directory, (string) $framework['core_package']);
        if ($coreVersion === null) {
            $coreVersion = readInstalledJsonVersion($directory, (string) $framework['core_package']);
        }
        if ($coreVersion !== null) {
            $installed = $coreVersion;
            $source = 'composer.lock (core)';
            $comparePackage = (string) $framework['core_package'];
        }
    }

    if ($installed === null) {
        $rootVersion = readRootPrettyVersion($directory);
        if ($rootVersion !== null) {
            $installed = $rootVersion;
            $source = 'installed.php';
        }
    }

    if ($framework === null) {
        if ($rootName === '') {
            return null;
        }
        $framework = [
            'id' => 'custom',
            'name' => $rootName,
            'package' => $rootName,
            'core_package' => null,
            'description' => 'Другой Composer-проект',
            'homepage' => '',
            'tags' => ['Composer'],
            'color' => '#64748b',
            'monogram' => strtoupper(substr($rootName, 0, 1)),
            'target' => 'app',
            'weight' => 0,
            'custom' => true,
        ];
    }

    $constraint = null;
    if (is_array($composerJson['require'] ?? null)) {
        $constraint = $composerJson['require'][$framework['package']] ?? null;
        if ($constraint === null && !empty($framework['core_package'])) {
            $constraint = $composerJson['require'][$framework['core_package']] ?? null;
        }
    }

    return [
        'path' => relativePath($directory, baseDir()),
        'abs_path' => $directory,
        'name' => $rootName !== '' ? $rootName : basename($directory),
        'framework' => $framework['name'],
        'framework_id' => $framework['id'],
        'package' => $framework['package'],
        'compare_package' => $comparePackage ?? $framework['package'],
        'core_package' => $framework['core_package'] ?? null,
        'installed' => $installed,
        'source' => $source,
        'constraint' => is_string($constraint) ? $constraint : null,
        'mtime' => @filemtime($directory . '/composer.json') ?: null,
        'meta' => is_array($meta) ? [
            'installed_at' => $meta['installed_at'] ?? null,
            'requested_version' => $meta['requested_version'] ?? null,
            'options' => $meta['options'] ?? null,
        ] : null,
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function scanProjects(string $root, ?int $maxDepth = null): array
{
    $root = resolvePath($root);
    if (!is_dir($root)) {
        throw new InstallerException('Каталог для поиска не найден: ' . $root);
    }

    $maxDepth = $maxDepth ?? maxScanDepth();
    $skip = [
        'vendor', 'node_modules', '.git', '.svn', '.hg', '.idea', '.vscode',
        'storage', 'var', 'cache', 'tmp', 'bower_components', '.installer',
    ];
    $projects = [];

    try {
        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $current) use ($skip, $root, $maxDepth): bool {
                if (!$current->isDir()) {
                    return true;
                }
                $name = $current->getFilename();
                if (in_array($name, $skip, true)) {
                    return false;
                }
                $relative = substr(normalizePath($current->getPathname()), strlen(normalizePath($root)));
                $depth = substr_count(trim($relative, '/'), '/') + 1;
                return $depth <= $maxDepth;
            }
        );

        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'composer.json') {
                continue;
            }
            $project = detectProject(dirname($file->getPathname()));
            if ($project !== null) {
                $projects[] = $project;
            }
            if (count($projects) >= 200) {
                break;
            }
        }
    } catch (Throwable $e) {
        installerLog('scan failed', ['root' => $root, 'error' => $e->getMessage()]);
    }

    usort(
        $projects,
        static fn (array $a, array $b): int => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0)
    );
    return $projects;
}

// ============================================================================
// Installation
// ============================================================================

function validatePackageName(string $package): bool
{
    $package = strtolower(trim($package));
    return (bool) preg_match(
        '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$#',
        $package
    );
}

function validateTarget(string $target): string
{
    $target = trim(str_replace('\\', '/', $target));
    if ($target === '') {
        throw new InstallerException('Укажите папку для установки.');
    }
    if (strlen($target) > 120) {
        throw new InstallerException('Путь к папке слишком длинный.');
    }
    if (str_starts_with($target, '/') || str_starts_with($target, '//') || preg_match('/^[A-Za-z]:/', $target)) {
        throw new InstallerException('Путь должен быть относительным и находиться внутри каталога установщика.');
    }

    $reserved = [
        'install.php', 'composer.json', 'composer.lock', 'vendor', 'node_modules',
        '.git', '.installer', '.htaccess', '.installer.json',
    ];
    $segments = explode('/', $target);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new InstallerException('Некорректный путь к папке.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $segment)) {
            throw new InstallerException('В пути допустимы только латинские буквы, цифры, точка, дефис и подчёркивание.');
        }
        if (str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
            throw new InstallerException('Сегмент пути не может заканчиваться точкой или пробелом.');
        }
        if (in_array(strtolower($segment), $reserved, true)) {
            throw new InstallerException('Имя «' . $segment . '» зарезервировано установщиком.');
        }
        if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $segment)) {
            throw new InstallerException('Имя «' . $segment . '» зарезервировано Windows.');
        }
    }

    $relative = implode('/', $segments);
    $absolute = baseDir() . '/' . $relative;
    $parent = dirname($absolute);
    if (!is_dir($parent)) {
        throw new InstallerException('Родительская папка не существует: ' . relativePath($parent, baseDir()));
    }
    $realParent = realpath($parent);
    if ($realParent === false || !pathIsInside($realParent, baseDir())) {
        throw new InstallerException('Папка установки должна находиться внутри ' . baseDir());
    }
    if (is_link($absolute)) {
        throw new InstallerException('Нельзя устанавливать в символическую ссылку.');
    }
    if (file_exists($absolute)) {
        if (!is_dir($absolute)) {
            throw new InstallerException('Путь уже занят файлом: ' . $relative);
        }
        $entries = @scandir($absolute);
        if ($entries === false || count($entries) > 2) {
            throw new InstallerException('Папка «' . $relative . '» уже существует и не пуста.');
        }
    }

    return $relative;
}

/**
 * @return array<string,mixed>
 */
function installProject(array $params, ?callable $emit = null, ?callable $tick = null): array
{
    $package = strtolower(trim((string) ($params['package'] ?? '')));
    if (!validatePackageName($package)) {
        throw new InstallerException('Некорректное имя Composer-пакета. Ожидается vendor/package.');
    }

    $target = validateTarget((string) ($params['target'] ?? ''));
    $version = trim((string) ($params['version'] ?? ''));
    if ($version !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]*$/', $version)) {
        throw new InstallerException('Некорректная версия пакета.');
    }

    $options = is_array($params['options'] ?? null) ? $params['options'] : [];
    $targetAbsolute = baseDir() . '/' . $target;
    $targetExisted = file_exists($targetAbsolute);

    $free = @disk_free_space(baseDir());
    $minimum = $package === 'magento/project-community-edition'
        ? 2 * 1024 * 1024 * 1024
        : 200 * 1024 * 1024;
    if ($free !== false && $free < $minimum) {
        throw new InstallerException(
            'Недостаточно свободного места: нужно не менее ' . formatBytes($minimum)
            . ', доступно ' . formatBytes((float) $free) . '.'
        );
    }

    $lockPath = installLockFile();
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new InstallerException('Не удалось открыть файл блокировки установки.');
    }
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        @fclose($lock);
        throw new InstallerException('Уже выполняется другая установка. Дождитесь её завершения.');
    }

    @ftruncate($lock, 0);
    @fwrite($lock, jsonEncode([
        'pid' => getmypid(),
        'time' => date('c'),
        'package' => $package,
        'target' => $target,
    ]));

    $started = microtime(true);
    try {
        if ($emit !== null) {
            $emit('Проверка Composer...', 'out');
        }
        $composer = ensureComposer($emit);
        if ($emit !== null) {
            $emit('Composer ' . ($composer['version'] ?? '?') . ' — ' . $composer['path'], 'out');
        }

        $arguments = ['create-project', '--no-interaction', '--prefer-dist', '--no-progress', '--remove-vcs'];
        if (!empty($options['no_dev'])) {
            $arguments[] = '--no-dev';
        }
        if (!empty($options['no_scripts'])) {
            $arguments[] = '--no-scripts';
        }
        if (!empty($options['no_plugins'])) {
            $arguments[] = '--no-plugins';
        }
        if (!empty($options['prefer_source'])) {
            $arguments = array_values(array_diff($arguments, ['--prefer-dist']));
            $arguments[] = '--prefer-source';
        }
        if (!empty($options['ignore_platform_reqs'])) {
            $arguments[] = '--ignore-platform-reqs';
        }
        if (!empty($options['no_audit'])) {
            $arguments[] = '--no-audit';
        }
        if (!empty($options['no_blocking'])) {
            $arguments[] = '--no-blocking';
        }

        $arguments[] = $package;
        $arguments[] = $target;
        if ($version !== '') {
            $arguments[] = $version;
        }

        if ($emit !== null) {
            $emit('Команда: composer ' . implode(' ', $arguments), 'out');
        }

        $result = runComposer($arguments, $emit, installTimeout(), $tick);
        $elapsed = microtime(true) - $started;

        if ($result['exit'] !== 0 || $result['timed_out']) {
            $combined = trim($result['stderr'] . "\n" . $result['stdout']);
            $lines = preg_split('/\r\n|\r|\n/', $combined) ?: [];
            $tail = implode("\n", array_slice($lines, -25));
            throw new InstallerException(
                'Composer завершился с кодом ' . $result['exit']
                . ($result['timed_out'] ? ' (превышен таймаут)' : '')
                . ($tail !== '' ? ":\n" . $tail : '')
            );
        }

        if (!is_file($targetAbsolute . '/composer.json')) {
            throw new InstallerException('Composer завершился успешно, но composer.json в целевой папке не найден.');
        }

        $installed = readLockedVersion($targetAbsolute, $package);
        if ($installed === null) {
            $installed = readRootPrettyVersion($targetAbsolute);
        }
        if ($installed === null && $version !== '') {
            $installed = $version;
        }

        $meta = [
            'package' => $package,
            'version' => $installed,
            'requested_version' => $version !== '' ? $version : null,
            'installed_at' => date('c'),
            'php' => PHP_VERSION,
            'options' => $options,
            'installer' => INSTALLER_VERSION,
        ];
        writeFileAtomic($targetAbsolute . '/.installer.json', jsonEncode($meta) . "\n", 0644);

        $project = detectProject($targetAbsolute);
        installerLog('install success', [
            'package' => $package,
            'target' => $target,
            'version' => $installed,
            'elapsed' => round($elapsed, 2),
        ]);

        return [
            'ok' => true,
            'package' => $package,
            'target' => $target,
            'path' => $targetAbsolute,
            'version' => $installed,
            'elapsed' => round($elapsed, 2),
            'project' => $project,
            'composer' => $composer,
        ];
    } catch (Throwable $e) {
        installerLog('install failed', [
            'package' => $package,
            'target' => $target,
            'error' => $e->getMessage(),
        ]);
        if (!empty($options['cleanup']) && !$targetExisted && is_dir($targetAbsolute)) {
            deleteDirectory($targetAbsolute);
        }
        throw $e;
    } finally {
        @flock($lock, LOCK_UN);
        @ftruncate($lock, 0);
        @fclose($lock);
    }
}

// ============================================================================
// Environment diagnostics
// ============================================================================

function environmentInfo(): array
{
    $free = @disk_free_space(baseDir());
    $total = @disk_total_space(baseDir());

    return [
        'installer_version' => INSTALLER_VERSION,
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'os' => PHP_OS_FAMILY,
        'arch' => PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',
        'base_dir' => baseDir(),
        'base_writable' => is_writable(baseDir()),
        'disk_free' => $free === false ? null : (float) $free,
        'disk_total' => $total === false ? null : (float) $total,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'extensions' => [
            'openssl' => extension_loaded('openssl'),
            'curl' => extension_loaded('curl'),
            'zip' => extension_loaded('zip'),
            'mbstring' => extension_loaded('mbstring'),
            'fileinfo' => extension_loaded('fileinfo'),
            'intl' => extension_loaded('intl'),
            'pdo' => extension_loaded('pdo'),
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'sockets' => extension_loaded('sockets'),
        ],
        'proc_open' => function_exists('proc_open'),
        'allow_url_fopen' => filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
        'shell_exec' => function_exists('shell_exec'),
        'composer' => composerInfo(false),
        'key_file' => keyFile(),
        'cache_dir' => cacheDir(),
        'log_file' => logFile(),
        'install_lock' => is_file(lockFile()),
        'https' => isHttps(),
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? null,
        'time' => date('c'),
    ];
}

function environmentChecks(): array
{
    $info = environmentInfo();
    $checks = [];
    $add = static function (
        string $id,
        string $label,
        string $status,
        string $value,
        string $hint = ''
    ) use (&$checks): void {
        $checks[] = [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'value' => $value,
            'hint' => $hint,
        ];
    };

    $add(
        'php',
        'Версия PHP',
        version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail',
        PHP_VERSION,
        'Требуется PHP 8.1 или новее.'
    );

    $httpClient = $info['extensions']['openssl'] || findExecutable('curl') !== null;
    $add(
        'https',
        'HTTPS-клиент',
        $httpClient ? 'ok' : 'warn',
        $info['extensions']['openssl'] ? 'OpenSSL' : ($info['extensions']['curl'] ? 'cURL' : 'системный curl'),
        'Нужен для запросов к Packagist и загрузки Composer.'
    );

    $add(
        'proc_open',
        'proc_open',
        $info['proc_open'] ? 'ok' : 'fail',
        $info['proc_open'] ? 'доступна' : 'отключена',
        'Без proc_open нельзя запустить Composer.'
    );

    $add(
        'writable',
        'Запись в каталог',
        $info['base_writable'] ? 'ok' : 'fail',
        $info['base_dir'],
        'Каталог установщика должен быть доступен для записи.'
    );

    $diskStatus = 'warn';
    if ($info['disk_free'] !== null) {
        $diskStatus = $info['disk_free'] > 200 * 1024 * 1024 ? 'ok' : 'fail';
    }
    $add(
        'disk',
        'Свободное место',
        $diskStatus,
        $info['disk_free'] !== null ? formatBytes($info['disk_free']) : 'не определено',
        'Для большинства фреймворков достаточно 200 МБ; Magento требует больше.'
    );

    $add(
        'composer',
        'Composer',
        $info['composer']['found'] ? 'ok' : 'warn',
        $info['composer']['found']
            ? (($info['composer']['version'] ?? 'найден') . ' — ' . ($info['composer']['path'] ?? ''))
            : 'не найден',
        'При установке Composer будет скачан автоматически с проверкой подписи.'
    );

    $add(
        'zip',
        'Расширение ZipArchive',
        $info['extensions']['zip'] ? 'ok' : 'warn',
        $info['extensions']['zip'] ? 'включено' : 'выключено',
        'Ускоряет распаковку пакетов; без него Composer попробует системные утилиты.'
    );

    $add(
        'mbstring',
        'Расширение mbstring',
        $info['extensions']['mbstring'] ? 'ok' : 'warn',
        $info['extensions']['mbstring'] ? 'включено' : 'выключено',
        'Многие фреймворки требуют mbstring.'
    );

    $add(
        'web_https',
        'HTTPS веб-интерфейса',
        $info['https'] ? 'ok' : 'warn',
        $info['https'] ? 'да' : 'нет',
        'Для доступа к веб-установщику рекомендуется HTTPS.'
    );

    $add(
        'lock',
        'Файл install.lock',
        $info['install_lock'] ? 'warn' : 'ok',
        $info['install_lock'] ? 'существует — веб-интерфейс заблокирован' : 'отсутствует',
        'Создайте install.lock после завершения настройки, чтобы отключить веб-установку.'
    );

    $add(
        'key',
        'Ключ доступа',
        (is_file(keyFile()) || envValue('INSTALL_KEY') !== null) ? 'ok' : 'warn',
        keyFile(),
        'Ключ создаётся автоматически при первом открытии веб-интерфейса.'
    );

    return ['info' => $info, 'checks' => $checks];
}

// ============================================================================
// CLI
// ============================================================================

/**
 * @return array{0:array<int,string>,1:array<string,mixed>}
 */
function parseCliOptions(array $args): array
{
    $positional = [];
    $options = [];
    $stop = false;

    foreach ($args as $argument) {
        if ($stop) {
            $positional[] = $argument;
            continue;
        }
        if ($argument === '--') {
            $stop = true;
            continue;
        }
        if (str_starts_with($argument, '--')) {
            $argument = substr($argument, 2);
            $equals = strpos($argument, '=');
            if ($equals !== false) {
                $options[substr($argument, 0, $equals)] = substr($argument, $equals + 1);
            } else {
                $options[$argument] = true;
            }
            continue;
        }
        if (str_starts_with($argument, '-') && $argument !== '-') {
            $options[substr($argument, 1)] = true;
            continue;
        }
        $positional[] = $argument;
    }

    return [$positional, $options];
}

function cliUsage(): int
{
    $lines = [
        'PHP Framework Installer ' . INSTALLER_VERSION,
        '',
        'Использование:',
        '  php install.php                              интерактивная установка',
        '  php install.php list [--json]                список фреймворков',
        '  php install.php versions <пакет> [--all]     версии пакета из Packagist',
        '  php install.php detect [путь] [--json]       найти установленные проекты',
        '  php install.php install <пакет> [опции]      установить фреймворк',
        '  php install.php composer                     установить/проверить Composer',
        '  php install.php doctor [--json]              диагностика окружения',
        '  php install.php key                          показать ключ веб-доступа',
        '  php install.php cache:clear                  очистить кэш',
        '  php install.php --version                    версия установщика',
        '',
        'Опции install:',
        '  --version=<версия>        версия пакета (по умолчанию последняя совместимая)',
        '  --dir=<папка>             целевая папка относительно install.php',
        '  --no-dev                  без dev-зависимостей',
        '  --no-scripts              не выполнять Composer-скрипты',
        '  --no-plugins              не загружать Composer-плагины',
        '  --prefer-source           устанавливать из исходников',
        '  --ignore-platform-reqs    игнорировать требования платформы',
        '  --no-audit                пропустить аудит безопасности Composer',
        '  --no-blocking             отключить блокировки политик Composer',
        '  --cleanup                 удалить папку при ошибке установки',
        '  --yes                     без подтверждения',
        '',
        'Примеры:',
        '  php install.php versions laravel/laravel',
        '  php install.php install laravel --dir=my-app --no-dev',
        '  php install.php install symfony/skeleton --version=8.1.99',
        '  php install.php detect .',
        '',
        'Веб-режим: откройте install.php в браузере и введите ключ из файла,',
        'путь к которому показан на странице входа.',
        '',
        'Разработал: Михаил Губин',
    ];
    echo implode(PHP_EOL, $lines) . PHP_EOL;
    return 0;
}

function cliList(array $options): int
{
    $catalog = frameworkCatalog();
    if (!empty($options['json'])) {
        echo jsonEncode(['ok' => true, 'frameworks' => $catalog]) . PHP_EOL;
        return 0;
    }

    $rows = [];
    foreach ($catalog as $framework) {
        $rows[] = [
            $framework['id'],
            $framework['name'],
            $framework['package'],
            implode(', ', $framework['tags']),
        ];
    }
    printTable(['ID', 'Название', 'Пакет', 'Категории'], $rows);
    echo PHP_EOL . 'Всего фреймворков: ' . count($catalog) . '.' . PHP_EOL;
    echo 'Свой пакет: php install.php versions vendor/package' . PHP_EOL;
    return 0;
}

function cliVersions(array $positional, array $options): int
{
    if ($positional === []) {
        throw new InstallerException('Укажите фреймворк или Composer-пакет.');
    }

    $framework = findFramework($positional[0]);
    $package = $framework['package'] ?? strtolower($positional[0]);
    if (!validatePackageName($package)) {
        throw new InstallerException('Некорректное имя пакета: ' . $package);
    }

    $data = getPackageVersions($package, !empty($options['refresh']));
    if (!$data['ok']) {
        throw new InstallerException($data['error']);
    }

    if (!empty($options['json'])) {
        echo jsonEncode($data) . PHP_EOL;
        return 0;
    }

    echo 'Пакет: ' . $package . PHP_EOL;
    echo 'Последняя стабильная: ' . ($data['latest']['version'] ?? '—')
        . ' (PHP ' . ($data['latest']['php'] ?? '—') . ')' . PHP_EOL;
    echo 'Совместимая с PHP ' . PHP_VERSION . ': '
        . ($data['latest_compatible']['version'] ?? '—') . PHP_EOL;
    if ($data['stale']) {
        echo cliColor('Внимание: ' . $data['warning'], 'yellow') . PHP_EOL;
    }

    $branches = [];
    foreach ($data['branches'] as $branch) {
        $branches[] = $branch['branch'] . '.x → ' . $branch['version'];
    }
    if ($branches !== []) {
        echo 'Ветки: ' . implode(', ', $branches) . PHP_EOL;
    }
    echo PHP_EOL;

    $rows = [];
    $limit = !empty($options['all']) ? count($data['versions']) : 30;
    $shown = 0;
    foreach ($data['versions'] as $version) {
        if (empty($options['prerelease']) && !$version['stable']) {
            continue;
        }
        $rows[] = [
            $version['version'],
            $version['stable'] ? 'stable' : 'pre',
            substr((string) ($version['time'] ?? ''), 0, 10),
            $version['php'] ?? '—',
            implode('; ', $version['issues']),
        ];
        $shown++;
        if ($shown >= $limit) {
            break;
        }
    }
    printTable(['Версия', 'Статус', 'Дата', 'PHP', 'Замечания'], $rows);
    return 0;
}

function cliDetect(array $positional, array $options): int
{
    $path = resolvePath($positional[0] ?? baseDir());
    $projects = scanProjects($path, isset($options['depth']) ? (int) $options['depth'] : null);

    if (!empty($options['json'])) {
        echo jsonEncode(['ok' => true, 'projects' => $projects]) . PHP_EOL;
        return 0;
    }

    if ($projects === []) {
        echo 'Composer-проекты не найдены в ' . $path . PHP_EOL;
        return 0;
    }

    $rows = [];
    foreach ($projects as $project) {
        $rows[] = [
            $project['name'],
            $project['framework'],
            $project['installed'] ?? '—',
            $project['source'] ?? '—',
            $project['path'],
        ];
    }
    printTable(['Проект', 'Фреймворк', 'Версия', 'Источник', 'Путь'], $rows);
    return 0;
}

function cliInstall(array $positional, array $options): int
{
    if ($positional === []) {
        throw new InstallerException('Укажите фреймворк или Composer-пакет.');
    }

    $framework = findFramework($positional[0]);
    $package = $framework['package'] ?? strtolower($positional[0]);
    if (!validatePackageName($package)) {
        throw new InstallerException('Некорректное имя пакета: ' . $package);
    }

    $version = trim((string) ($options['version'] ?? ''));
    if ($version === '') {
        $data = getPackageVersions($package);
        if (!$data['ok']) {
            throw new InstallerException($data['error']);
        }
        $version = $data['latest_compatible']['version'] ?? $data['latest']['version'] ?? '';
    }

    $target = trim((string) ($options['dir'] ?? ''));
    if ($target === '') {
        $target = $framework['target'] ?? slugify($package);
    }

    $parameters = [
        'package' => $package,
        'version' => $version,
        'target' => $target,
        'options' => [
            'no_dev' => !empty($options['no-dev']),
            'no_scripts' => !empty($options['no-scripts']),
            'no_plugins' => !empty($options['no-plugins']),
            'prefer_source' => !empty($options['prefer-source']),
            'ignore_platform_reqs' => !empty($options['ignore-platform-reqs']),
            'no_audit' => !empty($options['no-audit']),
            'no_blocking' => !empty($options['no-blocking']),
            'cleanup' => !empty($options['cleanup']),
        ],
    ];

    echo 'Пакет:  ' . $package . PHP_EOL;
    echo 'Версия: ' . ($version !== '' ? $version : 'последняя') . PHP_EOL;
    echo 'Папка:  ' . baseDir() . '/' . $target . PHP_EOL;

    if (empty($options['yes']) && isTty()) {
        echo 'Продолжить? [y/N]: ';
        $answer = trim((string) fgets(STDIN));
        if (!preg_match('/^[yYдД]/u', $answer)) {
            echo 'Отменено.' . PHP_EOL;
            return 0;
        }
    }

    $emit = static function (string $line, string $stream): void {
        $prefix = $stream === 'err' ? cliColor('! ', 'yellow') : '  ';
        $handle = $stream === 'err' ? STDERR : STDOUT;
        fwrite($handle, $prefix . $line . PHP_EOL);
    };

    $result = installProject($parameters, $emit);
    echo PHP_EOL . cliColor('Установка завершена: ' . $result['path'], 'green') . PHP_EOL;
    echo 'Версия: ' . ($result['version'] ?? '—') . PHP_EOL;
    echo 'Время:  ' . formatDuration((float) $result['elapsed']) . PHP_EOL;
    if (is_array($result['project'])) {
        echo 'Определено: ' . $result['project']['framework']
            . ' ' . ($result['project']['installed'] ?? '') . PHP_EOL;
    }
    return 0;
}

function cliComposer(array $options): int
{
    $emit = static function (string $line, string $stream): void {
        fwrite($stream === 'err' ? STDERR : STDOUT, $line . PHP_EOL);
    };
    $info = ensureComposer($emit);
    echo 'Composer ' . ($info['version'] ?? '?') . ' — ' . $info['path'] . PHP_EOL;
    return 0;
}

function cliDoctor(array $options): int
{
    $result = environmentChecks();
    if (!empty($options['json'])) {
        echo jsonEncode(['ok' => true] + $result) . PHP_EOL;
        return 0;
    }

    $labels = ['ok' => '[OK]  ', 'warn' => '[WARN]', 'fail' => '[FAIL]'];
    foreach ($result['checks'] as $check) {
        $color = $check['status'] === 'ok' ? 'green' : ($check['status'] === 'warn' ? 'yellow' : 'red');
        echo cliColor($labels[$check['status']] ?? '[?]   ', $color)
            . ' ' . $check['label'] . ': ' . $check['value'] . PHP_EOL;
        if ($check['hint'] !== '') {
            echo '       ' . cliColor($check['hint'], 'gray') . PHP_EOL;
        }
    }
    return 0;
}

function cliHttp(array $positional, array $options): int
{
    if ($positional === []) {
        throw new InstallerException('Укажите URL для проверки.');
    }
    $url = $positional[0];
    if (!preg_match('#^https?://#i', $url)) {
        throw new InstallerException('Поддерживаются только HTTP(S)-адреса.');
    }

    $result = httpGet($url, 30);
    if (!empty($options['json'])) {
        echo jsonEncode($result) . PHP_EOL;
        return $result['ok'] ? 0 : 1;
    }

    echo 'Канал:  ' . $result['via'] . PHP_EOL;
    echo 'Статус: ' . $result['status'] . PHP_EOL;
    echo 'Размер: ' . strlen($result['body']) . ' байт' . PHP_EOL;
    if (!$result['ok']) {
        echo 'Ошибка: ' . $result['error'] . PHP_EOL;
        return 1;
    }
    echo substr($result['body'], 0, 500) . PHP_EOL;
    return 0;
}

function cliKey(array $options): int
{
    $key = ensureKey();
    echo $key . PHP_EOL;
    echo 'Файл: ' . keyFile() . PHP_EOL;
    return 0;
}

function cliCacheClear(array $options): int
{
    $count = clearCache();
    echo 'Удалено файлов кэша: ' . $count . PHP_EOL;
    return 0;
}

function cliInteractive(): int
{
    echo 'PHP Framework Installer ' . INSTALLER_VERSION . PHP_EOL . PHP_EOL;
    $catalog = frameworkCatalog();
    foreach ($catalog as $index => $framework) {
        echo sprintf(
            "%2d. %s (%s)%s",
            $index + 1,
            $framework['name'],
            $framework['package'],
            PHP_EOL
        );
    }

    echo PHP_EOL . 'Выберите номер или vendor/package (q — выход): ';
    $input = trim((string) fgets(STDIN));
    if ($input === '' || $input === 'q' || $input === 'Q') {
        return 0;
    }

    $framework = ctype_digit($input)
        ? ($catalog[(int) $input - 1] ?? null)
        : findFramework($input);
    if ($framework === null) {
        echo 'Фреймворк не найден.' . PHP_EOL;
        return 2;
    }

    $data = getPackageVersions($framework['package']);
    if (!$data['ok']) {
        throw new InstallerException($data['error']);
    }

    $defaultVersion = $data['latest_compatible']['version'] ?? $data['latest']['version'] ?? '';
    echo PHP_EOL . 'Последняя стабильная: ' . ($data['latest']['version'] ?? '—')
        . '; совместимая: ' . ($data['latest_compatible']['version'] ?? '—') . PHP_EOL;
    echo 'Версия [' . $defaultVersion . ']: ';
    $version = trim((string) fgets(STDIN));
    if ($version === '') {
        $version = $defaultVersion;
    }

    echo 'Папка [' . $framework['target'] . ']: ';
    $target = trim((string) fgets(STDIN));
    if ($target === '') {
        $target = $framework['target'];
    }

    return cliInstall([$framework['package']], [
        'version' => $version,
        'dir' => $target,
        'yes' => true,
    ]);
}

function cliMain(array $argv): int
{
    $arguments = array_slice($argv, 1);
    if ($arguments === []) {
        if (isTty()) {
            try {
                return cliInteractive();
            } catch (Throwable $e) {
                fwrite(STDERR, cliColor('Ошибка: ' . $e->getMessage(), 'red') . PHP_EOL);
                return 1;
            }
        }
        cliUsage();
        return 2;
    }

    $command = array_shift($arguments);
    if ($command === '--version' || $command === '-V') {
        echo 'PHP Framework Installer ' . INSTALLER_VERSION . PHP_EOL;
        return 0;
    }
    [$positional, $options] = parseCliOptions($arguments);

    try {
        return match ($command) {
            'list', 'ls' => cliList($options),
            'versions', 'version' => cliVersions($positional, $options),
            'detect', 'scan' => cliDetect($positional, $options),
            'install' => cliInstall($positional, $options),
            'composer' => cliComposer($options),
            'doctor', 'check' => cliDoctor($options),
            'http' => cliHttp($positional, $options),
            'key' => cliKey($options),
            'cache:clear', 'cache-clear' => cliCacheClear($options),
            'help', '--help', '-h' => cliUsage(),
            default => throw new InstallerException('Неизвестная команда: ' . $command),
        };
    } catch (Throwable $e) {
        fwrite(STDERR, cliColor('Ошибка: ' . $e->getMessage(), 'red') . PHP_EOL);
        if (envFlag('INSTALL_DEBUG', false)) {
            fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
        }
        return 1;
    }
}

// ============================================================================
// Web authentication and API
// ============================================================================

function cspNonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }
    return $nonce;
}

function securityHeaders(string $nonce): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cache-Control: no-store, max-age=0');
    header(
        "Content-Security-Policy: default-src 'none'; "
        . "style-src 'nonce-" . $nonce . "'; "
        . "script-src 'nonce-" . $nonce . "'; "
        . "connect-src 'self'; img-src 'none'; font-src 'self'; "
        . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'"
    );
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent()) {
        throw new InstallerException('Не удалось запустить сессию: заголовки уже отправлены.');
    }

    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.gc_maxlifetime', '7200');
    session_name('php_fw_installer');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    if (!@session_start()) {
        throw new InstallerException('Не удалось запустить сессию. Проверьте session.save_path.');
    }
}

function logoutSession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }
    @session_destroy();
}

function isAuthenticated(): bool
{
    if (($_SESSION['auth'] ?? false) !== true) {
        return false;
    }
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && time() - $lastActivity > 7200) {
        logoutSession();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(?string $token = null): void
{
    if ($token === null) {
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    }
    if ($token === '' || !hash_equals(csrfToken(), $token)) {
        throw new InstallerException('CSRF-токен недействителен. Обновите страницу.', 403);
    }
}

function redirectSelf(): void
{
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
    if (!is_string($path) || $path === '') {
        $path = (string) ($_SERVER['SCRIPT_NAME'] ?? 'install.php');
    }
    $path = str_replace(["\r", "\n"], '', $path);
    header('Location: ' . $path);
    exit;
}

function loginAttemptsFile(string $ip): string
{
    return toolsDir() . '/login-' . hash('sha256', $ip) . '.json';
}

/**
 * @return array{blocked:bool,retry:int}
 */
function loginRateLimit(string $ip): array
{
    $file = loginAttemptsFile($ip);
    $data = readJsonFile($file, 100000) ?? ['attempts' => []];
    $now = time();
    $attempts = array_values(array_filter(
        is_array($data['attempts'] ?? null) ? $data['attempts'] : [],
        static fn (mixed $timestamp): bool => is_int($timestamp) && $now - $timestamp < 600
    ));

    if (count($attempts) >= 5) {
        return ['blocked' => true, 'retry' => max(1, 600 - ($now - min($attempts)))];
    }
    return ['blocked' => false, 'retry' => 0];
}

function recordLoginFailure(string $ip): void
{
    $file = loginAttemptsFile($ip);
    $data = readJsonFile($file, 100000) ?? ['attempts' => []];
    $now = time();
    $attempts = array_values(array_filter(
        is_array($data['attempts'] ?? null) ? $data['attempts'] : [],
        static fn (mixed $timestamp): bool => is_int($timestamp) && $now - $timestamp < 600
    ));
    $attempts[] = $now;
    writeFileAtomic($file, jsonEncode(['attempts' => $attempts]), 0600);
}

function resetLoginFailures(string $ip): void
{
    $file = loginAttemptsFile($ip);
    if (is_file($file)) {
        @unlink($file);
    }
}

function handleLogin(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        renderLogin();
        return;
    }

    try {
        verifyCsrf((string) ($_POST['csrf'] ?? ''));
    } catch (InstallerException $e) {
        renderLogin($e->getMessage());
        return;
    }

    $ip = requestIp();
    $limit = loginRateLimit($ip);
    if ($limit['blocked']) {
        http_response_code(429);
        renderLogin('Слишком много попыток входа. Повторите через ' . $limit['retry'] . ' с.');
        return;
    }

    try {
        $expected = ensureKey();
    } catch (Throwable $e) {
        renderLogin('', $e->getMessage());
        return;
    }

    $provided = (string) ($_POST['key'] ?? '');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        recordLoginFailure($ip);
        usleep(400000);
        renderLogin('Неверный ключ доступа.');
        return;
    }

    resetLoginFailures($ip);
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    redirectSelf();
}

function handleLogout(): void
{
    logoutSession();
    redirectSelf();
}

function startNdjson(): void
{
    if (!headers_sent()) {
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

function streamEvent(array $event): void
{
    if (connection_aborted()) {
        return;
    }
    echo jsonEncode($event) . "\n";
    @ob_flush();
    @flush();
}

function apiRegistry(): void
{
    $frameworks = [];
    foreach (frameworkCatalog() as $framework) {
        $cached = cacheGet('summary:' . $framework['package']);
        $frameworks[] = array_merge($framework, [
            'summary' => $cached['data'] ?? null,
            'cached' => $cached !== null,
            'stale' => $cached['stale'] ?? false,
        ]);
    }

    jsonOut([
        'ok' => true,
        'frameworks' => $frameworks,
        'environment' => environmentInfo(),
        'server_time' => date('c'),
    ]);
}

function apiVersions(): void
{
    $package = strtolower(trim((string) ($_GET['package'] ?? '')));
    if (!validatePackageName($package)) {
        jsonOut(['ok' => false, 'error' => 'Некорректное имя Composer-пакета.'], 400);
    }

    $force = !empty($_GET['refresh']);
    if (!empty($_GET['summary'])) {
        $summary = getPackageSummary($package, $force);
        if (!$summary['ok']) {
            jsonOut($summary, 502);
        }
        jsonOut($summary);
    }

    $data = getPackageVersions($package, $force);
    if (!$data['ok']) {
        jsonOut(['ok' => false, 'package' => $package, 'error' => $data['error']], 502);
    }

    $brief = static function (?array $version): ?array {
        if ($version === null) {
            return null;
        }
        return [
            'version' => $version['version'],
            'stable' => $version['stable'],
            'time' => $version['time'],
            'php' => $version['php'],
            'issues' => $version['issues'],
        ];
    };

    $versions = array_map(
        static fn (array $version): array => [
            'version' => $version['version'],
            'stable' => $version['stable'],
            'time' => $version['time'],
            'php' => $version['php'],
            'issues' => $version['issues'],
        ],
        array_slice($data['versions'], 0, 200)
    );

    jsonOut([
        'ok' => true,
        'package' => $package,
        'latest' => $brief($data['latest']),
        'latest_compatible' => $brief($data['latest_compatible']),
        'latest_any' => $brief($data['latest_any']),
        'branches' => $data['branches'],
        'versions' => $versions,
        'versions_count' => count($data['versions']),
        'stale' => $data['stale'],
        'cached' => $data['cached'],
        'warning' => $data['warning'],
        'php' => PHP_VERSION,
        'via' => $data['via'],
        'updated_at' => date('c'),
    ]);
}

function apiDetect(): void
{
    $projects = scanProjects(baseDir(), maxScanDepth());
    jsonOut([
        'ok' => true,
        'projects' => $projects,
        'base_dir' => baseDir(),
        'depth' => maxScanDepth(),
        'scanned_at' => date('c'),
    ]);
}

function apiDoctor(): void
{
    $result = environmentChecks();
    jsonOut(array_merge(
        ['ok' => true],
        $result,
        ['log' => tailFile(logFile(), 200)]
    ));
}

function apiLog(): void
{
    jsonOut([
        'ok' => true,
        'file' => logFile(),
        'lines' => tailFile(logFile(), 200),
    ]);
}

function apiClearCache(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonOut(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
    }
    verifyCsrf();
    $removed = clearCache();
    jsonOut(['ok' => true, 'removed' => $removed]);
}

function apiInstall(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonOut(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
    }
    verifyCsrf();

    $raw = @file_get_contents('php://input', false, null, 0, 65536);
    $params = json_decode((string) $raw, true);
    if (!is_array($params)) {
        jsonOut(['ok' => false, 'error' => 'Некорректный JSON в запросе.'], 400);
    }

    $package = strtolower(trim((string) ($params['package'] ?? '')));
    if (!validatePackageName($package)) {
        jsonOut(['ok' => false, 'error' => 'Некорректное имя Composer-пакета.'], 400);
    }
    try {
        validateTarget((string) ($params['target'] ?? ''));
    } catch (InstallerException $e) {
        jsonOut(['ok' => false, 'error' => $e->getMessage()], 400);
    }

    @set_time_limit(0);
    @ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    startNdjson();
    streamEvent([
        'type' => 'start',
        'package' => $package,
        'target' => (string) ($params['target'] ?? ''),
        'time' => date('c'),
    ]);

    $emit = static function (string $line, string $stream): void {
        streamEvent(['type' => 'log', 'stream' => $stream, 'line' => $line]);
    };
    $tick = static function (): void {
        streamEvent(['type' => 'ping', 'time' => time()]);
    };

    try {
        $result = installProject($params, $emit, $tick);
        streamEvent(['type' => 'done', 'ok' => true, 'result' => $result]);
    } catch (Throwable $e) {
        installerLog('web install error', [
            'package' => $package,
            'error' => $e->getMessage(),
        ]);
        streamEvent(['type' => 'error', 'ok' => false, 'error' => $e->getMessage()]);
    }
}

function apiComposer(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonOut(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
    }
    verifyCsrf();

    @set_time_limit(0);
    @ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    startNdjson();
    streamEvent(['type' => 'start', 'time' => date('c')]);

    $emit = static function (string $line, string $stream): void {
        streamEvent(['type' => 'log', 'stream' => $stream, 'line' => $line]);
    };

    try {
        $composer = ensureComposer($emit);
        streamEvent(['type' => 'done', 'ok' => true, 'composer' => $composer]);
    } catch (Throwable $e) {
        streamEvent(['type' => 'error', 'ok' => false, 'error' => $e->getMessage()]);
    }
}

// ============================================================================
// Web interface
// ============================================================================

function renderLogin(string $error = '', string $keyError = ''): void
{
    $nonce = cspNonce();
    securityHeaders($nonce);
    $keyPath = keyFile();
    $keyFromEnv = envValue('INSTALL_KEY') !== null;
    ?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Вход — PHP Framework Installer</title>
    <style nonce="<?= e($nonce) ?>">
        :root{--bg:#070b14;--panel:#101a2e;--line:#253656;--text:#e8eefc;--muted:#94a3b8;--accent:#38bdf8;--accent2:#818cf8;--red:#f87171;--shadow:0 20px 60px rgba(0,0,0,.35)}
        *{box-sizing:border-box}
        html,body{margin:0;padding:0}
        body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:radial-gradient(1200px 600px at 10% -10%,#12305a 0,transparent 60%),radial-gradient(900px 500px at 110% 10%,#2a1b5e 0,transparent 55%),var(--bg);color:var(--text);min-height:100vh}
        code{font-family:ui-monospace,SFMono-Regular,Consolas,"Liberation Mono",monospace;font-size:.86em}
        .login-body{display:grid;place-items:center;min-height:100vh;padding:24px}
        .login-card{width:min(440px,100%);background:linear-gradient(180deg,var(--panel),#0b1425);border:1px solid var(--line);border-radius:18px;padding:28px;box-shadow:var(--shadow)}
        .login-brand{display:flex;gap:14px;align-items:center;margin-bottom:22px}
        .logo{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;font-weight:800;letter-spacing:.5px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#04101f;box-shadow:0 8px 24px rgba(56,189,248,.35);flex:0 0 auto}
        .login-brand h1{font-size:20px;margin:0;line-height:1.2}
        .login-brand p{margin:4px 0 0;color:var(--muted);font-size:12px}
        .login-card label{display:block;font-size:12px;color:var(--muted);margin:0 0 6px}
        .login-card input{width:100%;background:#0a1324;border:1px solid var(--line);color:var(--text);padding:12px 14px;border-radius:10px;font:inherit;outline:none}
        .login-card input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(56,189,248,.15)}
        .login-card button{width:100%;margin-top:14px;appearance:none;border:none;border-radius:10px;padding:12px 14px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#04101f;font:inherit;font-weight:700;cursor:pointer}
        .login-card button:hover{filter:brightness(1.08)}
        .login-card .hint{font-size:12px;color:var(--muted);line-height:1.5;margin:16px 0 0}
        .login-card .hint code{display:inline-block;margin-top:4px;color:#bae6fd;word-break:break-all}
        .login-card footer{margin-top:22px;padding-top:14px;border-top:1px solid var(--line);color:var(--muted);font-size:11px;text-align:center}
        .alert{border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:14px;line-height:1.4}
        .alert.error{color:#fecaca;background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3)}
    </style>
</head>
<body class="login-body">
<main class="login-card">
    <div class="login-brand">
        <span class="logo">PI</span>
        <div>
            <h1>PHP Framework Installer</h1>
            <p>Установка и проверка версий PHP-фреймворков</p>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($keyError !== ''): ?>
        <div class="alert error"><?= e($keyError) ?></div>
    <?php endif; ?>

    <form method="post" action="?action=login" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <label for="key">Ключ доступа</label>
        <input id="key" name="key" type="password" required autofocus autocomplete="current-password" placeholder="Введите ключ">
        <button type="submit">Войти</button>
    </form>

    <p class="hint">
        <?php if ($keyFromEnv): ?>
            Ключ задан через переменную окружения <code>INSTALL_KEY</code>.
        <?php else: ?>
            Ключ создан автоматически и хранится в файле:<br>
            <code><?= e($keyPath) ?></code>
        <?php endif; ?>
    </p>
    <p class="hint">
        Если файл недоступен, задайте переменную окружения <code>INSTALL_KEY</code>
        или укажите путь через <code>INSTALL_KEY_FILE</code>.
    </p>

    <footer>PHP Framework Installer <?= e(INSTALLER_VERSION) ?> · Михаил Губин</footer>
</main>
</body>
</html><?php
}

function renderBlocked(): void
{
    $nonce = cspNonce();
    securityHeaders($nonce);
    ?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Веб-установщик заблокирован</title>
    <style nonce="<?= e($nonce) ?>">
        body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:#070b14;color:#e8eefc;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}
        main{max-width:560px;background:#101a2e;border:1px solid #253656;border-radius:16px;padding:28px;line-height:1.55}
        h1{margin:0 0 12px;font-size:22px}
        p{color:#94a3b8;margin:0 0 12px}
        code{color:#bae6fd;word-break:break-all}
        footer{margin-top:20px;padding-top:14px;border-top:1px solid #253656;color:#94a3b8;font-size:12px}
    </style>
</head>
<body>
<main>
    <h1>Веб-установщик заблокирован</h1>
    <p>Найден файл <code><?= e(lockFile()) ?></code>. Это защищает установщик от повторного использования после настройки.</p>
    <p>Удалите файл, чтобы снова открыть веб-интерфейс, или используйте CLI:</p>
    <p><code>php install.php install &lt;пакет&gt;</code></p>
    <footer>PHP Framework Installer <?= e(INSTALLER_VERSION) ?> · Михаил Губин</footer>
</main>
</body>
</html><?php
}

function renderApp(): void
{
    $nonce = cspNonce();
    securityHeaders($nonce);

    $catalog = frameworkCatalog();
    $environment = environmentInfo();
    $composer = $environment['composer'];
    $composerLabel = $composer['found']
        ? 'Composer ' . ($composer['version'] ?? '?')
        : 'Composer не найден';
    $diskLabel = $environment['disk_free'] !== null
        ? formatBytes($environment['disk_free']) . ' свободно'
        : 'Диск: н/д';

    $colorRules = '';
    foreach ($catalog as $framework) {
        $colorRules .= '.monogram[data-fw="' . e($framework['id']) . '"]{--fw:' . e($framework['color']) . "}\n";
    }
    $colorRules .= ".monogram[data-fw=\"custom\"]{--fw:#64748b}\n";

    $bootstrap = [
        'csrf' => csrfToken(),
        'catalog' => $catalog,
        'environment' => $environment,
        'base_dir' => baseDir(),
        'installer_version' => INSTALLER_VERSION,
    ];
    ?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>PHP Framework Installer</title>
    <style nonce="<?= e($nonce) ?>">
        :root{--bg:#070b14;--bg2:#0d1526;--panel:#101a2e;--card:#14203a;--card2:#182746;--line:#253656;--text:#e8eefc;--muted:#94a3b8;--accent:#38bdf8;--accent2:#818cf8;--green:#34d399;--yellow:#fbbf24;--red:#f87171;--radius:14px;--shadow:0 20px 60px rgba(0,0,0,.35)}
        *{box-sizing:border-box}
        html,body{margin:0;padding:0}
        body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:radial-gradient(1200px 600px at 10% -10%,#12305a 0,transparent 60%),radial-gradient(900px 500px at 110% 10%,#2a1b5e 0,transparent 55%),var(--bg);color:var(--text);min-height:100vh}
        a{color:var(--accent)}
        code{font-family:ui-monospace,SFMono-Regular,Consolas,"Liberation Mono",monospace;font-size:.86em}
        .topbar{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:16px;padding:14px 22px;background:rgba(7,11,20,.82);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
        .brand{display:flex;align-items:center;gap:12px;min-width:0}
        .logo{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;font-weight:800;letter-spacing:.5px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#04101f;box-shadow:0 8px 24px rgba(56,189,248,.35);flex:0 0 auto}
        .brand h1{font-size:17px;margin:0;line-height:1.2}
        .brand p{margin:2px 0 0;color:var(--muted);font-size:12px}
        .env-chips{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}
        .chip{font-size:12px;color:var(--muted);border:1px solid var(--line);background:var(--panel);padding:6px 10px;border-radius:999px;white-space:nowrap}
        .chip.warn{color:var(--yellow);border-color:rgba(251,191,36,.35)}
        .tabs{display:flex;gap:6px;padding:10px 22px 0;border-bottom:1px solid var(--line);background:rgba(7,11,20,.4);position:sticky;top:71px;z-index:19;overflow-x:auto}
        .tab{appearance:none;border:1px solid transparent;border-bottom:none;background:transparent;color:var(--muted);padding:10px 16px;border-radius:10px 10px 0 0;cursor:pointer;font:inherit;font-size:14px;white-space:nowrap}
        .tab:hover{color:var(--text);background:rgba(255,255,255,.03)}
        .tab.active{color:var(--text);background:var(--panel);border-color:var(--line)}
        main{padding:22px;max-width:1500px;margin:0 auto}
        .view{display:none}
        .view.active{display:block}
        .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:18px}
        .toolbar input[type=search],.toolbar input[type=text]{flex:1 1 260px;min-width:220px;background:var(--panel);border:1px solid var(--line);color:var(--text);padding:11px 14px;border-radius:10px;font:inherit;outline:none}
        .toolbar input:focus,.panel input:focus,.panel select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(56,189,248,.15)}
        .custom-package{display:flex;gap:8px;flex:1 1 340px}
        .custom-package input{flex:1}
        .layout{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:18px;align-items:start}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
        .fw-card{position:relative;background:linear-gradient(180deg,var(--card),var(--card2));border:1px solid var(--line);border-radius:var(--radius);padding:16px;cursor:pointer;transition:transform .15s ease,border-color .15s ease,box-shadow .15s ease;outline:none}
        .fw-card:hover{transform:translateY(-2px);border-color:#3b5b8a;box-shadow:0 12px 30px rgba(0,0,0,.25)}
        .fw-card:focus-visible{box-shadow:0 0 0 3px rgba(56,189,248,.35)}
        .fw-card.selected{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent),0 16px 40px rgba(56,189,248,.12)}
        .fw-head{display:flex;gap:12px;align-items:center;margin-bottom:10px}
        .monogram{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-weight:800;font-size:15px;color:#fff;background:var(--fw,#64748b);flex:0 0 auto;box-shadow:inset 0 0 0 1px rgba(255,255,255,.15)}
        <?= $colorRules ?>
        .fw-head h3{font-size:16px;margin:0}
        .fw-head code{color:var(--muted);font-size:11px}
        .fw-card p{color:var(--muted);font-size:13px;line-height:1.45;margin:0 0 12px;min-height:38px}
        .badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
        .badge{font-size:11px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--muted);padding:4px 8px;border-radius:999px;white-space:nowrap}
        .badge.latest{color:var(--green);border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.08)}
        .badge.warn{color:var(--yellow);border-color:rgba(251,191,36,.35)}
        .tags{display:flex;gap:6px;flex-wrap:wrap}
        .tags span{font-size:10px;color:#7dd3fc;background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.18);padding:3px 7px;border-radius:6px}
        .panel{position:sticky;top:132px;background:linear-gradient(180deg,var(--panel),#0d1729);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:var(--shadow)}
        .panel-empty{color:var(--muted);font-size:14px;line-height:1.5;padding:18px 6px;text-align:center}
        .panel h2{font-size:19px;margin:0 0 4px}
        .panel .pkg{color:var(--muted);font-size:12px;margin-bottom:14px}
        .panel label{display:block;font-size:12px;color:var(--muted);margin:12px 0 6px}
        .panel select,.panel input[type=text]{width:100%;background:#0a1324;border:1px solid var(--line);color:var(--text);padding:10px 12px;border-radius:9px;font:inherit;outline:none}
        .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .row + .row{margin-top:8px}
        .panel .note{font-size:12px;color:var(--yellow);background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);padding:8px 10px;border-radius:8px;margin-top:10px;line-height:1.4}
        .panel .issues{font-size:12px;color:var(--red);background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.25);padding:8px 10px;border-radius:8px;margin-top:10px;line-height:1.4}
        .panel .issues ul{margin:4px 0 0;padding-left:18px}
        .options{display:grid;grid-template-columns:1fr 1fr;gap:6px 10px;margin-top:10px}
        .options label{display:flex;align-items:center;gap:7px;margin:0;font-size:12px;color:var(--text);background:rgba(255,255,255,.02);border:1px solid var(--line);padding:7px 8px;border-radius:8px;cursor:pointer}
        .options input{accent-color:var(--accent)}
        .btn{appearance:none;border:1px solid var(--line);background:var(--card2);color:var(--text);padding:10px 14px;border-radius:10px;font:inherit;font-size:14px;cursor:pointer;transition:filter .15s ease,transform .15s ease}
        .btn:hover{filter:brightness(1.12)}
        .btn:active{transform:translateY(1px)}
        .btn.primary{background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;color:#04101f;font-weight:700}
        .btn.ghost{background:transparent}
        .btn.danger{color:var(--red);border-color:rgba(248,113,113,.35)}
        .btn:disabled{opacity:.55;cursor:not-allowed;transform:none}
        .btn.wide{width:100%;margin-top:14px}
        .status{font-size:12px;color:var(--muted);margin-top:10px;min-height:18px}
        .log{background:#050a12;border:1px solid var(--line);border-radius:10px;padding:12px;color:#cbd5e1;font-size:12px;line-height:1.45;max-height:320px;overflow:auto;white-space:pre-wrap;word-break:break-word;margin:10px 0 0}
        .log.small{max-height:260px}
        .section-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}
        .section-head h2{margin:0 0 4px;font-size:20px}
        .section-head p{margin:0;color:var(--muted);font-size:13px}
        .projects{background:var(--panel);border:1px solid var(--line);border-radius:14px;overflow:auto}
        .table{width:100%;border-collapse:collapse;font-size:13px;min-width:760px}
        .table th,.table td{padding:11px 12px;text-align:left;border-bottom:1px solid var(--line);vertical-align:middle}
        .table th{color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.04em;background:rgba(255,255,255,.02);position:sticky;top:0}
        .table tr:last-child td{border-bottom:none}
        .table code{color:#bae6fd}
        .status-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;padding:4px 8px;border-radius:999px;border:1px solid var(--line);color:var(--muted);white-space:nowrap}
        .status-pill.ok{color:var(--green);border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.08)}
        .status-pill.update{color:var(--yellow);border-color:rgba(251,191,36,.35);background:rgba(251,191,36,.08)}
        .status-pill.unknown{color:var(--muted)}
        .empty{padding:40px;text-align:center;color:var(--muted);font-size:14px}
        .checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-bottom:18px}
        .check{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px}
        .check .check-head{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:6px}
        .check .check-head strong{font-size:13px}
        .check p{margin:0;color:var(--muted);font-size:12px;line-height:1.4;word-break:break-word}
        .check .dot{width:9px;height:9px;border-radius:50%;background:var(--muted);flex:0 0 auto}
        .check.ok .dot{background:var(--green);box-shadow:0 0 10px rgba(52,211,153,.6)}
        .check.warn .dot{background:var(--yellow);box-shadow:0 0 10px rgba(251,191,36,.6)}
        .check.fail .dot{background:var(--red);box-shadow:0 0 10px rgba(248,113,113,.6)}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px}
        .card h3{margin:0 0 12px;font-size:15px}
        .card dl{display:grid;grid-template-columns:auto 1fr;gap:7px 12px;margin:0;font-size:12px}
        .card dt{color:var(--muted)}
        .card dd{margin:0;word-break:break-word}
        .toast{position:fixed;right:20px;bottom:20px;max-width:min(420px,calc(100vw - 40px));background:#0b1528;border:1px solid var(--line);border-left:4px solid var(--accent);border-radius:12px;padding:12px 14px;box-shadow:var(--shadow);font-size:13px;line-height:1.4;opacity:0;transform:translateY(12px);pointer-events:none;transition:opacity .2s ease,transform .2s ease;z-index:50}
        .toast.show{opacity:1;transform:none}
        .toast.error{border-left-color:var(--red)}
        .toast.success{border-left-color:var(--green)}
        .spinner{width:18px;height:18px;border:2px solid rgba(255,255,255,.2);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite;display:inline-block;vertical-align:-3px;margin-right:8px}
        @keyframes spin{to{transform:rotate(360deg)}}
        footer.app-footer{max-width:1500px;margin:0 auto;padding:8px 22px 26px;color:var(--muted);font-size:11px;text-align:right}
        @media(max-width:1100px){.layout{grid-template-columns:1fr}.panel{position:static}.grid-2{grid-template-columns:1fr}}
        @media(max-width:640px){main{padding:14px}.topbar{padding:12px 14px;flex-wrap:wrap}.env-chips{order:3;width:100%;margin-left:0}.tabs{top:auto;position:static;padding:8px 14px 0}.options{grid-template-columns:1fr}.section-head{flex-direction:column}.custom-package{flex:1 1 100%}footer.app-footer{padding:8px 14px 20px}}
    </style>
</head>
<body>
<header class="topbar">
    <div class="brand">
        <span class="logo">PI</span>
        <div>
            <h1>PHP Framework Installer</h1>
            <p>Установка и проверка версий PHP-фреймворков</p>
        </div>
    </div>
    <div class="env-chips">
        <span class="chip">PHP <?= e($environment['php']) ?></span>
        <span class="chip"><?= e($composerLabel) ?></span>
        <span class="chip"><?= e($diskLabel) ?></span>
        <?php if (!$environment['https']): ?>
            <span class="chip warn">HTTP без TLS</span>
        <?php endif; ?>
    </div>
    <a class="btn ghost" href="?action=logout">Выйти</a>
</header>

<nav class="tabs" role="tablist" aria-label="Разделы установщика">
    <button class="tab active" type="button" data-view="install" role="tab" aria-selected="true">Установка</button>
    <button class="tab" type="button" data-view="projects" role="tab" aria-selected="false">Установленные проекты</button>
    <button class="tab" type="button" data-view="doctor" role="tab" aria-selected="false">Диагностика</button>
</nav>

<main>
    <section id="view-install" class="view active" role="tabpanel">
        <div class="toolbar">
            <input id="search" type="search" placeholder="Поиск по названию, пакету, категории" autocomplete="off">
            <button id="refresh-catalog" class="btn ghost" type="button">Обновить каталог</button>
            <div class="custom-package">
                <input id="custom-package" type="text" placeholder="Свой пакет: vendor/package" autocomplete="off" spellcheck="false">
                <button id="custom-check" class="btn ghost" type="button">Проверить</button>
            </div>
        </div>
        <div class="layout">
            <div id="grid" class="grid" aria-live="polite"></div>
            <aside id="panel" class="panel">
                <div class="panel-empty">Выберите фреймворк слева, чтобы увидеть версии и параметры установки.</div>
            </aside>
        </div>
    </section>

    <section id="view-projects" class="view" role="tabpanel">
        <div class="section-head">
            <div>
                <h2>Установленные проекты</h2>
                <p>Поиск выполняется в <?= e($environment['base_dir']) ?> на глубину до <?= e(maxScanDepth()) ?> уровней.</p>
            </div>
            <button id="refresh-projects" class="btn ghost" type="button">Проверить обновления</button>
        </div>
        <div id="projects" class="projects">
            <div class="empty">Откройте вкладку, чтобы выполнить поиск.</div>
        </div>
    </section>

    <section id="view-doctor" class="view" role="tabpanel">
        <div class="section-head">
            <div>
                <h2>Диагностика</h2>
                <p>Проверка PHP, Composer, расширений и прав доступа.</p>
            </div>
            <div class="row">
                <button id="install-composer" class="btn ghost" type="button">Проверить Composer</button>
                <button id="refresh-doctor" class="btn ghost" type="button">Обновить</button>
            </div>
        </div>
        <div id="doctor-checks" class="checks"></div>
        <div class="grid-2">
            <div class="card">
                <h3>Окружение</h3>
                <dl id="doctor-info"></dl>
            </div>
            <div class="card">
                <h3>Журнал установок</h3>
                <pre id="doctor-log" class="log small"></pre>
                <div class="row">
                    <button id="clear-cache" class="btn ghost" type="button">Очистить кэш</button>
                </div>
                <pre id="composer-log" class="log small" hidden></pre>
            </div>
        </div>
    </section>
</main>

<footer class="app-footer">PHP Framework Installer <?= e(INSTALLER_VERSION) ?> · Михаил Губин</footer>
<div id="toast" class="toast" role="status" aria-live="polite"></div>

<script nonce="<?= e($nonce) ?>">
window.__INSTALLER__ = <?= jsonForScript($bootstrap) ?>;
(() => {
    'use strict';

    const DATA = window.__INSTALLER__ || {};
    const state = {
        catalog: Array.isArray(DATA.catalog) ? DATA.catalog.slice() : [],
        selected: null,
        versions: null,
        installing: false,
        summaries: new Map(),
        projects: [],
        tab: 'install'
    };

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[char]));
    }

    function fmtBytes(bytes) {
        const value = Number(bytes);
        if (!Number.isFinite(value) || value <= 0) {
            return '0 Б';
        }
        const units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        const power = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
        return (value / Math.pow(1024, power)).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + ' ' + units[power];
    }

    function fmtDate(value) {
        if (!value) {
            return '';
        }
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return String(value).slice(0, 10);
        }
        return date.toLocaleDateString('ru-RU');
    }

    function cmpVersion(a, b) {
        const normalize = (value) => String(value ?? '').replace(/^v/i, '').replace(/-p(\d+)$/i, '.$1');
        const left = normalize(a).split(/[.+-]/).map((part) => parseInt(part, 10) || 0);
        const right = normalize(b).split(/[.+-]/).map((part) => parseInt(part, 10) || 0);
        const length = Math.max(left.length, right.length);
        for (let index = 0; index < length; index += 1) {
            const x = left[index] || 0;
            const y = right[index] || 0;
            if (x > y) return 1;
            if (x < y) return -1;
        }
        return 0;
    }

    function toast(message, type = '') {
        const element = $('#toast');
        if (!element) return;
        element.textContent = String(message);
        element.className = 'toast show ' + type;
        window.clearTimeout(toast.timer);
        toast.timer = window.setTimeout(() => {
            element.className = 'toast ' + type;
        }, 5200);
    }

    async function apiFetch(url, options = {}) {
        const response = await fetch(url, options);
        if (response.status === 401) {
            window.location.reload();
            throw new Error('Сессия истекла');
        }
        return response;
    }

    function postHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-Token': DATA.csrf || ''
        };
    }

    function setTab(name) {
        state.tab = name;
        $$('.tab').forEach((button) => {
            const active = button.dataset.view === name;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        $$('.view').forEach((view) => {
            view.classList.toggle('active', view.id === 'view-' + name);
        });
        if (name === 'projects') {
            loadProjects();
        }
        if (name === 'doctor') {
            loadDoctor();
        }
    }

    $$('.tab').forEach((button) => {
        button.addEventListener('click', () => setTab(button.dataset.view));
    });

    const grid = $('#grid');
    const search = $('#search');

    function filteredCatalog() {
        const query = (search?.value || '').trim().toLowerCase();
        if (!query) {
            return state.catalog;
        }
        return state.catalog.filter((framework) => {
            return [framework.name, framework.package, framework.description, (framework.tags || []).join(' ')]
                .join(' ').toLowerCase().includes(query);
        });
    }

    function renderGrid() {
        if (!grid) return;
        const items = filteredCatalog();
        if (!items.length) {
            grid.innerHTML = '<div class="empty">Ничего не найдено.</div>';
            return;
        }
        grid.innerHTML = items.map((framework) => {
            const summary = state.summaries.get(framework.package);
            const latest = summary?.latest?.version || summary?.latest_compatible?.version || '';
            const php = summary?.latest?.php || summary?.latest_compatible?.php || '';
            const selected = state.selected?.id === framework.id;
            return `<article class="fw-card${selected ? ' selected' : ''}" data-id="${esc(framework.id)}" tabindex="0" role="button" aria-pressed="${selected ? 'true' : 'false'}">
                <div class="fw-head">
                    <span class="monogram" data-fw="${esc(framework.id)}">${esc(framework.monogram)}</span>
                    <div><h3>${esc(framework.name)}</h3><code>${esc(framework.package)}</code></div>
                </div>
                <p>${esc(framework.description)}</p>
                <div class="badges">
                    <span class="badge latest" data-role="latest">${latest ? 'v ' + esc(latest.replace(/^v/i, '')) : 'загрузка...'}</span>
                    ${php ? `<span class="badge">PHP ${esc(php)}</span>` : ''}
                    ${framework.heavy ? '<span class="badge warn">тяжёлый</span>' : ''}
                </div>
                <div class="tags">${(framework.tags || []).map((tag) => `<span>${esc(tag)}</span>`).join('')}</div>
            </article>`;
        }).join('');
    }

    function updateCard(framework) {
        const card = $$('.fw-card').find((element) => element.dataset.id === framework.id);
        if (!card) return;
        const badge = card.querySelector('[data-role="latest"]');
        if (!badge) return;
        const summary = state.summaries.get(framework.package);
        if (summary?.error) {
            badge.textContent = 'н/д';
            badge.title = summary.error;
            return;
        }
        const latest = summary?.latest?.version || summary?.latest_compatible?.version || '';
        badge.textContent = latest ? 'v ' + latest.replace(/^v/i, '') : '—';
        badge.title = latest ? 'Последняя стабильная версия' : '';
    }

    function selectById(id) {
        const framework = state.catalog.find((item) => item.id === id);
        if (framework) {
            selectFramework(framework);
        }
    }

    grid?.addEventListener('click', (event) => {
        const card = event.target.closest('.fw-card');
        if (card) {
            selectById(card.dataset.id);
        }
    });

    grid?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        const card = event.target.closest('.fw-card');
        if (card) {
            event.preventDefault();
            selectById(card.dataset.id);
        }
    });

    search?.addEventListener('input', renderGrid);

    function selectFramework(framework) {
        state.selected = framework;
        state.versions = null;
        renderGrid();
        renderPanelLoading(framework);
        loadVersions(framework.package).catch((error) => {
            const panel = $('#panel');
            if (panel) {
                panel.innerHTML = `<div class="panel-empty">${esc(error.message)}</div>`;
            }
        });
    }

    function renderPanelLoading(framework) {
        const panel = $('#panel');
        if (!panel) return;
        panel.innerHTML = `<h2>${esc(framework.name)}</h2>
            <div class="pkg">${esc(framework.package)}</div>
            <div class="panel-empty"><span class="spinner"></span>Загрузка версий из Packagist...</div>`;
    }

    async function loadVersions(packageName, refresh = false) {
        const url = `?api=versions&package=${encodeURIComponent(packageName)}${refresh ? '&refresh=1' : ''}`;
        const response = await apiFetch(url);
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || 'Не удалось получить версии');
        }
        if (!state.selected || state.selected.package !== packageName) {
            return;
        }
        state.versions = data;
        renderPanel(state.selected, data);
    }

    function renderPanel(framework, data) {
        const panel = $('#panel');
        if (!panel || !framework) return;

        const versions = Array.isArray(data.versions) ? data.versions : [];
        const stable = versions.filter((version) => version.stable);
        const prerelease = versions.filter((version) => !version.stable);
        const latest = data.latest?.version || '';
        const compatible = data.latest_compatible?.version || latest;

        let options = '';
        if (stable.length) {
            options += '<optgroup label="Стабильные">' + stable.slice(0, 80).map((version) => {
                return `<option value="${esc(version.version)}"${version.version === compatible ? ' selected' : ''}>${esc(version.version)}${version.time ? ' — ' + esc(fmtDate(version.time)) : ''}</option>`;
            }).join('') + '</optgroup>';
        }
        if (prerelease.length) {
            options += '<optgroup label="Pre-release">' + prerelease.slice(0, 20).map((version) => {
                return `<option value="${esc(version.version)}"${version.version === compatible ? ' selected' : ''}>${esc(version.version)}${version.time ? ' — ' + esc(fmtDate(version.time)) : ''}</option>`;
            }).join('') + '</optgroup>';
        }
        if (!options) {
            options = '<option value="">Нет доступных версий</option>';
        }

        const branches = (data.branches || []).map((branch) => {
            return `<span class="badge">${esc(branch.branch)}.x → ${esc(branch.version)}</span>`;
        }).join('');
        const note = framework.note ? `<div class="note">${esc(framework.note)}</div>` : '';
        const stale = data.stale ? `<div class="note">${esc(data.warning || 'Используются устаревшие данные.')}</div>` : '';

        panel.innerHTML = `<h2>${esc(framework.name)}</h2>
            <div class="pkg">${esc(framework.package)}</div>
            <div class="row">
                <span class="badge latest">Последняя: ${latest ? esc(latest) : '—'}</span>
                <span class="badge">Совместимая: ${compatible ? esc(compatible) : '—'}</span>
            </div>
            ${branches ? `<div class="row">${branches}</div>` : ''}
            ${note}${stale}
            <label for="version-select">Версия</label>
            <select id="version-select">${options}</select>
            <div id="version-meta"></div>
            <label for="target-input">Папка установки</label>
            <input id="target-input" type="text" value="${esc(framework.target)}" spellcheck="false" autocomplete="off">
            <div class="options">
                <label><input type="checkbox" id="opt-no-dev"> Без dev-зависимостей</label>
                <label><input type="checkbox" id="opt-no-scripts"> Без скриптов</label>
                <label><input type="checkbox" id="opt-no-plugins"> Без плагинов</label>
                <label><input type="checkbox" id="opt-prefer-source"> Из исходников</label>
                <label><input type="checkbox" id="opt-ignore-platform"> Игнорировать платформу</label>
                <label><input type="checkbox" id="opt-no-audit"> Без аудита</label>
                <label><input type="checkbox" id="opt-no-blocking"> Без блокировок</label>
                <label><input type="checkbox" id="opt-cleanup"> Удалить при ошибке</label>
            </div>
            <button id="install-button" class="btn primary wide" type="button">Установить</button>
            <div id="install-status" class="status" aria-live="polite"></div>
            <pre id="install-console" class="log" hidden></pre>`;

        $('#version-select')?.addEventListener('change', updateVersionInfo);
        $('#install-button')?.addEventListener('click', startInstall);
        updateVersionInfo();
    }

    function updateVersionInfo() {
        const select = $('#version-select');
        const meta = $('#version-meta');
        const button = $('#install-button');
        if (!select || !meta) return;

        const version = select.value;
        const entry = (state.versions?.versions || []).find((item) => item.version === version);
        const compatible = state.versions?.latest_compatible?.version || '';
        let html = '';

        if (entry) {
            html += `<div class="row">
                <span class="badge">PHP ${esc(entry.php || '—')}</span>
                <span class="badge">${entry.stable ? 'stable' : 'pre-release'}</span>
                ${entry.time ? `<span class="badge">${esc(fmtDate(entry.time))}</span>` : ''}
            </div>`;
            if (entry.issues && entry.issues.length) {
                html += `<div class="issues"><strong>Замечания:</strong><ul>${entry.issues.map((issue) => `<li>${esc(issue)}</li>`).join('')}</ul></div>`;
            }
            if (compatible && version !== compatible && cmpVersion(version, compatible) < 0) {
                html += `<div class="note">Доступна более новая совместимая версия: ${esc(compatible)}.</div>`;
            }
        }

        meta.innerHTML = html;
        if (button) {
            button.disabled = !version || state.installing;
        }
    }

    function appendConsole(line, stream = 'out') {
        const element = $('#install-console');
        if (!element) return;
        element.hidden = false;
        const text = (stream === 'err' ? '! ' : '') + String(line ?? '');
        const lines = element.textContent ? element.textContent.split('\n') : [];
        lines.push(text);
        if (lines.length > 2000) {
            lines.splice(0, lines.length - 2000);
        }
        element.textContent = lines.join('\n');
        element.scrollTop = element.scrollHeight;
    }

    async function readNdjson(response, handler) {
        if (!response.body || !response.body.getReader) {
            const text = await response.text();
            text.split(/\r?\n/).forEach((line) => {
                if (line.trim()) {
                    try {
                        handler(JSON.parse(line));
                    } catch (error) {
                        appendConsole(line, 'out');
                    }
                }
            });
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });
            let position;
            while ((position = buffer.indexOf('\n')) >= 0) {
                const line = buffer.slice(0, position).trim();
                buffer = buffer.slice(position + 1);
                if (!line) continue;
                try {
                    handler(JSON.parse(line));
                } catch (error) {
                    appendConsole(line, 'out');
                }
            }
        }
        const rest = buffer.trim();
        if (rest) {
            try {
                handler(JSON.parse(rest));
            } catch (error) {
                appendConsole(rest, 'out');
            }
        }
    }

    async function startInstall() {
        if (state.installing || !state.selected) {
            return;
        }

        const version = $('#version-select')?.value || '';
        const target = ($('#target-input')?.value || '').trim();
        const options = {
            no_dev: $('#opt-no-dev')?.checked || false,
            no_scripts: $('#opt-no-scripts')?.checked || false,
            no_plugins: $('#opt-no-plugins')?.checked || false,
            prefer_source: $('#opt-prefer-source')?.checked || false,
            ignore_platform_reqs: $('#opt-ignore-platform')?.checked || false,
            no_audit: $('#opt-no-audit')?.checked || false,
            no_blocking: $('#opt-no-blocking')?.checked || false,
            cleanup: $('#opt-cleanup')?.checked || false
        };

        if (!version) {
            toast('Выберите версию.', 'error');
            return;
        }
        if (!/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/.test(target) || target.includes('..')) {
            toast('Некорректная папка установки.', 'error');
            return;
        }
        if (!window.confirm(`Установить ${state.selected.package} ${version} в папку «${target}»?`)) {
            return;
        }

        state.installing = true;
        const button = $('#install-button');
        const status = $('#install-status');
        const consoleElement = $('#install-console');
        if (button) {
            button.disabled = true;
            button.textContent = 'Установка...';
        }
        if (status) status.textContent = 'Подготовка...';
        if (consoleElement) {
            consoleElement.hidden = false;
            consoleElement.textContent = '';
        }
        appendConsole('Запуск установки ' + state.selected.package + ' ' + version, 'out');

        try {
            const response = await apiFetch('?api=install', {
                method: 'POST',
                headers: postHeaders(),
                body: JSON.stringify({
                    package: state.selected.package,
                    version,
                    target,
                    options
                })
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({ error: 'HTTP ' + response.status }));
                throw new Error(data.error || ('HTTP ' + response.status));
            }

            await readNdjson(response, (event) => {
                if (event.type === 'log') {
                    appendConsole(event.line, event.stream);
                } else if (event.type === 'status') {
                    if (status) status.textContent = event.message;
                } else if (event.type === 'error') {
                    appendConsole(event.error, 'err');
                    throw new Error(event.error);
                } else if (event.type === 'done') {
                    const result = event.result || {};
                    appendConsole('Готово: ' + (result.path || target) + ' (' + (result.version || version) + ')', 'out');
                    if (status) status.textContent = 'Установка завершена.';
                    toast('Установка завершена: ' + (result.path || target), 'success');
                    state.summaries.delete(state.selected.package);
                    loadSummary(state.selected);
                    loadProjects();
                }
            });
        } catch (error) {
            appendConsole('Ошибка: ' + error.message, 'err');
            if (status) status.textContent = 'Ошибка установки.';
            toast(error.message, 'error');
        } finally {
            state.installing = false;
            if (button) {
                button.disabled = false;
                button.textContent = 'Установить';
            }
            updateVersionInfo();
        }
    }

    let summaryQueue = [];
    let summaryActive = 0;

    function queueSummaries() {
        summaryQueue = state.catalog.filter((framework) => !state.summaries.has(framework.package));
        const pump = () => {
            while (summaryActive < 3 && summaryQueue.length) {
                const framework = summaryQueue.shift();
                summaryActive += 1;
                loadSummary(framework).finally(() => {
                    summaryActive -= 1;
                    pump();
                });
            }
        };
        pump();
    }

    async function loadSummary(framework) {
        try {
            const response = await apiFetch(`?api=versions&package=${encodeURIComponent(framework.package)}&summary=1`);
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.error || 'Нет данных');
            }
            state.summaries.set(framework.package, data);
            updateCard(framework);
        } catch (error) {
            state.summaries.set(framework.package, { error: error.message });
            updateCard(framework);
        }
    }

    async function loadProjects() {
        const container = $('#projects');
        if (container) {
            container.innerHTML = '<div class="empty"><span class="spinner"></span>Поиск проектов...</div>';
        }
        try {
            const response = await apiFetch('?api=detect');
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.error || 'Не удалось выполнить поиск');
            }
            state.projects = data.projects || [];
            renderProjects();

            const queue = state.projects
                .map((project, index) => project.compare_package ? index : -1)
                .filter((index) => index >= 0);
            let active = 0;
            const pump = () => {
                while (active < 3 && queue.length) {
                    const index = queue.shift();
                    active += 1;
                    fillProjectLatest(index).finally(() => {
                        active -= 1;
                        pump();
                    });
                }
            };
            pump();
        } catch (error) {
            if (container) {
                container.innerHTML = `<div class="empty">${esc(error.message)}</div>`;
            }
        }
    }

    function projectStatus(project) {
        if (!project.installed) {
            return ['unknown', 'не определена'];
        }
        if (!project.latest) {
            return ['unknown', 'проверка...'];
        }
        return cmpVersion(project.installed, project.latest) >= 0
            ? ['ok', 'актуально']
            : ['update', 'обновление'];
    }

    function projectRow(project, index) {
        const status = projectStatus(project);
        return `<tr data-index="${index}">
            <td><strong>${esc(project.name)}</strong><br><code>${esc(project.package)}</code></td>
            <td>${esc(project.framework)}</td>
            <td><code>${esc(project.installed || '—')}</code>${project.constraint ? `<br><span class="badge">${esc(project.constraint)}</span>` : ''}</td>
            <td><code data-role="latest">${esc(project.latest || '—')}</code></td>
            <td><span class="status-pill ${status[0]}" data-role="status">${status[1]}</span></td>
            <td><code>${esc(project.path)}</code></td>
            <td><button class="btn ghost" type="button" data-copy="${esc(project.abs_path || project.path)}">Копировать</button></td>
        </tr>`;
    }

    function renderProjects() {
        const container = $('#projects');
        if (!container) return;
        if (!state.projects.length) {
            container.innerHTML = '<div class="empty">Composer-проекты не найдены. Установите первый фреймворк.</div>';
            return;
        }
        container.innerHTML = `<table class="table">
            <thead><tr>
                <th>Проект</th><th>Фреймворк</th><th>Установлено</th><th>Последняя</th><th>Статус</th><th>Путь</th><th></th>
            </tr></thead>
            <tbody>${state.projects.map((project, index) => projectRow(project, index)).join('')}</tbody>
        </table>`;
        $$('[data-copy]', container).forEach((button) => {
            button.addEventListener('click', () => copyText(button.dataset.copy));
        });
    }

    async function fillProjectLatest(index) {
        const project = state.projects[index];
        if (!project || !project.compare_package) return;
        try {
            const response = await apiFetch(`?api=versions&package=${encodeURIComponent(project.compare_package)}&summary=1`);
            const data = await response.json();
            if (!data.ok) return;
            project.latest = data.latest?.version || data.latest_compatible?.version || '';
            project.latest_php = data.latest?.php || '';
            updateProjectRow(index);
        } catch (error) {
            // Leave the latest version unknown.
        }
    }

    function updateProjectRow(index) {
        const project = state.projects[index];
        const row = document.querySelector(`tr[data-index="${index}"]`);
        if (!project || !row) return;
        const latestCell = row.querySelector('[data-role="latest"]');
        if (latestCell) {
            latestCell.textContent = project.latest || '—';
        }
        const status = projectStatus(project);
        const statusCell = row.querySelector('[data-role="status"]');
        if (statusCell) {
            statusCell.className = 'status-pill ' + status[0];
            statusCell.textContent = status[1];
        }
    }

    async function copyText(text) {
        try {
            await navigator.clipboard.writeText(String(text));
            toast('Путь скопирован.', 'success');
        } catch (error) {
            const area = document.createElement('textarea');
            area.value = String(text);
            document.body.appendChild(area);
            area.select();
            try {
                document.execCommand('copy');
                toast('Путь скопирован.', 'success');
            } catch (copyError) {
                toast('Не удалось скопировать путь.', 'error');
            }
            area.remove();
        }
    }

    async function loadDoctor() {
        const checks = $('#doctor-checks');
        if (checks) {
            checks.innerHTML = '<div class="empty"><span class="spinner"></span>Проверка окружения...</div>';
        }
        try {
            const response = await apiFetch('?api=doctor');
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.error || 'Ошибка диагностики');
            }
            renderDoctor(data);
        } catch (error) {
            if (checks) {
                checks.innerHTML = `<div class="empty">${esc(error.message)}</div>`;
            }
        }
    }

    function renderDoctor(data) {
        const checks = $('#doctor-checks');
        if (checks) {
            checks.innerHTML = (data.checks || []).map((check) => {
                return `<div class="check ${esc(check.status)}">
                    <div class="check-head"><strong>${esc(check.label)}</strong><span class="dot"></span></div>
                    <p>${esc(check.value)}</p>
                    ${check.hint ? `<p>${esc(check.hint)}</p>` : ''}
                </div>`;
            }).join('');
        }

        const info = $('#doctor-info');
        const env = data.info || {};
        if (info) {
            const rows = [
                ['PHP', env.php],
                ['SAPI', env.sapi],
                ['ОС', (env.os || '—') + ' / ' + (env.arch || '—')],
                ['Каталог', env.base_dir],
                ['Свободно', fmtBytes(env.disk_free)],
                ['Composer', env.composer?.version || (env.composer?.found ? 'найден' : 'не найден')],
                ['Ключ', env.key_file],
                ['Кэш', env.cache_dir],
                ['Журнал', env.log_file],
                ['install.lock', env.install_lock ? 'есть' : 'нет']
            ];
            info.innerHTML = rows.map(([key, value]) => `<dt>${esc(key)}</dt><dd>${esc(value ?? '—')}</dd>`).join('');
        }

        const log = $('#doctor-log');
        if (log) {
            log.textContent = (data.log || []).join('\n') || 'Журнал пуст.';
        }
    }

    async function clearCache() {
        try {
            const response = await apiFetch('?api=clear-cache', {
                method: 'POST',
                headers: postHeaders(),
                body: '{}'
            });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.error || 'Не удалось очистить кэш');
            }
            toast('Кэш очищен: ' + data.removed + ' файлов.', 'success');
            state.summaries.clear();
            renderGrid();
            queueSummaries();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function checkComposer() {
        const log = $('#composer-log');
        const button = $('#install-composer');
        if (button) button.disabled = true;
        if (log) {
            log.hidden = false;
            log.textContent = '';
        }
        try {
            const response = await apiFetch('?api=composer', {
                method: 'POST',
                headers: postHeaders(),
                body: '{}'
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({ error: 'HTTP ' + response.status }));
                throw new Error(data.error || ('HTTP ' + response.status));
            }
            await readNdjson(response, (event) => {
                if (event.type === 'log') {
                    if (log) {
                        log.textContent += (event.stream === 'err' ? '! ' : '') + event.line + '\n';
                        log.scrollTop = log.scrollHeight;
                    }
                } else if (event.type === 'error') {
                    if (log) log.textContent += 'Ошибка: ' + event.error + '\n';
                    toast(event.error, 'error');
                } else if (event.type === 'done') {
                    const composer = event.composer || {};
                    toast('Composer ' + (composer.version || 'готов') + ' — ' + (composer.path || ''), 'success');
                    loadDoctor();
                }
            });
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            if (button) button.disabled = false;
        }
    }

    $('#refresh-catalog')?.addEventListener('click', () => {
        state.summaries.clear();
        renderGrid();
        queueSummaries();
        toast('Каталог обновлён.', 'success');
    });

    $('#refresh-projects')?.addEventListener('click', loadProjects);
    $('#refresh-doctor')?.addEventListener('click', loadDoctor);
    $('#clear-cache')?.addEventListener('click', clearCache);
    $('#install-composer')?.addEventListener('click', checkComposer);

    $('#custom-check')?.addEventListener('click', () => {
        const input = $('#custom-package');
        const value = (input?.value || '').trim().toLowerCase();
        if (!/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/.test(value)) {
            toast('Введите пакет в формате vendor/package.', 'error');
            return;
        }

        const name = value.split('/').pop().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'app';
        const framework = {
            id: 'custom',
            name: value,
            package: value,
            description: 'Произвольный Composer-пакет с типом project или skeleton.',
            homepage: 'https://packagist.org/packages/' + value,
            tags: ['Composer'],
            color: '#64748b',
            monogram: value.charAt(0).toUpperCase(),
            target: name,
            weight: 0,
            custom: true
        };

        state.catalog = state.catalog.filter((item) => item.id !== 'custom').concat(framework);
        state.summaries.delete(value);
        renderGrid();
        selectFramework(framework);
        loadSummary(framework);
    });

    $('#custom-package')?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            $('#custom-check')?.click();
        }
    });

    renderGrid();
    queueSummaries();
})();
</script>
</body>
</html><?php
}

function handleWeb(): void
{
    try {
        startSession();
        $nonce = cspNonce();
        securityHeaders($nonce);

        if (envFlag('INSTALL_WEB', true) === false) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Веб-интерфейс отключён переменной INSTALL_WEB=0. Используйте CLI.';
            return;
        }

        if (is_file(lockFile()) && !envFlag('INSTALL_ALLOW_LOCKED', false)) {
            http_response_code(403);
            renderBlocked();
            return;
        }

        $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
        if ($action === 'logout') {
            handleLogout();
            return;
        }
        if ($action === 'login') {
            handleLogin();
            return;
        }

        $api = (string) ($_GET['api'] ?? '');
        if ($api !== '') {
            if (!isAuthenticated()) {
                jsonOut(['ok' => false, 'error' => 'Требуется авторизация.'], 401);
            }
            switch ($api) {
                case 'registry':
                    apiRegistry();
                    return;
                case 'versions':
                    apiVersions();
                    return;
                case 'detect':
                    apiDetect();
                    return;
                case 'doctor':
                    apiDoctor();
                    return;
                case 'log':
                    apiLog();
                    return;
                case 'clear-cache':
                    apiClearCache();
                    return;
                case 'composer':
                    apiComposer();
                    return;
                case 'install':
                    apiInstall();
                    return;
                default:
                    jsonOut(['ok' => false, 'error' => 'Неизвестный API-метод.'], 404);
            }
        }

        if (!isAuthenticated()) {
            try {
                ensureKey();
            } catch (Throwable $e) {
                renderLogin('', $e->getMessage());
                return;
            }
            renderLogin();
            return;
        }

        renderApp();
    } catch (InstallerException $e) {
        $code = (int) $e->getCode();
        if (!in_array($code, [401, 403, 429], true)) {
            $code = 500;
        }
        installerLog('web error', ['error' => $e->getMessage(), 'code' => $code]);

        $wantsJson = isset($_GET['api']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if ($wantsJson && !headers_sent()) {
            jsonOut(['ok' => false, 'error' => $e->getMessage()], $code);
        }

        http_response_code($code);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Ошибка</title>'
            . '<body style="font-family:system-ui,sans-serif;background:#070b14;color:#e8eefc;padding:2rem">'
            . '<h1>Ошибка</h1><p>' . e($e->getMessage()) . '</p></body></html>';
    } catch (Throwable $e) {
        installerLog('web fatal', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Внутренняя ошибка установщика. Подробности записаны в журнал.';
    }
}

// ============================================================================
// Bootstrap
// ============================================================================

date_default_timezone_set(envValue('INSTALL_TZ', 'Europe/Moscow') ?? 'Europe/Moscow');

if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
    exit(cliMain($argv ?? []));
}

handleWeb();
