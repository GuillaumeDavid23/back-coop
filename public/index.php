<?php

require __DIR__ . '/../src/bootstrap.php';

$router = new Router();

$router->get('/login', function () use ($auth) {
    if ($auth->check()) {
        header('Location: /');
        exit;
    }
    render('login.php', ['error' => null]);
});

$router->post('/login', function () use ($auth) {
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        render('login.php', ['error' => 'Session expirée, merci de réessayer.']);
        return;
    }

    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $result = $auth->attempt($username, $password);

    if ($result === true) {
        header('Location: /');
        exit;
    }

    if ($result === 'locked') {
        render('login.php', ['error' => 'Trop de tentatives échouées. Réessayez dans quelques minutes.']);
        return;
    }

    render('login.php', ['error' => 'Identifiant ou mot de passe incorrect.']);
});

$router->get('/logout', function () use ($auth) {
    $auth->logout();
    header('Location: /login');
    exit;
});

$router->get('/', function () use ($auth, $sites) {
    $auth->requireLogin();

    $accessible = $sites->accessibleToUser($auth->userId(), $auth->isDev());

    if (count($accessible) === 1) {
        header('Location: /go/' . urlencode($accessible[0]['code']));
        exit;
    }

    render('choose_site.php', [
        'sites' => $accessible,
        'isDev' => $auth->isDev(),
        'username' => $auth->username(),
    ]);
});

$router->get('/go/{code}', function (array $params) use ($auth, $sites) {
    $auth->requireLogin();

    $site = $sites->findByCode($params['code']);

    if ($site === null || !$site['is_active']) {
        http_response_code(404);
        echo 'Site introuvable.';
        return;
    }

    if (!$auth->isDev() && !$sites->userHasAccess((int) $site['id'], $auth->userId())) {
        http_response_code(403);
        echo 'Accès non autorisé à ce site.';
        return;
    }

    header('Location: ' . $site['backoffice_url']);
    exit;
});

$router->get('/admin/sites', function () use ($auth, $sites) {
    $auth->requireDev();

    $search = trim($_GET['q'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pagination = $sites->paginate($search, $page, 10);

    $userCounts = [];
    foreach ($pagination['items'] as $site) {
        $userCounts[$site['id']] = count($sites->userIdsForSite($site['id']));
    }

    render('admin/sites_list.php', [
        'pagination' => $pagination,
        'search' => $search,
        'userCounts' => $userCounts,
        'username' => $auth->username(),
    ]);
});

$router->get('/admin/sites/new', function () use ($auth, $users) {
    $auth->requireDev();

    render('admin/site_form.php', [
        'site' => null,
        'old' => [],
        'allUsers' => $users->all(),
        'assignedUserIds' => [],
        'error' => null,
    ]);
});

$router->post('/admin/sites/new', function () use ($auth, $sites, $users) {
    $auth->requireDev();

    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo 'Session expirée, merci de réessayer.';
        return;
    }

    $data = readSiteForm();
    $error = validateSiteForm($sites, $data, null);

    if ($error !== null) {
        render('admin/site_form.php', [
            'site' => null,
            'old' => $data,
            'allUsers' => $users->all(),
            'assignedUserIds' => array_map('intval', $_POST['user_ids'] ?? []),
            'error' => $error,
        ]);
        return;
    }

    $id = $sites->create($data);
    $sites->setUserAccess($id, array_map('intval', $_POST['user_ids'] ?? []));

    header('Location: /admin/sites');
    exit;
});

$router->get('/admin/sites/{id}/edit', function (array $params) use ($auth, $sites, $users) {
    $auth->requireDev();

    $site = $sites->find((int) $params['id']);
    if ($site === null) {
        http_response_code(404);
        echo 'Site introuvable.';
        return;
    }

    render('admin/site_form.php', [
        'site' => $site,
        'old' => [],
        'allUsers' => $users->all(),
        'assignedUserIds' => $sites->userIdsForSite($site['id']),
        'error' => null,
    ]);
});

$router->post('/admin/sites/{id}/edit', function (array $params) use ($auth, $sites, $users) {
    $auth->requireDev();

    $site = $sites->find((int) $params['id']);
    if ($site === null) {
        http_response_code(404);
        echo 'Site introuvable.';
        return;
    }

    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo 'Session expirée, merci de réessayer.';
        return;
    }

    $data = readSiteForm();
    $error = validateSiteForm($sites, $data, $site['id']);

    if ($error !== null) {
        render('admin/site_form.php', [
            'site' => $site,
            'old' => $data,
            'allUsers' => $users->all(),
            'assignedUserIds' => array_map('intval', $_POST['user_ids'] ?? []),
            'error' => $error,
        ]);
        return;
    }

    $sites->update($site['id'], $data);
    $sites->setUserAccess($site['id'], array_map('intval', $_POST['user_ids'] ?? []));

    header('Location: /admin/sites');
    exit;
});

$router->post('/admin/sites/{id}/delete', function (array $params) use ($auth, $sites) {
    $auth->requireDev();

    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo 'Session expirée, merci de réessayer.';
        return;
    }

    $sites->delete((int) $params['id']);

    header('Location: /admin/sites');
    exit;
});

$router->get('/admin/users', function () use ($auth, $users) {
    $auth->requireDev();

    $search = trim($_GET['q'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pagination = $users->paginate($search, $page, 10);

    render('admin/users_list.php', [
        'pagination' => $pagination,
        'search' => $search,
        'username' => $auth->username(),
        'currentUserId' => $auth->userId(),
    ]);
});

$router->get('/admin/users/new', function () use ($auth) {
    $auth->requireDev();

    render('admin/user_form.php', [
        'user' => null,
        'old' => [],
        'error' => null,
    ]);
});

$router->post('/admin/users/new', function () use ($auth, $users) {
    $auth->requireDev();

    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo 'Session expirée, merci de réessayer.';
        return;
    }

    $data = readUserForm();
    $error = validateUserForm($users, $data, null, true);

    if ($error !== null) {
        render('admin/user_form.php', ['user' => null, 'old' => $data, 'error' => $error]);
        return;
    }

    $users->create($data['username'], $data['password'], $data['role']);

    header('Location: /admin/users');
    exit;
});

$router->get('/admin/users/{id}/edit', function (array $params) use ($auth, $users) {
    $auth->requireDev();

    $user = $users->findById((int) $params['id']);
    if ($user === null) {
        http_response_code(404);
        echo 'Utilisateur introuvable.';
        return;
    }

    render('admin/user_form.php', ['user' => $user, 'old' => [], 'error' => null]);
});

$router->post('/admin/users/{id}/edit', function (array $params) use ($auth, $users) {
    $auth->requireDev();

    $user = $users->findById((int) $params['id']);
    if ($user === null) {
        http_response_code(404);
        echo 'Utilisateur introuvable.';
        return;
    }

    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo 'Session expirée, merci de réessayer.';
        return;
    }

    $data = readUserForm();
    $error = validateUserForm($users, $data, $user['id'], false);

    if ($error === null && $user['id'] == $auth->userId() && $data['role'] !== 'dev') {
        $error = 'Vous ne pouvez pas retirer votre propre rôle dev.';
    }

    if ($error !== null) {
        render('admin/user_form.php', ['user' => $user, 'old' => $data, 'error' => $error]);
        return;
    }

    $users->update($user['id'], $data['username'], $data['role'], $data['password'] !== '' ? $data['password'] : null);

    if ($user['id'] == $auth->userId()) {
        $_SESSION['username'] = $data['username'];
    }

    header('Location: /admin/users');
    exit;
});

$router->post('/admin/users/{id}/delete', function (array $params) use ($auth, $users) {
    $auth->requireDev();

    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo 'Session expirée, merci de réessayer.';
        return;
    }

    $id = (int) $params['id'];

    if ($id === $auth->userId()) {
        http_response_code(400);
        echo 'Vous ne pouvez pas supprimer votre propre compte.';
        return;
    }

    $target = $users->findById($id);
    if ($target !== null && $target['role'] === 'dev' && $users->countDevs() <= 1) {
        http_response_code(400);
        echo 'Impossible de supprimer le dernier compte dev.';
        return;
    }

    $users->delete($id);

    header('Location: /admin/users');
    exit;
});

function readUserForm(): array
{
    return [
        'username' => trim($_POST['username'] ?? ''),
        'role' => ($_POST['role'] ?? 'user') === 'dev' ? 'dev' : 'user',
        'password' => (string) ($_POST['password'] ?? ''),
        'password_confirm' => (string) ($_POST['password_confirm'] ?? ''),
    ];
}

function validateUserForm(UserRepository $users, array $data, ?int $ignoreId, bool $passwordRequired): ?string
{
    if ($data['username'] === '') {
        return "L'identifiant est obligatoire.";
    }

    if (!preg_match('/^[a-zA-Z0-9._\-]+$/', $data['username'])) {
        return "L'identifiant ne doit contenir que des lettres, chiffres, points, tirets et underscores.";
    }

    $existing = $users->findByUsername($data['username']);
    if ($existing !== null && $existing['id'] != $ignoreId) {
        return 'Cet identifiant est déjà utilisé.';
    }

    if ($passwordRequired && $data['password'] === '') {
        return 'Le mot de passe est obligatoire.';
    }

    if ($data['password'] !== '' && strlen($data['password']) < 8) {
        return 'Le mot de passe doit contenir au moins 8 caractères.';
    }

    if ($data['password'] !== $data['password_confirm']) {
        return 'Les mots de passe ne correspondent pas.';
    }

    return null;
}

function readSiteForm(): array
{
    return [
        'name' => trim($_POST['name'] ?? ''),
        'code' => trim($_POST['code'] ?? ''),
        'backoffice_url' => trim($_POST['backoffice_url'] ?? ''),
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        'is_active' => !empty($_POST['is_active']),
    ];
}

function validateSiteForm(SiteRepository $sites, array $data, ?int $ignoreId): ?string
{
    if ($data['name'] === '' || $data['code'] === '' || $data['backoffice_url'] === '') {
        return 'Tous les champs sont obligatoires.';
    }

    if (!preg_match('/^[a-z0-9\-]+$/', $data['code'])) {
        return 'Le code ne doit contenir que des minuscules, chiffres et tirets.';
    }

    if (!filter_var($data['backoffice_url'], FILTER_VALIDATE_URL)) {
        return "L'URL du back-office n'est pas valide.";
    }

    $scheme = parse_url($data['backoffice_url'], PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return "L'URL du back-office doit commencer par http:// ou https://.";
    }

    $existing = $sites->findByCode($data['code']);
    if ($existing !== null && $existing['id'] != $ignoreId) {
        return 'Ce code est déjà utilisé par un autre site.';
    }

    return null;
}

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
