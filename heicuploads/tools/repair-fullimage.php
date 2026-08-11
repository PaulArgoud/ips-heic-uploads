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
 * Répare les messages déjà réécrits par une version antérieure.
 *
 * Le jeton de stockage était resté en accolades dans data-full-image, si bien
 * que la vignette s'affichait mais que le clic menait à une URL littérale
 * « {fileStore.core_Attachment}/… ».
 *
 * À lancer depuis la RACINE du forum :
 *     php applications/heicuploads/tools/repair-fullimage.php          (simulation)
 *     php applications/heicuploads/tools/repair-fullimage.php --ecrire (applique)
 */

$root = realpath( __DIR__ . '/../../..' );

if ( !is_file( $root . '/init.php' ) )
{
	fwrite( STDERR, "init.php introuvable. Lancez ce script depuis la racine du forum.\n" );
	exit( 1 );
}

require_once $root . '/init.php';

use IPS\Application;
use IPS\Content;
use IPS\Db;
use IPS\heicuploads\Rewriter;

$ecrire = in_array( '--ecrire', $argv );

printf( "%s\n\n", $ecrire ? "=== ÉCRITURE ===" : "=== SIMULATION (ajoutez --ecrire pour appliquer) ===" );

$vus = $corriges = 0;

/* On repart des conversions connues : chacune a pu donner lieu à une
   réécriture, et donc à un data-full-image mal formé. */
foreach ( Db::i()->select( 'attach_id', 'heicuploads_map', array( 'status=?', 'converted' ), 'attach_id ASC' ) as $attachId )
{
	foreach ( Db::i()->select( '*', 'core_attachments_map', array( 'attachment_id=?', $attachId ) ) as $map )
	{
		$exploded = explode( '_', $map['location_key'] );

		if ( count( $exploded ) < 2 )
		{
			continue;
		}

		try
		{
			$extensions = Application::load( $exploded[0] )->extensions( 'core', 'EditorLocations' );

			if ( !isset( $extensions[ $exploded[1] ] ) )
			{
				continue;
			}

			$object = $extensions[ $exploded[1] ]->attachmentLookup( $map['id1'], $map['id2'], $map['id3'] );

			if ( !( $object instanceof Content ) )
			{
				continue;
			}

			$class  = $object::class;
			$column = $class::$databaseColumnMap['content'] ?? NULL;

			if ( $column === NULL )
			{
				continue;
			}

			if ( is_array( $column ) )
			{
				$column = reset( $column );
			}

			$table    = $class::$databaseTable;
			$prefix   = $class::$databasePrefix;
			$idColumn = $class::$databaseColumnId;

			$row      = Db::i()->select( '*', $table, array( $prefix . $idColumn . '=?', $object->$idColumn ) )->first();
			$original = $row[ $prefix . $column ];
			$corrige  = Rewriter::restoreDataFullImage( Rewriter::restoreEscapedTokens( $original ) );

			$vus++;

			if ( $corrige === $original )
			{
				continue;
			}

			printf( "  %s #%s (pièce jointe %d)\n", $table, $object->$idColumn, $attachId );

			if ( $ecrire )
			{
				Db::i()->update(
					$table,
					array( $prefix . $column => $corrige ),
					array( $prefix . $idColumn . '=?', $object->$idColumn )
				);
			}

			$corriges++;
		}
		catch( Throwable $e )
		{
			printf( "  ANOMALIE pièce jointe %d : %s\n", $attachId, $e->getMessage() );
		}
	}
}

printf( "\n%d contenu(s) examiné(s), %d %s.\n",
	$vus,
	$corriges,
	$ecrire ? "corrigé(s)" : "à corriger" );
