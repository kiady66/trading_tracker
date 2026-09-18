---
description: Commiter les fichiers modifiés durant la session avec un message conventionnel
allowed-tools: Bash(git add:*), Bash(git commit:*), Bash(git status:*), Bash(git diff:*), Bash(git log:*)
---

Commite les fichiers modifiés durant cette session.

## Étapes

1. Exécute `git status` et `git diff` pour voir les fichiers modifiés et comprendre les changements.
2. Identifie les fichiers modifiés durant la session en cours (ceux sur lesquels tu as travaillé). Ne stage que ces fichiers-là, individuellement avec `git add <fichier>` — jamais `git add -A` ni `git add .`.
3. **INTERDIT : ne jamais commiter le fichier `.env`** (ni `.env.local`, ni aucune variante contenant des secrets). S'il apparaît dans les modifications, l'exclure systématiquement du staging.
4. Rédige le message de commit puis commite.

## Format du message de commit

Le titre suit le format conventionnel :

```
feat: message
fix: message
chore: message
docs: message
refactor: message
test: message
```

Choisis le type selon la nature du changement. Le titre est court et à l'impératif.

## Description du commit (corps du message)

Le corps du commit doit obligatoirement contenir trois parties :

- **Problème** : quel était le problème ou le besoin.
- **Solution** : la solution choisie.
- **Pourquoi** : pourquoi cette solution a été retenue plutôt qu'une autre.

Exemple :

```
fix: corrige le calcul du winrate dans les stats

Problème : le winrate incluait les trades en break-even, faussant le pourcentage.
Solution : exclusion des trades break-even du dénominateur dans StatsService.
Pourquoi : c'est la convention standard en trading et cela évite de modifier le modèle de données.
```
