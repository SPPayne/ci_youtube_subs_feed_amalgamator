<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Youtube extends CI_Controller {
	
	public function __construct(){
		
		parent::__construct();
		
		//Set timezone
		date_default_timezone_set('Etc/GMT');
		
		//Set feed key, directory path and video-per-feed check limit
		$this->feed_key = [INSERT KEY HERE];
		$this->directory_path = [INSERT FILE PATH HERE];
		$this->vid_limit = 2;

	}
	
	//Expects a file exported from YouTube subscriptions manager - click the "export subscriptions" button
	//https://www.youtube.com/subscription_manager
	
	//Designed to be run on the command line to dump out a file - please note we're using CodeIgniter!
	//e.g. 0 */6 * * * php -q [FILEPATH]/index.php youtube fetch_feed [KEY] >/dev/null 2>&1
	
	//Any problems, consult my blog post about this:
	//http://www.payneful.co.uk/portfolio/2015/07/03/creating-a-new-youtube-subscription-feed/
	
	//Function: presents feed to page, crucially passes back "last-modified" header - use this to present your feed!
	public function show_feed($key = FALSE){
		
		//Use a fixed key here to validate the feed accessing it - kill function if not a valid key
		if($key != $this->feed_key){
			return FALSE;
		}
		
		//Set RSS path
		$file = "subscriptions.rss";
		$path = $this->directory_path . $file;
		
		//Check file exists
		if(!file_exists($path)){
			return FALSE;
		}

		//Convert to XML object, get first item and then it's date
		$rss_parse = simplexml_load_file($path);
		if($rss_parse){
			$item = $rss_parse->channel->item;
			$last_modified = $item->pubDate;
		} else {
			return FALSE;
		}
		
		//DEBUG
		//echo $last_modified;die;
		
		//Spit out a header for the last modified date, and then spill out the contents of the file
		//Ref: http://php.net/manual/en/function.header.php
		//Ref: http://fishbowl.pastiche.org/2002/10/21/http_conditional_get_for_rss_hackers/
		//Ref: http://stackoverflow.com/questions/1911094/is-there-a-way-to-have-a-codeigniter-controller-return-an-image
		header('Last-Modified: ' . $last_modified, TRUE);
		readfile($path);
		die(); exit;
		
	}
	
	//Function: processes all of the feeds!
	public function fetch_feed($key = FALSE){
		
		//DEBUG
		//print_r(curl_version());
		//echo function_exists('curl_version');die;
		
		//Script relies on CURL
		if(!function_exists('curl_version')){
			echo "Script requires CURL to be enabled!";
			return FALSE;
		}
		
		//Prevent calls outside of Command Line - this broke on my server but uncomment it if it's of value to you
		//Ref: http://zacharyflower.com/2014/08/07/running-codeigniter-controllers-in-the-command-line/
		/*if(!$this->input->is_cli_request()){
			return FALSE;
		}*/
		
		//Use a fixed key here to validate the feed accessing it - kill function if not a valid key
		if($key != $this->feed_key){
			return FALSE;
		}
		
		//Set XML path - replace the file with an updated version to update subs
		$file = "subscription_manager.opml";
		$path = $this->directory_path . $file;
		
		//Check file integrity
		if(!file_exists($path)){
			echo "File could not be found at path " . $path;
			return FALSE;
		}

		//Get the feed file, convert to XML
		$opml = simplexml_load_file($path);
		if(empty($opml)){
			echo "File could not be read at path " . $path;
			return FALSE;
		}
		
		//DEBUG
		//print_r($opml);
		
		//Set array to collect feeds
		$feeds = array();

		//Loop through nodes...
		foreach($opml->body->outline as $outline){
			
			//Loop even further through nodes
			foreach($outline->outline as $attributes){
				
				//Loop through attributes
				foreach($attributes->attributes() as $key => $item){
					
					//Reassign items -  we have to cast them as strings otherwise PHP throws a hissy fit over the type of the value
					if($key == "title"){ $title = (string)$item; }
					if($key == "xmlUrl"){ $url = (string)$item; }
					
				}
				
				//Add to array if info
				if($title != "" && $url != ""){
					$feeds[$title] = $url;
				}
				
				//Blank values
				$title = $url = "";
				
			}
			
		}
		
		//Sort or die!
		if(!empty($feeds)){
			ksort($feeds);
		} else {
			echo "No feeds. Sorry :(";
			return FALSE;
		}
		
		//DEBUG
		//print_r($feeds);
		
		//Set limit of videos to check against
		$vid_limit = $this->vid_limit;
		
		//Use function to loop through all feeds, return array
		$rss_items = $this->_process_feeds($feeds,$vid_limit);
		
		//Anything?
		if($rss_items == FALSE || empty($rss_items)){
			echo "No new videos found. Sorry! :(";
			return FALSE;
		}
		
		//Reassign
		$data['rss_items'] = $rss_items;
		
		//Set RSS values
		$data['rss_title'] = "My YouTube Subscriptions";
		$data['rss_url'] = base_url() . "youtube/show_feed/" . $this->feed_key;
		$data['rss_description'] = "Take that, Google! I can get around your RSS limiting!";
		
		//Load rss view into variable
		$rss = $this->load->view('rss', $data, TRUE);
		
		//DEBUG
		//echo $rss;
		//die;
		
		//Setup the file and path we will write to
		$new_file = "subscriptions.rss";
		$path = $this->directory_path . $new_file;
		
		//Write RSS to a file
		$this->_write_rss($path,$rss);
		
		//End function
		echo $rss;
		return;
		
	}
	
	//Function: fetches a URL using CURL
	//Ref: http://stackoverflow.com/questions/3535799/file-get-contents-failed-to-open-stream
	private function _fetch_url($url){
		
		//Initialise
		$ch = curl_init();
		
		//Set values
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, FALSE);
		curl_setopt($ch, CURLOPT_NOPROGRESS, TRUE);
		
		//Execute CURL
		$data = curl_exec($ch);
		
		//Errors?
		if($errno = curl_errno($ch)) {
			//$error_message = curl_strerror($errno);
			//echo "cURL error ({$errno}):\n {$error_message}";
			return FALSE;
		}
		
		//Close CURL
		curl_close($ch);
		
		//Test that what we've got is XML...
		//Ref: http://stackoverflow.com/questions/9211645/check-whether-returned-file-is-xml-or-not
		$test = simplexml_load_string($data, 'SimpleXmlElement', LIBXML_NOERROR+LIBXML_ERR_FATAL+LIBXML_ERR_NONE);
		if($test == FALSE){ return FALSE; }

		//Data to return? Should return a string or FALSE
		return $data;
		
	}
	
	//Function: loops through RSS and gets values
	private function _process_feeds($feeds = FALSE,$vid_limit = 1){
		
		//Input?
		if($feeds == FALSE || empty($feeds)){
			return FALSE;
		}
		
		//Set new array to collect feed items
		$rss_arrange = array();
		
		//Set timestamp to compare against
		$period = strtotime('-2 day');
		
		//Now loop through the feeds...
		foreach($feeds as $title => $url){
			
			//Fetch RSS or continue - suppress any warnings from feed not existing
			//If you don't have CURL uncomment the following and comment out the line after
			//$rss = @file_get_contents($url);
			$rss = $this->_fetch_url($url);
			if($rss == FALSE){
				continue;
			}
			
			//Reset count
			$vid_count = 0;
			
			//DEBUG
			//print_r($rss);die;
			
			//Convert to XML
			$rss = new SimpleXmlElement($rss);
			
			//Loop links
			foreach($rss as $item){
				
				//We only want the last few vids...
				if($vid_count >= $vid_limit){
					continue(2);
				}
				
				//If a vid...
				if($item->title){
					
					//DEBUG
					//echo $item->title . "<br />";die;
					
					//Set date
					$date = strtotime((string)$item->published);
					
					//If the time is older than a week, skip this feed and move on to next channel
					if($date < $period){
						continue(2);
					}
					
					//Set values (cast as strings again)
					$title = (string)$item->title;
					$link = (string)$item->link['href'];
					$author = (string)$item->author->name;
					
					//Grab the description and thumbail
					$description = (string)$item->children('media', TRUE)->group->description;
					$thumbnail = (string)$item->children('media', TRUE)->group->thumbnail->attributes();
					
					//DEBUG
					//echo "<img src='" . $thumbnail . "' /><hr />"; continue;
					//echo $date . " " . $title . " " . $link . " " . $description. "<br />";
					
					//Add to array
					$rss_arrange[$date]['title'] = $author . ": " . $title;
					$rss_arrange[$date]['desc'] = "<a href='" . $link . "'><img width='200' align='right' src='" . $thumbnail . "' /></a>";
					$rss_arrange[$date]['desc'] .= "Latest video from " . $author . ": " . $description;
					$rss_arrange[$date]['link'] = $link;
					
					//Increment count
					$vid_count++;
					
				}

			}

			//DEBUG
			//print_r($rss);die;
			//print_r($rss_arrange);die;

		}
		
		//Nothing to show
		if(empty($rss_arrange)){
			return FALSE;
		//Sort and return
		} else {
			krsort($rss_arrange);
			return $rss_arrange;
		}
	
	}
	
	//Function: write rss to a file
	private function _write_rss($path,$rss){
		
		//Input?
		if(!$path || !$rss){
			return FALSE;
		}
		
		//Open/create file
		$rss_file = fopen($path, "w") or die("Unable to write file.");

		//Write to file, close
		fwrite($rss_file, $rss);
		fclose($rss_file);
		
		//End function
		return TRUE;
		
	}
	
}