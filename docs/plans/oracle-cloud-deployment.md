# Déploiement sur Oracle Cloud Always Free

Stack : Oracle Cloud ARM VM + Nginx + PHP 8.3-FPM + PostgreSQL + Let's Encrypt

## Prérequis

- Compte Oracle Cloud créé (carte de crédit requise, jamais débitée pour Always Free)
- Nom de domaine (optionnel pour SSL, sinon IP publique en attendant)
- Accès SSH local

---

## Étape 1 — Créer le compte Oracle Cloud

1. Aller sur https://www.oracle.com/cloud/free/
2. Créer un compte (email, carte de crédit pour vérification)
3. Choisir une région home (Paris ou Amsterdam — ne peut pas être changée après)

---

## Étape 2 — Créer la VM ARM

1. Dans la console OCI → **Compute** → **Instances** → **Create Instance**
2. Configuration :
   - Image : **Ubuntu 22.04** (Canonical)
   - Shape : **VM.Standard.A1.Flex** (ARM Ampere)
   - OCPU : **2** / RAM : **12 GB** (limites Always Free 2026)
   - Boot volume : **100 GB** (sur les 200 GB disponibles)
3. Réseau :
   - Créer un nouveau VCN ou utiliser l'existant
   - Cocher **Assign public IPv4 address**
4. SSH :
   - Générer ou uploader une clé SSH publique
   - Sauvegarder la clé privée (`.pem`)
5. Cliquer **Create**

---

## Étape 3 — Ouvrir les ports firewall

### Dans la console OCI (Security List)

Networking → Virtual Cloud Networks → ton VCN → Security Lists → Default

Ajouter les Ingress Rules :
| Source CIDR | Protocol | Port |
|---|---|---|
| 0.0.0.0/0 | TCP | 80 |
| 0.0.0.0/0 | TCP | 443 |

### Sur la VM (iptables Ubuntu)

```bash
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save
```

---

## Étape 4 — Connexion SSH

```bash
ssh -i /chemin/vers/clé.pem ubuntu@<IP_PUBLIQUE>
```

---

## Étape 5 — Installer le stack serveur

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.3
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.3 php8.3-fpm php8.3-cli php8.3-pgsql php8.3-xml \
  php8.3-mbstring php8.3-intl php8.3-curl php8.3-zip php8.3-gd php8.3-opcache

# Nginx
sudo apt install -y nginx

# PostgreSQL
sudo apt install -y postgresql postgresql-contrib

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Git
sudo apt install -y git

# Certbot (SSL)
sudo apt install -y certbot python3-certbot-nginx
```

---

## Étape 6 — Configurer PostgreSQL

```bash
sudo -u postgres psql

# Dans psql :
CREATE USER trading_user WITH PASSWORD 'mot_de_passe_fort';
CREATE DATABASE trading_data OWNER trading_user;
GRANT ALL PRIVILEGES ON DATABASE trading_data TO trading_user;
\q
```

---

## Étape 7 — Déployer le projet

```bash
# Créer le dossier
sudo mkdir -p /var/www/trading-tracker
sudo chown ubuntu:ubuntu /var/www/trading-tracker

# Cloner le repo
git clone https://github.com/<ton-user>/trading-tracker.git /var/www/trading-tracker
cd /var/www/trading-tracker

# Créer le .env avec les vraies valeurs
cp .env.example .env  # ou créer manuellement

# Contenu du .env à remplir :
# APP_ENV=prod
# APP_SECRET=<générer avec: php -r "echo bin2hex(random_bytes(32));"> 
# DATABASE_URL="postgresql://trading_user:mot_de_passe_fort@127.0.0.1:5432/trading_data?serverVersion=15&charset=utf8"
# R2_BUCKET=trading-tracker-screenshot
# R2_ENDPOINT=https://1818a26f16f2ea1b28f461f17c3cc14b.r2.cloudflarestorage.com
# R2_ACCESS_KEY_ID=c75344e443d386daca824c1714ba4e06
# R2_SECRET_ACCESS_KEY=3642a3dcfdf5981d9a59083020e6d721510d626514f927004f47d6524f0ab437
# SCREENSHOTS_BASE_URL=https://pub-5fe7e03a5e464847ab71b174a80d3cf0.r2.dev

# Installer les dépendances
composer install --no-dev --optimize-autoloader

# Permissions
sudo chown -R www-data:www-data /var/www/trading-tracker/var
sudo chmod -R 775 /var/www/trading-tracker/var

# Migrations
APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction

# Cache
APP_ENV=prod php bin/console cache:warmup
```

---

## Étape 8 — Configurer Nginx

```bash
sudo nano /etc/nginx/sites-available/trading-tracker
```

Contenu :

```nginx
server {
    listen 80;
    server_name <ton-domaine.com>;  # ou IP publique
    root /var/www/trading-tracker/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    error_log /var/log/nginx/trading-tracker_error.log;
    access_log /var/log/nginx/trading-tracker_access.log;
}
```

```bash
sudo ln -s /etc/nginx/sites-available/trading-tracker /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## Étape 9 — Nom de domaine (gratuit ou payant)

### Option A — Dynu (sous-domaine gratuit, recommandé pour tester)

1. Créer un compte sur https://www.dynu.com/
2. **DDNS Services** → **Add** → choisir un nom ex: `trading-tracker.dynu.net`
3. Pointer vers l'IP publique Oracle
4. Compte gratuit, ne expire jamais (4 sous-domaines max)

Mettre à jour le `server_name` dans Nginx :
```nginx
server_name trading-tracker.dynu.net;
```

### Option B — Domaine payant (pour production)

Cloudflare Registrar (https://www.cloudflare.com/products/registrar/) — prix coûtant, ~€10-12/an pour un `.com`.
Puis pointer le DNS vers l'IP Oracle via un enregistrement A.

---

## Étape 10 — SSL avec Let's Encrypt

```bash
sudo certbot --nginx -d trading-tracker.dynu.net
# ou avec ton domaine payant :
sudo certbot --nginx -d ton-domaine.com
# Certbot modifie automatiquement la config Nginx pour HTTPS
# Renouvellement automatique inclus
```

---

## Étape 11 — Vérifier l'app

Ouvrir `http://<IP_PUBLIQUE>` (sans domaine), `https://trading-tracker.dynu.net` (Dynu), ou `https://ton-domaine.com` (domaine payant) dans le navigateur.

---

## Déploiements futurs (mise à jour du code)

```bash
cd /var/www/trading-tracker
git pull
composer install --no-dev --optimize-autoloader
APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod php bin/console cache:warmup
sudo chown -R www-data:www-data var/
```

---

## Ajouter un nouveau projet sur la même VM

1. Créer un nouveau dossier dans `/var/www/`
2. Créer un nouveau bloc `server {}` dans Nginx avec un sous-domaine différent
3. Recharger Nginx

La VM ARM (2 OCPU / 12 GB RAM) peut faire tourner 5-10 projets légers simultanément.