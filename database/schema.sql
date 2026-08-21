-- Schéma de la passerelle multi-back-office (back-coop)
-- À importer une seule fois (via phpMyAdmin IONOS en prod, ou automatiquement en Docker local).

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'dev') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    backoffice_url VARCHAR(500) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username_created (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_site (
    user_id INT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, site_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Compte dev de démarrage : identifiant "dev", mot de passe "ChangeMe123!"
-- À changer immédiatement après la première connexion.
INSERT INTO users (username, password_hash, role)
VALUES ('dev', '$2y$10$6fm2at/0gn6Fowlfw1No6urp6//BE9iw4Usq1/Zr2dP1pg.D8kwPm', 'dev')
ON DUPLICATE KEY UPDATE username = username;

-- Deux sites d'exemple pour démarrer (à modifier/supprimer depuis l'admin).
INSERT INTO sites (code, name, backoffice_url, sort_order) VALUES
    ('site1', 'Site 1', 'https://backoffice-site1.example.com', 1),
    ('site2', 'Site 2', 'https://backoffice-site2.example.com', 2)
ON DUPLICATE KEY UPDATE name = VALUES(name);
