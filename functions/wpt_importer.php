<?php
	
	class WPT_Importer {
		
		function init($args) {
			
			$defaults = array(
				'slug' => '',
				'name' => '',
				'options' => array(),
			);
			$args = wp_parse_args($args, $defaults);
			
			$this->slug = $args['slug'];
			$this->name = $args['name'];
			$this->options = $args['options'];
			$this->marker = '_'.$this->slug.'_marker';
			$this->options = get_option($this->slug);
			$this->stats = get_option($this->slug.'_stats');
			
			add_action('update_option_'.$this->slug, array($this,'update_options'), 10 ,2);
			add_action('wp_loaded', array( $this, 'handle_import_linked' ));

			add_filter('admin_init',array($this,'admin_init'));
			add_filter('wpt_admin_page_tabs',array($this,'wpt_admin_page_tabs'));
			add_action($this->slug.'_import', array($this, 'execute' ));
			
		}
		
		/**
		 * Checks if all requirements for the import are met.
		 *
		 * You should override this method in your child class.
		 * 
		 * @since 0.10
		 *
		 * @access protected
		 * @return bool Returns <true> if all requirements are met. Default: <false>.
		 */
		protected function ready_for_import() {
			return false;
		}
		
		/**
		 * Processes the feed for you import.
		 * 
		 * You should override this method in your child class.
		 *
		 * This method does the actual importing and is unique to every importer.
		 * 
		 * @since 0.10
		 *
		 * @see WPT_Importer::execute()
		 *
		 * @access protected
		 * @return bool Returns <true> if the feed is successfully processed. Default: <false>.
		 */
		protected function process_feed() {
			return false;
		}

		/**
		 * Created a new event.
		 *
		 * Use this helper function to create a new event while processing your feed.
		 * 
		 * @since 0.10
		 *
		 * @see WPT_Importer::get_event_by_ref()
		 * @see WPT_Importer::update_event()
		 *
		 * @access protected
		 * @param array $args {
		 *		@type int $production 		The ID of the parent production.
		 *		@type string $venue			The venue of the event.
		 *		@type string $city			The city of the event.
		 *		@type string $tickets_url	The tickets url of the event.
		 *		@type string $event_date	The date of the event.
		 * 		@type string $ref			A unique identifier for the event.
		 * }
		 * @return void
		 */
		function create_event($args) {
			
			$defaults = array(
				'production' => false,
				'venue' => '',
				'city' => '',
				'tickets_url' => '',
				'event_date' => '',
				'ref' => '',
			);
			
			$args = wp_parse_args($args, $defaults);

			$post = array(
				'post_type' => WPT_Event::post_type_name,
				'post_status' => 'publish',
			);
			
			if ($post_id = wp_insert_post($post)) {
				add_post_meta($post_id, '_wpt_source', $this->slug, true);
				add_post_meta($post_id, '_wpt_source_ref', sanitize_text_field($args['ref']), true);
				add_post_meta($post_id, WPT_Production::post_type_name, $args['production'], true);
				add_post_meta($post_id, 'venue', $args['venue'], true);
				add_post_meta($post_id, 'city', $args['city'], true);
				add_post_meta($post_id, 'tickets_url', $args['tickets_url'], true);
				add_post_meta($post_id, 'event_date', $args['event_date'], true);

				$this->stats['events_created']++;
			}			
		}

		/**
		 * Creates a new production.
		 *
		 * Use this helper function to create new production while processing your feed.
		 * 
		 * @since 0.10
		 *
		 * @access protected
		 * @param array $args {
		 *		@type string $title 	The title of the production.
		 *		@type string $content	The post content for the production.
		 * 		@type string $ref		A unique identifier for the production.
		 * }
		 * @return void
		 */
		protected function create_production($args) {
			$defaults = array(
				'title' => '',
				'content' => '',
				'ref' => '',
			);
			
			$args = wp_parse_args($args, $defaults);

			$post = array(
				'post_type' => WPT_Production:: post_type_name,
				'post_title' => $args['title'],
				'post_content' => $args['content'],
			);
			
			if ($post_id = wp_insert_post($post)) {
				add_post_meta($post_id, '_wpt_source', $this->slug, true);
				add_post_meta($post_id, '_wpt_source_ref', sanitize_text_field($args['ref']), true);
				$this->stats['productions_created']++;
			}			
		}
		
		/**
		 * Gets a production based on the unique identifier.
		 * 
		 * Use this helper function to find a previously imported production while processing your feed.
		 * 
		 * @since 0.10
		 *
		 * @access protected
		 * @param string $ref A unique identifier for the production.
		 * @return WPT_Production The production. Returns `false` if no previously imported production was found.
		 */
		protected function get_production_by_ref($ref) {
			$args = array(
				'post_type' => WPT_Production::post_type_name,
				'post_status' => 'any',
				'meta_query' => array(
					array(
						'key' => '_wpt_source',
						'value' => $this->slug,
					),
					array(
						'key' => '_wpt_source_ref',
						'value' => $ref
					),
				),
			);
			$productions = get_posts($args);
	
			if (!empty($productions[0])) {
				return new WPT_Production($productions[0]);
			} else {
				return false;
			}
		}
		
		/**
		 * Updates a previously imported event.
		 *
		 * Use this helper function to update existing events while processing your feed.
		 * If no existing event is found then a new one is created.
		 * 
		 * @since 0.10
		 *
		 * @see WPT_Importer::get_event_by_ref()
		 * @see WPT_Importer::create_event()
		 *
		 * @access protected
		 * @param array $args {
		 *		@type int $production 		The ID of the parent production.
		 *		@type string $venue			The venue of the event.
		 *		@type string $city			The city of the event.
		 *		@type string $tickets_url	The tickets url of the event.
		 *		@type string $event_date	The date of the event.
		 * 		@type string $ref			A unique identifier for the event.
		 * }
		 * @return void
		 */
		function update_event($args) {

			$defaults = array(
				'production' => false,
				'venue' => '',
				'city' => '',
				'tickets_url' => '',
				'event_date' => '',
				'ref' => '',
			);
			
			$args = wp_parse_args($args, $defaults);

			$event = $this->get_event_by_ref($args['ref']);
			
			if (empty($event)) {
				return $this->create_event($args);
			}
			
			update_post_meta($event->ID, WPT_Production::post_type_name, $args['production']);
			update_post_meta($event->ID, 'venue', $args['venue']);
			update_post_meta($event->ID, 'city', $args['city']);
			update_post_meta($event->ID, 'tickets_url', $args['tickets_url']);
			update_post_meta($event->ID, 'event_date', $args['event_date']);
			
			delete_post_meta($event->ID, $this->marker);

			$this->stats['events_updated']++;
			
		}

		/**
		 * Executes the import.
		 *
		 * 1. Mark any previously imported upcoming events.
		 * 2. Process your feed.
		 * 3. If successful: Remove all previously imported upcoming events that are no longer
		 *    present in your feed.
		 *    If unsuccesful: Clean up, unmark all previously imported events.
		 * 4. Save import stats for display on the settings screen.
		 *
		 * @since 0.10
		 *
		 * @see WPT_Importer::mark_upcoming_events()
		 * @see WPT_Importer::process_feed()
		 * @see WPT_Importer::remove_marked_events()
		 * @see WPT_Importer::unmark_events()
		 * @see WPT_Importer::save_stats()
		 *
		 * @return void
		 */
		protected function execute() {
			
			$this->stats['start'] = time();
			$this->stats['events_created'] = 0;
			$this->stats['events_updated'] = 0;
			$this->stats['productions_created'] = 0;
			$this->stats['productions_updated'] = 0;
			
			$this->mark_upcoming_events();
			
			if ($this->process_feed()) {
				$this->remove_marked_events();			
			} else {
				$this->unmark_events();
			}
			
			// update wpt_order

			$this->stats['end'] = time();
			
			$this->save_stats();
		}

		/**
		 * Gets all events that are marked.
		 *
 		 * @since 0.10
		 *
		 * @see WPT_Importer::mark_upcoming_events()
		 * @see WPT_Importer::unmark_events()
		 * @see WPT_Importer::remove_marked_events()
		 * 
		 * @access private
		 * @return void
		 */
		private function get_marked_events() {
			$args = array(
				'post_type' => WPT_Event::post_type_name,
				'post_status' => 'any',
				'meta_query' => array(
					array(
						'key' => $this->marker,
						'value' => 1,
					),
				),
			);
			return get_posts($args);
		}

		/**
		 * Executes the import when the 'Run import now'-link is clicked.
		 * 
		 * Hooked into the `wp_loaded` option.
		 * 
		 * @since 0.10
		 *
		 * @see WPT_Importer::execute()
		 * @see WPT_Importer::init()
		 *
		 * @return void
		 */
		function handle_import_linked() {
			if (
				!empty($_GET['wpt_import']) && 
				($this->slug == $_GET['wpt_import']) &&
				check_admin_referer( 'wpt_import' )
			) {
				$this->execute();
				wp_redirect( 'admin.php?page=wpt_admin&tab='.$this->slug );
				exit;
			}
		}
		
		/**
		 * Mark any previously imported upcoming events.
		 * 
		 * @since 0.10
		 *
		 * @see WPT_Importer::execute()
		 * 
		 * @access private
		 * @return void
		 */
		private function mark_upcoming_events() {
			
			global $wp_theatre;
			
			$args = array(
				'post_type' => WPT_Event::post_type_name,
				'post_status' => 'any',
				'meta_query' => array(
					array(
						'key' => '_wpt_source',
						'value' => $this->slug,
					),
					array(
						'key' => $wp_theatre->order->meta_key,
						'value' => time(),
						'compare' => '>=',
					),
				),
			);
			
			$events = get_posts($args);
			
			foreach($events as $event) {
				add_post_meta($event->ID, $this->marker, 1, true);
			}
			
		}
		
		/**
		 * Removes all previously imported events that are still marked.
		 * 
		 * @see WPT_Importer::execute()
		 * @see WPT_Importer::get_marked_events()
		 *
		 * @access private
		 * @return void
		 */
		private function remove_marked_events() {
			foreach($this->get_marked_events() as $event) {
				wp_delete_post($event->ID, true);
			}
		}
		
		/**
		 * Saves the import stats.
		 *
		 * The import stats are displayed on the setting screen.
		 * 
		 * @since 0.10
		 *
		 * @see WPT_Importer::execute()
		 * @see WPT_Importer::settings_field_last_import()
		 * 
		 * @access private
		 * @return void
		 */
		private function save_stats() {
			update_option($this->slug.'_stats', $this->stats);
		}
		
		/**
		 * Schedules the import.
		 *
		 * Removes any previously scheduled imports. 
		 * If no valid $schedule is given then no new import is scheduled.
		 * 
		 * @since 0.10
		 *
		 * @see WPT_Importer::update_options()
		 * @see WPT_Setup::cron_schedules()
		 * 
		 * @access protected
		 * @param string $schedule 	How often the event should reoccur. 
		 							Accepts `hourly`, `twicedaily`, `daily` and
		 							any custom intervals created using the cron_schedules filter 
		 							in wp_get_schedules().
		 * @return bool 			Returns 'true' if the import was succesfully scheduled. 
		 */
		protected function schedule_import($schedule) {
			
			// remove previously scheduled imports
			wp_clear_scheduled_hook($this->slug.'_import');
			
			// schedule import
			$schedules = wp_get_schedules();
			if (in_array($schedule,array_keys($schedules))) {
				return (false !== wp_schedule_event( time(), $schedule, $this->slug.'_import'));
			} else {
				return false;
			}
			
		}

		/**
		 * Cleans up, unmarks all previously imported events that are still marked.
		 * 
		 * @see WPT_Importer::execute()
		 * @see WPT_Importer::get_marked_events()
		 *
		 * @access private
		 * @return void
		 */
		private function unmark_events() {
			foreach($this->get_marked_events() as $event) {
				delete_post_meta($event->ID, $this->marker);
			}
		}
		
		/**
		 * Runs after the settings are updated.
		 *
		 * Hooked into the `update_option_$option` action.
		 * We use this to schedule the import after the import schedule is set on the settings page.
		 * 
		 * @since 0.10
		 *
		 * @see WPT_Importer::init()
		 *
		 * @param string $old_value
		 * @param string $value
		 */
		function update_options($old_value,$value) {
			if (isset($value['schedule'])) {
				$this->schedule_import($value['schedule']);
			}
		}
		

		
		
		
		

		function get_event_by_ref($ref) {
			$args = array(
				'post_type' => WPT_Event::post_type_name,
				'post_status' => 'any',
				'meta_query' => array(
					array(
						'key' => '_wpt_source',
						'value' => $this->slug,
					),
					array(
						'key' => '_wpt_source_ref',
						'value' => $ref
					),
				),
			);
			$productions = get_posts($args);
	
			if (!empty($productions[0])) {
				return new WPT_Production($productions[0]);
			} else {
				return false;
			}
			
		}
		
		function update_production_thumbnail_from_url($production_id, $image_url, $image_desc) {

			require_once(ABSPATH . 'wp-admin/includes/media.php');
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			require_once(ABSPATH . 'wp-admin/includes/image.php');

			$tmp = download_url( $image_url );
			$file_array = array();
			
			// Set variables for storage
			// fix file filename for query strings
			preg_match('/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $image_url, $matches);
			$file_array['name'] = basename($matches[0]);
			$file_array['tmp_name'] = $tmp;
			
			// If error storing temporarily, unlink
			if ( is_wp_error( $tmp ) ) {
				@unlink($file_array['tmp_name']);
				$file_array['tmp_name'] = '';
			}
			
			// do the validation and storage stuff
			$thumbnail_id = media_handle_sideload( $file_array, $production_id, $image_desc );

			// If error storing permanently, unlink
			if ( is_wp_error($thumbnail_id) ) {
				@unlink($file_array['tmp_name']);
				return $thumbnail_id;
			}

			return set_post_thumbnail( $production_id, $thumbnail_id );
		}

		function admin_init() {
	        register_setting($this->slug, $this->slug);
	
	        add_settings_section(
	            $this->slug.'_settings', // ID
	            __('Import','wp_theatre'), // Title
	            '', // Callback
	            $this->slug // Page
	        );  

	        add_settings_field(
	            'schedule', // ID
	            __('Schedule','wp_theatre'), // Title 
	            array( $this, 'settings_field_schedule' ), // Callback
	            $this->slug, // Page
	            $this->slug.'_settings' // Section           
	        );      
	        
	        add_settings_section(
	            $this->slug.'_status', // ID
	            __('Status','wp_theatre'), // Title
	            '', // Callback
	            $this->slug // Page
	        );  

	        add_settings_field(
	            'status', // ID
	            __('Ready for import','wp_theatre'), // Title 
	            array( $this, 'settings_field_status' ), // Callback
	            $this->slug, // Page
	            $this->slug.'_status' // Section           
	        );      
	        
	        add_settings_field(
	            'next import', // ID
	            __('Next import','wp_theatre'), // Title 
	            array( $this, 'settings_field_next_import' ), // Callback
	            $this->slug, // Page
	            $this->slug.'_status' // Section           
	        );      
	        
	        add_settings_field(
	            'last import', // ID
	            __('Last import','wp_theatre'), // Title 
	            array( $this, 'settings_field_last_import' ), // Callback
	            $this->slug, // Page
	            $this->slug.'_status' // Section           
	        );      
	        
		}
		
		function settings_field_schedule() {

			$schedules = wp_get_schedules();

			echo '<select id="schedule" name="'.$this->slug.'[schedule]">';
			
			echo '<option value="manual">'.__('Manual','wp_theatre').'</option>';

			foreach($schedules as $name => $value) {

				echo '<option value="'.$name.'"';
				if ($name==$this->options['schedule']) {
					echo ' selected="selected"';
				}
				echo '>'.$value['display'].'</option>';
				
			}

			echo '</select>';
			
			if ($this->ready_for_import()) {
			
				$import_url = add_query_arg('wpt_import', $this->slug);
				$import_url = wp_nonce_url( $import_url, 'wpt_import' );
	
				echo '<p><a href="'.$import_url.'">'.__('Run import now','wp_theatre').'</a></>';				
				
			}
		}
	
		function settings_field_status() {
			
			echo '<p>';
			
			if ($this->ready_for_import()) {
				_e('Yes','wp_theatre');
			} else {
				_e('No','wp_theatre');
			}

			echo '</p>';

		}
		
		function settings_field_next_import() {
			
			if ($timestamp = wp_next_scheduled( $this->slug.'_import' )) {
				echo sprintf(__('In %s.','wp_theatre'),human_time_diff($timestamp));
			}
		}
		
		function settings_field_last_import() {
			
			echo '<table>';
			
			echo '<tbody>';
			
			if (!empty($this->stats['start'])) {
				echo '<tr>';
				echo '<th><strong>'.__('Start','wp_theatre').'</strong></th>';

				echo '<td>'.
					date_i18n(get_option('date_format'), $this->stats['start']).
					'<br />'.
					date_i18n(get_option('time_format'), $this->stats['start']).
					'</td>';

				echo '</tr>';

				if (!empty($this->stats['end'])) {
					echo '<tr>';
					echo '<th>'.__('Duration','wp_theatre').'</th>';
					echo '<td>'.human_time_diff($this->stats['start'], $this->stats['end']).'</td>';				
					echo '</tr>';
				}
			}

			echo '</tbody>';

			echo '</table>';
		}
		
		function wpt_admin_page_tabs($tabs) {
			$tabs[$this->slug] = $this->name;		
			return $tabs;
		}
		
		
	}