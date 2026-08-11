<?php

if ( PHP_SAPI !== 'cli' )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}
/**
 * Test du moteur de conversion, hors Invision Community.
 *
 * Le moteur ne dépend d'aucune classe \IPS : on peut donc l'éprouver en
 * ligne de commande, avant même d'installer l'application, et rejouer un
 * fichier litigieux sans passer par un envoi réel sur le forum.
 *
 * Usage : php tools/selftest.php photo.heic [autre.heic ...]
 */

/* Le moteur porte la garde anti-accès direct des fichiers IPS. En contexte
   de test on la satisfait explicitement.

   Attention au nom : il se déclare SANS antislash initial. Avec un antislash,
   PHP crée une constante dont le nom commence littéralement par « \ », que
   defined('\IPS\SUITE_UNIQUE_KEY') ne retrouve pas — la garde se déclenche
   alors et le script sort en silence avec le code 0. */
define( 'IPS\SUITE_UNIQUE_KEY', 'selftest' );

require __DIR__ . '/../sources/Converter/Converter.php';

use IPS\heicuploads\Converter;

$ko = fn( int $bytes ) : string => sprintf( '%.0f Ko', $bytes / 1024 );


/* ------------------------------------------------------------------ */
/* 1. Diagnostic de l'environnement                                    */
/* ------------------------------------------------------------------ */

echo "=== Diagnostic ===\n";

$problems = Converter::diagnose();

if ( !$problems )
{
	echo "  Aucun problème. Le serveur peut convertir.\n";
}

foreach ( $problems as $problem )
{
	printf( "  [%s] %s\n         → %s\n",
		$problem['blocking'] ? 'BLOQUANT' : 'avertissement',
		$problem['what'],
		$problem['fix'] );
}

printf( "\n  Opérationnel : %s\n\n", Converter::isOperational( $problems ) ? 'OUI' : 'NON' );

if ( !Converter::isOperational( $problems ) )
{
	fwrite( STDERR, "Environnement inutilisable, conversion non tentée.\n" );
	exit( 1 );
}


/* ------------------------------------------------------------------ */
/* 2. Conversion des fichiers passés en argument                       */
/* ------------------------------------------------------------------ */

$sources = array_slice( $argv, 1 );

if ( !$sources )
{
	echo "Aucun fichier à convertir. Usage : php tools/selftest.php photo.heic\n";
	exit( 0 );
}

$converter = new Converter();
$echecs    = 0;

foreach ( $sources as $source )
{
	printf( "=== %s ===\n", $source );

	if ( !Converter::isSource( $source ) )
	{
		printf( "  ignoré : ce n'est pas un HEIC/HEIF\n\n" );
		continue;
	}

	$base  = preg_replace( '/\.(heic|heif)$/i', '', $source );
	$avif  = $base . '.avif';

	try
	{
		/* Un seul appel : l'AVIF et la vignette sortent du même décodage. */
		$result = $converter->process( $source, $avif, $base . '.thumb' );

		$stats = $result['avif'];
		$thumb = $result['thumb'];

		printf( "  AVIF     : %s  %dx%d  %.2f s  (source %s)\n",
			$ko( $stats['filesize'] ), $stats['width'], $stats['height'],
			$result['duration'], $ko( filesize( $source ) ) );
		printf( "  Signature: %s\n", Converter::hasAvifSignature( $avif ) ? 'ftypavif — conforme' : 'NON CONFORME' );

		/* Second portail, indépendant de la signature : c'est getimagesize()
		   qui décide de attach_is_image dans \IPS\File. */
		$gis = @getimagesize( $avif );
		printf( "  getimagesize : %s\n", $gis ? "{$gis[0]}x{$gis[1]}, type {$gis[2]}" : 'ÉCHEC' );

		printf( "  Vignette : %s  %dx%d  (%s)\n",
			$ko( $thumb['filesize'] ), $thumb['width'], $thumb['height'], $thumb['format'] );

		printf( "  Gain     : %.1f%% du poids d'origine\n\n",
			$stats['filesize'] / filesize( $source ) * 100 );
	}
	catch ( Throwable $e )
	{
		$echecs++;
		printf( "  ÉCHEC : %s\n\n", $e->getMessage() );
	}
}

printf( "%d fichier(s), %d échec(s).\n", count( $sources ), $echecs );
exit( $echecs ? 1 : 0 );
