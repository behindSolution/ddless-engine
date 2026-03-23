<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * HTTP request entry point. Receives captured request payload from Electron,
 * reconstructs PHP superglobals ($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES),
 * and delegates to the appropriate framework handler.
 */

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', realpath(dirname(__DIR__)));
}

if (getenv('DDLESS_DEBUG_MODE') === 'true') {
    // Defer stream wrapper registration to avoid issues with Laravel bootstrap
    $GLOBALS['__DDLESS_DEFER_WRAPPER__'] = true;
    require_once __DIR__ . '/debug.php';
}

$payloadPath = getenv('DDLESS_PAYLOAD') ?: null;
$payloadContent = null;

if ($payloadPath && is_file($payloadPath)) {
    $payloadContent = file_get_contents($payloadPath);
}

if ($payloadContent === null) {
    foreach ($argv as $argument) {
        if (!is_string($argument)) {
            continue;
        }
        if (substr($argument, -5) === '.json' && is_file($argument)) {
            $payloadContent = file_get_contents($argument);
            break;
        }
    }
}

if ($payloadContent === null) {
    fwrite(STDERR, "Payload file not provided or unreadable." . PHP_EOL);
    exit(1);
}

$payload = json_decode($payloadContent, true) ?? [];

$headers = [];
if (isset($payload['headers']) && is_array($payload['headers'])) {
    $headers = $payload['headers'];
}
$normalizedHeaders = array_change_key_case($headers, CASE_LOWER);

$method = strtoupper($payload['method'] ?? 'GET');

$uriFragment = $payload['uri'] ?? ($payload['path'] ?? '/');
if (!is_string($uriFragment) || $uriFragment === '') {
    $uriFragment = '/';
}
if ($uriFragment[0] !== '/') {
    $uriFragment = '/' . ltrim($uriFragment, '/');
}

$queryParameters = [];
if (!empty($payload['query']) && is_array($payload['query'])) {
    $queryParameters = $payload['query'];
}

$queryString = '';
$uriParts = explode('?', $uriFragment, 2);
if (count($uriParts) === 2) {
    $queryString = $uriParts[1];
} elseif (!empty($queryParameters)) {
    $queryString = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);
    $uriFragment = $uriParts[0] . ($queryString !== '' ? '?' . $queryString : '');
}

// Scheme detection
$scheme = 'http';
$forwardedProto = $normalizedHeaders['x-forwarded-proto'] ?? null;
if (is_array($forwardedProto)) {
    $forwardedProto = reset($forwardedProto);
}
if (is_string($forwardedProto) && stripos($forwardedProto, 'https') !== false) {
    $scheme = 'https';
}

// Host parsing
$hostHeader = $normalizedHeaders['x-forwarded-host']
    ?? $normalizedHeaders['host']
    ?? '127.0.0.1';
if (is_array($hostHeader)) {
    $hostHeader = reset($hostHeader) ?: '127.0.0.1';
} elseif (!is_string($hostHeader) || trim($hostHeader) === '') {
    $hostHeader = '127.0.0.1';
}
$hostHeader = trim($hostHeader);

$authority = $scheme . '://' . $hostHeader;
$hostParts = @parse_url($authority);
$serverName = $hostParts['host'] ?? '127.0.0.1';

$forwardedPort = $normalizedHeaders['x-forwarded-port'] ?? null;
if (is_array($forwardedPort)) {
    $forwardedPort = reset($forwardedPort);
}
if (is_string($forwardedPort) && preg_match('/^\d+$/', trim($forwardedPort))) {
    $serverPort = (int) trim($forwardedPort);
} else {
    $serverPort = $hostParts['port'] ?? ($scheme === 'https' ? 443 : 80);
}

$path = $payload['path'] ?? $uriFragment;
if (!is_string($path) || $path === '') {
    $path = '/';
}

// Content-Type
$contentTypeHeader = $normalizedHeaders['content-type'] ?? null;
if (is_array($contentTypeHeader)) {
    $contentTypeHeader = reset($contentTypeHeader) ?: null;
}
$contentType = is_string($contentTypeHeader) ? $contentTypeHeader : null;

// Body parsing
$rawBody = null;
$decodedBody = $payload['body'] ?? null;
if (isset($payload['rawBody']) && is_string($payload['rawBody'])) {
    $rawBody = $payload['rawBody'];
} elseif (($payload['bodyEncoding'] ?? null) === 'base64' && isset($payload['body']) && is_string($payload['body'])) {
    $rawBody = base64_decode($payload['body'], true);
    if ($rawBody === false) {
        $rawBody = '';
    }
} elseif (is_string($decodedBody)) {
    $rawBody = $decodedBody;
}

// Form parameters
$formParameters = [];
if ($contentType !== null && stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
    $parsedForm = [];
    parse_str($rawBody ?? '', $parsedForm);
    $formParameters = $parsedForm;
    if (empty($formParameters) && is_array($decodedBody)) {
        $formParameters = $decodedBody;
    }
} elseif (is_array($decodedBody)) {
    if ($contentType !== null && stripos($contentType, 'application/json') !== false) {
        if ($rawBody === null || $rawBody === '') {
            $rawBody = json_encode($decodedBody);
        }
    } else {
        $formParameters = $decodedBody;
        if ($rawBody === null || $rawBody === '') {
            $rawBody = json_encode($decodedBody);
        }
    }
}

if ($rawBody === null) {
    $rawBody = '';
}

// Cookie parsing
$cookies = [];

if (isset($normalizedHeaders['cookies']) && is_array($normalizedHeaders['cookies']) && count($normalizedHeaders['cookies']) > 0) {
    $cookiesArray = array_filter(array_map('trim', $normalizedHeaders['cookies']), fn($c) => $c !== '');
    foreach ($cookiesArray as $cookieEntry) {
        $parts = explode('=', $cookieEntry, 2);
        $cookieName = trim($parts[0]);
        if ($cookieName === '') {
            continue;
        }
        // cookie values. urldecode() converts '+' to space which corrupts
        // Laravel's encrypted session/XSRF cookies.
        $cookieValue = isset($parts[1]) ? rawurldecode($parts[1]) : '';
        $cookies[$cookieName] = $cookieValue;
    }
}

// PHP Superglobals Setup
$projectRoot = dirname(__DIR__);
$publicIndex = $projectRoot . '/public/index.php';
$scriptFilename = is_file($publicIndex) ? $publicIndex : ($projectRoot . '/index.php');
$scriptName = '/index.php';
$documentRoot = dirname($scriptFilename);

// Resolve SCRIPT_FILENAME from URL path for traditional PHP apps
$urlPath = parse_url($uriFragment, PHP_URL_PATH) ?: '/';
$urlCandidate = ltrim(str_replace('\\', '/', $urlPath), '/');
if ($urlCandidate !== '' && substr($urlCandidate, -4) === '.php') {
    $candidateAbsolute = $projectRoot . '/' . $urlCandidate;
    if (is_file($candidateAbsolute)) {
        $scriptFilename = $candidateAbsolute;
        $scriptName = '/' . $urlCandidate;
        $documentRoot = $projectRoot;
    }
} elseif ($urlCandidate !== '' && strpos($urlCandidate, '.') === false) {
    $dirCandidate = rtrim($urlCandidate, '/') . '/index.php';
    $dirAbsolute = $projectRoot . '/' . $dirCandidate;
    if (is_file($dirAbsolute)) {
        $scriptFilename = $dirAbsolute;
        $scriptName = '/' . $dirCandidate;
        $documentRoot = $projectRoot;
    }
}

$server = array_merge($_SERVER, [
    'REQUEST_METHOD' => $method,
    'REQUEST_URI' => $uriFragment,
    'SERVER_NAME' => $serverName,
    'SERVER_PORT' => (string)$serverPort,
    'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'HTTP_HOST' => $hostHeader,
    'REQUEST_SCHEME' => $scheme,
    'HTTPS' => $scheme === 'https' ? 'on' : 'off',
    'QUERY_STRING' => $queryString,
    'SERVER_PROTOCOL' => $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1',
    'SCRIPT_NAME' => $scriptName,
    'PHP_SELF' => $scriptName,
    'SCRIPT_FILENAME' => $scriptFilename,
    'DOCUMENT_ROOT' => $documentRoot,
]);

if ($contentType !== null) {
    $server['CONTENT_TYPE'] = $contentType;
}

$contentLengthHeader = $normalizedHeaders['content-length'] ?? null;
if (is_array($contentLengthHeader)) {
    $contentLengthHeader = reset($contentLengthHeader);
}
if (is_string($contentLengthHeader) && $contentLengthHeader !== '') {
    $server['CONTENT_LENGTH'] = $contentLengthHeader;
} else {
    $server['CONTENT_LENGTH'] = (string)strlen($rawBody);
}

foreach ($headers as $name => $value) {
    if (!is_string($name) || $name === '') {
        continue;
    }
    $normalizedName = strtoupper(str_replace('-', '_', $name));
    if ($normalizedName === 'CONTENT_TYPE' || $normalizedName === 'CONTENT_LENGTH') {
        continue;
    }
    $serverKey = 'HTTP_' . $normalizedName;
    $server[$serverKey] = is_array($value) ? implode(', ', $value) : $value;
}

$phpQueryParameters = [];
if ($queryString !== '') {
    parse_str($queryString, $phpQueryParameters);
}
if (empty($phpQueryParameters) && !empty($queryParameters)) {
    $phpQueryParameters = $queryParameters;
}

$_SERVER = $server;

if (isset($payload['serverVariables']) && is_array($payload['serverVariables'])) {
    foreach ($payload['serverVariables'] as $key => $value) {
        if (is_string($key) && $key !== '' && is_string($value)) {
            $_SERVER[$key] = $value;
        }
    }
}

$_GET = $phpQueryParameters;
$_POST = $formParameters;
$_COOKIE = $cookies;
$_REQUEST = array_merge($_GET, $_POST);

$_FILES = [];
if (isset($payload['files']) && is_array($payload['files'])) {
    foreach ($payload['files'] as $fileEntry) {
        if (!is_array($fileEntry) || empty($fileEntry['fieldName']) || empty($fileEntry['fileName'])) {
            continue;
        }

        $fieldName = $fileEntry['fieldName'];
        $fileName = $fileEntry['fileName'];
        $fileContentType = $fileEntry['contentType'] ?? 'application/octet-stream';
        $fileContent = isset($fileEntry['content']) ? base64_decode($fileEntry['content'], true) : '';
        if ($fileContent === false) {
            $fileContent = '';
        }
        $fileSize = strlen($fileContent);

        $tmpPath = tempnam(sys_get_temp_dir(), 'ddless_upload_');
        if ($tmpPath !== false) {
            file_put_contents($tmpPath, $fileContent);

            $isArrayField = preg_match('/^(.+)\[([^\]]*)\]$/', $fieldName, $arrayMatch);

            if ($isArrayField) {
                $baseField = $arrayMatch[1];
                if (!isset($_FILES[$baseField])) {
                    $_FILES[$baseField] = [
                        'name' => [],
                        'type' => [],
                        'tmp_name' => [],
                        'error' => [],
                        'size' => [],
                    ];
                }
                $_FILES[$baseField]['name'][] = $fileName;
                $_FILES[$baseField]['type'][] = $fileContentType;
                $_FILES[$baseField]['tmp_name'][] = $tmpPath;
                $_FILES[$baseField]['error'][] = UPLOAD_ERR_OK;
                $_FILES[$baseField]['size'][] = $fileSize;
            } else {
                $_FILES[$fieldName] = [
                    'name' => $fileName,
                    'type' => $fileContentType,
                    'tmp_name' => $tmpPath,
                    'error' => UPLOAD_ERR_OK,
                    'size' => $fileSize,
                ];
            }
        }
    }
}

if (!empty($_FILES)) {
    register_shutdown_function(function () {
        foreach ($_FILES as $file) {
            if (isset($file['tmp_name'])) {
                $paths = is_array($file['tmp_name']) ? $file['tmp_name'] : [$file['tmp_name']];
                foreach ($paths as $path) {
                    if (is_string($path) && is_file($path)) {
                        @unlink($path);
                    }
                }
            }
        }
    });
}

$GLOBALS['__DDLESS_RAW_INPUT__'] = is_string($rawBody) ? $rawBody : '';

// Framework Handler
// Determine framework from environment variable (default: laravel)
$framework = getenv('DDLESS_FRAMEWORK') ?: 'laravel';
$frameworkHandler = __DIR__ . '/frameworks/' . $framework . '/http_request.php';

if (!is_file($frameworkHandler)) {
    fwrite(STDERR, "[ddless] Framework handler not found: {$frameworkHandler}\n");
    echo "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nDDLess: Framework handler not found for: {$framework}";
    exit(1);
}

require_once $frameworkHandler;
