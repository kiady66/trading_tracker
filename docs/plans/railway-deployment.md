# Plan de déploiement — Railway + Cloudflare R2

## Contexte

L'application Symfony doit être hébergée gratuitement pendant la phase de développement.
Les screenshots de trades (~200 fichiers) sont actuellement stockés localement dans `public/uploads/screenshots/`.
Le système de fichiers Railway étant éphémère, les fichiers doivent être migrés vers un stockage objet externe.

**Stack cible :**
- **Hébergeur** : Railway (app Symfony + PostgreSQL)
- **Stockage fichiers** : Cloudflare R2 (10 GB gratuits, sans expiration)

---

## Étape 1 — Cloudflare R2 : créer le bucket

1. Créer un compte sur [cloudflare.com](https://cloudflare.com)
2. Aller dans **R2 Object Storage** > **Create bucket**
   - Nom du bucket : `trading-tracker-screenshots`
   - Région : automatique
3. Dans les paramètres du bucket, activer **Public access** pour pouvoir afficher les images directement
4. Noter l'URL publique du bucket : `https://pub-xxxx.r2.dev`
5. Créer un **API Token R2** (R2 > Manage R2 API Tokens) avec les droits `Object Read & Write`
   - Noter : `Account ID`, `Access Key ID`, `Secret Access Key`

---

## Étape 2 — Symfony : installer Flysystem

```bash
composer require league/flysystem-bundle
composer require async-aws/s3 async-aws/core
```

Configurer `config/packages/flysystem.yaml` :

```yaml
flysystem:
    storages:
        screenshots.storage:
            adapter: 'asyncaws'
            options:
                client: 'AsyncAws\S3\S3Client'
                bucket: '%env(R2_BUCKET)%'
                prefix: ''
```

Configurer `config/packages/async_aws.yaml` :

```yaml
async_aws:
    clients:
        default:
            region: 'auto'
            endpoint: '%env(R2_ENDPOINT)%'
            accessKeyId: '%env(R2_ACCESS_KEY_ID)%'
            accessKeySecret: '%env(R2_SECRET_ACCESS_KEY)%'
```

Variables d'environnement à ajouter dans `.env` :

```dotenv
R2_BUCKET=trading-tracker-screenshots
R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
R2_ACCESS_KEY_ID=xxx
R2_SECRET_ACCESS_KEY=xxx
SCREENSHOTS_BASE_URL=https://pub-xxxx.r2.dev
```

---

## Étape 3 — Adapter FileUploader

Modifier `src/Service/FileUploader.php` pour :
- Injecter `FilesystemOperator $screenshotsStorage` (Flysystem)
- Remplacer `$file->move()` par `$screenshotsStorage->write($fileName, file_get_contents($tmpPath))`
- Adapter la compression : compresser dans un fichier temporaire, puis uploader vers R2
- Remplacer `unlink()` dans `remove()` par `$screenshotsStorage->delete($filename)`

---

## Étape 4 — Adapter les templates

Ajouter une variable Twig globale dans `config/packages/twig.yaml` :

```yaml
twig:
    globals:
        screenshots_base_url: '%env(SCREENSHOTS_BASE_URL)%'
```

Remplacer dans les templates concernés (`show.html.twig`, `edit.html.twig`, `new.html.twig`, `home/index.html.twig`) :

```twig
{# Avant #}
asset('uploads/screenshots/' ~ screenshot.filename)

{# Après #}
screenshots_base_url ~ '/' ~ screenshot.filename
```

---

## Étape 5 — Migrer les fichiers existants vers R2

Utiliser `rclone` pour uploader les ~200 screenshots existants en une commande.

### Installer rclone

```bash
brew install rclone
```

### Configurer rclone pour R2

```bash
rclone config
```

Choisir `New remote` > `S3` > `Cloudflare R2`, saisir les credentials notés à l'étape 1.

### Lancer la migration

```bash
rclone copy public/uploads/screenshots/ r2:trading-tracker-screenshots/ --progress
```

Vérifier que tous les fichiers sont bien présents dans le bucket R2 avant de continuer.

---

## Étape 6 — Déployer sur Railway

### Créer le projet

1. Créer un compte sur [railway.app](https://railway.app)
2. **New Project** > **Deploy from GitHub repo** > sélectionner `trading-tracker`
3. Ajouter un service **PostgreSQL** depuis le tableau de bord Railway

### Variables d'environnement Railway

Dans les settings du service app, ajouter :

```
APP_ENV=prod
APP_SECRET=<générer une clé aléatoire>
DATABASE_URL=<copier depuis le service PostgreSQL Railway>
R2_BUCKET=trading-tracker-screenshots
R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
R2_ACCESS_KEY_ID=xxx
R2_SECRET_ACCESS_KEY=xxx
SCREENSHOTS_BASE_URL=https://pub-xxxx.r2.dev
```

### Nixpacks / build

Railway détecte PHP automatiquement via Nixpacks. Créer un fichier `nixpacks.toml` à la racine :

```toml
[phases.setup]
nixPkgs = ["php83", "php83Extensions.pdo_pgsql", "php83Extensions.gd", "php83Extensions.intl", "php83Extensions.mbstring", "composer"]

[phases.build]
cmds = ["composer install --no-dev --optimize-autoloader"]

[start]
cmd = "php -S 0.0.0.0:$PORT -t public/"
```

### Post-déploiement

Une fois déployé, lancer les migrations via la console Railway :

```bash
symfony console doctrine:migrations:migrate --no-interaction
```

---

## Checklist finale

- [ ] Compte Cloudflare créé
- [ ] Bucket R2 créé avec accès public
- [ ] API Token R2 généré
- [ ] Flysystem installé et configuré
- [ ] `FileUploader` adapté pour R2
- [ ] Templates mis à jour
- [ ] Screenshots existants migrés avec rclone
- [ ] Compte Railway créé
- [ ] Projet Railway créé + PostgreSQL ajouté
- [ ] Variables d'environnement configurées sur Railway
- [ ] `nixpacks.toml` créé
- [ ] Premier déploiement réussi
- [ ] Migrations lancées en production
