<?php
/**
 * Diagnostic de bout en bout.
 *
 * Parcourt les six maillons de la chaîne dans l'ordre et s'arrête au premier
 * qui casse. À lancer depuis la RACINE du forum :
 *
 *     php applications/heicuploads/tools/diagnose.php
 */

require_once __DIR__ . '/_bootstrap.php';

use IPS\Db;
use IPS\heicuploads\Application as HeicUploadsApplication;
use IPS\heicuploads\Converter;
use IPS\heicuploads\Map;
use IPS\Settings;

$ok   = fn( string $m ) => print( "  [OK]      {$m}\n" );
$ko   = fn( string $m ) => print( "  [BLOCKED] {$m}\n" );
$info = fn( string $m ) => print( "            {$m}\n" );

$titre = function( int $n, string $t ) {
	printf( "\n=== %d. %s ===\n", $n, $t );
};


/* ------------------------------------------------------------------ */
$titre( 1, "Is the application enabled, and the setting on?" );

try
{
	$enabled = Settings::i()->heicuploads_enabled;
	$enabled ? $ok( "heicuploads_enabled = 1" ) : $ko( "heicuploads_enabled = 0 — the task exits immediately." );

	$info( sprintf( "quality %s, thumbnail %s, filter %s, speed %s, threads %s",
		Settings::i()->heicuploads_quality,
		Settings::i()->heicuploads_thumb_quality,
		Settings::i()->heicuploads_filter,
		Settings::i()->heicuploads_speed,
		Settings::i()->heicuploads_threads ) );

	/* Le repère de départ n'était affiché NULLE PART : ni ici, ni dans
	   l'AdminCP. Or c'est lui qui décide de ce qui sera converti, et un repère
	   absent suspend toute la détection en silence. */
	$baseline = HeicUploadsApplication::baseline();

	$baseline === NULL
		? $ko( "Baseline NOT SET — detection is suspended, nothing will be converted. Reinstall the application to set it." )
		: $ok( "Baseline: attach_id {$baseline}. Nothing older will ever be converted." );
}
catch( Throwable $e )
{
	$ko( "Settings missing: " . $e->getMessage() );
}


/* ------------------------------------------------------------------ */
$titre( 2, "Can this server convert?" );

$problems = Converter::diagnose();

if ( Converter::isOperational( $problems ) )
{
	$ok( "diagnose() reports no blocking problem." );
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
$titre( 3, "Are there HEIC attachments in the database?" );

try
{
	$total = Db::i()->select( 'COUNT(*)', 'core_attachments', array( Db::i()->in( 'attach_ext', Converter::SOURCE_EXTENSIONS ) ) )->first();

	if ( $total )
	{
		$ok( "{$total} attachment(s) with attach_ext heic/heif." );

		foreach ( Db::i()->select( '*', 'core_attachments', array( Db::i()->in( 'attach_ext', Converter::SOURCE_EXTENSIONS ) ), 'attach_id DESC', array( 0, 3 ) ) as $a )
		{
			$info( sprintf( "id=%d ext=%s is_image=%d file=%s",
				$a['attach_id'], $a['attach_ext'], $a['attach_is_image'], $a['attach_file'] ) );
			$info( "   location = " . $a['attach_location'] );
		}
	}
	else
	{
		$ko( "No core_attachments row with heic/heif." );
		$info( "The file may have been uploaded without being attached to a post." );

		$temp = Db::i()->select( 'COUNT(*)', 'core_files_temp' )->first();
		$info( "core_files_temp holds {$temp} row(s) — an upload never submitted would sit there." );
	}
}
catch( Throwable $e )
{
	$ko( "Cannot read core_attachments: " . $e->getMessage() );
}


/* ------------------------------------------------------------------ */
$titre( 4, "Is the detection task installed and running?" );

try
{
	$task = Db::i()->select( '*', 'core_tasks', array( '`key`=?', 'scanHeic' ) )->first();

	$task['enabled'] ? $ok( "Task scanHeic present and enabled." ) : $ko( "Task scanHeic present but DISABLED." );

	$info( "frequency : " . $task['frequency'] );
	$info( "last run  : " . ( $task['last_run'] ? date( 'Y-m-d H:i:s', $task['last_run'] ) : 'NEVER' ) );
	$info( "next run  : " . date( 'Y-m-d H:i:s', $task['next_run'] ) );
	$info( "locks     : " . $task['lock_count'] . ( $task['lock_count'] >= 3 ? "  <- stuck, IPS has set it aside" : '' ) );

	if ( !$task['last_run'] )
	{
		$info( "A task that never ran means either the cron is not calling IPS," );
		$info( "or traffic has not triggered it yet. A deploy-sync also resets this." );
	}
}
catch( UnderflowException $e )
{
	$ko( "Task scanHeic is NOT in core_tasks — data/tasks.json was not applied." );
}
catch( Throwable $e )
{
	$ko( "Cannot read core_tasks: " . $e->getMessage() );
}


/* ------------------------------------------------------------------ */
$titre( 5, "The tracking table and the queue" );

try
{
	$counts = Map::counts();

	if ( array_sum( $counts ) )
	{
		foreach ( $counts as $statut => $total )
		{
			$ok( "heicuploads_map: {$total} in \"{$statut}\"" );
		}

		/* Les lignes bloquées ne se lisent pas dans le seul compteur d'échecs :
		   une tentative interrompue reste « en attente » jusqu'à ce que la
		   tâche la ferme. */
		if ( $blocked = Map::countBlocked() )
		{
			$info( "{$blocked} blocked row(s), retryable from the AdminCP." );
		}

		foreach ( Map::recentFailures() as $f )
		{
			$info( "failed attach_id={$f['attach_id']} after {$f['attempts']} attempt(s): " . $f['error_message'] );
		}
	}
	else
	{
		$ko( "heicuploads_map is EMPTY — the task never detected anything." );
	}
}
catch( Throwable $e )
{
	$ko( "Table heicuploads_map missing or unreadable: " . $e->getMessage() );
}

try
{
	$queue = iterator_to_array( Db::i()->select( '*', 'core_queue', array( '`app`=?', 'heicuploads' ) ) );

	if ( $queue )
	{
		foreach ( $queue as $q )
		{
			$ok( "Queue: key={$q['key']} offset={$q['offset']} data=" . $q['data'] );
		}
	}
	else
	{
		$info( "No core_queue item for heicuploads." );
		$info( "Normal if everything is converted, abnormal if any row is pending." );
	}
}
catch( Throwable $e )
{
	$ko( "Cannot read core_queue: " . $e->getMessage() );
}


/* ------------------------------------------------------------------ */
$titre( 6, "Application logs" );

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
		$info( "No entry — neither error nor warning." );
	}
}
catch( Throwable $e )
{
	$ko( "Cannot read core_log: " . $e->getMessage() );
}

print( "\n" );
