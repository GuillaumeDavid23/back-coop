<?php

final class UserRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->query('SELECT id, username, role FROM users ORDER BY username')->fetchAll();
    }

    public function countDevs(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'dev'")->fetchColumn();
    }

    /**
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
            $where = 'WHERE username LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT id, username, role, created_at FROM users $where ORDER BY username LIMIT :limit OFFSET :offset"
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

    public function create(string $username, string $password, string $role): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, :role)'
        );
        $stmt->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $username, string $role, ?string $newPassword): void
    {
        if ($newPassword !== null && $newPassword !== '') {
            $stmt = $this->db->prepare(
                'UPDATE users SET username = :username, role = :role, password_hash = :password_hash WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'username' => $username,
                'role' => $role,
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ]);
            return;
        }

        $stmt = $this->db->prepare('UPDATE users SET username = :username, role = :role WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'username' => $username,
            'role' => $role,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
