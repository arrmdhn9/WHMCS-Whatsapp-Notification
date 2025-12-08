/**
 * WHMCS WhatsApp Gateway Service (Fix Real Number)
 */
require('dotenv').config();
const fs = require('fs');
const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode');
const express = require('express');
const bodyParser = require('body-parser');
const cors = require('cors');
const axios = require('axios');

const app = express();
app.use(cors());
app.use(bodyParser.json());

const CONFIG_FILE = './saved_config.json';

let botConfig = {
    port: process.env.PORT || 3000,
    apiKey: process.env.API_KEY || 'default_secret',
    webhookUrl: process.env.WEBHOOK_URL || null
};

if (fs.existsSync(CONFIG_FILE)) {
    try {
        const saved = JSON.parse(fs.readFileSync(CONFIG_FILE));
        if(saved.webhookUrl) botConfig.webhookUrl = saved.webhookUrl;
        console.log("Configuration loaded from file.");
    } catch(e) {}
}

function saveConfig() {
    fs.writeFileSync(CONFIG_FILE, JSON.stringify({ webhookUrl: botConfig.webhookUrl }, null, 2));
}

let client;
let qrCodeUrl = null;
let isConnected = false;
let messageQueue = [];
let isProcessing = false;

function startClient() {
    client = new Client({
        authStrategy: new LocalAuth({ dataPath: './.wwebjs_auth' }),
        puppeteer: { args: ['--no-sandbox', '--disable-setuid-sandbox'], headless: true },
        webVersionCache: { type: 'remote', remotePath: 'https://raw.githubusercontent.com/wppconnect-team/wa-version/main/html/2.2412.54.html' }
    });

    client.on('qr', async (qr) => { qrCodeUrl = await qrcode.toDataURL(qr); isConnected = false; console.log("QR Generated"); });
    client.on('ready', () => { console.log('WhatsApp Client Ready!'); isConnected = true; qrCodeUrl = null; });
    client.on('disconnected', () => { console.log("Client Disconnected"); isConnected = false; client.initialize(); });

    // --- BAGIAN INI YANG DIPERBAIKI (FIX REAL NUMBER) ---
    client.on('message', async msg => {
        // Abaikan status update atau pesan grup
        if(msg.from.includes('@g.us') || msg.isStatus) return;
        if(!botConfig.webhookUrl) return;

        try {
            // AMBIL KONTAK ASLI UNTUK DAPATKAN NOMOR HP REAL
            const contact = await msg.getContact();
            
            // Prioritas: Ambil nomor dari contact.id.user (ini nomor asli)
            // Jika gagal, baru ambil dari msg.from
            let realNumber = contact.id ? contact.id.user : msg.from.replace(/\D/g, '');

            // Pastikan format bersih (hanya angka)
            realNumber = realNumber.replace(/\D/g, '');

            console.log(`Debug ID: ${msg.from} -> Real Number: ${realNumber}`);

            await axios.post(botConfig.webhookUrl, {
                action: 'incoming_message',
                phone: realNumber, // Kirim nomor yang sudah diperbaiki
                message: msg.body,
                secret: botConfig.apiKey
            });
            console.log(`Webhook sent: ${realNumber}`);
        } catch (e) { 
            console.error('Webhook Error:', e.message); 
        }
    });
    // ----------------------------------------------------

    client.initialize();
}

const processQueue = async () => {
    if (isProcessing || messageQueue.length === 0 || !isConnected) return;
    isProcessing = true;
    const item = messageQueue.shift();
    try {
        await client.sendMessage(item.phone, item.message);
        console.log(`Sent to ${item.phone}`);
        await new Promise(r => setTimeout(r, 1500));
    } catch (e) { console.error('Send Error:', e.message); }
    isProcessing = false;
    processQueue();
};

app.get('/status', (req, res) => {
    res.json({
        connected: isConnected,
        qr: qrCodeUrl,
        queue: messageQueue.length,
        webhook: botConfig.webhookUrl
    });
});

app.post('/config', (req, res) => {
    if(req.body.apiKey !== botConfig.apiKey) return res.status(403).json({error:'Invalid Key'});
    if(req.body.webhookUrl) {
        botConfig.webhookUrl = req.body.webhookUrl;
        saveConfig();
    }
    res.json({status:'saved', webhook: botConfig.webhookUrl});
});

app.post('/send', (req, res) => {
    if(req.body.secret !== botConfig.apiKey) return res.status(403).json({error:'Invalid Key'});
    let chatId = req.body.phone.replace(/\D/g, '');
    if(!chatId.endsWith('@c.us')) chatId += '@c.us';
    messageQueue.push({ phone: chatId, message: req.body.message });
    processQueue();
    res.json({status:'queued'});
});

app.post('/logout', async (req, res) => {
    if(req.body.secret !== botConfig.apiKey) return res.status(403).json({error:'Invalid Key'});
    try { await client.logout(); res.json({status:'ok'}); } catch(e){ res.status(500).json({error:e.message}); }
});

startClient();
app.listen(botConfig.port, () => console.log(`Gateway running on port ${botConfig.port}`));