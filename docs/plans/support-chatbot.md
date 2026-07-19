# Plan : Chatbot de contact (support utilisateur → admin)

## Objectif

Permettre à chaque utilisateur connecté de contacter l'administrateur du site via un petit widget de chat en bas à droite, présent sur toutes les pages :

- le widget est **réduit par défaut** (une simple barre/bulle) ; un clic l'agrandit en petite fenêtre de chat, et le bouton **(−)** dans l'en-tête le réduit à nouveau ;
- la fenêtre affiche **l'historique des messages** entre l'utilisateur et l'admin ;
- si l'utilisateur n'a **encore aucun message**, un **message d'accueil** s'affiche : on le remercie d'utiliser le site, on l'invite à demander de l'aide en cas de besoin, et à proposer des idées d'amélioration ;
- côté admin, une page permet de voir toutes les conversations et d'y répondre.

Ce n'est **pas** un bot automatique : c'est une messagerie simple entre l'utilisateur et l'admin (toi). Le message d'accueil est le seul contenu « automatique ».

## Décisions validées avec l'utilisateur

| Sujet | Décision |
|---|---|
| Message d'accueil | **Virtuel** (rendu dans le template quand l'historique est vide), pas stocké en base — évite de créer une ligne par utilisateur et reste modifiable en un seul endroit |
| Temps réel | **Polling léger** (fetch toutes les ~15 s quand la fenêtre est ouverte) — pas de Mercure/WebSocket pour rester simple ; améliorable plus tard |
| État réduit/ouvert | Persisté en `localStorage` pour survivre à la navigation Turbo |
| Destinataire | Un seul « support » : tous les messages utilisateur vont à l'admin ; pas de chat entre utilisateurs |
| Visibilité | **Connectés seulement** : widget affiché uniquement si `is_granted('ROLE_USER')`, rien pour les visiteurs anonymes ; masqué pour l'admin lui-même (il a sa page dédiée) |
| Notification admin | **Badge seul** dans la nav admin (pas d'e-mail en v1) |
| Côté admin | **Page dédiée** `/admin/chat` (liste des conversations + fil + réponse), pas de widget pour l'admin |
| Badge widget réduit | Mis à jour **au chargement de page** uniquement — pas de polling quand la fenêtre est réduite |
| Notification utilisateur | **Badge seul** également côté utilisateur : il découvre la réponse à sa prochaine visite (pas d'e-mail en v1) |
| Ton | **Tutoiement** dans le message d'accueil et les libellés du widget |
| Mobile | Réduite : même bulle en bas à droite. Ouverte : **quasi plein écran** (toute la largeur, ~toute la hauteur) via media query |
| Identité côté admin | Les conversations sont identifiées par **e-mail** (le pseudo `displayName` du plan communautaire pourra s'y substituer plus tard) |
| Widget réduit (visuel) | **Barre « 💬 Contact »** style en-tête de fenêtre avec badge non-lus, cliquable pour agrandir (pas de bulle ronde) |
| Nom affiché | **« Support »** comme titre de la fenêtre et signature des réponses admin |
| Horodatage | **Absolu** (« 19/07 14:32 ») sous chaque message |
| Saisie | **Entrée envoie**, Maj+Entrée insère un saut de ligne ; bouton « Envoyer » en plus |
| Longueur max | **2000 caractères** par message (validé côté serveur, `maxlength` côté client) |
| Historique | **50 derniers messages** chargés, pas de « voir plus » en v1 |
| Git | Commits **directement sur `main`**, un commit par étape du plan |
| Tests | **Tests fonctionnels basiques** : accès refusé si anonyme, envoi d'un message, marquage lu, accès admin réservé à `ROLE_ADMIN` |

## 1. Modèle de données — entité `ChatMessage`

Une seule entité suffit (la « conversation » est implicite : un fil par utilisateur) :

| Champ | Type | Rôle |
|---|---|---|
| `id` | int | PK |
| `user` | ManyToOne `User`, non null | Propriétaire du fil (l'utilisateur côté client) |
| `fromAdmin` | `bool`, défaut `false` | `true` si le message est une réponse de l'admin |
| `content` | `text` | Contenu du message |
| `createdAt` | `datetime_immutable` | Horodatage |
| `readAt` | `datetime_immutable`, nullable | Marqué quand le destinataire a vu le message (badge « non lu » des deux côtés) |

- `ChatMessageRepository` :
  - `findByUser(User $user)` : historique d'un fil, trié par `createdAt`, limité aux **50 derniers** messages ;
  - `countUnreadForUser(User $user)` : messages `fromAdmin = true` non lus (badge côté widget) ;
  - `findConversations()` : pour l'admin — derniers messages groupés par utilisateur avec compteur de non-lus (requête agrégée pour éviter le N+1) ;
  - `markAsRead(User $user, bool $fromAdmin)` : `UPDATE ... SET readAt = NOW()` sur les messages non lus du sens concerné.
- Migration Doctrine via `doctrine:migrations:diff`.

## 2. Backend — `ChatController` (côté utilisateur)

Routes JSON sous `/chat` (préfixe `app_chat_`), réservées à `ROLE_USER` :

- `GET /chat/messages` : historique du fil de l'utilisateur courant + marque les messages admin comme lus. Retourne aussi le compteur non-lus (pour le badge quand la fenêtre est fermée, le front n'appelle que ça).
- `POST /chat/messages` : crée un message (`fromAdmin = false`). Validation : contenu non vide, longueur max (ex. 2000 caractères). Protection CSRF (token dans le formulaire ou header, comme le reste du site).
- Réponses en JSON simple `{ messages: [...], unreadCount: n }` — pas besoin de serializer complet, un mapping manuel dans le contrôleur suffit.

## 3. Backend — côté admin

Nouveau `Admin/ChatAdminController` (ou route dans un contrôleur admin existant), réservé à `ROLE_ADMIN` :

- `GET /admin/chat` : liste des conversations — une ligne par utilisateur ayant au moins un message : e-mail, extrait du dernier message, date, badge non-lus. Triée par dernier message décroissant.
- `GET /admin/chat/{user}` : fil complet avec un utilisateur + formulaire de réponse ; marque les messages utilisateur comme lus.
- `POST /admin/chat/{user}` : crée la réponse (`fromAdmin = true`).
- Lien « Chat » dans la nav, visible seulement pour l'admin, avec badge du total de non-lus.

## 4. Frontend — le widget

### 4.1 Template

- `templates/chat/_widget.html.twig`, inclus dans `base.html.twig` en fin de `<body>` (après `{% block body %}`), entouré de `{% if is_granted('ROLE_USER') and not app.user.isAdmin %}`.
- Structure :
  - **état réduit** : une barre/bulle fixe en bas à droite (« 💬 Contact » + badge non-lus) ;
  - **état ouvert** : fenêtre ~320×420 px, `position: fixed; bottom: 0; right: 1rem;` — sur mobile (media query ~`max-width: 640px`), la fenêtre ouverte passe en quasi plein écran (toute la largeur, ~toute la hauteur) — avec :
    - en-tête (titre « Support » + bouton **−** pour réduire),
    - zone scrollable des messages (bulles alignées à droite pour l'utilisateur, à gauche pour « Support », horodatage absolu « 19/07 14:32 »),
    - champ de saisie + bouton envoyer.
- **Message d'accueil** (affiché uniquement si le fil est vide, stylé comme un message admin) :

  > Merci d'utiliser Trading Tracker ! 🙏
  > Si tu as besoin d'aide, n'hésite pas à m'écrire ici, je te répondrai dès que possible.
  > Et si tu as des idées d'amélioration pour le site, je suis preneur !

### 4.2 Contrôleur Stimulus `chat_controller.js`

Dans `assets/controllers/` (Asset Mapper, pas de build) :

- `toggle()` : réduit/agrandit la fenêtre, persiste l'état dans `localStorage` (`data-turbo-permanent` sur le conteneur pour éviter le « flash » entre pages Turbo) ;
- `connect()` : restaure l'état, charge l'historique si ouvert, démarre le polling (15 s) **uniquement fenêtre ouverte** ; `disconnect()` : stoppe le polling ;
- `send(event)` : `fetch POST`, ajoute le message localement (optimiste), vide le champ, scroll en bas ; **Entrée envoie, Maj+Entrée insère un saut de ligne** ;
- `refresh()` : `fetch GET`, re-render la liste, met à jour le badge, scroll en bas seulement si l'utilisateur était déjà en bas ;
- fenêtre fermée : un seul appel léger au `connect()` pour récupérer `unreadCount` (badge).

## 5. Étapes d'implémentation

1. Entité `ChatMessage` + repository + migration.
2. `ChatController` (GET/POST JSON) + tests basiques (accès refusé si anonyme, création, marquage lu).
3. Widget Twig + contrôleur Stimulus (état réduit/ouvert, envoi, message d'accueil).
4. Polling + badge non-lus côté widget.
5. Pages admin (liste des conversations + fil + réponse) + badge dans la nav.
6. Vérification manuelle : deux navigateurs (un utilisateur, un admin), échange de messages, badges, persistance de l'état réduit à travers la navigation.

## Hors périmètre (améliorations futures)

- Temps réel via Mercure (Symfony l'intègre bien) à la place du polling.
- Notification e-mail à l'admin quand un message arrive.
- Pièces jointes / captures d'écran dans le chat.
- Suppression/édition de messages.