<?php

class Badges extends GP_Plugin {
	var $id = 'badges';

	var $types = array();

	function __construct() {
		parent::__construct();

		$this->add_action( 'init' );
		$this->add_action( 'after_notices' );
		$this->add_action( 'user_page_extra_sections' );
		$this->add_action( 'GP_Translation_create' );
		$this->add_action( 'GP_Translation_set_status', array( 'args' => 2 ) );

		GP::$router->add( '/-recursive-badge', 'badges_recursive' );
		GP::$router->add( '/badges', 'badges_stats' );
	}

	function init() {
		$this->add_badge_type( 'multilingual', __( 'Multilingual', 'badges' ), __( 'Translate strings into 3 different languages', 'badges' ), 3 );
		$this->add_badge_type( 'speedrun', __( 'Speed Run', 'badges' ), __( 'Translate five strings in under a minute.', 'badges' ) );
		$this->add_badge_type( 'firstbirthday', __( 'First Birthday', 'badges' ), __( 'Log in over a year after you registered.', 'badges' ) );
		$this->add_badge_type( 'yesman', __( 'Yes Man', 'badges' ), __( 'Approve 500 strings.', 'badges' ), 500 );
		$this->add_badge_type( 'rejectionist', __( 'Rejectionist', 'badges' ), __( 'Reject 100 strings.', 'badges' ), 100 );
		$this->add_badge_type( 'recursive', __( 'Recursive', 'badges' ), preg_replace( '/(.)$/', '<span onclick="location.href=\'' . gp_url( '/-recursive-badge' ) . '\'">$1</span>', __( 'Get this badge.', 'badges' ) ) );
		$this->add_badge_type( 'centennial', __( 'Centennial', 'badges' ),__( 'Translate 100 strings.', 'badges' ), 100 );
		$this->add_badge_type( 'thousandwords', __( 'Worth A Thousand Words', 'badges' ),__( 'Translate 1000 strings.', 'badges' ), 1000 );
		$this->add_badge_type( 'millionare', __( 'Millionare', 'badges' ),__( 'Translate 1,000,000 strings.', 'badges' ), 1000000 );
		$this->add_badge_type( 'revisionist', __( 'Revisionist', 'badges' ),__( 'Edit 200 strings translated by other users.', 'badges' ), 200 );

		if ( GP::$user->logged_in() && !$this->get_badge_progress( 'firstbirthday' ) ) {
			if ( strtotime( GP::$user->current()->user_registered ) < time() - 31556926 )
				$this->set_badge_progress( 'firstbirthday', 1 );
		}
	}

	function after_notices() {
		if ( gp_notice( 'badge' ) ): ?>
			<div class="notice" style="background-color: #6cf;">
				<?php echo gp_notice( 'badge' ); ?>
			</div>
		<?php endif;
	}

	function add_badge_type( $id, $name, $description, $progress_needed = 1 ) {
		$this->types[$id] = (object) compact( 'id', 'name', 'description', 'progress_needed' );
	}

	function GP_Translation_create( $translation ) {
		global $gpdb;
		$user = GP::$user->get( $translation->user_id );
		if ( !$user )
			return;
		if ( $this->get_badge_progress( 'multilingual', $user ) < 3 ) {
			$languages = (array) $user->get_meta( $gpdb->prefix . 'badges_multilingual' );
			$language = GP::$translation_set->get( $translation->translation_set_id )->locale;
			if ( !in_array( $language, $languages ) ) {
				$languages[] = $language;
				$this->add_badge_progress( 'multilingual', 1, $user );
				if ( $this->get_badge_progress( 'multilingual', $user ) < 3 )
					$user->set_meta( $gpdb->prefix . 'badges_multilingual', $languages );
				else
					$user->delete_meta( $gpdb->prefix . 'badges_multilingual' );
			}
		}
		if ( !$this->get_badge_progress( 'speedrun', $user ) ) {
			$last5 = array_filter( (array) $user->get_meta( $gpdb->prefix . 'badges_speedrun' ), lambda( '$time', '$time > time() - 60' ) );
			$last5[] = time();

			if ( count( $last5 ) >= 5 ) {
				$this->set_badge_progress( 'speedrun', 1, $user );
				$user->delete_meta( $gpdb->prefix . 'badges_speedrun' );
			} else {
				$user->set_meta( $gpdb->prefix . 'badges_speedrun', $last5 );
			}
		}
		if ( $this->get_badge_progress( 'centennial', $user ) < 100 ) {
			$this->add_badge_progress( 'centennial', 1, $user );
		}
		if ( $this->get_badge_progress( 'thousandwords', $user ) < 1000 ) {
			$this->add_badge_progress( 'thousandwords', 1, $user );
		}
		if ( $this->get_badge_progress( 'millionare', $user ) < 1000000 ) {
			$this->add_badge_progress( 'millionare', 1, $user );
		}
		if ( $this->get_badge_progress( 'revisionist', $user ) < 200 ) {
			$prev_translations = GP::$translation->for_translation( (object) array( 'id' => GP::$translation_set->get( $translation->translation_set_id )->project_id ), GP::$translation_set->get( $translation->translation_set_id ), array( 'original_id' => $translation->original_id ), array( 'by' => 'translation_date_added', 'how' => 'desc' ) );
			if ( isset( $prev_translations[1] ) && $prev_translations[1]->user_id != $user->id ) {
				$this->add_badge_progress( 'revisionist', 1, $user );
			}
		}
	}

	function GP_Translation_set_status( $status, $translation ) {
		if ( $status == 'current' )
			$this->add_badge_progress( 'yesman' );
		elseif ( $status == 'rejected' )
			$this->add_badge_progress( 'rejectionist' );
	}

	private function get_user( $user = 0 ) {
		if ( is_object( $user ) )
			$user = $user->id;
		if ( !$user )
			$user = GP::$user->current()->id;
		if ( !$user )
			return false;

		$user = GP::$user->get( $user );
		return $user ? $user : false;
	}

	function get_badge_progress( $id, $user = 0 ) {
		global $gpdb;

		if ( !isset( $this->types[$id] ) )
			return false;

		if ( !$user = $this->get_user( $user ) )
			return false;

		$badges = $user->get_meta( $gpdb->prefix . 'badges' );

		return isset( $badges[$id] ) ? min( $badges[$id], $this->types[$id]->progress_needed ) : 0;
	}

	function add_badge_progress( $id, $progress = 1, $user = 0 ) {
		global $gpdb;

		if ( !isset( $this->types[$id] ) )
			return false;

		if ( !$user = $this->get_user( $user ) )
			return false;

		$badges = $user->get_meta( $gpdb->prefix . 'badges' );
		if ( $badges[$id] < $this->types[$id]->progress_needed && $badges[$id] + $progress >= $this->types[$id]->progress_needed ) {
			gp_notice_set( sprintf( __( '<strong>Badge Unlocked!</strong> %1$s - %2$s', 'badges' ), $this->types[$id]->name, $this->types[$id]->description ), 'badge' );
		}
		$badges[$id] += $progress;
		$user->set_meta( $gpdb->prefix . 'badges', $badges );

		return true;
	}

	function set_badge_progress( $id, $progress, $user = 0 ) {
		global $gpdb;

		if ( !isset( $this->types[$id] ) )
			return false;

		if ( !$user = $this->get_user( $user ) )
			return false;

		$badges = $user->get_meta( $gpdb->prefix . 'badges' );
		if ( $badges[$id] < $this->types[$id]->progress_needed && $progress >= $this->types[$id]->progress_needed ) {
			gp_notice_set( sprintf( __( '<strong>Badge Unlocked!</strong> %1$s - %2$s', 'badges' ), $this->types[$id]->name, $this->types[$id]->description ), 'badge' );
		}
		$badges[$id] = $progress;
		$user->set_meta( $gpdb->prefix . 'badges', $badges );

		return true;
	}

	function user_page_extra_sections( $user ) {
		if ( $badges = $user->get_meta( $gpdb->prefix . 'badges' ) ) {
			echo '<h2 id="badges">', __( 'Badges', 'badges' ), '</h2>';

			ksort( $badges );

			foreach ( $badges as $badge => $progress ) {
				if ( !isset( $this->types[$badge] ) )
					continue;
				$data = $this->types[$badge];
				// TODO: i18n
				echo '<span',  $progress >= $data->progress_needed ? '' : ' style="color: #777;"', '><strong>', $data->name, '</strong>';
				if ( $data->progress_needed > 1 )
					echo ' <span class="secondary">', min( $progress, $data->progress_needed ), '/', $data->progress_needed, ' (', round( min( $progress / $data->progress_needed, 1 ) * 100 ), '%)</span>';
				echo ' - ', $data->description, '</span><br/>';
			}
		}
	}
}

function badges_recursive() {
	GP::$plugins->badges->set_badge_progress( 'recursive', 1 );
	gp_redirect( wp_get_referer() ? wp_get_referer() : gp_url() );
}

function badges_stats() {
	global $wp_users_object, $gpdb;

	$badges =& GP::$plugins->badges;
	$types  =& $badges->types;
	$stats  =  array_map( returner( array(
		'total_obtained'      => 0,
		'total_have_progress' => 0,
		'total_progress'      => 0,
	) ), $types );

	$all_users = GP::$user->map( $wp_users_object->get_user( $gpdb->get_col( 'SELECT `ID` FROM `' . $gpdb->users . '`' ) ) );

	foreach ( $all_users as $user ) {
		if ( $user->badges ) {
			foreach ( $user->badges as $badge => $progress ) {
				if ( $types[$badge]->progress_needed <= $progress ) {
					$stats[$badge]['total_obtained']++;
				} else {
					$stats[$badge]['total_have_progress']++;
					$stats[$badge]['total_progress'] += min( $types[$badge]->progress_needed, $progress );
				}
			}
		}
	}

	$i = 0;
	gp_title( __( 'Badges &lt; GlotPress', 'badges' ) );
	gp_breadcrumb( array( gp_link_get( gp_url_current(), __( 'Badges', 'badges' ) ) ) );
	gp_tmpl_header();
	echo '<h2>';
	_e( 'Badges', 'badges' );
	echo '</h2>';
	foreach ( $types as $type => $badge ) {
		if ( !array_sum( $stats[$type] ) )
			continue;
		echo '<div class="half">';
		echo '<h3 id="', $type, '" style="margin-bottom: 0;">', $badge->name, '</h3>';
		echo '<p style="margin-top: 0; margin-bottom: .3em;">', $badge->description, '</p>';
		echo '<ul style="padding-left: 15px; margin-top: 0;">';
		if ( $stats[$type]['total_obtained'] ) {
			echo '<li>', sprintf( __( '%1$d%% (%2$d) of this sites\'s users have this badge.', 'badges' ), $stats[$type]['total_obtained'] / count( $all_users ) * 100, $stats[$type]['total_obtained'] ), '</li>';
		}
		if ( $badge->progress_needed != 1 && $stats[$type]['total_have_progress'] ) {
			echo '<li>', sprintf( __( '%1$d%% (%2$d) of this sites\'s users are earning this badge.', 'badges' ), ( $stats[$type]['total_obtained'] + $stats[$type]['total_have_progress'] ) / count( $all_users ) * 100, ( $stats[$type]['total_obtained'] + $stats[$type]['total_have_progress'] ) ), '</li>';
			echo '<li>', sprintf( __( 'The average progress for users currently earning this badge is %1$d/%2$d.', 'badges' ), $stats[$type]['total_progress'] / $stats[$type]['total_have_progress'], $badge->progress_needed ), '</li>';
		}
		echo '</ul>';
		echo '</div>';
		if ( $i++ % 2 )
			echo '<br class="clear"/>';
	}
	echo '<br class="clear"/>';
	gp_tmpl_footer();
}

//GP::$plugins->badges = new Badges;