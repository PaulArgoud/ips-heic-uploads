<?php
/**
 * @brief		Réglages AdminCP
 * @package		HEIC Uploads
 */

namespace IPS\heicuploads\modules\admin\heicuploads;

use IPS\Dispatcher;
use IPS\Dispatcher\Controller;
use IPS\heicuploads\Application as HeicUploadsApplication;
use IPS\heicuploads\Converter;
use IPS\heicuploads\Map;
use IPS\Helpers\Form;
use IPS\Helpers\Form\Number;
use IPS\Helpers\Form\Select;
use IPS\Helpers\Form\YesNo;
use IPS\Log;
use IPS\Member;
use IPS\Output;
use IPS\Session;
use IPS\Settings as SettingsClass;
use IPS\Task;
use Throwable;
use function defined;
use function htmlspecialchars;

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
	 *
	 * ATTENTION — ce drapeau ne PROTÈGE rien : il DÉSACTIVE le seul contrôle
	 * automatique de l'AdminCP. Le dispatcher teste sa seule présence, jamais
	 * sa valeur (`!isset( $this->classname::$csrfProtected )`,
	 * system/Dispatcher/Admin.php:227) : le déclarer à FALSE désactiverait le
	 * contrôle tout autant. C'est une promesse du développeur — « nous avons
	 * ajouté nous-mêmes les contrôles CSRF » — et elle vaut pour TOUTES les
	 * actions du contrôleur.
	 *
	 * Ici, `manage()` la tient par son formulaire : Form::values() refuse de
	 * rendre des valeurs si le jeton ne correspond pas (Form.php:185 et 689).
	 * Toute action `do=` ajoutée doit appeler `Session::i()->csrfCheck()`
	 * elle-même, en première ligne.
	 */
	public static bool $csrfProtected = TRUE;

	/**
	 * Point d'entrée du contrôleur.
	 *
	 * La permission est contrôlée ici plutôt que dans chaque action : c'est
	 * l'idiome du coeur (applications/forums/modules/admin/stats/solved.php:67-71),
	 * et il garantit qu'une action ajoutée plus tard est couverte même si on
	 * oublie de le faire. `Controller::execute()` route ensuite `do=` vers la
	 * méthode du même nom (system/Dispatcher/Controller.php:123-130).
	 *
	 * @return	void
	 */
	public function execute() : void
	{
		Dispatcher::i()->checkAcpPermission( 'heicuploads_manage' );

		parent::execute();
	}

	/**
	 * Écran principal
	 *
	 * @return	void
	 */
	protected function manage() : void
	{
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
		   fasse en connaissance de cause plutôt qu'au nom du filtre.
		   Ce sont des CLÉS de langue : « parse » vaut « lang » par défaut
		   (system/Helpers/Form/Select.php:44 et 63). La version précédente
		   forçait « normal » et écrivait les libellés en français dans le
		   code — un administrateur anglophone lisait donc du français au
		   milieu d'un écran par ailleurs traduit. */
		$form->add( new Select(
			'heicuploads_filter',
			SettingsClass::i()->heicuploads_filter ?: Converter::FILTER_DEFAULT,
			TRUE,
			array(
				'options' => array(
					'catrom'   => 'heicuploads_filter_catrom',
					'lanczos'  => 'heicuploads_filter_lanczos',
					'triangle' => 'heicuploads_filter_triangle',
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

			/* $this->url, PAS Dispatcher::i()->url : cette propriété n'existe
			   pas. Ni \IPS\Dispatcher, ni \IPS\Dispatcher\Standard, ni
			   \IPS\Dispatcher\Admin ne déclarent $url, et aucune n'a de __get.
			   La lecture donnait NULL, et redirect() attend un
			   « Url|string » non nullable (system/Output/Output.php:1448) :
			   les réglages étaient enregistrés, puis la page tombait en
			   TypeError. C'est \IPS\Dispatcher\Controller qui porte $url,
			   posée au constructeur (Controller.php:47 et 69-81). */
			Output::i()->redirect( $this->url, 'saved' );
		}

		/* addToStack et non la clé brute : Output::i()->title attend une chaîne
		   déjà résolue. Tous les contrôleurs du coeur font ainsi (par exemple
		   applications/forums/modules/admin/forums/settings.php:98). Passer la
		   clé affichait « heicuploads_settings_title » à l'écran. */
		Output::i()->title  = Member::loggedIn()->language()->addToStack( 'heicuploads_settings_title' );
		Output::i()->output = $this->status() . $form;
	}

	/**
	 * Relancer les conversions bloquées.
	 *
	 * Sans cette action, une conversion en échec ne repartait QUE par une
	 * requête SQL à la main : le plafond de tentatives est atteint, le
	 * sélecteur de la file ignore la ligne, et plus rien ne la reprend jamais.
	 *
	 * Deux états sont repris, parce que l'administrateur ne fait pas la
	 * différence entre les deux et n'a pas à la faire : les lignes « en
	 * échec », et celles restées « en attente » avec leur plafond épuisé —
	 * une conversion tuée en cours de route, que la tâche de détection n'a pas
	 * encore fermée.
	 *
	 * @return	void
	 */
	protected function relancer() : void
	{
		/* Le contrôle CSRF n'est PAS automatique ici : voir $csrfProtected.
		   Première ligne, comme le fait le coeur dans ses actions « do= »
		   (applications/forums/modules/admin/stats/solved.php:252-254). */
		Session::i()->csrfCheck();

		try
		{
			Map::retryBlocked();

			/* Le marqueur voyage dans les DONNÉES du travail : la
			   déduplication de Task::queue() compare les clés de $data, pas
			   une étiquette à part (system/Task/Task.php:210-231). */
			Task::queue( 'heicuploads', 'Convert', array( 'job' => 'convert' ), 3, array( 'job' ) );
		}
		catch( Throwable $e )
		{
			Log::log( "HEIC vers AVIF : relance des conversions impossible — " . $e->getMessage(), 'heicuploads' );
		}

		Output::i()->redirect( $this->url, 'heicuploads_retry_done' );
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

		/* --- Le repère de départ est-il posé ? --- */

		/* En tête, parce que c'est la panne la plus silencieuse : sans repère,
		   la détection se suspend d'elle-même et plus rien ne se convertit,
		   sans qu'aucune erreur n'apparaisse nulle part. Le cas se produit
		   quand une installation s'est interrompue à sa dernière étape — un
		   serveur sans libheif, par exemple. */
		if ( HeicUploadsApplication::baseline() === NULL )
		{
			$html .= "<div class='ipsMessage ipsMessage--error'>"
				. Member::loggedIn()->language()->addToStack( 'heicuploads_status_no_baseline' )
				. "</div>";
		}

		/* --- Le serveur peut-il convertir ? --- */

		try
		{
			$problems = Converter::diagnose();
		}
		catch( Throwable $e )
		{
			$problems = array( array(
				'blocking' => TRUE,
				'what'     => "Diagnostics failed: " . $e->getMessage(),
				'fix'      => "Check the ImageMagick installation.",
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
			$counts = Map::counts();

			$html .= "<div class='ipsMessage ipsMessage--info'>"
				. Member::loggedIn()->language()->addToStack( 'heicuploads_status_counts', FALSE, array( 'sprintf' => array( $counts[ Map::CONVERTED ], $counts[ Map::PENDING ], $counts[ Map::FAILED ] ) ) )
				. "</div>";

			/* Échecs définitifs ET tentatives interrompues : l'administrateur
			   ne fait pas la différence et n'a pas à la faire. La tâche de
			   détection ferme les secondes dans la minute, mais le bouton ne
			   doit pas disparaître dans cet intervalle. */
			if ( Map::countBlocked() )
			{
				/* csrf() ajoute csrfKey à l'URL (system/Http/Url/Internal.php:270-273) :
				   sans lui, relancer() refuserait l'action. */
				$link = htmlspecialchars(
					(string) $this->url->setQueryString( 'do', 'relancer' )->csrf(),
					ENT_QUOTES,
					'UTF-8'
				);

				$html .= "<div class='ipsMessage ipsMessage--warning'>"
					. Member::loggedIn()->language()->addToStack( 'heicuploads_status_failed_hint' )
					. "<br><br><a href='{$link}' class='ipsButton ipsButton--primary ipsButton--small'>"
					. Member::loggedIn()->language()->addToStack( 'heicuploads_retry_button' )
					. "</a></div>";
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
