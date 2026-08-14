<?php
/**
 * @brief		Amorçage commun aux outils en ligne de commande
 * @package		HEIC Uploads
 */

/* PREMIÈRE instruction du fichier, avant tout effet de bord.
 *
 * Les outils chargent init.php eux-mêmes ; ils sont donc placés sous la racine
 * web du forum et joignables par une simple requête HTTP. Sans cette garde,
 * n'importe qui obtiendrait la sortie de diagnose.php — réglages, chemins de
 * stockage obscurcis, extraits de journaux — ou déclencherait une écriture en
 * base avec deploy-sync.php.
 *
 * Ce fichier porte la garde pour tout le monde, et se protège lui-même : il
 * est dans le même répertoire, donc tout aussi joignable.
 */
if ( PHP_SAPI !== 'cli' )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/* La racine du forum, trois niveaux au-dessus de applications/heicuploads/tools. */
$root = realpath( __DIR__ . '/../../..' );

if ( $root === FALSE or !is_file( $root . '/init.php' ) )
{
	fwrite( STDERR, "init.php introuvable depuis " . __DIR__ . ". Ces outils s'exécutent depuis la racine du forum :\n" );
	fwrite( STDERR, "    php applications/heicuploads/tools/<outil>.php\n" );
	exit( 1 );
}

require_once $root . '/init.php';

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	fwrite( STDERR, "init.php n'a pas initialisé la suite. Installation incomplète ?\n" );
	exit( 1 );
}
