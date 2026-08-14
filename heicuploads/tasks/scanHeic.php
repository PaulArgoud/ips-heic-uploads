<?php
/**
 * @brief		Tâche : détection des pièces jointes HEIC
 * @package		HEIC Uploads
 */

namespace IPS\heicuploads\tasks;

use IPS\Db;
use IPS\heicuploads\Application as HeicUploadsApplication;
use IPS\heicuploads\Converter;
use IPS\heicuploads\Map;
use IPS\Log;
use IPS\Settings;
use IPS\Task;
use IPS\Task\Exception as TaskException;
use Throwable;
use function count;
use function defined;
use function implode;

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

		$messages = array();

		/* Avant toute détection : fermer les tentatives interrompues.
		   Volontairement placé avant le contrôle d'aptitude du serveur — une
		   ligne abandonnée l'est déjà, et c'est justement quand le serveur ne
		   convertit plus qu'il faut que l'AdminCP dise la vérité. */
		if ( $closed = $this->closeAbandoned() )
		{
			$messages[] = $closed;
		}

		/* Un serveur devenu incapable de convertir ne doit pas accumuler des
		   lignes en attente indéfiniment. */
		if ( !HeicUploadsApplication::isOperational() )
		{
			return $this->summarise( $messages );
		}

		/* Repère de départ. NULL veut dire « jamais posé », et non « zéro » :
		   balayer à partir de zéro reprendrait des photos vieilles de
		   plusieurs années alors que la conversion détruit l'original. C'est
		   l'incident du 10/08/2026. On refuse plutôt que de deviner ; le bloc
		   d'état de l'AdminCP dit à l'administrateur quoi faire. */
		$baseline = HeicUploadsApplication::baseline();

		if ( $baseline === NULL )
		{
			/* Renvoyé, et non passé à Log::log(). Le retour d'une tâche EST
			   journalisé : Task::runAndLog() insère une ligne dans
			   core_tasks_log dès qu'il n'est pas NULL (system/Task/Task.php:314-322),
			   depuis system/Dispatcher/Standard.php:347-356. Tant que le repère
			   manque, cela fait donc bien une ligne par passage, jusqu'à 1 440
			   par jour — assumé, et c'est précisément pourquoi on n'écrit pas
			   dans core_log : le bloc d'état de l'AdminCP renvoie
			   l'administrateur aux journaux du système, catégorie
			   « heicuploads », où closeAbandoned() dépose le détail des échecs.
			   Une ligne par minute y rendrait cette catégorie inutilisable.
			   L'état ne dure que le temps de réinstaller, et l'AdminCP comme
			   tools/diagnose.php le disent en toutes lettres. */
			$messages[] = 'Baseline not set: detection suspended, no photo will be converted. Reinstall the application to set it.';

			return $this->summarise( $messages );
		}

		$messages = array_merge( $messages, $this->detect( $baseline ) );

		/* Mise en file INCONDITIONNELLE dès qu'il reste du convertible, et non
		   plus seulement quand on vient de détecter du nouveau. Le coeur
		   supprime la ligne core_queue dès que run() lève
		   \IPS\Task\Queue\OutOfRangeException (Task.php:146-150) — ce qu'il
		   fait notamment quand l'administrateur coupe puis rétablit le
		   réglage. Les lignes « en attente » restaient alors sans travail pour
		   les traiter, jusqu'au prochain envoi d'un membre. */
		$this->queue();

		return $this->summarise( $messages );
	}

	/**
	 * Détecter les pièces jointes à convertir.
	 *
	 * @param	int	$baseline	Repère de départ
	 * @return	array			Messages à journaliser
	 * @throws	TaskException
	 */
	protected function detect( int $baseline ) : array
	{
		try
		{
			/* Repère haut plutôt que filtrage a posteriori.
			   La version précédente prenait toujours les BATCH plus anciennes
			   puis écartait celles déjà connues : une fois les 100 premières
			   enregistrées, la fenêtre ne bougeait plus et les suivantes
			   n'étaient jamais atteintes. attach_id étant auto-incrémenté,
			   un simple repère haut fait avancer le balayage et coûte une
			   requête triviale. */
			$lastId = max( Map::highWaterMark(), $baseline );

			$candidates = iterator_to_array( Db::i()->select(
				'attach_id',
				'core_attachments',
				array(
					array( Db::i()->in( 'attach_ext', Converter::SOURCE_EXTENSIONS ) ),
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
			return array();
		}

		$added = 0;

		foreach ( $candidates as $attachId )
		{
			try
			{
				Map::record( (int) $attachId );

				$added++;
			}
			catch( Throwable $e )
			{
				Log::log( "HEIC Uploads: cannot record attachment {$attachId} — " . $e->getMessage(), 'heicuploads' );

				/* On INTERROMPT le lot au lieu de poursuivre. Le repère du
				   prochain passage est le plus grand attach_id ENREGISTRÉ :
				   continuer ferait passer l'identifiant en échec sous ce
				   repère, et il ne serait plus jamais candidat — la photo du
				   membre resterait un lien de téléchargement pour toujours.
				   S'arrêter ici le laisse au-dessus du repère, donc repris au
				   passage suivant. */
				break;
			}
		}

		return $added ? array( "{$added} HEIC attachment(s) queued for conversion" ) : array();
	}

	/**
	 * Fermer les tentatives interrompues.
	 *
	 * La tentative est comptabilisée AVANT le travail, délibérément : un
	 * fichier qui fait tomber le processus ne doit pas être rejoué sans fin.
	 * Mais si le processus meurt pendant la conversion — dépassement mémoire,
	 * redémarrage de PHP —, l'échec n'est jamais consigné. La ligne reste
	 * « en attente » avec son plafond épuisé : plus rien ne la rejoue, et le
	 * bloc d'état de l'AdminCP continue de l'annoncer comme normale.
	 *
	 * @return	string|null	Message à journaliser, ou NULL s'il n'y avait rien
	 */
	protected function closeAbandoned() : ?string
	{
		try
		{
			$abandoned = Map::closeAbandoned();

			if ( !count( $abandoned ) )
			{
				return NULL;
			}

			$message = count( $abandoned ) . ' abandoned conversion(s) marked as failed: attachments ' . implode( ', ', $abandoned );

			Log::log( 'HEIC Uploads: ' . $message, 'heicuploads' );

			return $message;
		}
		catch( Throwable $e )
		{
			/* Un ménage impossible ne doit pas empêcher la détection : c'est
			   la conversion des nouveaux envois qui compte. */
			Log::log( "HEIC Uploads: cannot close abandoned conversions — " . $e->getMessage(), 'heicuploads' );

			return NULL;
		}
	}

	/**
	 * Mettre la file en route s'il reste à faire.
	 *
	 * La clé de déduplication porte sur les DONNÉES du travail, pas sur une
	 * étiquette à part : Task::queue() compare `$oldData[$k] == $data[$k]`
	 * avec un isset() sur les deux (system/Task/Task.php:210-231). Passer
	 * array( 'convert' ) avec des données vides — ce que faisait la version
	 * précédente — ne déduplique rien, la clé n'existant d'aucun côté : une
	 * ligne core_queue s'ajoutait à chaque passage fructueux. Le marqueur doit
	 * aussi être non nul, isset() étant faux sur NULL.
	 *
	 * @return	void
	 */
	protected function queue() : void
	{
		try
		{
			if ( !Map::countConvertible() )
			{
				return;
			}

			/* Ne rien faire si un travail attend déjà. Task::queue() ne se
			   contente pas d'ignorer un doublon : il SUPPRIME la ligne
			   existante et en insère une neuve (Task.php:230-233), ce qui
			   remet l'offset à zéro. Appelée chaque minute sans ce contrôle,
			   la mise en file réinitialisait sans cesse l'avancement affiché
			   dans les processus de fond de l'AdminCP, et faisait tourner
			   inutilement une ligne de core_queue.
			   La clé de déduplication reste passée plus bas : elle couvre la
			   course entre deux passages simultanés, que ce contrôle-ci ne
			   voit pas. */
			$queued = Db::i()->select(
				'COUNT(*)',
				'core_queue',
				array( '`app`=? AND `key`=?', 'heicuploads', 'Convert' )
			)->first();

			if ( $queued )
			{
				return;
			}

			Task::queue( 'heicuploads', 'Convert', array( 'job' => 'convert' ), 3, array( 'job' ) );
		}
		catch( Throwable $e )
		{
			Log::log( "HEIC Uploads: cannot queue — " . $e->getMessage(), 'heicuploads' );
		}
	}

	/**
	 * Réunir les messages du passage.
	 *
	 * @param	array	$messages	Messages collectés
	 * @return	string|null			NULL si le passage n'a rien fait
	 */
	protected function summarise( array $messages ) : ?string
	{
		return count( $messages ) ? implode( ' ; ', $messages ) : NULL;
	}
}
