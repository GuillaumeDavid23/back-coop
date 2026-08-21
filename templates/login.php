<div class="card">
    <div class="brand">
        <img src="/assets/img/logo.png" alt="CLCOM" style="max-width: 220px; width: 100%; height: auto; display: block; margin: 0 auto 8px;">
    </div>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="username">Identifiant</label>
            <input type="text" id="username" name="username" autocomplete="username" required autofocus>
        </div>
        <div class="field">
            <label for="password">Mot de passe</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <div class="actions">
            <button type="submit" class="btn-primary">Connexion</button>
        </div>
    </form>
</div>
