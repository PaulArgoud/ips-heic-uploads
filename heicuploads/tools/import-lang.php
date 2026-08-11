<?php

/* Ces scripts chargent init.php eux-memes : sans cette garde ils seraient
   executables par une simple requete HTTP, et divulgueraient reglages,
   chemins de stockage obscurcis et journaux. */
if ( PHP_SAPI !== 'cli' )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}
/**
 * Applique un fichier de traduction à une langue déjà installée.
 *
 * data/lang.xml n'est lu qu'à l'installation, et il est en anglais. Ce script
 * permet d'appliquer une des traductions de translations/ sans réinstaller.
 *
 * À lancer depuis la RACINE du forum :
 *     php applications/heicuploads/tools/import-lang.php                 (liste les langues)
 *     php applications/heicuploads/tools/import-lang.php french 2        (simulation)
 *     php applications/heicuploads/tools/import-lang.php french 2 --ecrire
 */

$root = realpath( __DIR__ . '/../../..' );

if ( !is_file( $root . '/init.php' ) )
{
	fwrite( STDERR, "init.php introuvable. Lancez ce script depuis la racine du forum.\n" );
	exit( 1 );
}

require_once $root . '/init.php';

use IPS\Db;

$fichier = $argv[1] ?? NULL;
$langId  = isset( $argv[2] ) ? (int) $argv[2] : NULL;
$ecrire  = in_array( '--ecrire', $argv );

/* Sans argument : on montre les langues du forum et les traductions dispo. */
if ( !$fichier or !$langId )
{
	print( "Langues installées :\n" );

	foreach ( Db::i()->select( '*', 'core_sys_lang' ) as $l )
	{
		printf( "  id=%-3d %-30s %s\n", $l['lang_id'], $l['lang_title'], $l['lang_default'] ? '(par défaut)' : '' );
	}

	print( "\nTraductions disponibles :\n" );

	foreach ( glob( __DIR__ . '/../translations/*.xml' ) as $f )
	{
		printf( "  %s\n", basename( $f, '.xml' ) );
	}

	printf( "\nExemple : php %s french 2 --ecrire\n", 'applications/heicuploads/tools/import-lang.php' );
	exit( 0 );
}

$chemin = __DIR__ . '/../translations/' . basename( $fichier, '.xml' ) . '.xml';

if ( !is_file( $chemin ) )
{
	fwrite( STDERR, "Traduction introuvable : {$chemin}\n" );
	exit( 1 );
}

$xml = @simplexml_load_file( $chemin );

if ( !$xml )
{
	fwrite( STDERR, "XML illisible : {$chemin}\n" );
	exit( 1 );
}

try
{
	$langue = Db::i()->select( '*', 'core_sys_lang', array( 'lang_id=?', $langId ) )->first();
}
catch( Throwable $e )
{
	fwrite( STDERR, "Aucune langue avec l'identifiant {$langId}.\n" );
	exit( 1 );
}

printf( "%s\n%s → %s\n\n",
	$ecrire ? "=== ÉCRITURE ===" : "=== SIMULATION (ajoutez --ecrire pour appliquer) ===",
	basename( $chemin ),
	$langue['lang_title'] );

$applique = $absent = 0;

foreach ( $xml->app->word as $mot )
{
	$cle    = (string) $mot['key'];
	$valeur = (string) $mot;

	/* On écrit dans word_custom, pas word_default : c'est la colonne des
	   traductions, et elle survit à une réinstallation de l'application. */
	$n = $ecrire
		? Db::i()->update(
			'core_sys_lang_words',
			array( 'word_custom' => $valeur, 'word_is_custom' => 1 ),
			array( 'word_key=? AND word_app=? AND lang_id=?', $cle, 'heicuploads', $langId )
		)
		: Db::i()->select( 'COUNT(*)', 'core_sys_lang_words', array( 'word_key=? AND word_app=? AND lang_id=?', $cle, 'heicuploads', $langId ) )->first();

	if ( $n )
	{
		$applique++;
	}
	else
	{
		printf( "  ABSENTE de la base : %s\n", $cle );
		$absent++;
	}
}

printf( "\n%d chaîne(s) %s, %d absente(s) de la base.\n",
	$applique,
	$ecrire ? 'appliquée(s)' : 'trouvée(s)',
	$absent );

if ( $ecrire )
{
	unset( \IPS\Data\Store::i()->languages );
	print( "Cache des langues vidé.\n" );
}
