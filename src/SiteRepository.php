<?php

final class SiteRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Sites accessibles à un utilisateur : tous les sites actifs pour un "dev",
     * uniquement ceux explicitement affectés pour un utilisateur normal.
     *
     * @return array<int, array<string, mixed>>
     */
    public function accessibleToUser(int $userId, bool $isDev): array
    {
        if ($isDev) {
            $stmt = $this->db->query(
                'SELECT * FROM sites WHERE is_active = 1 ORDER BY sort_order, name'
            );

            return $stmt->fetchAll();
        }

        $stmt = $this->db->prepare(
            'SELECT s.* FROM sites s
             INNER JOIN user_site us ON us.site_id = s.id
             WHERE us.user_id = :user_id AND s.is_active = 1
             ORDER BY s.sort_order, s.name'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sites WHERE code = :code');
        $stmt->execute(['code' => $code]);
        $site = $stmt->fetch();

        return $site ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sites WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $site = $stmt->fetch();

        return $site ?: null;
    }

    public function userHasAccess(int $siteId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM user_site WHERE site_id = :site_id AND user_id = :user_id'
        );
        $stmt->execute(['site_id' => $siteId, 'user_id' => $userId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Liste paginée + recherche pour l'interface d'administration.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function paginate(string $search, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $where = '';
        $params = [];
        if ($search !== '') {
            // Deux paramètres distincts requis : avec ATTR_EMULATE_PREPARES à false,
            // un même paramètre nommé ne peut pas être réutilisé plusieurs fois.
            $where = 'WHERE name LIKE :search1 OR code LIKE :search2';
            $params['search1'] = '%' . $search . '%';
            $params['search2'] = '%' . $search . '%';
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM sites $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT * FROM sites $where ORDER BY sort_order, name LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO sites (code, name, backoffice_url, is_active, sort_order)
             VALUES (:code, :name, :backoffice_url, :is_active, :sort_order)'
        );
        $stmt->execute([
            'code' => $data['code'],
            'name' => $data['name'],
            'backoffice_url' => $data['backoffice_url'],
            'is_active' => $data['is_active'] ? 1 : 0,
            'sort_order' => $data['sort_order'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE sites SET code = :code, name = :name, backoffice_url = :backoffice_url,
             is_active = :is_active, sort_order = :sort_order WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'code' => $data['code'],
            'name' => $data['name'],
            'backoffice_url' => $data['backoffice_url'],
            'is_active' => $data['is_active'] ? 1 : 0,
            'sort_order' => $data['sort_order'],
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM sites WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /** @param int[] $userIds */
    public function setUserAccess(int $siteId, array $userIds): void
    {
        $this->db->beginTransaction();

        $delete = $this->db->prepare('DELETE FROM user_site WHERE site_id = :site_id');
        $delete->execute(['site_id' => $siteId]);

        $insert = $this->db->prepare(
            'INSERT INTO user_site (user_id, site_id) VALUES (:user_id, :site_id)'
        );
        foreach ($userIds as $userId) {
            $insert->execute(['user_id' => $userId, 'site_id' => $siteId]);
        }

        $this->db->commit();
    }

    /** @return int[] */
    public function userIdsForSite(int $siteId): array
    {
        $stmt = $this->db->prepare('SELECT user_id FROM user_site WHERE site_id = :site_id');
        $stmt->execute(['site_id' => $siteId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
