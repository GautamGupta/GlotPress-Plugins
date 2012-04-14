<?php

class Project_Of_The_Day extends GP_Plugin {
	var $id = 'potd';
	var $project = null;

	function __construct() {
		parent::__construct();

		$this->add_action( 'pre_tmpl_load', array( 'args' => 2 ) );
		$this->add_action( 'post_tmpl_load', array( 'args' => 2 ) );
		$this->add_action( 'gp_head' );
	}

	function pre_tmpl_load( $template, &$args ) {
		if ( $template == 'projects' ) {
			$last_chose = $this->get_option( 'project_chosen_at' );
			if ( $last_chose > floor( time() / 86400 ) * 86400 ) {
				$this->project = GP::$project->get( $this->get_option( 'project' ) );
			}
			if ( !$this->project ) {
				$projects = GP::$project->top_level();
				shuffle( $projects );
				$this->update_option( 'project', $projects[0]->id );
				$this->project = $projects[0];
				$this->update_option( 'project_chosen_at', time() );
			}
		}
	}

	function post_tmpl_load( $template, &$args ) {
		if ( $this->project && $template == 'header' ) {
			echo '<div id="project-of-the-day"><h2><span>';
			_e( 'Project of the Day:', 'project-of-the-day' );
			ob_start();
			$project_url = gp_url_project( $this->project );
			ob_end_clean();
			echo '</span> <a href="', $project_url, '">';
			echo esc_html( $this->project->name );
			echo '</a></h2><p>';
			printf( __( 'Today\'s randomly chosen "Project of the Day" is %s.', 'project-of-the-day' ), esc_html( $this->project->name ) );
			$originals = GP::$original->by_project_id( $this->project->id );
			if ( $originals && $originals[0]->date_added ) {
				printf( ' ' . __( '%1$s has been available for translation on this site since %2$s.', 'project-of-the-day' ),
					esc_html( $this->project->name ),
					date( 'F j<\\s\\u\\p>S</\\s\\u\\p>, Y', strtotime( $originals[0]->date_added ) ) );
			}
			echo '</p></div>';
		}
	}

	function gp_head() {
		if ( $this->project ) {
			echo '<style type="text/css">#project-of-the-day {
				float: right;
				margin-top: 3em;
				border: 1px solid #333;
				-moz-border-radius: 3px;
				-webkit-border-radius: 3px;
				-khtml-border-radius: 3px;
				border-radius: 3px;
				padding: 3px;
				background-color: #eff;
				width: 200px;
			}
			#project-of-the-day h2 {
				text-align: center;
				margin-top: 3px;
			}
			#project-of-the-day h2 span {
				font-size: .7em;
				display: block;
			}</style>';
		}
	}
}

//GP::$plugins->project_of_the_day = new Project_Of_The_Day;