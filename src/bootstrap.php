<?php

// Ne jamais afficher les erreurs/stack traces au navigateur (fuite d'infos serveur) :
// on logue côté serveur et on affiche une page générique.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log((string) $e);
    http_response_code(500);
    echo 'Une erreur est survenue. Merci de réessayer plus tard.';
});

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $isHttps,
]);
session_start();

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/UserRepository.php';
require_once __DIR__ . '/SiteRepository.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Router.php';

$db = Database::connection();
$users = new UserRepository($db);
$sites = new SiteRepository($db);
$auth = new Auth($db, $users);

// En-têtes de sécurité appliqués à toutes les réponses.
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'");
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function render(string $template, array $vars = []): void
{
    extract($vars);
    require __DIR__ . '/../templates/layout.php';
}
