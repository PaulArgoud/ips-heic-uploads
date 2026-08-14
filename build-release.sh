#!/bin/sh
#
# Fabrique l'archive d'installation d'une version étiquetée.
#
# Invision Community n'accepte qu'un .tar NON COMPRESSÉ, et il attend le
# CONTENU du répertoire de l'application à la racine de l'archive — pas un
# dossier « heicuploads/ », pas le dépôt. Les deux contraintes viennent du
# coeur, vérifiées dans applications/core/modules/admin/applications/applications.php :
#
#   - « 'allowedFileTypes' => array( 'tar' ) » puis « new PharData( …, Phar::TAR ) » :
#     un .tar.gz est refusé sur son extension avant même d'être ouvert ;
#   - « buildFromIterator( …, ROOT_PATH . "/applications/" . $directory . "/" ) » :
#     ce second argument est la base RETIRÉE des chemins, d'où la mise à plat.
#
# Les deux archives que GitHub génère automatiquement violent les deux règles :
# elles sont compressées et encapsulent tout dans un dossier racine versionné.
# Il faut donc joindre CETTE archive-ci à la release, en pièce jointe.
#
# On part d'une ÉTIQUETTE et non du répertoire de travail : le contenu est
# alors reproductible — la même étiquette rendra toujours la même archive — et
# les fichiers non suivis par git, à commencer par les .DS_Store de macOS, ne
# peuvent pas s'y glisser.
#
# On n'emploie délibérément PAS l'export de l'AdminCP, qui appelle build()
# avant de fabriquer le tar : build() régénère les manifestes depuis la BASE DE
# DONNÉES et écraserait data/*.json écrits à la main.
#
# Usage :
#   ./build-release.sh            # la dernière étiquette
#   ./build-release.sh v1.0.1     # une étiquette précise

set -eu

APP="heicuploads"
NOM="HEIC Uploads"

cd "$(dirname "$0")"

TAG="${1:-$(git describe --tags --abbrev=0 2>/dev/null || true)}"

if [ -z "$TAG" ]; then
	echo "Aucune étiquette. Créez-en une, ou passez-en une en argument." >&2
	exit 1
fi

if ! git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
	echo "Étiquette inconnue : $TAG" >&2
	exit 1
fi

# La version fait foi côté application, pas côté étiquette : c'est elle que
# l'AdminCP affichera. La DERNIÈRE entrée de versions.json l'emporte, et non la
# plus grande — comportement du coeur, contre-intuitif mais vérifié.
VERSION=$(git show "$TAG:$APP/data/versions.json" | php -r '
	$v = json_decode( file_get_contents( "php://stdin" ), TRUE );
	if ( !is_array( $v ) or !count( $v ) ) { exit( 1 ); }
	echo end( $v );
')

if [ -z "$VERSION" ]; then
	echo "versions.json illisible ou vide dans $TAG." >&2
	exit 1
fi

# Une étiquette qui ne correspond pas à la version déclarée est une erreur de
# publication, pas un détail : c'est la version, pas l'étiquette, qui décidera
# du numéro affiché aux administrateurs.
if [ "$TAG" != "v$VERSION" ]; then
	echo "Incohérence : étiquette $TAG, version déclarée $VERSION." >&2
	echo "Alignez data/versions.json et l'étiquette avant de publier." >&2
	exit 1
fi

ARCHIVE="$NOM $VERSION.tar"

git archive --format=tar "$TAG:$APP" -o "$ARCHIVE"

# --- Contrôles, sur l'archive réellement produite -------------------------
# Chacun correspond à une manière connue de se tromper. Le script échoue au
# premier, plutôt que de livrer une archive que l'AdminCP refusera sans dire
# pourquoi.

echec=0

verifier() {
	if [ "$2" -eq 0 ]; then
		printf '  [OK]     %s\n' "$1"
	else
		printf '  [ECHEC]  %s\n' "$1"
		echec=1
	fi
}

entrees=$(tar -tf "$ARCHIVE")

printf '\n%s — %s\n\n' "$ARCHIVE" "$(wc -c < "$ARCHIVE" | tr -d ' ') octets"

# 1. Mise à plat : aucune entrée ne doit être préfixée du nom du répertoire.
printf '%s\n' "$entrees" | grep -q "^$APP/" && n=1 || n=0
verifier "Contenu à plat, sans dossier « $APP/ » englobant" "$n"

# 2. Les deux fichiers qu'IPS lit en premier doivent être à la racine.
printf '%s\n' "$entrees" | grep -qx 'Application.php' && n=0 || n=1
verifier "Application.php présent à la racine" "$n"
printf '%s\n' "$entrees" | grep -qx 'data/application.json' && n=0 || n=1
verifier "data/application.json présent à la racine" "$n"

# 3. Saletés macOS : impossibles depuis une étiquette git, vérifiées quand même —
#    ce script pourrait un jour être modifié pour partir du répertoire de travail.
printf '%s\n' "$entrees" | grep -Eq '(^|/)\.DS_Store$|(^|/)\._' && n=1 || n=0
verifier "Aucun fichier parasite macOS" "$n"

# 4. Rien du dépôt lui-même : ces fichiers n'ont rien à faire dans
#    applications/heicuploads/ sur le forum d'un tiers.
printf '%s\n' "$entrees" | grep -Eq '^(README|CHANGELOG|LICENSE|\.git)' && n=1 || n=0
verifier "Aucun fichier de dépôt (README, CHANGELOG, .git…)" "$n"

# 5. Une archive vide ou tronquée se repère au compte.
#    grep -E et non l'alternation « \| » d'une regex basique : celle-ci n'est
#    pas portable, le grep de macOS n'en comptait qu'une branche sur trois.
n=$(printf '%s\n' "$entrees" | grep -Ec '\.(php|json|xml)$')
[ "$n" -ge 25 ] && m=0 || m=1
verifier "$n fichiers php/json/xml (attendu : au moins 25)" "$m"

if [ "$echec" -ne 0 ]; then
	printf '\nArchive NON conforme, supprimée.\n' >&2
	rm -f "$ARCHIVE"
	exit 1
fi

cat <<FIN

Archive conforme.

À joindre en pièce jointe à la release $TAG sur GitHub. Les deux archives que
GitHub génère seul (.tar.gz et .zip) ne conviennent pas : compressées, et
encapsulées dans un dossier racine.

Rappel : téléverser cette archive sur une application DÉJÀ installée emprunte le
chemin de montée de version du coeur, qui cherche un dossier setup/ que cette
application n'a pas. Ce chemin n'a jamais été éprouvé ici. Pour mettre à jour une
installation existante, copier les fichiers puis lancer tools/deploy-sync.php.
FIN
