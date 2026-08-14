<?php
/**
 * Répare les messages déjà réécrits par une version antérieure.
 *
 * Le jeton de stockage était resté en accolades dans data-full-image, si bien
 * que la vignette s'affichait mais que le clic menait à une URL littérale
 * « {fileStore.core_Attachment}/… ».
 *
 * À lancer depuis la RACINE du forum :
 *     php applications/heicuploads/tools/repair-fullimage.php          (simulation)
 *     php applications/heicuploads/tools/repair-fullimage.php --write   (applique)
 */

require_once __DIR__ . '/_bootstrap.php';

use IPS\Db;
use IPS\heicuploads\Map;
use IPS\heicuploads\Rewriter;

$ecrire = ecritureDemandee( $argv );

printf( "%s\n\n", $ecrire ? "=== ÉCRITURE ===" : "=== SIMULATION (ajoutez --write pour appliquer) ===" );

$vus = $corriges = 0;

/* On repart des conversions connues : chacune a pu donner lieu à une
   réécriture, et donc à un data-full-image mal formé. */
foreach ( Map::converted() as $conversion )
{
	$attachId = (int) $conversion['attach_id'];

	foreach ( Db::i()->select( '*', 'core_attachments_map', array( 'attachment_id=?', $attachId ) ) as $map )
	{
		try
		{
			/* La résolution du contenu et la localisation de sa colonne HTML
			   sont empruntées au Rewriter, jamais recopiées : ce script en
			   avait sa propre version, et les deux avaient déjà divergé. Le
			   dernier argument fait la simulation — rien n'est écrit tant que
			   --write n'est pas passé. */
			$resultat = Rewriter::transform(
				$map,
				static fn( string $html ) : ?string => Rewriter::restoreDataFullImage( Rewriter::restoreEscapedTokens( $html ) ),
				$ecrire
			);

			/* Contenu supprimé, application désinstallée, ou emplacement qui
			   n'est pas du contenu (avatar, champ de profil) : rien à faire. */
			if ( $resultat === NULL )
			{
				continue;
			}

			$vus++;

			if ( !$resultat['changed'] )
			{
				continue;
			}

			printf( "  %s #%s (pièce jointe %d)\n", $resultat['table'], $resultat['id'], $attachId );

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
