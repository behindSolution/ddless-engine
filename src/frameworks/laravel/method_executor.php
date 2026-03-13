<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Laravel method executor. Bootstraps the Laravel application container
 * and executes methods/functions with full dependency injection support,
 * returning serialized results with execution metrics.
 */

declare(strict_types=1);

if (!defined('DDLESS_PROJECT_ROOT')) {
    // From .ddless/frameworks/laravel/ we need to go up 3 levels to reach project root
    define('DDLESS_PROJECT_ROOT', realpath(dirname(__DIR__, 3)));
}

define('DDLESS_METHOD_EXECUTOR', true);
define('LARAVEL_START', microtime(true));

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

function ddless_capture_variables(): array
{
    $captured = [];
    
    foreach ($GLOBALS as $name => $value) {
        if (in_array($name, ['_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_REQUEST', '_ENV', 'GLOBALS', 'argv', 'argc'])) {
            continue;
        }
        if (str_starts_with($name, '__DDLESS_')) {
            continue;
        }
    }
    
    return $captured;
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
        
        if ($value instanceof \Illuminate\Database\Eloquent\Model) {
            $data = [
                '__class__' => $className,
                '__type__' => 'eloquent_model',
                'key' => $value->getKey(),
                'exists' => $value->exists,
                'attributes' => ddless_serialize_value($value->getAttributes(), $depth + 1, $maxDepth, $maxArrayItems),
            ];
            
            $relations = $value->getRelations();
            if (!empty($relations)) {
                $data['relations'] = ddless_serialize_value($relations, $depth + 1, $maxDepth, $maxArrayItems);
            }
            
            return $data;
        }
        
        if ($value instanceof \Illuminate\Support\Collection) {
            $count = $value->count();
            $items = $count > $maxArrayItems 
                ? $value->take($maxArrayItems)->toArray() 
                : $value->toArray();
            
            return [
                '__class__' => $className,
                '__type__' => 'collection',
                '__count__' => $count,
                '__truncated__' => $count > $maxArrayItems,
                'items' => ddless_serialize_value($items, $depth + 1, $maxDepth, $maxArrayItems),
            ];
        }
        
        $stringValue = null;
        if (method_exists($value, '__toString')) {
            try {
                $stringValue = (string) $value;
            } catch (Throwable $e) {
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
            } catch (Throwable $e) {
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
            } catch (Throwable $e) {
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
            $reflection = new ReflectionClass($value);
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
                } catch (Throwable $e) {
                }
            }
            
            if (!empty($props)) {
                $data['properties'] = $props;
            }
        } catch (Throwable $e) {
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

// Priority 1: Check if input is in GLOBALS (set by method_debug_runner.php)
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

// PHP CLI may not have all extensions. Force fallback drivers BEFORE anything
// else loads if Redis extension is missing.

$sapiName = php_sapi_name();
$redisExtLoaded = extension_loaded('redis');
fwrite(STDERR, "[ddless] SAPI: {$sapiName}, Redis ext loaded: " . ($redisExtLoaded ? 'yes' : 'no') . "\n");

// Always force fallback drivers when Redis extension is missing (regardless of SAPI)
if (!$redisExtLoaded) {
    fwrite(STDERR, "[ddless] Redis extension NOT available. Applying workarounds...\n");
    
    // CRITICAL: Create Redis stub class BEFORE autoload to prevent "class not found" fatal errors
    // This must happen before Composer autoload because Laravel's Redis connector checks for the class
    if (!class_exists('Redis', false)) {
        fwrite(STDERR, "[ddless] Creating Redis stub class...\n");
        
        // Minimal stub that mimics the Redis extension just enough to not crash
        class Redis {
            const REDIS_NOT_FOUND = 0;
            const REDIS_STRING = 1;
            const REDIS_SET = 2;
            const REDIS_LIST = 3;
            const REDIS_ZSET = 4;
            const REDIS_HASH = 5;
            const ATOMIC = 0;
            const MULTI = 1;
            const PIPELINE = 2;
            const OPT_SERIALIZER = 1;
            const OPT_PREFIX = 2;
            const SERIALIZER_NONE = 0;
            const SERIALIZER_PHP = 1;
            const OPT_READ_TIMEOUT = 3;
            const SERIALIZER_IGBINARY = 2;
            const SERIALIZER_MSGPACK = 3;
            const SERIALIZER_JSON = 4;
            const OPT_SCAN = 4;
            const SCAN_RETRY = 1;
            const SCAN_NORETRY = 0;
            
            private static $errorShown = false;
            
            public function __construct() {
                if (!self::$errorShown) {
                    fwrite(STDERR, "[ddless] WARNING: Redis stub instantiated - Redis extension not available!\n");
                    self::$errorShown = true;
                }
            }

            public function connect($host, $port = 6379, $timeout = 0) {
                throw new \RuntimeException('[ddless] Cannot connect to Redis: extension not available');
            }
            public function pconnect($host, $port = 6379, $timeout = 0) {
                throw new \RuntimeException('[ddless] Cannot connect to Redis: extension not available');
            }
            public function auth($password) { return false; }
            public function select($db) { return false; }
            public function ping() { return false; }
            public function get($key) { return false; }
            public function set($key, $value, $timeout = null) { return false; }
            public function setex($key, $ttl, $value) { return false; }
            public function del($key) { return 0; }
            public function exists($key) { return false; }
            public function __call($method, $args) { return false; }
            public static function __callStatic($method, $args) { return false; }
        }
        
        // Also create RedisException if it doesn't exist
        if (!class_exists('RedisException', false)) {
            class RedisException extends \Exception {}
        }
        
        fwrite(STDERR, "[ddless] Redis stub class created.\n");
    }
    
    // Force file-based drivers for the debug session - set BEFORE autoload
    putenv('CACHE_DRIVER=file');
    putenv('CACHE_STORE=file');
    putenv('SESSION_DRIVER=file');
    putenv('QUEUE_CONNECTION=sync');
    putenv('REDIS_CLIENT=predis');
    
    $_ENV['CACHE_DRIVER'] = 'file';
    $_ENV['CACHE_STORE'] = 'file';
    $_ENV['SESSION_DRIVER'] = 'file';
    $_ENV['QUEUE_CONNECTION'] = 'sync';
    $_ENV['REDIS_CLIENT'] = 'predis';
    
    $_SERVER['CACHE_DRIVER'] = 'file';
    $_SERVER['CACHE_STORE'] = 'file';
    $_SERVER['SESSION_DRIVER'] = 'file';
    $_SERVER['QUEUE_CONNECTION'] = 'sync';
    $_SERVER['REDIS_CLIENT'] = 'predis';
    
    fwrite(STDERR, "[ddless] Environment set: CACHE_DRIVER=file, SESSION_DRIVER=file, QUEUE_CONNECTION=sync, REDIS_CLIENT=predis\n");
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

$laravelBootstrap = DDLESS_PROJECT_ROOT . '/bootstrap/app.php';
if (!file_exists($laravelBootstrap)) {
    ddless_method_error('Laravel bootstrap not found', ['path' => $laravelBootstrap]);
}

$noRedis = !extension_loaded('redis');

try {
    fwrite(STDERR, "[ddless] Loading Laravel bootstrap/app.php...\n");
    $app = require $laravelBootstrap;
    fwrite(STDERR, "[ddless] Laravel app created successfully.\n");
    
    // Force config overrides if Redis is not available
    if ($noRedis && isset($app['config'])) {
        $app['config']->set('cache.default', 'file');
        $app['config']->set('session.driver', 'file');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.redis.client', 'predis');
        fwrite(STDERR, "[ddless] Config overridden: cache=file, session=file, queue=sync\n");
    }
    
    // Boot the kernel to initialize the application
    fwrite(STDERR, "[ddless] Bootstrapping Laravel kernel...\n");
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    fwrite(STDERR, "[ddless] Laravel kernel bootstrapped successfully.\n");
    
    // Re-apply config overrides after bootstrap (in case bootstrap overwrote them)
    if ($noRedis) {
        $app['config']->set('cache.default', 'file');
        $app['config']->set('session.driver', 'file');
        $app['config']->set('queue.default', 'sync');
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
    $context = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice($e->getTrace(), 0, 5),
    ];
    
    // Provide helpful hints for common extension-related errors
    if (stripos($errorMessage, 'Redis') !== false) {
        $context['hint'] = 'The Redis PHP extension is not available. ' .
            'Solutions: ' .
            '1) Install php-redis in your environment, OR ' .
            '2) Change CACHE_DRIVER and SESSION_DRIVER to "file" in your .env, OR ' .
            '3) Ensure your app does not force Redis initialization on boot.';
        $context['php_extensions'] = get_loaded_extensions();
        $context['redis_extension_loaded'] = extension_loaded('redis');
        $context['redis_class_exists'] = class_exists('Redis', false);
        $context['env_vars'] = [
            'CACHE_DRIVER' => getenv('CACHE_DRIVER'),
            'SESSION_DRIVER' => getenv('SESSION_DRIVER'),
            'QUEUE_CONNECTION' => getenv('QUEUE_CONNECTION'),
        ];
    }
    
    ddless_method_error('Failed to bootstrap Laravel: ' . $errorMessage, $context);
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
    $constructorParameters = (function() use ($cleanedConstructorCode, $app) {
        // Make Laravel helpers available
        $result = eval($cleanedConstructorCode);
        if (!is_array($result)) {
            throw new InvalidArgumentException('Constructor code must return an array');
        }
        return $result;
    })();
} catch (Throwable $e) {
    ddless_method_error('Error executing constructor code: ' . $e->getMessage(), [
        'code' => $cleanedConstructorCode,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice($e->getTrace(), 0, 5),
    ]);
}

try {
    $methodParameters = (function() use ($cleanedParameterCode, $app) {
        // Make Laravel helpers available
        $result = eval($cleanedParameterCode);
        if (!is_array($result)) {
            throw new InvalidArgumentException('Parameter code must return an array');
        }
        return $result;
    })();
} catch (Throwable $e) {
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
        
        $reflection = new ReflectionClass($targetClass);
        $methodReflection = $reflection->getMethod($targetMethod);
        $isStatic = $methodReflection->isStatic();
        
        // Determine if we need to instantiate or call statically
        $instance = null;
        if (!$isStatic) {
            // Always try Laravel's container first for automatic dependency injection
            try {
                if (!empty($constructorParameters)) {
                    // User provided custom constructor parameters - use them
                    $instance = $reflection->newInstanceArgs($constructorParameters);
                } else {
                    $instance = $app->make($targetClass);
                }
            } catch (Throwable $containerError) {
                // Container failed, try direct instantiation only if class has no required constructor params
                $constructor = $reflection->getConstructor();
                if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                    try {
                        $instance = $reflection->newInstance();
                    } catch (Throwable $e) {
                        ddless_method_error('Failed to instantiate class: ' . $containerError->getMessage(), [
                            'class' => $targetClass,
                            'containerError' => $containerError->getMessage(),
                            'fallbackError' => $e->getMessage(),
                        ]);
                    }
                } else {
                    ddless_method_error('Cannot instantiate class - Laravel container failed and constructor requires parameters: ' . $containerError->getMessage(), [
                        'class' => $targetClass,
                        'requiredParams' => $constructor->getNumberOfRequiredParameters(),
                        'hint' => 'Provide constructor parameters or ensure dependencies are registered in the container',
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
    
} catch (Throwable $e) {
    ddless_method_error('Error executing: ' . $e->getMessage(), [
        'class' => $targetClass,
        'method' => $targetMethod,
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice($e->getTrace(), 0, 10),
    ]);
}
