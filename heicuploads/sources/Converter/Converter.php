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
	 * Marques ISOBMFF acceptées comme HEIF.
	 *
	 * Ce sont les valeurs légitimes de la marque majeure d'une boîte « ftyp »
	 * pour un fichier HEIF. « mif1 » et « msf1 » y figurent : ce sont les
	 * marques génériques du conteneur, qu'emploient certains encodeurs.
	 */
	public const HEIF_BRANDS = array(
		'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs', 'mif1', 'msf1',
	);

	/**
	 * Codeurs ImageMagick autorisés, par signature reconnue.
	 *
	 * La liste est délibérément courte : ce sont les formats que le coeur
	 * lui-même sait identifier (\IPS\Image::create, Image.php:76-127). Tout
	 * le reste — PDF, SVG, MSL, MVG, et les quelque deux cents formats
	 * qu'ImageMagick accepte — est refusé.
	 */
	public const CODERS = array(
		'jpeg' => 'jpeg:',
		'png'  => 'png:',
		'gif'  => 'gif:',
		'webp' => 'webp:',
		'avif' => 'avif:',
		'heif' => 'heic:',
	);

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

	/**
	 * Nombre de pixels au-delà duquel on refuse de décoder.
	 *
	 * ESTIMATION, pas une mesure — et volontairement très large. Le plus gros
	 * capteur de téléphone du marché produit 200 Mpx ; aucune photo réelle
	 * n'atteint donc ce plafond, et un membre légitime ne peut pas s'y heurter.
	 * Il n'est là que pour arrêter l'absurde : un fichier de quelques centaines
	 * d'octets peut DÉCLARER 60 000 × 60 000 pixels dans son en-tête, soit
	 * 3,6 milliards de pixels. En Q16, ImageMagick alloue 8 octets par pixel —
	 * 250 Mpx font déjà 2 Go, pris HORS du tas PHP, donc sans que
	 * `memory_limit` n'y puisse rien : c'est le processus entier que l'OS tue,
	 * en emportant la requête du membre qui passait par là.
	 *
	 * À relever si un refus légitime apparaît un jour dans les journaux.
	 */
	public const MAX_PIXELS = 250000000;

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
				'what'     => "The PHP imagick extension is not loaded.",
				'fix'      => "Install the imagick extension for PHP, then restart PHP-FPM.",
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
				'what'     => "ImageMagick cannot decode HEIC: the format is absent from queryFormats().",
				'fix'      => "Rebuild ImageMagick with the libheif delegate, then restart PHP-FPM.",
			);
		}

		/* 3. L'encodage AVIF, fourni par libaom ou libavif selon la build. */
		if ( !in_array( 'AVIF', $formats ) )
		{
			$problems[] = array(
				'blocking' => TRUE,
				'what'     => "ImageMagick cannot encode AVIF: the format is absent from queryFormats().",
				'fix'      => "Rebuild ImageMagick with an AVIF delegate (libaom or libavif).",
			);
		}

		/* 4. getimagesize() doit reconnaître l'AVIF, sinon \IPS\File laissera
		      attach_is_image à 0 et l'image s'affichera en lien de
		      téléchargement au lieu d'une vignette. */
		if ( !defined( 'IMAGETYPE_AVIF' ) )
		{
			$problems[] = array(
				'blocking' => TRUE,
				'what'     => "PHP does not know IMAGETYPE_AVIF: getimagesize() will not recognise the files produced, and they will show as download links.",
				'fix'      => "PHP 8.1 or later is required. Current version: " . PHP_VERSION . ".",
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
						'fix'      => "Update ImageMagick's AVIF library.",
					);
				}
			}
			catch ( Throwable $e )
			{
				$problems[] = array(
					'blocking' => TRUE,
					'what'     => "The AVIF test encode failed: " . $e->getMessage(),
					'fix'      => "Check the installation of ImageMagick's AVIF delegate.",
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
	 * Quel codeur ImageMagick imposer pour ce fichier ?
	 *
	 * Le contenu vient d'un membre, et son extension n'est que le nom qu'il a
	 * choisi : `attach_ext` est découpé dans le nom d'envoi brut
	 * (File.php:1369-1377). Le coeur ne contrôle le contenu QUE des extensions
	 * qu'il sait afficher (File.php:632, via getimagesize) — et « heic » n'en
	 * fait justement pas partie, `Image::$imageExtensions` ne listant que
	 * gif/jpeg/png, plus webp et avif selon la build (Image.php:40,
	 * Imagemagick.php:389-404). Un PNG truqué serait refusé à l'envoi ; un
	 * HEIC truqué passe.
	 *
	 * Notre décodage est donc le PREMIER de la chaîne à voir ces octets, et il
	 * était le seul sans garde : `new Imagick( $chemin )` choisit son décodeur
	 * en reniflant le contenu, parmi tous les codeurs enregistrés. Reconnaître
	 * la signature nous-mêmes et imposer le codeur ferme ce choix.
	 *
	 * On ne se contente pas de refuser le non-HEIF : un vrai JPEG renommé en
	 * .heic — cas banal, certains outils de transfert le font — continue
	 * d'être converti, donc affiché. Refuser tout sauf le HEIF transformerait
	 * un correctif de sécurité en régression pour le membre.
	 *
	 * @param	string	$path	Chemin du fichier à examiner
	 * @return	string|null		Préfixe de codeur, NULL si non reconnu
	 */
	public static function coderFor( string $path ) : ?string
	{
		/* 64 octets : de quoi couvrir la boîte ftyp complète d'un HEIC réel,
		   marques compatibles comprises, sans jamais lire le fichier entier. */
		$header = @file_get_contents( $path, FALSE, NULL, 0, 64 );

		if ( $header === FALSE or strlen( $header ) < 12 )
		{
			return NULL;
		}

		/* ISOBMFF : la boîte « ftyp » est obligatoirement la première. Sa
		   marque MAJEURE occupe les octets 8 à 11, suivie de la version
		   mineure (12-15) puis de la liste des marques COMPATIBLES, par
		   groupes de quatre octets jusqu'à la fin de la boîte.
		   Il faut lire les deux : la norme HEIF admet qu'un fichier annonce
		   une marque majeure quelconque tant que « heic » ou « mif1 » figure
		   parmi les compatibles, et des encodeurs le font. S'en tenir à la
		   marque majeure aurait refusé des HEIC parfaitement valides — donc
		   laissé la photo d'un membre en lien de téléchargement. */
		if ( substr( $header, 4, 4 ) === 'ftyp' )
		{
			$major = substr( $header, 8, 4 );

			/* La marque MAJEURE prime, et se lit en premier. L'ordre n'est pas
			   indifférent : un AVIF légitime liste « mif1 » parmi ses marques
			   compatibles, si bien qu'examiner les compatibles d'abord le
			   faisait décoder par le codeur HEIF. Vérifié par jeu d'essai. */
			if ( $major === 'avif' )
			{
				return static::CODERS['avif'];
			}

			if ( in_array( $major, static::HEIF_BRANDS, TRUE ) )
			{
				return static::CODERS['heif'];
			}

			/* Marque majeure inconnue : la norme HEIF admet qu'un fichier
			   l'annonce ainsi tant que « heic » ou « mif1 » figure parmi ses
			   marques compatibles, et des encodeurs le font. S'en tenir à la
			   marque majeure refuserait des HEIC valides — donc laisserait la
			   photo d'un membre en lien de téléchargement.
			   Les compatibles suivent la version mineure, par groupes de
			   quatre octets jusqu'à la fin de la boîte. La taille annoncée est
			   bornée par ce qu'on a lu : un en-tête déclarant une boîte énorme
			   ne doit pas nous faire sortir du tampon. */
			$size    = unpack( 'N', substr( $header, 0, 4 ) )[1];
			$compat  = array();

			for ( $offset = 16; $offset + 4 <= min( $size, strlen( $header ) ); $offset += 4 )
			{
				$compat[] = substr( $header, $offset, 4 );
			}

			if ( in_array( 'avif', $compat, TRUE ) )
			{
				return static::CODERS['avif'];
			}

			return array_intersect( $compat, static::HEIF_BRANDS ) ? static::CODERS['heif'] : NULL;
		}

		/* Mêmes signatures que \IPS\Image::create() (Image.php:76-127). */
		if ( bin2hex( substr( $header, 0, 3 ) ) === 'ffd8ff' )
		{
			return static::CODERS['jpeg'];
		}

		if ( bin2hex( substr( $header, 0, 8 ) ) === '89504e470d0a1a0a' )
		{
			return static::CODERS['png'];
		}

		if ( substr( $header, 0, 4 ) === 'RIFF' and substr( $header, 8, 4 ) === 'WEBP' )
		{
			return static::CODERS['webp'];
		}

		if ( substr( $header, 0, 6 ) === 'GIF87a' or substr( $header, 0, 6 ) === 'GIF89a' )
		{
			return static::CODERS['gif'];
		}

		return NULL;
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
	 * @param	string	$thumbTarget	Chemin de la vignette à produire
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
			throw new RuntimeException( "Unreadable source: {$source}" );
		}

		/* Refus AVANT qu'ImageMagick ne voie les octets : c'est le seul moment
		   où le refus coûte zéro. */
		$coder = static::coderFor( $source );

		if ( $coder === NULL )
		{
			throw new RuntimeException( "Content not recognised as an image: decoding refused." );
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

		/* La cible est prise TELLE QUELLE, sans extension dérivée. La version
		   précédente écrivait dans « $thumbTarget.avif » : un chemin que
		   tempnam() n'avait pas créé, donc sans sa réservation atomique ni son
		   mode 0600, dans un /tmp partagé. L'extension n'a de toute façon
		   aucune importance pour Imagick, le format étant fixé par
		   setImageFormat() quelques lignes plus bas. */
		$thumbPath = $thumbTarget;

		try
		{
			/* SEULE limite globale que nous posons, et c'est assumé — à ne pas
			   confondre avec le plafond de mémoire écarté quinze lignes plus
			   bas. setResourceLimit() est statique ici aussi, la valeur survit
			   donc à la conversion dans le même worker. La différence est le
			   SENS : à 2 sur une machine à 4 coeurs on ABAISSE, ce qui ne peut
			   pas assouplir la politique de l'hébergeur. Le pire effet de bord
			   est un redimensionnement du coeur sur 2 threads au lieu de 4 :
			   plus lent, jamais cassé.
			   Au-delà de 2 threads le gain est nul (mesuré : 1,87 s à 2
			   threads, 1,90 s à 4) alors que le coût CPU double — ce qui
			   compte dès que plusieurs membres téléversent simultanément. */
			Imagick::setResourceLimit( Imagick::RESOURCETYPE_THREAD, $this->threads );

			$image = new Imagick;

			/* On LIT l'en-tête avant de décoder quoi que ce soit. pingImage()
			   n'alloue pas le tampon de pixels : c'est le seul moment où l'on
			   connaît les dimensions annoncées sans avoir déjà payé le prix de
			   les croire.
			   La parade évidente aurait été de plafonner la MÉMOIRE par
			   Imagick::setResourceLimit(), mais c'est une méthode STATIQUE :
			   le plafond survit à notre conversion et s'applique à tout ce que
			   le worker PHP-FPM traitera ensuite — y compris les
			   redimensionnements du reste du forum. Poser un plafond généreux
			   ASSOUPLIRAIT la politique de l'hébergeur pour les autres
			   requêtes. Un contrôle local n'a pas cet effet de bord. */
			$image->pingImage( $coder . $source );

			/* La SOMME de toutes les images du fichier, et non la seule image
			   sur laquelle l'itérateur se trouve. pingImage() peuple l'objet
			   avec TOUTES les images du conteneur — trames d'un GIF ou d'un
			   WEBP animé, images de haut niveau d'un HEIF —, alors que
			   getImageWidth() ne rend que celle de l'image courante.
			   N'en mesurer qu'une laissait la garde se contourner par
			   construction : 200 trames de 4 000 × 4 000 en aplat uniforme
			   tiennent dans quelques mégaoctets compressés et totalisent
			   3,2 milliards de pixels, quand la première trame en annonce 16
			   millions. Et coderFor() accepte délibérément gif et webp sous une
			   extension .heic : le fichier n'a même pas besoin d'être un HEIF.
			   Sommer ne peut produire aucun refus abusif — une vraie rafale
			   HEIC reste à quelques dizaines de Mpx face au plafond. */
			$frames = $image->getNumberImages();
			$pixels = 0;

			for ( $i = 0; $i < $frames; $i++ )
			{
				$image->setIteratorIndex( $i );
				$pixels += $image->getImageWidth() * $image->getImageHeight();
			}

			if ( $pixels > static::MAX_PIXELS )
			{
				/* Le nombre d'images figure dans le message : c'est ce qui
				   distingue dans le journal « une photo trop grande » d'un
				   fichier fabriqué pour faire tomber le processus. */
				throw new RuntimeException( sprintf(
					'Image refused: %s pixels declared across %d image(s), beyond the %s cap.',
					number_format( $pixels, 0, ',', ' ' ),
					$frames,
					number_format( static::MAX_PIXELS, 0, ',', ' ' )
				) );
			}

			$image->clear();

			/* Le codeur est IMPOSÉ, jamais deviné : « heic:/chemin » plutôt
			   que « /chemin ». Voir coderFor(). */
			$image = new Imagick( $coder . $source );
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
			throw new RuntimeException( "Imagick failure: " . $e->getMessage(), 0, $e );
		}
		catch ( Throwable $e )
		{
			$this->release( $image );
			throw new RuntimeException( "Conversion failure: " . $e->getMessage(), 0, $e );
		}

		/* Un HEIC de 48 Mpx décompressé en Q16 occupe plusieurs centaines de
		   mégaoctets, alloués hors du tas PHP. Libérer n'est pas optionnel. */
		$this->release( $image );

		if ( !is_file( $avifTarget ) or !filesize( $avifTarget ) )
		{
			throw new RuntimeException( "No AVIF produced: {$avifTarget}" );
		}

		if ( !is_file( $thumbPath ) or !filesize( $thumbPath ) )
		{
			throw new RuntimeException( "No thumbnail produced: {$thumbPath}" );
		}

		/* Contrôle de la marque AVANT de déclarer la réussite : un AVIF non
		   conforme serait rejeté par le coeur à l'affichage, et l'échec se
		   manifesterait très loin d'ici. */
		if ( !static::hasAvifSignature( $avifTarget ) )
		{
			@unlink( $avifTarget );
			@unlink( $thumbPath );
			throw new RuntimeException( "The AVIF produced does not carry the \"ftypavif\" brand that \\IPS\\Image::create() requires." );
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
