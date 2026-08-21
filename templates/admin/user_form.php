<div class="card">
    <div class="topbar">
        <h1><?= $user ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur' ?></h1>
        <a href="/admin/users" class="muted-link">← Retour à la liste</a>
    </div>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $user ? '/admin/users/' . $user['id'] . '/edit' : '/admin/users/new' ?>">
        <?= Csrf::field() ?>

        <div class="field">
            <label for="username">Identifiant</label>
            <input type="text" id="username" name="username" required value="<?= htmlspecialchars($user['username'] ?? $old['username'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="role">Rôle</label>
            <select id="role" name="role">
                <?php $role = $user['role'] ?? $old['role'] ?? 'user'; ?>
                <option value="user" <?= $role === 'user' ? 'selected' : '' ?>>Utilisateur</option>
                <option value="dev" <?= $role === 'dev' ? 'selected' : '' ?>>Dev (accès admin)</option>
            </select>
        </div>

        <div class="field">
            <label for="password"><?= $user ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe' ?></label>
            <input type="password" id="password" name="password" autocomplete="new-password" <?= $user ? '' : 'required' ?>>
        </div>

        <div class="field">
            <label for="password_confirm">Confirmer le mot de passe</label>
            <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password">
        </div>

        <div class="actions">
            <button type="submit" class="btn-primary">Enregistrer</button>
        </div>
    </form>
</div>
