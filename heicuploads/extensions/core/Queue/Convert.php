<?php
/**
 * @brief		File d'attente : conversion HEIC vers AVIF
 * @package		HEIC Uploads
 */

namespace IPS\heicuploads\extensions\core\Queue;

use Exception;
use IPS\Db;
use IPS\Extensions\QueueAbstract;
use IPS\File;
use IPS\heicuploads\Converter;
use IPS\heicuploads\Rewriter;
use IPS\Member;
use IPS\Log;
use IPS\Settings;
use OutOfRangeException;
use Throwable;
use function defined;
use const IPS\REBUILD_INTENSE;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Conversion en tâche de fond.
 *
 * Une conversion coûte environ 1,9 s et deux threads ImageMagick. On n'en
 * traite donc qu'UNE par passage : le parallélisme d'ImageMagick réduit la
 * latence d'une conversion mais n'augmente pas le débit, et monopoliser la
 * machine pénaliserait le reste du forum.
 */
class Convert extends QueueAbstract
{
	/**
	 * @brief	Nombre d'éléments par cycle
	 */
	public int $rebuild = REBUILD_INTENSE;

	/**
	 * @brief	Tentatives avant abandon définitif d'une conversion
	 *
	 * Constante et non réglage : personne n'a jamais eu besoin d'ajuster ça,
	 * et un plafond est indispensable pour garantir que la file se termine
	 * même si un fichier échoue systématiquement.
	 */
	protected const MAX_ATTEMPTS = 3;

	/**
	 * Préparer les données avant mise en file
	 *
	 * @param	array	$data	Données
	 * @return	array|null
	 * @throws	OutOfRangeException	S'il n'y a rien à convertir
	 */
	public function preQueueData( array $data ): ?array
	{
		try
		{
			$data['count'] = Db::i()->select( 'COUNT(*)', 'heicuploads_map', array( 'status=?', 'pending' ) )->first();
		}
		catch( Exception )
		{
			throw new OutOfRangeException;
		}

		if ( !$data['count'] )
		{
			throw new OutOfRangeException;
		}

		return $data;
	}

	/**
	 * Convertir un fichier.
	 *
	 * Aucune exception ne doit sortir d'ici autrement que par
	 * \IPS\Task\Queue\OutOfRangeException : un échec de conversion se solde
	 * par un statut « failed » et une trace, jamais par une file bloquée.
	 *
	 * @param	array	$data	Données
	 * @param	int		$offset	Position
	 * @return	int				Nouvelle position
	 * @throws	\IPS\Task\Queue\OutOfRangeException	Quand tout est traité
	 */
	public function run( array &$data, int $offset ): int
	{
		/* L'interrupteur d'activation arrête AUSSI la file, pas seulement la
		   détection. Sans ce contrôle, couper le réglage laissait se dérouler
		   jusqu'au bout les éléments déjà en file — donc continuer à détruire
		   des originaux alors que l'administrateur croyait avoir tout arrêté. */
		if ( !Settings::i()->heicuploads_enabled )
		{
			throw new \IPS\Task\Queue\OutOfRangeException;
		}

		$maxAttempts = static::MAX_ATTEMPTS;

		/* On ne sélectionne pas par offset : le statut des lignes change au
		   fil de l'eau, une pagination serait incohérente. On prend la plus
		   ancienne ligne encore convertible. Le plafond de tentatives garantit
		   la terminaison même si un fichier échoue systématiquement. */
		try
		{
			$row = Db::i()->select(
				'*',
				'heicuploads_map',
				array( 'status=? AND attempts<?', 'pending', $maxAttempts ),
				'created ASC',
				array( 0, 1 )
			)->first();
		}
		catch( Throwable $e )
		{
			throw new \IPS\Task\Queue\OutOfRangeException;
		}

		/* Tentative comptabilisée AVANT le travail : si le processus est tué
		   en cours de conversion (dépassement mémoire, par exemple), la ligne
		   ne sera pas rejouée indéfiniment. */
		Db::i()->update(
			'heicuploads_map',
			array( 'attempts' => $row['attempts'] + 1, 'updated' => time() ),
			array( 'id=?', $row['id'] )
		);

		try
		{
			$this->convertRow( $row );
		}
		catch( Throwable $e )
		{
			$this->markFailed( $row, $e, $maxAttempts );
		}

		return $offset + 1;
	}

	/**
	 * Convertir une ligne de correspondance
	 *
	 * @param	array	$row	Ligne de heicuploads_map
	 * @return	void
	 * @throws	Throwable
	 */
	protected function convertRow( array $row ) : void
	{
		$attachment = Db::i()->select( '*', 'core_attachments', array( 'attach_id=?', $row['attach_id'] ) )->first();

		$source = File::get( 'core_Attachment', $attachment['attach_location'] );

		/* On passe par un fichier temporaire quel que soit le mode de
		   stockage : c'est le seul chemin qui vaille aussi bien en local
		   qu'en distant. Le coût est modeste, il s'agit du HEIC compressé
		   (quelques mégaoctets) et non de l'image décompressée. */
		$heicTmp = tempnam( \IPS\TEMP_DIRECTORY, 'heicuploads_src_' );
		file_put_contents( $heicTmp, $source->contents() );

		$avifTmp  = tempnam( \IPS\TEMP_DIRECTORY, 'heicuploads_out_' );
		$thumbTmp = tempnam( \IPS\TEMP_DIRECTORY, 'heicuploads_thb_' );

		try
		{
			/* Un seul appel, un seul décodage du HEIC : l'AVIF et la vignette
			   sortent des mêmes pixels. */
			$result = static::converter()->process( $heicTmp, $avifTmp, $thumbTmp, ...static::thumbnailDimensions() );

			$stats = $result['avif'];
			$thumb = $result['thumb'];

			/* Radical commun aux deux fichiers : le nom d'origine sans son
			   extension. \IPS\File y ajoutera son propre suffixe anti-collision. */
			$base = pathinfo( $attachment['attach_file'], PATHINFO_FILENAME );

			$avif = File::create( 'core_Attachment', $base . '.avif', NULL, $source->container, TRUE, $avifTmp, TRUE );
			$thumbFile = File::create( 'core_Attachment', $base . '.thumb.' . $thumb['format'], NULL, $source->container, TRUE, $thumb['path'], TRUE );

			/* Bascule de la pièce jointe sur l'AVIF. attach_is_image=1 est ce
			   qui conditionne la reprise par le coeur dans les listes, les flux
			   d'activité et les résultats de recherche. */
			/* attach_file doit suivre, sinon le fichier reste annoncé « .heic »
			   partout où le coeur l'affiche. Ce n'est pas cosmétique :
			   File::isImage() teste l'extension de CE nom (File.php:1432), si
			   bien qu'un attachement converti restait vu comme un fichier
			   quelconque — icône générique et pas d'aperçu dans la liste des
			   pièces jointes de l'éditeur. */
			Db::i()->update( 'core_attachments', array(
				'attach_file'          => $base . '.avif',
				'attach_is_image'      => 1,
				'attach_location'      => (string) $avif,
				'attach_thumb_location'=> (string) $thumbFile,
				'attach_thumb_width'   => $thumb['width'],
				'attach_thumb_height'  => $thumb['height'],
				'attach_img_width'     => $stats['width'],
				'attach_img_height'    => $stats['height'],
				'attach_ext'           => 'avif',
				'attach_filesize'      => $stats['filesize'],
			), array( 'attach_id=?', $row['attach_id'] ) );

			Db::i()->update( 'heicuploads_map', array(
				'status'        => 'converted',
				'error_message' => NULL,
				'updated'       => time(),
			), array( 'id=?', $row['id'] ) );

			/* Voie de rattrapage. Si le membre a validé son message avant la
			   fin de la conversion, le HTML publié porte un lien de
			   téléchargement figé qu'aucune mise à jour de core_attachments
			   ne corrigera. En marche normale la conversion s'est terminée
			   pendant la rédaction, et rewrite() ne trouve rien à faire. */
			try
			{
				Rewriter::rewrite( (int) $row['attach_id'] );
			}
			catch( Throwable $e )
			{
				/* Un échec de réécriture laisse un lien au lieu d'une image :
				   c'est regrettable, jamais bloquant. La conversion, elle,
				   est acquise. */
				Log::log( "HEIC vers AVIF : réécriture du message échouée pour la pièce jointe {$row['attach_id']} — " . $e->getMessage(), 'heicuploads' );
			}

			/* Le HEIC d'origine n'est supprimé qu'une fois l'AVIF écrit ET la
			   base à jour : à aucun moment il n'existe de fenêtre où la photo
			   ne serait nulle part. Passé ce point elle est perdue — c'est le
			   choix assumé de ne pas conserver d'archive. */
			try
			{
				$source->delete();
			}
			catch( Exception $e )
			{
				Log::log( "HEIC vers AVIF : original non supprimé pour la pièce jointe {$row['attach_id']} — " . $e->getMessage(), 'heicuploads' );
			}
		}
		finally
		{
			foreach ( array( $heicTmp, $avifTmp, $thumbTmp, $thumbTmp . '.avif' ) as $tmp )
			{
				if ( is_file( $tmp ) )
				{
					@unlink( $tmp );
				}
			}
		}
	}

	/**
	 * Consigner un échec
	 *
	 * @param	array		$row			Ligne concernée
	 * @param	Throwable	$e				Erreur rencontrée
	 * @param	int			$maxAttempts	Plafond de tentatives
	 * @return	void
	 */
	protected function markFailed( array $row, Throwable $e, int $maxAttempts ) : void
	{
		/* La ligne ne bascule en « failed » qu'une fois le plafond atteint :
		   en deçà, elle reste « pending » et sera rejouée, ce qui absorbe les
		   incidents passagers (verrou, mémoire momentanément saturée). */
		$definitive = ( $row['attempts'] + 1 ) >= $maxAttempts;

		Db::i()->update( 'heicuploads_map', array(
			'status'        => $definitive ? 'failed' : 'pending',
			'error_message' => mb_substr( $e->getMessage(), 0, 1000 ),
			'updated'       => time(),
		), array( 'id=?', $row['id'] ) );

		Log::log(
			sprintf(
				"HEIC vers AVIF : échec sur la pièce jointe %d (tentative %d/%d)%s — %s",
				$row['attach_id'], $row['attempts'] + 1, $maxAttempts,
				$definitive ? ', abandon' : '', $e->getMessage()
			),
			'heicuploads'
		);
	}

	/**
	 * Construire le moteur depuis les réglages
	 *
	 * Les dimensions maximales viennent du réglage NATIF
	 * attachment_resample_size (system/Helpers/Form/Editor.php:683-686), pour
	 * que nos conversions respectent le même gabarit que le reste du forum.
	 * Il n'y a délibérément PAS de réglage concurrent dans l'application : deux
	 * valeurs pour la même chose finissent toujours par diverger.
	 *
	 * @return	Converter
	 */
	public static function converter() : Converter
	{
		/* Repli si l'administrateur n'a jamais configuré le redimensionnement
		   natif : sans limite, on produirait des AVIF en 4032 px. */
		$maxWidth  = 2048;
		$maxHeight = 2048;

		if ( Settings::i()->attachment_resample_size )
		{
			$native = explode( 'x', Settings::i()->attachment_resample_size );

			if ( !empty( $native[0] ) and !empty( $native[1] ) )
			{
				$maxWidth  = (int) $native[0];
				$maxHeight = (int) $native[1];
			}
		}

		return new Converter(
			$maxWidth,
			$maxHeight,
			(int) ( Settings::i()->heicuploads_quality ?: 65 ),
			(int) ( Settings::i()->heicuploads_speed ?: 9 ),
			(int) ( Settings::i()->heicuploads_threads ?: 2 ),
			(string) ( Settings::i()->heicuploads_filter ?: Converter::FILTER_DEFAULT )
		);
	}

	/**
	 * Dimensions de vignette attendues par le coeur
	 *
	 * Reprend attachment_image_size, le réglage que \IPS\File utilise
	 * lui-même pour les vignettes de pièces jointes (File.php:1400).
	 *
	 * @return	array	maxWidth, maxHeight, quality
	 */
	public static function thumbnailDimensions() : array
	{
		$dims = Settings::i()->attachment_image_size ? explode( 'x', Settings::i()->attachment_image_size ) : array( 1000, 750 );

		return array(
			(int) ( $dims[0] ?: 1000 ),
			(int) ( $dims[1] ?? 750 ) ?: 750,
			(int) ( Settings::i()->heicuploads_thumb_quality ?: 25 ),
		);
	}

	/**
	 * Progression
	 *
	 * @param	array	$data	Données
	 * @param	int		$offset	Position
	 * @return	array
	 */
	public function getProgress( array $data, int $offset ): array
	{
		/* addToStack plutôt que Lang::load( defaultLanguage ) : le libellé suit
		   la langue de l'administrateur qui regarde, pas celle du forum. */
		return array(
			'text'     => Member::loggedIn()->language()->addToStack( 'heicuploads_queue_progress' ),
			'complete' => $data['count'] ? round( ( $offset / $data['count'] ) * 100, 2 ) : 100,
		);
	}
}
