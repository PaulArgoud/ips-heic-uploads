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
use IPS\heicuploads\Map;
use IPS\heicuploads\Rewriter;
use IPS\Member;
use IPS\Log;
use IPS\Settings;
use OutOfRangeException;
use RuntimeException;
use Throwable;
use UnderflowException;
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
			/* Les lignes réellement convertibles, plafond de tentatives
			   compris : une ligne abandonnée ne doit pas faire créer un
			   travail qui n'aurait rien à traiter. */
			$data['count'] = Map::countConvertible();
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

		$row = Map::nextConvertible();

		if ( $row === NULL )
		{
			throw new \IPS\Task\Queue\OutOfRangeException;
		}

		Map::beginAttempt( $row );

		try
		{
			$this->convertRow( $row );
		}
		catch( UnderflowException $e )
		{
			/* La pièce jointe n'existe plus. Le membre l'a retirée de
			   l'éditeur, ou le message a été supprimé avant que la file n'y
			   arrive. Aucune reprise ne la fera réapparaître : la rejouer
			   trois fois ne ferait que retarder le même constat, et
			   l'UnderflowException de Select::first() a un message VIDE —
			   la ligne finissait « en échec » sans rien dire. */
			Map::abandon( $row, "The attachment no longer exists in core_attachments: removed by the member, or the post was deleted." );

			Log::log( "HEIC Uploads: attachment {$row['attach_id']} is gone; row closed without another attempt", 'heicuploads' );
		}
		catch( Throwable $e )
		{
			$this->markFailed( $row, $e );
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

		/* Garde-fou : une pièce jointe qui n'est plus en HEIC ne doit JAMAIS
		   repasser par ici. Le cas se produit dès qu'un blocage est relancé
		   depuis l'AdminCP : si le processus avait été tué entre la mise à
		   jour de core_attachments et le marquage de notre table, la ligne est
		   restée « en attente » alors que la photo est déjà convertie.
		   Reconvertir ne se contenterait pas de recompresser une deuxième
		   fois : File::create() écrit un NOUVEAU nom et l'ancien est supprimé,
		   alors que le HTML du message porte l'ancien chemin en dur — le
		   membre verrait une image morte à la place de sa photo.
		   La détection ne retient que les extensions de Converter, toute autre
		   valeur signifie que la conversion a eu lieu. */
		if ( !Converter::isSource( (string) $attachment['attach_file'] ) )
		{
			/* Régulariser ne suffit pas : si le processus est mort avant le
			   marquage, il est mort AVANT la réécriture du message. Marquer
			   « converted » sans la rejouer figerait un lien de téléchargement
			   dans le message pour toujours — plus rien ne relit une ligne
			   convertie. La réécriture est idempotente : elle ne trouve rien à
			   faire si le message porte déjà l'image. */
			$this->rewrite( $row );

			Map::markConverted( $row );

			Log::log(
				"HEIC Uploads: attachment {$row['attach_id']} already converted (\"{$attachment['attach_file']}\"); row reconciled without re-converting",
				'heicuploads'
			);

			return;
		}

		$source = File::get( 'core_Attachment', $attachment['attach_location'] );

		/* Le conteneur est capturé MAINTENANT : il sert aux dérivés, mais
		   $source sera relâché bien avant, pour libérer la mémoire. Les
		   dérivés vont dans le répertoire mensuel de la photo d'origine, pas
		   dans le mois courant. */
		$container = $source->container;

		/* On passe par un fichier temporaire quel que soit le mode de
		   stockage : c'est le seul chemin qui vaille aussi bien en local
		   qu'en distant. Le coût est modeste, il s'agit du HEIC compressé
		   (quelques mégaoctets) et non de l'image décompressée. */
		$contents = $source->contents();

		$heicTmp  = tempnam( \IPS\TEMP_DIRECTORY, 'heicuploads_src_' );
		$avifTmp  = tempnam( \IPS\TEMP_DIRECTORY, 'heicuploads_out_' );
		$thumbTmp = tempnam( \IPS\TEMP_DIRECTORY, 'heicuploads_thb_' );

		if ( $heicTmp === FALSE or $avifTmp === FALSE or $thumbTmp === FALSE )
		{
			throw new RuntimeException( 'Cannot create a temporary file in ' . \IPS\TEMP_DIRECTORY );
		}

		$avif = $thumbFile = NULL;

		try
		{
			/* Écriture vérifiée, parce que l'original sera DÉTRUIT au bout de
			   cette méthode. Un disque plein ou un quota atteint fait écrire
			   file_put_contents() partiellement et rendre le nombre d'octets
			   écrits, sans exception : le gestionnaire d'erreurs d'IPS ignore
			   E_WARNING (init.php:796-799), l'avertissement n'est donc ni
			   converti ni journalisé. Sans ce contrôle, un HEIC tronqué
			   pouvait être converti en une image tronquée, puis l'original
			   supprimé. */
			$written = file_put_contents( $heicTmp, $contents );

			if ( $written === FALSE or $written !== strlen( $contents ) )
			{
				throw new RuntimeException( sprintf(
					'Incomplete local copy: %s bytes written out of %d expected.',
					var_export( $written, TRUE ),
					strlen( $contents )
				) );
			}

			/* DEUX références désignent la même chaîne : la variable locale ET
			   la propriété interne de l'objet \IPS\File, où contents() met le
			   fichier en cache (system/File/FileSystem.php:270-290, propriété
			   déclarée File.php:785). L'unset() de la seule variable locale ne
			   libérait donc rien : le HEIC complet restait résident pendant
			   tout le décodage et les deux File::create(), au moment précis où
			   ImageMagick réclame le plus de mémoire. Il faut lâcher les deux.
			   L'objet sera repris plus bas, au moment de la suppression. */
			$contents = NULL;
			$source   = NULL;

			/* Un seul appel, un seul décodage du HEIC : l'AVIF et la vignette
			   sortent des mêmes pixels. */
			$result = static::converter()->process( $heicTmp, $avifTmp, $thumbTmp, ...static::thumbnailDimensions() );

			$stats = $result['avif'];
			$thumb = $result['thumb'];

			/* Radical commun aux deux fichiers : le nom d'origine sans son
			   extension. \IPS\File y ajoutera son propre suffixe anti-collision. */
			$base = pathinfo( $attachment['attach_file'], PATHINFO_FILENAME );

			$avif      = File::create( 'core_Attachment', $base . '.avif', NULL, $container, TRUE, $avifTmp, TRUE );
			$thumbFile = File::create( 'core_Attachment', $base . '.thumb.' . $thumb['format'], NULL, $container, TRUE, $thumb['path'], TRUE );

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

			/* Passé cette mise à jour, les fichiers produits sont RÉFÉRENCÉS :
			   les détruire au rattrapage casserait la pièce jointe. */
			$avif = $thumbFile = NULL;

			/* Voie de rattrapage. Si le membre a validé son message avant la
			   fin de la conversion, le HTML publié porte un lien de
			   téléchargement figé qu'aucune mise à jour de core_attachments
			   ne corrigera. En marche normale la conversion s'est terminée
			   pendant la rédaction, et rewrite() ne trouve rien à faire. */
			$this->rewrite( $row );

			/* Le HEIC d'origine n'est supprimé qu'une fois l'AVIF écrit ET la
			   base à jour : à aucun moment il n'existe de fenêtre où la photo
			   ne serait nulle part. Passé ce point elle est perdue — c'est le
			   choix assumé de ne pas conserver d'archive. */
			try
			{
				/* Repris ici, l'objet ayant été relâché plus haut pour libérer
				   la copie en mémoire. $attachment a été lu AVANT la bascule et
				   porte donc encore l'ancien emplacement, celui du HEIC : la
				   mise à jour de core_attachments n'a pas touché ce tableau. */
				File::get( 'core_Attachment', $attachment['attach_location'] )->delete();
			}
			catch( Exception $e )
			{
				Log::log( "HEIC Uploads: original not deleted for attachment {$row['attach_id']} — " . $e->getMessage(), 'heicuploads' );
			}

			/* Marquage en DERNIER, délibérément : « converted » veut dire
			   « convertie ET réécrite ET original supprimé ». Toute
			   interruption avant ce point laisse la ligne « en attente » avec
			   un nom de fichier qui n'est plus heic — état que le garde-fou du
			   haut sait régulariser, réécriture comprise. L'inverse, marquer
			   d'abord, produisait une ligne « convertie » dont le message
			   pouvait n'avoir jamais été réécrit, et que plus rien ne
			   reprenait. */
			Map::markConverted( $row );
		}
		finally
		{
			/* Les dérivés créés mais jamais référencés sont détruits : sans
			   cela, chaque tentative en échec après File::create() laissait
			   deux fichiers orphelins de plus dans le stockage — le nom porte
			   un suffixe aléatoire de 32 caractères (File.php:1056), donc
			   jamais réutilisé, et plus rien ne les désigne. */
			foreach ( array( $avif, $thumbFile ) as $orphan )
			{
				if ( $orphan !== NULL )
				{
					try
					{
						$orphan->delete();
					}
					catch( Exception $e )
					{
						Log::log( "HEIC Uploads: orphaned derivative not deleted for attachment {$row['attach_id']} — " . $e->getMessage(), 'heicuploads' );
					}
				}
			}

			foreach ( array( $heicTmp, $avifTmp, $thumbTmp ) as $tmp )
			{
				if ( is_file( $tmp ) )
				{
					@unlink( $tmp );
				}
			}
		}
	}

	/**
	 * Réécrire le HTML des messages qui portent cette pièce jointe.
	 *
	 * Isolé parce que DEUX chemins en ont besoin : la conversion normale, et
	 * la régularisation d'une ligne dont la conversion s'était interrompue
	 * après la mise à jour de core_attachments. Les avoir laissés diverger
	 * aurait figé un lien de téléchargement dans le message du membre.
	 *
	 * @param	array	$row	Ligne de heicuploads_map
	 * @return	void
	 */
	protected function rewrite( array $row ) : void
	{
		try
		{
			Rewriter::rewrite( (int) $row['attach_id'] );
		}
		catch( Throwable $e )
		{
			/* Un échec de réécriture laisse un lien au lieu d'une image :
			   c'est regrettable, jamais bloquant. La conversion, elle,
			   est acquise. */
			Log::log( "HEIC Uploads: post rewrite failed for attachment {$row['attach_id']} — " . $e->getMessage(), 'heicuploads' );
		}
	}

	/**
	 * Consigner un échec
	 *
	 * @param	array		$row	Ligne concernée
	 * @param	Throwable	$e		Erreur rencontrée
	 * @return	void
	 */
	protected function markFailed( array $row, Throwable $e ) : void
	{
		$definitive = Map::markFailed( $row, $e->getMessage() );

		Log::log(
			sprintf(
				"HEIC Uploads: failure on attachment %d (attempt %d/%d)%s — %s",
				$row['attach_id'], $row['attempts'] + 1, Map::MAX_ATTEMPTS,
				$definitive ? ', giving up' : '', $e->getMessage()
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

		/* ?? et non ?: — le formulaire autorise 0 pour la qualité comme pour la
		   vitesse, et « ?: » les traitait comme non renseignés : régler la
		   vitesse à 0, la plus lente et la plus dense, appliquait 9. Un
		   administrateur pouvait donc croire avoir choisi l'encodage le plus
		   soigné tout en obtenant le plus rapide. Le repli ne doit couvrir que
		   le réglage réellement absent — Settings rend NULL dans ce cas. */
		return new Converter(
			$maxWidth,
			$maxHeight,
			(int) ( Settings::i()->heicuploads_quality ?? 65 ),
			(int) ( Settings::i()->heicuploads_speed ?? 9 ),
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
			/* ?? et non ?: : la qualité 0 est proposée par le formulaire. */
			(int) ( Settings::i()->heicuploads_thumb_quality ?? 25 ),
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
		/* Le total est recalculé À L'AFFICHAGE, et non repris du $data['count']
		   figé à la mise en file. Deux raisons, toutes deux ordinaires :
		   run() rend $offset + 1 sur TOUS les chemins, y compris après un échec
		   rejoué — une même ligne avance donc l'offset de trois ; et
		   scanHeic::queue() refuse de créer un second travail tant qu'une ligne
		   core_queue existe, si bien que le travail en cours absorbe les photos
		   détectées APRÈS sa création. Le rapport dépassait alors 100 % : un
		   membre qui poste cinq photos à cheval sur deux passages de la
		   détection suffisait. $data['count'] garde son rôle dans
		   preQueueData() : décider s'il y a lieu de créer un travail. */
		try
		{
			$remaining = Map::countConvertible();
		}
		catch( Exception )
		{
			/* Un compte indisponible ne doit pas faire tomber l'écran des
			   processus de fond : on affiche « terminé » plutôt que rien. */
			$remaining = 0;
		}

		$total = $offset + $remaining;

		/* addToStack plutôt que Lang::load( defaultLanguage ) : le libellé suit
		   la langue de l'administrateur qui regarde, pas celle du forum. */
		return array(
			'text'     => Member::loggedIn()->language()->addToStack( 'heicuploads_queue_progress' ),
			'complete' => $total ? round( ( $offset / $total ) * 100, 2 ) : 100,
		);
	}
}
