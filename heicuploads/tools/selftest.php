<?php
/**
 * Test du moteur de conversion, hors Invision Community.
 *
 * Le moteur ne dépend d'aucune classe \IPS : on peut donc l'éprouver en
 * ligne de commande, avant même d'installer l'application, et rejouer un
 * fichier litigieux sans passer par un envoi réel sur le forum.
 *
 * Usage : php tools/selftest.php photo.heic [autre.heic ...]
 */

/* Garde écrite ici et non empruntée à _bootstrap.php : cet outil est le SEUL
   à ne pas charger init.php — c'est même sa raison d'être, éprouver le moteur
   avant que l'application ne soit installée. Il doit malgré tout refuser
   l'accès HTTP comme les autres, d'où cette recopie assumée de quatre lignes. */
if ( PHP_SAPI !== 'cli' )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/* Le moteur porte la garde anti-accès direct des fichiers IPS. En contexte
   de test on la satisfait explicitement.

   Attention au nom : il se déclare SANS antislash initial. Avec un antislash,
   PHP crée une constante dont le nom commence littéralement par « \ », que
   defined('\IPS\SUITE_UNIQUE_KEY') ne retrouve pas — la garde se déclenche
   alors et le script sort en silence avec le code 0. */
define( 'IPS\SUITE_UNIQUE_KEY', 'selftest' );

require __DIR__ . '/../sources/Converter/Converter.php';

use IPS\heicuploads\Converter;

$ko = fn( int $bytes ) : string => sprintf( '%.0f KB', $bytes / 1024 );


/* ------------------------------------------------------------------ */
/* 1. Diagnostic de l'environnement                                    */
/* ------------------------------------------------------------------ */

echo "=== Diagnostics ===\n";

$problems = Converter::diagnose();

if ( !$problems )
{
	echo "  No problem. This server can convert.\n";
}

foreach ( $problems as $problem )
{
	printf( "  [%s] %s\n         → %s\n",
		$problem['blocking'] ? 'BLOCKING' : 'warning',
		$problem['what'],
		$problem['fix'] );
}

printf( "\n  Operational: %s\n\n", Converter::isOperational( $problems ) ? 'YES' : 'NO' );

if ( !Converter::isOperational( $problems ) )
{
	fwrite( STDERR, "Unusable environment, conversion not attempted.\n" );
	exit( 1 );
}


/* ------------------------------------------------------------------ */
/* 2. Conversion des fichiers passés en argument                       */
/* ------------------------------------------------------------------ */

$sources = array_slice( $argv, 1 );

if ( !$sources )
{
	echo "No file to convert. Usage: php tools/selftest.php photo.heic\n";
	exit( 0 );
}

$converter = new Converter();
$echecs    = 0;

foreach ( $sources as $source )
{
	printf( "=== %s ===\n", $source );

	if ( !Converter::isSource( $source ) )
	{
		printf( "  skipped: not a HEIC/HEIF file\n\n" );
		continue;
	}

	$base  = preg_replace( '/\.(heic|heif)$/i', '', $source );
	$avif  = $base . '.avif';

	try
	{
		/* Un seul appel : l'AVIF et la vignette sortent du même décodage. */
		$result = $converter->process( $source, $avif, $base . '.thumb.avif' );

		$stats = $result['avif'];
		$thumb = $result['thumb'];

		printf( "  AVIF     : %s  %dx%d  %.2f s  (source %s)\n",
			$ko( $stats['filesize'] ), $stats['width'], $stats['height'],
			$result['duration'], $ko( filesize( $source ) ) );
		printf( "  Signature: %s\n", Converter::hasAvifSignature( $avif ) ? 'ftypavif — compliant' : 'NON-COMPLIANT' );

		/* Second portail, indépendant de la signature : c'est getimagesize()
		   qui décide de attach_is_image dans \IPS\File. */
		$gis = @getimagesize( $avif );
		printf( "  getimagesize : %s\n", $gis ? "{$gis[0]}x{$gis[1]}, type {$gis[2]}" : 'FAILED' );

		printf( "  Thumbnail: %s  %dx%d  (%s)\n",
			$ko( $thumb['filesize'] ), $thumb['width'], $thumb['height'], $thumb['format'] );

		printf( "  Ratio    : %.1f%% of the original size\n\n",
			$stats['filesize'] / filesize( $source ) * 100 );
	}
	catch ( Throwable $e )
	{
		$echecs++;
		printf( "  FAILED: %s\n\n", $e->getMessage() );
	}
}

printf( "%d file(s), %d failure(s).\n", count( $sources ), $echecs );
exit( $echecs ? 1 : 0 );
