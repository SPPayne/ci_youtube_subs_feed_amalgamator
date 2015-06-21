<?php header("Content-Type: application/rss+xml; charset=ISO-8859-1"); ?>
<?php echo '<?xml version="1.0" encoding="ISO-8859-1"?>';//Echo otherwise it is interpreted as PHP! ?>
<rss version="2.0">
	<channel>
		<title><?php echo $rss_title; ?></title>
		<link><?php echo $rss_url; ?></link>
		<description><?php echo $rss_description; ?></description>
		<language>en-uk</language>
		<copyright>Copyright (C) Sean Patrick Payne 2009-<?php echo date('Y'); ?></copyright>
		<?php if($rss_items){ //If items... ?>
			<?php foreach($rss_items as $timestamp => $item){ ?>
				<item>
					<title><?php echo $item['title']; ?></title>
					<description><![CDATA[<?php echo $item['desc']; ?>]]></description>
					<link><?php echo $item['link']; ?></link>
					<pubDate><?php echo date("D, d M Y H:i:s O", $timestamp); ?></pubDate>
				</item>
			<?php } ?>
		<?php } ?>
	</channel>
</rss>