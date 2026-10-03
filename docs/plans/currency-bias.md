# Page Biais des devises (`/biais`)

> Statut : livré le 2026-10-03. Ce document garde le pourquoi et la liste des
> suites possibles.

## Besoin

Poser un biais fondamental par devise (USD, EUR, GBP, CAD, AUD, NZD, CHF, JPY)
avec des notes, et en déduire les paires « divergentes » à surveiller : une
devise hawkish contre une devise dovish. L'ordre de cette liste est une
priorité personnelle, maintenue à la main par glisser-déposer.

## Choix

- **Une seule source de biais** : celui de la banque centrale sur `/cycles`
  (`CentralBank.bias`). Changer le biais sur `/biais` change donc la page
  Cycles, et inversement (y compris via le bouton d'actualisation par l'IA).
  Mapping devise → banque dans `BiasPairList::CURRENCY_BANKS`.
- **Les neutres n'entrent dans aucune paire** ; seules les 28 paires
  devise/devise de `Trade::ALLOWED_ASSETS` sont candidates (pas d'or, de
  crypto ni d'indice).
- **L'ordre survit aux changements de biais** : `BiasPairList::sync()` retire
  les paires devenues non divergentes et ajoute les nouvelles en fin de liste,
  sans toucher au rang des autres. Une paire qui sort puis revient repart en
  bas. La synchronisation se fait à chaque affichage et après chaque changement
  de biais, donc un changement venu de `/cycles` est pris en compte au
  prochain chargement.
- **Notes en HTML riche** (Quill, thème snow) pour conserver gras, listes,
  titres et surlignage lors d'un collage depuis Claude Desktop. Le HTML est
  nettoyé côté serveur par le sanitizer `currency_note`
  (`config/packages/html_sanitizer.yaml`) : pas de style inline, surlignage par
  classes `ql-bg-*`. Enregistrement par bouton, pas d'auto-save, pas
  d'historique.
- **Par utilisateur** : `currency_note` et `bias_pair` portent `user_id`.

## À faire (idées validées, non planifiées)

- [ ] Exploiter le biais ailleurs : badge sur un trade dont la paire est dans
      la liste des paires divergentes, ou filtre « paires divergentes » sur
      `/trade`. Le modèle actuel suffit (`BiasPairList::divergentPairs()`).
