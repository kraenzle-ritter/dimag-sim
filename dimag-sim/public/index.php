<?php

// #ddev-generated (DDEV add-on: "ddev add-on get" may overwrite this copy)

declare(strict_types=1);

// Router for PHP's built-in server: php -S 0.0.0.0:8080 public/index.php

spl_autoload_register(function (string $class) {
    if (str_starts_with($class, 'DimagSim\\') && !str_starts_with($class, 'DimagSim\\Recorder\\')) {
        require __DIR__ . '/../src/' . substr($class, 9) . '.php';
    }
});

// DIMAG reports and compares local times (getObjectsChangedSince, aenderungs-datum)
date_default_timezone_set(getenv('DIMAG_SIM_TIMEZONE') ?: 'Europe/Berlin');

$server = new DimagSim\Server(DimagSim\Config::fromEnv());
[$status, $headers, $body] = $server->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
    (string) file_get_contents('php://input'),
);

http_response_code($status);
foreach ($headers as $k => $v) {
    header("$k: $v");
}
echo $body;
