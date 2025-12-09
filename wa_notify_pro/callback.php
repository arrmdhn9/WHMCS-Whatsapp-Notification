<?php
/**
 * WHMCS WA Bot Logic - Smart Error Handling (Fixed)
 */
require_once __DIR__ . '/../../../init.php';
use WHMCS\Database\Capsule;

// 1. Capture & Validate Input
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE);
if (!$input || ($input['action']??'') !== 'incoming_message') die();

$conf = Capsule::table('tbladdonmodules')->where('module','wa_notify_pro')->pluck('value','setting');
if (($input['secret']??'') !== $conf['api_key']) die();

$sender = $input['phone'];
$msg = trim($input['message']);
$adminPhone = preg_replace('/[^0-9]/', '', $conf['admin_phone']);

// Log Incoming Message
Capsule::table('mod_wanotify_logs')->insert(['date'=>date('Y-m-d H:i:s'), 'phone'=>$sender, 'message'=>'[IN] '.$msg, 'status'=>'received']);

// 2. HANYA PROSES JIKA PENGIRIM ADALAH ADMIN
if ($sender == $adminPhone) {
    $adminUser = $conf['admin_user'] ?? 'admin';
    
    // --- COMMAND: HELP / MENU ---
    if (preg_match('/^(help|menu|info|\?)$/i', $msg)) {
        $txt = "🤖 *Admin Bot Commands*\n\n"
             . "💰 *Invoice Actions:*\n"
             . "• `/paid #ID` (Mark Paid)\n"
             . "• `/unpaid #ID` (Mark Unpaid)\n"
             . "• `/cancel #ID` (Cancel Inv)\n\n"
             . "🎫 *Support Tickets:*\n"
             . "• `#ID Message` (Reply Ticket)\n"
             . "• `/status #ID Status` (Change Status) (Open/Closed/Answered/Hold/Progress)";
        wa_reply($conf, $sender, $txt);
        return; // Stop process
    }

    // --- GROUP 1: INVOICE COMMANDS ---
    // Deteksi Kata Kunci dulu (tanpa cek ID dulu) agar tidak lari ke 'Unknown Command'
    if (preg_match('/^\/(paid|unpaid|cancel|refund)\b/i', $msg, $m)) {
        $cmdKeyword = strtolower($m[1]);
        
        // Sekarang Validasi Format Lengkap (Harus ada ID angka)
        if (preg_match('/^\/(?:paid|unpaid|cancel|refund)\s+#?(\d+)/i', $msg, $matches)) {
            $cmd = strtolower($matches[1]); 
            $id = $matches[2];
            
            // CEK DATABASE (Data Exist?)
            $inv = Capsule::table('tblinvoices')->where('id', $id)->first();
            
            if (!$inv) {
                // KASUS 1: Format Benar, TAPI Data Tidak Ada
                wa_reply($conf, $sender, "⚠️ *Data Tidak Ditemukan*\nInvoice #$id tidak ada di database WHMCS.");
            } else {
                // KASUS 2: Format Benar & Data Ada -> EKSEKUSI
                if ($cmd == 'paid') {
                    $r = localAPI('AddInvoicePayment', ['invoiceid'=>$id, 'transid'=>'WA-'.time(), 'gateway'=>'banktransfer'], $adminUser);
                    wa_reply($conf, $sender, $r['result']=='success' ? "✅ Invoice #$id BERHASIL diubah jadi *PAID*." : "❌ Gagal API: ".$r['message']);
                } else {
                    $map = ['unpaid'=>'Unpaid', 'cancel'=>'Cancelled', 'refund'=>'Refunded'];
                    $r = localAPI('UpdateInvoice', ['invoiceid'=>$id, 'status'=>$map[$cmd]], $adminUser);
                    wa_reply($conf, $sender, $r['result']=='success' ? "✅ Invoice #$id status berubah ke *{$map[$cmd]}*." : "❌ Gagal API: ".$r['message']);
                }
            }
        } else {
            // KASUS 3: Kata kunci benar, TAPI Format Salah (misal lupa ID)
            wa_reply($conf, $sender, "⚠️ *Format Salah*\nHarap masukkan ID Invoice.\nContoh: `/$cmdKeyword #1024`");
        }
    }

    // --- GROUP 2: TICKET REPLY ---
    // Deteksi awalan #
    elseif (substr($msg, 0, 1) == '#') {
        // Cek apakah formatnya #ID Pesan
        if (preg_match('/^#(\d+)\s+(.+)/s', $msg, $matches)) {
            $tid = $matches[1]; 
            $reply = $matches[2];
            
            // CEK DATABASE
            $t = Capsule::table('tbltickets')->where('tid', $tid)->first();
            
            if (!$t) {
                // KASUS 1: Data Tiket Tidak Ada
                wa_reply($conf, $sender, "⚠️ *Tiket Tidak Ditemukan*\nTiket #$tid tidak ada di database.");
            } else {
                // KASUS 2: Sukses
                $r = localAPI('AddTicketReply', ['ticketid'=>$t->id, 'message'=>$reply."\n\n(Replied via WhatsApp)", 'adminusername'=>$adminUser]);
                wa_reply($conf, $sender, $r['result']=='success' ? "✅ Balasan terkirim ke tiket #$tid." : "❌ Gagal Reply: ".$r['message']);
            }
        } else {
            // KASUS 3: Format Salah (Mungkin cuma #123 tanpa pesan)
            // Cek apakah dia cuma ngetik ID doang?
            if (preg_match('/^#(\d+)$/', $msg)) {
                wa_reply($conf, $sender, "⚠️ *Pesan Kosong*\nUntuk membalas, formatnya: `#ID Pesan Anda`");
            } else {
                // Anggap bukan perintah bot jika tidak sesuai pola ID
            }
        }
    }

    // --- GROUP 3: TICKET STATUS ---
    elseif (preg_match('/^\/status\b/i', $msg)) {
        // Cek Format Lengkap
        if (preg_match('/^\/status\s+#?(\d+)\s+(.+)/i', $msg, $matches)) {
            $tid = $matches[1]; 
            $statInput = ucfirst(strtolower(trim($matches[2])));
            
            $validStatuses = ['Open'=>'Open', 'Closed'=>'Closed', 'Answered'=>'Answered', 'Hold'=>'On Hold', 'Progress'=>'In Progress'];
            $finalStat = null;
            foreach ($validStatuses as $k => $v) { if (stripos($k, $statInput) !== false) $finalStat = $v; }
            
            if (!$finalStat) {
                wa_reply($conf, $sender, "⚠️ *Status Tidak Valid*\nStatus tersedia: Open, Closed, Answered, Hold, Progress.");
                return;
            }

            // CEK DATABASE
            $t = Capsule::table('tbltickets')->where('tid', $tid)->first();
            if (!$t) {
                wa_reply($conf, $sender, "⚠️ *Tiket Tidak Ditemukan*\nTiket #$tid tidak ada.");
            } else {
                $r = localAPI('UpdateTicket', ['ticketid'=>$t->id, 'status'=>$finalStat]);
                wa_reply($conf, $sender, $r['result']=='success' ? "✅ Tiket #$tid status berubah ke *$finalStat*." : "❌ Gagal API: ".$r['message']);
            }
        } else {
            wa_reply($conf, $sender, "⚠️ *Format Salah*\nGunakan: `/status #ID Status`\nContoh: `/status #9281 Closed`");
        }
    }

    // --- CATCH ALL: UNKNOWN COMMAND ---
    // Jika diawali '/' tapi tidak cocok dengan perintah di atas
    elseif (substr($msg, 0, 1) == '/') {
        wa_reply($conf, $sender, "❓ *Perintah Tidak Dikenal*.\nKetik `help` atau `menu` untuk melihat daftar perintah.");
    }
}

// Helper Function
function wa_reply($c, $p, $m) {
    $ch=curl_init(); curl_setopt($ch,CURLOPT_URL,$c['api_url'].'/send'); curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(['phone'=>$p,'message'=>$m,'secret'=>$c['api_key']]));
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type:application/json']); curl_exec($ch); curl_close($ch);
}