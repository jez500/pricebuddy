<?php

// A separate PHP process and connection, never the test runner's transaction.
use App\Models\Url;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = $argv[1];
$stage = 'input';
try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $connection = $input['connection'];
    // A cloned connection must have its own name as well as its own PDO.
    // Otherwise Eloquent hydrates models on the original connection name and
    // the transaction observed by CONNECTION_ID() is not the writer's session.
    unset($connection['name']);
    $stage = 'bootstrap';
    foreach ([
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $connection['host'],
        'DB_PORT' => $connection['port'], 'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
        'DB_SOCKET' => $connection['unix_socket'] ?? '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
    ] as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config()->set('database.default', 'history_worker');
    config()->set('database.connections.history_worker', $connection);
    DB::purge('history_worker');
    $stage = 'connect';
    DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
    $stage = 'load-url';
    $url = Url::query()->findOrFail($input['url_id']);
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    file_put_contents($directory.'/ready', json_encode([
        'connection_id' => (int) $connectionId,
        'database' => DB::selectOne('SELECT DATABASE() AS current_database')->current_database,
        'model_connection_id' => (int) $url->getConnection()->selectOne('SELECT CONNECTION_ID() AS id')->id,
    ], JSON_THROW_ON_ERROR));
    $stage = 'wait-for-parent';
    $deadline = microtime(true) + 5;
    while (! is_file($directory.'/write') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($directory.'/write')) {
        throw new RuntimeException('Synchronization timed out.');
    }
    touch($directory.'/attempting');
    $stage = 'persist-price';
    $price = $url->updatePrice($input['price']);
    file_put_contents($directory.'/result.json', json_encode(['ok' => $price !== null, 'stage' => 'complete', 'price_id' => $price?->getKey()], JSON_THROW_ON_ERROR));
    touch($directory.'/done');
} catch (Throwable $error) {
    // Do not expose database configuration, SQL bindings or credentials in test output.
    $sqlState = preg_match('/^[A-Z0-9]{5}$/', (string) $error->getCode()) ? (string) $error->getCode() : null;
    $driverCode = isset($error->errorInfo[1]) && is_numeric($error->errorInfo[1]) ? (int) $error->errorInfo[1] : null;
    file_put_contents($directory.'/result.json', json_encode(['ok' => false, 'stage' => $stage, 'error_type' => get_class($error), 'sql_state' => $sqlState, 'driver_code' => $driverCode], JSON_THROW_ON_ERROR));
    touch($directory.'/done');
    exit(1);
}
