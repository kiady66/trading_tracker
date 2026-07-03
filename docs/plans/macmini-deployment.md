# Déploiement sur Mac mini (Docker)

Stack : Docker Compose + PHP 8.3-FPM + Nginx + PostgreSQL

## Prérequis

- Mac mini ARM (M1/M2/M3) toujours allumé
- Docker Desktop installé : https://www.docker.com/products/docker-desktop/
- Accès à la box/routeur pour le port forwarding

---

## Étape 1 — Cloner le repo sur le Mac mini

```bash
git clone https://github.com/<ton-user>/trading-tracker.git ~/trading-tracker
cd ~/trading-tracker
```

---

## Étape 2 — Créer le fichier .env

```bash
cp .env .env.bak  # optionnel, pour voir la structure
nano .env
```

Valeurs à mettre (différentes du dev) :

```env
APP_ENV=prod
APP_SECRET=<générer avec: php -r "echo bin2hex(random_bytes(32));"> 
# ou: openssl rand -hex 32

DATABASE_URL="postgresql://trading_user:MOT_DE_PASSE@database:5432/trading_data?serverVersion=16&charset=utf8"
# IMPORTANT: l'hôte est "database" (nom du service Docker), pas 127.0.0.1

POSTGRES_PASSWORD=MOT_DE_PASSE

MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
MAILER_DSN=null://null

R2_BUCKET=trading-tracker-screenshot
R2_ENDPOINT=https://1818a26f16f2ea1b28f461f17c3cc14b.r2.cloudflarestorage.com
R2_ACCESS_KEY_ID=c75344e443d386daca824c1714ba4e06
R2_SECRET_ACCESS_KEY=3642a3dcfdf5981d9a59083020e6d721510d626514f927004f47d6524f0ab437
SCREENSHOTS_BASE_URL=https://pub-5fe7e03a5e464847ab71b174a80d3cf0.r2.dev
```

---

## Étape 3 — Lancer les conteneurs

```bash
docker compose -f compose.prod.yaml up -d --build
```

Le premier build prend ~5 minutes (téléchargement des images + compilation).

Vérifier que tout tourne :
```bash
docker compose -f compose.prod.yaml ps
docker compose -f compose.prod.yaml logs app
```

---

## Étape 4 — Migrations

```bash
docker compose -f compose.prod.yaml exec app php bin/console doctrine:migrations:migrate --no-interaction
```

---

## Étape 5 — Vérifier

Ouvrir `http://localhost` dans le navigateur du Mac mini.

Pour accéder depuis un autre appareil sur le réseau local : `http://<IP_LOCAL_MACMINI>` (ex: `http://192.168.1.50`)

---

## Étape 6 — Accès depuis Internet (Dynu DDNS)

### 6a — IP locale statique pour le Mac mini

Dans les réglages de ta box/routeur : assigner une IP fixe au Mac mini via son adresse MAC.
(Sinon l'IP locale peut changer et casser le port forwarding)

### 6b — Port forwarding sur la box

Ouvrir dans ton routeur :
| Port externe | Port interne | Protocole | Destination |
|---|---|---|---|
| 80 | 80 | TCP | IP du Mac mini |
| 443 | 443 | TCP | IP du Mac mini |

### 6c — Dynu DDNS

1. Créer un compte sur https://www.dynu.com/
2. **DDNS Services** → **Add** → nom ex: `trading-tracker.dynu.net`
3. Pointer vers ton IP publique (Dynu la détecte automatiquement)
4. Activer la mise à jour automatique : télécharger le client Dynu pour macOS, ou configurer un cron :

```bash
# Mise à jour IP automatique (remplacer les valeurs)
# Ajouter dans crontab -e :
*/5 * * * * curl -s "https://api.dynu.com/nic/update?hostname=trading-tracker.dynu.net&password=TON_HASH_DYNU" > /dev/null
```

---

## Étape 7 — SSL avec Let's Encrypt (optionnel)

Installer certbot via Homebrew sur le Mac mini :

```bash
brew install certbot
```

Obtenir le certificat :

```bash
sudo certbot certonly --standalone -d trading-tracker.dynu.net \
  --email ton@email.com --agree-tos --no-eff-email
```

Les certs sont dans `/etc/letsencrypt/live/trading-tracker.dynu.net/`.
Ils sont déjà montés dans le conteneur nginx via `compose.prod.yaml`.

Mettre à jour `docker/nginx/default.conf` pour activer HTTPS :

```nginx
server {
    listen 80;
    server_name trading-tracker.dynu.net;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name trading-tracker.dynu.net;
    root /var/www/html/public;

    ssl_certificate /etc/letsencrypt/live/trading-tracker.dynu.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/trading-tracker.dynu.net/privkey.pem;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass app:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

Recharger Nginx :
```bash
docker compose -f compose.prod.yaml exec nginx nginx -s reload
```

Renouvellement automatique (crontab) :
```bash
# crontab -e
0 3 * * * sudo certbot renew --quiet && docker compose -f ~/trading-tracker/compose.prod.yaml exec nginx nginx -s reload
```

---

## Mises à jour du code

```bash
cd ~/trading-tracker
git pull
docker compose -f compose.prod.yaml up -d --build
docker compose -f compose.prod.yaml exec app php bin/console doctrine:migrations:migrate --no-interaction
```

---

## Commandes utiles

```bash
# Voir les logs
docker compose -f compose.prod.yaml logs -f app

# Redémarrer un service
docker compose -f compose.prod.yaml restart app

# Arrêter tout
docker compose -f compose.prod.yaml down

# Tout arrêter + supprimer les données (ATTENTION)
docker compose -f compose.prod.yaml down -v
```
