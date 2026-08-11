<?php
/**
 * @brief		Classe d'application : conversion HEIC vers AVIF
 * @package		HEIC Uploads
 */

namespace IPS\heicuploads;

use IPS\Application as SystemApplication;
use IPS\Db;
use IPS\Settings;
use IPS\Log;
use RuntimeException;
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
 * Classe d'application
 */
class Application extends SystemApplication
{
	/**
	 * Dernière étape de l'installation.
	 *
	 * Point d'override documenté par le coeur : « Install 'other' items.
	 * Left blank here so that application classes can override for app
	 * specific installation needs. Always run as the last step. »
	 * (system/Application/Application.php:3060-3069)
	 *
	 * On refuse de s'installer sur un serveur incapable de faire le travail.
	 * Laisser l'application s'activer sur une machine sans libheif ni AVIF
	 * produirait des échecs silencieux au moment des envois des membres,
	 * c'est-à-dire au pire endroit possible.
	 *
	 * @return	void
	 * @throws	RuntimeException
	 */
	public function installOther() : void
	{
		$problems = Converter::diagnose();

		/* Les avertissements non bloquants sont journalisés, pas opposés à
		   l'installation : une absence de WebP dégrade les vignettes sans
		   empêcher la conversion. */
		foreach ( $problems as $problem )
		{
			if ( !$problem['blocking'] )
			{
				Log::log( "HEIC vers AVIF — avertissement : {$problem['what']} {$problem['fix']}", 'heicuploads_install' );
			}
		}

		$blocking = array_values( array_filter( $problems, fn( array $p ) : bool => $p['blocking'] ) );

		/* Le repère de départ est posé même si tout va bien : c'est la
		   garantie que rien d'antérieur ne sera converti. */
		if ( !count( $blocking ) )
		{
			$this->setBaseline();
			return;
		}

		$message = "L'application « HEIC vers AVIF » ne peut pas fonctionner sur ce serveur.\n\n";

		foreach ( $blocking as $i => $problem )
		{
			$message .= sprintf( "%d. %s\n   → %s\n", $i + 1, $problem['what'], $problem['fix'] );
		}

		$message .= "\nCorrigez ces points puis relancez l'installation.";

		Log::log( $message, 'heicuploads_install' );

		throw new RuntimeException( $message );
	}

	/**
	 * Poser le repère de départ du balayage.
	 *
	 * L'application ne doit convertir QUE les envois postérieurs à son
	 * installation. Sans ce repère, la tâche de détection remonte toute la
	 * table core_attachments et reprend des photos vieilles de plusieurs
	 * années — une modification de masse sur de la production, alors que
	 * l'original est supprimé après conversion.
	 *
	 * On enregistre donc à l'installation le plus grand attach_id existant.
	 * Tout ce qui est antérieur est ignoré, définitivement, sauf décision
	 * explicite de l'administrateur.
	 *
	 * @return	void
	 */
	protected function setBaseline() : void
	{
		try
		{
			$max = (int) ( Db::i()->select( 'MAX(attach_id)', 'core_attachments' )->first() ?: 0 );

			Settings::i()->changeValues( array( 'heicuploads_baseline_id' => $max ) );

			Log::log( "HEIC Uploads : repère de départ posé à attach_id {$max}. Les pièces jointes antérieures ne seront pas converties.", 'heicuploads_install' );
		}
		catch( Throwable $e )
		{
			/* Un repère absent vaut 0, donc balayage complet : on refuse
			   plutôt que de laisser filer une conversion rétroactive
			   silencieuse. */
			throw new RuntimeException(
				"Impossible de déterminer le point de départ du balayage : " . $e->getMessage()
			);
		}
	}

	/**
	 * L'application est-elle en état de fonctionner ?
	 *
	 * Le diagnostic complet exécute un encodage AVIF de test, on ne le rejoue
	 * donc pas à chaque requête. Le résultat est mémorisé pour la durée du
	 * processus, ce qui suffit : les délégués d'ImageMagick ne changent pas
	 * en cours de requête.
	 *
	 * @return	bool
	 */
	public static function isOperational() : bool
	{
		static $operational = NULL;

		if ( $operational === NULL )
		{
			try
			{
				$operational = Converter::isOperational();
			}
			catch ( Throwable $e )
			{
				/* Un diagnostic qui échoue ne doit jamais casser une page. */
				$operational = FALSE;
			}
		}

		return $operational;
	}
}
