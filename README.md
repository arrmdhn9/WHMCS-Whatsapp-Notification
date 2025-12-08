# 🚀 WHMCS WhatsApp Gateway (Open Source) | Build With GeminiAI

A powerful, open-source WhatsApp integration module for WHMCS. Send notifications to clients, manage invoices, and reply to support tickets directly from WhatsApp.

![Version](https://img.shields.io/badge/version-1.0.0-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![Status](https://img.shields.io/badge/status-stable-success)

## ✨ Features

*   **Real-time Notifications:** Invoice Created, Invoice Paid, Ticket Actions.
*   **Smart Admin Bot:** Control WHMCS via WhatsApp commands.
    *   Mark Invoices as Paid/Unpaid/Cancelled.
    *   Reply to Support Tickets.
    *   Change Ticket Status (Open, Closed, etc.).
*   **Manual Messaging:** Send WhatsApp messages to clients or manual numbers from the WHMCS Admin area.
*   **Two-Way Sync:** Webhook integration handles replies from clients/admins.
*   **Persistent Configuration:** Node.js settings are saved automatically.
*   **Modern UI:** Beautiful, responsive dashboard integrated into WHMCS.

## 🛠️ Prerequisites

*   **Node.js** (v14 or higher)
*   **WHMCS** (v8.0 or higher)
*   **VPS/Server** with SSH access (to run the Node.js gateway)

## 📦 Installation

### 1. Setup Node.js Gateway
This service connects WhatsApp Web to your server.

1.  Navigate to `gateway-service` folder.
2.  Install dependencies:
    ```bash
    npm install
    ```
3.  Create `.env` file:
    ```env
    PORT=3000
    API_KEY=my_super_secret_key_123
    ```
4.  Start the service:
    ```bash
    node index.js
    ```
    *(Recommended: Use PM2 to keep it running: `pm2 start index.js --name wa-gateway`)*

### 2. Install WHMCS Module
1.  Upload `whmcs-module/modules` folder to your WHMCS root directory.
2.  Go to **System Settings > Addon Modules**.
3.  Activate **WA Notify Pro**.
4.  Click **Configure**:
    *   **Gateway URL:** `http://your-server-ip:3000` (or localhost if on same server).
    *   **API Key:** `my_super_secret_key_123` (Must match .env).
    *   **Admin Phone:** Your WhatsApp number (e.g., `62812345678`).
    *   **Admin Username:** Your WHMCS admin login username.
5.  Save Changes.

## ⚙️ Configuration

1.  Go to **Addons > WA Notify Pro**.
2.  You will see a QR Code. Scan it with your WhatsApp (Linked Devices).
3.  Once connected, click **Force Sync Webhook** in the Dashboard tab.
    *   This registers your WHMCS URL to the Node.js gateway.

## 🤖 Admin Bot Commands
Send these commands from your registered Admin WhatsApp number:

| Command | Description | Example |
| :--- | :--- | :--- |
| `/paid #ID` | Mark Invoice as Paid | `/paid #1024` |
| `/unpaid #ID` | Mark Invoice as Unpaid | `/unpaid #1024` |
| `/cancel #ID` | Cancel Invoice | `/cancel #1024` |
| `#ID Message` | Reply to Ticket | `#85921 Hello there...` |
| `/status #ID Status` | Change Ticket Status | `/status #85921 Closed` |

## 📝 License
<<<<<<< HEAD
This project is licensed under the MIT License - see the LICENSE file for details.
=======
This project is licensed under the MIT License - see the LICENSE file for details.
>>>>>>> eef703150b806594faa4d2fbc64735076267c0a1
