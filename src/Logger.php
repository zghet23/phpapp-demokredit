<?php
declare(strict_types=1);

class Logger
{
    private static string $service = 'kredit-plus';
    private static string $env     = 'local';
    private static string $version = '1.0.0';

    public static function init(): void
    {
        self::$service = getenv('DD_SERVICE') ?: 'kredit-plus';
        self::$env     = getenv('DD_ENV')     ?: 'local';
        self::$version = getenv('DD_VERSION') ?: '1.0.0';
    }

    public static function debug(string $msg, array $ctx = []): void    { self::write('DEBUG',    $msg, $ctx); }
    public static function info(string $msg, array $ctx = []): void     { self::write('INFO',     $msg, $ctx); }
    public static function warning(string $msg, array $ctx = []): void  { self::write('WARNING',  $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void    { self::write('ERROR',    $msg, $ctx); }
    public static function critical(string $msg, array $ctx = []): void { self::write('CRITICAL', $msg, $ctx); }

    public static function exception(\Throwable $e, string $msg = 'Exception occurred'): void
    {
        self::write('ERROR', $msg, [
            'error.type'    => get_class($e),
            'error.message' => $e->getMessage(),
            'error.stack'   => $e->getTraceAsString(),
            'error.code'    => $e->getCode(),
        ]);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $traceId = $spanId = null;
        if (extension_loaded('ddtrace')) {
            $traceId = \DDTrace\logs_correlation_trace_id();
            $span    = \DDTrace\active_span();
            if ($span !== null) $spanId = $span->hexId();
        }

        $entry = array_merge([
            '@timestamp'  => date('c'),
            'level'       => $level,
            'message'     => $message,
            'app'         => self::$service,
            'service'     => self::$service,
            'env'         => self::$env,
            'version'     => self::$version,
            'dd.trace_id' => $traceId,
            'dd.span_id'  => $spanId,
        ], $context);

        file_put_contents('php://stdout', json_encode($entry) . PHP_EOL);
    }
}
