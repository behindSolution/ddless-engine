<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Symfony method executor. Bootstraps the Symfony application kernel and
 * container, then executes methods/functions with dependency injection
 * support, returning serialized results with execution metrics.
 */

declare(strict_types=1);

if (!defined('DDLESS_PROJECT_ROOT')) {
    // From .ddless/frameworks/symfony/ we need to go up 3 levels to reach project root
    define('DDLESS_PROJECT_ROOT', realpath(dirname(__DIR__, 3)));
}

define('DDLESS_METHOD_EXECUTOR', true);

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
                'iso8601' => $value->format(\DateTimeInterface::ISO8601),
            ];
        }

        // Doctrine entity support
        if (class_exists('Doctrine\ORM\EntityManagerInterface') && method_exists($value, 'getId')) {
            try {
                $data = [
                    '__class__' => $className,
                    '__type__' => 'doctrine_entity',
                    'id' => $value->getId(),
                ];

                $reflection = new \ReflectionClass($value);
                $props = [];
                foreach ($reflection->getProperties() as $prop) {
                    try {
                        $prop->setAccessible(true);
                        if (!$prop->isInitialized($value)) {
                            continue;
                        }
                        $propValue = $prop->getValue($value);
                        // Skip lazy-loaded collections at depth
                        if ($depth > 1 && is_object($propValue) && str_contains(get_class($propValue), 'Collection')) {
                            $props[$prop->getName()] = '[Collection]';
                        } else {
                            $props[$prop->getName()] = ddless_serialize_value($propValue, $depth + 1, $maxDepth, $maxArrayItems);
                        }
                    } catch (\Throwable $e) {
                    }
                }
                if (!empty($props)) {
                    $data['properties'] = $props;
                }
                return $data;
            } catch (\Throwable $e) {
                // Fall through to generic handling
            }
        }

        $stringValue = null;
        if (method_exists($value, '__toString')) {
            try {
                $stringValue = (string) $value;
            } catch (\Throwable $e) {
                // Ignore if __toString fails
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
                // Continue to generic handling
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
                // Continue to generic handling
            }
        }

        // Generic object - try to extract all accessible data
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
// Priority 2: Check if input should come from a file (debug mode legacy)
elseif (($inputFile = getenv('DDLESS_METHOD_INPUT_FILE')) && $inputFile !== '__ALREADY_LOADED__' && is_file($inputFile)) {
    $inputJson = file_get_contents($inputFile);
    @unlink($inputFile);
}
// Priority 3: Read from stdin (normal mode)
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

if (!$targetMethod) {
    ddless_method_error('Missing required field: method/function name');
}

$composerAutoload = DDLESS_PROJECT_ROOT . '/vendor/autoload.php';
if (!file_exists($composerAutoload)) {
    ddless_method_error('Composer autoload not found', ['path' => $composerAutoload]);
}

// The stream wrapper MUST be registered BEFORE Composer autoload, because
// files with breakpoints may be loaded during autoload (e.g., helper files).
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

fwrite(STDERR, "[ddless] Loading Composer autoload...\n");
require $composerAutoload;
fwrite(STDERR, "[ddless] Composer autoload loaded.\n");

// Load .env variables (Symfony normally does this via autoload_runtime.php)
if (class_exists('Symfony\Component\Dotenv\Dotenv')) {
    $envFile = DDLESS_PROJECT_ROOT . '/.env';
    if (file_exists($envFile)) {
        (new \Symfony\Component\Dotenv\Dotenv())->bootEnv($envFile);
    }
}

// Resolve the Symfony Kernel class
$kernelClass = null;
$kernelCandidates = [
    'App\\Kernel',
    'App\\HttpKernel',
];

foreach ($kernelCandidates as $candidate) {
    if (class_exists($candidate)) {
        $kernelClass = $candidate;
        break;
    }
}

if ($kernelClass === null) {
    ddless_method_error('Symfony Kernel class not found', [
        'tried' => $kernelCandidates,
        'hint' => 'Ensure App\\Kernel exists and is autoloadable.',
    ]);
}

$env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
$debug = (bool)($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? true);

try {
    fwrite(STDERR, "[ddless] Booting Symfony kernel ({$kernelClass})...\n");
    $kernel = new $kernelClass($env, $debug);
    $kernel->boot();
    $container = $kernel->getContainer();
    fwrite(STDERR, "[ddless] Symfony kernel booted successfully.\n");
} catch (\Throwable $e) {
    ddless_method_error('Failed to boot Symfony kernel: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice($e->getTrace(), 0, 5),
    ]);
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
    $constructorParameters = (function() use ($cleanedConstructorCode, $container) {
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
        'trace' => array_slice($e->getTrace(), 0, 5),
    ]);
}

try {
    $methodParameters = (function() use ($cleanedParameterCode, $container) {
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
        'trace' => array_slice($e->getTrace(), 0, 5),
    ]);
}

try {
    // Capture start time
    $startTime = microtime(true);
    $startMemory = memory_get_usage(true);

    $result = null;
    $isFunction = false;
    $isStatic = false;

    $isGlobalFunction = empty($targetClass) || strtolower($targetClass) === 'function' || strtolower($targetClass) === 'global';

    if ($isGlobalFunction) {
        // It's a global function call
        $isFunction = true;

        if (!function_exists($targetMethod)) {
            ddless_method_error("Function not found: {$targetMethod}", [
                'hint' => 'Make sure the function is loaded. For helper files, they should be autoloaded via composer.json "files" array.',
            ]);
        }

        $result = call_user_func_array($targetMethod, $methodParameters);

    } else {
        // It's a class method call

        if (!class_exists($targetClass)) {
            ddless_method_error("Class not found: {$targetClass}");
        }

        if (!method_exists($targetClass, $targetMethod)) {
            ddless_method_error("Method not found: {$targetClass}::{$targetMethod}");
        }

        $reflection = new \ReflectionClass($targetClass);
        $methodReflection = $reflection->getMethod($targetMethod);
        $isStatic = $methodReflection->isStatic();

        // Determine if we need to instantiate or call statically
        $instance = null;
        if (!$isStatic) {
            // Try Symfony's container first for dependency injection
            try {
                if (!empty($constructorParameters)) {
                    // User provided custom constructor parameters — use them
                    $instance = $reflection->newInstanceArgs($constructorParameters);
                } elseif ($container->has($targetClass)) {
                    // Service is registered in the container
                    $instance = $container->get($targetClass);
                } else {
                    // Try to auto-wire via the container's test container or direct instantiation
                    $testContainer = null;
                    if (method_exists($kernel, 'getContainer')) {
                        $c = $kernel->getContainer();
                        if ($c->has('test.service_container')) {
                            $testContainer = $c->get('test.service_container');
                        }
                    }

                    if ($testContainer && $testContainer->has($targetClass)) {
                        $instance = $testContainer->get($targetClass);
                    } else {
                        // Fallback: direct instantiation
                        $constructor = $reflection->getConstructor();
                        if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                            $instance = $reflection->newInstance();
                        } else {
                            ddless_method_error('Cannot instantiate class - not registered in Symfony container and constructor requires parameters.', [
                                'class' => $targetClass,
                                'requiredParams' => $constructor->getNumberOfRequiredParameters(),
                                'hint' => 'Register the service as public in services.yaml, provide constructor parameters, or mark it as public for testing.',
                            ]);
                        }
                    }
                }
            } catch (\Throwable $containerError) {
                // Container failed, try direct instantiation
                $constructor = $reflection->getConstructor();
                if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                    try {
                        $instance = $reflection->newInstance();
                    } catch (\Throwable $e) {
                        ddless_method_error('Failed to instantiate class: ' . $containerError->getMessage(), [
                            'class' => $targetClass,
                            'containerError' => $containerError->getMessage(),
                            'fallbackError' => $e->getMessage(),
                        ]);
                    }
                } else {
                    ddless_method_error('Cannot instantiate class - Symfony container failed and constructor requires parameters: ' . $containerError->getMessage(), [
                        'class' => $targetClass,
                        'requiredParams' => $constructor->getNumberOfRequiredParameters(),
                        'hint' => 'Provide constructor parameters or ensure the service is registered in services.yaml',
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

    // Capture end metrics
    $endTime = microtime(true);
    $endMemory = memory_get_usage(true);

    // Serialize the result
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
