# 🚀 Meta (Facebook / Instagram) Direct Lead Ads Webhook Setup Guide

Bhai, aapke CRM me **Direct Meta Lead Ads Webhook & Graph API Handler** completely ready ho gaya hai. Ab aapko **Zapier ya Make.com ki koi zarurat nahi hai!**

---

## 🛠️ Step-by-Step Meta Developer Setup (Free of Cost)

### STEP 1: Meta Developer Portal par App Banayein
1. Browser me [developers.facebook.com](https://developers.facebook.com/) kholein aur apne Facebook Account se login karein.
2. Top right me **My Apps** -> **Create App** par click karein.
3. Use Case me **Other** select karein -> Next -> **Business** (or Business Manager) select karein -> Next.
4. App ka Name rakhein (e.g. `CRM Leads Direct Integrator`) aur **Create App** par click karein.

---

### STEP 2: Webhooks Product Add Karein
1. App Dashboard me **Add Product** me **Webhooks** dhoondhein aur **Set Up** par click karein.
2. Dropdown me **Page** select karein aur **Subscribe to this object** par click karein.
3. Ek popup khulega:
   - **Callback URL**: `https://yourdomain.com/api/webhook.php` (Aapka live server URL with HTTPS)
   - **Verify Token**: `crm_meta_lead_token_123`
4. **Verify and Save** par click karein. (Meta GET request bhejkar instant verify kar lega).
5. Verification ke baad `Page` events ki list aayegi. Usme **`leadgen`** ke aage **Subscribe** button par click kar dein.

---

### STEP 3: Facebook Page ko App se Link & Token Generate Karein
1. Meta Developers me Top Bar me **Tools** -> **Graph API Explorer** par jayein.
2. Right side me Meta App select karein.
3. User or Page dropdown me **Get Page Access Token** select karein aur apna Facebook Page choose karein.
4. Permissions dropdown me ye 3 permissions add karein:
   - `leads_retrieval`
   - `pages_read_engagement`
   - `pages_manage_metadata`
5. **Generate Access Token** par click karke login approve karein.

#### Permanent Token (Recommended):
Long-lived / Never expiring token ke liye:
- Facebook Business Settings (`business.facebook.com`) -> **System Users** -> **Add System User**.
- System user ko Page ki Access aur `leads_retrieval` permission dekar **Generate Token** karein.

---

### STEP 4: Token CRM me Save Karein
1. `c:\xampp\htdocs\crmleads\includes\config.php` file kholein.
2. Line 16-18 par `META_PAGE_ACCESS_TOKEN` me apna token paste kar dein:

```php
define('META_PAGE_ACCESS_TOKEN', 'PASTE_YOUR_META_PAGE_ACCESS_TOKEN_HERE');
```

---

### STEP 5: Free Test Lead Bhej Kar Check Karein (Testing Tool)
Meta Official **Lead Ads Testing Tool** se free me test lead bhej sakte hain:
1. Open link: [developers.facebook.com/tools/lead-ads-testing](https://developers.facebook.com/tools/lead-ads-testing)
2. Apna **Facebook Page** aur **Form** select karein.
3. **Create Lead** par click karein.
4. Aapka CRM instantly lead catch karega aur assigned telecaller ko assign kar dega!
5. **Track status** par click karke Webhook HTTP Response Code `200` confirm kar sakte hain.

---

## ⚡ Summary & Technical Specs
- **Webhook Endpoint**: `api/webhook.php`
- **Verify Token**: `crm_meta_lead_token_123`
- **Supported Platforms**: Meta Lead Ads (Direct), Google Ads, 99acres, Zapier, Pabbly
- **Cost**: ₹0 / Month (100% Free)
