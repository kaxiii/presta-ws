<?php
declare(strict_types=1);

require __DIR__ . '/services/env.php';

$vars = loadEnvFile(__DIR__ . '/.env');
$baseUrl = env($vars, 'PRESTASHOP_BASE_URL');
$apiKey  = env($vars, 'PRESTASHOP_WEBSERVICE_API_KEY');


$endpoint = rtrim($baseUrl, '/') . '/api/';

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $apiKey . ':',     // APIKEY:
    CURLOPT_HTTPHEADER => ['Accept: application/xml'],
    CURLOPT_TIMEOUT => 30,
]);

$body = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

header('Content-Type: text/plain; charset=utf-8');

echo "URL: $endpoint\n";
echo "HTTP: $http\n";
if ($body === false) {
    echo "cURL error: $err\n";
    exit;
}

echo "---- BODY (primeros 1000 chars) ----\n";
echo substr($body, 0, 1000) . "\n";
