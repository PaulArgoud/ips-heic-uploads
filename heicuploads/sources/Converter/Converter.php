<?php
/**
 * @brief		Moteur de conversion HEIC vers AVIF
 * @package		HEIC Uploads
 */

namespace IPS\heicuploads;

use Imagick;
use ImagickException;
use RuntimeException;
use Throwable;
use function defined;
use function strlen;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Moteur de conversion.
 *
 * Cette classe ne dépend d'AUCUNE classe \IPS : elle ne manipule que des
 * chemins de fichiers locaux et des scalaires. C'est ce qui permet de la
 * tester en ligne de commande sur le serveur, isolément du forum, et de
 * rejouer un cas litigieux sans passer par un envoi réel. Tous les réglages
 * sont injectés par l'appelant, jamais lus depuis \IPS\Settings ici.
 */
class Converter
{
	/**
	 * Marque de format attendue aux octets 4 à 11 d'un AVIF conforme.
	 *
	 * \IPS\Image::create() identifie l'AVIF par cette signature exacte
	 * (system/Image/Image.php:103). Un encodeur écrivant « mif1 » comme
	 * marque majeure produirait un fichier que le coeur refuserait, sans
	 * vignette ni affichage possible.
	 */
	public const AVIF_FTYP = '6674797061766966';

	public const SOURCE_EXTENSIONS = array( 'heic', 'heif' );

	/**
	 * Filtres de redimensionnement proposés.
	 *
	 * Volontairement limité aux trois que nous avons MESURÉS sur le serveur.
	 * ImageMagick en propose une vingtaine, mais offrir des options dont on
	 * ignore le comportement inviterait à choisir à l'aveugle.
	 *
	 * Mesures à 2 threads sur une photo iPhone de 12 Mpx :
	 *   triangle  1,84 s   78 Ko   le plus doux, perd du détail
	 *   catrom    1,87 s   91 Ko   équilibré, valeur par défaut
	 *   lanczos   2,37 s   99 Ko   le plus piqué, le plus lent
	 *
	 * Le poids plus faible de « triangle » n'est PAS une meilleure
	 * compression : c'est une image plus floue, donc moins de hautes
	 * fréquences à encoder.
	 */
	public const FILTERS = array(
		'triangle' => Imagick::FILTER_TRIANGLE,
		'catrom'   => Imagick::FILTER_CATROM,
		'lanczos'  => Imagick::FILTER_LANCZOS,
	);

	public const FILTER_DEFAULT = 'catrom';

	protected int $maxWidth;
	protected int $maxHeight;
	protected int $quality;
	protected int $speed;
	protected int $threads;
	protected int $filter;

	/**
	 * Constructeur
	 *
	 * Les valeurs par défaut sont celles mesurées sur le serveur de
	 * production (ImageMagick 7.1.1-43, 4 coeurs) sur une photo iPhone de
	 * 12 Mpx : 1,87 s au total, 91 Ko en sortie.
	 *
	 * @param	int	$maxWidth	Largeur maximale, 0 pour aucune limite
	 * @param	int	$maxHeight	Hauteur maximale, 0 pour aucune limite
	 * @param	int	$quality	Qualité AVIF
	 * @param	int	$speed		Vitesse d'encodage (heic:speed)
	 * @param	int		$threads	Threads ImageMagick
	 * @param	string	$filter		Clé de FILTERS, repli sur catrom si inconnue
	 */
	public function __construct(
		int $maxWidth = 2048,
		int $maxHeight = 2048,
		int $quality = 65,
		int $speed = 9,
		int $threads = 2,
		string $filter = self::FILTER_DEFAULT
	)
	{
		$this->maxWidth  = $maxWidth;
		$this->maxHeight = $maxHeight;
		$this->quality   = $quality;
		$this->speed     = $speed;
		$this->threads   = $threads;

		/* Repli silencieux plutôt qu'exception : un réglage corrompu ne doit
		   pas empêcher toutes les conversions du forum. */
		$this->filter = static::FILTERS[ $filter ] ?? static::FILTERS[ static::FILTER_DEFAULT ];
	}

	/**
	 * L'environnement sait-il faire le travail ?
	 *
	 * Appelé à l'installation (Application::installOther) et exposé en
	 * permanence dans l'AdminCP.
	 *
	 * On ne se contente PAS de queryFormats() : un format peut y être
	 * déclaré et être écrit de travers. Le contrôle décisif est un encodage
	 * AVIF réel suivi de la vérification de sa marque de format — exactement
	 * ce que le coeur exigera à l'affichage.
	 *
	 * @return	array	Problèmes, chacun avec 'blocking', 'what', 'fix'.
	 *					Tableau vide si tout va bien.
	 */
	public static function diagnose() : array
	{
		$problems = array();

		/* 1. L'extension PHP elle-même. Sans elle, rien d'autre n'a de sens. */
		if ( !class_exists( 'Imagick', FALSE ) )
		{
			return array( array(
				'blocking' => TRUE,
				'what'     => "L'extension PHP imagick n'est pas chargée.",
				'fix'      => "Installer l'extension imagick pour PHP, puis redémarrer PHP-FPM.",
			) );
		}

		$formats = Imagick::queryFormats();

		/* 2. Le décodage HEIC. Le coder n'est enregistré par ImageMagick que
		      si libheif était présent à la compilation : son absence de
		      queryFormats() est donc bien le symptôme d'un libheif manquant. */
		if ( !in_array( 'HEIC', $formats ) )
		{
			$problems[] = array(
				'blocking' => TRUE,
				'what'     => "ImageMagick ne sait pas décoder le HEIC : le format est absent de queryFormats().",
				'fix'      => "Recompiler ImageMagick avec le délégué libheif, puis redémarrer PHP-FPM.",
			);
		}

		/* 3. L'encodage AVIF, fourni par libaom ou libavif selon la build. */
		if ( !in_array( 'AVIF', $formats ) )
		{
			$problems[] = array(
				'blocking' => TRUE,
				'what'     => "ImageMagick ne sait pas encoder l'AVIF : le format est absent de queryFormats().",
				'fix'      => "Recompiler ImageMagick avec le délégué AVIF (libaom ou libavif).",
			);
		}

		/* 4. getimagesize() doit reconnaître l'AVIF, sinon \IPS\File laissera
		      attach_is_image à 0 et l'image s'affichera en lien de
		      téléchargement au lieu d'une vignette. */
		if ( !defined( 'IMAGETYPE_AVIF' ) )
		{
			$problems[] = array(
				'blocking' => TRUE,
				'what'     => "PHP ne connaît pas IMAGETYPE_AVIF : getimagesize() ne reconnaîtra pas les fichiers produits, qui s'afficheront en liens de téléchargement.",
				'fix'      => "PHP 8.1 ou supérieur est requis. Version actuelle : " . PHP_VERSION . ".",
			);
		}

		/* 5. Le contrôle qui compte vraiment : produire un AVIF et vérifier
		      qu'il porte la marque « ftypavif ». Un encodeur écrivant « mif1 »
		      passerait tous les tests précédents et produirait pourtant des
		      fichiers que le coeur refuserait d'afficher. */
		if ( in_array( 'AVIF', $formats ) )
		{
			$probe = NULL;

			try
			{
				$probe  = tempnam( sys_get_temp_dir(), 'heicuploads_probe_' );
				$canvas = new Imagick();
				$canvas->newImage( 64, 64, 'red' );
				$canvas->setImageFormat( 'avif' );
				$canvas->writeImage( $probe );
				$canvas->clear();
				$canvas->destroy();

				if ( !static::hasAvifSignature( $probe ) )
				{
					$problems[] = array(
						'blocking' => TRUE,
						'what'     => "L'AVIF produit par ce serveur ne porte pas la marque « ftypavif » qu'Invision Community attend : les images converties ne seraient pas affichables.",
						'fix'      => "Mettre à jour la bibliothèque AVIF d'ImageMagick.",
					);
				}
			}
			catch ( Throwable $e )
			{
				$problems[] = array(
					'blocking' => TRUE,
					'what'     => "L'encodage AVIF de test a échoué : " . $e->getMessage(),
					'fix'      => "Vérifier l'installation du délégué AVIF d'ImageMagick.",
				);
			}
			finally
			{
				if ( $probe !== NULL and is_file( $probe ) )
				{
					@unlink( $probe );
				}
			}
		}

		return $problems;
	}

	/**
	 * Y a-t-il un problème rédhibitoire ?
	 *
	 * @param	array|null	$problems	Résultat de diagnose(), recalculé si omis
	 * @return	bool
	 */
	public static function isOperational( ?array $problems = NULL ) : bool
	{
		$problems = $problems ?? static::diagnose();

		foreach ( $problems as $problem )
		{
			if ( $problem['blocking'] )
			{
				return FALSE;
			}
		}

		return TRUE;
	}

	/**
	 * Le fichier est-il un HEIC/HEIF, d'après son nom ?
	 *
	 * @param	string	$filename	Nom de fichier
	 * @return	bool
	 */
	public static function isSource( string $filename ) : bool
	{
		return in_array( mb_strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ), static::SOURCE_EXTENSIONS );
	}

	/**
	 * Produire l'AVIF et sa vignette à partir d'un HEIC local.
	 *
	 * Un SEUL décodage sert aux deux fichiers. C'est important pour deux
	 * raisons. D'abord la vitesse : le décodage du HEIC est l'opération la
	 * plus coûteuse de la chaîne (0,85 s mesurées, contre 0,22 s pour
	 * l'encodage AVIF), la payer deux fois serait absurde. Ensuite la
	 * qualité : la vignette est tirée des pixels d'origine, et non d'une
	 * relecture de l'AVIF déjà compressé à 65 — pas de double compression.
	 *
	 * L'ordre des opérations n'est pas arbitraire :
	 *  - autoOrient() DOIT précéder stripImage(), faute de quoi l'orientation
	 *    EXIF est supprimée avant d'avoir été appliquée et les photos
	 *    ressortent couchées ;
	 *  - la conversion sRGB corrige le délavage des HEIC HDR des iPhone
	 *    récents, et a été mesurée à 0,00 s : jamais un motif d'optimisation ;
	 *  - stripImage() est indispensable en aval, sans quoi le pipeline d'IPS
	 *    réappliquerait l'orientation une seconde fois.
	 *
	 * @param	string	$source			Chemin du HEIC source
	 * @param	string	$avifTarget		Chemin de l'AVIF à produire
	 * @param	string	$thumbTarget	Chemin de la vignette, SANS extension
	 * @param	int		$thumbWidth		Largeur maximale de la vignette
	 * @param	int		$thumbHeight	Hauteur maximale de la vignette
	 * @param	int		$thumbQuality	Qualité de la vignette
	 * @return	array					avif, thumb, duration
	 * @throws	RuntimeException
	 */
	public function process(
		string $source,
		string $avifTarget,
		string $thumbTarget,
		int $thumbWidth = 1000,
		int $thumbHeight = 750,
		int $thumbQuality = 25
	) : array
	{
		if ( !is_readable( $source ) )
		{
			throw new RuntimeException( "Source illisible : {$source}" );
		}

		$started = microtime( TRUE );
		$image   = NULL;

		/* La vignette est en AVIF, comme l'image pleine taille.
		   Un format plus conservateur n'aurait rien protégé : un navigateur
		   incapable d'afficher l'AVIF échouerait de toute façon sur l'image
		   principale. Et l'AVIF tient bien mieux la basse qualité que le WebP,
		   ce qui compte à 25.
		   L'objection d'un « second encodage lent » ne vaut plus depuis que les
		   deux fichiers sortent d'un décodage unique : à 1000 px on encode
		   quatre fois moins de pixels qu'à 2048. */
		$thumbFormat = 'avif';
		$thumbPath   = $thumbTarget . '.' . $thumbFormat;

		try
		{
			/* Au-delà de 2 threads le gain est nul (mesuré : 1,87 s à 2
			   threads, 1,90 s à 4) alors que le coût CPU double — ce qui
			   compte dès que plusieurs membres téléversent simultanément. */
			Imagick::setResourceLimit( Imagick::RESOURCETYPE_THREAD, $this->threads );

			$image = new Imagick( $source );
			$image->autoOrient();
			$image->transformImageColorspace( Imagick::COLORSPACE_SRGB );
			$image->stripImage();

			/* --- L'AVIF pleine taille --- */

			$this->constrain( $image, $this->maxWidth, $this->maxHeight );

			$width  = $image->getImageWidth();
			$height = $image->getImageHeight();

			$image->setImageFormat( 'avif' );
			$image->setImageCompressionQuality( $this->quality );
			$image->setOption( 'heic:speed', (string) $this->speed );
			$image->writeImage( $avifTarget );

			/* --- La vignette, depuis les MÊMES pixels --- */

			$this->constrain( $image, $thumbWidth, $thumbHeight );

			$thumbW = $image->getImageWidth();
			$thumbH = $image->getImageHeight();

			$image->setImageFormat( $thumbFormat );
			$image->setImageCompressionQuality( $thumbQuality );
			$image->setOption( 'heic:speed', (string) $this->speed );
			$image->writeImage( $thumbPath );
		}
		catch ( ImagickException $e )
		{
			$this->release( $image );
			throw new RuntimeException( "Échec Imagick : " . $e->getMessage(), 0, $e );
		}
		catch ( Throwable $e )
		{
			$this->release( $image );
			throw new RuntimeException( "Échec de conversion : " . $e->getMessage(), 0, $e );
		}

		/* Un HEIC de 48 Mpx décompressé en Q16 occupe plusieurs centaines de
		   mégaoctets, alloués hors du tas PHP. Libérer n'est pas optionnel. */
		$this->release( $image );

		if ( !is_file( $avifTarget ) or !filesize( $avifTarget ) )
		{
			throw new RuntimeException( "Aucun AVIF produit : {$avifTarget}" );
		}

		if ( !is_file( $thumbPath ) or !filesize( $thumbPath ) )
		{
			throw new RuntimeException( "Aucune vignette produite : {$thumbPath}" );
		}

		/* Contrôle de la marque AVANT de déclarer la réussite : un AVIF non
		   conforme serait rejeté par le coeur à l'affichage, et l'échec se
		   manifesterait très loin d'ici. */
		if ( !static::hasAvifSignature( $avifTarget ) )
		{
			@unlink( $avifTarget );
			@unlink( $thumbPath );
			throw new RuntimeException( "L'AVIF produit ne porte pas la marque « ftypavif » attendue par \\IPS\\Image::create()." );
		}

		return array(
			'avif' => array(
				'path'     => $avifTarget,
				'width'    => $width,
				'height'   => $height,
				'filesize' => filesize( $avifTarget ),
			),
			'thumb' => array(
				'path'     => $thumbPath,
				'format'   => $thumbFormat,
				'width'    => $thumbW,
				'height'   => $thumbH,
				'filesize' => filesize( $thumbPath ),
			),
			'duration' => round( microtime( TRUE ) - $started, 3 ),
		);
	}

	/**
	 * Inscrire l'image dans le gabarit.
	 *
	 * Les dimensions maximales de l'AdminCP sont une garantie, pas une
	 * option : elles s'appliquent à la largeur ET à la hauteur. On ne réduit
	 * que si l'image dépasse — agrandir n'aurait aucun sens et coûterait du
	 * temps.
	 *
	 * @param	Imagick	$image		Image à contraindre
	 * @param	int		$maxWidth	Largeur maximale, 0 pour aucune limite
	 * @param	int		$maxHeight	Hauteur maximale, 0 pour aucune limite
	 * @return	void
	 */
	protected function constrain( Imagick $image, int $maxWidth, int $maxHeight ) : void
	{
		$width  = $image->getImageWidth();
		$height = $image->getImageHeight();

		$maxWidth  = $maxWidth ?: $width;
		$maxHeight = $maxHeight ?: $height;

		if ( $width <= $maxWidth and $height <= $maxHeight )
		{
			return;
		}

		/* Le dernier argument active le « bestfit », qui inscrit l'image dans
		   le gabarit en conservant ses proportions. Le filtre vient de
		   l'AdminCP, catrom par défaut (voir FILTERS pour les mesures). */
		$image->resizeImage( $maxWidth, $maxHeight, $this->filter, 1, TRUE );
	}

	/**
	 * Le fichier porte-t-il la marque AVIF que le coeur exige ?
	 *
	 * Reproduit exactement le test de system/Image/Image.php:103 : trois
	 * octets nuls, puis « ftypavif » en position 4.
	 *
	 * @param	string	$path	Chemin du fichier
	 * @return	bool
	 */
	public static function hasAvifSignature( string $path ) : bool
	{
		$header = @file_get_contents( $path, FALSE, NULL, 0, 12 );

		if ( $header === FALSE or strlen( $header ) < 12 )
		{
			return FALSE;
		}

		return bin2hex( substr( $header, 0, 3 ) ) === '000000'
			and bin2hex( substr( $header, 4, 8 ) ) === static::AVIF_FTYP;
	}

	/**
	 * Libérer les objets Imagick
	 *
	 * @param	Imagick|null	$image	Objet à libérer
	 * @return	void
	 */
	protected function release( ?Imagick $image ) : void
	{
		if ( $image === NULL )
		{
			return;
		}

		try
		{
			$image->clear();
			$image->destroy();
		}
		catch ( Throwable $e )
		{
			/* Un objet déjà détruit ne doit pas masquer l'erreur d'origine. */
		}
	}
}
