<?php
/**
 * WHMCS WA Bot Logic - English
 */
require_once __DIR__ . '/../../../init.php';
use WHMCS\Database\Capsule;

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE);
if (!$input || ($input['action']??'') !== 'incoming_message') die();

$conf = Capsule::table('tbladdonmodules')->where('module','wa_notify_pro')->pluck('value','setting');
if (($input['secret']??'') !== $conf['api_key']) die();

$sender = $input['phone'];
$msg = trim($input['message']);
$adminPhone = preg_replace('/[^0-9]/', '', $conf['admin_phone']);

Capsule::table('mod_wanotify_logs')->insert(['date'=>date('Y-m-d H:i:s'), 'phone'=>$sender, 'message'=>'[IN] '.$msg, 'status'=>'received']);

// AUTH ADMIN
if ($sender == $adminPhone) {
    $adminUser = $conf['admin_user'] ?? 'admin';

    // 1. INVOICE ACTIONS
    if (preg_match('/^\/(paid|unpaid|cancel|refund)\s+#?(\d+)/i', $msg, $m)) {
        $cmd = strtolower($m[1]); $id = $m[2];
        if ($cmd == 'paid') {
            $r = localAPI('AddInvoicePayment', ['invoiceid'=>$id, 'transid'=>'WA-'.time(), 'gateway'=>'banktransfer'], $adminUser);
            wa_reply($conf, $sender, $r['result']=='success' ? "✅ Invoice #$id marked as PAID." : "❌ Failed: ".$r['message']);
        } else {
            $map = ['unpaid'=>'Unpaid', 'cancel'=>'Cancelled', 'refund'=>'Refunded'];
            $r = localAPI('UpdateInvoice', ['invoiceid'=>$id, 'status'=>$map[$cmd]], $adminUser);
            wa_reply($conf, $sender, $r['result']=='success' ? "✅ Invoice #$id updated to {$map[$cmd]}." : "❌ Failed: ".$r['message']);
        }
    }

    // 2. REPLY TICKET
    elseif (preg_match('/^#(\d+)\s+(.+)/s', $msg, $m)) {
        $tid = $m[1]; $reply = $m[2];
        $t = Capsule::table('tbltickets')->where('tid', $tid)->first();
        if ($t) {
            $r = localAPI('AddTicketReply', ['ticketid'=>$t->id, 'message'=>$reply."\n\n(Replied via WhatsApp)", 'adminusername'=>$adminUser]);
            wa_reply($conf, $sender, $r['result']=='success' ? "✅ Reply sent to #$tid." : "❌ Failed.");
        } else {
            wa_reply($conf, $sender, "❌ Ticket #$tid not found.");
        }
    }

    // 3. CHANGE STATUS
    elseif (preg_match('/^\/status\s+#?(\d+)\s+(.+)/i', $msg, $m)) {
        $tid = $m[1]; 
        $statInput = ucfirst(strtolower(trim($m[2])));
        $validStatuses = ['Open'=>'Open', 'Closed'=>'Closed', 'Answered'=>'Answered', 'Hold'=>'On Hold', 'Progress'=>'In Progress'];
        foreach ($validStatuses as $k => $v) { if (stripos($k, $statInput) !== false) $statInput = $v; }

        $t = Capsule::table('tbltickets')->where('tid', $tid)->first();
        if ($t) {
            $r = localAPI('UpdateTicket', ['ticketid'=>$t->id, 'status'=>$statInput]);
            wa_reply($conf, $sender, $r['result']=='success' ? "✅ Ticket #$tid status -> $statInput" : "❌ Failed: ".$r['message']);
        } else {
            wa_reply($conf, $sender, "❌ Ticket #$tid not found.");
        }
    }
}

function wa_reply($c, $p, $m) {
    $ch=curl_init(); curl_setopt($ch,CURLOPT_URL,$c['api_url'].'/send'); curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(['phone'=>$p,'message'=>$m,'secret'=>$c['api_key']]));
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type:application/json']); curl_exec($ch); curl_close($ch);
}