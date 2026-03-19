<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Generic PHP method executor. Executes methods/functions without a
 * framework container, using reflection for class instantiation and
 * method invocation. Returns serialized results with execution metrics.
 */

declare(strict_types=1);

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', realpath(dirname(__DIR__, 3)));
}

define('DDLESS_METHOD_EXECUTOR', true);

// PHP 7.4 compatibility polyfills
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

function ddless_method_error(string $message, array $context = []): void
{
    $output = [
        'ok' => false,
        'error' => $message,
        'context' => $context,
        'timestamp' => date('c'),
    ];

    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

function ddless_method_success(array $data): void
{
    $output = array_merge([
        'ok' => true,
        'timestamp' => date('c'),
    ], $data);

    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function ddless_serialize_value(mixed $value, int $depth = 0, int $maxDepth = 10, int $maxArrayItems = 100): mixed
{
    if ($depth > $maxDepth) {
        return '[max depth reached]';
    }

    if ($value === null) {
        return null;
    }

    if (is_scalar($value)) {
        return $value;
    }

    if (is_array($value)) {
        $result = [];
        $count = 0;
        $total = count($value);
        foreach ($value as $key => $item) {
            if ($count++ >= $maxArrayItems) {
                $result['__truncated__'] = true;
                $result['__total__'] = $total;
                $result['__showing__'] = $maxArrayItems;
                break;
            }
            $result[$key] = ddless_serialize_value($item, $depth + 1, $maxDepth, $maxArrayItems);
        }
        return $result;
    }

    if (is_object($value)) {
        $className = get_class($value);

        if ($value instanceof \DateTimeInterface) {
            return [
                '__class__' => $className,
                '__type__' => 'datetime',
                '__value__' => $value->format('Y-m-d H:i:s'),
                'timezone' => $value->getTimezone()->getName(),
                'timestamp' => $value->getTimestamp(),
            ];
        }

        $stringValue = null;
        if (method_exists($value, '__toString')) {
            try {
                $stringValue = (string) $value;
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        if (method_exists($value, 'toArray')) {
            try {
                $arrayData = $value->toArray();
                $data = [
                    '__class__' => $className,
                    '__type__' => 'object',
                ];
                if ($stringValue !== null) {
                    $data['__value__'] = $stringValue;
                }
                $data['data'] = ddless_serialize_value($arrayData, $depth + 1, $maxDepth, $maxArrayItems);
                return $data;
            } catch (\Throwable $e) {
                // Continue
            }
        }

        if ($value instanceof \JsonSerializable) {
            try {
                $jsonData = $value->jsonSerialize();
                $data = [
                    '__class__' => $className,
                    '__type__' => 'object',
                ];
                if ($stringValue !== null) {
                    $data['__value__'] = $stringValue;
                }
                $data['data'] = ddless_serialize_value($jsonData, $depth + 1, $maxDepth, $maxArrayItems);
                return $data;
            } catch (\Throwable $e) {
                // Continue
            }
        }

        // Generic object via reflection
        $data = [
            '__class__' => $className,
            '__type__' => 'object',
        ];

        if ($stringValue !== null) {
            $data['__value__'] = $stringValue;
        }

        try {
            $reflection = new \ReflectionClass($value);
            $props = [];

            foreach ($reflection->getProperties() as $prop) {
                try {
                    $prop->setAccessible(true);
                    if (!$prop->isInitialized($value)) {
                        continue;
                    }
                    $propName = $prop->getName();
                    $propValue = $prop->getValue($value);

                    if ($depth > 2 && is_array($propValue) && count($propValue) > 20) {
                        $props[$propName] = '[array with ' . count($propValue) . ' items]';
                    } else {
                        $props[$propName] = ddless_serialize_value($propValue, $depth + 1, $maxDepth, $maxArrayItems);
                    }
                } catch (\Throwable $e) {
                    // Skip
                }
            }

            if (!empty($props)) {
                $data['properties'] = $props;
            }
        } catch (\Throwable $e) {
            $data['__error__'] = 'Unable to read properties: ' . $e->getMessage();
        }

        return $data;
    }

    if (is_resource($value)) {
        return '[resource: ' . get_resource_type($value) . ']';
    }

    return '[unknown type]';
}

$inputJson = '';

// Priority 1: Check if input is in GLOBALS (set by method_trigger.php)
if (!empty($GLOBALS['__DDLESS_METHOD_INPUT__'])) {
    $inputJson = $GLOBALS['__DDLESS_METHOD_INPUT__'];
}
// Priority 2: Check env var file
elseif (($inputFile = getenv('DDLESS_METHOD_INPUT_FILE')) && $inputFile !== '__ALREADY_LOADED__' && is_file($inputFile)) {
    $inputJson = file_get_contents($inputFile);
    @unlink($inputFile);
}
// Priority 3: Read from stdin
else {
    while (!feof(STDIN)) {
        $line = fgets(STDIN);
        if ($line === false) break;
        $inputJson .= $line;
    }
}

$input = json_decode(trim($inputJson), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ddless_method_error('Invalid JSON input: ' . json_last_error_msg());
}

$targetClass = $input['class'] ?? '';
$targetMethod = $input['method'] ?? null;
$parameterCode = $input['parameterCode'] ?? 'return [];';
$constructorCode = $input['constructorCode'] ?? 'return [];';
$sourceFilePath = $input['filePath'] ?? null;

if (!$targetMethod) {
    ddless_method_error('Missing required field: method/function name');
}

$composerAutoload = DDLESS_PROJECT_ROOT . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    fwrite(STDERR, "[ddless] Loading Composer autoload...\n");

    if (getenv('DDLESS_DEBUG_MODE') === 'true') {
        $debugModule = dirname(__DIR__, 2) . '/debug.php';
        if (file_exists($debugModule)) {
            require_once $debugModule;
            if (function_exists('ddless_register_stream_wrapper')) {
                fwrite(STDERR, "[ddless] Registering stream wrapper BEFORE autoload...\n");
                ddless_register_stream_wrapper();
            }
        }
    }

    require $composerAutoload;
    fwrite(STDERR, "[ddless] Composer autoload loaded.\n");
} else {
    fwrite(STDERR, "[ddless] No Composer autoload found, proceeding without it.\n");

    // Still register debug wrapper if in debug mode
    if (getenv('DDLESS_DEBUG_MODE') === 'true') {
        $debugModule = dirname(__DIR__, 2) . '/debug.php';
        if (file_exists($debugModule)) {
            require_once $debugModule;
            if (function_exists('ddless_register_stream_wrapper')) {
                ddless_register_stream_wrapper();
            }
        }
    }
}

$entryPoint = $input['entryPoint'] ?? null;
if ($entryPoint) {
    $entryPointPath = DDLESS_PROJECT_ROOT . '/' . ltrim(str_replace('\\', '/', $entryPoint), '/');
    if (is_file($entryPointPath)) {
        fwrite(STDERR, "[ddless] Loading entry point: {$entryPoint}\n");
        ob_start();
        require_once $entryPointPath;
        ob_end_clean();
        fwrite(STDERR, "[ddless] Entry point loaded.\n");
    } else {
        fwrite(STDERR, "[ddless] Entry point not found: {$entryPointPath}\n");
    }
}

if ($sourceFilePath) {
    $absoluteSourcePath = DDLESS_PROJECT_ROOT . '/' . ltrim(str_replace('\\', '/', $sourceFilePath), '/');
    if (is_file($absoluteSourcePath)) {
        fwrite(STDERR, "[ddless] Including source file: {$sourceFilePath}\n");
        // Buffer output to prevent top-level code (routers, echo, etc.) from
        // polluting stdout. We only need the function/class definitions.
        ob_start();
        require_once $absoluteSourcePath;
        $discarded = ob_get_clean();
        if ($discarded !== '' && $discarded !== false) {
            fwrite(STDERR, "[ddless] Discarded " . strlen($discarded) . " bytes of output from source file\n");
        }
    } else {
        fwrite(STDERR, "[ddless] Source file not found: {$absoluteSourcePath}\n");
    }
}

function ddless_clean_php_code(string $code): string
{
    $code = preg_replace('/^<\?php\s*/i', '', trim($code));
    $code = preg_replace('/^<\?=?\s*/', '', $code);
    $code = preg_replace('/\s*\?>\s*$/', '', $code);
    $cleaned = trim($code);

    if ($cleaned === '') {
        return 'return [];';
    }

    return $cleaned;
}

$methodParameters = [];
$constructorParameters = [];

$cleanedConstructorCode = ddless_clean_php_code($constructorCode);
$cleanedParameterCode = ddless_clean_php_code($parameterCode);

try {
    $constructorParameters = (function() use ($cleanedConstructorCode) {
        $result = eval($cleanedConstructorCode);
        if (!is_array($result)) {
            throw new \InvalidArgumentException('Constructor code must return an array');
        }
        return $result;
    })();
} catch (\Throwable $e) {
    ddless_method_error('Error executing constructor code: ' . $e->getMessage(), [
        'code' => $cleanedConstructorCode,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

try {
    $methodParameters = (function() use ($cleanedParameterCode) {
        $result = eval($cleanedParameterCode);
        if (!is_array($result)) {
            throw new \InvalidArgumentException('Parameter code must return an array');
        }
        return $result;
    })();
} catch (\Throwable $e) {
    ddless_method_error('Error executing parameter code: ' . $e->getMessage(), [
        'code' => $cleanedParameterCode,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

try {
    $startTime = microtime(true);
    $startMemory = memory_get_usage(true);

    $result = null;
    $isFunction = false;
    $isStatic = false;

    $isGlobalFunction = empty($targetClass) || strtolower($targetClass) === 'function' || strtolower($targetClass) === 'global';

    if ($isGlobalFunction) {
        $isFunction = true;

        if (!function_exists($targetMethod)) {
            ddless_method_error("Function not found: {$targetMethod}", [
                'hint' => 'Make sure the function is loaded. Include the file manually or use Composer autoload.',
            ]);
        }

        $result = call_user_func_array($targetMethod, $methodParameters);

    } else {
        if (!class_exists($targetClass)) {
            ddless_method_error("Class not found: {$targetClass}", [
                'hint' => 'Make sure the class is autoloadable via Composer or included manually.',
            ]);
        }

        if (!method_exists($targetClass, $targetMethod)) {
            ddless_method_error("Method not found: {$targetClass}::{$targetMethod}");
        }

        $reflection = new \ReflectionClass($targetClass);
        $methodReflection = $reflection->getMethod($targetMethod);
        $isStatic = $methodReflection->isStatic();

        $instance = null;
        if (!$isStatic) {
            if (!empty($constructorParameters)) {
                $instance = $reflection->newInstanceArgs($constructorParameters);
            } else {
                $constructor = $reflection->getConstructor();
                if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                    $instance = $reflection->newInstance();
                } else {
                    ddless_method_error('Cannot instantiate class - constructor requires parameters.', [
                        'class' => $targetClass,
                        'requiredParams' => $constructor->getNumberOfRequiredParameters(),
                        'hint' => 'Provide constructor parameters in the Constructor Code editor.',
                    ]);
                }
            }
        }

        if ($isStatic) {
            $result = $methodReflection->invokeArgs(null, $methodParameters);
        } else {
            $result = $methodReflection->invokeArgs($instance, $methodParameters);
        }
    }

    $endTime = microtime(true);
    $endMemory = memory_get_usage(true);

    $serializedResult = ddless_serialize_value($result);
    $serializedParams = ddless_serialize_value($methodParameters);

    ddless_method_success([
        'class' => $isFunction ? null : $targetClass,
        'method' => $targetMethod,
        'type' => $isFunction ? 'function' : 'method',
        'static' => $isStatic,
        'parameters' => $serializedParams,
        'result' => $serializedResult,
        'metrics' => [
            'durationMs' => round(($endTime - $startTime) * 1000, 2),
            'memoryUsedBytes' => $endMemory - $startMemory,
            'memoryPeakBytes' => memory_get_peak_usage(true),
        ],
    ]);

} catch (\Throwable $e) {
    // Application exception — treat as execution result, not ddless error.
    // The method ran but threw (e.g. ValidationException, ModelNotFoundException).
    $endTime = microtime(true);
    $endMemory = memory_get_usage(true);

    $exceptionInfo = [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];

    // Extract extra data from well-known exception types
    if (method_exists($e, 'errors')) {
        $exceptionInfo['errors'] = $e->errors();
    }
    if (method_exists($e, 'getStatusCode')) {
        $exceptionInfo['statusCode'] = $e->getStatusCode();
    }
    if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
        try {
            $resp = $e->getResponse();
            if (method_exists($resp, 'getStatusCode')) {
                $exceptionInfo['statusCode'] = $resp->getStatusCode();
            }
        } catch (\Throwable $_) {}
    }

    // Filter trace to show only application frames (skip vendor/framework internals)
    $appTrace = [];
    foreach ($e->getTrace() as $frame) {
        $file = $frame['file'] ?? '';
        if ($file && !str_contains($file, '/vendor/') && !str_contains($file, '\\vendor\\')) {
            $appTrace[] = [
                'file' => $frame['file'] ?? '',
                'line' => $frame['line'] ?? 0,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ];
            if (count($appTrace) >= 5) break;
        }
    }
    if (!empty($appTrace)) {
        $exceptionInfo['trace'] = $appTrace;
    }

    ddless_method_success([
        'class' => isset($isFunction) && $isFunction ? null : ($targetClass ?? null),
        'method' => $targetMethod ?? '',
        'type' => (isset($isFunction) && $isFunction) ? 'function' : 'method',
        'static' => $isStatic ?? false,
        'parameters' => isset($methodParameters) ? ddless_serialize_value($methodParameters) : [],
        'result' => null,
        'exception' => $exceptionInfo,
        'metrics' => [
            'durationMs' => isset($startTime) ? round(($endTime - $startTime) * 1000, 2) : 0,
            'memoryUsedBytes' => isset($startMemory) ? ($endMemory - $startMemory) : 0,
            'memoryPeakBytes' => memory_get_peak_usage(true),
        ],
    ]);
}
