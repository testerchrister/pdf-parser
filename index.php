<?php
// if you are using composer, just use this
include 'vendor/autoload.php';
use Gufy\PdfToHtml\Config;
// change pdftohtml bin location
Config::set('pdftohtml.bin', 'C:/poppler-0.51/bin/pdftohtml.exe');

// change pdfinfo bin location
Config::set('pdfinfo.bin', 'C:/poppler-0.51/bin/pdfinfo.exe');
// initiate
$pdf = new Gufy\PdfToHtml\Pdf('uploads/GeneratePdfReport.pdf');

// convert to html and return it as [Dom Object](https://github.com/paquettg/php-html-parser)
$total_pages = $pdf->getPages();

$pase_report_info = array();
$info_type = array();
for ($page = 1; $page < 3; $page++) {
	$html = $pdf->html($page);
	if ($html) {
		$dom = new DOMDocument();
		@$dom->loadHTML($html);
		$dom->preserveWhiteSpace = false;	
	} else {
		echo "Something goes wrong!";
		die();
	}
	
	switch($page) {
		case 1:
			$pase_report_info['basic_info'] = parseBasicInfo($dom);
			break;
		case 2:
			$info_type = getPageInfoType($dom);
			break;			
	}
}

if (is_array($info_type)  && count($info_type)) {
	$doc_personal_info = array();
	$doc_report_summary = array();
	foreach ($info_type as $page_info) {
		//Parse Personal information section
		if (isset($page_info['title']) && preg_match('/Personal Information/', $page_info['title'])) {
			if(isset($page_info['page_start']) && !empty($page_info['page_start']) && isset($page_info['page_end']) && !empty($page_info['page_end'])) {
				for($i = $page_info['page_start']; $i <= $page_info['page_end']; $i++) {
					$html = $pdf->html($i);
					if ($html) {
						$dom = new DOMDocument();
						@$dom->loadHTML($html);
						$dom->preserveWhiteSpace = false;
						$personal_info = parsePersonalInformation($dom);	
						if(is_array($personal_info) && count($personal_info)) {
							$doc_personal_info = array_merge($doc_personal_info, $personal_info);
						}
					} else {
						echo "Something goes wrong!";
						die();
					}
					
				}				
			}
			$pase_report_info['personal_info'] = $doc_personal_info;
		}

		//Report Summary
		if (isset($page_info['title']) && preg_match('/Report Summary/', $page_info['title'])) {
			if(isset($page_info['page_start']) && !empty($page_info['page_start']) && isset($page_info['page_end']) && !empty($page_info['page_end'])) {
				for($i = $page_info['page_start']; $i <= $page_info['page_end']; $i++) {
					$html = $pdf->html($i);
					if ($html) {
						//echo $html; die();
						$dom = new DOMDocument();
						@$dom->loadHTML($html);
						$dom->preserveWhiteSpace = false;
						$report_summary = parseReportSummary($dom);	
						if(is_array($report_summary) && count($report_summary)) {
							$doc_report_summary = array_merge($doc_report_summary, $report_summary);
						}
					} else {
						echo "Something goes wrong! Unable to parse Report Summary section";
						die();
					}
					
				}				
			}
			$pase_report_info['report_summary'] = $doc_report_summary;
			
		}


	}
}
		

echo "<pre>";
print_r($pase_report_info);
die();
// check if your pdf has more than one pages

//echo "Total Pages: " . $total_pages; die();

//$html = new Gufy\PdfToHtml\Html('uploads/GeneratePdfReport.pdf');
// Your pdf happen to have more than one pages and you want to go another page? Got it. use this command to change the current page to page 3
//$html->goToPage(3);

// and then you can do as you please with that dom, you can find any element you want
//$paragraphs = $html->find('body > p');

//var_dump($paragraphs);
//die();

function getPageInfoType($dom)
{
	$pages_info = array();
	$paras = $dom->getElementById('page2-div')->getElementsByTagName('p'); 
	if ($paras instanceof DOMNodeList &&  $paras->length) {
		$page_head = '';
		$i = -1;
		foreach ($paras as $p) {
			$tmp_head = $p->getElementsByTagName('b');
			if ($tmp_head instanceof DOMNodeList && $tmp_head->length) {
				foreach ($tmp_head as $tmp) {
					$content = $tmp->textContent;
					$content = str_replace("Â", "", $content);
					if(!preg_match('/Table of Contents/', $content)) {
						$i++;
						$pages_info[$i]['title'] = $content;
						break;
					}
				}
			} else {
				$content = $p->textContent;
				$content = preg_replace('/..../', '', $content);
				if(strlen($content) && $i >= 0) {
					$pages_info[$i]['page_start'] = $content;
					if($i > 0){
						$pages_info[$i-1]['page_end'] = $pages_info[$i]['page_start'] - 1;
					}
				}
			}
		}
		return $pages_info;
	}
}

function parseBasicInfo($dom)
{
	$basic_info = array();
	$paras = $dom->getElementById('page1-div')->getElementsByTagName('p');
	if ($paras instanceof DOMNodeList &&  $paras->length) {
		$next = false;
		foreach ($paras as $p) {
			$content = $p->textContent;
			$content = str_replace("Â", "", $content);
			if (!empty($content) && !isset($basic_info['created_for'])) {				 
				 $exist = preg_match("/Credit Report Prepared For/", $content);
				 if($exist) {
				 	$next = true;
				 } else if($next) {
				 	$basic_info['created_for'] = $content;
				 	$next = false;
				 } 
			}
			if (!empty($content) && !isset($basic_info['created'])) {
				$exist = preg_match("/Report as Of/", $content);
				if ($exist) {
					$report_date_parts = explode(":", $content);
					$report_date = preg_replace("/\s/", "", $report_date_parts[1]);
					$basic_info['report_date'] = $report_date;
				}
			}
			
			if (isset($basic_info['created']) && isset($basic_info['report_date'])) {
				break;
			}
		}		
	}
	return  $basic_info;
}

function parsePersonalInformation($dom)
{
	$personal_info = array();
	$paras = $dom->getElementById('page3-div')->getElementsByTagName('p');
	$name_found = false;
	if($paras instanceof DOMNodeList) {
		$name_parsed = false;
		$aka_found = false;
		$aka_parsed = false;
		$yob_found = false;
		$yob_parsed = false;
		$next_head = '';
		$address_found = $address_parsed = false;
		foreach($paras as $p) {
			$sub_head = $p->getElementsByTagName('i');
			if ($sub_head instanceof DOMNodeList && $sub_head->length) {
				foreach($sub_head as $sh) {
					$bureau_index = 1;
					$next_head = str_replace("Â", "", $sh->textContent);
				}
				continue;
			}
			$content = $p->textContent;
			$content = str_replace("Â", "", $content);
			if(!empty($next_head) && preg_match("/Name/", $next_head)) {
				if (!$name_parsed) {
					switch ($bureau_index) {
						case 1:
							$personal_info['experien']['name'] = $content;
							$bureau_index++;
							break;
						case 2:
							$personal_info['equifax']['name'] = $content;
							$bureau_index++;
							break;
						case 3:
							$personal_info['transunion']['name'] = $content;
							$bureau_index = 1;
							$name_parsed = true;
							break;
					}
				}
				continue;
			}
			 
			if (!empty($next_head) && preg_match("/AKA/", $next_head)) {
				if (!$aka_parsed) {
					switch($bureau_index) {
						case 1:
							$personal_info['experien']['aka'] = $content;
							$bureau_index++;
							break;
						case 2:
							$personal_info['equifax']['aka'] = $content;
							$bureau_index++;
							break;
						case 3:
							$personal_info['transunion']['aka'] = $content;
							$bureau_index = 1;
							$aka_found = false;
							$aka_parsed = true;
							break;
					}
				}
				continue;
			}
			

			if(!empty($next_head) && preg_match("/Year of Birth/", $next_head)) {
				if (!$yob_parsed) {
					switch($bureau_index) {
						case 1:
							$personal_info['experien']['yob'] = $content;
							$bureau_index++;
							break;
						case 2:
							$personal_info['equifax']['yob'] = $content;
							$bureau_index++;
							break;
						case 3:
							$personal_info['transunion']['yob'] = $content;
							$bureau_index = 1;
							$yob_parsed = true;
							break;
					}
				}
				continue;
			}
			
			if(!empty($next_head) && preg_match("/Address/", $next_head)) {
				if (!$address_parsed) {
					switch($bureau_index) {
						case 1:
							$personal_info['experien']['addresses'] = explode('****', str_replace('&nbsp;&nbsp;', '****', $content));
							$bureau_index++;
							break;
						case 2:
							$personal_info['equifax']['addresses'] = explode('****', str_replace('&nbsp;&nbsp;', '****', $content));
							$bureau_index++;
							break;
						case 3:
							$personal_info['transunion']['addresses'] = explode('****', str_replace('&nbsp;&nbsp;', '****', $content));
							$bureau_index = 1;
							$address_parsed = true;
							break;
					}
				}
				continue;
			}

			if (!empty($next_head) && preg_match("/Current Employer/", $next_head)) {
				$personal_info['current_employer'][] = $content;
				continue;
			}

			if (!empty($next_head) && preg_match("/Previous Employer/", $next_head)) {
				$personal_info['previous_employer'][] = $content;
				continue;
			}
		}
	} else {
		die('Something goes wrong while parsinf the record');
	}

	return $personal_info;
}

 function parseReportSummary($dom)
 {
	$report_summary = array();
	$paras = $dom->getElementById('page4-div')->getElementsByTagName('p');
	$next_head = $next_sub_head = '';
	if ($paras instanceof DOMNodeList) {
		foreach($paras as $p) {
			$tmp_sub_head = $p->getElementsByTagName('i');
			if ($tmp_sub_head instanceof DOMNodeList && $tmp_sub_head->length) {
				foreach($tmp_sub_head as $sbh) {
					$next_sub_head = str_replace("Â", "", $sbh->textContent);
					//echo $next_sub_head . " -- Sub<br />";
					$bureau_index = 1;
					break;
				}
				continue;
			}

			$temp_head = $p->getElementsByTagName('b');
			if ($temp_head instanceof DOMNodeList && $temp_head->length) {
				foreach($temp_head as $nh) {
					$next_head = str_replace("Â", "", $nh->textContent);
					//echo $next_head . "<br />";
					if (preg_match('/Report Summary/', $next_head)) {
						$next_head = '';
					}
				}
				continue;
			}
			$content = $p->textContent;
			$content = str_replace("Â", "", $content);
			if(!empty($next_sub_head) && preg_match('/Real Estate/', $next_head)) {
				/*echo $next_sub_head; die();*/
				switch ($bureau_index) {
					case 1:
						$report_summary['real_estate']['experien'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 2:
						$report_summary['real_estate']['equifax'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 3:
						$report_summary['real_estate']['transunion'][$next_sub_head] = $content;
						$bureau_index = 1;
						break;
				}
			}
			if(!empty($next_sub_head) && preg_match('/Revolving/', $next_head)) {
				/*echo $next_sub_head; die();*/
				switch ($bureau_index) {
					case 1:
						$report_summary['revolving']['experien'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 2:
						$report_summary['revolving']['equifax'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 3:
						$report_summary['revolving']['transunion'][$next_sub_head] = $content;
						$bureau_index = 1;
						break;
				}
			}

			if(!empty($next_sub_head) && preg_match('/Installments/', $next_head)) {
				/*echo $next_sub_head; die();*/
				switch ($bureau_index) {
					case 1:
						$report_summary['installments']['experien'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 2:
						$report_summary['installments']['equifax'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 3:
						$report_summary['installments']['transunion'][$next_sub_head] = $content;
						$bureau_index = 1;
						break;
				}
			}

			if(!empty($next_sub_head) && preg_match('/Other/', $next_head)) {
				/*echo $next_sub_head; die();*/
				switch ($bureau_index) {
					case 1:
						$report_summary['other']['experien'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 2:
						$report_summary['other']['equifax'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 3:
						$report_summary['other']['transunion'][$next_sub_head] = $content;
						$bureau_index = 1;
						break;
				}
			}

			if(!empty($next_sub_head) && preg_match('/Collections/', $next_head)) {
				/*echo $next_sub_head; die();*/
				switch ($bureau_index) {
					case 1:
						$report_summary['collections']['experien'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 2:
						$report_summary['collections']['equifax'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 3:
						$report_summary['collections']['transunion'][$next_sub_head] = $content;
						$bureau_index = 1;
						break;
				}
			}

			if(!empty($next_sub_head) && preg_match('/All Accounts/', $next_head)) {
				/*echo $next_sub_head; die();*/
				switch ($bureau_index) {
					case 1:
						$report_summary['all_accounts']['experien'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 2:
						$report_summary['all_accounts']['equifax'][$next_sub_head] = $content;
						$bureau_index++;
						break;
					case 3:
						$report_summary['all_accounts']['transunion'][$next_sub_head] = $content;
						$bureau_index = 1;
						break;
				}
			}
		}
	}

	return $report_summary;
 }
?>