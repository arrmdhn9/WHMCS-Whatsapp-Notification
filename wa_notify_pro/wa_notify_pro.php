<?php
/**
 * WHMCS WA Notify Pro - GitHub Edition
 * Author: Your Name / Handle
 * License: MIT
 */

if (!defined("WHMCS")) die("This file cannot be accessed directly");
use WHMCS\Database\Capsule;

function wa_notify_pro_config() {
    return [
        'name' => 'WA Notify Pro (Open Source)',
        'description' => 'Real-time WhatsApp Gateway with Two-Way Admin Chat & Payment Actions.',
        'author' => 'CasperdotID',
        'version' => '1.0.0',
        'fields' => [
            'api_url' => ['FriendlyName'=>'Gateway URL', 'Type'=>'text', 'Size'=>'50', 'Default'=>'http://localhost:3000', 'Description'=>'Your Node.js Server URL'],
            'api_key' => ['FriendlyName'=>'API Key', 'Type'=>'password', 'Size'=>'50', 'Default'=>'secret_key', 'Description'=>'Must match API_KEY in .env'],
            'admin_phone' => ['FriendlyName'=>'Admin Phone Number', 'Type'=>'text', 'Size'=>'20', 'Description'=>'For admin alerts & bot commands (Format: CountryCode+Number, e.g., 62812...)'],
            'admin_user' => ['FriendlyName'=>'WHMCS Admin Username', 'Type'=>'text', 'Size'=>'20', 'Description'=>'Username used to execute API actions (e.g. admin)'],
        ]
    ];
}

// --- CORE FUNCTIONS ---
function wanotify_get_system_countries() {
    $jsonPath = ROOTDIR . '/resources/country/dist.countries.json';
    $countries = [];
    if (file_exists($jsonPath)) {
        $json = json_decode(file_get_contents($jsonPath), true);
        foreach ($json as $iso => $data) { $countries[$iso] = ['name' => $data['name'], 'code' => $data['callingCode']]; }
    } else { $countries['US'] = ['name' => 'United States', 'code' => '1']; } // Fallback
    uasort($countries, function($a, $b) { return strcmp($a['name'], $b['name']); });
    return $countries;
}

function wanotify_fmt($phone, $iso) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $list = wanotify_get_system_countries();
    $prefix = isset($list[$iso]['code']) ? $list[$iso]['code'] : '1'; // Default US if not found
    if (substr($phone, 0, 1) == '0') return $prefix . substr($phone, 1);
    if (substr($phone, 0, strlen($prefix)) == $prefix) return $phone;
    return $prefix . $phone;
}

function wa_notify_pro_activate() { return ['status'=>'success','description'=>'Module Activated Successfully']; }

function wa_notify_pro_output($vars) {
    // Auto DB Migration
    if (!Capsule::schema()->hasTable('mod_wanotify_logs')) Capsule::schema()->create('mod_wanotify_logs', function ($t) { $t->increments('id'); $t->dateTime('date'); $t->string('phone'); $t->text('message'); $t->string('status'); $t->text('response')->nullable(); });
    if (!Capsule::schema()->hasTable('mod_wanotify_templates')) {
        Capsule::schema()->create('mod_wanotify_templates', function ($t) { $t->increments('id'); $t->string('name')->unique(); $t->text('message'); $t->boolean('active')->default(true); });
        
        // Default English Templates
        $defs = [
            ['name'=>'InvoiceCreated', 'message'=>"Hello {firstname}, New Invoice #{invoiceid} generated. Total: {total}. Due Date: {duedate}."],
            ['name'=>'InvoicePaid', 'message'=>"Thank you {firstname}, Invoice #{invoiceid} is successfully PAID."],
            ['name'=>'TicketReply', 'message'=>"Hello {firstname}, Reply for ticket #{ticketid}:\n\n{message}"],
            ['name'=>'TicketStatusChange', 'message'=>"Ticket #{ticketid} status changed to: {status}."],
            ['name'=>'AdminPaymentAlert', 'message'=>"💰 *Payment Received!*\nClient: {firstname}\nInv: #{invoiceid}\nTotal: {total}\nMethod: {gateway}"]
        ];
        foreach($defs as $d) Capsule::table('mod_wanotify_templates')->insert($d);
    }

    $url = $vars['api_url']; $key = $vars['api_key']; $link = $vars['modulelink'];
    $sysHook = rtrim(Capsule::table('tblconfiguration')->where('setting','SystemURL')->value('value'),'/') . '/modules/addons/wa_notify_pro/callback.php';
    $countries = wanotify_get_system_countries();

    // --- PROCESS ACTIONS ---
    if (isset($_POST['act'])) {
        if ($_POST['act']=='webhook') {
            $ch=curl_init(); curl_setopt($ch,CURLOPT_URL,$url.'/config'); curl_setopt($ch,CURLOPT_POST,1);
            curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(['apiKey'=>$key,'webhookUrl'=>$sysHook]));
            curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type:application/json']); curl_exec($ch); curl_close($ch);
            echo '<div class="alert alert-success">Webhook Synced Successfully!</div>';
        }
        if ($_POST['act']=='logout') {
            $ch=curl_init(); curl_setopt($ch,CURLOPT_URL,$url.'/logout'); curl_setopt($ch,CURLOPT_POST,1);
            curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(['secret'=>$key])); curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type:application/json']); curl_exec($ch); curl_close($ch);
            echo '<div class="alert alert-warning">Logout command sent. Please refresh in 5 seconds.</div>';
        }
        if ($_POST['act']=='save_tpl') {
            foreach($_POST['tpl'] as $id=>$v) Capsule::table('mod_wanotify_templates')->where('id',$id)->update(['message'=>$v['msg'],'active'=>isset($v['on'])?1:0]);
            echo '<div class="alert alert-success">Templates Saved.</div>';
        }
        if ($_POST['act']=='send') {
            $tgt = ($_POST['type']=='client') ? wanotify_fmt(Capsule::table('tblclients')->find($_POST['cid'])->phonenumber, Capsule::table('tblclients')->find($_POST['cid'])->country) : wanotify_fmt($_POST['dest_number'], $_POST['iso']);
            $ch=curl_init(); curl_setopt($ch,CURLOPT_URL,$url.'/send'); curl_setopt($ch,CURLOPT_POST,1);
            curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(['phone'=>$tgt,'message'=>$_POST['msg'],'secret'=>$key]));
            curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type:application/json']); curl_exec($ch); curl_close($ch);
            echo '<div class="alert alert-info">Message sent to '.$tgt.'</div>';
        }
    }
    if (isset($_GET['ajax'])) { ob_clean(); $ch=curl_init(); curl_setopt($ch,CURLOPT_URL,$url.'/status'); curl_setopt($ch,CURLOPT_RETURNTRANSFER,1); curl_setopt($ch,CURLOPT_TIMEOUT,2); $r=curl_exec($ch); echo $r?$r:json_encode(['connected'=>false]); exit; }

    // --- UI STYLING ---
    echo '<style>
    :root { --p-color: #4f46e5; --s-color: #10b981; --bg-gray: #f3f4f6; --card-bg: #ffffff; --text-main: #1f2937; }
    .wa-wrap { font-family: "Segoe UI", system-ui, sans-serif; background: var(--bg-gray); padding: 20px; border-radius: 8px; }
    .wa-card { background: var(--card-bg); border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 20px; overflow: hidden; border: 1px solid #e5e7eb; }
    .wa-head { background: #fff; padding: 15px 20px; border-bottom: 1px solid #e5e7eb; font-weight: 700; color: var(--text-main); font-size: 1.1em; display:flex; justify-content:space-between; align-items:center; }
    .wa-body { padding: 25px; }
    .wa-nav { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: none; }
    .wa-nav a { padding: 10px 20px; border-radius: 50px; background: #fff; color: #6b7280; font-weight: 600; text-decoration: none; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.2s; }
    .wa-nav a:hover, .wa-nav a.active { background: var(--p-color); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 6px rgba(79, 70, 229, 0.2); }
    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
    .stat-item { background: #fff; padding: 20px; border-radius: 12px; display: flex; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
    .stat-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2em; margin-right: 15px; }
    .st-green { background: #d1fae5; color: #059669; } .st-red { background: #fee2e2; color: #dc2626; } .st-blue { background: #dbeafe; color: #2563eb; }
    .live-dot { height: 10px; width: 10px; background-color: #10b981; border-radius: 50%; display: inline-block; animation: pulse 2s infinite; margin-right: 8px; }
    @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); } 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }
    .mockup { width: 260px; height: 380px; background: #000; border-radius: 30px; margin: 0 auto; padding: 10px; position: relative; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); }
    .mockup-screen { background: #fff; width: 100%; height: 100%; border-radius: 20px; display: flex; align-items: center; justify-content: center; overflow: hidden; position:relative; }
    .mockup-notch { width: 100px; height: 20px; background: #000; position: absolute; top: 0; left: 50%; transform: translateX(-50%); border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; z-index:10; }
    .wa-table th { background: #f9fafb; color: #6b7280; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; }
    .form-control { border-radius: 8px; padding: 10px; border: 1px solid #d1d5db; box-shadow: none; height: auto; }
    .btn-p { background: var(--p-color); color: #fff; border:none; padding: 10px 20px; border-radius: 8px; font-weight: 600; transition:0.2s; }
    .btn-p:hover { background: #4338ca; }
    </style>';

    $tab = $_GET['tab'] ?? 'dash';
    echo '<div class="wa-wrap">';
    
    // NAVIGATION
    echo '<div class="wa-nav">
        <a href="'.$link.'&tab=dash" class="'.($tab=='dash'?'active':'').'"><i class="fas fa-home"></i> Dashboard</a>
        <a href="'.$link.'&tab=send" class="'.($tab=='send'?'active':'').'"><i class="fas fa-paper-plane"></i> Send Message</a>
        <a href="'.$link.'&tab=tpl" class="'.($tab=='tpl'?'active':'').'"><i class="fas fa-layer-group"></i> Templates</a>
        <a href="'.$link.'&tab=logs" class="'.($tab=='logs'?'active':'').'"><i class="fas fa-list-alt"></i> Logs</a>
    </div>';

    if ($tab == 'dash') {
        ?>
        <!-- STATISTICS CARDS -->
        <div class="stat-grid">
            <div class="stat-item">
                <div class="stat-icon st-green" id="icon-con"><i class="fas fa-wifi"></i></div>
                <div><h4 style="margin:0; font-weight:bold" id="txt-con">Connecting...</h4><small class="text-muted">WhatsApp Status</small></div>
            </div>
            <div class="stat-item">
                <div class="stat-icon st-blue" id="icon-wh"><i class="fas fa-link"></i></div>
                <div><h4 style="margin:0; font-weight:bold" id="txt-wh">Checking...</h4><small class="text-muted">Webhook</small></div>
            </div>
            <div class="stat-item">
                <div class="stat-icon st-red"><i class="fas fa-server"></i></div>
                <div><h4 style="margin:0; font-weight:bold"><?php echo parse_url($url, PHP_URL_HOST); ?></h4><small class="text-muted">Gateway Node</small></div>
            </div>
        </div>

        <div class="row">
            <!-- MAIN DASHBOARD -->
            <div class="col-md-8">
                <div class="wa-card">
                    <div class="wa-head"><span><i class="fas fa-robot"></i> Admin Bot Commands (Auto-Reply)</span> <span class="badge" style="background:var(--p-color)">Smart Bot</span></div>
                    <div class="wa-body">
                        <p class="text-muted" style="margin-bottom:20px;">Use your registered Admin WhatsApp number to control WHMCS:</p>
                        
                        <div class="table-responsive">
                            <table class="table wa-table">
                                <thead><tr><th>Action</th><th>Command</th><th>Example</th></tr></thead>
                                <tbody>
                                    <tr>
                                        <td><span class="label label-success">Mark Paid</span></td>
                                        <td><code>/paid #ID</code></td>
                                        <td><code>/paid #1055</code> (Set Invoice to Paid)</td>
                                    </tr>
                                    <tr>
                                        <td><span class="label label-warning">Mark Unpaid</span></td>
                                        <td><code>/unpaid #ID</code></td>
                                        <td><code>/unpaid #1055</code> (Set Invoice to Unpaid)</td>
                                    </tr>
                                    <tr>
                                        <td><span class="label label-danger">Cancel</span></td>
                                        <td><code>/cancel #ID</code></td>
                                        <td><code>/cancel #1055</code> (Cancel Invoice)</td>
                                    </tr>
                                    <tr>
                                        <td><span class="label label-info">Reply Ticket</span></td>
                                        <td><code>#ID Message</code></td>
                                        <td><code>#82911 Hello, we are checking...</code></td>
                                    </tr>
                                    <tr>
                                        <td><span class="label label-primary">Change Status</span></td>
                                        <td><code>/status #ID [Status]</code></td>
                                        <td><code>/status #82911 Closed</code> (Open/Closed/Answered/Hold/Progress)</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div style="text-align:right; margin-top:10px;">
                             <form method="post" style="display:inline"><input type="hidden" name="act" value="webhook"><button class="btn btn-default btn-sm"><i class="fas fa-sync"></i> Force Sync Webhook</button></form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- DEVICE STATUS -->
            <div class="col-md-4">
                <div class="wa-card">
                    <div class="wa-head">Device Session</div>
                    <div class="wa-body" style="background:#f9fafb; text-align:center;">
                        <div class="mockup">
                            <div class="mockup-notch"></div>
                            <div class="mockup-screen" id="qr-screen">
                                <i class="fas fa-circle-notch fa-spin fa-2x text-muted"></i>
                            </div>
                        </div>
                        <br>
                        <form method="post" onsubmit="return confirm('Disconnect & Logout?');">
                            <input type="hidden" name="act" value="logout">
                            <button class="btn btn-danger btn-block"><i class="fas fa-sign-out-alt"></i> Logout Device</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
        var sysWh = "<?php echo $sysHook; ?>";
        setInterval(function(){
            $.getJSON('<?php echo $link; ?>&ajax=1', function(d){
                if(d.connected) {
                    $('#icon-con').attr('class','stat-icon st-green').html('<i class="fas fa-check"></i>');
                    $('#txt-con').html('<span class="live-dot"></span>Online');
                    $('#qr-screen').html('<div style="text-align:center; color:#10b981"><i class="fab fa-whatsapp fa-5x"></i><br><br><b>WhatsApp Ready</b></div>');
                } else {
                    $('#icon-con').attr('class','stat-icon st-red').html('<i class="fas fa-times"></i>');
                    $('#txt-con').html('<span style="color:#ef4444">Offline</span>');
                    if(d.qr) $('#qr-screen').html('<img src="'+d.qr+'" style="width:100%">');
                    else $('#qr-screen').html('<div class="text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading QR...</p></div>');
                }
                if(d.webhook==sysWh) {
                    $('#icon-wh').attr('class','stat-icon st-blue').html('<i class="fas fa-link"></i>');
                    $('#txt-wh').text('Synced');
                } else {
                    $('#icon-wh').attr('class','stat-icon st-red').html('<i class="fas fa-exclamation"></i>');
                    $('#txt-wh').text('Mismatch');
                }
            });
        }, 3000);
        </script>
        <?php
    }
    elseif ($tab == 'send') {
        $clients = Capsule::table('tblclients')->where('status','Active')->limit(100)->get();
        ?>
        <div class="row"><div class="col-md-8 col-md-offset-2">
            <div class="wa-card">
                <div class="wa-head"><i class="fas fa-paper-plane"></i> Send Manual Message</div>
                <div class="wa-body">
                    <form method="post">
                        <input type="hidden" name="act" value="send">
                        <div class="form-group"><label>Recipient Type</label>
                            <select name="type" class="form-control" onchange="if(this.value=='c'){$('#cl').show();$('#mn').hide()}else{$('#cl').hide();$('#mn').show()}">
                                <option value="c">Existing Client</option><option value="m">Manual Number</option>
                            </select>
                        </div>
                        <div id="cl" class="form-group"><label>Select Client</label><select name="cid" class="form-control"><?php foreach($clients as $c) echo "<option value='{$c->id}'>{$c->firstname} {$c->lastname}</option>";?></select></div>
                        <div id="mn" class="form-group" style="display:none"><div class="row">
                            <div class="col-md-4"><label>Country</label><select name="iso" class="form-control"><?php foreach($countries as $i=>$d) echo "<option value='$i' ".($i=='US'?'selected':'').">{$d['name']} (+{$d['code']})</option>";?></select></div>
                            <div class="col-md-8"><label>Number</label><input name="dest_number" class="form-control" placeholder="8123456789"></div>
                        </div></div>
                        <div class="form-group"><label>Message</label><textarea name="msg" class="form-control" rows="5"></textarea></div>
                        <button class="btn btn-p btn-block">Send Now</button>
                    </form>
                </div>
            </div>
        </div></div>
        <?php
    }
    elseif ($tab == 'tpl') {
        echo '<div class="wa-card"><div class="wa-head">Notification Templates</div><div class="wa-body"><form method="post"><input type="hidden" name="act" value="save_tpl">';
        foreach(Capsule::table('mod_wanotify_templates')->get() as $t) {
            echo '<div style="background:#f9fafb; padding:15px; border-radius:8px; margin-bottom:15px; border:1px solid #e5e7eb">';
            echo '<div style="display:flex; justify-content:space-between; margin-bottom:10px"><b>'.$t->name.'</b> <label><input type="checkbox" name="tpl['.$t->id.'][on]" '.($t->active?'checked':'').'> Active</label></div>';
            echo '<textarea class="form-control" name="tpl['.$t->id.'][msg]" rows="2">'.$t->message.'</textarea></div>';
        }
        echo '<button class="btn btn-p">Save Changes</button></form></div></div>';
    }
    elseif ($tab == 'logs') {
        echo '<div class="wa-card"><div class="wa-head">Message Logs</div><div class="wa-body table-responsive"><table class="table wa-table table-hover"><thead><tr><th>Time</th><th>To</th><th>Message</th><th>Status</th></tr></thead><tbody>';
        foreach(Capsule::table('mod_wanotify_logs')->orderBy('id','desc')->limit(50)->get() as $l) {
            $cls = ($l->status=='received') ? 'info' : 'success';
            echo "<tr><td>{$l->date}</td><td><b>{$l->phone}</b></td><td>".substr(htmlspecialchars($l->message),0,60)."</td><td><span class='label label-$cls'>".strtoupper($l->status)."</span></td></tr>";
        }
        echo '</tbody></table></div></div>';
    }
    echo '</div>'; // end wa-wrap
}