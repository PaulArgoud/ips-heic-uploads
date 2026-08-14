# Changelog

Toutes les modifications notables de **HEIC Uploads**.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et le projet applique le [versionnage sémantique](https://semver.org/lang/fr/).

## [1.0.1] — 2026-08-14

Version d'audit. Aucune fonction nouvelle côté membre : que des correctifs, une
relance depuis l'AdminCP, et une factorisation. Deux des correctifs portent sur
des bogues qui étaient **en production**.

### Corrigé

- **La page de réglages tombait en erreur fatale à l'enregistrement**
  _(majeur)_. `Dispatcher::i()->url` n'existe pas : ni `\IPS\Dispatcher`, ni
  `Standard`, ni `Admin` ne déclarent `$url`, et aucune n'a de `__get`. La
  lecture donnait `NULL`, que `Output::redirect()` refuse — son paramètre est
  un `Url|string` non nullable (`Output.php:1448`). Les réglages étaient bien
  enregistrés, puis la page mourait en `TypeError`. C'est
  `\IPS\Dispatcher\Controller` qui porte `$url` (`Controller.php:47`).
- **La déduplication de la file ne dédupliquait rien**. `Task::queue()` compare
  les clés des **données** du travail, avec un `isset()` sur les deux côtés
  (`Task.php:221-229`). On passait des données vides et la clé `convert` : elle
  n'existait nulle part, la comparaison n'aboutissait jamais, et une ligne
  `core_queue` s'ajoutait à chaque passage fructueux. Le marqueur voyage
  désormais dans les données.
- **Le contenu du membre était confié à ImageMagick sans contrôle**
  _(sécurité)_. Le cœur ne vérifie le contenu que des extensions qu'il sait
  afficher (`File.php:632`), et `heic` n'en fait pas partie (`Image.php:40`) :
  un `.png` truqué est refusé à l'envoi, un `.heic` truqué passait. Notre
  décodage étant le premier de la chaîne, `new Imagick( $chemin )` choisissait
  son décodeur en reniflant, parmi tous les codeurs enregistrés. La signature
  est maintenant reconnue en propre et le codeur **imposé** ; un contenu non
  reconnu est refusé avant qu'ImageMagick ne le voie. Un vrai JPEG renommé en
  `.heic` continue d'être converti.
- **Aucune borne sur les dimensions décodées**. Elles sont déclarées dans
  l'en-tête du fichier : quelques centaines d'octets pouvaient annoncer
  60 000 × 60 000 pixels et faire allouer plusieurs gigaoctets hors du tas PHP,
  où `memory_limit` n'a aucune prise — c'est le processus entier que l'OS tue.
  L'en-tête est désormais lu par `pingImage()`, qui n'alloue pas les pixels, et
  l'image refusée au-delà de 250 Mpx (plafond large et assumé comme estimation :
  le plus gros capteur du marché produit 200 Mpx). Le compte porte sur la
  **somme de toutes les images du conteneur**, pas sur la première : `pingImage()`
  charge toutes les trames d'un GIF ou d'un WEBP animé, que `coderFor()` accepte
  délibérément sous une extension `.heic`, et n'en mesurer qu'une rendait la
  garde contournable en renommant un fichier — 200 trames de 4 000 × 4 000
  tiennent dans quelques mégaoctets et totalisent 3,2 milliards de pixels.
  Délibérément **pas** `setResourceLimit()` pour la mémoire, qui est statique :
  le plafond aurait survécu à notre conversion et modifié le traitement d'images
  du reste du forum dans le même worker PHP-FPM — en l'assouplissant si
  l'hébergeur a une politique stricte. La limite de threads, elle, reste posée :
  elle ABAISSE, elle ne peut donc rien assouplir.
- **Le HEIC restait résident en mémoire pendant toute la conversion.**
  `File::contents()` met la chaîne en cache dans l'objet
  (`FileSystem.php:270-290`) : deux références la désignaient, et n'en libérer
  qu'une ne libérait rien. Le fichier complet occupait donc le tas PHP au moment
  précis où ImageMagick réclame le plus de mémoire.
- **Les trois tentatives se consommaient d'affilée**, en quelques secondes : une
  ligne qui vient d'échouer reste la plus ancienne convertible et repart au
  cycle suivant. Le plafond ne pouvait donc pas jouer son rôle affiché
  — absorber un incident passager. Deux minutes séparent maintenant deux
  tentatives d'une même ligne.
- **Une pièce jointe disparue rallumait un avertissement inextinguible.** Elle
  était close avec le même état qu'un échec relançable : le bouton de l'AdminCP
  la reprenait, elle retombait sur la même disparition, et le bandeau revenait.
  Ces lignes portent désormais une marque distincte et sortent du décompte.
- **L'avancement de la file dépassait 100 %.** Le total était figé à la mise en
  file alors que l'offset compte les passages, réessais compris, et que le
  travail en cours absorbe les photos détectées après sa création. Il est
  recalculé à l'affichage.
- **Le `Rewriter` écrivait dans la mauvaise base**. Les messages archivés des
  forums vivent dans une autre connexion dès que `archive_remote_sql_host` est
  réglé (`ArchivedPost.php:50-68`) ; on utilisait `Db::i()` au lieu de
  `$class::db()`.
- **Une pièce jointe retirée par le membre produisait trois tentatives puis un
  échec sans message.** L'`UnderflowException` de `Select::first()` a un
  message vide. Ce cas est désormais clos immédiatement, avec sa cause écrite.
- **Une conversion interrompue laissait une ligne « en attente » à jamais.**
  La tentative est comptabilisée avant le travail, délibérément ; mais si le
  processus mourait pendant, l'échec n'était jamais consigné : plus rien ne
  rejouait la ligne, et l'AdminCP l'annonçait comme normale. La tâche de
  détection ferme ces lignes — après un délai de cinq minutes, sans quoi elle
  déclarerait en échec une conversion **en cours** sur sa dernière tentative,
  qui présente exactement le même état.
- **Retomber sur une ligne existante la remettait à zéro.** `Db::insert()`
  prend `$odkUpdate` en 3ᵉ argument et `$ignoreErrors` en 4ᵉ (`Db.php:996`) :
  on passait le 3ᵉ, donc un `ON DUPLICATE KEY UPDATE` sur toutes les colonnes
  là où le commentaire annonçait une insertion idempotente. Deux passages
  concurrents de la détection pouvaient ainsi renvoyer une pièce jointe déjà
  convertie au statut « en attente ».
- **Le marquage « converti » précédait la réécriture du message et la
  suppression de l'original.** Une interruption entre les deux figeait un lien
  de téléchargement dans le message, définitivement. Le marquage est passé en
  dernier : « converti » signifie désormais *converti, réécrit, original
  supprimé*.
- **Vitesse 0 et qualité 0 étaient inatteignables.** L'opérateur `?:` les
  traitait comme non renseignées : choisir la vitesse 0, la plus lente et la
  plus dense, appliquait 9 — la plus rapide.
- **Le libellé de la tâche s'affichait en clé brute** dans l'AdminCP : le cœur
  attend le préfixe `task__`.
- **Les libellés du choix de filtre étaient en français dans le code**, sur un
  écran par ailleurs traduit en cinq langues.
- **Un échec après création de l'AVIF laissait deux fichiers orphelins** dans
  le stockage, à chaque tentative — le nom porte un suffixe aléatoire, plus
  rien ne les désignait.
- **La copie locale du HEIC n'était pas vérifiée** alors que l'original allait
  être détruit. Un disque plein tronque sans exception, et le gestionnaire
  d'erreurs d'IPS ignore `E_WARNING` (`init.php:796-799`).
- **La vignette s'écrivait sur un chemin dérivé d'un `tempnam()`**, donc sans
  sa réservation atomique ni son mode 0600, dans un `/tmp` partagé.
- **Un échec d'insertion faisait sauter une photo pour toujours.** Le repère du
  passage suivant est le plus grand identifiant enregistré : poursuivre le lot
  faisait passer l'identifiant en échec sous ce repère. Le lot s'interrompt.
- **Les lignes en attente n'étaient plus reprises si la ligne `core_queue`
  disparaissait** — ce qui arrive quand l'administrateur coupe puis rétablit le
  réglage. La détection remet en file dès qu'il reste du convertible, mais
  seulement si aucun travail n'attend déjà : `Task::queue()` ne se contente pas
  d'ignorer un doublon, il supprime la ligne existante et en insère une neuve
  (`Task.php:230-233`), ce qui remettrait l'avancement à zéro chaque minute.

### Ajouté

- **Bouton « Relancer les conversions bloquées »** dans l'AdminCP. Il n'existait
  aucun moyen de relancer un échec sans requête SQL. Il reprend aussi les
  tentatives interrompues, que l'administrateur n'a pas à distinguer. Les
  lignes relancées repassent en **fin** de file : une relance manuelle n'est
  jamais urgente, alors qu'un envoi en cours court après la fenêtre de
  rédaction du membre.
- **Le repère de départ est affiché** dans le diagnostic et dans l'AdminCP. Il
  décide de tout ce qui sera converti et n'apparaissait nulle part.

### Sécurité

- **Un repère de départ absent ne vaut plus zéro.** Le réglage naissait à `0`,
  et zéro déclenche le balayage de tout l'historique — l'incident du
  10/08/2026, 185 pièces jointes anciennes converties et leurs originaux
  supprimés. La valeur par défaut est désormais `-1`, « non posé », la
  détection **refuse de balayer** dans ce cas, et l'AdminCP le dit. Le cas
  survient quand une installation s'interrompt à sa dernière étape.
- **Réinstaller ne redéplace plus le repère.** `setBaseline()` était
  inconditionnel : le rejouer l'aurait remonté au plus grand identifiant du
  moment, écartant définitivement les photos envoyées entre-temps.
- Le contrôle CSRF de l'AdminCP n'est **pas** automatique : `$csrfProtected`
  ne protège rien, il désactive le seul contrôle du dispatcher, qui teste sa
  seule présence (`Admin.php:227`). La nouvelle action appelle
  `Session::i()->csrfCheck()` elle-même, et la permission est contrôlée dans un
  `execute()` surchargé, comme le fait le cœur.

### Modifié — factorisation

- **`sources/Map/Map.php`** : propriétaire unique de `heicuploads_map` et de sa
  machine à états. La table était interrogée depuis six fichiers, chacun avec
  sa propre idée des états ; le prédicat des lignes abandonnées était écrit à
  trois endroits, de sorte qu'en corriger deux avait l'apparence du travail
  fait. Plus aucun autre fichier n'écrit la table ni ne manipule les littéraux
  `pending`, `converted` et `failed`.
- **`tools/_bootstrap.php`** : la garde `PHP_SAPI !== 'cli'` et la recherche de
  `init.php` étaient recopiées dans chaque outil. `selftest.php` garde la
  sienne — il est le seul à ne pas charger `init.php`, c'est sa raison d'être.
- `Rewriter::transform()` : la résolution du contenu depuis
  `core_attachments_map` et la localisation de sa colonne HTML ne vivent plus
  qu'à un endroit. `repair-fullimage.php` en avait sa propre copie, et les deux
  avaient divergé.
- Les extensions source ne sont plus écrites en dur : `Converter::isSource()`
  et `Converter::SOURCE_EXTENSIONS` font foi.
- `Rewriter` : `array_pop()` au lieu de `reset()` sur une colonne de contenu
  multiple, comme le cœur, dans `Content::mapped()` (`Content.php:388-393`) ; et
  `attachmentLookup()` est protégée, celle des lieux du calendrier levant
  `LogicException` sans condition.

### À faire au déploiement

`deploy-sync.php --ecrire` est **nécessaire** : cette version ajoute six mots
de langue et renomme le libellé de la tâche. Attention, `installTasks()` fait un
`REPLACE INTO` sur quatre colonnes de `core_tasks` — `enabled`, `last_run`,
`lock_count` et `running` repartent au défaut du schéma.

### Mesure à faire, correctif suspendu à son résultat

La fenêtre de balayage de `scanHeic` n'avance que sur les HEIC : entre deux
envois de photos, l'intervalle parcouru dans `core_attachments` s'allonge d'une
ligne à chaque pièce jointe d'un autre format. Le coût ne croît que si
`attach_ext` n'est pas indexé. **Rien n'a été changé** : la mesure décide.

```sql
SHOW INDEX FROM core_attachments;

-- Remplacer <repère> par la valeur de heicuploads_baseline_id
EXPLAIN SELECT attach_id FROM core_attachments
  WHERE attach_ext IN ('heic','heif') AND attach_id > <repère>
  ORDER BY attach_id ASC LIMIT 100;
```

Si `key` n'est pas `NULL`, le sujet est clos. Si `key` est `NULL` **et** que
`rows` se compte déjà en milliers, il faudra un curseur de balayage — dans le
datastore, jamais dans un réglage, `Settings::changeValues()` réécrivant tout le
bloc des réglages à chaque appel. Ne pas ajouter d'index à `core_attachments` :
ce serait modifier le schéma du cœur.

## [1.0.0] — 2026-08-11

Première version stable. L'application tourne en production depuis le
10/08/2026 : 191 conversions, aucune en échec.

### Corrigé

- **Corruption des jetons de stockage des autres pièces jointes** _(critique)_.
  `DOMDocument` échappe les chevrons en valeur d'attribut, et le pré-encodage
  ne protégeait pas tout : sa regex, reprise de `Parser::rebuildAttachmentUrls`,
  utilise `[^>]+?` et ne peut pas franchir le `>` d'un jeton figurant dans un
  attribut précédent. Le gabarit du cœur écrivant `data-full-image` avant `src`,
  le `src` des images déjà présentes ressortait en `&lt;fileStore.…&gt;` — donc
  en URL relative morte. Toute image d'un message où un HEIC était converti se
  retrouvait cassée. Restitution ajoutée en sortie, comme le fait
  `\IPS\Text\DOMParser::parse()`.
- **Jeton non résolu dans `data-full-image`**. `Parser::replaceFileStoreTags()`
  ne traite que `srcset|src|href|cite` : le clic sur une vignette menait à une
  URL littérale `{fileStore.core_Attachment}/…`.
- **`attach_file` n'était pas mis à jour** après conversion. Comme
  `File::isImage()` teste l'extension de ce nom, l'éditeur affichait une icône
  générique et le nom `photo.heic` pour un fichier devenu AVIF.
- **Titre de la page de réglages affiché en clair** : `Output::i()->title`
  attend une chaîne résolue, pas une clé de langue.
- **Bloc d'état en français en dur** alors que le reste de la page suivait la
  langue installée. Passé en chaînes de langue.
- **Scripts de `tools/` exécutables par HTTP anonyme** _(sérieux)_. Ils
  chargent `init.php` eux-mêmes et n'avaient pas la garde des autres fichiers ;
  ils divulguaient réglages, chemins de stockage obscurcis et journaux. Garde
  `PHP_SAPI` ajoutée aux cinq scripts d'alors ; le sixième, `deploy-sync.php`,
  la porte dès l'origine.

### Ajouté

- `tools/deploy-sync.php` — aligne la base sur les manifestes après une simple
  copie de fichiers. Sans lui, une mise à jour s'installe dans un état
  trompeur : un réglage absent de `core_sys_conf_settings` est **ignoré
  silencieusement** par `Settings::changeValues()` (`Settings.php:296-301`), si
  bien que la page de réglages paraît fonctionner sans rien enregistrer ; un
  libellé absent s'affiche en clé brute ; une colonne absente bloque toute la
  chaîne. Le script n'écrit rien de sa main, il appelle les routines du cœur
  (`installDatabaseSchema`, `installJsonData`, `installLanguages`), additives et
  rejouables. Simulation par défaut. Il montre notamment, **avant** d'écrire,
  les réglages qu'`installSettings()` supprimerait — `heicuploads_baseline_id`
  passerait par là s'il disparaissait du manifeste, et le repère perdu, c'est la
  conversion rétroactive qui revient.
- `tools/repair-fullimage.php` — répare les messages réécrits par les versions
  antérieures. Simulation par défaut, `--ecrire` pour appliquer.
- `tools/import-lang.php` — applique une traduction à une langue déjà
  installée, `data/lang.xml` n'étant lu qu'à l'installation.
- `Rewriter::restoreEscapedTokens()` et `Rewriter::restoreDataFullImage()`,
  publiques pour être réutilisables par les outils de réparation.
- Version longue `10001` dans `data/versions.json`. Sur une installation
  existante, `deploy-sync.php --ecrire` aligne `core_applications` : il n'y a
  pas de dossier `setup/`, le déploiement se fait par copie de fichiers puis
  réconciliation.
- `LICENSE` (MIT) et `.gitignore`, en préparation du dépôt public. Le
  `.gitignore` exclut d'abord la copie de travail du code source d'Invision
  Community : c'est du code propriétaire, le publier serait une violation de
  licence.

### Limites connues

- **Une conversion tuée en cours de route reste invisible.** La tentative est
  comptabilisée avant le travail, délibérément, pour qu'un fichier qui fait
  tomber le processus ne soit pas rejoué sans fin. Mais si le processus meurt
  pendant la conversion — dépassement mémoire, par exemple — `markFailed()`
  n'est jamais atteint : la ligne reste « en attente », cesse d'être rejouée une
  fois le plafond de tentatives atteint, et n'est jamais comptée en échec. Le
  bloc d'état de l'AdminCP l'annonce donc comme normale, et il n'existe aucun
  moyen de la relancer sans SQL. Jamais observé en production à ce jour.
- **`tools/repair-fullimage.php` recopie une cinquantaine de lignes du
  `Rewriter`** — résolution du contenu depuis `core_attachments_map`, puis de la
  colonne HTML — au lieu de les lui emprunter. Les deux copies ont déjà divergé
  sur le rattrapage d'exceptions.

## [1.0.0-beta.1] — 2026-08-10

Première mise en production.

### Ajouté

- Conversion HEIC/HEIF vers AVIF par ImageMagick, en tâche de fond.
- Vignette AVIF tirée du **même décodage** que l'image pleine taille : le
  décodage HEIC, opération la plus coûteuse de la chaîne, n'est payé qu'une
  fois, et la vignette échappe à la double compression.
- Contrôle de la marque `ftypavif` avant de déclarer une conversion réussie —
  c'est la signature que `\IPS\Image::create()` exige.
- Réécriture du HTML des messages publiés avant la fin de la conversion
  (`sources/Rewriter/Rewriter.php`), sans quoi la pièce jointe resterait
  définitivement un lien de téléchargement.
- Diagnostic d'environnement bloquant à l'installation : imagick, décodage
  HEIC, encodage AVIF, `IMAGETYPE_AVIF`, et un encodage de test réel.
- Page de réglages AdminCP : activation, qualités AVIF et vignette, filtre de
  redimensionnement, vitesse d'encodage, threads — plus un bloc d'état.
- Cinq langues : anglais, français, espagnol, chinois simplifié, hindi.
- `tools/selftest.php`, `tools/diagnose.php`, `tools/verify.php`.

### Sécurité

- Repère de départ (`heicuploads_baseline_id`) posé à l'installation :
  l'application ne convertit que les envois postérieurs. **Ce garde-fou a été
  ajouté après incident** — une première version balayait toute la table et a
  converti 185 pièces jointes anciennes, dont des photos de janvier 2020, en
  supprimant leurs originaux.

### Notes

- **L'original HEIC est supprimé après conversion.** Décision produit assumée :
  la photo pleine résolution est perdue.
- Les dimensions maximales viennent des réglages **natifs** d'Invision
  Community (`attachment_resample_size`, `attachment_image_size`) ; il n'y a
  délibérément pas de réglage concurrent.
- Réglages par défaut adossés à des mesures sur le serveur de production
  (4 cœurs, ImageMagick 7.1.1-43) : filtre catrom, vitesse 9, 2 threads —
  1,87 s pour une photo iPhone de 12 Mpx.
