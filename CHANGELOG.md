# Changelog

Toutes les modifications notables de **HEIC Uploads**.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et le projet applique le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

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
  `PHP_SAPI` ajoutée aux cinq.

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
- `LICENSE` (MIT) et `.gitignore`, en préparation du dépôt public. Le
  `.gitignore` exclut d'abord la copie de travail du code source d'Invision
  Community : c'est du code propriétaire, le publier serait une violation de
  licence.

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
