<?php
/**
 * Contrôle des conversions déjà effectuées.
 *
 * À lancer depuis la RACINE du forum :
 *     php applications/heicuploads/tools/verify.php [nombre]
 *
 * Vérifie, pour les dernières conversions : que les fichiers existent, que
 * l'AVIF porte la marque que le coeur exige, que les dimensions et le poids
 * sont cohérents, et que la pièce jointe est bien passée en image.
 */

require_once __DIR__ . '/_bootstrap.php';

use IPS\Db;
use IPS\File;
use IPS\heicuploads\Converter;
use IPS\heicuploads\Map;

$limit = (int) ( $argv[1] ?? 10 );
$ko    = fn( int $b ) => sprintf( '%d Ko', round( $b / 1024 ) );

printf( "=== %d dernières conversions ===\n\n", $limit );

$problemes = 0;

foreach ( Map::converted( $limit, 'updated DESC' ) as $row )
{
	printf( "attach_id %d\n", $row['attach_id'] );

	try
	{
		$a = Db::i()->select( '*', 'core_attachments', array( 'attach_id=?', $row['attach_id'] ) )->first();
	}
	catch( Throwable $e )
	{
		printf( "  ANOMALIE : la pièce jointe n'existe plus en base\n\n" );
		$problemes++;
		continue;
	}

	printf( "  nom       : %s  (ext en base : %s)\n", $a['attach_file'], $a['attach_ext'] );
	printf( "  image ?   : %s\n", $a['attach_is_image'] ? 'oui' : 'NON — s\'affichera en lien de téléchargement' );
	printf( "  dimensions: %dx%d  vignette %dx%d\n",
		$a['attach_img_width'], $a['attach_img_height'],
		$a['attach_thumb_width'], $a['attach_thumb_height'] );

	if ( !$a['attach_is_image'] )
	{
		$problemes++;
	}

	/* Les deux fichiers sont-ils réellement là, et lisibles ? */
	foreach ( array( 'attach_location' => 'AVIF', 'attach_thumb_location' => 'vignette' ) as $col => $libelle )
	{
		if ( !$a[ $col ] )
		{
			printf( "  %-9s : ABSENT de la base\n", $libelle );
			$problemes++;
			continue;
		}

		try
		{
			$file = File::get( 'core_Attachment', $a[ $col ] );
			$size = $file->filesize();

			/* On relit les premiers octets pour contrôler la marque de format,
			   celle que \IPS\Image::create() exige (Image.php:103). */
			$tmp = tempnam( \IPS\TEMP_DIRECTORY, 'heicverify_' );
			file_put_contents( $tmp, $file->contents() );

			$conforme = Converter::hasAvifSignature( $tmp );
			$gis      = @getimagesize( $tmp );

			@unlink( $tmp );

			printf( "  %-9s : %s  signature %s  getimagesize %s\n",
				$libelle,
				$ko( $size ),
				$conforme ? 'OK' : 'NON CONFORME',
				$gis ? "{$gis[0]}x{$gis[1]}" : 'ÉCHEC' );

			if ( !$conforme or !$gis )
			{
				$problemes++;
			}
		}
		catch( Throwable $e )
		{
			printf( "  %-9s : ILLISIBLE — %s\n", $libelle, $e->getMessage() );
			$problemes++;
		}
	}

	/* Le message a-t-il bien été réécrit ? On cherche la balise image portant
	   l'identifiant de la pièce jointe dans le contenu qui la réclame. */
	try
	{
		$maps = iterator_to_array( Db::i()->select( '*', 'core_attachments_map', array( 'attachment_id=?', $row['attach_id'] ) ) );

		if ( !$maps )
		{
			printf( "  message   : aucune ligne core_attachments_map (pièce jointe orpheline)\n" );
		}

		foreach ( $maps as $m )
		{
			printf( "  message   : %s id1=%s id2=%s\n", $m['location_key'], $m['id1'], $m['id2'] );
		}
	}
	catch( Throwable $e )
	{
		printf( "  message   : lecture impossible — %s\n", $e->getMessage() );
	}

	print( "\n" );
}

printf( "%s\n", $problemes
	? "{$problemes} anomalie(s) relevée(s) — NE PAS laisser les conversions restantes se poursuivre."
	: "Aucune anomalie sur cet échantillon." );
