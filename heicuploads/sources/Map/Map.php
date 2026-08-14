<?php
/**
 * @brief		Table de suivi des conversions et machine à états
 * @package		HEIC Uploads
 */

namespace IPS\heicuploads;

use IPS\Db;
use Throwable;
use function count;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Propriétaire unique de `heicuploads_map`.
 *
 * Cette classe existe parce que la table était interrogée depuis six fichiers
 * — la tâche, la file, l'écran AdminCP et trois outils — chacun avec sa propre
 * idée de la machine à états. Le prédicat des lignes abandonnées, en
 * particulier, était écrit à trois endroits : il suffisait d'en corriger deux
 * pour croire le travail fait.
 *
 * Aucun autre fichier ne doit écrire `heicuploads_map` ni manipuler les
 * littéraux « pending », « converted » et « failed ».
 *
 * ÉTATS ET TRANSITIONS
 *
 *   (détection)  →  pending, attempts=0
 *   pending      →  pending, attempts+1        début de tentative
 *   pending      →  converted                  conversion complète
 *   pending      →  failed                     plafond de tentatives atteint
 *   pending      →  failed                     tentative interrompue (fermeture)
 *   failed       →  pending, attempts=0        relance depuis l'AdminCP
 *
 * « converted » est un état terminal : plus rien ne relit une ligne convertie.
 * Il ne doit donc être posé qu'une fois TOUT fait — conversion, réécriture du
 * message et suppression de l'original.
 */
class Map
{
	public const TABLE = 'heicuploads_map';

	public const PENDING   = 'pending';
	public const CONVERTED = 'converted';
	public const FAILED    = 'failed';

	/**
	 * Tentatives avant abandon définitif d'une conversion.
	 *
	 * Constante et non réglage : personne n'a jamais eu besoin d'ajuster ça,
	 * et un plafond est indispensable pour garantir que la file se termine
	 * même si un fichier échoue systématiquement.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Délai avant de considérer une tentative comme interrompue, en secondes.
	 *
	 * Il ne suffit PAS qu'une ligne soit « en attente » avec son plafond
	 * épuisé pour être abandonnée : c'est exactement l'état d'une conversion
	 * en cours sur sa dernière tentative. `beginAttempt()` pose `attempts` à 3
	 * puis le travail dure environ deux secondes ; la tâche de détection,
	 * qui passe chaque minute, tomberait tôt ou tard dans cette fenêtre et
	 * déclarerait en échec une conversion parfaitement saine — fausse alerte
	 * dans les journaux, faux compteur dans l'AdminCP, bouton de relance
	 * proposé pour rien.
	 *
	 * D'où le délai. `beginAttempt()` rafraîchit `updated` : une ligne dont
	 * `updated` est récent est en cours, pas abandonnée.
	 *
	 * 300 s est un ordre de grandeur, pas une mesure : la conversion mesurée
	 * dure 1,87 s, on laisse cent cinquante fois cette durée pour couvrir un
	 * fichier hors norme ou une machine chargée, tout en fermant la ligne dans
	 * les minutes qui suivent une vraie interruption.
	 */
	public const STALE_AFTER = 300;

	/**
	 * Délai avant de rejouer une tentative en échec, en secondes.
	 *
	 * Sans lui, `markFailed()` laisse la ligne « en attente » avec son
	 * `created` d'origine : elle reste la plus ancienne convertible et repart
	 * au passage de file SUIVANT, puis au suivant. Les trois tentatives se
	 * consommaient d'affilée, en quelques secondes, sans laisser à un verrou
	 * ou à un montage de stockage le temps de revenir — exactement ce que le
	 * plafond de tentatives prétend absorber.
	 *
	 * 120 s est un ordre de grandeur, PAS une mesure : deux minutes couvrent
	 * un remontage ou un verrou passager, et étalent les trois tentatives sur
	 * quatre minutes au lieu de trois cycles consécutifs.
	 */
	public const RETRY_AFTER = 120;

	/**
	 * Valeur de `attempts` marquant une ligne close, sans reprise possible.
	 *
	 * Elle sépare deux choses que « failed » confondait : l'échec qu'une
	 * reprise peut corriger, et celui dont on sait qu'aucune reprise ne
	 * changera rien — la pièce jointe a disparu. Sans cette distinction, un
	 * membre retirant une photo de l'éditeur, geste parfaitement normal,
	 * allumait dans l'AdminCP un avertissement que plus rien ne pouvait
	 * éteindre : relancer remettait la ligne en attente, elle retombait sur la
	 * même disparition, et le bandeau revenait.
	 *
	 * 255 est le plafond exact d'un TINYINT UNSIGNED (`data/schema.json`) : la
	 * valeur passe sans montée de schéma. Aucune suite de tentatives réelles
	 * ne peut l'atteindre, `nextConvertible()` n'ouvrant une tentative que
	 * sous MAX_ATTEMPTS. Constante distincte et non « MAX_ATTEMPTS + 1 » :
	 * relever un jour le plafond rendrait « relançables » les lignes closes
	 * d'hier.
	 */
	public const CLOSED_ATTEMPTS = 255;

	/**
	 * Enregistrer une pièce jointe à convertir.
	 *
	 * Le 4e argument, et non le 3e. La signature est
	 * `insert( $table, $set, $odkUpdate = FALSE, $ignoreErrors = FALSE )`
	 * (system/Db/Db.php:996) : le 3e produit un « ON DUPLICATE KEY UPDATE »
	 * sur TOUTES les colonnes, le 4e un « INSERT IGNORE ».
	 *
	 * La différence n'est pas cosmétique. Avec le 3e, retomber sur une ligne
	 * existante la RÉÉCRIT — statut remis à « pending », tentatives à zéro,
	 * horodatage de création écrasé. Une pièce jointe déjà convertie ou
	 * définitivement en échec repartirait pour un tour, et l'ordre FIFO de la
	 * file serait faussé. C'est « INSERT IGNORE » qu'on veut : sur doublon, ne
	 * rien faire.
	 *
	 * En marche normale le cas ne se présente pas — la détection ne regarde
	 * que les identifiants strictement supérieurs au plus grand déjà
	 * enregistré. Il se présente entre deux passages concurrents, qui voient
	 * le même repère haut.
	 *
	 * @param	int	$attachId	Identifiant de la pièce jointe
	 * @return	void
	 */
	public static function record( int $attachId ) : void
	{
		Db::i()->insert( static::TABLE, array(
			'attach_id' => $attachId,
			'status'    => static::PENDING,
			'attempts'  => 0,
			'created'   => time(),
			'updated'   => time(),
		), FALSE, TRUE );
	}

	/**
	 * Repère haut du balayage.
	 *
	 * Le plus grand attach_id déjà enregistré. attach_id étant
	 * auto-incrémenté, ce simple repère fait avancer la détection sans
	 * filtrage a posteriori.
	 *
	 * @return	int	0 si la table est vide
	 */
	public static function highWaterMark() : int
	{
		return (int) ( Db::i()->select( 'MAX(attach_id)', static::TABLE )->first() ?: 0 );
	}

	/**
	 * La plus ancienne ligne encore convertible.
	 *
	 * On ne pagine pas : le statut des lignes change au fil de l'eau, un
	 * offset serait incohérent. Le plafond de tentatives garantit la
	 * terminaison même si un fichier échoue systématiquement.
	 *
	 * @return	array|null	NULL s'il n'y a plus rien à convertir
	 */
	public static function nextConvertible() : ?array
	{
		try
		{
			return Db::i()->select(
				'*',
				static::TABLE,
				static::convertibleCondition(),
				'created ASC',
				array( 0, 1 )
			)->first();
		}
		catch( Throwable $e )
		{
			return NULL;
		}
	}

	/**
	 * Condition des lignes réellement convertibles MAINTENANT.
	 *
	 * « En attente », plafond non atteint, et — pour celles qui ont déjà
	 * consommé une tentative — pas retouchées depuis RETRY_AFTER.
	 *
	 * Le prédicat est ici et nulle part ailleurs : `nextConvertible()` et
	 * `countConvertible()` doivent voir exactement le même ensemble, faute de
	 * quoi la détection met en file un travail qui n'aura rien à traiter, et
	 * l'avancement affiché compte des lignes que la file ne prendra pas.
	 *
	 * `attempts=0 OR ...` plutôt qu'un `updated<?` seul : une relance depuis
	 * l'AdminCP remet `attempts` à zéro, et n'a aucune raison d'attendre deux
	 * minutes de plus. Une pièce jointe qui vient d'être détectée non plus.
	 *
	 * @return	array	Clause WHERE
	 */
	public static function convertibleCondition() : array
	{
		return array(
			'status=? AND attempts<? AND ( attempts=0 OR updated<? )',
			static::PENDING,
			static::MAX_ATTEMPTS,
			time() - static::RETRY_AFTER
		);
	}

	/**
	 * Reste-t-il quelque chose à convertir ?
	 *
	 * Compte les lignes RÉELLEMENT convertibles, plafond de tentatives
	 * compris — et non toutes les « en attente ». Une ligne abandonnée ne
	 * doit pas faire croire à du travail restant.
	 *
	 * @return	int
	 */
	public static function countConvertible() : int
	{
		return (int) Db::i()->select( 'COUNT(*)', static::TABLE, static::convertibleCondition() )->first();
	}

	/**
	 * Comptage par état, pour l'AdminCP et le diagnostic.
	 *
	 * @return	array	pending, converted, failed — toujours les trois clés
	 */
	public static function counts() : array
	{
		$counts = array( static::PENDING => 0, static::CONVERTED => 0, static::FAILED => 0 );

		foreach ( Db::i()->select( 'status, COUNT(*) as total', static::TABLE, NULL, NULL, NULL, 'status' ) as $row )
		{
			$counts[ $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Comptabiliser une tentative, AVANT le travail.
	 *
	 * L'ordre est délibéré : si le processus est tué en cours de conversion
	 * — dépassement mémoire, redémarrage de PHP —, la ligne ne sera pas
	 * rejouée indéfiniment. La contrepartie est l'état « abandonné », que
	 * closeAbandoned() rattrape.
	 *
	 * @param	array	$row	Ligne concernée
	 * @return	void
	 */
	public static function beginAttempt( array $row ) : void
	{
		Db::i()->update( static::TABLE, array(
			'attempts' => $row['attempts'] + 1,
			'updated'  => time(),
		), array( 'id=?', $row['id'] ) );
	}

	/**
	 * Marquer une conversion aboutie.
	 *
	 * À n'appeler qu'une fois TOUT fait : « converted » est terminal, plus
	 * rien ne relit la ligne.
	 *
	 * @param	array	$row	Ligne concernée
	 * @return	void
	 */
	public static function markConverted( array $row ) : void
	{
		Db::i()->update( static::TABLE, array(
			'status'        => static::CONVERTED,
			'error_message' => NULL,
			'updated'       => time(),
		), array( 'id=?', $row['id'] ) );
	}

	/**
	 * Consigner un échec de tentative.
	 *
	 * La ligne ne bascule en « failed » qu'une fois le plafond atteint : en
	 * deçà elle reste « pending » et sera rejouée, ce qui absorbe les
	 * incidents passagers (verrou, mémoire momentanément saturée).
	 *
	 * @param	array	$row		Ligne concernée
	 * @param	string	$message	Cause, telle qu'elle sera montrée
	 * @return	bool				TRUE si l'échec est définitif
	 */
	public static function markFailed( array $row, string $message ) : bool
	{
		$definitive = ( $row['attempts'] + 1 ) >= static::MAX_ATTEMPTS;

		Db::i()->update( static::TABLE, array(
			'status'        => $definitive ? static::FAILED : static::PENDING,
			'error_message' => mb_substr( $message, 0, 1000 ),
			'updated'       => time(),
		), array( 'id=?', $row['id'] ) );

		return $definitive;
	}

	/**
	 * Clore définitivement une ligne, sans consommer de tentative.
	 *
	 * La ligne prend CLOSED_ATTEMPTS, ce qui la distingue d'un échec ordinaire
	 * arrivé au plafond : elle n'est plus comptée parmi les blocages et le
	 * bouton de relance de l'AdminCP ne la reprend pas. Sans quoi elle
	 * rallumait indéfiniment un avertissement que rien ne pouvait éteindre.
	 *
	 * Pour les causes dont on sait qu'aucune reprise ne les corrigera : la
	 * pièce jointe a disparu, son contenu n'est pas une image. Les rejouer
	 * trois fois ne ferait que retarder le même constat.
	 *
	 * @param	array	$row		Ligne concernée
	 * @param	string	$message	Cause
	 * @return	void
	 */
	public static function abandon( array $row, string $message ) : void
	{
		Db::i()->update( static::TABLE, array(
			'status'        => static::FAILED,
			'attempts'      => static::CLOSED_ATTEMPTS,
			'error_message' => mb_substr( $message, 0, 1000 ),
			'updated'       => time(),
		), array( 'id=?', $row['id'] ) );
	}

	/**
	 * Condition des lignes abandonnées.
	 *
	 * « En attente », plafond de tentatives épuisé, ET plus touchée depuis
	 * STALE_AFTER secondes. Le prédicat est ici, et NULLE PART ailleurs : il
	 * était écrit à trois endroits, et une correction sur deux d'entre eux
	 * avait l'apparence du travail fait.
	 *
	 * Il est sûr : une conversion réussie passe en « converted », un échec
	 * ordinaire en « failed » au plafond. « pending » avec le plafond atteint
	 * n'a que deux causes — une tentative interrompue, ou une tentative EN
	 * COURS. C'est le délai qui les sépare.
	 *
	 * @return	array	Clause WHERE
	 */
	public static function abandonedCondition() : array
	{
		return array(
			'status=? AND attempts>=? AND updated<?',
			static::PENDING,
			static::MAX_ATTEMPTS,
			time() - static::STALE_AFTER
		);
	}

	/**
	 * Condition des lignes bloquées : échec définitif ou tentative interrompue,
	 * à l'EXCLUSION des lignes closes sans reprise possible (CLOSED_ATTEMPTS).
	 *
	 * @return	array	Clause WHERE
	 */
	public static function blockedCondition() : array
	{
		return array(
			'( ( status=? AND attempts<>? ) OR ( status=? AND attempts>=? AND updated<? ) )',
			static::FAILED,
			static::CLOSED_ATTEMPTS,
			static::PENDING,
			static::MAX_ATTEMPTS,
			time() - static::STALE_AFTER
		);
	}

	/**
	 * Combien de lignes sont bloquées, tous motifs confondus ?
	 *
	 * Échecs relançables et tentatives interrompues : l'administrateur ne fait
	 * pas la différence et n'a pas à la faire, les unes comme les autres se
	 * relancent.
	 *
	 * @return	int
	 */
	public static function countBlocked() : int
	{
		return (int) Db::i()->select( 'COUNT(*)', static::TABLE, static::blockedCondition() )->first();
	}

	/**
	 * Fermer les tentatives interrompues.
	 *
	 * Sans cela, la ligne reste « en attente » avec son plafond épuisé : le
	 * sélecteur l'ignore, plus rien ne la rejoue, et le bloc d'état de
	 * l'AdminCP continue de l'annoncer comme normale. Ni traitée, ni visible.
	 *
	 * @return	array	Identifiants de pièces jointes fermées
	 */
	public static function closeAbandoned() : array
	{
		/* Condition évaluée UNE fois : elle contient un time(), et la rappeler
		   pour l'UPDATE pourrait rattraper une ligne devenue périmée entre les
		   deux requêtes — fermée sans figurer dans la liste rendue. */
		$condition = static::abandonedCondition();

		$abandoned = iterator_to_array( Db::i()->select( 'attach_id', static::TABLE, $condition ) );

		if ( !count( $abandoned ) )
		{
			return array();
		}

		Db::i()->update( static::TABLE, array(
			'status'        => static::FAILED,
			'error_message' => 'Conversion interrupted before completion (process stopped); the cap of ' . static::MAX_ATTEMPTS . ' attempts was reached.',
			'updated'       => time(),
		), $condition );

		return $abandoned;
	}

	/**
	 * Remettre en attente tout ce qui est bloqué.
	 *
	 * @return	int	Nombre de lignes remises en attente
	 */
	public static function retryBlocked() : int
	{
		$blocked = static::countBlocked();

		if ( !$blocked )
		{
			return 0;
		}

		/* `created` est rafraîchi, délibérément. C'est la colonne de tri de la
		   file (`created ASC`) : sans cela, un lot relancé passerait DEVANT
		   les photos que les membres viennent d'envoyer, alors que celles-ci
		   sont les seules à courir après une échéance — la fenêtre de
		   rédaction, passé laquelle le message garde un lien de
		   téléchargement. Une relance manuelle n'est jamais urgente ; un envoi
		   en cours, si. */
		Db::i()->update( static::TABLE, array(
			'status'        => static::PENDING,
			'attempts'      => 0,
			'error_message' => NULL,
			'created'       => time(),
			'updated'       => time(),
		), static::blockedCondition() );

		return $blocked;
	}

	/**
	 * Les conversions abouties, pour les outils de vérification.
	 *
	 * @param	int|null	$limit	Nombre maximal de lignes
	 * @param	string		$order	Tri
	 * @return	iterable
	 */
	public static function converted( ?int $limit = NULL, string $order = 'attach_id ASC' ) : iterable
	{
		return Db::i()->select(
			'*',
			static::TABLE,
			array( 'status=?', static::CONVERTED ),
			$order,
			$limit ? array( 0, $limit ) : NULL
		);
	}

	/**
	 * Les derniers échecs, pour le diagnostic.
	 *
	 * @param	int	$limit	Nombre maximal de lignes
	 * @return	iterable
	 */
	public static function recentFailures( int $limit = 3 ) : iterable
	{
		return Db::i()->select(
			'*',
			static::TABLE,
			array( 'status=?', static::FAILED ),
			'updated DESC',
			array( 0, $limit )
		);
	}
}
