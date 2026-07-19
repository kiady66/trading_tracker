# Plan : Visibilité communautaire des trades et statistiques

## Objectif

Permettre à chaque utilisateur de rendre son activité de trading visible par les autres utilisateurs connectés, via des paramètres de confidentialité personnels. Les autres peuvent alors :

- découvrir les traders visibles sur une page « Traders » (liste avec résumé : win rate, total R, nombre de trades) ;
- consulter les statistiques d'un trader (si partagées) ;
- parcourir ses trades en cours et/ou terminés (selon les toggles) et ouvrir chaque trade en **version réduite lecture seule**.

Décisions validées avec l'utilisateur :

| Sujet | Décision |
|---|---|
| Découverte | Page liste `/traders` de tous les comptes visibles |
| Identité publique | Nouveau champ **pseudo** (display name), obligatoire pour activer la visibilité ; l'email reste privé |
| Détail d'un trade partagé | Version réduite + screenshots + raison d'entrée (`executionReason`). **Jamais** les erreurs, notes d'erreurs, ni aucun montant en euro — tout est exprimé en R/R |
| « Trades du mois » | Mois calendaire en cours (du 1er à aujourd'hui), stats recalculées sur cette période |
| Trade « à cheval » sur deux mois | Un trade encore **ouvert** est toujours visible même si entré le mois précédent ; la borne du mois s'applique aux trades **terminés** (date de sortie dans le mois) |
| Stats publiques | Page complète (tous les blocs de la page stats actuelle) convertie en R |
| Filtres pour le visiteur | Mêmes filtres de période/confluences que la page stats perso, bornés au mois en cours si « mois uniquement » est actif |
| Tri de la liste /traders | Select entre « Activité récente » (défaut) et « Performance » (total R décroissant) |
| URL du profil public | `/traders/{pseudo}` (le pseudo est unique et sert d'identifiant public, l'ID interne n'est pas exposé) |
| Layout du profil public | 3 onglets : Statistiques / En cours / Terminés |
| Soi-même dans la liste | Exclu de `/traders` ; lien « Voir mon profil public » sur sa propre page profil |
| Colonnes des listes publiques | Complètes : asset, sens, dates entrée/sortie, timeframes, type, RR initial, RR final |
| Traders sans contenu | Masqués de la liste : seuls les traders avec au moins un trade visible apparaissent |
| Pseudo | **Figé après création** (non modifiable → URLs stables), 3–30 caractères `[a-zA-Z0-9_-]`, unique insensible à la casse |
| Trade encore ouvert | Tout montrer (screenshots, raison d'entrée, RR initial) — même contenu qu'un trade terminé |
| Défauts des sous-toggles | Tout activé quand on active le toggle maître ; « mois uniquement » off par défaut |
| Page /stats perso | Corrigée (filtrage user) **et** enrichie d'un switch €/R |
| Menu navigation | « Traders » |
| Pagination trades terminés publics | Les 50 derniers, sans pagination (les filtres de dates affinent) |
| Workflow git | Un commit par étape du plan, directement sur `main` |

## Constat sur l'existant (prérequis)

- `StatsController::index()` ne passe pas `user` dans `$filters` alors que `TradeRepository` le supporte (`TradeRepository.php:241`). **Aujourd'hui la page stats agrège les trades de tous les utilisateurs.** À corriger en premier : passer `['user' => $this->getUser()]` — sinon la notion de « stats d'un utilisateur » n'a pas de sens.
- `TradeController` filtre déjà correctement par utilisateur (`findByUserAndDateRange`) et vérifie l'ownership sur show/edit/delete.
- `Trade.status` distingue déjà `watching` / `open` / `closed` (calculé depuis `entryDate`/`exitDate`). Les trades `watching` ne seront **jamais** exposés aux autres.
- Les screenshots sont servis depuis `public/uploads/` : ils sont techniquement accessibles par URL directe à quiconque connaît le chemin (comportement existant, limitation acceptée par l'utilisateur — hors périmètre).

## 1. Modèle de données — champs sur `User`

Ajouter directement sur l'entité `User` (pas besoin d'une entité dédiée pour 5 booléens + 1 string) :

| Champ | Type | Défaut | Rôle |
|---|---|---|---|
| `displayName` | `string(30)`, unique, nullable | `null` | Pseudo public. Obligatoire pour activer le partage. **Figé une fois défini** |
| `shareEnabled` | `bool` | `false` | **Toggle maître** : rien n'est visible tant qu'il est off |
| `shareStats` | `bool` | `true` | Statistiques visibles |
| `shareOpenTrades` | `bool` | `true` | Trades en cours (`status = open`) visibles |
| `shareClosedTrades` | `bool` | `true` | Trades terminés (`status = closed`) visibles |
| `shareCurrentMonthOnly` | `bool` | `false` | Si actif : seuls les trades du mois calendaire en cours sont visibles, et les stats partagées sont recalculées sur ce périmètre |

Les sous-toggles sont à `true` par défaut mais sans effet tant que `shareEnabled` est `false` : activer le maître partage tout, puis on affine.

Ajouter un helper sur `User` :

```php
public function isProfilePublic(): bool
{
    return $this->shareEnabled && $this->displayName !== null;
}
```

- Migration Doctrine (`doctrine:migrations:diff`) pour les 6 colonnes.
- `UserRepository::findPublicProfiles()` : `WHERE u.shareEnabled = true AND u.displayName IS NOT NULL`.

## 2. Paramètres de confidentialité — page Profil

Étendre la page profil existante (`ProfileController`, `templates/profile/index.html.twig`) :

- Nouveau `Form/ProfileVisibilityType` : champ `displayName` + les 5 toggles (checkboxes stylées en switch, cohérentes avec le reste de l'UI).
- Route `POST /profile/visibility` dans `ProfileController` (CSRF comme `regenerateToken`).
- Validation du pseudo :
  - requis si `shareEnabled` coché (contrainte `Expression` ou validation dans le contrôleur) ;
  - 3–30 caractères, motif `[a-zA-Z0-9_-]+` (doit passer dans l'URL) ;
  - unique **insensible à la casse** (`UniqueEntity` + normalisation, ou requête `LOWER(display_name)`) ;
  - **figé après création** : le champ est désactivé dans le formulaire une fois défini, et le serveur ignore toute tentative de modification (URLs `/traders/{pseudo}` stables).
- UX : les sous-toggles sont grisés/désactivés côté JS (petit contrôleur Stimulus) tant que le toggle maître est off ; le serveur reste la source de vérité.
- Lien « Voir mon profil public » sur la page profil (visible si `isProfilePublic()`), pour prévisualiser ce que les autres voient — c'est le seul accès à son propre profil public puisqu'on est exclu de la liste.

## 3. Page « Traders » — découverte

Nouveau `TraderController` (`/traders`, name `app_traders_`), accessible à tout `ROLE_USER`.

### 3.1 Liste `GET /traders`

- Liste les utilisateurs `isProfilePublic()` **ayant au moins un trade visible** (selon leurs toggles et la règle du mois) — les profils visibles mais vides ou sans trade partagé n'apparaissent pas. L'utilisateur courant est **exclu** de la liste (il se prévisualise via le lien sur sa page profil).
- Carte par trader : pseudo, ancienneté (`createdAt`), et si `shareStats` : win rate, total R, nombre de trades — calculés **en respectant `shareCurrentMonthOnly`** (badge « stats du mois » dans ce cas). Si `shareStats` est off, la carte affiche « stats privées » à la place des métriques.
- **Tri** : select en haut de page avec deux options — « Activité récente » (défaut : date du trade le plus récent, décroissant) et « Performance » (total R décroissant ; les traders qui ne partagent pas leurs stats sont relégués en fin de liste). Paramètre GET `?sort=recent|performance`, petit contrôleur Stimulus ou simple submit du select.
- Résumé calculé via une méthode repository dédiée (voir §5) pour éviter le N+1 ; pagination simple si besoin plus tard (hors périmètre initial).

### 3.2 Profil public `GET /traders/{pseudo}`

- Résolution par pseudo (insensible à la casse). 404 si l'utilisateur cible n'existe pas ou `!isProfilePublic()` (404 plutôt que 403 pour ne pas révéler l'existence d'un profil privé).
- En-tête : pseudo + ancienneté, puis **3 onglets** (les onglets dont le toggle est off sont absents) :
  - **Statistiques** (si `shareStats`) : page stats complète en R (voir §5).
  - **En cours** (si `shareOpenTrades`) : tableau complet — asset, sens, date d'entrée, timeframes, type, RR initial.
  - **Terminés** (si `shareClosedTrades`) : mêmes colonnes + date de sortie et RR final, **limité aux 50 derniers** (pas de pagination ; les filtres de dates permettent d'affiner).
- Onglets implémentés en query param `?tab=stats|open|closed` (ou fragments Turbo) pour garder des URLs partageables.
- **Filtres visiteur** : les mêmes filtres de période et de confluences que la page stats perso sont disponibles sur le profil public. Si `shareCurrentMonthOnly` est actif, les dates demandées sont **écrêtées côté serveur** au mois en cours (`start_date = max(start_date demandée, 1er du mois)`) — un visiteur qui force `?start_date=2026-01-01` dans l'URL ne voit rien hors du mois.
- Si `shareCurrentMonthOnly` : la règle du mois est appliquée **côté SQL**, pas seulement à l'affichage, avec la convention suivante : les trades **terminés** sont bornés sur `exitDate >= 1er du mois courant` ; les trades **ouverts** restent visibles quel que soit leur mois d'entrée (un trade en cours est « actuel » par définition). Bandeau « Ce trader partage uniquement son mois en cours ».
- Son propre profil public reste consultable par soi-même (aperçu « vu par les autres »).

### 3.3 Détail d'un trade `GET /traders/{pseudo}/trades/{tradeId}`

- Contrôles serveur, dans l'ordre : profil public → trade appartient bien à ce user → statut du trade autorisé par les toggles (`open`/`closed`, jamais `watching`) → si `shareCurrentMonthOnly` : un trade `closed` doit avoir `exitDate` dans le mois courant, un trade `open` passe toujours. Sinon 404.
- Template dédié `templates/traders/trade_show.html.twig` (ne **pas** réutiliser `trade/show.html.twig` avec des `if` : trop risqué, une fuite = donnée privée exposée).

**Champs affichés** (version réduite validée) :

- asset, type d'ordre (buy/sell), dates d'entrée/sortie, jour, timeframes, tendance, type de trade, confluences ;
- `initialRR`, `finalRR`, `gainRR` (résultat en R), `riskPercentage` (en % — sans `maxRiskEuro`, impossible d'en déduire un montant) ;
- `executionReason` (la raison d'entrée) ;
- screenshots (exécution / gestion / clôture).

**Champs interdits** (à ne jamais passer au template) :

- `gainEuro`, `maxRiskEuro` (les seuls champs monétaires) ;
- `error`, `noteErrors`, `goodTrade`, `tradeQuality`, `tradeManagement`, `ctraderPositionId`.

Pour verrouiller ça, créer un DTO `App\Dto\PublicTradeView` (readonly) construit depuis `Trade` : le template public ne reçoit que le DTO, jamais l'entité. Même approche pour les listes (§3.2).

## 4. Requêtes — `TradeRepository`

Nouvelles méthodes (ou extension du système `$filters` existant) :

- `findPublicTrades(User $owner, array $statuses, bool $currentMonthOnly, array $filters = [])` : trades `open`/`closed` selon toggles, règle du mois si demandée, filtres visiteur (période/confluences) écrêtés, tri par date décroissante.
- Règle « mois calendaire en cours » (`monthStart = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0)`) :
  - trades `closed` : `exitDate >= :monthStart` — cohérent avec `getCalendarData` qui raisonne déjà sur la date de sortie ;
  - trades `open` : **pas de borne**, ils restent visibles même entrés le mois précédent ;
  - en clair : `status = 'open' OR (status = 'closed' AND exitDate >= :monthStart)`.
- Les filtres visiteur réutilisent le système `$filters` existant (`start_date`, `end_date`, `confluences`) ; l'écrêtage au mois se fait dans le contrôleur/service avant de passer au repository.

## 5. Statistiques publiques en R-only

Les méthodes existantes (`getStatistics`, `getChartData`, `getDayStats`, `getCalendarData`, `getConfluenceStats`) acceptent déjà `$filters['user']`. Plan :

1. Ajouter un filtre `month_start` (réutilisable) dans `applyFilters`.
2. Créer un service `App\Service\PublicStatsProvider` qui appelle ces méthodes avec `['user' => $owner, 'month_start' => …]` plus les filtres visiteur écrêtés, puis **retire/convertit toute valeur en euro** : total P&L en R (somme des `finalRR`), courbe d'équité en R cumulés, calendrier en R, profit factor et drawdown calculés en R. Le win rate, les stats par jour et par confluence sont inchangés (pas monétaires).
3. Template `templates/traders/stats.html.twig` : **page complète** reprenant tous les blocs de `stats/index.html.twig` (courbe d'équité, win rate, profit factor, drawdown, calendrier P&L, stats par jour, par confluence, streaks) avec « R » comme unité partout, **y compris le formulaire de filtres période/confluences** (réutiliser le partial de filtres existant ou l'extraire en include partagé).

Si `getStatistics` mélange trop les métriques € et R, préférer des méthodes dédiées `getPublicStatistics()` plutôt que de post-traiter : plus sûr contre une fuite de champ monétaire.

### 5.1 Switch €/R sur la page stats perso

Ta propre page `/stats` gagne aussi un **switch €/R** (toggle en haut de page, paramètre GET `?unit=eur|rr`, défaut €) :

- en mode R, tous les blocs monétaires (P&L total, courbe d'équité, calendrier, drawdown, profit factor) basculent sur les métriques R ;
- c'est le même calcul que les stats publiques → factoriser : le `PublicStatsProvider` devient un `StatsProvider` avec un paramètre d'unité, utilisé par les deux pages (la version publique force `unit=rr` et applique en plus les toggles de visibilité) ;
- le template des blocs de stats est partagé entre `/stats` et l'onglet Statistiques public (includes Twig paramétrés par l'unité).

## 6. Navigation & accès

- `templates/base.html.twig` : ajout du lien « Traders » dans la nav (visible pour tout utilisateur connecté).
- Toutes les routes `/traders/*` sont derrière `ROLE_USER` (déjà couvert par l'access control global). Aucun accès anonyme.
- Chaque règle de visibilité est appliquée **côté serveur** dans le contrôleur/repository — jamais uniquement dans le template.

## 7. Corrections préalables sur l'existant

1. `StatsController` : passer `'user' => $this->getUser()` dans `$filters` (bug multi-utilisateur actuel).
2. Vérifier que `getChartData`, `getDayStats`, `getCalendarData`, `getConfluenceStats` respectent tous le filtre `user` via `applyFilters` (sinon corriger).
3. `ConfluenceRepository->findAll()` dans la page stats : les confluences/timeframes/setups semblent globales (pas de champ user) — OK, ce sont des référentiels partagés.

## 8. Ordre d'implémentation

Un commit par étape, directement sur `main` :

1. **Prérequis** : filtrage par user des stats existantes (§7).
2. Migration + champs `User` + helper + `findPublicProfiles` (§1).
3. Formulaire de paramètres de visibilité sur la page profil + lien « Voir mon profil public » (§2).
4. Switch €/R sur la page stats perso + factorisation `StatsProvider` (§5.1) — fait avant les pages publiques pour que celles-ci réutilisent le mode R.
5. DTO `PublicTradeView` + `findPublicTrades` (§3.3, §4).
6. `TraderController` : liste triable, profil public à onglets, détail trade + templates (§3).
7. Onglet Statistiques public branché sur le `StatsProvider` en mode R (§5).
8. Lien nav « Traders » + polish (badges « mois en cours », état vide « aucun trader visible ») (§6).
9. Tests (§9).

## 9. Tests

Tests fonctionnels (`WebTestCase`) prioritaires — c'est une feature de confidentialité, les tests d'accès sont le cœur :

- profil non partagé (`shareEnabled = false`) → `/traders/{id}` et `/traders/{id}/trades/{tid}` renvoient 404 ;
- toggle `shareOpenTrades = false` → un trade `open` n'apparaît pas dans la liste et son URL directe renvoie 404 (idem `closed`) ;
- trade `watching` → jamais visible, même tous toggles actifs ;
- `shareCurrentMonthOnly = true` → un trade **terminé** le mois précédent est invisible (liste + URL directe) et les stats ne l'incluent pas ;
- `shareCurrentMonthOnly = true` → un trade **ouvert** entré le mois précédent reste visible (trade « à cheval ») ;
- `shareCurrentMonthOnly = true` → forcer `?start_date=2026-01-01` dans l'URL du profil public ne fait pas fuiter de données hors du mois (écrêtage serveur) ;
- la page trade publique ne contient aucune valeur euro (asserter l'absence de `gainEuro`/`maxRiskEuro` dans la réponse) ;
- `displayName` requis pour activer `shareEnabled`, unicité insensible à la casse, immutabilité (un POST tentant de changer un pseudo existant est ignoré) ;
- un trader visible sans aucun trade partagé n'apparaît pas dans `/traders` ;
- le propriétaire voit toujours ses propres trades normalement via `/trades`, et la page `/stats` en mode € comme en mode R donne des chiffres cohérents.

## Hors périmètre (pour plus tard)

- Follow/favoris entre traders, commentaires, leaderboard avancé (au-delà du tri « Performance » de la liste).
- Protection des fichiers `public/uploads/` par un contrôleur de service de fichiers (limitation existante documentée en §Constat).
- Pagination de la liste des traders et des trades publics.