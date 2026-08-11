<?php

/* Ces scripts chargent init.php eux-memes : sans cette garde ils seraient
   executables par une simple requete HTTP, et divulgueraient reglages,
   chemins de stockage obscurcis et journaux. */
if ( PHP_SAPI !== 'cli' )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}
/**
 * Diagnostic de bout en bout.
 *
 * Parcourt les six maillons de la chaîne dans l'ordre et s'arrête au premier
 * qui casse. À lancer depuis la RACINE du forum :
 *
 *     php applications/heicuploads/tools/diagnose.php
 */

$root = realpath( __DIR__ . '/../../..' );

if ( !is_file( $root . '/init.php' ) )
{
	fwrite( STDERR, "init.php introuvable depuis {$root}. Lancez ce script depuis la racine du forum.\n" );
	exit( 1 );
}

require_once $root . '/init.php';

use IPS\Db;
use IPS\heicuploads\Converter;
use IPS\Settings;

$ok   = fn( string $m ) => print( "  [OK]      {$m}\n" );
$ko   = fn( string $m ) => print( "  [BLOQUE]  {$m}\n" );
$info = fn( string $m ) => print( "            {$m}\n" );

$titre = function( int $n, string $t ) {
	printf( "\n=== %d. %s ===\n", $n, $t );
};


/* ------------------------------------------------------------------ */
$titre( 1, "L'application est-elle active et le réglage activé ?" );

try
{
	$enabled = Settings::i()->heicuploads_enabled;
	$enabled ? $ok( "heicuploads_enabled = 1" ) : $ko( "heicuploads_enabled = 0 — la tâche sort immédiatement." );

	$info( sprintf( "qualité %s, vignette %s, filtre %s, vitesse %s, threads %s",
		Settings::i()->heicuploads_quality,
		Settings::i()->heicuploads_thumb_quality,
		Settings::i()->heicuploads_filter,
		Settings::i()->heicuploads_speed,
		Settings::i()->heicuploads_threads ) );
}
catch( Throwable $e )
{
	$ko( "Réglages absents : " . $e->getMessage() );
}


/* ------------------------------------------------------------------ */
$titre( 2, "Le serveur sait-il convertir ?" );

$problems = Converter::diagnose();

if ( Converter::isOperational( $problems ) )
{
	$ok( "diagnose() ne signale aucun blocage." );
}
else
{
	foreach ( $problems as $p )
	{
		if ( $p['blocking'] )
		{
			$ko( $p['what'] );
			$info( "→ " . $p['fix'] );
		}
	}
}


/* ------------------------------------------------------------------ */
$titre( 3, "Y a-t-il des pièces jointes HEIC en base ?" );

try
{
	$total = Db::i()->select( 'COUNT(*)', 'core_attachments', array( Db::i()->in( 'attach_ext', array( 'heic', 'heif' ) ) ) )->first();

	if ( $total )
	{
		$ok( "{$total} pièce(s) jointe(s) avec attach_ext heic/heif." );

		foreach ( Db::i()->select( '*', 'core_attachments', array( Db::i()->in( 'attach_ext', array( 'heic', 'heif' ) ) ), 'attach_id DESC', array( 0, 3 ) ) as $a )
		{
			$info( sprintf( "id=%d ext=%s is_image=%d file=%s",
				$a['attach_id'], $a['attach_ext'], $a['attach_is_image'], $a['attach_file'] ) );
			$info( "   location = " . $a['attach_location'] );
		}
	}
	else
	{
		$ko( "Aucune ligne core_attachments en heic/heif." );
		$info( "Le fichier a peut-être été téléversé sans être rattaché à un message." );

		$temp = Db::i()->select( 'COUNT(*)', 'core_files_temp' )->first();
		$info( "core_files_temp contient {$temp} ligne(s) — un envoi jamais validé y resterait." );
	}
}
catch( Throwable $e )
{
	$ko( "Lecture de core_attachments impossible : " . $e->getMessage() );
}


/* ------------------------------------------------------------------ */
$titre( 4, "La tâche de détection est-elle installée et exécutée ?" );

try
{
	$task = Db::i()->select( '*', 'core_tasks', array( '`key`=?', 'scanHeic' ) )->first();

	$task['enabled'] ? $ok( "Tâche scanHeic présente et activée." ) : $ko( "Tâche scanHeic présente mais DÉSACTIVÉE." );

	$info( "fréquence   : " . $task['frequency'] );
	$info( "dernier run : " . ( $task['last_run'] ? date( 'Y-m-d H:i:s', $task['last_run'] ) : 'JAMAIS' ) );
	$info( "prochain    : " . date( 'Y-m-d H:i:s', $task['next_run'] ) );
	$info( "verrous     : " . $task['lock_count'] . ( $task['lock_count'] >= 3 ? "  ← bloquée, IPS l'a mise de côté" : '' ) );

	if ( !$task['last_run'] )
	{
		$info( "Une tâche jamais lancée signifie que le cron n'appelle pas IPS," );
		$info( "ou que le déclenchement par le trafic n'a pas encore eu lieu." );
	}
}
catch( UnderflowException $e )
{
	$ko( "La tâche scanHeic n'est PAS dans core_tasks — data/tasks.json n'a pas été pris en compte." );
}
catch( Throwable $e )
{
	$ko( "Lecture de core_tasks impossible : " . $e->getMessage() );
}


/* ------------------------------------------------------------------ */
$titre( 5, "La table de suivi et la file d'attente" );

try
{
	$rows = iterator_to_array( Db::i()->select( 'status, COUNT(*) as total', 'heicuploads_map', NULL, NULL, NULL, 'status' ) );

	if ( $rows )
	{
		foreach ( $rows as $r )
		{
			$ok( "heicuploads_map : {$r['total']} en « {$r['status']} »" );
		}

		foreach ( Db::i()->select( '*', 'heicuploads_map', array( 'status=?', 'failed' ), 'updated DESC', array( 0, 3 ) ) as $f )
		{
			$info( "échec attach_id={$f['attach_id']} après {$f['attempts']} tentative(s) : " . $f['error_message'] );
		}
	}
	else
	{
		$ko( "heicuploads_map est VIDE — la tâche n'a jamais rien détecté." );
	}
}
catch( Throwable $e )
{
	$ko( "Table heicuploads_map absente ou illisible : " . $e->getMessage() );
}

try
{
	$queue = iterator_to_array( Db::i()->select( '*', 'core_queue', array( '`app`=?', 'heicuploads' ) ) );

	if ( $queue )
	{
		foreach ( $queue as $q )
		{
			$ok( "File : clé={$q['key']} offset={$q['offset']} données=" . $q['data'] );
		}
	}
	else
	{
		$info( "Aucun élément dans core_queue pour heicuploads." );
		$info( "Normal si tout est converti, anormal s'il reste des « pending »." );
	}
}
catch( Throwable $e )
{
	$ko( "Lecture de core_queue impossible : " . $e->getMessage() );
}


/* ------------------------------------------------------------------ */
$titre( 6, "Journaux de l'application" );

try
{
	$logs = iterator_to_array( Db::i()->select(
		'*',
		'core_log',
		array( Db::i()->in( 'category', array( 'heicuploads', 'heicuploads_install' ) ) ),
		'time DESC',
		array( 0, 10 )
	) );

	if ( $logs )
	{
		foreach ( $logs as $l )
		{
			$info( date( 'Y-m-d H:i:s', $l['time'] ) . " [{$l['category']}] " . mb_substr( $l['message'], 0, 300 ) );
		}
	}
	else
	{
		$info( "Aucune entrée — ni erreur, ni avertissement." );
	}
}
catch( Throwable $e )
{
	$ko( "Lecture de core_log impossible : " . $e->getMessage() );
}

print( "\n" );
