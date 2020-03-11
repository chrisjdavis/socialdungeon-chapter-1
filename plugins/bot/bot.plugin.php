<?php
namespace Habari;

class Bot extends Plugin
{
	const TWITKEY = '';
	const TWITSECRET = '';
	const OAUTH_TOKEN = '';
	const OAUTH_SECRET = '';

  /**
	 * insert
	 *
	 * @access private
	 * @param mixed array $data
	 * @param string $table_name
	 * @return void
	 */
	private function insert(array $data, string $table_name) {
		return DB::insert( DB::table( $table_name ), $data );
	}

	public function filter_autoload_dirs($dirs) {
		$dirs[] = __DIR__ . '/classes';

		return $dirs;
	}

	public function action_init() {
		DB::register_table( 'person_stats' );
	}

  /**
	 * setup_stats_table function.
	 *
	 * @access private
	 * @return void
	 */
	private function setup_stats_table() {
		$sql = "CREATE TABLE {\$prefix}person_stats (
		  id int(11) unsigned NOT NULL AUTO_INCREMENT,
			user_id int(11) unsigned DEFAULT NULL,
		  updated varchar(255) CHARACTER SET latin1 DEFAULT NULL,
			awarded_by varchar(255) CHARACTER SET latin1 DEFAULT NULL,
		  PRIMARY KEY (id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

		DB::dbdelta( $sql );
	}

  public function action_plugin_activated( $plugin_file ) {
		$this->setup_stats_table();
	}

  public function filter_default_rewrite_rules($rules) {
    $this->add_rule('"roll"/"mentions"', 'display_get_mentions');

		return $rules;
	}

  private function exists($id, $table) {
		$check = DB::get_column( "select id from $table where twitter_id = :id", array('id' => $id) );

		if( $check ) {
			return true;
		} else {
			return false;
		}
	}

  /**
		 * Take the Twitter oAuth information provided and return an authenticated session.
		 *
		 * @access private
		 * @param string $consumer_key
		 * @param string $consumer_secret
		 * @return authenticated session
		 */
	private function auth_twitter($consumer_key, $consumer_secret) {
		$oauth_token = self::OAUTH_TOKEN;
		$oauth_secret = self::OAUTH_SECRET;

		return new \Abraham\TwitterOAuth\TwitterOAuth( $consumer_key, $consumer_secret, $oauth_token, $oauth_secret );
	}

  public function theme_route_display_get_mentions($theme, $params) {
  	$game = new Gamer();
  	$connection = $this->auth_twitter( self::TWITKEY, self::TWITSECRET );
  	$data = $connection->get( 'statuses/mentions_timeline', ["count" => 100] );
  	$regex = "/@+([a-zA-Z0-9_]+)/";
  	$commands = array( 'help', 'mystats' );
  	$cats = array(
  		'strength', 'wisdom', 'charisma', 'defensive',
  		'constitution', 'dexterity', 'intelligence',
  		'willpower', 'perception', 'luck'
  	);
  	// Loop through the mentions returned.
  	foreach( $data as $mention ) {
  		$bits = array_filter(
  			explode( ' ' , preg_replace( $regex, '', $mention->text ))
  		);

  		$poop = array_pop($bits);

  		// Check to see if we have seen this mention before.
  		if( $this->exists( $mention->id, '{person_stats}' ) == false ) {
  			if( count($mention->entities->user_mentions) > 1 ) {

  				// This is an award or deduction.
  				$award = array_filter(
  					explode( ' ' , preg_replace( $regex, '', $mention->text ))
  				);

  				// Make sure we have all the bits we need to create an award.
  				if( count( $award ) > 2 ) {
  					$points = reset( $bits );
  					$category = array_pop( $bits );
  				} else {
  					$points = reset( $award );
  					$category = array_pop( $award );
  				}

  				// Next we check to make sure the category in the mention is
  				// one we support.
  				if( in_array($category, $cats) ) {
  					$args = array(
  						'awarded_to'	=>	$mention->in_reply_to_screen_name,
  						'awarded_by'	=>	$mention->user->screen_name,
  						'category'		=>	$category,
  						'points'			=>	intval( $points ),
  						'awarded_on'	=>	$mention->created_at,
  						'twitter_id'	=>	$mention->id,
  					);

  					// Finally we insert the award into the DB.
  					$this->insert( $args, '{person_stats}' );
  				}
  			}
  		}
  	}
  }
}
?>
