<?php
if (!defined("WHMCS")) die("This file cannot be accessed directly");
use WHMCS\Database\Capsule;

function wanotify_hook_code($iso) {
    $path = ROOTDIR . '/resources/country/dist.countries.json';
    if (file_exists($path)) {
        $json = json_decode(file_get_contents($path), true);
        if(isset($json[$iso]['callingCode'])) return $json[$iso]['callingCode'];
    }
    return '1'; // Default US
}

function wanotify_send($phone, $msg) {
    $conf = Capsule::table('tbladdonmodules')->where('module','wa_notify_pro')->pluck('value','setting');
    if (!$conf['api_url'] || !$phone) return;
    $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $conf['api_url'].'/send'); curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['phone'=>$phone, 'message'=>$msg, 'secret'=>$conf['api_key']]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']); curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_exec($ch); curl_close($ch);
    try { if(Capsule::schema()->hasTable('mod_wanotify_logs')) Capsule::table('mod_wanotify_logs')->insert(['date'=>date('Y-m-d H:i:s'), 'phone'=>$phone, 'message'=>$msg, 'status'=>'hook_sent']); } catch(Exception $e){}
}

function wanotify_get_tpl($name, $vars) {
    $tpl = Capsule::table('mod_wanotify_templates')->where('name', $name)->where('active', 1)->first();
    if (!$tpl) return null;
    $msg = $tpl->message;
    foreach ($vars as $k => $v) $msg = str_replace('{'.$k.'}', $v, $msg);
    return $msg;
}

// --- HOOKS ---
add_hook('InvoicePaid', 1, function($vars) {
    $inv = Capsule::table('tblinvoices')->find($vars['invoiceid']);
    $cl = Capsule::table('tblclients')->find($vars['userid']);
    $conf = Capsule::table('tbladdonmodules')->where('module','wa_notify_pro')->pluck('value','setting');
    
    // Client Notif
    $prefix = wanotify_hook_code($cl->country); $phone = preg_replace('/[^0-9]/', '', $cl->phonenumber);
    if (substr($phone, 0, strlen($prefix)) != $prefix) $phone = (substr($phone,0,1)=='0') ? $prefix.substr($phone,1) : $prefix.$phone;
    $msgClient = wanotify_get_tpl('InvoicePaid', ['firstname'=>$cl->firstname, 'invoiceid'=>$vars['invoiceid']]);
    if($msgClient) wanotify_send($phone, $msgClient);

    // Admin Alert
    if (!empty($conf['admin_phone'])) {
        $msgAdmin = wanotify_get_tpl('AdminPaymentAlert', ['firstname'=>$cl->firstname.' '.$cl->lastname, 'invoiceid'=>$vars['invoiceid'], 'total'=>$inv->total, 'gateway'=>$inv->paymentmethod]);
        if($msgAdmin) wanotify_send($conf['admin_phone'], $msgAdmin);
    }
});

add_hook('InvoiceCreated', 1, function($vars) {
    $inv = Capsule::table('tblinvoices')->find($vars['invoiceid']);
    $cl = Capsule::table('tblclients')->find($vars['userid']);
    $prefix = wanotify_hook_code($cl->country); $phone = preg_replace('/[^0-9]/', '', $cl->phonenumber);
    if (substr($phone, 0, strlen($prefix)) != $prefix) $phone = (substr($phone,0,1)=='0') ? $prefix.substr($phone,1) : $prefix.$phone;
    $msg = wanotify_get_tpl('InvoiceCreated', ['firstname'=>$cl->firstname, 'invoiceid'=>$vars['invoiceid'], 'total'=>$inv->total, 'duedate'=>$inv->duedate]);
    if($msg) wanotify_send($phone, $msg);
});

add_hook('TicketStatusChange', 1, function($vars) {
    $ticket = Capsule::table('tbltickets')->find($vars['ticketid']);
    if ($ticket && $ticket->userid) {
        $cl = Capsule::table('tblclients')->find($ticket->userid);
        $prefix = wanotify_hook_code($cl->country); $phone = preg_replace('/[^0-9]/', '', $cl->phonenumber);
        if (substr($phone, 0, strlen($prefix)) != $prefix) $phone = (substr($phone,0,1)=='0') ? $prefix.substr($phone,1) : $prefix.$phone;
        $msg = wanotify_get_tpl('TicketStatusChange', ['firstname'=>$cl->firstname, 'ticketid'=>$ticket->tid, 'status'=>$vars['status']]);
        if($msg) wanotify_send($phone, $msg);
    }
});