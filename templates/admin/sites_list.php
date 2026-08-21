<div class="card wide">
    <div class="topbar">
        <h1>Gestion des sites</h1>
        <div>
            <a href="/" class="muted-link">← Choix de site</a> ·
            <a href="/admin/users" class="muted-link">Gérer les utilisateurs</a> ·
            <a href="/logout" class="muted-link">Déconnexion (<?= htmlspecialchars($username) ?>)</a>
        </div>
    </div>

    <div class="topbar">
        <form method="get" action="/admin/sites" class="search-box" style="flex: 1; margin-bottom: 0;">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher par nom ou code…">
        </form>
        <a href="/admin/sites/new" class="btn btn-primary" style="margin-left: 16px;">+ Nouveau site</a>
    </div>

    <?php if (empty($pagination['items'])): ?>
        <p class="empty">Aucun site trouvé.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Code</th>
                    <th>URL back-office</th>
                    <th>Statut</th>
                    <th>Utilisateurs</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pagination['items'] as $site): ?>
                    <tr>
                        <td><?= htmlspecialchars($site['name']) ?></td>
                        <td><?= htmlspecialchars($site['code']) ?></td>
                        <td><a href="<?= htmlspecialchars($site['backoffice_url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($site['backoffice_url']) ?></a></td>
                        <td>
                            <span class="badge <?= $site['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $site['is_active'] ? 'Actif' : 'Inactif' ?>
                            </span>
                        </td>
                        <td><?= (int) $userCounts[$site['id']] ?></td>
                        <td class="row-actions">
                            <a href="/admin/sites/<?= $site['id'] ?>/edit">Modifier</a>
                            <a href="#" data-confirm="Supprimer ce site ?" data-form-id="del-<?= $site['id'] ?>">Supprimer</a>
                            <form id="del-<?= $site['id'] ?>" method="post" action="/admin/sites/<?= $site['id'] ?>/delete" style="display:none;">
                                <?= Csrf::field() ?>
                            </form>
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
                        <a href="/admin/sites?page=<?= $p ?><?= $qs ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
