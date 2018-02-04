<?php
/**
 * @package		Google Analytics Dashboard - Module for Joomla!
 * @author		Alin Marcu - https://deconf.com
 * @copyright	Copyright (c) 2010 - 2012 DeConf.com
 * @license		GNU/GPL license: http://www.gnu.org/licenses/gpl-2.0.html
 */
// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

class modGoogleAnalyticsDashboardHelper {

	public static function store_token( $token ) {
		$db = JFactory::getDBO();
		try {
			$query = "UPDATE #__ga_dash SET token='$token' WHERE id=1;";
			$db->setQuery( $query );
			$result = $db->query();
		} catch ( exception $e ) {
			$query = "CREATE TABLE IF NOT EXISTS #__ga_dash (id INT NOT NULL , token VARCHAR(1000) NOT NULL);";
			$db->setQuery( $query );
			$result = $db->query();

			$query = "INSERT INTO #__ga_dash (id, token) VALUES (1, '$token');";
			$db->setQuery( $query );
			$result = $db->query();
		}
	}

	public static function get_token() {
		$db = JFactory::getDBO();
		try {
			$query = "SELECT token FROM #__ga_dash";
			$db->setQuery( $query );
			$result = $db->loadResult();
		} catch ( exception $e ) {
			return;
		}
		return $result;
	}

	public static function ga_generate_code( $params ) {
		require_once dirname( __FILE__ ) . '/functions.php';

		$scriptUri = JURI::current();

		if ( isset( $_REQUEST['ga_dash_reset_token'] ) ) {
			$db = JFactory::getDBO();
			$query = "DROP TABLE IF EXISTS #__ga_dash";
			$db->setQuery( $query );
			$result = $db->loadResult();
			ga_dash_clear_cache();
			header( "Location: " . $scriptUri );
			return;
		}

		if ( isset( $_REQUEST['ga_dash_clear_cache'] ) ) {
			ga_dash_clear_cache();
			header( "Location: " . $scriptUri );
			return;
		}

		require 'vendor/autoload.php';

		$client = new Google_Client();
		$client->setAccessType( 'offline' );
		$client->setScopes( 'https://www.googleapis.com/auth/analytics.readonly' );
		$client->setApplicationName( 'Google Analytics Dashboard' );
		$client->setRedirectUri( 'urn:ietf:wg:oauth:2.0:oob' );

		if ( $params->get( 'ga_api_key' ) and $params->get( 'ga_client_id' ) and $params->get( 'ga_client_secret' ) ) {
			$client->setClientId( $params->get( 'ga_client_id' ) );
			$client->setClientSecret( $params->get( 'ga_client_secret' ) );
			$client->setDeveloperKey( $params->get( 'ga_api_key' ) ); // API key
		} else {
			$client->setClientId( '866889662555.apps.googleusercontent.com' );
			$client->setClientSecret( '0fqZWhlMsXHnrYG_8V9sndRi' );
			$client->setDeveloperKey( 'AIzaSyBTwsIsXFHIpa8RhpziT8cbzg7iJ-bpZFo' );
		}

		$service = new Google_Service_Analytics( $client );

		if ( self::get_token() ) { // extract token from session and configure client
			$token = self::get_token();
			$client->setAccessToken( $token );
		}

		if ( ! $client->getAccessToken() ) { // auth call to google

			$authUrl = $client->createAuthUrl();

			if ( ! isset( $_REQUEST['ga_dash_authorize'] ) ) {
				return '<div style="padding:20px;">' . JText::_( 'GAD_CODE_ACTION_D' ) . ' <a href="' . $authUrl . '" target="_blank">' . JText::_( 'GAD_CODE_ACTION' ) . '</a><br /><br />' . '<form name="ga_dash_input" action="' . $scriptUri . '" method="get">
						<p><b>' . JText::_( 'GAD_ACCESS_CODE' ) . ' </b><input type="text" name="ga_dash_code" value="" size="61"></p>
						<input type="submit" class="button button-primary" name="ga_dash_authorize" value="' . JText::_( 'GAD_SAVE_CODE' ) . '"/>
					</form>
				</div>';
			} else {
				if ( $_REQUEST['ga_dash_code'] ) {
					$client->authenticate( $_REQUEST['ga_dash_code'] );
					self::store_token( $client->getAccessToken() );
				} else {
					header( "Location: " . $scriptUri );
				}
			}
		}

		$projectId = ga_dash_get_profiles( $service, $client, $params );

		if ( ! $projectId ) {
			ga_dash_clear_cache();
			return "<br />&nbsp;&nbsp;Error: " . JText::_( 'GAD_INVALID' ) . " - <a href='https://deconf.com/google-analytics-dashboard-joomla/' target='_blank'>" . JText::_( 'GAD_HELP' ) . "</a><br /><br />";
		}

		if ( isset( $_REQUEST['gaquery'] ) ) {
			$gaquery = $_REQUEST['gaquery'];
		} else {
			$gaquery = "sessions";
		}

		if ( isset( $_REQUEST['gaperiod'] ) ) {
			$gaperiod = $_REQUEST['gaperiod'];
		} else {
			$gaperiod = "30daysAgo";
		}

		switch ( $gaperiod ) {

			case 'today' :
				$from = 'today';
				$to = 'today';
				$showevery = 5;
				break;

			case 'yesterday' :
				$from = 'yesterday';
				$to = 'yesterday';
				$showevery = 5;
				break;

			case '7daysAgo' :
				$from = '7daysAgo';
				$to = 'yesterday';
				$showevery = 3;
				break;

			case '14daysAgo' :
				$from = '14daysAgo';
				$to = 'yesterday';
				$showevery = 4;
				break;

			default :
				$from = '30daysAgo';
				$to = 'yesterday';
				$showevery = 6;
				break;
		}

		switch ( $gaquery ) {

			case 'users' :
				$title = JText::_( 'GAD_VISITORS' );
				break;

			case 'pageviews' :
				$title = JText::_( 'GAD_PAGE_VIEWS' );
				break;

			case 'visitBounceRate' :
				$title = JText::_( 'GAD_BOUNCE_RATE' );
				break;

			case 'organicSearches' :
				$title = JText::_( 'GAD_ORGANIC_SEARCHES' );
				break;

			default :
				$title = JText::_( 'GAD_VISITS' );
		}

		$metrics = 'ga:' . $gaquery;
		$dimensions = 'ga:year,ga:month,ga:day';

		if ( $gaperiod == "today" or $gaperiod == "yesterday" ) {
			$dimensions = 'ga:hour';
		} else {
			$dimensions = 'ga:year,ga:month,ga:day';
		}

		try {
			$serial = 'gadash_qr2' . str_replace( array( 'ga:', ',', '-', date( 'Y' ) ), "", $projectId . $from . $to . $metrics );
			$transient = ga_dash_cache_get( $serial );
			if ( ! $transient ) {
				$data = $service->data_ga->get( 'ga:' . $projectId, $from, $to, $metrics, array( 'dimensions' => $dimensions ) );
				ga_dash_cache_set( $serial, $data, $params->get( 'ga_dash_cache' ) );
			} else {
				$data = $transient;
			}
		} catch ( Exception $e ) {
			return "<br />&nbsp;&nbsp;Error: " . $e->getMessage() . " - <a href='https://deconf.com/google-analytics-dashboard-joomla/' target='_blank'>" . JText::_( 'GAD_HELP' ) . "</a><br /><br />";
		}
		$gadash_data = "";
		for ( $i = 0; $i < $data['totalResults']; $i++ ) {
			if ( $gaperiod == "today" or $gaperiod == "yesterday" ) {
				$gadash_data .= "['" . $data['rows'][$i][0] . ":00'," . round( $data['rows'][$i][1], 2 ) . "],";
			} else {
				$gadash_data .= "['" . $data['rows'][$i][0] . "-" . $data['rows'][$i][1] . "-" . $data['rows'][$i][2] . "'," . round( $data['rows'][$i][3], 2 ) . "],";
			}
		}

		$metrics = 'ga:sessions,ga:users,ga:pageviews,ga:visitBounceRate,ga:organicSearches,ga:sessionDuration';
		$dimensions = 'ga:year';
		try {
			$serial = 'gadash_qr3' . str_replace( array( 'ga:', ',', '-', date( 'Y' ) ), "", $projectId . $from . $to );
			$transient = ga_dash_cache_get( $serial );
			if ( ! $transient ) {
				$data = $service->data_ga->get( 'ga:' . $projectId, $from, $to, $metrics, array( 'dimensions' => $dimensions ) );
				ga_dash_cache_set( $serial, $data, $params->get( 'ga_dash_cache' ) );
			} else {
				$data = $transient;
			}
		} catch ( Exception $e ) {
			return "<br />&nbsp;&nbsp;Error: " . $e->getMessage() . " - <a href='https://deconf.com/google-analytics-dashboard-joomla/' target='_blank'>" . JText::_( 'GAD_HELP' ) . "</a><br /><br />";
		}

		$code = '<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script type="text/javascript">
	  google.charts.load("current", {"packages":["corechart","table","geochart"]});
      google.charts.setOnLoadCallback(ga_dash_callback);

	  function ga_dash_callback(){
			ga_dash_drawstats();
			if(typeof ga_dash_drawmap == "function"){
				ga_dash_drawmap();
			}
			if(typeof ga_dash_drawpgd == "function"){
				ga_dash_drawpgd();
			}
			if(typeof ga_dash_drawrd == "function"){
				ga_dash_drawrd();
			}
			if(typeof ga_dash_drawsd == "function"){
				ga_dash_drawsd();
			}
			if(typeof ga_dash_drawtraffic == "function"){
				ga_dash_drawtraffic();
			}
	  }

      function ga_dash_drawstats() {
        var data = google.visualization.arrayToDataTable([' . "
          ['" . JText::_( 'GAD_DATE' ) . "', '" . $title . "']," . $gadash_data . "
        ]);

        var options = {
		  legend: {position: 'none'},
		  " . ( $params->get( 'ga_chart_theme' ) == 1 ? "colors:['#3366CC','#2B56AD']," : "colors:['darkgray','lightgray']," ) . "
		  pointSize: 3,
          title: '" . $title . "',
		  chartArea: {width: '95%'},
          hAxis: { title: '" . JText::_( 'GAD_DATE' ) . "',  titleTextStyle: {color: 'black'}, showTextEvery: " . $showevery . "},
		  vAxis: { textPosition: 'none', minValue: 0}
		};

        var chart = new google.visualization.AreaChart(document.getElementById('gadash_div'));
		chart.draw(data, options);

      }";

		if ( $params->get( 'ga_enable_map' ) ) {
			$ga_dash_sessions_country = ga_dash_sessions_country( $service, $projectId, $from, $to, $params );
			if ( $ga_dash_sessions_country ) {
				$code .= '

			function ga_dash_drawmap() {
			var data = google.visualization.arrayToDataTable([' . "
			  ['Country', 'Sessions']," . $ga_dash_sessions_country . "
			]);

			var options = {
				colors: ['white', '" . ( $params->get( 'ga_chart_theme' ) == 1 ? "blue" : "#2D2D2D" ) . "']
			};

			var chart = new google.visualization.GeoChart(document.getElementById('ga_dash_mapdata'));
			chart.draw(data, options);

		  }";
			}
		}

		if ( $params->get( 'ga_enable_traffic' ) ) {
			$ga_dash_traffic_sources = ga_dash_traffic_sources( $service, $projectId, $from, $to, $params );
			$ga_dash_new_return = ga_dash_new_return( $service, $projectId, $from, $to, $params );
			if ( $ga_dash_traffic_sources and $ga_dash_new_return ) {
				$code .= '

			function ga_dash_drawtraffic() {
			var data = google.visualization.arrayToDataTable([' . "
			  ['Source', 'Sessions']," . $ga_dash_traffic_sources . '
			]);

			var datanvr = google.visualization.arrayToDataTable([' . "
			  ['Type', 'Sessions']," . $ga_dash_new_return . "
			]);

			var chart = new google.visualization.PieChart(document.getElementById('ga_dash_trafficdata'));
			chart.draw(data, {
				is3D: false,
				tooltipText: 'percentage',
				legend: 'none',
				title: 'Traffic Sources',
				colors: ['" . ( $params->get( 'ga_chart_theme' ) == 1 ? "#001BB5" : "gray" ) . "', '" . ( $params->get( 'ga_chart_theme' ) == 1 ? "#2D41AF" : "#B7B7B7" ) . "', '" . ( $params->get( 'ga_chart_theme' ) == 1 ? "#00137F" : "#2D2D2D" ) . "', '" . ( $params->get( 'ga_chart_theme' ) == 1 ? "blue" : "#A0A0A0" ) . "', '" . ( $params->get( 'ga_chart_theme' ) == 1 ? "#425AE5" : "#707070" ) . "']
			});

			var gadash = new google.visualization.PieChart(document.getElementById('ga_dash_nvrdata'));
			gadash.draw(datanvr,  {
				is3D: false,
				tooltipText: 'percentage',
				legend: 'none',
				title: 'New vs. Returning',
				colors: ['" . ( $params->get( 'ga_chart_theme' ) == 1 ? "#001BB5" : "gray" ) . "', '" . ( $params->get( 'ga_chart_theme' ) == 1 ? "#2D41AF" : "#B7B7B7" ) . "', '" . ( $params->get( 'ga_chart_theme' ) == 1 ? "#00137F" : "#2D2D2D" ) . "', '" . ( $params->get( 'ga_chart_theme' ) == 1 ? "blue" : "#A0A0A0" ) . "', '" . ( $params->get( 'ga_chart_theme' ) == 1 ? "#425AE5" : "#707070" ) . "']
			});

		  }";
			}
		}

		if ( $params->get( 'ga_enable_pgd' ) ) {
			$ga_dash_top_pages = ga_dash_top_pages( $service, $projectId, $from, $to, $params );
			if ( $ga_dash_top_pages ) {
				$code .= '

			function ga_dash_drawpgd() {
			var data = google.visualization.arrayToDataTable([' . "
			  ['Top Pages', 'Sessions']," . $ga_dash_top_pages . "
			]);

			var options = {
				page: 'enable',
				pageSize: 6,
				width: '100%'
			};

			var chart = new google.visualization.Table(document.getElementById('ga_dash_pgddata'));
			chart.draw(data, options);

		  }";
			}
		}

		if ( $params->get( 'ga_enable_rd' ) ) {
			$ga_dash_top_referrers = ga_dash_top_referrers( $service, $projectId, $from, $to, $params );
			if ( $ga_dash_top_referrers ) {
				$code .= '

			function ga_dash_drawrd() {
			var datar = google.visualization.arrayToDataTable([' . "
			  ['Top Referrers', 'Sessions']," . $ga_dash_top_referrers . "
			]);

			var options = {
				page: 'enable',
				pageSize: 6,
				width: '100%'
			};

			var chart = new google.visualization.Table(document.getElementById('ga_dash_rdata'));
			chart.draw(datar, options);

		  }";
			}
		}

		if ( $params->get( 'ga_enable_sd' ) ) {
			$ga_dash_top_searches = ga_dash_top_searches( $service, $projectId, $from, $to, $params );
			if ( $ga_dash_top_searches ) {
				$code .= '

			function ga_dash_drawsd() {

			var datas = google.visualization.arrayToDataTable([' . "
			  ['Top Searches', 'Sessions']," . $ga_dash_top_searches . "
			]);

			var options = {
				page: 'enable',
				pageSize: 6,
				width: '100%'
			};

			var chart = new google.visualization.Table(document.getElementById('ga_dash_sdata'));
			chart.draw(datas, options);

		  }";
			}
		}

		$code .= "

	jQuery(window).resize(function(){
		if(typeof ga_dash_drawstats == 'function'){
			ga_dash_drawstats();
		}
		if(typeof ga_dash_drawmap == 'function'){
			ga_dash_drawmap();
		}
		if(typeof ga_dash_drawpgd == 'function'){
			ga_dash_drawpgd();
		}
		if(typeof ga_dash_drawrd == 'function'){
			ga_dash_drawrd();
		}
		if(typeof ga_dash_drawsd == 'function'){
			ga_dash_drawsd();
		}
		if(typeof ga_dash_drawtraffic == 'function'){
			ga_dash_drawtraffic();
		}
	});

	</script>" . '
	<div id="ga-dash">
		<div class="btn-toolbar">
			<div class="btn-wrapper"><button class="btn btn-small' . ( $gaperiod == "today" ? ' active' : '' ) . '" onClick="window.location=\'?gaperiod=today&gaquery=' . $gaquery . '\'">' . JText::_( 'GAD_TODAY' ) . '</button></div>
			<div class="btn-wrapper"><button class="btn btn-small' . ( $gaperiod == "yesterday" ? ' active' : '' ) . '" onClick="window.location=\'?gaperiod=yesterday&gaquery=' . $gaquery . '\'">' . JText::_( 'GAD_YESTERDAY' ) . '</button></div>
			<div class="btn-wrapper"><button class="btn btn-small' . ( $gaperiod == "7daysAgo" ? ' active' : '' ) . '" onClick="window.location=\'?gaperiod=7daysAgo&gaquery=' . $gaquery . '\'">' . JText::_( 'GAD_7DAYSAGO' ) . '</button></div>
			<div class="btn-wrapper"><button class="btn btn-small' . ( $gaperiod == "14daysAgo" ? ' active' : '' ) . '" onClick="window.location=\'?gaperiod=14daysAgo&gaquery=' . $gaquery . '\'">' . JText::_( 'GAD_14DAYSAGO' ) . '</button></div>
			<div class="btn-wrapper"><button class="btn btn-small' . ( $gaperiod == "30daysAgo" ? ' active' : '' ) . '" onClick="window.location=\'?gaperiod=30daysAgo&gaquery=' . $gaquery . '\'">' . JText::_( 'GAD_30DAYSAGO' ) . '</button></div>
			<br /><br />
		</div>

		<div id="gadash_div" style="height:350px;"></div>
			<br />
			<table class="gatable" cellpadding="4" width="100%" align="center">
			<tr>
			<td width="24%">' . JText::_( 'GAD_VISITS' ) . ':</td>
			<td width="12%" class="gavalue"><a href="?gaquery=sessions&gaperiod=' . $gaperiod . '" class="gatable">' . $data['rows'][0][1] . '</td>
			<td width="24%">' . JText::_( 'GAD_VISITORS' ) . ':</td>
			<td width="12%" class="gavalue"><a href="?gaquery=users&gaperiod=' . $gaperiod . '" class="gatable">' . $data['rows'][0][2] . '</a></td>
			<td width="24%">' . JText::_( 'GAD_PAGE_VIEWS' ) . ':</td>
			<td width="12%" class="gavalue"><a href="?gaquery=pageviews&gaperiod=' . $gaperiod . '" class="gatable">' . $data['rows'][0][3] . '</a></td>
			</tr>
			<tr>
			<td>' . JText::_( 'GAD_BOUNCE_RATE' ) . ':</td>
			<td class="gavalue"><a href="?gaquery=visitBounceRate&gaperiod=' . $gaperiod . '" class="gatable">' . round( $data['rows'][0][4], 2 ) . '%</a></td>
			<td>' . JText::_( 'GAD_ORGANIC_SEARCHES' ) . ':</td>
			<td class="gavalue"><a href="?gaquery=organicSearches&gaperiod=' . $gaperiod . '" class="gatable">' . $data['rows'][0][5] . '</a></td>
			<td>' . JText::_( 'GAD_PAGES_VISIT' ) . ':</td>
			<td class="gavalue"><a href="#" class="gatable">' . ( ( $data['rows'][0][1] ) ? round( $data['rows'][0][3] / $data['rows'][0][1], 2 ) : '0' ) . '</a></td>
			</tr>
			</table>
	</div><br/>';
		if ( $params->get( 'ga_enable_map' ) ) {
			$code .= '<div id="ga_dash_mapdata"></div><br/>';
		}
		if ( $params->get( 'ga_enable_traffic' ) ) {
			$code .= '<div style="width:100%;"><div id="ga_dash_trafficdata" style="width:50%;float:left;"></div><div id="ga_dash_nvrdata" style="width:50%;float:left;"></div></div><div style="clear:both;"></div><br/>';
		}
		if ( $params->get( 'ga_enable_rd' ) ) {
			$code .= '<div id="ga_dash_rdata" style="text-align:left;"></div><br/>';
		}
		if ( $params->get( 'ga_enable_sd' ) ) {
			$code .= '<div id="ga_dash_sdata" style="text-align:left;"></div><br/>';
		}
		if ( $params->get( 'ga_enable_pgd' ) ) {
			$code .= '<div id="ga_dash_pgddata" style="text-align:left;"></div><br/>';
		}
		return $code;
	}
}
?>
