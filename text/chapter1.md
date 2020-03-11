# 1 Start at the beginning

So as I mentioned in an earlier post, it's time for a good old SSWI tutorial series. This time we're going to look at the process of building [Social Dungeon](https://sillyness.co/socialdm). In this first chapter, we're going to look at how I built the Twitter Bot. Each of these "chapters" will have a corresponding github repo that will house all the code we are covering.

For this chapter you can <a href="https://github.com/chrisjdavis/socialdungeon-chapter-1" title="Building Social Dungeon: Chapter 1">find the repo here</a>. Happy hacking.

### So What's the Plan?
Okay, so this is what we need.
1. A bot to live on Twitter that responds to a user giving points to another user.
2. A database to track these awards for posterity.
3. A front end that allows users to see their stats as well as everyone else's in the system.

The first thing to do is create an Twitter app that we can connect to. Head on over to the [Twitter Developer site](https://developer.twitter.com/) and create your app. You'll need to save your API key and secret key, as well as your access token and access token secret, we'll need them later.

We will be using Habari for this, so all of the code we're looking at will be plugins. The first thing we need to do is grab a twitter library with [Composer](https://getcomposer.com), since I don't feel like rolling all of that from scratch.

In my case I grabbed [TwitterOAuth](https://github.com/abraham/twitteroauth) since it handles all of the bits that we need for this bot. I'm not going to go over how to use composer in this series, there are a lot of really great tutorials already online.

So once we've added our library it's time to sling some code.

### In the Beginning...
Was our first plugin, *Bot*. Plugins in Habari consist of at least two files. The file that holds all the code, ending in **.php** and an **.xml** file that holds info Habari needs to know how to handle the file.

You can find the files in the repo above, but for the sake of completeness, here is the XML file.

<script src="https://gist.github.com/chrisjdavis/ece101a2e475e33c6ef54761c8705e98.js"></script>

Okay now that we have that out of the way, let's move on to the meat of the situation, *bot.plugin.php.*

I think the best way to do that is to follow the flow of the functions, so we're going to jump around a little bit. Hopefully you'll catch on :)

But first we need to do some housekeeping. We need to have a table in our DB to hold the awards we are going to be processing. Habari's plugin architecure makes this easy.

<pre>
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
// go ahead and make that table.
public function action_plugin_activated( $plugin_file ) {
	$this->setup_stats_table();
}
</pre>

The first method *action_init()* tells Habari that we have a new table called *person_stats* and makes it available to the system. Our private method *setup_stats_table()* holds the schema for our new table.

To actually make the table, we reference the private method fro with *action_plugin_activated()* which is a function that is only run when the plugin is activated.

So, the way the bot works, is that once every few seconds a cronjob is fired that calls: */roll/mentions*

Which Habari's internal URL routing system maps to the *display_get_mentions* method. To tell Habari to map this URL to that method, we call *filter_default_rewrite_rules*, and add a new rule.

<pre>
public function filter_default_rewrite_rules($rules) {
	$this->add_rule('"roll"/mentions', 'display_get_mentions');
	return $rules;
}
</pre>

<blockquote>
Notice that in our new URL *roll* and *mentions* are surrounded in double quotes. This tells Habari that they are static parts of the URL. If we provided a string without any quotes, say *username*, that would tell Habari that username is a variable that could be used to say, query for mentions from a specific account.
</blockquote>

Now that Habari knows what we want to do with that URL, it's time to actually write the method. So what's going to happen here is that we are going to make a connection to Twitter as our Social Dungeon Master account, and check for any @mentions that have been sent our way.

If we find any, we check to see if they have already been run, and if so skip to the next one. Once we find a new @mention, we analyze the text to extract the category and value which we then save to the DB. We are able to get the account that was awarded from the mention, so we save that as well.

Let's break down the method piece by piece. First we need to authenticate as our DM user. Remember I said we would need those keys from Twitter? Here is the first time we'll be using them.

For ease of use, go ahead and create four constants at the top of the plugin so we can just reference these and your oAuth tokens in the code.

<pre>
class Bot extends Plugin
{
	const TWITKEY = '';
	const TWITSECRET = '';
	const OAUTH_TOKEN = '';
	const OAUTH_SECRET = '';
}
</pre>

Now we can update them once, and anywhere they are referenced in the code, they will be updated. Now, let's get to authenticating.

<pre>
public function theme_route_display_get_mentions($theme, $params) {
	$connection = $this->auth_twitter( self::TWITKEY, self::TWITSECRET );
	$data = $connection->get( 'statuses/mentions_timeline', ["count" => 100] );
}
</pre>

So this should be self explanatory, but here we are grabbing the latest 100 mentions from our socialdungeon account, that we can then process. We are referencing a new method, *auth_twitter()* so let's set that up right quick.

<pre>
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
</pre>

Pretty simple. We provide the long lived oAuth token and secret that Twitter generated when we setup our app, and use it to create an authenticated session. Moving on!

<pre>
public function theme_route_display_get_mentions($theme, $params) {
	$connection = $this->auth_twitter( self::TWITKEY, self::TWITSECRET );
	$data = $connection->get( 'statuses/mentions_timeline', ["count" => 100] );
	$regex = "/@+([a-zA-Z0-9_]+)/";
	/**
	 * Array that holds attributes that we currently support.
	 * @todo: Move this into a DB table so it can be managed.
	**/
	$cats = array(
		'strength', 'wisdom', 'charisma', 'defensive',
		'constitution', 'dexterity', 'intelligence',
		'willpower', 'perception', 'luck'
	);
}
</pre>

Now that we have some mentions, we need to iterate over them, and see if there are any that are awarding points to a user. The first step of this process is to craft a regex, and determine the attributes that we support.

If the mention has something outside the confines of these attributes, we can safely ignore it **for now**. We'll be using that array of attributes in a minute, hang tight.

Right, so let's do some matching!

<pre>
public function theme_route_display_get_mentions($theme, $params) {
	$connection = $this->auth_twitter( self::TWITKEY, self::TWITSECRET );
	$data = $connection->get( 'statuses/mentions_timeline', ["count" => 100] );
	$regex = "/@+([a-zA-Z0-9_]+)/";
	/**
	 * Array that holds attributes that we currently support.
	 * @todo: Move this into a DB table so it can be managed.
	**/
	$cats = array(
		'strength', 'wisdom', 'charisma', 'defensive',
		'constitution', 'dexterity', 'intelligence',
		'willpower', 'perception', 'luck'
	);
	// Loop through our mentions to find awards.
	foreach( $data as $mention ) {
		$bits = array_filter(
			explode( ' ' , preg_replace( $regex, '', $mention->text ))
		);
		// get the last element from our newly created mention array.
		$poop = array_pop($bits);
	}
}
</pre>

Okay, at this point we should have a mention that is looks like an award to another user. The next thing we need to do is make sure we haven't seen this mention before, and to do that we need to write a new method, and reference it in our method.

<pre>
public function theme_route_display_get_mentions($theme, $params) {
	$connection = $this->auth_twitter( self::TWITKEY, self::TWITSECRET );
	$data = $connection->get( 'statuses/mentions_timeline', ["count" => 100] );
	$regex = "/@+([a-zA-Z0-9_]+)/";
	/**
	 * Array that holds attributes that we currently support.
	 * @todo: Move this into a DB table so it can be managed.
	**/
	$cats = array(
		'strength', 'wisdom', 'charisma', 'defensive',
		'constitution', 'dexterity', 'intelligence',
		'willpower', 'perception', 'luck'
	);
	// Loop through our mentions to find awards.
	foreach( $data as $mention ) {
		$bits = array_filter(
			explode( ' ' , preg_replace( $regex, '', $mention->text ))
		);
		// get the last element from our newly created mention array.
		$poop = array_pop($bits);
		// Check to see if we have seen this mention before.
		if( $this->exists( $mention->id, '{person_stats}' ) == false ) {
		}
	}
}
</pre>

As you can see we are passing the id of the mention (this is the tweet_id from twitter), and checking to see if we already have it in the DB. We return true if the tweet_id is found, and false if it isn't. Pretty simple.

Here for the method "exists". Very simple.

<pre>
private function exists($id, $table) {
	$check = DB::get_column( "select id from $table where twitter_id = :id", array('id' => $id) );
	if( $check ) {
		return true;
	} else {
		return false;
	}
}
</pre>

You can place this anywhere in your plugin file you want> I tend to group all the private methods together near the top of the class, but again you are free to do as you like.

Okay now that we can grab mentions, check for those that look like they are awarding points ∫

That means we head back to our *display_get_mentions* method!

<pre>
public function theme_route_display_get_mentions($theme, $params) {
	$connection = $this->auth_twitter( self::TWITKEY, self::TWITSECRET );
	$data = $connection->get( 'statuses/mentions_timeline', ["count" => 100] );
	$regex = "/@+([a-zA-Z0-9_]+)/";
	/**
	 * Array that holds attributes that we currently support.
	 * @todo: Move this into a DB table so it can be managed.
	**/
	$cats = array(
		'strength', 'wisdom', 'charisma', 'defensive',
		'constitution', 'dexterity', 'intelligence',
		'willpower', 'perception', 'luck'
	);
	// Loop through our mentions to find awards.
	foreach( $data as $mention ) {
		$bits = array_filter(
			explode( ' ' , preg_replace( $regex, '', $mention->text ))
		);
		// get the last element from our newly created mention array.
		$poop = array_pop($bits);
		// Check to see if we have seen this mention before.
		if( $this->exists( $mention->id, '{person_stats}' ) == false ) {
			if( count($mention->entities->user_mentions) > 1 ) {
			// This is an award or deduction.
			$award = array_filter(
				explode( ' ' , preg_replace( $regex, '', $mention->text ))
			);
			// Make sure we have what we need to create an award.
			if( count( $award ) > 2 ) {
				$points = reset( $bits );
				$category = array_pop( $bits );
			} else {
				$points = reset( $award );
				$category = array_pop( $award );
			}
		}
	}
}
</pre>

This might look complicated, but trust me it isn't. Let's take it line by line. First up we check for the number of *user_mentions*.

<pre>
if( count($mention->entities->user_mentions) > 1 ) {}
</pre>

There are inconsistencies with how Twitter returns mentions based on a few factors, things like how many people are referenced in the tweet thread. To handle that we need to check how many user_mentions are associated with this tweet. If there is more than one, we are good.

So now we have a mention that could possibly be an award, we need to break the mention up into bits, so we can isolate the attribute and the points total.

<pre>
$award = array_filter(
	explode( ' ' , preg_replace( $regex, '', $mention->text ))
);
</pre>

We reuse the regex from earlier and are rewarded with an array of text strings. We'll need two of these for the next step. Remember how I said that Twitter has some inconsistencies with how they return data via the API? Well here is a fine example. This *if* statement handles one of those cases.

<pre>
if( count( $award ) > 2 ) {
	$points = reset( $bits );
	$category = array_pop( $bits );
} else {
	$points = reset( $award );
	$category = array_pop( $award );
}
</pre>

Okay, presumably we now have the points we need to award and the attribute category. Next we need to check and see if the attribute provided is one we support, and if so go ahead and save that award to the DB. Almost there!

<pre>
public function theme_route_display_get_mentions($theme, $params) {
	$connection = $this->auth_twitter( self::TWITKEY, self::TWITSECRET );
	$data = $connection->get( 'statuses/mentions_timeline', ["count" => 100] );
	$regex = "/@+([a-zA-Z0-9_]+)/";
	/**
	 * Array that holds attributes that we currently support.
	 * @todo: Move this into a DB table so it can be managed.
	**/
	$cats = array(
		'strength', 'wisdom', 'charisma', 'defensive',
		'constitution', 'dexterity', 'intelligence',
		'willpower', 'perception', 'luck'
	);
	// Loop through our mentions to find awards.
	foreach( $data as $mention ) {
		$bits = array_filter(
			explode( ' ' , preg_replace( $regex, '', $mention->text ))
		);
		// get the last element from our newly created mention array.
		$poop = array_pop($bits);
		// Check to see if we have seen this mention before.
		if( $this->exists( $mention->id, '{person_stats}' ) == false ) {
			if( count($mention->entities->user_mentions) > 1 ) {
			// This is an award or deduction.
			$award = array_filter(
				explode( ' ' , preg_replace( $regex, '', $mention->text ))
			);
			// Make sure we have what we need to create an award.
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
					'points'		=>	intval( $points ),
					'awarded_on'	=>	$mention->created_at,
					'twitter_id'	=>	$mention->id,
				);
				// Finally we insert the award into the DB.
				$this->insert( $args, '{person_stats}' );
			}
		}
	}
}
</pre>

Okay, this is the last bit I promise! At least for today! So first we check that the attribute we found is supported by the system. For this we use trust *in_array()*. If that returns true it's time to create our payload that will be saved to the DB.

<pre>
if( in_array($category, $cats) ) {
	$args = array(
		'awarded_to'	=>	$mention->in_reply_to_screen_name,
		'awarded_by'	=>	$mention->user->screen_name,
		'category'		=>	$category,
		'points'		=>	intval( $points ),
		'awarded_on'	=>	$mention->created_at,
		'twitter_id'	=>	$mention->id,
	);
</pre>

Now that we have our payload, the last step is to pass it to our *insert()* method and call it a day.

<pre>
// Finally we insert the award into the DB.
$this->insert( $args, '{person_stats}' );
</pre>

And with that we've saved an award to the DB. Step one of this nonsense is complete. Pat yourself on the back for a job well done, and get ready for Chapter 2 which will be coming next week.
