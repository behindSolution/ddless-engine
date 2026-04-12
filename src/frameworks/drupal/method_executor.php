<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Drupal 9/10/11 method executor. Boots the DrupalKernel and container,
 * then executes methods/functions with service injection support.
 */

define('DDLESS_PROJECT_ROOT', realpath(dirname(__DIR__, 3)));

function ddless_method_error(string $message, array $context = []): void
{
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => $message, 'context' => $context, 'timestamp' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

function ddless_method_success(array $data): void
{
    fwrite(STDOUT, json_encode(array_merge(['ok' => true, 'timestamp' => date('c')], $data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function ddless_clean_php_code(string $code): string
{
    $code = trim($code);
    if (str_starts_with($code, '<?php')) $code = substr($code, 5);
    elseif (str_starts_with($code, '<?')) $code = substr($code, 2);
    $code = rtrim($code);
    if (str_ends_with($code, '?>')) $code = substr($code, 0, -2);
    return trim($code);
}

function ddless_serialize_value($value, int $depth = 0, int $maxDepth = 10, int $maxArrayItems = 100)
{
    if ($depth > $maxDepth) {
        if (is_object($value)) return ['__type' => 'object', '__class' => get_class($value), '__truncated' => true];
        if (is_array($value)) return ['__type' => 'array', '__truncated' => true, '__count' => count($value)];
        return $value;
    }
    if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) return $value;
    if (is_string($value)) return strlen($value) > 10000 ? substr($value, 0, 10000) . "\xe2\x80\xa6 [truncated]" : $value;
    if (is_array($value)) {
        $result = []; $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= $maxArrayItems) { $result['__truncated'] = true; $result['__totalItems'] = count($value); break; }
            $result[$key] = ddless_serialize_value($item, $depth + 1, $maxDepth, $maxArrayItems); $count++;
        }
        return $result;
    }
    if ($value instanceof \DateTimeInterface) return ['__type' => 'datetime', 'formatted' => $value->format('Y-m-d H:i:s'), 'timezone' => $value->getTimezone()->getName(), 'iso8601' => $value->format(\DateTimeInterface::ATOM)];
    if ($value instanceof \JsonSerializable) { try { return ddless_serialize_value($value->jsonSerialize(), $depth + 1, $maxDepth, $maxArrayItems); } catch (\Throwable $e) { return ['__type' => 'object', '__class' => get_class($value), '__error' => $e->getMessage()]; } }
    if (method_exists($value, 'toArray')) { try { return ddless_serialize_value($value->toArray(), $depth + 1, $maxDepth, $maxArrayItems); } catch (\Throwable $e) {} }
    if (is_object($value)) {
        $result = ['__type' => 'object', '__class' => get_class($value)];
        try {
            $ref = new \ReflectionClass($value);
            foreach ($ref->getProperties() as $prop) { $prop->setAccessible(true); if ($prop->isInitialized($value)) $result[$prop->getName()] = ddless_serialize_value($prop->getValue($value), $depth + 1, $maxDepth, $maxArrayItems); }
        } catch (\Throwable $e) { $result['__error'] = $e->getMessage(); }
        return $result;
    }
    if (is_resource($value)) return ['__type' => 'resource', '__resourceType' => get_resource_type($value)];
    return (string)$value;
}

$inputJson = '';
if (!empty($GLOBALS['__DDLESS_METHOD_INPUT__'])) { $inputJson = $GLOBALS['__DDLESS_METHOD_INPUT__']; }
elseif (($inputFile = getenv('DDLESS_METHOD_INPUT_FILE')) && $inputFile !== '__ALREADY_LOADED__' && is_file($inputFile)) { $inputJson = file_get_contents($inputFile); @unlink($inputFile); }
else { while (!feof(STDIN)) { $line = fgets(STDIN); if ($line === false) break; $inputJson .= $line; } }

$input = json_decode(trim($inputJson), true);
if (!is_array($input)) ddless_method_error('Invalid input JSON');

$targetClass = $input['class'] ?? '';
$targetMethod = $input['method'] ?? null;
$parameterCode = $input['parameterCode'] ?? 'return [];';
$constructorCode = $input['constructorCode'] ?? 'return [];';

if (empty($targetMethod)) ddless_method_error('Missing required field: method/function name');

$composerAutoload = DDLESS_PROJECT_ROOT . '/autoload.php';
if (!file_exists($composerAutoload)) {
    $composerAutoload = DDLESS_PROJECT_ROOT . '/vendor/autoload.php';
}
if (!file_exists($composerAutoload)) ddless_method_error('Autoload not found', ['path' => $composerAutoload]);

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

fwrite(STDERR, "[ddless] Loading autoload...\n");
$webDir = is_dir(DDLESS_PROJECT_ROOT . '/web') ? DDLESS_PROJECT_ROOT . '/web' : DDLESS_PROJECT_ROOT;
chdir($webDir);
$autoloader = require $composerAutoload;
fwrite(STDERR, "[ddless] Autoload loaded.\n");

$container = null;
try {
    fwrite(STDERR, "[ddless] Booting DrupalKernel...\n");
    $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    $kernel = \Drupal\Core\DrupalKernel::createFromRequest($request, $autoloader, 'prod');
    $kernel->boot();
    $container = $kernel->getContainer();
    fwrite(STDERR, "[ddless] DrupalKernel booted successfully.\n");
} catch (\Throwable $e) {
    ddless_method_error('Failed to boot DrupalKernel: ' . $e->getMessage(), [
        'file' => $e->getFile(), 'line' => $e->getLine(),
        'trace' => array_slice($e->getTrace(), 0, 5),
    ]);
}

$cleanedConstructorCode = ddless_clean_php_code($constructorCode);
$cleanedParameterCode = ddless_clean_php_code($parameterCode);
$constructorParameters = [];
$methodParameters = [];

try { $constructorParameters = (function() use ($cleanedConstructorCode, $container) { $result = eval($cleanedConstructorCode); if (!is_array($result)) throw new \InvalidArgumentException('Constructor code must return an array'); return $result; })(); }
catch (\Throwable $e) { ddless_method_error('Error executing constructor code: ' . $e->getMessage(), ['code' => $cleanedConstructorCode]); }

try { $methodParameters = (function() use ($cleanedParameterCode, $container) { $result = eval($cleanedParameterCode); if (!is_array($result)) throw new \InvalidArgumentException('Parameter code must return an array'); return $result; })(); }
catch (\Throwable $e) { ddless_method_error('Error executing parameter code: ' . $e->getMessage(), ['code' => $cleanedParameterCode]); }

try {
    $startTime = microtime(true);
    $startMemory = memory_get_usage(true);
    $result = null; $isFunction = false; $isStatic = false;
    $isGlobalFunction = empty($targetClass) || strtolower($targetClass) === 'function' || strtolower($targetClass) === 'global';

    if ($isGlobalFunction) {
        $isFunction = true;
        if (!function_exists($targetMethod)) ddless_method_error("Function not found: {$targetMethod}");
        $result = call_user_func_array($targetMethod, $methodParameters);
    } else {
        if (!class_exists($targetClass)) ddless_method_error("Class not found: {$targetClass}");
        if (!method_exists($targetClass, $targetMethod)) ddless_method_error("Method not found: {$targetClass}::{$targetMethod}");

        $reflection = new \ReflectionClass($targetClass);
        $methodReflection = $reflection->getMethod($targetMethod);
        $isStatic = $methodReflection->isStatic();

        $instance = null;
        if (!$isStatic) {
            try {
                if (!empty($constructorParameters)) {
                    $instance = $reflection->newInstanceArgs($constructorParameters);
                } elseif ($container && $container->has($targetClass)) {
                    $instance = $container->get($targetClass);
                } else {
                    $constructor = $reflection->getConstructor();
                    if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                        $instance = $reflection->newInstance();
                    } else {
                        ddless_method_error('Cannot instantiate class - constructor requires parameters.', [
                            'class' => $targetClass,
                            'hint' => 'Provide constructor parameters or use a Drupal service ID.',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $constructor = $reflection->getConstructor();
                if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                    try { $instance = $reflection->newInstance(); }
                    catch (\Throwable $e2) { ddless_method_error('Failed to instantiate: ' . $e->getMessage(), ['fallbackError' => $e2->getMessage()]); }
                } else {
                    ddless_method_error('Cannot instantiate: ' . $e->getMessage(), ['class' => $targetClass]);
                }
            }
        }

        $result = $isStatic ? $methodReflection->invokeArgs(null, $methodParameters) : $methodReflection->invokeArgs($instance, $methodParameters);
    }

    $endTime = microtime(true); $endMemory = memory_get_usage(true);
    ddless_method_success([
        'class' => $isFunction ? null : $targetClass, 'method' => $targetMethod,
        'type' => $isFunction ? 'function' : 'method', 'static' => $isStatic,
        'parameters' => ddless_serialize_value($methodParameters), 'result' => ddless_serialize_value($result),
        'metrics' => ['durationMs' => round(($endTime - $startTime) * 1000, 2), 'memoryUsedBytes' => $endMemory - $startMemory, 'memoryPeakBytes' => memory_get_peak_usage(true)],
    ]);
} catch (\Throwable $e) {
    $endTime = microtime(true); $endMemory = memory_get_usage(true);
    $exceptionInfo = ['class' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode(), 'file' => $e->getFile(), 'line' => $e->getLine()];
    $appTrace = [];
    foreach ($e->getTrace() as $frame) {
        $file = $frame['file'] ?? '';
        if ($file && !str_contains($file, '/vendor/') && !str_contains($file, '\\vendor\\')) {
            $appTrace[] = ['file' => $file, 'line' => $frame['line'] ?? 0, 'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '')];
            if (count($appTrace) >= 5) break;
        }
    }
    if (!empty($appTrace)) $exceptionInfo['trace'] = $appTrace;
    ddless_method_success([
        'class' => isset($isFunction) && $isFunction ? null : ($targetClass ?? null),
        'method' => $targetMethod ?? '', 'type' => (isset($isFunction) && $isFunction) ? 'function' : 'method',
        'static' => $isStatic ?? false, 'parameters' => isset($methodParameters) ? ddless_serialize_value($methodParameters) : [],
        'result' => null, 'exception' => $exceptionInfo,
        'metrics' => ['durationMs' => isset($startTime) ? round(($endTime - $startTime) * 1000, 2) : 0, 'memoryUsedBytes' => isset($startMemory) ? ($endMemory - $startMemory) : 0, 'memoryPeakBytes' => memory_get_peak_usage(true)],
    ]);
}
