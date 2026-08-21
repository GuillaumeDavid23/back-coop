<?php

final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function __construct(private PDO $db, private UserRepository $users)
    {
    }

    public function attempt(string $username, string $password): bool|string
    {
        if ($this->isLockedOut($username)) {
            return 'locked';
        }

        $user = $this->users->findByUsername($username);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $this->recordFailedAttempt($username);
            return false;
        }

        $this->clearAttempts($username);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        return true;
    }

    private function isLockedOut(string $username): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE username = :username AND created_at > (NOW() - INTERVAL :minutes MINUTE)"
        );
        $stmt->bindValue(':username', $username);
        $stmt->bindValue(':minutes', self::LOCKOUT_MINUTES, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    private function recordFailedAttempt(string $username): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO login_attempts (username, ip_address, created_at) VALUES (:username, :ip, NOW())'
        );
        $stmt->execute([
            'username' => $username,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        // Purge des vieilles entrées pour ne pas faire grossir la table indéfiniment.
        $this->db->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    }

    private function clearAttempts(string $username): void
    {
        $stmt = $this->db->prepare('DELETE FROM login_attempts WHERE username = :username');
        $stmt->execute(['username' => $username]);
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public function isDev(): bool
    {
        return ($_SESSION['role'] ?? null) === 'dev';
    }

    public function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public function username(): ?string
    {
        return $_SESSION['username'] ?? null;
    }

    public function requireLogin(): void
    {
        if (!$this->check()) {
            header('Location: /login');
            exit;
        }
    }

    public function requireDev(): void
    {
        $this->requireLogin();

        if (!$this->isDev()) {
            http_response_code(403);
            echo 'Accès réservé.';
            exit;
        }
    }
}
