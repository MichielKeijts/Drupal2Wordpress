<?php
	
	require_once("php-mysql.php");

	//Database Host Name
	$DB_HOSTNAME	= 'localhost';
	
	//Wordpress Database Name, Username and Password
	$DB_WP_USERNAME	= 'root';
	$DB_WP_PASSWORD	= 'root';
	$DB_WORDPRESS	= 'wordpress';

	//Drupal Database Name, Username and Password
	$DB_DP_USERNAME	= 'root';
	$DB_DP_PASSWORD	= 'root';
	$DB_DRUPAL		= 'drupal';

	//Table Prefix
	$DB_WORDPRESS_PREFIX = 'wp_';
	$DB_DRUPAL_PREFIX	 = '';
	
	// Image dir (relative to root of drupal, with trailing /
	// default: 
	// $DRUPAL_IMG_DIR	=	'/sites/default/files/';
	$DRUPAL_IMG_DIR	=	'/sites/default/files/';
	
	// Wordpress Site URI to image dir with trailing /
	// default: 
	// $WP_SITE_URI		=	'http://www.yourdomain.ext/wp-content/uploads/';
	$WP_SITE_URI	=	'http://wordpress/wp-content/uploads/';

	//Create Connection Array for Drupal and Wordpress
	$drupal_connection		= array("host" => "localhost","username" => $DB_DP_USERNAME,"password" => $DB_DP_PASSWORD,"database" => $DB_DRUPAL);
	$wordpress_connection	= array("host" => "localhost","username" => $DB_WP_USERNAME,"password" => $DB_WP_PASSWORD,"database" => $DB_WORDPRESS);

	//Create Connection for Drupal and Wordpress
	$dc = new DB($drupal_connection);
	$wc = new DB($wordpress_connection);

	//Check if database connection is fine
	$dcheck = $dc->check();	
	if (!$dcheck){
		echo "This $DB_DRUPAL service is UNAVAILABLE"; die();
	}

	$wcheck = $wc->check();	
	if (!$wcheck){
		echo "This $DB_WORDPRESS service is UNAVAILABLE"; die();
	}

	message('Database Connection successful');

	//Empty the current worpdress Tables	
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."comments");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."links");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."postmeta");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."posts");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."term_relationships");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."term_taxonomy");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."terms");
	message('Wordpress Table Truncated');
	
	//Get all drupal Tags and add it into worpdress terms table
	$drupal_tags = $dc->results("SELECT DISTINCT d.tid, d.name, REPLACE(LOWER(d.name), ' ', '_') AS slug FROM ".$DB_DRUPAL_PREFIX."taxonomy_term_data d INNER JOIN ".$DB_DRUPAL_PREFIX."taxonomy_term_hierarchy h ON (d.tid = h.tid) ORDER BY d.tid ASC");
	foreach($drupal_tags as $dt)
	{
		$wc->query("REPLACE INTO ".$DB_WORDPRESS_PREFIX."terms (term_id, name, slug) VALUES ('%s','%s','%s')", $dt['tid'], $dt['name'], $dt['slug']);
	}

	//Update worpdress term_taxonomy table
	$drupal_taxonomy = $dc->results("SELECT DISTINCT d.tid AS term_id, 'post_tag' AS post_tag, d.description AS description, h.parent AS parent FROM ".$DB_DRUPAL_PREFIX."taxonomy_term_data d INNER JOIN ".$DB_DRUPAL_PREFIX."taxonomy_term_hierarchy h ON (d.tid = h.tid) ORDER BY 'term_id' ASC");
	foreach($drupal_taxonomy as $dt)
	{
		// Workaround, as WP Field description cannot be NULL
		if (empty($dt['description'])) $dt['description']=""; 
		$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."term_taxonomy (term_id, taxonomy, description, parent) VALUES ('%s','%s','%s','%s')", $dt['term_id'], $dt['post_tag'], $dt['description'], $dt['parent']);
	}

	message('Tags Updated');

	// Update worpdress category for a new Blog entry (as catgegory) which
	// is a must for a post to be displayed well
	// Insert a fake new category named Blog
	$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."terms (name, slug) VALUES ('%s','%s')", 'Blog', 'blog');
	
	// Then query to get this entry so we can attach it to content we create
	$blog_term_id = 0;
	$row = $wc->row("SELECT term_id FROM ".$DB_WORDPRESS_PREFIX."terms WHERE name = '%s' AND slug = '%s'", 'Blog', 'blog');
	if (!empty($row['term_id'])) {
		$blog_term_id = $row['term_id'];

		// Workaround, added WP Field description as it cannot be NULL
		$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."term_taxonomy (term_id, taxonomy, description) VALUES ('%d','%s','%s')", $blog_term_id, 'category', '');

	}

	message('Category Updated');


	//Get all post from Drupal and add it into wordpress posts table
	$drupal_posts = $dc->results("SELECT DISTINCT n.nid AS id, n.uid AS post_author, FROM_UNIXTIME(n.created) AS post_date, r.body_value AS post_content, n.title AS post_title, r.body_summary AS post_excerpt, n.type AS post_type,  IF(n.status = 1, 'publish', 'draft') AS post_status FROM ".$DB_DRUPAL_PREFIX."node n, ".$DB_DRUPAL_PREFIX."field_data_body r WHERE (n.nid = r.entity_id)");
	$post_type = 'page';
	foreach($drupal_posts as $dp)
	{

		// Wordpress basicially has 2 core post_type options, similar to Drupal -
		// either a blog-style content, where in Drupal is referred to as 'article'
		// and in Wordpress this is 'post', and the page-style content which is 
		// referred to as 'page' content type in both platforms.

		// For the sake of supporting out of the box seamless migration we will
		// assume that any Drupal 'article' content type should be a Wordpress
		// 'post' type and anything else will be set to 'page'
		
		// 2014-11-18 Added option 'blog', which is essentially the same as a article

		if ($dp['post_type'] === 'article' || $dp['post_type']=='blog')
			$post_type = 'post';
		else 
			$post_type = 'page';

		
		// Drupal Stores the links to images into the /sites/default/files/ directory
		// Within Wordpress this has to change to /wp-content/uploads/ 
		// SQL: 
		//	REPLACE(r.body_value, '/sites/default/files/',		'/wp-content/uploads/')
		//	REPLACE(r.body_summary, '/sites/default/files/',	'/wp-content/uploads/')
		// PHP:
		//	$DRUPAL_IMG_DIR is defined
		$dp['post_content']=str_replace($DRUPAL_IMG_DIR, '/wp-content/uploads/', $dp['post_content']);
		$dp['post_excerpt']=str_replace($DRUPAL_IMG_DIR, '/wp-content/uploads/', $dp['post_excerpt']);
		
		// 2014-11-15 Added field `post_content_filtered`, `to_ping`  and `pinged` as it cannot be NULL
		$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."posts (id, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_type, post_status, to_ping,pinged, post_content_filtered) VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')", $dp['id'], $dp['post_author'], $dp['post_date'], $dp['post_date'], $dp['post_content'], $dp['post_title'], $dp['post_excerpt'], $post_type, $dp['post_status'],'','','');

		// Attach all posts to the Blog category we created earlier
		if ($blog_term_id !== 0) {
			// Attach all posts the terms/tags 
			$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."term_relationships (object_id, term_taxonomy_id) VALUES ('%s','%s')", $dp['id'], $blog_term_id);
		}

	}
	message('Posts Updated');

	//Add relationship for post and tags
	$drupal_post_tags = $dc->results("SELECT DISTINCT node.nid, taxonomy_term_data.tid FROM (".$DB_DRUPAL_PREFIX."taxonomy_index taxonomy_index INNER JOIN ".$DB_DRUPAL_PREFIX."taxonomy_term_data taxonomy_term_data ON (taxonomy_index.tid = taxonomy_term_data.tid)) INNER JOIN ".$DB_DRUPAL_PREFIX."node node ON (node.nid = taxonomy_index.nid)"); 
	foreach($drupal_post_tags as $dpt)
	{
		$wordpress_term_tax = $wc->row("SELECT DISTINCT term_taxonomy.term_taxonomy_id FROM ".$DB_WORDPRESS_PREFIX."term_taxonomy term_taxonomy  WHERE (term_taxonomy.term_id = ".$dpt['tid'].")");

		// Attach all posts the terms/tags 
		$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."term_relationships (object_id, term_taxonomy_id) VALUES ('%s','%s')", $dpt['nid'], $wordpress_term_tax['term_taxonomy_id']);
	}

	message('Tags & Posts Relationships Updated');

	//Update the post type for worpdress
	$wc->query("UPDATE ".$DB_WORDPRESS_PREFIX."posts SET post_type = 'post' WHERE post_type IN ('blog')");
	message('Posted Type Updated');

	//Count the total tags
	$wc->query("UPDATE ".$DB_WORDPRESS_PREFIX."term_taxonomy tt SET count = ( SELECT COUNT(tr.object_id) FROM ".$DB_WORDPRESS_PREFIX."term_relationships tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id )");	
	message('Tags Count Updated');

	//Get the url alias from drupal and use it for the Post Slug
	$drupal_url = $dc->results("SELECT url_alias.source, url_alias.alias FROM ".$DB_DRUPAL_PREFIX."url_alias url_alias WHERE (url_alias.source LIKE 'node%')");
	foreach($drupal_url as $du)
	{
		$update = $wc->query("UPDATE ".$DB_WORDPRESS_PREFIX."posts SET post_name = '%s' WHERE ID = '%s'",
				// Make sure we import without Drupal's leading 'content/' in the URL
				str_replace('content/', '', $du['alias']),
				str_replace('node/','',$du['source'])
		);
	}
	message('URL Alias to Slug Updated');

	// Move the comments and their replies - 11 Levels
	// Ensure we import only approved comments (c.status = 1) as otherwise we might be importing a ton of spam from a Drupal site
	$drupal_comments = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = 0) AND c.status = 1");
	foreach($drupal_comments as $duc)
	{
		$insert = $wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$duc['comment_ID'],$duc['comment_post_ID'],$duc['comment_author'],$duc['comment_author_email'],$duc['comment_author_url'],$duc['comment_author_IP'],$duc['comment_date'],$duc['comment_date'],$duc['comment_content'],'1','0');

		$drupal_comments_level1 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$duc['comment_ID'].") AND c.status = 1");

		foreach($drupal_comments_level1 as $dcl1)
		{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl1['comment_ID'],$dcl1['comment_post_ID'],$dcl1['comment_author'],$dcl1['comment_author_email'],$dcl1['comment_author_url'],$dcl1['comment_author_IP'],$dcl1['comment_date'],$dcl1['comment_date'],$dcl1['comment_content'],'1',$duc['comment_ID']);

		$drupal_comments_level2 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$dcl1['comment_ID'].") AND c.status = 1");

			foreach ($drupal_comments_level2 as $dcl2)
			{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl2['comment_ID'],$dcl2['comment_post_ID'],$dcl2['comment_author'],$dcl2['comment_author_email'],$dcl2['comment_author_url'],$dcl2['comment_author_IP'],$dcl2['comment_date'],$dcl2['comment_date'],$dcl2['comment_content'],'1',$dcl1['comment_ID']);


		$drupal_comments_level3 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$dcl2['comment_ID'].") AND c.status = 1");

			foreach ($drupal_comments_level3 as $dcl3)
			{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl3['comment_ID'],$dcl3['comment_post_ID'],$dcl3['comment_author'],$dcl3['comment_author_email'],$dcl3['comment_author_url'],$dcl3['comment_author_IP'],$dcl3['comment_date'],$dcl3['comment_date'],$dcl3['comment_content'],'1',$dcl2['comment_ID']);


		$drupal_comments_level4 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$dcl3['comment_ID'].") AND c.status = 1");


			foreach ($drupal_comments_level4 as $dcl4)
			{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl4['comment_ID'],$dcl4['comment_post_ID'],$dcl4['comment_author'],$dcl4['comment_author_email'],$dcl4['comment_author_url'],$dcl4['comment_author_IP'],$dcl4['comment_date'],$dcl4['comment_date'],$dcl4['comment_content'],'1',$dcl3['comment_ID']);


		$drupal_comments_level5 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$dcl4['comment_ID'].") AND c.status = 1");


	foreach ($drupal_comments_level5 as $dcl5)
			{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl5['comment_ID'],$dcl5['comment_post_ID'],$dcl5['comment_author'],$dcl5['comment_author_email'],$dcl5['comment_author_url'],$dcl5['comment_author_IP'],$dcl5['comment_date'],$dcl5['comment_date'],$dcl5['comment_content'],'1',$dcl4['comment_ID']);


		$drupal_comments_level6 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$dcl5['comment_ID'].") AND c.status = 1");


	foreach ($drupal_comments_level6 as $dcl6)
			{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl6['comment_ID'],$dcl6['comment_post_ID'],$dcl6['comment_author'],$dcl6['comment_author_email'],$dcl6['comment_author_url'],$dcl6['comment_author_IP'],$dcl6['comment_date'],$dcl6['comment_date'],$dcl6['comment_content'],'1',$dcl5['comment_ID']);


		$drupal_comments_level7 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$dcl6['comment_ID'].") AND c.status = 1");


	foreach ($drupal_comments_level7 as $dcl7)
			{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl7['comment_ID'],$dcl7['comment_post_ID'],$dcl7['comment_author'],$dcl7['comment_author_email'],$dcl7['comment_author_url'],$dcl7['comment_author_IP'],$dcl7['comment_date'],$dcl7['comment_date'],$dcl7['comment_content'],'1',$dcl6['comment_ID']);


		$drupal_comments_level8 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$dcl7['comment_ID'].") AND c.status = 1");

	foreach ($drupal_comments_level8 as $dcl8)
			{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl8['comment_ID'],$dcl8['comment_post_ID'],$dcl8['comment_author'],$dcl8['comment_author_email'],$dcl8['comment_author_url'],$dcl8['comment_author_IP'],$dcl8['comment_date'],$dcl8['comment_date'],$dcl8['comment_content'],'1',$dcl7['comment_ID']);


		$drupal_comments_level9 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$dcl8['comment_ID'].") AND c.status = 1");

	foreach ($drupal_comments_level9 as $dcl9)
			{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl9['comment_ID'],$dcl9['comment_post_ID'],$dcl9['comment_author'],$dcl9['comment_author_email'],$dcl9['comment_author_url'],$dcl9['comment_author_IP'],$dcl9['comment_date'],$dcl9['comment_date'],$dcl9['comment_content'],'1',$dcl8['comment_ID']);



		$drupal_comments_level10 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$dcl9['comment_ID'].") AND c.status = 1");


foreach ($drupal_comments_level10 as $dcl10)
			{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl10['comment_ID'],$dcl10['comment_post_ID'],$dcl10['comment_author'],$dcl10['comment_author_email'],$dcl10['comment_author_url'],$dcl10['comment_author_IP'],$dcl10['comment_date'],$dcl10['comment_date'],$dcl10['comment_content'],'1',$dcl9['comment_ID']);

			echo $dcl10['comment_ID'] . '<br />';


		$drupal_comments_level11 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$dcl10['comment_ID'].") AND c.status = 1");



foreach ($drupal_comments_level11 as $dcl11)
			{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl11['comment_ID'],$dcl11['comment_post_ID'],$dcl11['comment_author'],$dcl11['comment_author_email'],$dcl11['comment_author_url'],$dcl11['comment_author_IP'],$dcl11['comment_date'],$dcl11['comment_date'],$dcl11['comment_content'],'1',$dcl10['comment_ID']);




		$drupal_comments_level12 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$dcl11['comment_ID'].") AND c.status = 1");


foreach ($drupal_comments_level12 as $dcl12)
			{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl12['comment_ID'],$dcl12['comment_post_ID'],$dcl12['comment_author'],$dcl12['comment_author_email'],$dcl12['comment_author_url'],$dcl12['comment_author_IP'],$dcl12['comment_date'],$dcl12['comment_date'],$dcl12['comment_content'],'1',$dcl11['comment_ID']);


			echo '<br />' . $dcl12['comment_ID'] . '<br />';



						}

					}



											}
										}
									}
								}
							}
						}
					}
				}
			}		
		}
	}
	message('Comments Updated - 11 Level');

	//Update Comment Counts in Wordpress
	$wc->query("UPDATE ".$DB_WORDPRESS_PREFIX."posts SET comment_count = ( SELECT COUNT(comment_post_id) FROM ".$DB_WORDPRESS_PREFIX."comments WHERE ".$DB_WORDPRESS_PREFIX."posts.id = ".$DB_WORDPRESS_PREFIX."comments.comment_post_id )");


	// Update wordpress users
	// From Drupal we're getting the essential user details, including their
	// user id so we can maintain the same posts ownership when we migrate
	// content over to Wordpress.
	//
	// * Special edge-case: we're skipping the administrative user migration
	// * Special edge-case: passwords are intentionally left blank as this
	//   forces user expiration in Wordpress
	$drupal_users = $dc->results("SELECT u.uid, u.name, u.mail, FROM_UNIXTIME(u.created) AS created, u.access FROM ".$DB_DRUPAL_PREFIX."users u WHERE u.uid != 1 AND u.uid != 0");
	foreach($drupal_users as $du)
	{
		$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."users 
			(`ID`, `user_login`, `user_pass`, `user_nicename`, `user_email`, `user_registered`, `display_name`)
			 VALUES 
			('%s','%s','%s','%s','%s','%s','%s')", $du['uid'], $du['name'], '', $du['name'], $du['mail'], $du['created'], $du['name']);
	}

	message('Users Updated');
	
	
	
	// Try to Update Drupal Image fields, when created
	// NB it is necessary to know a priori what table names are used for the images in the Drupal database
	//	1- find first the table with the image information (unlimitted supported, in principle, but not tested
	//	2- select all data from the image table
	//	3- copy all image data in the wp_posts table. 
	//	3a	- insert one image int the wp_posts table and associate entity_id with post_id
	//	3b	- insert in the postmeta table the information of the image
	//	
	//	assumed: 
	//	-	DRUPAL_IMG_DIR ('/sites/all/files/');
	//	
	//	First, make post-format-image and post-format-gallery available in database table
	$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."terms (name, slug, term_group) VALUES	('post-format-gallery','post-format-gallery',0)");
	$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."terms (name, slug, term_group) VALUES	('post-format-image','post-format-image',0)");
	$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."term_taxonomy (term_id, taxonomy, description) (SELECT term_id, 'post_format' AS t, '' as description FROM ".$DB_WORDPRESS_PREFIX."terms WHERE name='post-format-gallery' OR name='post-format-image')");
	
	
	// Drupal Image Fields Placeholder
	$dif_list=array();
	
	// find all image fields in Drupal from th field_config table
	//	-	only use field_sql_storage:	field_config.storage_module='field_sql_storage'  
	//	-	non-deleted: field_config.deleted=0
	$drupal_image_fields = $dc->results("select * from $DB_DRUPAL_PREFIX.field_config WHERE `type`='image' AND storage_module='field_sql_storage' AND deleted=0");
	message(sprintf('Found %d image fields.. ', (int)count($drupal_image_fields)));
	
	// try to pass through each image field table
	foreach($drupal_image_fields as $dif)
	{
		// current table for the images
		$current_img_table=$DB_DRUPAL_PREFIX.'field_data_'.$dif['field_name'];
		// dealing with images
		
		// find all images, where a node link is live (INNER JOIN)
		// join file information
		$images=$dc->results("SELECT * FROM {$current_img_table} f "
				. "INNER JOIN {$DB_DRUPAL_PREFIX}.node n ON n.nid = f.entity_id "
				. "INNER JOIN {$DB_DRUPAL_PREFIX}.file_managed fm ON fm.fid = f.{$dif['field_name']}_fid "
				. "ORDER BY entity_id ASC, delta ASC");
		
		
		// Only one thumbnail is allowed per post.
		// This array holds the used posts
		$post_thumbs=array();
		
		foreach ($images as $di) {
			// format filename to be used as guid
			$filename=$WP_SITE_URI.$di['filename'];
			$id=$wc->insert_and_return_id("INSERT INTO ".$DB_WORDPRESS_PREFIX."posts 
					(post_author, post_date	, post_date_gmt	, post_modified	, post_modified_gmt	,post_content	, post_title, post_excerpt	, post_parent	, guid				,post_type		, post_mime_type, post_status	, to_ping	,pinged	, post_content_filtered)
					VALUES
					('%s'		,'%s'		,'%s'			,'%s'			,'%s'				,'%s'			,'%s'		,'%s'			,'%s'			,'%s'				,'%s'			,'%s'			,'%s'			,'%s'		,'%s'	,'%s')", 
					$di['uid'], date('Y-m-d H:i:s',$di['timestamp']), gmdate('Y-m-d H:i:s',$di['timestamp']), date('Y-m-d H:i:s',$di['changed']), gmdate('Y-m-d H:i:s',$di['changed']),$di[$dif['field_name'].'_title'], $di['filename'], $di[$dif['field_name'].'_alt'], $di['entity_id'], $filename, 'attachment', $di['filemime'],'inherit','','',''
			);
			
			// INSERT into post_meta table
			// 
			// in Wordpress the post_meta saves some information about the image size this is set:
			//	wp_postmeta table
			//		-	meta_id		(to be created)
			//		-	post_id		(from drupal post_id)
			//		-	meta_key	(2 options: _wp_attached_file OR _wp_attachment_metadata
			//		-	meta_value	(filename OR serialized width and height)
			$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."postmeta 
				(`post_id`, `meta_key`, `meta_value`)
				 VALUES 
				('%s','%s','%s')", $id, '_wp_attached_file', $di['filename']);
			
			// set layout to inherit
			$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."postmeta 
				(`post_id`, `meta_key`, `meta_value`)
				 VALUES 
				('%s','%s','%s')", $id, '_layout', 'inherit');
			
			// Add thumbnail ID
			// only one is supported, so only one is used
			if (!isset($post_thumbs[$di['entity_id']])) {
				$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."postmeta 
					(`post_id`, `meta_key`, `meta_value`)
					 VALUES 
					('%s','%s','%s')", $di['entity_id'], '_thumbnail_id', $id);
			}
			
			// update post_thumbs
			$post_thumbs[$di['entity_id']]=true;
		}
		message('Updated all '.$dif['field_name'].' values.. (#' .count($images).')');
	}
	
	// update term relationships
	$wc->query("REPLACE INTO {$DB_WORDPRESS_PREFIX}term_relationships (object_id, term_taxonomy_id) SELECT post_parent, IF(COUNT(post_parent)>1, (SELECT term_id FROM {$DB_WORDPRESS_PREFIX}terms WHERE `name`='post-format-gallery'),(SELECT term_id FROM {$DB_WORDPRESS_PREFIX}terms WHERE `name`='post-format-image')) AS ttid FROM {$DB_WORDPRESS_PREFIX}posts WHERE `post_type`='attachment' AND post_parent>0 GROUP BY post_parent");
	
	
	// pfieww..
	message('Cheers !!');

	/*
		TO DO - Skipped coz didnt have much comment and Users, if you need then share you database and shall work upon and fix it for you.
		
		1.) Update Users/Authors
	*/
	
	//Preformat the Object for Debuggin Purpose
	function po($obj){
		echo "<pre>";
		print_r($obj);
		echo "</pre>";
	}

	function message($msg){
		echo "<hr>$msg</hr>";
		func_flush();
	}

	function func_flush($s = NULL)
	{
		if (!is_null($s))
			echo $s;

		if (preg_match("/Apache(.*)Win/S", getenv('SERVER_SOFTWARE')))
			echo str_repeat(" ", 2500);
		elseif (preg_match("/(.*)MSIE(.*)\)$/S", getenv('HTTP_USER_AGENT')))
			echo str_repeat(" ", 256);

		if (function_exists('ob_flush'))
		{
			// for PHP >= 4.2.0
			@ob_flush();
		}
		else
		{
			// for PHP < 4.2.0
			if (ob_get_length() !== FALSE)
				ob_end_flush();
		}
		flush();
	}
