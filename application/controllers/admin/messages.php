<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Messages Controller.
 * View SMS Messages Received Via FrontlineSMS
 *
 * PHP version 5
 * LICENSE: This source file is subject to LGPL license 
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/copyleft/lesser.html
 * @author     Ushahidi Team <team@ushahidi.com> 
 * @package    Ushahidi - http://source.ushahididev.com
 * @module     Admin Messages Controller  
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license    http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License (LGPL) 
 */

class Messages_Controller extends Admin_Controller
{
	function __construct()
	{
		parent::__construct();
	
		$this->template->this_page = 'messages';		
	}
	
	/**
	* Lists the messages.
    * @param int $page
    */
	function index($page = 1)
	{
		$this->template->content = new View('admin/messages');
		$this->template->content->title = 'SMS Messages';

        //So far this assumes that selected 'message_id's are for deleting
        if (isset($_POST['message_id']))
            $this->deleteMessages($_POST['message_id']);
		
		// Is this an Inbox or Outbox Filter?
		if (!empty($_GET['type']))
		{
			$type = $_GET['type'];
			
			if ($type == '2')
			{
				$filter = 'message_type = 2';
			}
			else
			{
				$type = "1";
				$filter = 'message_type = 1';
			}
		}
		else
		{
			$type = "1";
			$filter = 'message_type = 1';
		}
		
		// check, has the form been submitted?
		$form_error = FALSE;
		$form_saved = FALSE;
		$form_action = "";
		
		// Pagination
		$pagination = new Pagination(array(
			'query_string'    => 'page',
			'items_per_page' => (int) Kohana::config('settings.items_per_page_admin'),
			'total_items'    => ORM::factory('message')->where($filter)->count_all()
		));

		$messages = ORM::factory('message')->where($filter)->orderby('message_date', 'desc')->find_all((int) Kohana::config('settings.items_per_page_admin'), $pagination->sql_offset);
		
		$this->template->content->messages = $messages;
		$this->template->content->pagination = $pagination;
		$this->template->content->form_error = $form_error;
		$this->template->content->form_saved = $form_saved;
		$this->template->content->form_action = $form_action;

		// Total Reports
		$this->template->content->total_items = $pagination->total_items;

		// Message Type Tab - Inbox/Outbox
		$this->template->content->type = $type;
		
		// Javascript Header
		$this->template->js = new View('admin/messages_js');
	}
	
	/**
	* Send A New Message Using Clickatell Library
    */
	function send()
	{
		$this->template = "";
		$this->auto_render = FALSE;
		
		// setup and initialize form field names
		$form = array
	    (
			'to_id' => '',
			'message' => ''
	    );
        //  Copy the form as errors, so the errors will be stored with keys
        //  corresponding to the form field names
        $errors = $form;
		$form_error = FALSE;
		
		// check, has the form been submitted, if so, setup validation
	    if ($_POST)
	    {
            // Instantiate Validation, use $post, so we don't overwrite $_POST
            // fields with our own things
            $post = new Validation($_POST);

	        // Add some filters
	        $post->pre_filter('trim', TRUE);

	        // Add some rules, the input field, followed by a list of checks, carried out in order
			$post->add_rules('to_id', 'required', 'numeric');
			$post->add_rules('message', 'required', 'length[1,160]');
			
			// Test to see if things passed the rule checks
	        if ($post->validate())
	        {
				// Yes! everything is valid				
				$reply_to = ORM::factory('message', $post->to_id);
				if ($reply_to->loaded == true) {
					// Yes! Replyto Exists
					// This is the message we're replying to
					$sms_to = $reply_to->message_from;
					
					// Load Users Settings
					$settings = new Settings_Model(1);
					if ($settings->loaded == true) {
						// Get SMS Numbers
						if (!empty($settings->sms_no3)) {
							$sms_from = $settings->sms_no3;
						}elseif (!empty($settings->sms_no2)) {
							$sms_from = $settings->sms_no2;
						}elseif (!empty($settings->sms_no1)) {
							$sms_from = $settings->sms_no1;
						}else{
							$sms_from = "000";		// User needs to set up an SMS number
						}
						
						// Create Clickatell Object
						$mysms = new Clickatell();
						$mysms->api_id = $settings->clickatell_api;
						$mysms->user = $settings->clickatell_username;
						$mysms->password = $settings->clickatell_password;
						$mysms->use_ssl = false;
						$mysms->sms();
						$send_me = $mysms->send ($sms_to, $sms_from, $post->message);
					
						// Message Went Through??
						if ($send_me == "OK") {
							$newmessage = ORM::factory('message');
							$newmessage->parent_id = $post->to_id;	// The parent message
							$newmessage->message_from = $sms_from;
							$newmessage->message_to = $sms_to;
							$newmessage->message = $post->message;
							$newmessage->message_type = 2;			// This is an outgoing message
							$newmessage->message_date = date("Y-m-d H:i:s",time());
							$newmessage->save();
							
							echo json_encode(array("status"=>"sent", "message"=>"Your message has been sent!"));
						}
						// Message Failed
						else {
							echo json_encode(array("status"=>"error", "message"=>"Error! - " . $send_me));
						}
					}
					else
					{
						echo json_encode(array("status"=>"error", "message"=>"Error! Please check your SMS settings!"));
					}
				}
				// Send_To Mobile Number Doesn't Exist
				else {
					echo json_encode(array("status"=>"error", "message"=>"Error! Please make sure your message is valid!"));
				}
	        }
	                    
            // No! We have validation errors, we need to show the form again,
            // with the errors
            else
	        {
	            // populate the error fields, if any
	            $errors = arr::overwrite($errors, $post->errors('messages'));
				echo json_encode(array("status"=>"error", "message"=>"Error! Please make sure your message is valid!"));
	        }
	    }
		
	}

    /**
     * Delete a single message
     */
    function delete($id = FALSE,$dbtable='message')
    {
        if($dbtable=='twitter'){
	        if ($id){
	            $update = ORM::factory($dbtable)->where('id',$id)->find();
				if ($update->loaded == true) {
					$update->hide = '1';
					$update->save();
				}
	        }
        	$extradir = 'twitter/';
        }else{
        	if ($id){
	            ORM::factory($dbtable)->delete($id);
	        }
        	$extradir = '';
        }
        //XXX:get the current page number
        url::redirect(url::base().'admin/messages/'.$extradir);
    }

    /**
     * Delete selected messages
     */
    function deleteMessages($ids,$dbtable='message')
    {
        //XXX:get the current page number
        if($dbtable=='twitter'){
        	foreach($ids as $id)
	        {
	            $update = new Twitter_Model($id);
				if ($update->loaded == true) {
					$update->hide = '1';
					$update->save();
				}
	        }
        	$extradir = 'twitter/';
        }else{
        	foreach($ids as $id)
	        {
	            ORM::factory($dbtable)->delete($id);
	        }
        	$extradir = '';
        }
        url::redirect(url::base().'admin/messages/'.$extradir);

    }
    
    /**
	* Lists the Twitter messages.
    */
	function twitter()
	{
		$this->template->content = new View('admin/messages_twitter');
		$this->template->content->title = 'Twitter Messages';
		
		$this->load_tweets();
		
		//So far this assumes that selected 'twitter_id's are for deleting
		if (isset($_POST['tweet_id'])) {
			$this->deleteMessages($_POST['tweet_id'],'twitter');
		}
		
		//Set Inbox/Outbox filter for query and message tab in view
		//Set default as inbox
		$type = 1;
		$filter = 'tweet_type = 1';
		//Check if outbox
		if (!empty($_GET['type']) && $_GET['type'] == 2){
			$type = 2;
			$filter = 'tweet_type = 2';
		}
		
		// check, has the form been submitted?
		$form_error = FALSE;
		$form_saved = FALSE;
		$form_action = "";
		
		// Pagination
		$pagination = new Pagination(array(
			'query_string'   => 'page',
			'items_per_page' => (int) Kohana::config('settings.items_per_page_admin'),
			'total_items'    => ORM::factory('twitter')->where($filter)->count_all()
		));

		$tweets = ORM::factory('twitter')->where($filter)->where('hide',0)->orderby('tweet_date', 'desc')->find_all((int) Kohana::config('settings.items_per_page_admin'), $pagination->sql_offset);
		
		// Populate values for view
		$this->template->content->tweets = $tweets;
		$this->template->content->pagination = $pagination;
		$this->template->content->form_error = $form_error;
		$this->template->content->form_saved = $form_saved;
		$this->template->content->form_action = $form_action;

		// Total Reports
		$this->template->content->total_items = $pagination->total_items;

		// Message Type Tab - Inbox/Outbox
		$this->template->content->type = $type;
		
		// Javascript Header
		$this->template->js = new View('admin/messages_js');
		
	}
	
	/**
	* Collects the twitter messages and loads them into the database
    */
	function load_tweets()
	{
		// Set a timer so Twitter doesn't get requests every page load.
		// Note: We will move this to the fake-cron in the scheduler controller and change this.
		$proceed = 0; // Sanity check. This is just in case $proceed doesn't get set.
		if(!isset($_SESSION['twitter_timer'])) {
			$_SESSION['twitter_timer'] = time();
			$proceed = 1;
		}else{
			$timeCheck = time() - $_SESSION['twitter_timer'];
			if($timeCheck > 300) { //If it has been longer than 300 seconds (5 min)
				$proceed = 1;
				$_SESSION['twitter_timer'] = time(); //Only if we proceed do we want to reset the timer
			}else{
				$proceed = 0;
			}
		}
		
		if($proceed == 1) { // Grab Tweets
			// Retrieve Current Settings
			$settings = ORM::factory('settings', 1);
			
			$username = $settings->twitter_username;
			$password = $settings->twitter_password;
			$twitter_url = 'http://twitter.com/statuses/replies.rss';
			$curl_handle = curl_init();
			curl_setopt($curl_handle,CURLOPT_URL,$twitter_url);
			curl_setopt($curl_handle,CURLOPT_CONNECTTIMEOUT,2); //Since Twitter is down a lot, set timeout to 2 secs
			curl_setopt($curl_handle,CURLOPT_RETURNTRANSFER,1); //Set curl to store data in variable instead of print
			curl_setopt($curl_handle,CURLOPT_USERPWD,"$username:$password"); //Authenticate!
			$buffer = curl_exec($curl_handle);
			curl_close($curl_handle);
			
			$search_username = ': @'.$username;
			$feed_data = $this->_setup_simplepie( $buffer ); //Pass this the raw xml data
			foreach($feed_data->get_items(0,50) as $feed_data_item) {
				//Grab tweet data from RSS feed
				$full_tweet = $feed_data_item->get_description();
				$full_date = $feed_data_item->get_date();
				$tweet_link = $feed_data_item->get_link();
				
				//Parse tweet for data
				$cut1 = stripos($full_tweet, $search_username); //Find the position of the username
				$cut2 = $cut1 + strlen($search_username) + 1; //Calculate the pos of the start of the tweet
				$tweet_from = substr($full_tweet,0,$cut1);
				$tweet_to = $username;
				$tweet = substr($full_tweet,$cut2);
				$tweet_date = date("Y-m-d H:i:s",strtotime($full_date));
								
				if(isset($full_tweet) && !empty($full_tweet)) {
					// We need to check for duplicates.
					// Note: Heave on server.
					$dupe_count = ORM::factory('twitter')->where('tweet_link',$tweet_link)->where('tweet',$tweet)->count_all();
					if ($dupe_count == 0) {
						// Add tweet to database
						$newtweet = new Twitter_Model();
						$newtweet->tweet_from = $tweet_from;
						$newtweet->tweet_to = $tweet_to;
						$newtweet->tweet_link = $tweet_link;
						$newtweet->tweet = $tweet;
						$newtweet->tweet_date = $tweet_date;
						$newtweet->save();
					}
				}
			}
		}
	}
	
	/**
	 * setup simplepie
	 * @param string $raw_data
	 */
	private function _setup_simplepie( $raw_data ) {
			$data = new SimplePie();
			$data->set_raw_data( $raw_data );
			$data->enable_cache(false);
			$data->enable_order_by_date(true);
			$data->init();
			$data->handle_content_type();
			return $data;
	}

		
}