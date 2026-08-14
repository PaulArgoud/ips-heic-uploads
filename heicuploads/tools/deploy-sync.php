<?php
/**
 * Réconcilie la base de données avec le manifeste de l'application.
 *
 * Copier les fichiers ne suffit pas. Les manifestes data/*.json et data/lang.xml
 * ne sont relus qu'à l'installation ou à la montée de version, et il n'y a pas
 * de dossier setup/ ici. Sans ce script, après une copie de fichiers :
 *
 *   - un réglage nouveau n'existe pas en base, et Settings::changeValues()
 *     l'ignore SILENCIEUSEMENT (Settings.php:296-301) : la page de réglages
 *     paraît fonctionner et n'enregistre rien ;
 *   - un mot de langue nouveau n'existe pas, et import-lang.php ne fait qu'un
 *     UPDATE : il le signalera « ABSENTE de la base » sans le créer ;
 *   - une colonne nouvelle manque, et toute la chaîne se bloque sur un INSERT.
 *
 * On ne réécrit rien à la main : on appelle les routines du coeur, qui sont
 * additives et rejouables. Aucune ne touche aux pièces jointes.
 *
 * À lancer depuis la RACINE du forum :
 *     php applications/heicuploads/tools/deploy-sync.php          (simulation)
 *     php applications/heicuploads/tools/deploy-sync.php --write   (applique)
 */

require_once __DIR__ . '/_bootstrap.php';

use IPS\Application;
use IPS\Data\Store;
use IPS\Db;
use IPS\Lang;

$ecrire = ecritureDemandee( $argv );

$ok   = fn( string $m ) => print( "  [OK]      {$m}\n" );
$todo = fn( string $m ) => print( "  [A FAIRE] {$m}\n" );
$warn = fn( string $m ) => print( "  [ATTENTION] {$m}\n" );
$info = fn( string $m ) => print( "            {$m}\n" );

$titre = function( int $n, string $t ) {
	printf( "\n=== %d. %s ===\n", $n, $t );
};

printf( "%s\n", $ecrire
	? "=== ÉCRITURE ==="
	: "=== SIMULATION (ajoutez --write pour appliquer) ===" );

try
{
	$app = Application::load( 'heicuploads' );
}
catch( Throwable $e )
{
	fwrite( STDERR, "Application heicuploads introuvable en base : " . $e->getMessage() . "\n" );
	fwrite( STDERR, "Ce script réconcilie une installation existante ; il ne remplace pas l'installation initiale.\n" );
	exit( 1 );
}

$chemin = $app->getApplicationPath();

/* Ce qu'il reste à faire, pour la conclusion. */
$manques = 0;


/* ------------------------------------------------------------------ */
$titre( 1, "Version" );

$versions = json_decode( file_get_contents( $chemin . '/data/versions.json' ), TRUE );
$longFichier = (int) array_key_last( $versions );

$ligne = Db::i()->select( 'app_version, app_long_version', 'core_applications', array( 'app_directory=?', 'heicuploads' ) )->first();

$info( sprintf( "en base   : %s (%d)", $ligne['app_version'], $ligne['app_long_version'] ) );
$info( sprintf( "sur disque : %s (%d)", $versions[ (string) $longFichier ], $longFichier ) );

if ( (int) $ligne['app_long_version'] === $longFichier )
{
	$ok( "Versions identiques — la synchronisation ne repose donc pas dessus." );
	$info( "C'est justement pourquoi ce script existe : sans montée de version," );
	$info( "le coeur ne relit jamais les manifestes de lui-même." );
}
else
{
	$info( "Versions différentes : installJsonData() alignera core_applications." );
}


/* ------------------------------------------------------------------ */
$titre( 2, "Schéma de la table de suivi" );

$schema = json_decode( file_get_contents( $chemin . '/data/schema.json' ), TRUE );

foreach ( $schema as $table => $definition )
{
	if ( !Db::i()->checkForTable( $table ) )
	{
		$todo( "Table {$table} ABSENTE — elle serait créée." );
		$manques++;
		continue;
	}

	$actuel   = Db::i()->getTableDefinition( $table );
	$absentes = array();

	foreach ( $definition['columns'] as $colonne )
	{
		if ( empty( $actuel['columns'][ $colonne['name'] ] ) )
		{
			$absentes[] = $colonne['name'];
		}
	}

	if ( $absentes )
	{
		$todo( sprintf( "%s : colonne(s) manquante(s) — %s", $table, implode( ', ', $absentes ) ) );
		$info( "Une colonne absente bloque toute la chaîne sur un INSERT." );
		$manques += count( $absentes );
	}
	else
	{
		$ok( "{$table} : toutes les colonnes du manifeste sont présentes." );
	}
}


/* ------------------------------------------------------------------ */
$titre( 3, "Réglages" );

$reglages = json_decode( file_get_contents( $chemin . '/data/settings.json' ), TRUE );
$attendus = array_column( $reglages, 'key' );

/* Toutes les lignes portant une de nos clés, quel que soit conf_app : le coeur
   adopte une clé orpheline (conf_app NULL) au lieu de la réinsérer
   (Application.php:2337-2338). */
$presents = iterator_to_array( Db::i()->select(
	array( 'conf_key', 'conf_app', 'conf_value' ),
	'core_sys_conf_settings',
	array( Db::i()->in( 'conf_key', $attendus ) )
)->setKeyField( 'conf_key' ) );

foreach ( $attendus as $cle )
{
	if ( !isset( $presents[ $cle ] ) )
	{
		$todo( "{$cle} — ABSENT, serait créé." );
		$manques++;
	}
	elseif ( $presents[ $cle ]['conf_app'] !== 'heicuploads' )
	{
		$todo( sprintf( "%s — présent mais rattaché à « %s », serait rattaché à l'application.", $cle, $presents[ $cle ]['conf_app'] ?? 'aucune' ) );
		$manques++;
	}
	else
	{
		$ok( sprintf( "%s = %s", $cle, $presents[ $cle ]['conf_value'] ) );
	}
}

/* installSettings() supprime les réglages de l'application absents du
   manifeste (Application.php:2367-2373). On le montre AVANT d'écrire :
   heicuploads_baseline_id passerait par là s'il disparaissait du manifeste,
   et le repère perdu, c'est la conversion rétroactive qui revient. */
$aSupprimer = iterator_to_array( Db::i()->select(
	'conf_key',
	'core_sys_conf_settings',
	array( array( 'conf_app=?', 'heicuploads' ), array( Db::i()->in( 'conf_key', $attendus, TRUE ) ) )
) );

if ( $aSupprimer )
{
	$warn( "Réglage(s) en base mais absent(s) du manifeste — ils seraient SUPPRIMÉS :" );

	foreach ( $aSupprimer as $cle )
	{
		$info( "  - " . $cle );
	}

	$info( "Si l'un d'eux compte, ajoutez-le à data/settings.json avant d'écrire." );
}


/* ------------------------------------------------------------------ */
$titre( 4, "Module d'administration et tâche" );

$modules = json_decode( file_get_contents( $chemin . '/data/modules.json' ), TRUE );

foreach ( $modules as $zone => $liste )
{
	foreach ( $liste as $cle => $donnees )
	{
		$n = Db::i()->select( 'COUNT(*)', 'core_modules', array(
			'sys_module_application=? AND sys_module_area=? AND sys_module_key=?', 'heicuploads', $zone, $cle
		) )->first();

		if ( $n )
		{
			$ok( "Module {$zone}/{$cle} présent." );
		}
		else
		{
			$todo( "Module {$zone}/{$cle} ABSENT — la page de réglages est injoignable. Serait créé." );
			$manques++;
		}
	}
}

$taches = json_decode( file_get_contents( $chemin . '/data/tasks.json' ), TRUE );

foreach ( $taches as $cle => $frequence )
{
	try
	{
		$tache = Db::i()->select( '*', 'core_tasks', array( '`key`=?', $cle ) )->first();

		$ok( sprintf( "Tâche %s présente (%s, %s, verrous %d)",
			$cle,
			$tache['frequency'],
			$tache['enabled'] ? 'activée' : 'DÉSACTIVÉE',
			$tache['lock_count'] ) );

		if ( $tache['frequency'] !== $frequence or !$tache['enabled'] or $tache['lock_count'] >= 3 )
		{
			$info( "installTasks() fait un REPLACE : la ligne sera remise à neuf" );
			$info( "(fréquence du manifeste, réactivée, verrous et dernier run remis à zéro)." );
		}
	}
	catch( Throwable $e )
	{
		$todo( "Tâche {$cle} ABSENTE — rien ne détecterait les HEIC. Serait créée." );
		$manques++;
	}
}


/* ------------------------------------------------------------------ */
$titre( 5, "Mots de langue" );

$xml = @simplexml_load_file( $chemin . '/data/lang.xml' );

if ( !$xml )
{
	$warn( "data/lang.xml illisible." );
}
else
{
	$cles = array();

	foreach ( $xml->app->word as $mot )
	{
		$cles[] = (string) $mot['key'];
	}

	$info( sprintf( "%d clé(s) dans data/lang.xml.", count( $cles ) ) );

	foreach ( Lang::languages() as $langId => $langue )
	{
		$enBase = iterator_to_array( Db::i()->select(
			'word_key',
			'core_sys_lang_words',
			array( array( 'word_app=? AND lang_id=?', 'heicuploads', $langId ), array( Db::i()->in( 'word_key', $cles ) ) )
		) );

		$absentes = array_diff( $cles, $enBase );

		if ( $absentes )
		{
			$todo( sprintf( "%s (id=%d) : %d mot(s) manquant(s) sur %d.", $langue->title, $langId, count( $absentes ), count( $cles ) ) );

			foreach ( array_slice( $absentes, 0, 5 ) as $cle )
			{
				$info( "  - " . $cle );
			}

			if ( count( $absentes ) > 5 )
			{
				$info( sprintf( "  … et %d autre(s).", count( $absentes ) - 5 ) );
			}

			$manques += count( $absentes );
		}
		else
		{
			$ok( sprintf( "%s (id=%d) : complète.", $langue->title, $langId ) );
		}
	}
}


/* ------------------------------------------------------------------ */

if ( !$ecrire )
{
	printf( "\n%s\n", $manques
		? "{$manques} élément(s) à créer. Relancez avec --write pour appliquer."
		: "Rien à faire : la base correspond déjà au manifeste." );
	exit( 0 );
}

$titre( 6, "Application des changements" );

/* Le schéma d'abord : une colonne manquante ferait échouer tout le reste. */
$app->installDatabaseSchema();
$ok( "installDatabaseSchema() — table et colonnes manquantes ajoutées." );

/* Modules, tâches, réglages, version, cache des applications. */
$app->installJsonData();
$ok( "installJsonData() — modules, tâches, réglages." );

/* Les mots ensuite : word_custom n'est pas dans le jeu de colonnes inséré,
   l'ON DUPLICATE KEY UPDATE ne l'écrase donc pas (Db.php:1002-1005). Une
   traduction déjà appliquée survit. */
$inseres = $app->installLanguages();
$ok( sprintf( "installLanguages() — %d mot(s) parcouru(s).", $inseres ) );

/* installJsonData() vide déjà Store::i()->applications, et installSettings()
   le cache des réglages. Restent les modules — installModules() pose
   _skipClearingMenuCache hors mode développeur (Application.php:2180-2183),
   sans quoi la page de réglages resterait invisible jusqu'au prochain vidage —
   et les langues, qu'installLanguages() ne purge pas. */
unset( Store::i()->modules );
unset( Store::i()->languages );
$ok( "Caches des modules et des langues vidés." );

print( "\nTerminé.\n" );
print( "Étape suivante : appliquer la traduction française, maintenant que les\n" );
print( "mots existent en base :\n" );
print( "  php applications/heicuploads/tools/import-lang.php\n" );
print( "  php applications/heicuploads/tools/import-lang.php french <id> --write\n" );
