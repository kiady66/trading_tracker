# Plan : Page « News » — wrap quotidien des news de marché

## Objectif

Ajouter une page « News » qui affiche un résumé quotidien (wrap) des news et données macro
susceptibles de faire bouger les marchés suivis : **forex, or, pétrole, indices boursiers US**.

Le contenu HTML est généré une fois par jour par une commande Symfony qui pilote `claude -p`
(sources principales : investinglive.com / forexlive.com et autres sites de référence),
puis est enregistré en base de données et affiché dans l'application.

**Règle éditoriale stricte** : l'IA ne donne jamais son avis ni de prévision. Elle rapporte
les faits (news, chiffres publiés vs attendus) et peut décrire **la réaction constatée du
marché** (ex : « l'or a bondi de 1,2 % après la publication du CPI »).

## Décisions validées

- **Pipeline** : une commande Symfony `app:news:generate` exécute `claude -p` via
  `symfony/process`, récupère le HTML produit et l'enregistre via Doctrine. Le cron
  n'appellera qu'une seule commande.
- **Régénération** : une seule news par date (contrainte d'unicité). Relancer la commande
  le même jour **remplace** le contenu existant (upsert).
- **Langue** : contenu en **français**, termes de marché usuels conservés en anglais
  (NFP, hawkish, risk-on…).
- **Cron** : hors périmètre — mis en place manuellement par l'utilisateur plus tard.
  La commande accepte une option `--date` pour pouvoir générer/regénérer un jour précis.

## 1. Entity `DailyNews`

`src/Entity/DailyNews.php` + `src/Repository/DailyNewsRepository.php`

| Champ         | Type                          | Notes                                  |
|---------------|-------------------------------|----------------------------------------|
| `id`          | int, auto                     |                                        |
| `date`        | `date_immutable`, **unique**  | jour couvert par le wrap               |
| `contentHtml` | `text`                        | fragment HTML (pas de `<html>/<head>`) |
| `generatedAt` | `datetime_immutable`          | mis à jour à chaque (re)génération     |

Repository :
- `findOneByDate(\DateTimeImmutable $date): ?DailyNews`
- `findAvailableDates(): array` — liste des dates existantes (pour le sélecteur et les liens précédent/suivant)

Pas de relation avec `User` : la news est globale à l'application.

## 2. Migration

`migrations/Version20260718*.php` — écrite à la main (cohérent avec les migrations existantes) :

```sql
CREATE TABLE daily_news (
    id SERIAL PRIMARY KEY,
    date DATE NOT NULL,
    content_html TEXT NOT NULL,
    generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
);
CREATE UNIQUE INDEX UNIQ_daily_news_date ON daily_news (date);
```

## 3. Commande `app:news:generate`

`src/Command/GenerateDailyNewsCommand.php`

- Option `--date=YYYY-MM-DD` (défaut : aujourd'hui).
- Construit un prompt (constante/heredoc dans la classe) qui demande à Claude de :
  - rechercher les news du jour sur **investinglive.com / forexlive.com** en priorité,
    complétées par d'autres sources reconnues (Reuters, Bloomberg, CNBC, Investing.com…) ;
  - couvrir : forex (majors), or, pétrole, indices US (S&P 500, Nasdaq, Dow), calendrier
    éco du jour (chiffres publiés vs consensus vs précédent), banques centrales, géopolitique
    si impact marché ;
  - **aucune opinion, aucune prévision, aucun conseil** — uniquement les faits et la
    réaction observée du marché ;
  - rédiger en français ;
  - produire **uniquement un fragment HTML** (pas de `<html>`, `<head>`, `<style>`, ni
    markdown) utilisant exclusivement le vocabulaire CSS défini (voir §6) : titres `h2/h3`,
    tableaux, `.news-card`, `.news-callout`, listes à puces, `<strong>`…
- Exécute : `claude -p <prompt> --allowedTools "WebSearch,WebFetch" --output-format text`
  via `Symfony\Component\Process\Process`, timeout long (15 min), `-C` vers un répertoire
  neutre pour ne pas hériter du contexte du projet.
- Nettoie la sortie (retire d'éventuelles fences ```html), valide qu'elle est non vide et
  contient du HTML, puis **upsert** : met à jour la ligne existante pour la date ou en crée une.
- Affiche un résumé (date, taille du contenu, créé/remplacé).

Dépendance à ajouter : `composer require symfony/process` (si absent).

## 4. Contrôleur + route

`src/Controller/NewsController.php`

- `#[Route('/news', name: 'app_news')]` — protégé par le firewall existant (ROLE_USER).
- Paramètre query `?date=YYYY-MM-DD` ; défaut : la news la plus récente disponible.
- Passe au template : la news (ou null), la liste des dates disponibles, la date
  précédente/suivante pour la navigation.
- Si aucune news n'existe : état vide propre (« Aucune news pour cette date »).

## 5. Template + sélecteur de date

`templates/news/index.html.twig`

- Étend `base.html.twig`.
- En-tête de page : titre + date affichée + heure de génération.
- Sélecteur de date : `<input type="date">` (soumis en GET, auto-submit au changement)
  encadré par des boutons « ← jour précédent / jour suivant → » qui sautent directement
  aux dates disponibles.
- Affiche `daily_news.contentHtml|raw` dans un conteneur `.news-content`
  (contenu généré par notre propre commande — pas de saisie utilisateur).

## 6. CSS de base pour les wraps

Bloc `stylesheets` du template news (cohérent avec le style inline de `base.html.twig`),
sous le namespace `.news-content`, réutilisé par **tous les HTML générés à l'avenir** :

- `h2` (sections : Forex / Or / Pétrole / Indices / Calendrier éco), `h3` (sous-sections) ;
- `table` stylée (calendrier éco : heure, événement, publié, consensus, précédent) ;
- `.news-card` : carte encadrée pour un sujet/actif ;
- `.news-callout` : encadré mis en évidence pour l'info clé du jour ;
- listes `ul/li`, `strong`, `.up`/`.down` (vert/rouge pour les variations) ;
- responsive, cohérent avec les variables CSS existantes (`var(--border)`, etc.).

Le prompt de la commande référence exactement ces classes pour que tous les wraps
partagent la même mise en forme.

## 7. Navigation

`templates/base.html.twig` : ajouter dans `.nav-center` un lien
`<a class="nav-link-app" href="{{ path('app_news') }}"><i class="bi bi-newspaper"></i> News</a>`
entre « Statistiques » et « Configuration ».

## Fichiers créés / modifiés

| Action   | Fichier                                       |
|----------|-----------------------------------------------|
| créer    | `src/Entity/DailyNews.php`                    |
| créer    | `src/Repository/DailyNewsRepository.php`      |
| créer    | `migrations/Version20260718*.php`             |
| créer    | `src/Command/GenerateDailyNewsCommand.php`    |
| créer    | `src/Controller/NewsController.php`           |
| créer    | `templates/news/index.html.twig`              |
| modifier | `templates/base.html.twig` (lien nav)         |

## Vérification

1. `symfony console doctrine:migrations:migrate` — table `daily_news` créée.
2. `symfony console app:news:generate` — génère et enregistre le wrap du jour
   (test réel avec `claude -p`).
3. Relancer la commande → le contenu est remplacé, pas dupliqué.
4. `/news` affiche le wrap ; le sélecteur de date et précédent/suivant fonctionnent ;
   état vide correct pour une date sans news.

## Hors périmètre

- Mise en place du cron (fait manuellement par l'utilisateur).
- Historique/versionnage des régénérations.
- Édition manuelle du contenu depuis l'interface.