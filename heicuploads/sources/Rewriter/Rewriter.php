<?php
/**
 * @brief		Réécriture du HTML publié (voie de rattrapage)
 * @package		HEIC Uploads
 */

namespace IPS\heicuploads;

use DOMElement;
use DOMXpath;
use IPS\Application;
use IPS\Content;
use IPS\Db;
use IPS\Log;
use IPS\Text\Parser;
use IPS\Xml\DOMDocument;
use OutOfRangeException;
use Throwable;
use UnexpectedValueException;
use function defined;
use function is_array;
use function trim;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Réécriture du HTML des messages déjà publiés.
 *
 * Voie de rattrapage. En marche normale, la conversion se termine pendant que
 * le membre rédige son message, et le HTML est écrit correctement dès la
 * publication. Cette classe ne sert qu'aux messages validés avant la fin de
 * la conversion : leur HEIC y figure comme lien de téléchargement
 * (system/Helpers/Form/Editor.php:442-450), définitivement, puisque le HTML
 * est figé et que mettre à jour core_attachments ne le réécrit pas.
 *
 * La manipulation reprend exactement la technique du coeur
 * (\IPS\Text\Parser::rebuildAttachmentUrls, Parser.php:3603) : les jetons
 * « <fileStore.x>/ » sont encodés AVANT le passage au DOM, puis restaurés
 * après. Sans cela, DOMDocument échapperait le chevron en &lt; et casserait
 * toutes les URL de pièces jointes du message.
 */
class Rewriter
{
	/**
	 * Réécrire toutes les occurrences d'une pièce jointe convertie.
	 *
	 * @param	int	$attachId	Identifiant de la pièce jointe
	 * @return	int				Nombre de contenus réécrits
	 */
	public static function rewrite( int $attachId ) : int
	{
		try
		{
			$attachment = Db::i()->select( '*', 'core_attachments', array( 'attach_id=?', $attachId ) )->first();
		}
		catch( Throwable $e )
		{
			return 0;
		}

		/* Une pièce jointe non convertie n'a rien à faire ici : on ne
		   remplacerait un lien que par une image introuvable. */
		if ( !$attachment['attach_is_image'] )
		{
			return 0;
		}

		$rewritten = 0;

		/* Une même pièce jointe peut être réclamée par plusieurs contenus
		   (citation, déplacement). On les traite tous. */
		foreach ( Db::i()->select( '*', 'core_attachments_map', array( 'attachment_id=?', $attachId ) ) as $map )
		{
			try
			{
				if ( static::rewriteOne( $map, $attachment ) )
				{
					$rewritten++;
				}
			}
			catch( Throwable $e )
			{
				Log::log(
					"HEIC vers AVIF : réécriture impossible pour la pièce jointe {$attachId} ({$map['location_key']}) — " . $e->getMessage(),
					'heicuploads'
				);
			}
		}

		return $rewritten;
	}

	/**
	 * Réécrire un contenu donné
	 *
	 * @param	array	$map		Ligne de core_attachments_map
	 * @param	array	$attachment	Ligne de core_attachments
	 * @return	bool				TRUE si le contenu a été modifié
	 */
	protected static function rewriteOne( array $map, array $attachment ) : bool
	{
		$object = static::contentFor( $map );

		if ( $object === NULL )
		{
			return FALSE;
		}

		$class = $object::class;

		/* Localisation du champ HTML, d'après les propriétés statiques que
		   toute classe \IPS\Content déclare (patron vérifié sur
		   applications/forums/sources/Topic/Post.php:91-116). */
		$column = $class::$databaseColumnMap['content'] ?? NULL;

		if ( $column === NULL )
		{
			return FALSE;
		}

		/* Certaines classes mappent plusieurs colonnes sur un même rôle. */
		if ( is_array( $column ) )
		{
			$column = reset( $column );
		}

		$table    = $class::$databaseTable;
		$prefix   = $class::$databasePrefix;
		$idColumn = $class::$databaseColumnId;

		$row = Db::i()->select(
			'*',
			$table,
			array( $prefix . $idColumn . '=?', $object->$idColumn )
		)->first();

		$original = $row[ $prefix . $column ];
		$updated  = static::replaceInHtml( $original, $attachment );

		if ( $updated === NULL )
		{
			return FALSE;
		}

		/* Écriture directe plutôt que save() : on ne veut déclencher ni
		   réindexation, ni notification, ni horodatage d'édition. Le contenu
		   sémantique du message n'a pas changé, seule sa représentation. */
		Db::i()->update(
			$table,
			array( $prefix . $column => $updated ),
			array( $prefix . $idColumn . '=?', $object->$idColumn )
		);

		return TRUE;
	}

	/**
	 * Retrouver l'objet de contenu depuis une ligne de correspondance.
	 *
	 * Reproduit la résolution du coeur (system/Content/Statistics.php:646-666) :
	 * la location_key est de la forme « {app}_{CléExtension} », avec une
	 * majuscule sur la clé (« forums_Forums », pas « forums_forums »).
	 *
	 * @param	array	$map	Ligne de core_attachments_map
	 * @return	Content|null	NULL si le contenu n'existe plus ou n'est pas du contenu
	 */
	protected static function contentFor( array $map ) : ?Content
	{
		$exploded = explode( '_', $map['location_key'] );

		if ( count( $exploded ) < 2 )
		{
			return NULL;
		}

		try
		{
			$extensions = Application::load( $exploded[0] )->extensions( 'core', 'EditorLocations' );
		}
		catch( OutOfRangeException | UnexpectedValueException $e )
		{
			/* Application désinstallée ou désactivée. */
			return NULL;
		}

		if ( !isset( $extensions[ $exploded[1] ] ) )
		{
			return NULL;
		}

		$object = $extensions[ $exploded[1] ]->attachmentLookup( $map['id1'], $map['id2'], $map['id3'] );

		/* attachmentLookup peut aussi rendre un Node, une Url ou un Member
		   selon l'implémentation : seul le contenu nous intéresse. */
		return ( $object instanceof Content ) ? $object : NULL;
	}

	/**
	 * Remplacer le lien de téléchargement par l'image.
	 *
	 * @param	string	$html		HTML stocké
	 * @param	array	$attachment	Ligne de core_attachments
	 * @return	string|null			HTML modifié, ou NULL si rien à faire
	 */
	protected static function replaceInHtml( string $html, array $attachment ) : ?string
	{
		$attachId = (int) $attachment['attach_id'];

		/* Sortie rapide : inutile de construire un DOM si l'identifiant
		   n'apparaît pas, ce qui est le cas le plus fréquent. */
		if ( !str_contains( $html, 'data-fileid="' . $attachId . '"' )
			and !str_contains( $html, "data-fileid='" . $attachId . "'" ) )
		{
			return NULL;
		}

		/* Encodage des jetons AVANT le DOM. Sans cela, DOMDocument
		   transformerait « src="<fileStore.core_Attachment>/... » en
		   « src="&lt;fileStore... » et toutes les pièces jointes du message
		   deviendraient introuvables. Regex reprises telles quelles de
		   Parser::rebuildAttachmentUrls (Parser.php:3607-3609). */
		$prepared = preg_replace( '#<([^>]+?)(href|src)=(\'|")<fileStore\.([\d\w\_]+?)>/#i', '<\1\2=\3%7BfileStore.\4%7D/', $html );
		$prepared = preg_replace( '#<([^>]+?)(href|src)=(\'|")<___base_url___>/#i', '<\1\2=\3%7B___base_url___%7D/', $prepared );
		$prepared = preg_replace( '#<([^>]+?)(data-(fileid|ipshover\-target))=(\'|")<___base_url___>/#i', '<\1\2=\3%7B___base_url___%7D/', $prepared );

		$document = new DOMDocument( '1.0', 'UTF-8' );
		@$document->loadHTML( DOMDocument::wrapHtml( $prepared ) );

		$xpath   = new DOMXpath( $document );
		$anchors = $xpath->query( '//a[@data-fileid="' . $attachId . '"]' );

		if ( !$anchors->length )
		{
			return NULL;
		}

		$changed = FALSE;

		foreach ( $anchors as $anchor )
		{
			/* Le coeur regroupe TOUS les fichiers non-image dans un unique
			   paragraphe ajouté en fin de message (Editor.php:468-470). On
			   insère donc l'image APRÈS ce paragraphe plutôt que dedans :
			   un <p> imbriqué dans un <p> serait du HTML invalide. */
			$container = $anchor->parentNode;

			while ( $container !== NULL and $container->nodeName !== 'p' and $container->nodeName !== 'body' )
			{
				$container = $container->parentNode;
			}

			$paragraph = static::buildImageParagraph( $document, $attachment );

			if ( $container !== NULL and $container->nodeName === 'p' and $container->parentNode !== NULL )
			{
				$container->parentNode->insertBefore( $paragraph, $container->nextSibling );
				$anchor->parentNode->removeChild( $anchor );

				/* Paragraphe devenu vide : on l'enlève pour ne pas laisser un
				   blanc au bas du message. */
				if ( trim( $container->textContent ) === '' and !$container->getElementsByTagName( 'img' )->length )
				{
					$container->parentNode->removeChild( $container );
				}
			}
			else
			{
				$anchor->parentNode->replaceChild( $paragraph, $anchor );
			}

			$changed = TRUE;
		}

		if ( !$changed )
		{
			return NULL;
		}

		/* Sérialisation puis retrait de l'enveloppe ajoutée par wrapHtml,
		   exactement comme Parser.php:3710-3712. */
		$value = $document->saveHTML();
		$value = preg_replace( '/<meta http-equiv(?:[^>]+?)>/i', '',
			preg_replace( '/^<!DOCTYPE.+?>/', '',
				str_replace( array( '<html>', '</html>', '<body>', '</body>', '<head>', '</head>' ), '', $value )
			)
		);

		/* Restitution des chevrons échappés par DOMDocument.
		   Le pré-encodage fait plus haut ne suffit PAS : sa regex, reprise de
		   Parser::rebuildAttachmentUrls, utilise « [^>]+? » et ne peut donc
		   pas franchir le « > » d'un jeton figurant dans un attribut
		   précédent. Le gabarit du coeur écrivant data-full-image AVANT src
		   (core_global_editor.php:114-118), le src d'une image déjà présente
		   n'est jamais protégé : il ressortait en « &lt;fileStore.… », donc
		   en URL relative morte pour toutes les AUTRES pièces jointes du
		   message. C'est la parade que le coeur applique lui-même en sortie
		   (system/Text/DOMParser.php:60-64). */
		$value = static::restoreEscapedTokens( $value );

		/* Restitution des « {fileStore.x} » en « <fileStore.x> ». */
		$value = Parser::replaceFileStoreTags( $value );

		/* Mais replaceFileStoreTags ne traite que srcset|src|href|cite
		   (Parser.php:520). data-full-image, que le gabarit attachedImage
		   emploie pour la visionneuse, n'est pas dans cette liste blanche :
		   son jeton resterait en accolades et le clic sur la vignette
		   mènerait à une URL littérale « {fileStore.core_Attachment}/… ».
		   Le coeur n'a jamais ce problème puisque son gabarit écrit les
		   chevrons directement, sans passer par DOMDocument. */
		return static::restoreDataFullImage( $value );
	}

	/**
	 * Rétablir les jetons échappés par DOMDocument.
	 *
	 * Reproduit `\IPS\Text\DOMParser::parse()` (DOMParser.php:60-64). Publique
	 * parce qu'elle sert aussi à réparer les contenus déjà abîmés par les
	 * versions antérieures.
	 *
	 * @param	string	$value	HTML
	 * @return	string
	 */
	public static function restoreEscapedTokens( string $value ) : string
	{
		$value = preg_replace( '/&lt;fileStore\.([\d\w\_]+?)&gt;/i', '<fileStore.$1>', $value );

		return str_replace( '&lt;___base_url___&gt;', '<___base_url___>', $value );
	}

	/**
	 * Rétablir le jeton de stockage dans data-full-image.
	 *
	 * Isolé dans sa propre méthode parce qu'il sert aussi à réparer les
	 * messages réécrits par les versions antérieures, qui ont laissé des
	 * accolades dans cet attribut.
	 *
	 * @param	string	$value	HTML
	 * @return	string
	 */
	public static function restoreDataFullImage( string $value ) : string
	{
		return preg_replace(
			'#(data-full-image)=([\'"])(%7B|\{)fileStore\.([\d\w\_]+?)(%7D|\})/#i',
			'\1=\2<fileStore.\4>/',
			$value
		);
	}

	/**
	 * Construire le paragraphe image.
	 *
	 * Reproduit à l'identique le balisage du gabarit attachedImage du coeur
	 * (static/templates/core_global_editor.php:106-153). On le construit par
	 * le DOM plutôt qu'en concaténant du texte : les attributs sont ainsi
	 * échappés correctement, et les jetons de stockage restent en accolades
	 * jusqu'à la restitution finale.
	 *
	 * @param	DOMDocument	$document	Document courant
	 * @param	array		$attachment	Ligne de core_attachments
	 * @return	DOMElement				Le paragraphe prêt à insérer
	 */
	protected static function buildImageParagraph( DOMDocument $document, array $attachment ) : DOMElement
	{
		$paragraph = $document->createElement( 'p' );
		$image     = $document->createElement( 'img' );

		$image->setAttribute( 'class', 'ipsImage ipsImage_thumbnailed ipsRichText__align--block' );
		$image->setAttribute( 'data-fileid', (string) $attachment['attach_id'] );
		$image->setAttribute( 'data-full-image', '{fileStore.core_Attachment}/' . $attachment['attach_location'] );
		$image->setAttribute( 'src', '{fileStore.core_Attachment}/' . ( $attachment['attach_thumb_location'] ?: $attachment['attach_location'] ) );
		$image->setAttribute( 'height', (string) ( $attachment['attach_thumb_height'] ?: $attachment['attach_img_height'] ) );
		$image->setAttribute( 'width', (string) ( $attachment['attach_thumb_width'] ?: $attachment['attach_img_width'] ) );
		$image->setAttribute( 'alt', (string) $attachment['attach_file'] );
		$image->setAttribute( 'loading', 'lazy' );

		$paragraph->appendChild( $image );

		return $paragraph;
	}
}
