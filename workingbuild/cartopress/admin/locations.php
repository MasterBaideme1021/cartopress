<?php
/**
 * CartoPress Locations
 *
 * @package cartopress
 */
 
 /* add geocoder meta box
 *	@since 0.1.0
 */

if (!class_exists('geocoder_metabox')) {

	class geocoder_metabox {
	
		/**
		 * Hook into the appropriate actions when the class is constructed.
		 */
		public function __construct() {
			add_action( 'add_meta_boxes', array( $this, 'cartopress_add_geocoder' ) );
			add_action( 'save_post', array( $this, 'save' ) );
			require( cartopress_admin_dir . 'cp-sync.php' );
			// special action for attachment content type
			add_action( 'edit_attachment', array ( $this, 'save'), 10, 1 );
			// delete from cartodb when user moves post to trash
			add_action( 'wp_trash_post', 'cartopress_delete' );
			function cartopress_delete( $post_id ){
				cartopress_sync::cartodb_delete($post_id);
			}
			add_action( 'untrashed_post', 'cartopress_undelete' );
			function cartopress_undelete($post_id) {
				cartopress_sync::cartodb_sync($post_id);
			}
		} // end __construct
		
		
		/**
		 * Adds the meta box container.
		 */
		public function cartopress_add_geocoder( $post_type ) {
				$cpoptions = get_option( 'cartopress_admin_options', '' );
				if ($cpoptions['cartopress_collect_posts'] == 1) {
					$posts = 'post';
				} else {
					$posts = null;
				}
				if ($cpoptions['cartopress_collect_pages'] == 1) {
					$pages = 'page';
				} else {
					$pages = null;
				}
				if ($cpoptions['cartopress_collect_media'] == 1) {
					$media = 'attachment';
				} else {
					$media = null;
				}
				$post_types = array($posts, $pages, $media);     //limit meta box to certain post types
				if ( in_array( $post_type, $post_types )) {
					
					add_meta_box('cartopress_locator', __( 'CartoPress Geolocator', 'cartopress_textdomain' ), array( $this, 'cartopress_geocoder_content' ), $post_type, 'normal', 'high' );
					function load_geocoder_dependencies($location) {
					   if( 'post.php' == $location || 'post-new.php' == $location ) {
					     wp_enqueue_script('jquery2');
						 wp_enqueue_style('google_fonts');
						 wp_enqueue_style('leaflet');
						 wp_enqueue_style('ionicons');
					     wp_enqueue_script(array('cartopress-geocode-script'));
						 wp_enqueue_script(array('cartopress-geocode-helper-script'));
						 wp_enqueue_script('ionicons');
					     wp_enqueue_style(array('cartopress-geocode-styles', 'cartopress-leaflet-styles'));
					  }
					}
					add_action( 'admin_enqueue_scripts', 'load_geocoder_dependencies' );
				} // end if
		} // end cartopress_add_geocoder
		
		
		
		/**
		 * Save the meta when the post is saved.
		 *
		 * @param int $post_id The ID of the post being saved.
		 */
		public function save( $post_id ) {
	
			/*
			 * We need to verify this came from the our screen and with proper authorization,
			 * because save_post can be triggered at other times.
			 */

			// Check if our nonce is set.
			if ( ! isset( $_POST['cartopress_inner_custom_box_nonce'] ) )
				return $post_id;

			$nonce = $_POST['cartopress_inner_custom_box_nonce'];

			// Verify that the nonce is valid.
			if ( ! wp_verify_nonce( $nonce, 'cartopress_inner_custom_box' ) )
				return $post_id;

			// If this is an autosave, our form has not been submitted,
					//     so we don't want to do anything.
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
				return $post_id;

			// Check the user's permissions.
			if ( 'page' == $_POST['post_type'] ) {

				if ( ! current_user_can( 'edit_page', $post_id ) )
					return $post_id;
	
			} else {

				if ( ! current_user_can( 'edit_post', $post_id ) )
					return $post_id;
			}

			/* OK, its safe for us to save the data now. */

			// Sanitize the user input.
			$geodata = array(
				'cp_geo_displayname'=>$_POST['cp_geo_displayname'],
				'cp_geo_lat'=>$_POST['cp_geo_lat'],
				'cp_geo_long'=>$_POST['cp_geo_long'],
				'cp_geo_streetnumber'=>$_POST['cp_geo_streetnumber'],
				'cp_geo_street'=>$_POST['cp_geo_street'],
				'cp_geo_postal'=>$_POST['cp_geo_postal'],
				'cp_geo_adminlevel4_vill_neigh'=>$_POST['cp_geo_adminlevel4_vill_neigh'],
				'cp_geo_adminlevel3_city'=>$_POST['cp_geo_adminlevel3_city'],
				'cp_geo_adminlevel2_county'=>$_POST['cp_geo_adminlevel2_county'],
				'cp_geo_adminlevel1_st_prov_region'=>$_POST['cp_geo_adminlevel1_st_prov_region'],
				'cp_geo_adminlevel0_country'=>$_POST['cp_geo_adminlevel0_country']
			);
			
			foreach($geodata as $key=>$field) {
				$field = sanitize_text_field( $field );
			}
			
			$description = sanitize_text_field( $_POST['cp_post_description'] );
			
			// Update the meta field.
			update_post_meta( $post_id, '_cp_post_geo_data', $geodata );
			update_post_meta( $post_id, '_cp_post_description', $description );
			
			if (get_post_status( $post_id ) != 'publish') {
				cartopress_sync::cartodb_delete($post_id);
			} else {
				cartopress_sync::cartodb_sync($post_id);
			}
			
		} //end save function
		
		
		
		/**
		 * Render Meta Box content.
		 *
		 * @param WP_Post $post The post object.
		 */
		public function cartopress_geocoder_content( $post ) {
	
			// Add an nonce field so we can check for it later.
			wp_nonce_field( 'cartopress_inner_custom_box', 'cartopress_inner_custom_box_nonce' );

			// Use get_post_meta to retrieve an existing value from the database.
			$geodata = get_post_meta( $post->ID, '_cp_post_geo_data', true );
			$cp_geo_displayname = $geodata['cp_geo_displayname'];
			$cp_geo_lat = $geodata['cp_geo_lat'];
			$cp_geo_long = $geodata['cp_geo_long'];
			$cp_geo_streetnumber = $geodata['cp_geo_streetnumber'];
			$cp_geo_street = $geodata['cp_geo_street'];
			$cp_geo_postal = $geodata['cp_geo_postal'];
			$cp_geo_adminlevel4_vill_neigh = $geodata['cp_geo_adminlevel4_vill_neigh'];
			$cp_geo_adminlevel3_city = $geodata['cp_geo_adminlevel3_city'];
			$cp_geo_adminlevel2_county = $geodata['cp_geo_adminlevel2_county'];
			$cp_geo_adminlevel1_st_prov_region = $geodata['cp_geo_adminlevel1_st_prov_region'];
			$cp_geo_adminlevel0_country = $geodata['cp_geo_adminlevel0_country'];
			$cp_post_description = get_post_meta( $post->ID, '_cp_post_description', true );

			// Display the metabox
			echo '<p class="howto">Use the search bar to find lookup an address. Select the correct address from the results, or fill in the location fields manually. Note: Latitude and longitude cooridnates are required.</p>';
			echo '<div id="cpdb-metabox-wrapper">
					<h4>Choose a Location</h4>
			    	<section id="addressQuery">
			            <form id="geo_search">
			                <input id="omnibar" type="text" placeholder="Search Address (Neighborhood | City | State | Country)"/>
			                <input type="button" class="getCurrentPosition button" onclick="locateMe()"" value="Current Location"></input>
			                <input type="button" id="cpdb-search-button" class="button" onclick="doClick()" value="Search"></input>
			            </form>
			        </section>
			        
					
			        <div id="results">
			        	<a id="toggle-in-search" class="dashicons dashicons-dismiss cpdb-toggle-results"></a>
			        	<h4>Search Results</h4>
				        <section>
				        	<div class="cpdb-result-item">
				        		<p class="howto">There are no results to show.</p>
				        	</div>
				        </section>
			        </div>
					
					
			        <div class="cpdb-maincontent">
			        	<a id="toggle-in-map" class="dashicons dashicons-dismiss cpdb-toggle-results"></a>
			        	<div id="map"></div>
			        	<div id="cpdb-geocode-values">
			        		<h4>Location</h4>
			        		<input type="checkbox" id="unlock_manual_edit" name="unlock_manual_edit" /><label for="unlock_manual_edit">Allow Geo Data Editing</label>
			        		<section>
			        			<label for="cp_geo_displayname">Location Display Name: </label><span><input type="text" id="cp_geo_displayname" name="cp_geo_displayname" class="disabled" value="' . esc_attr( $cp_geo_displayname ) . '" placeholder="i.e. 200 North 7th Street, Brooklyn, NY" readonly="readonly" /></span>
			        			<label for="cp_geo_lat">Latitude: </label><span class="smaller" id="span_cp_geo_lat"><input type="text" id="cp_geo_lat" name="cp_geo_lat" class="disabled" value="' . esc_attr( $cp_geo_lat ) . '" placeholder="i.e. 40.7165" readonly="readonly"/></span>
			        			<label for="cp_geo_long">Longitude: </label><span><input type="text" id="cp_geo_long" name="cp_geo_long" class="disabled" value="' . esc_attr( $cp_geo_long ) . '" placeholder="i.e. -73.9553" readonly="readonly"/></span>
			        			<label for="cp_geo_streetnumber">Address Number: </label><span class="smaller" id="span_cp_geo_streetnumber"><input type="text" id="cp_geo_streetnumber" name="cp_geo_streetnumber" class="disabled" value="' . esc_attr( $cp_geo_streetnumber ) . '" placeholder="i.e. 200" readonly="readonly"/></span>
			        			<label for="cp_geo_street">Address Street: </label><span><input type="text" id="cp_geo_street" name="cp_geo_street" class="disabled" value="' . esc_attr( $cp_geo_street ) . '" placeholder="i.e. North 7th Street" readonly="readonly"/></span>
			        			<label for="cp_geo_postal">Postal Code: </label><span class="smaller" id="span_cp_geo_postal"><input type="text" id="cp_geo_postal" name="cp_geo_postal" class="disabled" value="' . esc_attr( $cp_geo_postal ) . '" placeholder="i.e. 11211" readonly="readonly"/></span>
			        			<label for="cp_geo_adminlevel4_vill_neigh">Village/Neighborhood: </label><span><input type="text" id="cp_geo_adminlevel4_vill_neigh" name="cp_geo_adminlevel4_vill_neigh" class="disabled" value="' . esc_attr( $cp_geo_adminlevel4_vill_neigh ) . '" placeholder="i.e. Williamsburg" readonly="readonly"/></span>
			        			<label for="cp_geo_adminlevel3_city">Town/City: </label><span class="smaller" id="span_cp_geo_adminlevel3_city"><input type="text" id="cp_geo_adminlevel3_city" name="cp_geo_adminlevel3_city" class="disabled" value="' . esc_attr( $cp_geo_adminlevel3_city ) . '" placeholder="i.e. Brooklyn" readonly="readonly"/></span>
			        			<label for="cp_geo_adminlevel2_county">County/District: </label><span><input type="text" id="cp_geo_adminlevel2_county" name="cp_geo_adminlevel2_county" class="disabled" value="' . esc_attr( $cp_geo_adminlevel2_county ) . '" placeholder="i.e. Kings" readonly="readonly"/></span>
			        			<label for="cp_geo_adminlevel1_st_prov_region">State/Province/Region: </label><span class="smaller" id="span_cp_geo_adminlevel1_st_prov_region"><input type="text" id="cp_geo_adminlevel1_st_prov_region" name="cp_geo_adminlevel1_st_prov_region" class="disabled" value="' . esc_attr( $cp_geo_adminlevel1_st_prov_region ) . '" placeholder="i.e. New York" readonly="readonly"/></span>
			        			<label for="cp_geo_adminlevel0_country">Country: </label><span><input type="text" id="cp_geo_adminlevel0_country" name="cp_geo_adminlevel0_country" class="disabled" value="' . esc_attr( $cp_geo_adminlevel0_country ) . '" placeholder="i.e. United States" readonly="readonly"/></span>
			        		</section>
			        		<h4>Summary Description</h4>
			        		<p class="howto">Add a custom summary description which will be available for display in the CartoDB infowindow. If you leave it blank, CartoPress will attempt to use the post excerpt. If the excerpt is empty, CartoPress will create a summary description using the first 55 words of the post content.</p>
			        		<section>
			        			<textarea placeholder="Enter a Summary Description" name="cp_post_description" id="cp_post_description">' . esc_attr( $cp_post_description ) . '</textarea>
			        		</section>
			        	</div>
			        </div>
			        
			 		<div id="comments"></div>
			 </div>';
		
		} //end cartopress_geocoder_content
	
	
	} // end class geocoder metabox
	
	// add instance  
	
} //end if class exists
?>
