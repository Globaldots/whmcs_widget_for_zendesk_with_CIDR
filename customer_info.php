<?php
#### CONFIGURATION ####
/*
 * Widget secure code
 * This code is shared between WHMCS and the Zendesk App
 * Set the code here and use it in Zendesk during the App instalation
 */
$widget_secure_code = "xxxxx";

/*
 * Widget IP whitelist
 * Set Zendesk's IP here to be able to fetch client details to the App
 * Unauthorized access attempts are logged in 'unauthorized_access_attempts.txt' file.
 */
 
 /* 
  This array can now contain IP addresses as well as CIDR ranges. 
  Zendesk public IP addresses can be found here: https://support.zendesk.com/hc/en-us/articles/203660846-Zendesk-Public-IP-addresses
  If your WHMCS is behind a CDN, or a security proxy, or a WAF, then adjust your list accordingly. 
 */
$widget_ip_whitelist = array(
    '192.161.144.144',
    '192.161.144.145',
	'192.161.144.146',
	'192.161.144.147',
	'174.137.46.0/24', 
	'96.46.150.192/27',
	'96.46.156.0/24',
	'104.218.200.0/21',
	'185.12.80.0/22',
	'188.172.128.0/20',
	'192.161.144.0/20',
	'216.198.0.0/18',
	'52.192.205.30/32',
	'52.193.22.204/32',
	'52.37.220.11/32',
	'52.27.183.82/32',
	'52.37.212.231/32',
	'52.203.58.200/32',
	'52.203.0.71/32',
	'52.21.112.236/32',

);

/*
 * DO NOT EDIT BELOW
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Max-Age: 1000');
if(isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
    header('Access-Control-Allow-Headers: '.$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'].', Access-Control-Allow-Origin, Access-Control-Allow-Headers, Access-Control-Allow-Methods');
$access_log_file=dirname(__FILE__).DIRECTORY_SEPARATOR.'unauthorized_access_attempts.txt';

// Start CIDR Support 
function cidr_match($ip, $cidr)
{
    list($subnet, $mask) = explode('/', $cidr);
    if ((ip2long($ip) & ~((1 << (32 - $mask)) - 1) ) == ip2long($subnet))
    { 
        return true;
    }
    return false;
}

function ip_cidr_lookup($ip, $whitelist) {
	foreach ($whitelist as $whiteitem) {
		if (strpos($whiteitem, '/') !== false && cidr_match($ip, $whiteitem)) {
					return true; 
			}
		} 
	return false;
}

// if (!in_array($_SERVER['REMOTE_ADDR'],$widget_ip_whitelist)) {
if (!in_array($_SERVER['REMOTE_ADDR'],$widget_ip_whitelist) && !ip_cidr_lookup($_SERVER['REMOTE_ADDR'],$widget_ip_whitelist)  ) {	
// END CIDR Support     
    sleep(5);
    $results = array('result' => "error", 'message' => "Wrong IP Address");

    $filecontent = file_get_contents($access_log_file);
    if($filecontent !== false){
        if(!$filecontent)
            $attempts=array();
        else
            $attempts = explode("\n",$filecontent);
        $iplist = array();
        foreach($attempts as $att){
            $temp               = explode(' - ',$att);
            $iplist[$temp[1]]   = $temp[0];
        }
        $iplist[$_SERVER['REMOTE_ADDR']] = date("Y-m-d H:i:s");
        asort($iplist);
        $attempts = array();
        foreach($iplist as $ip => $date){
            $attempts[] = $date." - ".$ip;
        }
        $attempts = implode("\n",$attempts);

        file_put_contents($access_log_file, $attempts);
    }
    
} else if (empty($_REQUEST['securecode']) || ($_REQUEST['securecode'] != $widget_secure_code)) {

    sleep(5);
    $results = array('result' => "error", 'message' => "Wrong Secure Code");
    
} else if (!empty($_REQUEST['email'])) {
    
    $whmcsdir = dirname(dirname(dirname(__FILE__))).DIRECTORY_SEPARATOR;
    
    if(file_exists($whmcsdir.'dbconnect.php')){
        require_once ($whmcsdir.'dbconnect.php');
    }else{
        require_once ($whmcsdir.'init.php');
    }    
    require_once ($whmcsdir.'includes'.DIRECTORY_SEPARATOR.'functions.php');

    global $CONFIG;
    global $customadminpath;

    $whmcs_url = $CONFIG['SystemSSLURL'] ? $CONFIG['SystemSSLURL'] : $CONFIG['SystemURL'];
    if(substr($whmcs_url,-1)!='/')
        $whmcs_url.='/';

    $query      = "SELECT username FROM tbladmins LIMIT 1";
    $result     = mysql_query($query);
    $row        = mysql_fetch_assoc($result);
    $adminuser  = $row['username'];

    $results    = array('result'=>'');
    $email      = mysql_real_escape_string($_REQUEST['email']);
    
    $query 	= "SELECT c.email FROM tblclients c JOIN tblcontacts s ON c.id=s.userid WHERE s.email='{$email}'";
    $result     = mysql_query($query);
    if($mainclient = mysql_fetch_assoc($result)){
        $email  = $mainclient['email'];
    }
    
    $query      = "SELECT * FROM tblclients WHERE email='{$email}'";
    $result     = mysql_query($query);
    
    if($clientsdetails = mysql_fetch_assoc($result)){
        unset($clientsdetails['password']);
        $results                = array('result'=>'success','clientsdetails'=>$clientsdetails);
        $results['adminpath']   = $customadminpath;
        $results['whmcsurl']    = $whmcs_url;
    }
    else{
        $results = array('result' => "error", 'message' => "Client not found");
    }

} else {
    $results = array('result' => "error", 'message' => "No email specified");
}


if($results['result']=='success'){
            
    $command            = "getclientsproducts";
    $values["clientid"] = $clientsdetails['id'];
    $productsresults    = localAPI($command,$values,$adminuser);

    if($productsresults['result'] == 'success'){

        $results['clientproducts'] = $productsresults['products']['product'];
        if(!$results['clientproducts']) $results['clientproducts']=array();
        
        $domainsresults = array();
        $query          = "SELECT * FROM tbldomains WHERE userid = {$clientsdetails['id']}";
        $result         = mysql_query($query);
        
        while($row = mysql_fetch_assoc($result)){
            $domainsresults[] = $row;
        }
        
        $results['clientdomains'] = $domainsresults;
        
        $command            = "getinvoices";
        $values["userid"]   = $clientsdetails['id'];
        $invoicesresults    = localAPI($command,$values,$adminuser);
        
        if($productsresults['result'] == 'success'){
            $client_invoices = array();
            foreach($invoicesresults['invoices']['invoice'] as $inv){
                if($inv['status'] == 'Unpaid'){
                    $client_invoices[] = $inv;
                }
            }
            $results['clientinvoices'] = $client_invoices;
        }
        else{
            $results = array('result'=>'error','message'=>$invoicesresults['message']);
        }
    }
    else{
        $results = array('result'=>'error','message'=>$productsresults['message']);
    }
}
@ob_clean();
echo json_encode($results);
?>