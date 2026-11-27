# 🎙️ Free Call Recording Integration Guide for CRM Leads (₹0 Cost)

This guide explains how to set up **100% Free Automatic Call Recording & Audio Sync** for your Telecallers using their existing Android mobile SIM cards.

---

## 🚀 How It Works
1. When a Telecaller makes/receives a call on their Android mobile phone using their regular SIM card (Jio, Airtel, Vi), the call recording file (MP3 / M4A) is saved on their device.
2. The **Android Call Logger App** (e.g., Call Auto Recorder / Call Log Sync / HTTP Post App) automatically uploads the audio file to your CRM server endpoint:
   `https://akinfracrm.auctechitsolutions.in/api/upload_call_recording.php`
3. The CRM automatically links the audio recording to the specific Customer Lead by matching the **Phone Number**.
4. Admin and Managers can immediately listen to call recordings right inside the CRM using the built-in ▶️ **Audio Player**!

---

## 🛠️ API Endpoint Specifications

* **Endpoint URL:** `https://akinfracrm.auctechitsolutions.in/api/upload_call_recording.php`
* **HTTP Method:** `POST` (multipart/form-data)

### Required / Optional Parameters:
| Parameter | Type | Required? | Description |
| :--- | :--- | :--- | :--- |
| `audio_file` | File | **YES** | The recorded audio file (MP3, M4A, WAV, 3GP, AMR) |
| `phone` | Text | **YES** | Customer Mobile Number (e.g. `9876543210`) |
| `user_email` | Text | Optional | Telecaller Email in CRM (e.g. `mrunal@akinfra.com`) |
| `call_type` | Text | Optional | `Outgoing`, `Incoming`, or `Missed` (Default: `Outgoing`) |
| `duration_seconds` | Number | Optional | Call duration in seconds (e.g. `165` for 2m 45s) |
| `notes` | Text | Optional | Brief call summary or notes |

### Sample Response:
```json
{
  "status": "success",
  "message": "Call recording uploaded and attached to lead.",
  "recording_id": 15,
  "lead_id": 98,
  "audio_url": "uploads/call_recordings/call_98_20260810_124500_123.mp3",
  "duration_str": "2m 45s"
}
```

---

## 📲 How Telecallers Upload Recordings

### Method A: Direct Web Upload inside CRM (Easiest & Instant)
1. Open CRM Dashboard (`akinfracrm.auctechitsolutions.in`).
2. Go to **Call Recordings** from the sidebar menu.
3. Click the green **Upload Recording File** button.
4. Enter customer phone number and select the audio file from mobile/PC. Click **Upload & Attach**!

### Method B: Automated Android App Upload
1. Install any standard Android HTTP Call Logger / Call Sync app on Telecaller Android phones (e.g., *Call Recorder Sync*, *Automate*, or *MacroDroid*).
2. Set Trigger: **On Call Ended**.
3. Set Action: **HTTP POST** to `https://akinfracrm.auctechitsolutions.in/api/upload_call_recording.php` with `phone` and `audio_file`.

---

## 🎧 How Admin Listens to Recordings in CRM

1. Open **Call Recordings** tab from the CRM sidebar navigation.
2. Filter by Telecaller, Date, or Customer Name/Phone.
3. Click the ▶️ **Play** button on the embedded audio player next to any lead!
4. Click the 📥 **Download** icon to save audio files locally anytime.
