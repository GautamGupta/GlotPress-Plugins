<?php

class PlusRep extends GP_Plugin {
	var $id = 'plusrep';

	var $points_for_translate = 2;
	var $points_for_accepted  = 1;
	var $points_for_accepting = 1;

	var $template;
	var $template_args;

	function __construct() {
		parent::__construct();

		$this->add_action( 'init' );
		$this->add_action( 'GP_Translation_create' );
		$this->add_action( 'GP_Translation_set_status', array( 'args' => 2 ) );
		$this->add_action( 'pre_tmpl_load', array( 'args' => 2 ) );
		$this->add_action( 'after_notices' );
		$this->add_filter( 'gp_pagination' );

		$language = '/projects/(.+?)/(' . implode('|', array_map( lambda( '$x', '$x->slug' ), GP_Locales::locales() ) ) . ')/([^_/][^/]*)';
		GP::$router->add( $language . '/-add-reputation-reward', array( 'PlusRep_Route', 'add_reward_get' ) );
		GP::$router->add( $language . '/-add-reputation-reward', array( 'PlusRep_Route', 'add_reward_post' ), 'post' );
		GP::$router->add( $language . '/-remove-reputation-reward', array( 'PlusRep_Route', 'remove_reward_get' ) );

		new GP_Translation_Set_Tag( __( 'Reward', 'plusrep' ), array( &$this, 'tag_reward' ), '#0df' );
		new GP_Project_Tag( __( 'Reward', 'plusrep' ), array( &$this, 'tag_reward_project' ), '#0df' );
	}

	function init() {
		if ( isset( GP::$plugins->badges ) ) {
			GP::$plugins->badges->add_badge_type( 'plusrep_organdonor', __( 'Organ Donor', 'plusrep' ), __( 'Put 10 times the number of organs in the human body of your reputation in reputation rewards.', 'plusrep' ), 780 );
		}
	}

	function GP_Translation_create( $translation ) {
		if ( $GLOBALS['dont_give_rep'] ) {
			$this->update_option( 'fromgame', $this->get_option( 'fromgame' ) + 1 );
			return;
		}
		$this->update_option( 'notfromgame', $this->get_option( 'notfromgame' ) + 1 );

		$bonus_rep = 0;
		if ( $set_rewards = $this->get_option( 'reward' . $translation->translation_set_id ) ) {
			if ( count( GP::$translation->find( array( 'original_id' => $translation->original_id, 'translation_set_id' => $translation->translation_set_id, 'status' => array( 'current', 'waiting' ) ) ) ) == 1 ) {
				$new_set_rewards = array();
				foreach ( $set_rewards as $user_id => $set_reward ) {
					$bonus_rep += $set_reward->reward;
					$set_reward->num--;
					if ( $set_reward->num )
						$new_set_rewards[$user_id] = $set_reward;
				}
				if ( $new_set_rewards )
					$this->update_option( 'reward' . $translation->translation_set_id, $new_set_rewards );
				else
					$this->delete_option( 'reward' . $translation->translation_set_id );
			}
		}

		if ( $user = GP::$user->get( $translation->user_id ) )
			$user->set_meta( 'reputation', $user->get_meta( 'reputation' ) + $this->points_for_translate + $bonus_rep );
	}

	function GP_Translation_set_status( $status, $translation ) {
		if ( $status != 'current' )
			return;

		$user = GP::$user->current();
		$translator = GP::$user->get( $translation->user_id );
		if ( !$translator || !$user )
			return;

		$user->set_meta( 'reputation', $user->get_meta( 'reputation' ) + $this->points_for_accepting );
		$translator->set_meta( 'reputation', $translator->get_meta( 'reputation' ) + $this->points_for_accepted );
	}

	function pre_tmpl_load( $template, $args ) {
		if ( !isset( $this->template ) ) {
			$this->template = $template;
			$this->template_args = $args;
		}
	}

	function gp_pagination( $a ) {
		if ( $this->template == 'translations' && !isset( $this->_injected_add_bounty_link ) ) {
			$this->_injected_add_bounty_link = true;
			if ( !GP::$user->logged_in() )
				return $a;

			$set_rewards = $this->get_option( 'reward' . $this->template_args['translation_set']->id );
			if ( isset( $set_rewards[GP::$user->current()->id] ) ) {
				if ( $set_rewards[GP::$user->current()->id]->added < time() - 604800 || $this->template_args['translation_set']->untranslated_count() + $this->template_args['translation_set']->fuzzy_count() == 0 )
					echo ' <span class="separator">&bull;</span> <a href="', gp_url( array( GP::$router->request_uri(), '-remove-reputation-reward' ) ), '">', __( 'Remove Reward', 'plusrep' ), '</a>';

				return $a;
			}
			if ( $this->template_args['translation_set']->untranslated_count() + $this->template_args['translation_set']->fuzzy_count() == 0 )
				return $a;

			echo ' <span class="separator">&bull;</span> <a href="', gp_url( array( GP::$router->request_uri(), '-add-reputation-reward' ) ), '">', __( 'Add Reward', 'plusrep' ), '</a>';
		}

		return $a;
	}

	function after_notices() {
		if ( $this->template == 'translations' ) {
			$set_rewards = $this->get_option( 'reward' . $this->template_args['translation_set']->id );
			if ( !$set_rewards )
				return;
			$set_reward = (object) array( 'num' => 0, 'reward' => 0, 'up_to' => false );
			foreach ( $set_rewards as $reward ) {
				if ( $set_reward->num && $set_reward->num != $reward->num )
					$set_reward->up_to = true;
				$set_reward->num = max( $set_reward->num, $reward->num );
				$set_reward->reward += $reward->reward;
			}
			echo '<div class="notice" style="margin: 3px 0; background-color: #0a0; color: #fff;">', sprintf( $set_reward->up_to ? __( 'Each of the next <strong>%1$d</strong> translations made for this translation set is worth up to <strong>%2$d</strong> extra reputation points.', 'plusrep' ) : __( 'Each of the next <strong>%1$d</strong> translations made for this translation set is worth <strong>%2$d</strong> extra reputation points.', 'plusrep' ), $set_reward->num, $set_reward->reward ), '</div>';
		}
	}

	function tag_reward( $set, $project ) {
		$set_reward = $this->get_option( 'reward' . $set->id );
		return !!$set_reward;
	}

	function tag_reward_project( $project ) {
		foreach ( GP::$translation_set->by_project_id( $project->id ) as $set ) {
			if ( GP::$default_tags->my_language( $set, $project ) && $this->get_option( 'reward' . $set->id ) )
				return true;
		}
		return false;
	}
}

class PlusRep_Route extends GP_Route {
	function add_reward_get( $project_slug, $locale_slug, $set_slug ) {
		$this->logged_in_or_forbidden();

		$project = GP::$project->by_path( $project_slug );
		$translation_set = GP::$translation_set->by_project_id_slug_and_locale( $project->id, $set_slug, $locale_slug );
		$locale = GP_Locales::by_slug( $locale_slug );
		$rewards = GP::$plugins->plusrep->get_option( 'reward' . $translation_set->id );

		if ( isset( $rewards[GP::$user->current()->id] ) || $translation_set->untranslated_count() + $translation_set->fuzzy_count() == 0 ) {
			$this->redirect();
			return;
		}

		gp_breadcrumb( array( gp_project_links_from_root( $project ),
			gp_link_get( dirname( gp_url_current() ), $locale->english_name . 'default' != $translation_set->slug ? ' '.$translation_set->name : '' ),
			gp_link_get( gp_url_current(), __( 'Add Reward', 'plusrep' ) )
		) );
		gp_title( sprintf( __( 'Add Reward &lt; Translations &lt; %s &lt; %s &lt; GlotPress', 'plusrep' ), $translation_set->name, $project->name ) );

		wp_enqueue_script( 'jquery' );
		gp_tmpl_header();
?>
<form action="" method="post">
	<dl>
		<dt><?php _e( 'Reward per translation:', 'plusrep' ); ?></dt>
		<dd><input type="text" name="reward" id="reward" value="0"/></dd>
	</dl>
	<dl>
		<dt><?php _e( 'Number of translations to reward:', 'plusrep' ); ?></dt>
		<dd><input type="text" name="num" id="num" value="0"/></dd>
	</dl>
	<dl>
		<dt><?php _e( 'Total cost:', 'plusrep' ); ?></dt>
		<dd id="total">JavaScript Required</dd>
	</dl>
	<input type="submit" id="submit" value="<?php esc_attr_e( 'Submit', 'plusrep' ); ?>"/>
	<script type="text/javascript">
jQuery(function($){
	$('#num, #reward').keydown(function(e){
		setTimeout(function(){
			$('#total').text(isNaN($('#num').val() * $('#reward').val()) ? '?' : $('#num').val() * $('#reward').val());
			if ($('#num').val() * $('#reward').val() > <?php echo (int) GP::$user->current()->get_meta( 'reputation' ); ?>) {
				$('#total').css('color', '#f00');
				$('#submit').attr('disabled', 'disabled');
			} else {
				$('#total').css('color', '');
				$('#submit').removeAttr('disabled');
			}
		}, 0);
		if (e.keyCode == 0 || e.keyCode == 8 || e.keyCode == 9 || e.keyCode == 13 || e.keyCode == 27 || e.keyCode == 37 || e.keyCode == 39 || (e.keyCode >= 96 && e.keyCode <= 105) || (e.keyCode >= 48 && e.keyCode <= 57)) {
			return true;
		}
		if (e.keyCode == 40 && $(this).val() > 0) {
			$(this).val(+$(this).val() - 1);
		}
		if (e.keyCode == 38) {
			$(this).val(+$(this).val() + 1);
		}
		return false;
	});
	$('#total').text(0);
});</script>
</form>
<?php
		gp_tmpl_footer();
	}

	function add_reward_post( $project_slug, $locale_slug, $set_slug ) {
		$this->logged_in_or_forbidden();

		$project = GP::$project->by_path( $project_slug );
		$translation_set = GP::$translation_set->by_project_id_slug_and_locale( $project->id, $set_slug, $locale_slug );
		$locale = GP_Locales::by_slug( $locale_slug );
		$rewards = GP::$plugins->plusrep->get_option( 'reward' . $translation_set->id );

		if ( isset( $rewards[GP::$user->current()->id] ) || $translation_set->untranslated_count() + $translation_set->fuzzy_count() == 0 ) {
			$this->redirect();
			return;
		}

		if ( ( $reward = (int) gp_post( 'reward', 0 ) ) > 0 && ( $num = (int) gp_post( 'num', 0 ) ) > 0 ) {
			if ( $reward * $num > $rep = GP::$user->current()->get_meta( 'reputation' ) ) {
				$this->redirect( gp_url_current() );
				return;
			}

			$rep -= $reward * $num;

			$added = time();
			$rewards[GP::$user->current()->id] = (object) compact( 'num', 'reward', 'added' );
			GP::$user->current()->set_meta( 'reputation', $rep );
			GP::$plugins->plusrep->update_option( 'reward' . $translation_set->id, $rewards );

			if ( isset( GP::$plugins->badges ) )
				GP::$plugins->badges->add_badge_progress( 'plusrep_organdonor', $reward * $num );

			$this->redirect( dirname( gp_url_current() ) );
			return;
		}

		$this->redirect( gp_url_current() );
	}

	function remove_reward_get( $project_slug, $locale_slug, $set_slug ) {
		$this->logged_in_or_forbidden();

		$project = GP::$project->by_path( $project_slug );
		$translation_set = GP::$translation_set->by_project_id_slug_and_locale( $project->id, $set_slug, $locale_slug );
		$locale = GP_Locales::by_slug( $locale_slug );
		$rewards = GP::$plugins->plusrep->get_option( 'reward' . $translation_set->id );

		if ( !isset( $rewards[GP::$user->current()->id] ) || ( $rewards[GP::$user->current()->id]->added > time() - 604800 && $translation_set->untranslated_count() + $translation_set->fuzzy_count() != 0 ) ) {
			$this->redirect();
			return;
		}

		$reward = $rewards[GP::$user->current()->id]->reward;
		$num = $rewards[GP::$user->current()->id]->num;

		GP::$user->current()->set_meta( 'reputation', GP::$user->current()->get_meta( 'reputation' ) + $reward * $num );
		unset( $rewards[GP::$user->current()->id] );
		GP::$plugins->plusrep->update_option( 'reward' . $translation_set->id, $rewards );

		if ( isset( GP::$plugins->badges ) )
			GP::$plugins->badges->add_badge_progress( 'plusrep_organdonor', -$reward * $num );

		$this->redirect( dirname( gp_url_current() ) );
	}
}

//GP::$plugins->plusrep = new PlusRep;