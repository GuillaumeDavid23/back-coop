<div class="card wide">
    <div class="topbar">
        <h1>Choisissez un site</h1>
        <div>
            <?php if ($isDev): ?>
                <a href="/admin/sites" class="muted-link">Gérer les sites</a> ·
                <a href="/admin/users" class="muted-link">Gérer les utilisateurs</a> ·
            <?php endif; ?>
            <a href="/logout" class="muted-link">Déconnexion (<?= htmlspecialchars($username) ?>)</a>
        </div>
    </div>

    <?php if (count($sites) > 6): ?>
        <div class="search-box">
            <input type="text" data-filter-target="#site-grid" placeholder="Rechercher un site…">
        </div>
    <?php endif; ?>

    <?php if (empty($sites)): ?>
        <p class="empty">Aucun site ne vous est encore attribué. Contactez un administrateur.</p>
    <?php else: ?>
        <div class="site-grid" id="site-grid">
            <?php foreach ($sites as $site): ?>
                <a class="site-card" href="/go/<?= urlencode($site['code']) ?>" data-name="<?= htmlspecialchars(mb_strtolower($site['name'] . ' ' . $site['code'])) ?>">
                    <div class="name"><?= htmlspecialchars($site['name']) ?></div>
                    <div class="code"><?= htmlspecialchars($site['code']) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
