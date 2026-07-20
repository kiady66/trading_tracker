# Plan : Authentification via Firebase Auth

## Objectif

Basculer l'authentification de l'application sur **Firebase Authentication**, avec Google comme premier provider activé. L'architecture est posée en multi-provider : ajouter Apple, GitHub ou email-link ensuite ne demandera qu'une activation côté console Firebase et un bouton de plus, sans retoucher le backend.

À terme, le formulaire email/mot de passe disparaît (phase 2). Il est conservé pendant la phase 1, le temps de migrer le compte réel.

Le firewall `api` (token) n'est concerné à aucun moment.

**Facebook est hors périmètre** — décision prise en connaissance de la contrainte : obtenir l'*Advanced Access* à la permission `email` impose une App Review Meta avec vérification business (entité légale). Firebase ne dispense de rien ici, il se contente de relayer l'App ID et l'App Secret Meta. À rouvrir si le besoin se confirme, en gardant en tête que Facebook peut ne renvoyer **aucun email** — ce qui obligerait à rendre `User::$email` nullable, avec des répercussions dans tout le modèle.

## État d'avancement (20/07/2026)

**Phase 1 : code terminé.** Suite verte, 126 tests dont 20 nouveaux.

| Étape | État |
|---|---|
| Dépendances (`kreait/firebase-php`, SDK JS via importmap) | ✅ |
| `User.firebaseUid` + `password` nullable + migration `Version20260720124625` | ✅ appliquée en dev et test |
| `FirebaseTokenVerifier` + implémentation kreait + `FirebaseAuthFactory` | ✅ |
| `FirebaseAuthenticator` + `POST /auth/firebase` + `security.yaml` | ✅ |
| Contrôleur Stimulus (popup + repli redirection) + bouton sur login/register | ✅ |
| Onboarding du pseudo + subscriber de redirection | ✅ |
| Tests | ✅ |
| **Configuration console Firebase + `.env.local`** | ❌ **prérequis au premier essai** |
| **Migration du compte `id = 2`** | ❌ |
| **Phase 2** | ❌ |

### Décision prise en cours d'implémentation

L'onboarding obligatoire rend un `displayName` nul inatteignable. Cela **annule la conception d'origine** de [community-visibility.md](community-visibility.md), où le pseudo était optionnel (requis seulement pour activer le profil public). Arbitrage retenu : **pseudo obligatoire pour tous**.

Conséquences appliquées :

- `ProfileVisibilityType` : champ `displayName` désormais toujours en lecture seule, contrainte « Un pseudo est requis pour rendre votre profil visible » supprimée (devenue inatteignable) ;
- tests `testDisplayNameIsRequiredToEnableSharing` et `testDisplayNameUniquenessIsCaseInsensitive` supprimés, couverture équivalente déplacée dans `OnboardingControllerTest` ;
- les helpers de `CommunityVisibilityTest`, `ChatControllerTest` et `ChatAdminControllerTest` attribuent un pseudo, sans quoi tout utilisateur de test rebondit sur `/onboarding`.

### Écarts par rapport au plan initial

- **`Request::toArray()` abandonné** dans l'authenticator : il lève une exception convertie en 400 par le noyau, ce qui court-circuitait la réponse 401 JSON attendue par le front. Décodage explicite via `json_decode`.
- **`Kreait\Firebase\Contract\Auth` déclaré `lazy`** dans `services.yaml` : sans cela, une configuration Firebase absente ferait échouer le conteneur sur *toutes* les pages, y compris celles sans rapport avec l'authentification. En dev sans credentials, l'application fonctionne normalement et seul le login Google échoue.
- **Configuration client exposée via des globales Twig** (`firebase_api_key`, `firebase_auth_domain`, `firebase_project_id`) plutôt que passée par chaque contrôleur.

## Décisions actées

| Sujet | Décision |
|---|---|
| Architecture | Firebase Auth, multi-provider par construction ; Google seul activé au départ |
| Providers | Google. Facebook écarté (vérification business Meta). Apple/GitHub/email-link ajoutables sans toucher au backend |
| Bibliothèque backend | `kreait/firebase-php` — gère rotation et cache des clés publiques |
| Projets Firebase | **Un seul**, avec `localhost` et le domaine de prod en Authorized domains |
| Inscriptions | **Ouvertes à tous** : tout compte Google peut s'inscrire, rôle `ROLE_TRADER` |
| Flux de login | `signInWithPopup`, repli automatique sur `signInWithRedirect` si la popup est bloquée |
| Pseudo | **Écran d'onboarding** après le premier login, avec contrôle d'unicité |
| Sync email | Aucune. L'email local reste figé ; `firebaseUid` est la seule clé d'identité |
| Accès de secours | **Aucun mécanisme dédié** — risque assumé, dépannage par accès direct à la base (§ 10) |
| Migration `id = 2` | Transplantation des identifiants (§ 9) |
| Phase 2 | Enchaînée **immédiatement** après validation de la phase 1 |
| Comptes démo | Laissés en base, simplement inconnectables |
| Tests | Service de vérification mocké, aucun appel réseau |
| Production | Pas encore déployé — section secrets à compléter au moment du déploiement |

## État de la base (constaté le 20/07/2026)

| id | email | rôles | pseudo | trades |
|---|---|---|---|---|
| **2** | `admin@trading-tracker.com` | `ROLE_TRADER`, `ROLE_ADMIN` | — | **57** |
| 5 | demo.alex@example.com | — | AlexSwing | 39 |
| 7 | demo.wolf@example.com | — | MidnightWolf | 35 |
| 8 | demo.luna@example.com | — | LunaFX | 37 |
| 9 | demo.rmaster@example.com | — | R_Master | 31 |
| 10 | demo.ghost@example.com | — | GhostTrader | 17 |
| 11 | demo.nova@example.com | — | NovaTrader | 33 |

**Un seul compte réel : `id = 2`**, avec 57 trades. Son email est fictif et ne correspondra donc **jamais** à l'email Google.

Tables référençant `user` : `trade.user_id` et `chat_message.user_id`. `chat_message` ne contient **aucune** ligne pour `user_id = 2` — les trades sont la seule donnée réellement en jeu.

> ⚠️ **Le piège central.** Si Firebase est mis en service avant d'avoir traité `id = 2`, le premier login crée un **nouveau** compte : les 57 trades restent orphelins sur `id = 2`, et le nouveau compte n'a pas `ROLE_ADMIN`. Rien ne signale l'erreur — l'application s'ouvre simplement sur un compte vide. Voir « Migration du compte `id = 2` ».

## Architecture retenue

```
Navigateur                          Symfony
──────────                          ───────
signInWithPopup(GoogleProvider)
  → Firebase renvoie un ID token (JWT, 1h)
      │
      └── POST /auth/firebase { idToken }
                    │
                    ├── FirebaseAuthenticator vérifie la signature
                    │   (clés publiques Google, audience = project ID)
                    ├── résout ou crée le User local
                    └── ouvre une session Symfony classique
      ┌─────────────┘
  signOut() côté Firebase  ← on ne garde pas deux sessions
```

Point important : **l'ID token ne sert qu'une fois**, pour établir la session Symfony. Ensuite tout le reste de l'application fonctionne sur la session Symfony habituelle. Il n'y a donc ni refresh token à gérer, ni expiration à 1h à surveiller, ni appel Firebase sur les requêtes suivantes.

Corollaire à ne pas rater : Firebase persiste par défaut sa propre session dans IndexedDB. Si on la laisse vivre, on se retrouve avec **deux sources de vérité** qui peuvent diverger (déconnexion Symfony sans déconnexion Firebase). On appelle donc `signOut()` immédiatement après avoir obtenu le token, et on règle la persistance sur `inMemoryPersistence`.

## 1. Dépendances

### Backend

```bash
composer require kreait/firebase-php
```

Gère la récupération et le cache des clés publiques Google, ainsi que leur rotation. Alternative plus légère si l'on veut éviter le SDK complet : `firebase/php-jwt` + récupération manuelle du JWKS sur `https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com`. Le SDK reste recommandé — la rotation de clés est exactement le genre de détail qu'on oublie et qui casse en production six mois plus tard.

### Frontend

```bash
symfony console importmap:require firebase/app firebase/auth
```

Asset Mapper récupère les modules ESM depuis le CDN et les épingle dans `importmap.php`. Pas de npm, cohérent avec l'existant. À noter : `firebase/auth` pèse plusieurs centaines de Ko — il ne doit être chargé que sur la page de login, pas dans `app.js`.

## 2. Configuration Firebase

**Un seul projet Firebase**, partagé entre dev et production.

Console Firebase :

1. Créer le projet.
2. Authentication → Sign-in method → activer **Google**.
3. Vérifier le réglage « **One account per email address** ». S'il est actif, un utilisateur qui reviendra plus tard via un autre provider avec le même email déclenchera `auth/account-exists-with-different-credential`, à traiter côté JS le jour où un second provider sera ajouté.
4. Authentication → Settings → Authorized domains : ajouter `localhost` (dev, `make run` sert sur `localhost:8001`). Le domaine de production s'ajoutera au moment du déploiement.
5. Project settings → Service accounts → générer une clé privée (JSON) pour le backend.

> **Conséquence du projet unique** : un login en local crée un vrai compte dans le même pool Firebase que la production. Les comptes Firebase et les `User` en base sont deux choses distinctes — supprimer une ligne de la table `user` ne supprime pas le compte Firebase correspondant. Pendant les essais, penser à nettoyer aussi côté console Firebase (Authentication → Users), sinon on se retrouve avec des comptes fantômes qui compliquent le diagnostic. C'est le prix de la simplicité choisie ici ; passer à deux projets reste possible plus tard, c'est un simple changement de variables d'environnement.

### Variables d'environnement

La config **client** (`apiKey`, `authDomain`, `projectId`) est publique par nature : elle est visible dans le JS de toute application Firebase, ce n'est pas un secret. Elle peut vivre dans `.env` versionné.

La **clé de service** est un vrai secret. Elle est stockée hors du dépôt, avec seulement son chemin en variable d'environnement.

`.env` (versionné, valeurs de dev non sensibles) :

```dotenv
FIREBASE_PROJECT_ID=
FIREBASE_API_KEY=
FIREBASE_AUTH_DOMAIN=
FIREBASE_CREDENTIALS=
```

`.env.local` (jamais commité) : `FIREBASE_CREDENTIALS=/chemin/absolu/hors-depot/firebase-service-account.json`

> Rappel projet : `.env.test` a déjà dû être retiré du suivi git. Vérifier que le JSON de service n'atterrit **pas** dans le dépôt, et ajouter un motif dans `.gitignore` par précaution (`*firebase*.json`).

**À traiter au déploiement** (l'application n'est pas encore en production) : un `Dockerfile` et `league/flysystem-async-aws-s3` sont déjà présents, ce qui suggère une cible conteneurisée. Si l'hébergeur a un système de fichiers éphémère (Railway par exemple), le JSON de service account ne peut pas être un fichier : il faudra le passer en variable d'environnement encodée en base64 et le décoder au démarrage. Le code qui charge les credentials doit donc accepter **soit un chemin, soit un contenu base64** — l'écrire ainsi dès la phase 1 évite d'y revenir.

## 3. Modèle de données

Sur `App\Entity\User` :

| Champ | Type | Rôle |
|---|---|---|
| `firebaseUid` | `string(128)`, unique, **nullable** | UID Firebase, stable et **indépendant du provider**. Clé de rattachement prioritaire |

C'est le gain concret de Firebase sur une intégration OAuth par provider : un seul champ d'identité, quel que soit le nombre de providers ajoutés plus tard. Pas de `googleId`, `appleId`, `githubId` à accumuler.

Et rendre `password` nullable (un compte Firebase n'en a pas) :

```php
#[ORM\Column(nullable: true)]
private ?string $password = null;

public function getPassword(): ?string { return $this->password; }
public function setPassword(?string $password): self { /* ... */ }
```

Helper pour l'UI du profil :

```php
public function isFirebaseAccount(): bool
{
    return $this->firebaseUid !== null;
}
```

Migration : `symfony console doctrine:migrations:diff` puis `migrate`. `password` passe de NOT NULL à NULL — non destructif, aucune donnée perdue.

> Rappel : fixtures idempotentes, chargées en `--append` uniquement (le dev tourne sur des trades réels).

## 4. Frontend — `assets/controllers/firebase_auth_controller.js`

Un contrôleur Stimulus, cohérent avec les 5 contrôleurs existants. Valeurs passées depuis Twig (`data-firebase-auth-api-key-value`, etc.) pour ne pas figer la config dans le JS.

Comportement au clic sur « Continuer avec Google » :

1. `setPersistence(auth, inMemoryPersistence)` — pas de session Firebase résiduelle.
2. `signInWithPopup(auth, new GoogleAuthProvider())`.
3. `await result.user.getIdToken()`.
4. `signOut(auth)` — la session Firebase a fait son travail.
5. POST du token vers `/auth/firebase`, puis redirection vers l'URL renvoyée.

### Repli sur redirection

Si `signInWithPopup` échoue avec `auth/popup-blocked` (ou `auth/operation-not-supported-in-this-environment`), on rejoue l'opération avec `signInWithRedirect`. Ce repli a une conséquence structurelle : **la page est rechargée**, le flux ne peut donc pas se poursuivre dans le même contexte JS.

Le contrôleur doit donc, à son `connect()`, appeler `getRedirectResult(auth)` et — si un résultat est présent — reprendre le flux aux étapes 3 à 5. Concrètement, les étapes 3-5 sont extraites dans une méthode réutilisée par les deux chemins.

Deux pièges avec ce mode :

- `inMemoryPersistence` est **incompatible** avec `signInWithRedirect` : le résultat doit survivre au rechargement de page. Sur le chemin redirection, il faut donc utiliser `browserSessionPersistence`, puis appeler `signOut()` dès le token récupéré. La persistance n'est choisie qu'au moment de savoir quel chemin on emprunte.
- Sur le retour de redirection, l'utilisateur atterrit sur `/login` avec un état intermédiaire : prévoir un indicateur de chargement, sinon la page semble figée le temps du POST.

Gestion d'erreurs restante : `auth/popup-closed-by-user` (silencieux, l'utilisateur a annulé — surtout ne pas afficher d'erreur), `auth/network-request-failed` (message invitant à réessayer), `auth/cancelled-popup-request` (silencieux, double clic).

> Les popups sont bloquées si `signInWithPopup` n'est pas déclenché directement par un clic utilisateur. Ne pas l'appeler depuis un callback asynchrone — un `await` avant l'appel suffit à perdre le geste utilisateur et à déclencher inutilement le repli.

### Turbo

L'application utilise Turbo. La redirection finale doit passer par `window.location.assign()` plutôt qu'un rendu Turbo : la session vient de changer, on veut un chargement complet pour que toute la page (navigation, badges) reflète le nouvel état d'authentification.

## 5. Backend — contrôleur et authenticator

### `src/Controller/FirebaseAuthController.php`

```php
#[Route('/auth/firebase', name: 'app_auth_firebase', methods: ['POST'])]
public function check(): void
{
    // Interceptée par FirebaseAuthenticator — jamais exécutée
}
```

### `src/Security/FirebaseAuthenticator.php`

Un `AbstractAuthenticator` classique.

- `supports()` : route `app_auth_firebase` et méthode POST.
- `authenticate()` :
  1. Extraire `idToken` du corps JSON. Absent ou malformé → `CustomUserMessageAuthenticationException`.
  2. `$this->auth->verifyIdToken($idToken)` (kreait). Toute exception de vérification → échec d'authentification. **Ne jamais décoder le JWT sans vérifier la signature** : c'est l'unique barrière, un token non vérifié est du texte fourni par le client.
  3. Vérifier le claim `email_verified === true`. Google le renseigne à `true` pour un compte Google standard ; le contrôle protège des providers futurs plus laxistes.
  4. Résolution via `UserBadge`, dans cet ordre :
     - par `firebaseUid` → retour d'un utilisateur connu ;
     - sinon par `email` → **rattachement** : `setFirebaseUid(...)` + flush ;
     - sinon **création** : `setEmail()`, `setFirebaseUid()`, `setRoles([User::ROLE_TRADER])`, `password` à `null`, `displayName` à `null`.
- `onAuthenticationSuccess()` : `JsonResponse` avec l'URL cible — `app_onboarding` si `displayName` est `null`, sinon la cible sauvegardée (`TargetPathTrait::getTargetPath()`) ou `app_trade_index`. C'est le JS qui redirige.
- `onAuthenticationFailure()` : `JsonResponse` 401 avec un message générique.

### Inscriptions ouvertes

Tout compte Google peut créer un compte, avec `ROLE_TRADER`. Aucune liste blanche.

Conséquence à garder en tête : dès la mise en ligne, n'importe qui peut créer un compte et accéder à l'application. Le cloisonnement des données est en place — `TradeController` filtre par `user` et vérifie l'ownership, `StatsController` passe bien `['user' => $this->getUser()]` au `StatsProvider` (`StatsController.php:29`). Le risque est donc un remplissage de la base par des comptes indésirables, pas une fuite de tes trades.

Si cela devient un problème, une liste blanche par variable d'environnement se greffe en une dizaine de lignes dans l'authenticator, sans rien changer au reste.

### Email : pas de synchronisation

Si l'email du compte Google change plus tard, l'email en base **n'est pas mis à jour**. `firebaseUid` étant la clé de rattachement prioritaire, l'utilisateur continue de se connecter normalement ; seul l'email affiché devient obsolète. C'est sans conséquence fonctionnelle — il n'est utilisé nulle part pour de l'envoi (`MAILER_DSN=null://null`).

> `displayName` reste `null` à la création. Il est unique et **figé après création** (cf. [community-visibility.md](community-visibility.md)) : le pré-remplir avec le nom Google garantit des collisions sur les homonymes, de façon irréversible. C'est l'écran d'onboarding qui le collecte.

### CSRF

Pas de protection CSRF nécessaire sur `/auth/firebase` : la requête n'est pas authentifiée par un cookie mais par un token que l'attaquant ne peut pas obtenir. Un site tiers peut déclencher le POST, il ne peut pas fabriquer d'ID token valide.

## 6. Sécurité — `config/packages/security.yaml`

Sur le firewall `main` uniquement :

```yaml
main:
    lazy: true
    provider: app_user_provider
    custom_authenticators:
        - App\Security\FirebaseAuthenticator
    form_login: # inchangé en phase 1
    logout:     # inchangé
```

`access_control` — **avant** la règle catch-all `^/` :

```yaml
- { path: ^/auth/firebase$, roles: PUBLIC_ACCESS }
```

## 7. Onboarding du pseudo

Après le premier login, l'utilisateur est envoyé sur `/onboarding` pour choisir son pseudo.

`OnboardingController` : formulaire à un champ, contraintes identiques à celles de la visibilité communautaire — 3 à 30 caractères, `[a-zA-Z0-9_-]`, unique **insensible à la casse**. Message d'erreur explicite si le pseudo est pris. Rappeler dans l'interface qu'il est **définitif**, puisqu'il sert d'URL publique `/traders/{pseudo}`.

Une fois validé, redirection vers la cible sauvegardée ou `app_trade_index`.

### Le piège de la redirection forcée

Pour que l'onboarding soit réellement incontournable, il faut un `EventSubscriber` sur `kernel.request` qui redirige tout utilisateur authentifié dont le `displayName` est `null`. Écrit naïvement, **il boucle à l'infini** : la redirection vers `/onboarding` déclenche à nouveau le subscriber.

Il faut donc exclure explicitement :

- la route `app_onboarding` elle-même (et sa soumission) ;
- `app_logout` — sinon un utilisateur sans pseudo ne peut littéralement plus se déconnecter ;
- `app_auth_firebase` ;
- les requêtes non principales (`$event->isMainRequest()`), le profiler et les assets.

Autre décision : ce blocage doit-il s'appliquer aux routes `/api/` ? Non — le firewall `api` est distinct et sans session ; le subscriber doit ignorer les requêtes qui en relèvent, sinon un client API se prend une redirection HTML incompréhensible.

> **Effet sur la migration** : `id = 2` n'a pas de `displayName`. Après la transplantation des identifiants, il passera donc par l'onboarding à sa première connexion. C'est le comportement voulu, mais autant le savoir pour ne pas le prendre pour un bug. Les 6 comptes démo en ont déjà un, ils ne sont pas concernés.

## 8. Interface

Sur `templates/security/login.html.twig` et `register.html.twig` : bouton « Continuer avec Google » au-dessus du formulaire, séparateur « ou », respect des [Google branding guidelines](https://developers.google.com/identity/branding-guidelines). Le contrôleur Stimulus n'est monté que sur ces pages.

Sur la page profil (`ProfileController`) : afficher « Compte lié à Google » et **masquer le formulaire de changement de mot de passe** si `password` est `null` — sinon l'utilisateur voit un champ « mot de passe actuel » impossible à remplir.

## 9. Migration du compte `id = 2`

Le vrai email n'est pas connu du code avant le premier login. Quatre approches ont été examinées :

| Option | Principe | Verdict |
|---|---|---|
| **A. Transplanter les identifiants (retenue)** | Se connecter → un compte temporaire est créé. Relever son `email` + `firebase_uid`, le supprimer, reporter les valeurs sur `id = 2` | **Retenue.** Les identifiants sont des faits *observés*, pas devinés |
| B. Pré-renseigner l'email | `UPDATE` avant tout login, le rattachement automatique fait le reste | Repose sur une saisie exacte de l'email — voir ci-dessous |
| C. Pré-renseigner le `firebase_uid` | L'UID n'existe qu'après un premier login | Impossible → écarté |
| D. Mode « adoption » dans l'authenticator | Rattacher l'unique compte `ROLE_ADMIN` si rien ne correspond | Code de sécurité jetable pour un cas unique → écarté |

**Pourquoi A plutôt que B.** B suppose de connaître l'adresse exacte que Google renverra. Un alias, un `@googlemail.com`, un point dans la partie locale, une faute de frappe — et le rattachement échoue silencieusement, produisant le doublon qu'on voulait éviter. A n'a pas ce mode d'échec.

A préserve aussi ce qu'un déplacement des données perdrait : `ROLE_ADMIN`, `createdAt`, et surtout l'**`apiToken`**, unique en base et potentiellement déjà utilisé par un client de l'API (cf. `docs/API.md`). Déplacer les 57 trades vers un nouveau compte changerait ce token et casserait l'intégration.

### Procédure

À exécuter après la phase 1 de code, avec un `pg_dump` préalable.

```sql
-- 1. Après le login, relever les valeurs du compte fraîchement créé
SELECT id, email, firebase_uid FROM "user" WHERE firebase_uid IS NOT NULL;

-- 2. Supprimer ce compte temporaire — email est UNIQUE (uniq_8d93d649e7927c74),
--    il bloquerait l'UPDATE. Il est vierge : aucun trade, aucun message.
DELETE FROM "user" WHERE id = <id_temporaire>;

-- 3. Reporter les identifiants sur le compte réel
UPDATE "user" SET email = '<email_relevé>', firebase_uid = '<uid_relevé>' WHERE id = 2;
```

**Se déconnecter avant l'étape 2**, puis se reconnecter à la fin : la session en cours pointe sur le compte temporaire, le supprimer sous ses propres pieds mène à un état incohérent. Entre le login et la suppression, ne rien faire dans l'application — tout trade créé atterrirait sur le compte temporaire et disparaîtrait avec lui.

Vérification : `SELECT id, email, firebase_uid FROM "user" ORDER BY id;` ne doit montrer aucun compte surnuméraire, et les 57 trades doivent apparaître dans l'interface.

### Effets de bord

- `tests/Controller/Api/TradeApiControllerTest.php:16` fige `admin@trading-tracker.com` dans `USER_EMAIL`. Vérifier si le test crée sa fixture ou dépend de la base ; dans le second cas, le rendre autonome.
- `id = 2` n'a pas de `displayName` : son profil public est inactif, ce qui est cohérent.

## 10. Points de vigilance

- **Formulaire de login en phase 1** : un compte créé via Firebase a `password = null` et ne doit pas pouvoir se connecter par formulaire. Symfony refuse de vérifier un hash `null`, mais il faut confirmer qu'aucune 500 n'est levée — à couvrir par un test.
- **Firewall `api`** : inchangé. Un compte Firebase obtient son `apiToken` via `User::__construct()`, l'API continue de fonctionner sans code supplémentaire.
- **Tests et réseau** : la vérification d'ID token appelle les serveurs Google pour récupérer les clés publiques. En test, le vérificateur doit être mocké — sinon la suite devient dépendante du réseau.
- **`eraseCredentials()`** : rien à changer en phase 1, ne touche que `plainPassword`.

### Absence d'accès de secours — risque assumé

Aucun mécanisme de secours n'est prévu : après la phase 2, **la seule façon d'entrer dans l'application est un login Google réussi**. Trois scénarios te mettent dehors sans préavis :

1. perte d'accès au compte Google ;
2. panne prolongée de Firebase Auth ;
3. erreur de configuration après un déploiement (domaine absent des Authorized domains, clé de service expirée ou mal montée) — le scénario de loin le plus probable.

Le risque est acceptable parce que tu gardes un accès direct à la base. La procédure de dépannage, à connaître **avant** d'en avoir besoin :

```sql
-- Réactiver temporairement un accès par mot de passe sur le compte admin
UPDATE "user" SET password = '<hash bcrypt généré hors ligne>' WHERE id = 2;
```

Puis remettre `form_login` et `password_hashers` dans `security.yaml`, le temps de corriger. Cela suppose que la colonne `password` existe encore — or la phase 2 la supprime. **Après le `DROP COLUMN`, le dépannage impose d'abord de recréer la colonne** (`ALTER TABLE "user" ADD COLUMN password VARCHAR(255) DEFAULT NULL`).

C'est le vrai coût de la décision, et il justifie de ne pas se précipiter sur le `DROP COLUMN` : le reste de la phase 2 (retrait de `form_login`, du formulaire, de l'inscription) peut être fait immédiatement, la suppression de la colonne peut attendre quelques semaines sans rien coûter. Une colonne inutilisée ne gêne personne ; se retrouver dehors, si.

## 11. Tests

Le service `Auth` de kreait est mocké partout : **aucun appel réseau dans la suite**. Concrètement, cela impose de ne pas typer l'authenticator directement sur la classe concrète de kreait, mais sur une petite interface applicative (`FirebaseTokenVerifier`) avec une implémentation kreait en production et un double en test. Sans cette indirection, le mock est pénible à écrire et la suite finit par dépendre du réseau.

Contrepartie honnête : la vérification cryptographique réelle n'est jamais exercée par les tests. C'est acceptable — c'est du code de bibliothèque, pas du code maison — mais cela veut dire qu'une erreur de câblage (mauvais project ID, credentials non chargés) ne sera visible qu'au premier essai manuel. Ce premier essai fait donc partie de la définition de « terminé ».

- `tests/Security/FirebaseAuthenticatorTest.php` (unitaire, vérificateur mocké) :
  - token invalide / signature refusée → échec, aucun `User` persisté ;
  - `email_verified` à `false` → échec ;
  - `firebaseUid` connu → utilisateur existant retourné, aucune création ;
  - email connu sans `firebaseUid` → rattachement, `firebaseUid` renseigné ;
  - inconnu → création avec `ROLE_TRADER`, `password` et `displayName` à `null` ;
  - email du token différent de celui en base → l'email en base **n'est pas** modifié.
- `tests/EventSubscriber/OnboardingSubscriberTest.php` :
  - utilisateur sans pseudo sur une route quelconque → redirection vers `/onboarding` ;
  - sur `/onboarding` et sur `app_logout` → **aucune** redirection (non-régression sur la boucle infinie) ;
  - requête relevant du firewall `api` → aucune redirection.
- Fonctionnel : `POST /auth/firebase` sans token → 401 propre.
- Fonctionnel : login par formulaire sur un compte sans mot de passe → échec propre, pas de 500.
- Fonctionnel : `/onboarding` refuse un pseudo déjà pris, y compris avec une casse différente.

## 12. Découpage en commits — phase 1

Un commit par étape, directement sur `main` (workflow habituel du projet) :

1. `chore: ajoute kreait/firebase-php et le SDK firebase côté importmap`
2. `feat(user): champ firebaseUid et password nullable (+ migration)`
3. `feat(auth): authenticator Firebase et endpoint /auth/firebase`
4. `feat(auth): configuration security.yaml pour Firebase`
5. `feat(ui): contrôleur Stimulus et bouton "Continuer avec Google"`
6. `feat(onboarding): choix du pseudo au premier login`
7. `test: couverture de l'authenticator Firebase et du subscriber d'onboarding`

Puis, **hors commit**, la bascule opérationnelle : la transplantation des identifiants décrite en section 9.

Ne pas enchaîner sur la phase 2 tant que la reconnexion sur `id = 2` avec ses 57 trades n'est pas vérifiée.

## 13. Reste à faire

Le code est écrit ; tout ce qui suit est manuel et n'a pas encore été fait.

### A. Configuration Firebase — bloquant pour tout essai

1. Créer le projet dans la console Firebase.
2. Authentication → Sign-in method → activer **Google**.
3. Authentication → Settings → Authorized domains → ajouter `localhost`.
4. Project settings → Service accounts → générer une clé privée JSON, l'enregistrer **hors du dépôt**.
5. Renseigner `.env.local` :

```dotenv
FIREBASE_PROJECT_ID=<project-id>
FIREBASE_API_KEY=<clé web>
FIREBASE_AUTH_DOMAIN=<project-id>.firebaseapp.com
FIREBASE_CREDENTIALS=/chemin/absolu/hors-depot/service-account.json
```

Tant que `FIREBASE_CREDENTIALS` est vide, le bouton s'affiche mais l'échange de token échoue — c'est le comportement attendu, pas un bug.

### B. Premier essai manuel

Aucun test ne couvre la vérification cryptographique réelle (le vérificateur est mocké partout). Une erreur de câblage — mauvais project ID, credentials mal chargés, domaine non autorisé — ne se verra donc qu'ici. **Cet essai fait partie de la définition de « terminé ».**

À vérifier : `make run`, `/login`, bouton Google, puis atterrissage sur `/onboarding` avec création d'un `User` portant `firebase_uid` et `password` à `null`.

### C. Migration du compte `id = 2`

Procédure complète en section 9. Rappel du principe : se connecter, relever `email` + `firebase_uid` du compte temporaire, le supprimer, reporter les valeurs sur `id = 2`. `pg_dump` au préalable.

Point à ne pas oublier : `id = 2` n'a pas de pseudo, il passera donc par l'onboarding à sa première connexion. C'est voulu.

### D. Nettoyage des comptes Firebase d'essai

Les comptes créés pendant les tests manuels subsistent dans la console Firebase même après suppression des lignes en base — les deux référentiels sont indépendants. À purger dans Authentication → Users, sinon des comptes fantômes brouilleront le diagnostic (projet unique partagé dev/prod).

### E. Correctifs différés

- `tests/Controller/Api/TradeApiControllerTest.php:16` fige encore `admin@trading-tracker.com`. À rendre autonome (le test doit créer sa fixture) **avant** de changer l'e-mail de `id = 2`, sinon il cassera.
- `src/Command/CreateAdminCommand.php` crée un compte avec mot de passe. À revoir en phase 2.

### F. Phase 2

Détaillée plus bas. Rappel : le `DROP COLUMN password` supprime l'unique voie de dépannage (§ 10) — le reste de la phase 2 peut être fait d'une traite, cette suppression peut attendre sans rien coûter.

### G. Déploiement (non planifié)

L'application n'est pas encore en production. Au moment du déploiement : ajouter le domaine aux Authorized domains, et fournir les credentials en base64 si l'hébergeur a un système de fichiers éphémère — `FirebaseAuthFactory` accepte déjà les deux formes.

---

# Phase 2 — Suppression de l'authentification par mot de passe

Enchaînée **immédiatement** après validation de la phase 1 — c'est-à-dire après une reconnexion réussie sur `id = 2` retrouvant ses 57 trades. Tant que ce n'est pas vérifié, le formulaire reste le seul moyen d'entrer dans l'application : le supprimer trop tôt, c'est se verrouiller dehors.

**Une réserve sur le `DROP COLUMN`.** Le reste de la phase 2 est sans danger et peut se faire d'une traite. La suppression de la colonne `password`, elle, est irréversible et supprime ton unique voie de dépannage (§ 10). Comme une colonne inutilisée ne coûte rien, la garder quelques semaines pendant que l'authentification Firebase fait ses preuves est un compromis à très faible prix. Le reste de cette section est écrit en supposant que tu la supprimes malgré tout — c'est ton appel.

### Code à retirer

| Emplacement | Action |
|---|---|
| `security.yaml` | Supprimer `form_login` et `password_hashers`. Garder `logout` et `custom_authenticators` |
| `access_control` | Supprimer `^/login$` et `^/register$` |
| `SecurityController::login()` | Supprimer le traitement, mais **garder une route** `app_login` affichant la page « Continuer avec Google » : `logout.target` et les redirections d'accès refusé y pointent |
| `SecurityController::register()` | Supprimer (l'inscription passe par Firebase) |
| `src/Form/RegistrationFormType.php` | Supprimer |
| `templates/security/register.html.twig` | Supprimer |
| `templates/security/login.html.twig` | Réduire au seul bouton Google |
| `ProfileController` | Supprimer le changement de mot de passe |
| `src/Command/CreateAdminCommand.php` | À revoir : créer un `User` avec le seul email suffit, le rattachement Firebase se fera au premier login |

### Modèle de données

Retirer `password` et `plainPassword` de `User`, retirer `PasswordAuthenticatedUserInterface` de la déclaration de classe et les accesseurs associés. `eraseCredentials()` devient un corps vide (`UserInterface` l'exige toujours).

Migration : `DROP COLUMN password`. **Destructif et irréversible** — `pg_dump` avant, et uniquement après validation de la phase 1.

### Comptes démo

Les 6 comptes démo sont **conservés en base et deviennent simplement inconnectables** : plus de mot de passe, pas de `firebaseUid`, donc aucun chemin d'authentification. Ils continuent d'alimenter `/traders` en lecture seule, leurs trades et leurs pseudos restent intacts.

Rien à faire, donc — mais le noter explicitement évite qu'un futur nettoyage de base les prenne pour des comptes orphelins à supprimer.

### Tests

Les tests se connectant par formulaire basculent sur `loginUser()` du `WebTestCase`, qui contourne l'authenticator. À vérifier en priorité : tout test fonctionnel postant sur `/login`. `TradeApiControllerTest` passe par le firewall `api`, a priori non impacté.
