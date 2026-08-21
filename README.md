# back-coop — passerelle multi-back-office

Écran de connexion unique : l'utilisateur se connecte, choisit un site
(événement), et est redirigé vers l'URL du back-office correspondant.
PHP natif, sans framework ni dépendance Composer — volontairement léger
pour un hébergement mutualisé avec quota de fichiers limité (IONOS, sans
accès root/sudo).

## Dev local (Docker)

Prérequis : Docker + Docker Compose.

```bash
docker compose up -d --build
```

- Application : http://localhost:8080
- phpMyAdmin : http://localhost:8081 (serveur `db`, utilisateur/mot de passe
  définis dans `docker-compose.yml` ou `.env.docker`)
- La base est initialisée automatiquement depuis `database/schema.sql` au
  premier démarrage (volume `db_data` vide).

Compte de démarrage :
- identifiant : `dev`
- mot de passe : `ChangeMe123!` (rôle `dev`, accès à l'admin `/admin/sites`)

Pour personnaliser les identifiants MySQL locaux : copier `.env.docker.dist`
en `.env.docker` puis lancer `docker compose --env-file .env.docker up -d --build`.

Deux sites d'exemple sont créés (`site1`, `site2`) avec des URLs de
démonstration — à modifier ou supprimer depuis `/admin/sites` une fois
connecté avec le compte `dev`. La gestion des utilisateurs (créer, changer
le rôle/mot de passe, supprimer) se fait depuis `/admin/users`, réservée
elle aussi au rôle `dev`.

### Faire tourner plusieurs projets Docker en parallèle

Quand vous développerez le back-office d'un nouveau site à côté de cette
passerelle, les deux `docker compose up` doivent pouvoir cohabiter :

- Chaque projet a un nom Compose explicite (`name:` en tête de
  `docker-compose.yml`) — donnez un nom différent au projet du nouveau site
  pour éviter toute collision de conteneurs/réseaux.
- Les ports (`APP_PORT`, `PHPMYADMIN_PORT`, `DB_PORT`) sont paramétrables
  via un fichier `.env.docker` (copié depuis `.env.docker.dist`). Donnez des
  valeurs différentes à chaque projet, par exemple :

  ```
  # back-coop/.env.docker
  APP_PORT=8080
  PHPMYADMIN_PORT=8081
  DB_PORT=3307

  # nouveau-site/.env.docker
  APP_PORT=8090
  PHPMYADMIN_PORT=8091
  DB_PORT=3317
  ```

  Puis démarrer chaque projet avec `docker compose --env-file .env.docker up -d --build`.
- Les deux bases MySQL restent totalement isolées (volumes Docker séparés
  par projet), donc aucun risque d'interférence des données entre la
  passerelle et le back-office testé.

Avec ça, vous pouvez laisser `back-coop` tourner en local et lancer le
`docker compose` du nouveau site à côté pour tester le flux complet :
connexion sur la passerelle → redirection → back-office du nouveau site.

## Sécurité

- Mots de passe hashés (`password_hash`), requêtes SQL préparées, jetons
  CSRF sur tous les formulaires.
- Sessions : cookie `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS,
  régénération d'ID à la connexion.
- Verrouillage anti-brute-force : 5 échecs de connexion pour un identifiant
  bloquent les tentatives suivantes pendant 15 minutes (table `login_attempts`).
- En-têtes de sécurité HTTP sur toutes les réponses : `X-Frame-Options`,
  `X-Content-Type-Options`, `Referrer-Policy`, `Content-Security-Policy`
  stricte (aucun script inline), `Strict-Transport-Security` en HTTPS.
- Les erreurs PHP ne sont jamais affichées au navigateur en cas de bug
  (page générique + log serveur), pour ne pas fuiter de chemins/infos internes.
- Un compte `dev` ne peut pas supprimer son propre compte, retirer son
  propre rôle `dev`, ni supprimer le dernier compte `dev` restant (évite un
  verrouillage total de l'admin).

Limites connues, à évaluer selon le contexte de déploiement réel :
pas de 2FA, pas de politique de rotation de mot de passe, pas de journal
d'audit des actions admin (qui a modifié/supprimé quel site ou utilisateur).

## Déploiement en production (IONOS, sans root/sudo)

1. Créer une base MySQL depuis le panneau client IONOS.
2. Importer `database/schema.sql` via phpMyAdmin (fourni par IONOS) —
   pensez à changer le mot de passe du compte `dev` après le premier login.
3. Copier `config/config.php.dist` en `config/config.php` et renseigner les
   vraies informations de connexion MySQL (`config/config.php` ne doit pas
   être versionné dans un dépôt public).
4. Uploader tout le projet via SFTP/FTP (pas de `composer install`, pas de
   build : aucune dépendance externe).
5. Configurer le document root du (sous-)domaine sur le dossier `public/`
   depuis le panneau IONOS. Si ce n'est pas possible sur votre offre, le
   `.htaccess` à la racine du projet bloque déjà l'accès direct à `src/`,
   `config/` et `database/`.

## Structure

- `public/` — point d'entrée web (front controller `index.php`, assets).
- `src/` — classes PHP (routeur, auth, accès BDD).
- `templates/` — vues PHP simples (pas de moteur de templating).
- `database/schema.sql` — schéma + données de démarrage.
- `docker-compose.yml`, `docker/` — environnement de dev local uniquement
  (non utilisé en production).
