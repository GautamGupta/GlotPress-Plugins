<?php

class Translation_Game extends GP_Plugin {
	var $id = 'translation-game';

	function __construct() {
		parent::__construct();

		GP::$router->add( '/game', 'translation_game_get' );
		GP::$router->add( '/game', 'translation_game_post', 'post' );
		$this->add_action( 'init' );
		$this->add_filter( 'gp_user_recent_actions', array( 'args' => '2' ) );
	}

	function init() {
		if ( isset( GP::$plugins->badges ) ) {
			GP::$plugins->badges->add_badge_type( 'game_highscore', __( 'High Score', 'translation-game' ), __( 'Earn a total score of 1000 or more in the translation game.', 'translation-game' ), 1000 );
			GP::$plugins->badges->add_badge_type( 'game_winstreak', __( 'Win Streak', 'translation-game' ), __( 'Achieve a streak of 25 or more in the translation game.', 'translation-game' ) );
			GP::$plugins->badges->add_badge_type( 'game_reputable', __( 'Reputable', 'translation-game' ), __( 'Earn 100 or more reputation in a single session of the translation game.', 'translation-game' ) );
			GP::$plugins->badges->add_badge_type( 'game_addicted', __( 'Addicted', 'translation-game' ), __( 'Play the translation game for over half an hour in a single session.', 'translation-game' ) );
			GP::$plugins->badges->add_badge_type( 'game_lightning', __( 'Lightning', 'translation-game' ), __( 'Achieve a translation speed of 200/hour or more in the translation game.', 'translation-game' ) );
		}
	}

	function show_page() {
		if ( !GP::$user->logged_in() ) {
			gp_redirect( gp_url_login() );
			exit;
		}

		$user = GP::$user->current();
		$locale = GP_Locales::by_slug( $user->get_meta( 'language' ) );

		if ( !$locale || !$locale->slug ) {
			gp_redirect( gp_url_user( $user, '-change-my-language' ) );
		}

		$session = $user->get_meta( 'translation_game_session' );

		$o = GP::$original->table;
		$ts = GP::$translation_set->table;
		$tr = GP::$translation->table;
		$p = GP::$project->table;
		$t = GP::$original->one( "
			SELECT o.*, ts.id AS tset FROM $o AS o
			INNER JOIN $ts AS ts ON o.project_id = ts.project_id
			INNER JOIN $p AS p ON p.id = o.project_id
			LEFT JOIN $tr AS t ON t.original_id = o.id AND t.translation_set_id = ts.id
			WHERE ts.locale = '%s' AND (t.translation_0 IS NULL OR t.status = 'fuzzy') AND o.status LIKE '+%%' AND o.id != %d
			ORDER BY RAND() LIMIT 1", $locale->slug, $session ? $session->translating : 0 );
		$project = GP::$project->get( $t->project_id );

		$t->references = preg_split('/\s+/', $t->references, -1, PREG_SPLIT_NO_EMPTY);
		$t->original_id = $t->id;

		if ( !$session || time() - $session->last_seen > 300 ) {
			if ( $session->num_translated ) {
				$old_sessions = (array) $user->get_meta( 'translation_game_prev_sessions' );
				$old_sessions[] = $session;
				$user->set_meta( 'translation_game_prev_sessions', array_slice( $old_sessions, -10 ) );
			}
			$session = (object) array(
				'last_seen'      => time(),
				'last_submit'    => time(),
				'playing_since'  => time(),
				'rep_earned'     => 0,
				'streak'         => 0,
				'translating'    => $t->id,
				'tset'           => $t->tset,
				'num_translated' => 0
			);
		} else {
			$session->last_seen = time();
			if ( $session->translating )
				$session->streak = 0;
			$session->translating = $t->id;
			$session->tset = $t->tset;
		}
		$user->set_meta( 'translation_game_session', $session );

		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'google-js-api' );

		wp_localize_script( 'common', '$gp_editor_options', array( 'google_translate_language' => $locale->google_code ) );

		gp_title( 'Translation Game &lt; GlotPress' );
		gp_breadcrumb( array( gp_link_get( gp_url_current(), 'Translation Game' ) ) );
		gp_tmpl_header();
?>
<script type="text/javascript">
jQuery(function($){
	$gp.notices.init();
	$('textarea').keydown(function(e){
		switch (e.keyCode) {
			case 27:
				location.href = location.href;
				break;
			case 33:
				if ($(this).prevAll('textarea').length)
					$(this).prevAll('textarea').eq(0).focus();
			case 34:
				if ($(this).nextAll('textarea').length)
					$(this).nextAll('textarea').eq(0).focus();
				break;
			case 13:
				if (!e.shiftKey)
					return true;
				if ($(this).nextAll('textarea').length)
					$(this).nextAll('textarea').eq(0).focus();
				else
					$('form').submit();
				break;
			default:
				return true;
		}
		return false;
	}).eq(0).focus();
	$('a.copy', 'tr.editor').live('click', function(){
		var link = $(this),
			original_text = link.parents('.textareas').siblings('.original').html();
		if (!original_text) original_text = link.parents('.textareas').siblings('p:not(.plural-numbers):last').children('.original').html();
		original_text = original_text.replace(/<span class=.invisibles.*?<\/span>/g, '');
		link.parent('p').siblings('textarea').html(original_text).focus();
	});
	$('a.gtranslate', 'tr.editor').live('click', function(){
		var link = $(this),
			original_text = link.parents('.textareas').siblings('.original').html();
		if (!original_text) original_text = link.parents('.textareas').siblings('p:not(.plural-numbers):last').children('.original').html();
		if (!original_text) return false;
		if (typeof google == 'undefined') {
			$gp.notices.error('Couldn&#8217;t load Google Translate library!');
			return false;
		}
		$gp.notices.notice('Translating via Google Translate&hellip;');
		google.language.translate({text: original_text, type: 'html'}, 'en', $gp_editor_options.google_translate_language, function(result) {
			if (!result.error) {
				// fix common google translate misbehaviours
				result.translation = result.translation.replace(/% (s|d)/gi, function(m, letter) {
					return '%'+letter.toLowerCase();
				});
				result.translation = result.translation.replace(/% (\d+) \$ (S|D)/gi, function(m, number, letter) {
					return '%'+number+'$'+letter.toLowerCase();
				});
				result.translation = result.translation.replace(/&lt;\/\s+([A-Z]+)&gt;/g, function(m, tag) {
					return '&lt;/'+tag.toLowerCase()+'&gt;';
				});

				link.parent('p').siblings('textarea').html(result.translation).focus();
				$gp.notices.success('Translated!');
			} else {
				$gp.notices.error('Error in translating via Google Translate: '+result.message+'!');
				link.parent('p').siblings('textarea').focus();
			}
		});
		return false;
	});
});
if (typeof google != 'undefined') google.load("language", "1");
</script>

<style type="text/css">
#sidebar {
	width: 300px;
	position: absolute;
	right: 0;
	top: 88px;
}
#stats-header {
	display: none;
}

form {
	padding-right: 315px;
}

@media only all and (max-width: 1200px) {
	#stats-header {
		display: block;
	}
	#sidebar {
		position: static;
		width: 100%;
		display: table;
	}
	#sidebar::after {
		clear: left;
		content: ".";
		visibility: hidden;
		height: 0;
		display: block;
	}
	#sidebar div {
		display: table-cell;
	}
	form {
		padding-right: 0;
	}
}
</style>

		<p>Currently translating to <strong><?php echo $locale->english_name; ?></strong>. <a href="<?php echo gp_url_user( $user, '-change-my-language' ); ?>" class="tag" style="background-color: #ccc; color: #000;">Change this</a></p>

<?php if ( $t->id ): ?>
		<form class="clearfix" method="POST">
			<table id="translations" class="translations">
				<tr row="42-15" id="editor-42-15" class="editor no-warnings" style="display: table-row;">
					<td colspan="5">
						<div class="strings">
						<?php if ( !$t->plural ): ?>
						<p class="original"><?php echo prepare_original( esc_translation($t->singular) ); ?></p>
						<?php textareas( $t, array( true, false ) ); ?>
						<?php else: ?>
							<?php if ( $locale->nplurals == 2 && $locale->plural_expression == 'n != 1'): ?>
								<p><?php printf(__('Singular: %s'), '<span class="original">'.esc_translation($t->singular).'</span>'); ?></p>
								<?php textareas( $t, array( true, false ), 0 ); ?>
								<p class="clear">
									<?php printf(__('Plural: %s'), '<span class="original">'.esc_translation($t->plural).'</span>'); ?>
								</p>
								<?php textareas( $t, array( true, false ), 1 ); ?>
							<?php else: ?>
								<!--
								TODO: labels for each plural textarea and a sample number
								-->
								<p><?php printf(__('Singular: %s'), '<span class="original">'.esc_translation($t->singular).'</span>'); ?></p>
								<p class="clear">
									<?php printf(__('Plural: %s'), '<span class="original">'.esc_translation($t->plural).'</span>'); ?>
								</p>
								<?php foreach( range( 0, $locale->nplurals - 1 ) as $plural_index ): ?>
									<?php if ( $locale->nplurals > 1 ): ?>
									<p class="plural-numbers"><?php printf(__('This plural form is used for numbers like: %s'),
											'<span class="numbers">'.implode(', ', $locale->numbers_for_index( $plural_index ) ).'</span>' ); ?></p>
									<?php endif; ?>
									<?php textareas( $t, array( true, false ), $plural_index ); ?>
								<?php endforeach; ?>
							<?php endif; ?>
						<?php endif; ?>
						</div>

						<div class="meta">
							<h3><?php _e('Meta'); ?></h3>
							<dl>
								<dt><?php _e( 'Project:' ); ?></dt>
								<dd><?php gp_link_project( $project, esc_html( $project->name ) ); ?></dd>
							</dl>
							<!--
							<dl>
								<dt><?php _e('Priority:'); ?></dt>
								<dd><?php echo esc_html($t->priority); ?></dd>
							</dl>
							-->

							<?php if ( $t->context ): ?>
							<dl>
								<dt><?php _e('Context:'); ?></dt>
								<dd><span class="context"><?php echo esc_translation($t->context); ?></span></dd>
							</dl>
							<?php endif; ?>
							<?php if ( $t->comment ): ?>
							<dl>
								<dt><?php _e('Comment:'); ?></dt>
								<dd><?php echo make_clickable( str_replace( "\n", '<br/>', esc_translation( $t->comment ) ) ); ?></dd>
							</dl>
							<?php endif; ?>

							<dl>
								<dt><?php _e('Priority of the original:'); ?></dt>
							<?php if ( $can_write ): ?>
								<dd><?php echo gp_select( 'priority-'.$t->original_id, GP::$original->get_static( 'priorities' ), $t->priority, array('class' => 'priority', 'tabindex' => '-1') ); ?></dd>
							<?php else: ?>
								<dd><?php echo gp_array_get( GP::$original->get_static( 'priorities' ), $t->priority, 'unknown' ); ?></dd>
							<?php endif; ?>
							</dl>

							<dl><dt>
							<?php references( $project, $t ); ?>
							</dt></dl>

							<dl>
				<?php
					$permalink = gp_url_project_locale( $project, $locale->slug, GP::$translation_set->get( $t->tset )->slug,
						 array('filters[status]' => 'either', 'filters[original_id]' => $t->original_id ) );
				?>
								<dt><a tabindex="-1" href="<?php echo $permalink; ?>" title="Permanent link to this translation">&infin;</a></dt>
							</dl>
						</div>
						<div class="actions">
							<input type="submit" class="ok" value="<?php esc_attr_e( 'Submit translation &rarr;' ); ?>" />
							<a href="<?php echo gp_url_current(); ?>" class="close"><?php _e( 'Skip' ); ?></a>
						</div>
					</td>
				</tr>
			</table>
		</form>
<?php else: ?>
		<div style="padding-right: 315px;"><div class="notice"><?php _e( 'Congratulations! There are no more strings in your language to translate!' ); ?></div></div>
<?php endif; ?>

		<div id="sidebar">
			<div>
				<h2 id="stats-header"><?php _e( 'Stats' ); ?></h2>
				<p style="font-size: 1.1em;">
					<strong>Score:</strong> <?php echo (int) $user->get_meta( 'translation_game_score' ); ?><br/>
					<strong>Reputation earned:</strong> <?php echo (int) $user->get_meta( 'translation_game_rep_earned' ); ?>
						<span class="secondary">(<?php echo (int) $session->rep_earned; ?> this session)</span><br/>
					<strong>Speed:</strong> <?php echo round( $session->num_translated / ( ( $session->last_submit - $session->playing_since ) + 1 ) * 3600, 2 ); ?><span title="Translations per hour" class="secondary">TPH</span><br/>
					<strong>Current streak:</strong> <?php echo (int) $session->streak; ?><br/>
					<strong>Best streak:</strong> <?php echo (int) $user->get_meta( 'translation_game_best_streak' ); if ( $user->get_meta( 'translation_game_best_streak' ) ) { ?> (<?php echo $user->get_meta( 'translation_game_best_streak_date' ); ?>)<?php } ?>
				</p>
			</div>

			<div>
				<h2><?php _e( 'Controls' ); ?></h2>
				<dl class="secondary">
					<dt>Shift+Enter</dt>
					<dd>Submit the translation</dd>

					<dt>Esc</dt>
					<dd>Show a different string to translate</dd>

					<dt>PageUp/PageDown</dt>
					<dd>Move between plural forms</dd>
				</dl>
				<p>Or, you can click buttons with your mouse.</p>
			</div>
		</div>
<?php
		gp_tmpl_footer();
	}

	function process_post() {
		$user = GP::$user->current();
		$locale = GP_Locales::by_slug( $user->get_meta( 'language' ) );
		$session = $user->get_meta( 'translation_game_session' );

		if ( !$session || !$session->translating ) {
			gp_redirect( gp_url_current() );
			exit;
		}

		$original = GP::$original->get( (int) $session->translating );
		$translations = $_POST['translation'][$original->id];

		$tset = GP::$translation_set->get( $session->tset );
		if ( $tset->locale != $locale->slug ) {
			gp_redirect( gp_url_current() );
			exit;
		}

		global $dont_give_rep;
		$dont_give_rep = true;

		$data = array(
			'original_id'        => $original->id,
			'user_id'            => $user->id,
			'translation_set_id' => $session->tset,
			'status'             => 'waiting',
			'translations'       => $translations,
			'warnings'           => GP::$translation_warnings->check( $original->singular, $original->plural, $translations, $locale )
		);

		$score = ( 50 - pow( log( time() - $session->last_seen ), 2 ) ) + sqrt( $session->streak ) * 10;
		$rep = apply_filters( 'translation_game_rep', round( sqrt( $score ) / 3 ), $session, $data );
		$score = apply_filters( 'translation_game_score', round( $score / 10 ) * 10, $session, $data );

		$session->rep_earned += $rep;
		$session->translating = null;
		$session->tset = null;
		$session->num_translated++;
		$session->last_submit = time();
		$session->streak++;
		if ( isset( GP::$plugins->badges ) ) {
			if ( !GP::$plugins->badges->get_badge_progress( 'game_reputable' ) &&
				$session->rep_earned >= 100 ) {
				GP::$plugins->badges->set_badge_progress( 'game_reputable', 1 );
			}
			if ( !GP::$plugins->badges->get_badge_progress( 'game_addicted' ) &&
				time() - $session->playing_since >= 1800 ) {
				GP::$plugins->badges->set_badge_progress( 'game_addicted', 1 );
			}
			if ( !GP::$plugins->badges->get_badge_progress( 'game_lightning' ) && $session->num_translated >= 5 &&
				$session->num_translated / ( ( $session->last_submit - $session->playing_since ) + 1 ) * 3600 >= 200 ) {
				GP::$plugins->badges->set_badge_progress( 'game_lightning', 1 );
			}
		}

		$user->set_meta( 'translation_game_session', $session );

		$user->set_meta( 'translation_game_score', $user->get_meta( 'translation_game_score' ) + $score );
		if ( isset( GP::$plugins->badges ) )
			GP::$plugins->badges->set_badge_progress( 'game_highscore', $user->get_meta( 'translation_game_score' ) );
		$user->set_meta( 'translation_game_rep_earned', $user->get_meta( 'translation_game_rep_earned' ) + $rep );
		$user->set_meta( 'reputation', $user->get_meta( 'reputation' ) + $rep );
		if ( $user->get_meta( 'translation_game_best_streak' ) < $session->streak ) {
			$user->set_meta( 'translation_game_best_streak', $session->streak );
			$user->set_meta( 'translation_game_best_streak_date', gmdate( 'Y-m-d' ) );
			if ( $session->streak >= 25 && isset( GP::$plugins->badges ) )
				GP::$plugins->badges->set_badge_progress( 'game_winstreak', 1 );
		}

		if ( (int) $rep && (int) $score )
			gp_notice_set( sprintf( __( 'Good job! You\'ve increased your score by %1$d and earned %2$d reputation!' ), $score, $rep ) );
		elseif ( (int) $score )
			gp_notice_set( sprintf( __( 'Good job! You\'ve increased your score by %1$d!' ), $score ) );
		elseif ( (int) $rep )
			gp_notice_set( sprintf( __( 'Good job! You\'ve earned %1$d reputation!' ), $score ) );
		else
			gp_notice_set( __( 'Good job!' ) );

		$translation = GP::$translation->create( $data );
		gp_redirect( gp_url_current() );
	}

	function gp_user_recent_actions( $actions, $user_id ) {
		if ( !$prev_sessions = GP::$user->get( $user_id )->get_meta( 'translation_game_prev_sessions' ) )
			return $actions;

		foreach ( $prev_sessions as $session ) {
			$actions[] = (object) array(
				'start_time' => date( DATE_MYSQL, $session->playing_since ),
				'end_time'   => date( DATE_MYSQL, $session->last_submit ),
				'content'    => sprintf( _n( 'Earned %1$d reputation by translating %2$d string in the <a href="%3$s">translation game</a>',
						'Earned %1$d reputation by translating %2$d strings in the <a href="%3$s">translation game</a>',
						$session->num_translated, 'translation-game' ), $session->rep_earned,
						$session->num_translated, gp_url( '/game' ) )
			);
		}

		return $actions;
	}
}

function translation_game_get() {
	GP::$plugins->translation_game->show_page();
}

function translation_game_post() {
	GP::$plugins->translation_game->process_post();
}

//GP::$plugins->translation_game = new Translation_Game();