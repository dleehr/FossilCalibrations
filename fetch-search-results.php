<?php 
/*
 * Return markup with a series of search results (fossil calibrations), based on the POSTed query
 *
 * TODO: Support different response types: JSON? others?
 */

// open and load site variables
require('Site.conf');

// build search object from GET vars or other inputs (eg, a saved-query ID)
include('build-search-query.php'); 

// Quick test for non-empty string
function isNullOrEmptyString($str){
    return (!isset($str) || ($str == null) || trim($str)==='');
}

// test for active filter (by name)
function filterIsActive( $fullFilterName ) {
	global $search;
	if (in_array($fullFilterName, $search['HiddenFilters'])) return false;
	if (in_array($fullFilterName, $search['BlockedFilters'])) return false;
	return true;
}

$responseType = $search['ResponseType']; // HTML | JSON | ??

/* TODO: page or limit results, eg, 
 *	$search['ResultsRange'] = "1-10"
 *	$search['ResultsRange'] = "21-40"
 */

$searchResults = array();

/* TODO: If the requested sort doesn't make sense given the search type(s), apply 
 * some simple rules to override it.
 */
$forcedSort = null;	

// connect to mySQL server and select the Fossil Calibration database (using newer 'msqli' interface)
$mysqli = new mysqli($SITEINFO['servername'],$SITEINFO['UserName'], $SITEINFO['password'], 'FossilCalibration');



/*
 * Building top-level search logic here for now, possibly move this into stored procedure later..?
 */
$showDefaultSearch = true; // TODO: improve logic for this as more filters are implemented

// apply each included search type in turn, then weigh/consolidate its results?


// simple text search; compare to misc titles, text data, and taxa(?)
// TODO: if a name resolves to a taxon, should it become an implicit tip-taxa or clade search?
if (!empty($search['SimpleSearch'])) {
	$showDefaultSearch = false;

	// break text into tokens (split on commas or whitespace, but respected quoted phrases)
	// see http://fr2.php.net/manual/en/function.preg-split.php#92632
	$search_expression = $search['SimpleSearch'];  // eg,  "apple bear \"Tom Cruise\" or 'Mickey Mouse' another word";
	$searchTerms = preg_split("/[\s,]*\\\"([^\\\"]+)\\\"[\s,]*|" . "[\s,]*'([^']+)'[\s,]*|" . "[\s,]+/", $search_expression, 0, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
?><div class="search-details">SIMPLE SEARCH TERMS:<br/>
	<pre><? print_r( $searchTerms ); ?></pre></div><?

	/* TODO: IF a term resolves as a geological period, copy it to that filter?
		IF geological-time filter is not already being used
		IF geological-time filter is not already blocked
 	 */

	/* TODO: IF a term resolves to a taxon, copy it to tip-taxa?
		IF tip-taxa filter is not already being used
		IF tip-taxa filter is not already blocked
		Copy the FIRST TWO matching terms found to taxa A and B, ignore others
			$multitree_id_A = nameToMultitreeID($search['FilterByTipTaxa']['TaxonA']);
			$multitree_id_B = nameToMultitreeID($search['FilterByTipTaxa']['TaxonB']);
	 */

	/* Search for each term (keeping tally for relevance score) in:
	 *  > calibration node name
	 *  > its publication description (full reference)
	 *  > associated fossils (ID, taxon, collection?)

	 *  > geological time?
	 *  > implied tip-taxa search?
	 *  > implied clade search?

	 *  > phylogenetic publication (lcf.PhyloPub => publications)?
	 *  > fossil publication (f.FossilPub => publications)?
	 *  > fossil locality (f.LocalityID => localities)?
	 */
	$matching_calibration_ids = array();
	$termPosition = 0;
	foreach($searchTerms as $term) {
		$termPosition++;
		$query="SELECT c.CalibrationID FROM calibrations AS c
			JOIN publications AS p ON p.PublicationID = c.NodePub
			JOIN Link_CalibrationFossil AS lcf ON lcf.CalibrationID = c.CalibrationID
			JOIN fossils AS f ON f.FossilID = lcf.FossilID
			WHERE
				c.NodeName LIKE '%$term%' OR 
				c.MinAgeExplanation LIKE '%$term%' OR 
				c.MaxAgeExplanation LIKE '%$term%' OR 
				p.ShortName LIKE '%$term%' OR 
				p.FullReference LIKE '%$term%' OR 
				p.DOI LIKE '%$term%' OR 
				lcf.Species LIKE '%$term%' OR 
				lcf.PhyJustification LIKE '%$term%' OR 
				f.CollectionAcro LIKE '%$term%' OR 
				f.CollectionNumber LIKE '%$term%'
		";
?><div class="search-details">SIMPLE-SEARCH QUERY:<br/><? print_r($query) ?></div><?

		$result=mysqli_query($mysqli, $query) or die ('Error  in query: '.$query.'|'. mysqli_error($mysqli));	
		while(mysqli_more_results($mysqli)) {
		     mysqli_next_result($mysqli);
		}
?><div class="search-details">SIMPLE-SEARCH RESULT:<br/><? print_r($result) ?></div><?
		// TODO: sort/sift from all the results lists above
		while($row=mysqli_fetch_assoc($result)) {
			$matching_calibration_ids[] = $row['CalibrationID'];
		}

		if (count($matching_calibration_ids) > 0) {
			addCalibrations( $searchResults, $matching_calibration_ids, Array('relationship' => "MATCHES-TERM-$termPosition", 'relevance' => 1.0) );
		}
	}
}

// tip-taxon search, using one or two taxa...
if (filterIsActive('FilterByTipTaxa')) {
	if (empty($search['FilterByTipTaxa']['TaxonA']) && empty($search['FilterByTipTaxa']['TaxonB'])) {
		// no taxa specified, bail out now
		
	} else if (!empty($search['FilterByTipTaxa']['TaxonA']) && !empty($search['FilterByTipTaxa']['TaxonB'])) {
?><div class="search-details">2 TAXA SUBMITTED</div><?
		// both taxa were specified... 
		$showDefaultSearch = false;

		/* 
		 * Check for associated calibrations ("direct hits" and "near misses") based on related multitree IDs
		 */

		// resolve taxon multitree IDs
		$multitree_id_A = nameToMultitreeID($search['FilterByTipTaxa']['TaxonA']);
		$multitree_id_B = nameToMultitreeID($search['FilterByTipTaxa']['TaxonB']);
/*
?><h3>A: <?= $multitree_id_A ?></h3><?
?><h3>B: <?= $multitree_id_A ?></h3><?
*/

		// check MRCA (common ancestor)
		$multitree_id_MRCA = getMultitreeIDForMRCA( $multitree_id_A, $multitree_id_B );
?><div class="search-details">MRCA: <?= $multitree_id_MRCA ?> <? if (empty($multitree_id_MRCA)) { ?>EMPTY<? } ?> <? if ($multitree_id_MRCA == null) { ?>NULL<? } ?></div><?
		// NOTE that if no MRCA was found, we still pass a one-item array to addAssociatedCalibrations()
		addAssociatedCalibrations( $searchResults, Array($multitree_id_MRCA), Array('relationship' => 'MRCA', 'relevance' => 1.0) );
?><div class="search-details">Result count: <?= count($searchResults) ?></div><?

		// check director ancestors of A or B (includes the tip taxa)
		$multitree_id_ancestors_A = getAllMultitreeAncestors( $multitree_id_A );
?><div class="search-details">ANCESTORS-A: <?= implode(", ", $multitree_id_ancestors_A) ?></div><?
		addAssociatedCalibrations( $searchResults, $multitree_id_ancestors_A, Array('relationship' => 'ANCESTOR-A', 'relevance' => 0.5) );
?><div class="search-details">Result count: <?= count($searchResults) ?></div><?

		$multitree_id_ancestors_B = getAllMultitreeAncestors( $multitree_id_B );
?><div class="search-details">ANCESTORS-B: <?= implode(", ", $multitree_id_ancestors_B) ?></div><?
		addAssociatedCalibrations( $searchResults, $multitree_id_ancestors_B, Array('relationship' => 'ANCESTOR-B', 'relevance' => 0.5) );
?><div class="search-details">Result count: <?= count($searchResults) ?></div><?

		// TODO: check all within clade of MRCA
		// addAssociatedCalibrations( $searchResults, $multitree_id_clade_members, Array('relationship' => 'MRCA-CLADE', 'relevance' => 0.25) );

		// TODO: check all neighbors of MRCA
		// addAssociatedCalibrations( $searchResults, $multitree_id_mrca_neighbors, Array('relationship' => 'MRCA-NEIGHBOR', 'relevance' => 0.1) );

		// TODO: check all neighbors of direct ancestors of A or B
		// addAssociatedCalibrations( $searchResults, $multitree_id_ancestor_neighbors, Array('relationship' => 'ANCESTOR-NEIGHBOR', 'relevance' => 0.1) );
	} else {
?><div class="search-details">1 TAXON SUBMITTED</div><?
		// just one taxon was specified
		$showDefaultSearch = false;
		$specifiedTaxon = empty($search['FilterByTipTaxa']['TaxonA']) ? 'B' : 'A'; 

		/* 
		 * Check for associated calibrations ("direct hits" and "near misses") based on related multitree IDs
		 */

		// resolve taxon multitree ID
		$multitree_id = nameToMultitreeID($search['FilterByTipTaxa']['Taxon'.$specifiedTaxon]);

		// check its direct ancestors (includes the tip taxon)
		$multitree_id_ancestors = getAllMultitreeAncestors( $multitree_id );
?><div class="search-details">ANCESTORS-<?= $specifiedTaxon ?>: <?= implode(", ", $multitree_id_ancestors) ?></div><?
		addAssociatedCalibrations( $searchResults, $multitree_id_ancestors, Array('relationship' => ('ANCESTOR-'.$specifiedTaxon), 'relevance' => 1.0) );

		// TODO: check all neighbors of direct ancestors
		// addAssociatedCalibrations( $searchResults, $multitree_id_ancestor_neighbors, Array('relationship' => 'ANCESTOR_NEIGHBOR', 'relevance' => 0.2) );
	}
}

// search within a named clade
if (filterIsActive('FilterByClade')) {
	if (empty($search['FilterByClade'])) {
		// no clade specified, bail out now
	} else {
?><div class="search-details">CLADE SUBMITTED</div><?
		// search within this clade
		$showDefaultSearch = false;

		/* 
		 * Check for associated calibrations ("direct hits" and "near misses") based on related multitree IDs
		 * 
		 * REMINDER: Prior versions of this file used a different approach, checking all clade members(!). This was
		 * painfully slow, esp. for large clades like Eukaryota, but it might contain some lessons if the logic above
		 * starts to crawl with many calibrations added.
                 */

		// resolve clade multitree ID
		$clade_root_source_id = nameToSourceNodeInfo($search['FilterByClade']);
		$clade_root_source_id = $clade_root_source_id['taxonid'];

		// test all eligible calibrations, backtracking from node IDs (should still be faster than testing every Eukaryote!)
		$test_taxon_ids = array();
		// test ALL nodes in all custom trees
		$query="SELECT node_id, tree_id from FCD_nodes;";
?><div class="search-details">SEARCH FOR ALL CUSTOM-TREE NODES (SOURCE IDS):<br/><?= $query ?></div><?
		$result=mysqli_query($mysqli, $query) or die ('Error  in query: '.$query.'|'. mysqli_error($mysqli));	
		while($row=mysqli_fetch_assoc($result)) {
			$test_taxon_ids[] = $row;
		}
?><div class="search-details">Checking <?= count($test_taxon_ids) ?> custom-tree nodes for clade membership...</div><?
/*

		       ";
		$query="SELECT c.CalibrationID FROM calibrations AS c
			JOIN publications AS p ON p.PublicationID = c.NodePub
			JOIN Link_CalibrationFossil AS lcf ON lcf.CalibrationID = c.CalibrationID
			JOIN fossils AS f ON f.FossilID = lcf.FossilID
			WHERE
				c.NodeName LIKE '%$term%' OR 
				c.MinAgeExplanation LIKE '%$term%' OR 
				c.MaxAgeExplanation LIKE '%$term%' OR 
				p.ShortName LIKE '%$term%' OR 
				p.FullReference LIKE '%$term%' OR 
				p.DOI LIKE '%$term%' OR 
				lcf.Species LIKE '%$term%' OR 
				lcf.PhyJustification LIKE '%$term%' OR 
				f.CollectionAcro LIKE '%$term%' OR 
				f.CollectionNumber LIKE '%$term%'
		";
*/

		// if any node comes 
		$matching_tree_ids = array();  // once a tree has matched, stop checking it!
		$matching_calibration_ids = array();
		foreach($test_taxon_ids as $taxon_ids) {
			$test_node_id = $taxon_ids['node_id'];
			$test_tree_id = $taxon_ids['tree_id'];
			if (!in_array($test_tree_id, $matching_tree_ids)) {
?><div class="search-details">Testing node <?= $test_node_id ?> in tree <?= $test_tree_id ?></div><?
				$query="CALL isMemberOfClade('NCBI', '$clade_root_source_id', CONCAT('FCD-', '$test_tree_id'), '$test_node_id', @isInClade);";
?><div class="search-details"><pre><?= $query ?></pre></div><?
				$result=mysqli_query($mysqli, $query) or die ('Error  in query: '.$query.'|'. mysqli_error($mysqli));	
				while(mysqli_more_results($mysqli)) {
				     mysqli_next_result($mysqli);
				     mysqli_store_result($mysqli);
				}
				$query='SELECT @isInClade';
				$result=mysqli_query($mysqli, $query) or die ('Error  in query: '.$query.'|'. mysqli_error($mysqli));	
				$foundInClade = mysqli_fetch_assoc($result);
				$foundInClade = $foundInClade['@isInClade'];
?><div class="search-details">Result for node <?= $test_node_id ?>: <? print_r($foundInClade); ?></div><?
				if ($foundInClade) {
					$matching_tree_ids[] = $test_tree_id;
					// TODO: add this calibration(!)?
?><div class="search-details">First match on node <?= $test_node_id ?>, tree <?= $test_tree_id ?></div><?
				}
			}
		}

		if (count($matching_tree_ids) > 0) {
			$query="SELECT calibration_id FROM FCD_trees WHERE tree_id IN (". implode(",", $matching_tree_ids) .");";
			$result=mysqli_query($mysqli, $query) or die ('Error  in query: '.$query.'|'. mysqli_error($mysqli));	
			while($row=mysqli_fetch_assoc($result)) {
				$matching_calibration_ids[] = $row['calibration_id'];
			}
			if (count($matching_calibration_ids) > 0) {
				addCalibrations( $searchResults, $matching_calibration_ids, Array('relationship' => 'CLADE-MEMBER', 'relevance' => 1.0) );
			}
		}

	}
}


// filtering results by minimum and/or maximum age
if (filterIsActive('FilterByAge')) {
	if (empty($search['FilterByAge']['MinAge']) && empty($search['FilterByAge']['MaxAge'])) {
		// no ages specified, bail out now
		
	} else if (!empty($search['FilterByAge']['MinAge']) && !empty($search['FilterByAge']['MaxAge'])) {
?><div class="search-details">MIN AND MAX AGES SUBMITTED</div><?
		// search within this clade
		$showDefaultSearch = false;

		/* 
		 * Check for calibrations within the specified age ranage. NOTE that we should check
		 * both age bounds, as a sanity check in case only one was entered ("blank" ranges will appear as 0).
		 */
		$matching_calibration_ids = array();
		$query="SELECT CalibrationID FROM calibrations WHERE 
			       MinAge >= '". $search['FilterByAge']['MinAge'] ."' AND MaxAge >= '". $search['FilterByAge']['MinAge'] ."'
			   AND MinAge <= '". $search['FilterByAge']['MaxAge'] ."' AND MaxAge <= '". $search['FilterByAge']['MaxAge'] ."'
		";
?><div class="search-details"><?= $query ?></div><?
		$result=mysqli_query($mysqli, $query) or die ('Error  in query: '.$query.'|'. mysqli_error($mysqli));	

		// TODO: sort/sift from all the results lists above
		while($row=mysqli_fetch_assoc($result)) {
			$matching_calibration_ids[] = $row['CalibrationID'];
		}
		if (count($matching_calibration_ids) > 0) {
			addCalibrations( $searchResults, $matching_calibration_ids, Array('relationship' => 'MATCHES-AGE', 'relevance' => 1.0) );
		}

	} else {
		// just one age was specified
		$showDefaultSearch = false;
		$specifiedAge = empty($search['FilterByAge']['MinAge']) ? 'MaxAge' : 'MinAge'; 
?><div class="search-details">1 AGE SUBMITTED (<?= $specifiedAge ?>)</div><?

		/* 
		 * Check for calibrations that are newer (or older) than the age specified. NOTE that we should check
		 * both age bounds, as a sanity check in case only one was entered ("blank" ranges will appear as 0).
		 */
		$matching_calibration_ids = array();
		if ($specifiedAge == 'MinAge') {
			$query="SELECT CalibrationID FROM calibrations WHERE MinAge >= '". $search['FilterByAge']['MinAge'] ."' AND MaxAge >= '". $search['FilterByAge']['MinAge'] ."'";
		} else {
			$query="SELECT CalibrationID FROM calibrations WHERE MinAge <= '". $search['FilterByAge']['MaxAge'] ."' AND MaxAge <= '". $search['FilterByAge']['MaxAge'] ."'";
		}
?><div class="search-details"><?= $query ?></div><?
		$result=mysqli_query($mysqli, $query) or die ('Error  in query: '.$query.'|'. mysqli_error($mysqli));	

		// TODO: sort/sift from all the results lists above
		while($row=mysqli_fetch_assoc($result)) {
			$matching_calibration_ids[] = $row['CalibrationID'];
		}
		if (count($matching_calibration_ids) > 0) {
			addCalibrations( $searchResults, $matching_calibration_ids, Array('relationship' => 'MATCHES-AGE', 'relevance' => 1.0) );
		}
	}
}


// filtering results by geological time
if (filterIsActive('FilterByGeologicalTime')) {
	if (empty($search['FilterByGeologicalTime'])) {
		// no time specified, bail out now
	} else {
?><div class="search-details">GEOLOGICAL TIME SUBMITTED</div><?
		// search within this period
		$showDefaultSearch = false;

		/* 
		 * Check for calibrations from the specified time
		 */
		$matching_calibration_ids = array();
		$query="SELECT CalibrationID FROM Link_CalibrationFossil WHERE FossilID IN 
			    (SELECT FossilID FROM fossils WHERE LocalityID IN
				(SELECT LocalityID FROM localities WHERE GeolTime = 
				    (SELECT GeolTimeID FROM geoltime WHERE Age = '". $search['FilterByGeologicalTime'] ."')));";
?><div class="search-details"><?= $query ?></div><?
		$result=mysqli_query($mysqli, $query) or die ('Error  in query: '.$query.'|'. mysqli_error($mysqli));	

		// TODO: sort/sift from all the results lists above
		while($row=mysqli_fetch_assoc($result)) {
			$matching_calibration_ids[] = $row['CalibrationID'];
		}
		if (count($matching_calibration_ids) > 0) {
			addCalibrations( $searchResults, $matching_calibration_ids, Array('relationship' => 'MATCHES-GEOTIME', 'relevance' => 1.0) );
		}
	}
}


// IF no search tools were active and loaded, return the n results most recently added
if ($showDefaultSearch) {
?><div class="search-details">SHOWING DEFAULT SEARCH</div><?
	$query='SELECT DISTINCT C . *, img.image, img.caption AS image_caption
		FROM (
			SELECT CF.CalibrationID, V . *
			FROM View_Fossils V
			JOIN Link_CalibrationFossil CF ON CF.FossilID = V.FossilID
		) AS J
		JOIN View_Calibrations C ON J.CalibrationID = C.CalibrationID
		LEFT JOIN publication_images img ON img.PublicationID = C.PublicationID
		ORDER BY DateCreated DESC
		LIMIT 10';
	$recently_added_list=mysqli_query($mysqli, $query) or die ('Error  in query: '.$query.'|'. mysqli_error($mysqli));	

	// TODO: sort/sift from all the results lists above
	while($row=mysqli_fetch_assoc($recently_added_list)) {
		$searchResults[] = $row;
	}
}


// return these results in the requested format
if ($responseType == 'JSON') {
	echo json_encode($searchResults);
	return;
}

/* ?><h3>FINAL: <?= count($searchResults) ?> results</h3><? */

// still here? then build HTML markup for the results
if (count($searchResults) == 0) {
	?><p style="font-style: italic;">No matching calibrations found. To see more results, simplify your search by removing text above or hiding filters.</p><?
} else {
	foreach($searchResults as $result) 
	{ 
		// print hidden diagnostic info
		?>
		<pre class="search-details" style="color: green;">
		<? print_r($result) ?>
		</pre>
		<?

		$calibrationDisplayURL = "/Show_Calibration.php?CalibrationID=". $result['CalibrationID'];

		/* TODO: Preset these "qualifiers" in consolidated results */
		$relationship = isset($result['relationship']) ? $result['relationship'] : null; 
		$relevance = isset($result['relevance']) ? $result['relevance'] : null; 
		$minAge = floatval($result['MinAge']);
		$maxAge = floatval($result['MaxAge']);
		// PHP's floats are imprecise, so we should define what constitutes equality here
		$epsilon = 0.0001;
?>
<div class="search-result">
	<table class="qualifiers" border="0">
		<tr>
			<td width="30" title="Cladistic relatioship to entered taxa">
			<? if ($relationship) { ?>
				<?= $relationship ?>
			<? } else { ?>
				&nbsp;	
			<? } ?>
			</td>
			<td width="*" title="Relevance based on all filters used">
			<? if ($relevance) { ?>
				<?= intval($relevance * 100) ?>% match
			<? } else { ?>
				&nbsp;	
			<? } ?>
			</td>
			<td width="100" title="Calibrated age range">
			<? if(abs($minAge-$maxAge) < $epsilon) { ?>
				<?= $minAge ?> Ma
			<? } else if ($minAge && $maxAge) { ?>
				<?= $minAge ?>&ndash;<?= $maxAge ?> Ma
			<? } else if ($minAge) { ?>
				&gt; <?= $minAge ?> Ma
			<? } else if ($maxAge){ ?>
				&lt; <?= $maxAge ?> Ma
			<? } else { ?>
				&nbsp;	
			<? } ?>
			</td>
			<td width="120" title="Date entered into database">
				Added <?= date("M d, Y", strtotime($result['DateCreated'])) ?>
			</td>
		</tr>
	</table>
	<a class="calibration-link" href="<?= $calibrationDisplayURL ?>>
		<span class="name"><?= $result['NodeName'] ?></span>
		<span class="citation">&ndash; from <?= $result['ShortName'] ?></span>
	</a>
	<br/>
	<? // if there's an image mapped to this publication, show it
	   if ($result['image']) { ?>
	<div class="optional-thumbnail" style="height: 60px;">
	    <a href="<?= $calibrationDisplayURL ?>">
		<img src="/publication_image.php?id=<?= $result['PublicationID'] ?>" style="height: 60px;"
		alt="<?= $result['image_caption'] ?>" title="<?= $result['image_caption'] ?>"
		/></a>
	</div>
	<? } ?>
	<div class="details">
		<?= $result['FullReference'] ?>
		&nbsp;
		<a class="more" style="display: block; text-align: right;" href="<?= $calibrationDisplayURL ?>">more &raquo;</a>
	</div>
</div>
    <? }
}

if (count($searchResults) > 10)  // TODO?
{ ?>
<div style="text-align: right; border-top: 1px solid #ddd; font-size: 0.9em; padding-top: 2px;">
	<a href="#">Show more results like this</a>
</div>
<? }

return;
?>

