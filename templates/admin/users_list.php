<div class="card wide">
    <div class="topbar">
        <h1>Gestion des utilisateurs</h1>
        <div>
            <a href="/admin/sites" class="muted-link">Gérer les sites</a> ·
            <a href="/" class="muted-link">← Choix de site</a> ·
            <a href="/logout" class="muted-link">Déconnexion (<?= htmlspecialchars($username) ?>)</a>
        </div>
    </div>

    <div class="topbar">
        <form method="get" action="/admin/users" class="search-box" style="flex: 1; margin-bottom: 0;">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher par identifiant…">
        </form>
        <a href="/admin/users/new" class="btn btn-primary" style="margin-left: 16px;">+ Nouvel utilisateur</a>
    </div>

    <?php if (empty($pagination['items'])): ?>
        <p class="empty">Aucun utilisateur trouvé.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Identifiant</th>
                    <th>Rôle</th>
                    <th>Créé le</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pagination['items'] as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td>
                            <span class="badge <?= $user['role'] === 'dev' ? 'active' : 'inactive' ?>">
                                <?= $user['role'] === 'dev' ? 'Dev' : 'Utilisateur' ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($user['created_at']) ?></td>
                        <td class="row-actions">
                            <a href="/admin/users/<?= $user['id'] ?>/edit">Modifier</a>
                            <?php if ($user['id'] != $currentUserId): ?>
                                <a href="#" data-confirm="Supprimer cet utilisateur ?" data-form-id="del-<?= $user['id'] ?>">Supprimer</a>
                                <form id="del-<?= $user['id'] ?>" method="post" action="/admin/users/<?= $user['id'] ?>/delete" style="display:none;">
                                    <?= Csrf::field() ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $totalPages = (int) ceil($pagination['total'] / $pagination['perPage']);
        $qs = $search !== '' ? '&q=' . urlencode($search) : '';
        ?>
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === $pagination['page']): ?>
                        <span class="current"><?= $p ?></span>
                    <?php else: ?>
                        <a href="/admin/users?page=<?= $p ?><?= $qs ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
