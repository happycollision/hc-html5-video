<?php
/*
Plugin Name: Happy HTML5 Video
Plugin URI:
Description: Create all the proper HTML to link to video files. Automatically opts out if link is "lightboxed", with ability to specifically ask to process a link despite lightboxing. Also adds proper MIME type to WordPress (single installs) for webm. Multisite installs need to have MIME types changed in the Super Admin Settings area.
Author: Don Denton
Version: 1.1
Author URI: http://happycollision.com
*/
function happycol_array_of_video_file_types(){
	//in order of preference
	return array('mp4','webm','ogg','m4v','flv','mov','f4v','mp4v','3gp','3g2');
}

function happycol_array_of_flash_compatible_video_file_types(){
	//in any order
	return array('mp4','m4v','flv','mov','f4v','mp4v','3gp','3g2');
}

function happycol_array_of_html5_video_file_types(){
	//in any order
	return array('mp4','webm','ogg');
}

//First of all, let's make sure that our expected file types are allowed:
function happycol_html5_video_mime_types($mime_types){
	$mime_types['webm'] = 'video/webm'; //Adding avi extension
	return $mime_types;
}
add_filter('upload_mimes', 'happycol_html5_video_mime_types', 1, 1);


/* testing stuff */
function my_header_content() {
  ?>
  <style>
  #main_content, .post_content{
	  overflow: scroll;
  }
  video{
	  background: #444;
  }
  </style>
  <?php
}
//add_action( 'wp_head', 'my_header_content' ); // 'my_header_content' here is the name of the function above


//This filter will run after most typical lightbox filters so that the links I create are not lightboxed after I am done
add_filter('the_content','happycol_html5_video',20);

function happycol_html5_video($post_content){
	$matched_anchors = array();
	/*
	Regex explaination (because I will eventually forget what it means!):
	
	#                      - delimiter. Anything between the opening and closing # will be evaluated
	<a                     - literal
	\s                     - any whitespace character
	(.*\s)*                - Match anything as long as it ends with whitespace. Will make subset, but it won't be used
	href="(                - literal and the opening parenthesis starts the subpattern that will represent the URL
	[^"]*\.                - matches anything except a double quote, then followed by a period
	([a-zA-Z0-9]{3,4})     - matches either three or four letters and numbers (the file extension) followed immediately by a quote and subsets the extension
	)"                     - closes the subset that represents the URL. Also the literal quote.
	[^>]*                  - zero or more of any character except closing >
	>(.*)<\/a>             - matches the end of the original a tag, followed by anything, followed by the closing a tag (</a>)
	#                      - closing delimiter
	s                      - turns on PCRE_DOTALL which tells the parser to have the periods go ahead and match line breaks as well
	i                      - search becomes case insensitive
	U                      - Ungreedy setting. This has the parser stop looking ahead after a match is found. (Keeps from matching beginning of pattern to the end of another pattern which has it's own independent beginning. It will match <a href="something.mp4">Text</a> and <a href="anotherThing.mp4>More Text</a> as two completely separate entities instead of combining them in one match.
	
	*/
	$did_match = preg_match_all('#<a\s(.*\s)*href="([^"]*\.([a-zA-Z0-9]{3,4}))"[^>]*>(.*)<\/a>#siU', $post_content, $matched_anchors);

	if($did_match){
		$find = array();
		$replace = array();
		foreach($matched_anchors[0] as $match_index => $entire_matched_anchor){
			//This if will ignore cases where the user has added an attribute to <a> called HappycolVideo="ignore"
			if(stripos($entire_matched_anchor,'HappycolVideo="ignore"') !== FALSE) continue;

			//This if will ignore anything that already uses a lightbox of some sort, unless we ask to run anyway via HappycolVideo att
			if(stripos($entire_matched_anchor,'HappycolVideo') === FALSE) {
				if( preg_match('#rel="[\w]*box.*"#', $entire_matched_anchor) == 1 ) continue;
			}
				
				//Set up some variables
				$video_file_types = happycol_array_of_video_file_types();
				$original_file_type = strtolower($matched_anchors[3][$match_index]); //without preceeding period
				$original_url = $matched_anchors[2][$match_index];
				$original_text = $matched_anchors[4][$match_index];
				
				// If we don't care about this file type, move on
				if( ! in_array($original_file_type, $video_file_types) ) continue;
				
				// construct the unix file path and check if it exists
				$basedir = wp_upload_dir();
				$basedir = $basedir['basedir'];
				$date_folder = NULL; preg_match('#/[0-9]{4}/[0-1]{1}[0-9]{1}#', $original_url, $date_folder);
				$path_to_file = $basedir . $date_folder[0] . '/' . basename($original_url);
				if( ! file_exists($path_to_file) ) continue;
				
								
				// Now that we know it is a video, we can search for sister files
				$related_video_files_array = setup_related_video_files($original_url);
				
				
				//cycle through all videos for download replacement text
				$download_replacement_text = '<span class="happycol_video_caption caption">View in browser/download video as ';
				foreach($related_video_files_array as $video_file_info){
					$download_replacement_text_array[] = '<a href="'.$video_file_info['url'].'">'.strtoupper($video_file_info['extension']).'</a>';
				}
				$download_replacement_text .= implode(' | ', $download_replacement_text_array);
				$download_replacement_text .= '</span>';
				reset($related_video_files_array);
				
				
				//Find the frist flash compatible video and put that in the player (if we end up using it)
				$flash_video_exists = FALSE;
				foreach($related_video_files_array as $video_file_info){
					if($video_file_info['flash_compatible']){
						$flash_video_exists = TRUE;
						$flash_video_info = $video_file_info;
						break;
					}
				}
				if($flash_video_exists){
					$flash_replacement_text = 
							'<object width="100%" height="360" type="application/x-shockwave-flash" data="'.plugins_url('/flowplayer-3.2.15.swf', __FILE__).'">'.
								'<param name="movie" value="'.plugins_url('/flowplayer-3.2.15.swf', __FILE__).'" />'.
								'<param name="allowfullscreen" value="true" />'.
								'<param name="flashvars" value="config={\'clip\': {\'url\': \''.$flash_video_info['url'].'\', \'autoPlay\':false, \'autoBuffering\':true, \'scaling\':\'fit\'}, \'canvas\':{\'backgroundColor\':\'#000\'}}" />'.
							'</object>';
				}else{
					$flash_replacement_text = '';	
				}
				
				
				
				$html5_video_exists = FALSE;
				foreach($related_video_files_array as $video_file_info){
					if($video_file_info['html5_compatible']){
						$html5_video_exists = TRUE;
						break;
					}
				}
				reset($related_video_files_array);
				
				
				if($html5_video_exists){
					$final_replacement_text = 
						'<video controls>'; // preload="metadata" causes problems.
					foreach($related_video_files_array as $video_file_info){
						if($video_file_info['html5_compatible']){
							$final_replacement_text .=
								'<source src="'.$video_file_info['url'].'" type="'.$video_file_info['type'].'" />';
						}
					}
					$final_replacement_text .= $flash_replacement_text;
					$final_replacement_text .= $download_replacement_text;
					$final_replacement_text .= '</video>';
				}else{
					$final_replacement_text = $flash_replacement_text;
				}
				
				//Shouldn't even be here at all if a replacement wasn't found
				if($final_replacement_text !== NULL){
					$find[] = $entire_matched_anchor;
					$replace[] = '<span class="happycol_video_title">' . $original_text . '</span>' . $final_replacement_text;
					
				}
			
			
		}
		$post_content = str_replace($find, $replace, $post_content);
		return $post_content;
	} 
	return $post_content;
}

// This function returns an array with each index pointing to an array of information about related video files, putting the primary file at index 0
function setup_related_video_files($url_to_file){

	$sister_files = get_sister_videos($url_to_file);
	//put the original url in the array as well
	array_unshift($sister_files,$url_to_file);
	
	// Set up a list of flash compatible types for later
	// This is not foolproof. These are container types, not codecs. Might still not play in the end.
	$flash_compatible_types = happycol_array_of_flash_compatible_video_file_types();
	$html5_compatible_types = happycol_array_of_html5_video_file_types();
	
	
	$ordered_file_info = array();
	$i = 0;
	foreach($sister_files as $url){
		$ordered_file_info[$i]['url'] = $url;
		$ordered_file_info[$i]['file_name'] = basename($url);
		$ordered_file_info[$i]['extension'] = substr(strrchr($ordered_file_info[$i]['file_name'], '.'),1);
		if( in_array($ordered_file_info[$i]['extension'], $flash_compatible_types) ){
			$ordered_file_info[$i]['flash_compatible'] = TRUE;
		}else{
			$ordered_file_info[$i]['flash_compatible'] = FALSE;
		}
		if( in_array($ordered_file_info[$i]['extension'], $html5_compatible_types) ){
			$ordered_file_info[$i]['html5_compatible'] = TRUE;
		}else{
			$ordered_file_info[$i]['html5_compatible'] = FALSE;
		}
		
		//assign the type dynamically based on extension, or use cases for when the extension and the type aren't exactly the same
		switch( strtolower($ordered_file_info[$i]['extension']) ):
			default:
				$ordered_file_info[$i]['type'] = 'video/' . strtolower( $ordered_file_info[$i]['extension'] );
				break;		
		endswitch;
		
		++$i;
	}
	
	// If we are dealing with a multisite environment and ms-file is running, we need one more thing
	if ( is_multisite() && get_site_option( 'ms_files_rewriting' ) ){
		$ordered_file_info = undo_ms_file_paths_to_videos($ordered_file_info);
	}
	return $ordered_file_info;
}

// This function returns an array of urls to related files from the one passed in
function get_sister_videos($url_to_original_file){
	
	//Construct unix file path without file extension
	$basedir = wp_upload_dir();
	$basedir = $basedir['basedir'];
	$date_folder = NULL; preg_match('#/[0-9]{4}/[0-1]{1}[0-9]{1}#', $url_to_original_file, $date_folder);
	$path_to_file = $basedir . $date_folder[0] . '/' . basename($url_to_original_file);
	$path_minus_extension = substr($path_to_file, 0, strrpos($path_to_file,'.'));
	$url_minus_extension = substr($url_to_original_file, 0, strrpos($url_to_original_file,'.'));
	$file_extension = substr($path_to_file, strrpos($path_to_file,'.')+1 ); //without period
	
	//Get the types we want to look for and remove the type that this one is (otherwise the primary file would also be in the sisters list)
	$video_file_types = happycol_array_of_video_file_types();
	$key_to_remove = array_search($file_extension, $video_file_types);
	if($key_to_remove !== FALSE) unset($video_file_types[$key_to_remove]);

	//If the file with the new extension exists, put it in the array of sister files
	$array_of_sister_urls = array();
	foreach($video_file_types as $video_file_type){
		if( file_exists($path_minus_extension . '.' . $video_file_type) ) {
			$array_of_sister_urls[] = $url_minus_extension . '.' . $video_file_type;
		}
	}

	return $array_of_sister_urls;
}


// The ms-file rewriting rules are nice for display, but they screw up playback of videos on iOS, so let's get the actual paths
function undo_ms_file_paths_to_videos($array_of_video_files_info){
	global $current_blog;
	
	foreach($array_of_video_files_info as &$video_file_info){
		$site_number = $current_blog->blog_id;
		$new_path_insert = "/wp-content/blogs.dir/$site_number/files/";
		
		$video_file_info['url'] = str_replace('/files/', $new_path_insert, $video_file_info['url']);
	}
	//hcprint($array_of_video_files_info);
	
	return $array_of_video_files_info;
}
?>
