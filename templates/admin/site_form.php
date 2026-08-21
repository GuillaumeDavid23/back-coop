<div class="card wide">
    <div class="topbar">
        <h1><?= $site ? 'Modifier le site' : 'Nouveau site' ?></h1>
        <a href="/admin/sites" class="muted-link">← Retour à la liste</a>
    </div>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $site ? '/admin/sites/' . $site['id'] . '/edit' : '/admin/sites/new' ?>">
        <?= Csrf::field() ?>

        <div class="field">
            <label for="name">Nom du site / événement</label>
            <input type="text" id="name" name="name" required value="<?= htmlspecialchars($site['name'] ?? $old['name'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="code">Code (identifiant unique dans l'URL)</label>
            <input type="text" id="code" name="code" required pattern="[a-z0-9\-]+" value="<?= htmlspecialchars($site['code'] ?? $old['code'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="backoffice_url">URL du back-office</label>
            <input type="url" id="backoffice_url" name="backoffice_url" required value="<?= htmlspecialchars($site['backoffice_url'] ?? $old['backoffice_url'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="sort_order">Ordre d'affichage</label>
            <input type="number" id="sort_order" name="sort_order" value="<?= htmlspecialchars((string) ($site['sort_order'] ?? $old['sort_order'] ?? 0)) ?>">
        </div>

        <div class="field">
            <label>
                <input type="checkbox" name="is_active" value="1" <?= (!isset($site) || $site['is_active']) ? 'checked' : '' ?> style="width:auto; margin-right:6px;">
                Site actif (visible pour les utilisateurs)
            </label>
        </div>

        <div class="field">
            <label>Utilisateurs ayant accès à ce site</label>
            <input type="text" data-filter-target="#user-list" placeholder="Filtrer les utilisateurs…" style="margin-bottom: 8px;">
            <div class="checkbox-list" id="user-list">
                <?php foreach ($allUsers as $user): ?>
                    <?php if ($user['role'] === 'dev') continue; ?>
                    <label data-name="<?= htmlspecialchars(mb_strtolower($user['username'])) ?>">
                        <input type="checkbox" name="user_ids[]" value="<?= $user['id'] ?>"
                            <?= in_array($user['id'], $assignedUserIds, true) ? 'checked' : '' ?>
                            style="width:auto; margin-right:6px;">
                        <?= htmlspecialchars($user['username']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="btn-primary">Enregistrer</button>
        </div>
    </form>
</div>
