<?php
/**
 * @brief		Tâche : détection des pièces jointes HEIC
 * @package		HEIC Uploads
 */

namespace IPS\heicuploads\tasks;

use IPS\Db;
use IPS\heicuploads\Application as HeicAvifApplication;
use IPS\Log;
use IPS\Settings;
use IPS\Task;
use IPS\Task\Exception as TaskException;
use Throwable;
use function count;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Détection des pièces jointes HEIC à convertir.
 *
 * C'est la voie normale, et elle repose sur une fenêtre de temps : le membre
 * dépose ses photos par requête AJAX, puis rédige son message avant de le
 * valider. La conversion doit tenir dans cet intervalle, car le HTML du
 * message est figé à la publication — une pièce jointe encore en HEIC à cet
 * instant y sera écrite comme lien de téléchargement, définitivement
 * (system/Helpers/Form/Editor.php:414-450).
 *
 * D'où la fréquence d'une minute : elle couvre la quasi-totalité des cas. Les
 * messages validés trop vite sont rattrapés par la réécriture du HTML.
 *
 * Le sondage de core_attachments est délibéré : le callback d'envoi de
 * l'éditeur est écrit en dur dans le coeur (Editor.php:662-680) et aucun
 * événement n'est déclenché par \IPS\File. Il n'existe donc aucun point
 * d'accroche pour être prévenu d'un dépôt de fichier.
 */
class scanHeic extends Task
{
	/**
	 * @brief	Nombre de pièces jointes examinées par passage
	 */
	protected const BATCH = 100;

	/**
	 * Exécution
	 *
	 * @return	mixed	Message à journaliser, ou NULL
	 * @throws	TaskException
	 */
	public function execute() : mixed
	{
		if ( !Settings::i()->heicuploads_enabled )
		{
			return NULL;
		}

		/* Un serveur devenu incapable de convertir ne doit pas accumuler des
		   lignes en attente indéfiniment. */
		if ( !HeicAvifApplication::isOperational() )
		{
			return NULL;
		}

		try
		{
			/* Repère haut plutôt que filtrage a posteriori.
			   La version précédente prenait toujours les BATCH plus anciennes
			   puis écartait celles déjà connues : une fois les 100 premières
			   enregistrées, la fenêtre ne bougeait plus et les suivantes
			   n'étaient jamais atteintes. attach_id étant auto-incrémenté,
			   un simple repère haut fait avancer le balayage et coûte une
			   requête triviale. */
			$lastId = (int) ( Db::i()->select( 'MAX(attach_id)', 'heicuploads_map' )->first() ?: 0 );

			/* Repère posé à l'installation : rien d'antérieur n'est jamais
			   converti. Sans lui, la tâche remonterait toute la table et
			   reprendrait des photos vieilles de plusieurs années, alors que
			   la conversion détruit l'original. La reprise du passé est un
			   chantier distinct, à décider explicitement. */
			$baseline = (int) ( Settings::i()->heicuploads_baseline_id ?: 0 );
			$lastId   = max( $lastId, $baseline );

			$candidates = iterator_to_array( Db::i()->select(
				'attach_id',
				'core_attachments',
				array(
					array( Db::i()->in( 'attach_ext', array( 'heic', 'heif' ) ) ),
					array( 'attach_id>?', $lastId ),
				),
				'attach_id ASC',
				array( 0, static::BATCH )
			) );
		}
		catch( Throwable $e )
		{
			throw new TaskException( $this, $e->getMessage() );
		}

		if ( !count( $candidates ) )
		{
			return NULL;
		}

		$added = 0;

		foreach ( $candidates as $attachId )
		{
			try
			{
				/* L'index UNIQUE sur attach_id rend l'insertion idempotente :
				   deux passages concurrents ne créeront pas de doublon. */
				Db::i()->insert( 'heicuploads_map', array(
					'attach_id' => $attachId,
					'status'    => 'pending',
					'attempts'  => 0,
					'created'   => time(),
					'updated'   => time(),
				), TRUE );

				$added++;
			}
			catch( Throwable $e )
			{
				Log::log( "HEIC vers AVIF : impossible d'enregistrer la pièce jointe {$attachId} — " . $e->getMessage(), 'heicuploads' );
			}
		}

		if ( !$added )
		{
			return NULL;
		}

		/* Mise en file. La clé de déduplication évite d'empiler plusieurs
		   travaux concurrents qui se disputeraient les mêmes lignes. */
		try
		{
			Task::queue( 'heicuploads', 'Convert', array(), 3, array( 'convert' ) );
		}
		catch( Throwable $e )
		{
			Log::log( "HEIC vers AVIF : mise en file impossible — " . $e->getMessage(), 'heicuploads' );
		}

		return "{$added} pièce(s) jointe(s) HEIC mise(s) en file de conversion";
	}
}
