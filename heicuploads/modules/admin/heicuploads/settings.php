<?php
/**
 * @brief		Réglages AdminCP
 * @package		HEIC Uploads
 */

namespace IPS\heicuploads\modules\admin\heicuploads;

use IPS\Db;
use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\heicuploads\Converter;
use IPS\Helpers\Form;
use IPS\Helpers\Form\Number;
use IPS\Helpers\Form\Select;
use IPS\Helpers\Form\YesNo;
use IPS\Member;
use IPS\Output;
use IPS\Settings as SettingsClass;
use Throwable;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Réglages de la conversion HEIC vers AVIF
 */
class settings extends Controller
{
	/**
	 * @brief	Protection CSRF
	 */
	public static bool $csrfProtected = TRUE;

	/**
	 * Écran principal
	 *
	 * @return	void
	 */
	protected function manage() : void
	{
		Dispatcher::i()->checkAcpPermission( 'heicuploads_manage' );

		$form = new Form;

		$form->add( new YesNo(
			'heicuploads_enabled',
			SettingsClass::i()->heicuploads_enabled,
			FALSE
		) );

		/* Même composant que le réglage natif « Qualité JPG et WebP » :
		   un Number en mode range (system/Helpers/Form/Number.php:50). */
		$form->add( new Number(
			'heicuploads_quality',
			SettingsClass::i()->heicuploads_quality,
			TRUE,
			array( 'min' => 0, 'max' => 100, 'range' => TRUE, 'decimals' => 0 )
		) );

		$form->add( new Number(
			'heicuploads_thumb_quality',
			SettingsClass::i()->heicuploads_thumb_quality,
			TRUE,
			array( 'min' => 0, 'max' => 100, 'range' => TRUE, 'decimals' => 0 )
		) );

		/* Trois filtres seulement : ceux que nous avons mesurés sur ce
		   serveur. Les libellés portent le compromis, pour que le choix se
		   fasse en connaissance de cause plutôt qu'au nom du filtre. */
		$form->add( new Select(
			'heicuploads_filter',
			SettingsClass::i()->heicuploads_filter ?: Converter::FILTER_DEFAULT,
			TRUE,
			array(
				'parse'   => 'normal',
				'options' => array(
					'catrom'   => 'Catrom — équilibré (recommandé)',
					'lanczos'  => 'Lanczos — plus piqué, plus lent',
					'triangle' => 'Triangle — plus rapide, plus doux',
				),
			)
		) );

		/* Mesuré : la vitesse 9 produit le même poids que la 6 en sept fois
		   moins de temps. Le réglage n'existe que parce qu'une autre build
		   d'ImageMagick pourrait se comporter autrement. */
		$form->add( new Number(
			'heicuploads_speed',
			SettingsClass::i()->heicuploads_speed,
			TRUE,
			array( 'min' => 0, 'max' => 9, 'range' => TRUE, 'decimals' => 0 )
		) );

		/* Mesuré : au-delà de 2 threads le gain est nul et le coût CPU
		   double, ce qui pénalise le reste du forum en cas d'envois
		   simultanés. À n'augmenter qu'avec une mesure à l'appui. */
		$form->add( new Number(
			'heicuploads_threads',
			SettingsClass::i()->heicuploads_threads,
			TRUE,
			array( 'min' => 1, 'max' => 16, 'decimals' => 0 )
		) );

		if ( $values = $form->values() )
		{
			$form->saveAsSettings( $values );
			Output::i()->redirect( Dispatcher::i()->url, 'saved' );
		}

		/* addToStack et non la clé brute : Output::i()->title attend une chaîne
		   déjà résolue. Tous les contrôleurs du coeur font ainsi (par exemple
		   applications/forums/modules/admin/forums/settings.php:98). Passer la
		   clé affichait « heicuploads_settings_title » à l'écran. */
		Output::i()->title  = Member::loggedIn()->language()->addToStack( 'heicuploads_settings_title' );
		Output::i()->output = $this->status() . $form;
	}

	/**
	 * Bloc d'état.
	 *
	 * Le diagnostic n'est pas décoratif : si une mise à jour du serveur fait
	 * disparaître libheif dans six mois, plus rien ne se convertira et rien
	 * ne le signalera ailleurs. Les compteurs jouent le même rôle : un
	 * nombre d'échecs qui grimpe se voit ici avant de se voir sur le forum.
	 *
	 * @return	string	HTML
	 */
	protected function status() : string
	{
		$html = '';

		/* --- Le serveur peut-il convertir ? --- */

		try
		{
			$problems = Converter::diagnose();
		}
		catch( Throwable $e )
		{
			$problems = array( array(
				'blocking' => TRUE,
				'what'     => "Le diagnostic a échoué : " . $e->getMessage(),
				'fix'      => "Vérifier l'installation d'ImageMagick.",
			) );
		}

		if ( Converter::isOperational( $problems ) )
		{
			$html .= "<div class='ipsMessage ipsMessage--success'>" . Member::loggedIn()->language()->addToStack( 'heicuploads_status_ok' ) . "</div>";
		}
		else
		{
			$html .= "<div class='ipsMessage ipsMessage--error'><strong>" . Member::loggedIn()->language()->addToStack( 'heicuploads_status_ko' ) . "</strong><ul>";

			foreach ( $problems as $problem )
			{
				if ( $problem['blocking'] )
				{
					$html .= "<li>" . htmlspecialchars( $problem['what'], ENT_QUOTES, 'UTF-8' )
						. "<br><em>" . htmlspecialchars( $problem['fix'], ENT_QUOTES, 'UTF-8' ) . "</em></li>";
				}
			}

			$html .= "</ul></div>";
		}

		/* Les avertissements non bloquants : la conversion fonctionne, mais
		   dans un mode dégradé qu'il vaut mieux connaître. */
		foreach ( $problems as $problem )
		{
			if ( !$problem['blocking'] )
			{
				$html .= "<div class='ipsMessage ipsMessage--warning'>"
					. htmlspecialchars( $problem['what'], ENT_QUOTES, 'UTF-8' )
					. "<br><em>" . htmlspecialchars( $problem['fix'], ENT_QUOTES, 'UTF-8' ) . "</em></div>";
			}
		}

		/* --- Où en sont les conversions ? --- */

		try
		{
			$counts = array( 'pending' => 0, 'converted' => 0, 'failed' => 0 );

			foreach ( Db::i()->select( 'status, COUNT(*) as total', 'heicuploads_map', NULL, NULL, NULL, 'status' ) as $row )
			{
				$counts[ $row['status'] ] = (int) $row['total'];
			}

			$html .= "<div class='ipsMessage ipsMessage--info'>"
				. Member::loggedIn()->language()->addToStack( 'heicuploads_status_counts', FALSE, array( 'sprintf' => array( $counts['converted'], $counts['pending'], $counts['failed'] ) ) )
				. "</div>";

			if ( $counts['failed'] )
			{
				$html .= "<div class='ipsMessage ipsMessage--warning'>"
					. Member::loggedIn()->language()->addToStack( 'heicuploads_status_failed_hint' )
					. "</div>";
			}
		}
		catch( Throwable $e )
		{
			/* La table peut ne pas exister si l'installation s'est
			   interrompue : ce n'est pas une raison pour casser la page. */
		}

		return $html;
	}
}
